<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaRuntime.php';

/**
 * Verifies that a replacement endpoint exposes the same canonical MySQL
 * generation before the external adapter configuration is changed.
 */
final class DatabaseEndpointVerifier
{
    /** @return array<string, mixed> */
    public function verify(PDO $canonical, PDO $candidate): array
    {
        if ((string) $canonical->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || (string) $candidate->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DatabaseAdapterException(
                'MySQL endpoint relocation requires two MySQL connections.',
                'driver_mismatch'
            );
        }
        if ($this->prefix($canonical) !== $this->prefix($candidate)) {
            throw new DatabaseAdapterException(
                'The replacement endpoint table prefix does not match the canonical database.',
                'endpoint_validation_failed'
            );
        }
        if ($canonical->inTransaction() || $candidate->inTransaction()) {
            throw new DatabaseAdapterException(
                'Database endpoint verification cannot start inside an active transaction.',
                'endpoint_validation_failed'
            );
        }

        $canonicalStarted = false;
        $candidateStarted = false;
        try {
            $canonical->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $candidate->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $canonical->beginTransaction();
            $canonicalStarted = true;
            $candidate->beginTransaction();
            $candidateStarted = true;

            $catalog = CoreSchemaCatalog::load();
            $canonicalCore = (new CoreSchemaRuntime($canonical, $catalog))->preflight(false);
            $candidateCore = (new CoreSchemaRuntime($candidate, $catalog))->preflight(false);
            if (!is_array($canonicalCore) || !is_array($candidateCore)) {
                throw new CoreSchemaException(['reason' => 'canonical_schema_upgrade_required']);
            }

            $inspector = new DatabaseSchemaInspector();
            $canonicalSchema = $inspector->inspect($canonical);
            $candidateSchema = $inspector->inspect($candidate);
            $canonicalTables = $canonicalSchema->tables();
            $candidateTables = $candidateSchema->tables();
            if (array_keys($canonicalTables) !== array_keys($candidateTables)) {
                throw new DatabaseAdapterException(
                    'The replacement endpoint does not contain the canonical table set.',
                    'endpoint_validation_failed'
                );
            }

            $rows = 0;
            $digests = [];
            foreach ($canonicalSchema->orderedTables() as $logicalName) {
                $canonicalTable = $canonicalSchema->table($logicalName);
                $candidateTable = $candidateSchema->table($logicalName);
                if ($this->schemaSignature($canonicalTable) !== $this->schemaSignature($candidateTable)) {
                    throw new DatabaseAdapterException(
                        'The replacement endpoint schema differs from the canonical database.',
                        'endpoint_validation_failed'
                    );
                }

                $canonicalPhysical = (string) $canonicalTable['physical_name'];
                $candidatePhysical = (string) $candidateTable['physical_name'];
                $canonicalCount = $this->rowCount($canonical, $canonicalPhysical);
                $candidateCount = $this->rowCount($candidate, $candidatePhysical);
                if ($canonicalCount !== $candidateCount) {
                    throw new DatabaseAdapterException(
                        'The replacement endpoint row counts differ from the canonical database.',
                        'endpoint_validation_failed'
                    );
                }
                $canonicalDigest = $this->tableDigest($canonical, $canonicalTable, $canonicalPhysical);
                $candidateDigest = $this->tableDigest($candidate, $candidateTable, $candidatePhysical);
                if (!hash_equals($canonicalDigest, $candidateDigest)) {
                    throw new DatabaseAdapterException(
                        'The replacement endpoint data differs from the canonical database.',
                        'endpoint_validation_failed'
                    );
                }
                $rows += $canonicalCount;
                $digests[$logicalName] = $candidateDigest;
            }

            $settings = [];
            foreach ([
                'database.schema_version',
                'database.schema_fingerprint',
                'rag.core_schema_version',
                'database.migration_version',
                'database.migrated_from',
            ] as $key) {
                $canonicalValue = $this->setting($canonical, $key);
                $candidateValue = $this->setting($candidate, $key);
                if ($canonicalValue === null || !hash_equals($canonicalValue, (string) $candidateValue)) {
                    throw new DatabaseAdapterException(
                        'The replacement endpoint migration metadata differs from the canonical database.',
                        'endpoint_validation_failed'
                    );
                }
                $settings[$key] = $candidateValue;
            }
            if (($settings['database.schema_version'] ?? '') !== (string) LogicalDatabaseSchema::VERSION
                || ($settings['database.schema_fingerprint'] ?? '') !== $catalog->artifactHash()
                || ($settings['rag.core_schema_version'] ?? '') !== (string) CoreSchemaRuntime::RAG_SCHEMA_VERSION
                || ($settings['database.migration_version'] ?? '') !== '1') {
                throw new DatabaseAdapterException(
                    'The replacement endpoint migration metadata is unsupported.',
                    'endpoint_validation_failed'
                );
            }

            $candidate->commit();
            $candidateStarted = false;
            $canonical->commit();
            $canonicalStarted = false;
            return [
                'tables' => count($digests),
                'rows' => $rows,
                'schema' => true,
                'row_counts' => true,
                'data_digests' => true,
                'migration_metadata' => true,
                'canonical_schema' => true,
                'canonical_schema_version' => $catalog->schemaVersion(),
                'canonical_rag_schema_version' => CoreSchemaRuntime::RAG_SCHEMA_VERSION,
                'canonical_schema_fingerprint' => $catalog->artifactHash(),
                'canonical_core_tables' => count($canonicalCore['verified_core_tables'] ?? []),
                'candidate_core_tables' => count($candidateCore['verified_core_tables'] ?? []),
            ];
        } catch (CoreSchemaException $exception) {
            throw new DatabaseAdapterException(
                'A MySQL endpoint does not match the canonical Core schema.',
                $exception->failureCode(),
                $exception
            );
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The replacement MySQL endpoint could not be verified.',
                'endpoint_validation_failed',
                $exception
            );
        } finally {
            if ($candidateStarted && $candidate->inTransaction()) {
                $candidate->rollBack();
            }
            if ($canonicalStarted && $canonical->inTransaction()) {
                $canonical->rollBack();
            }
        }
    }

    /** @param array<string, mixed> $table @return array<string, mixed> */
    private function schemaSignature(array $table): array
    {
        $columns = [];
        foreach ($table['columns'] ?? [] as $name => $column) {
            $columns[(string) $name] = [
                'type' => strtoupper(preg_replace('/\s+/', ' ', trim((string) ($column['type'] ?? ''))) ?? ''),
                'nullable' => (bool) ($column['nullable'] ?? true),
                'default' => $this->normalizedDefault($column['default'] ?? null),
                'auto_increment' => (bool) ($column['auto_increment'] ?? false),
            ];
        }
        $foreignKeys = array_map(static function (array $foreignKey): array {
            $normalizeAction = static fn (mixed $action): string => match (strtoupper((string) $action)) {
                'NO ACTION' => 'RESTRICT',
                default => strtoupper((string) $action),
            };
            return [
                'column' => (string) ($foreignKey['column'] ?? ''),
                'referenced_table' => (string) ($foreignKey['referenced_table'] ?? ''),
                'referenced_column' => (string) ($foreignKey['referenced_column'] ?? ''),
                'on_delete' => $normalizeAction($foreignKey['on_delete'] ?? ''),
                'on_update' => $normalizeAction($foreignKey['on_update'] ?? ''),
            ];
        }, array_values($table['foreign_keys'] ?? []));
        usort($foreignKeys, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
        $indexes = array_map(static fn (array $index): array => [
            'unique' => (bool) ($index['unique'] ?? false),
            'type' => strtoupper((string) ($index['type'] ?? 'BTREE')),
            'columns' => array_values(array_map('strval', $index['columns'] ?? [])),
        ], array_values($table['indexes'] ?? []));
        usort($indexes, static fn (array $left, array $right): int => json_encode($left) <=> json_encode($right));
        return [
            'columns' => $columns,
            'primary_key' => array_values(array_map('strval', $table['primary_key'] ?? [])),
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
        ];
    }

    private function normalizedDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $value = strtoupper(trim((string) $default));
        return in_array($value, ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()'], true)
            ? 'CURRENT_TIMESTAMP'
            : (string) $default;
    }

    /** @param array<string, mixed> $table */
    private function tableDigest(PDO $pdo, array $table, string $physicalName): string
    {
        $columns = array_keys($table['columns'] ?? []);
        if ($columns === []) {
            throw new DatabaseAdapterException('Canonical table has no columns.', 'endpoint_validation_failed');
        }
        $sql = 'SELECT ' . implode(', ', array_map($this->quote(...), $columns))
            . ' FROM ' . $this->quote($physicalName);
        $order = array_values($table['primary_key'] ?? []);
        if ($order === []) {
            $order = $columns;
        }
        $sql .= ' ORDER BY ' . implode(', ', array_map($this->quote(...), $order));
        $statement = $pdo->query($sql);
        $hash = hash_init('sha256');
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_resource($value)) {
                    $value = stream_get_contents($value);
                }
                if ($value === null) {
                    hash_update($hash, "N;");
                    continue;
                }
                $value = $this->normalizedDigestValue(
                    $column,
                    (string) ($table['columns'][$column]['type'] ?? ''),
                    (string) $value
                );
                hash_update($hash, 'V' . strlen($value) . ':' . $value . ';');
            }
            hash_update($hash, "\n");
        }
        return hash_final($hash);
    }

    private function normalizedDigestValue(string $column, string $type, string $value): string
    {
        if (!str_ends_with(strtolower($column), '_json') && !str_contains(strtoupper($type), 'JSON')) {
            return $value;
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            $canonicalize = static function (mixed $item) use (&$canonicalize): mixed {
                if (!is_array($item)) {
                    return $item;
                }
                if (!array_is_list($item)) {
                    ksort($item, SORT_STRING);
                }
                foreach ($item as $key => $child) {
                    $item[$key] = $canonicalize($child);
                }
                return $item;
            };
            return json_encode($canonicalize($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $value;
        }
    }

    private function rowCount(PDO $pdo, string $physicalName): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM ' . $this->quote($physicalName))->fetchColumn();
    }

    private function setting(PDO $pdo, string $key): ?string
    {
        $table = $this->quote($this->prefix($pdo) . 'system_settings');
        $statement = $pdo->prepare("SELECT setting_value FROM {$table} WHERE setting_key = ?");
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function prefix(PDO $pdo): string
    {
        return method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException('MySQL identifier is invalid.', 'endpoint_validation_failed');
        }
        return '`' . $identifier . '`';
    }
}
