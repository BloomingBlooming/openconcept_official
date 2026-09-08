<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaSignature.php';
require_once __DIR__ . '/CoreSchemaTriggerVerifier.php';
require_once __DIR__ . '/CoreSchemaVerifier.php';
require_once __DIR__ . '/DatabaseAdapterException.php';

final class DatabaseMigrationService
{
    private readonly ?Closure $checkpoint;

    public function __construct(
        private readonly PDO $source,
        private readonly PDO $destination,
        private readonly LogicalDatabaseSchema $schema,
        ?callable $checkpoint = null
    ) {
        $this->checkpoint = $checkpoint === null ? null : Closure::fromCallable($checkpoint);
    }

    /**
     * @return array{mode: string, existing_tables: int, non_empty_tables: int}
     */
    public function destinationDisposition(bool $managedRetry): array
    {
        $this->assertDestinationInventory();
        $driver = (string) $this->destination->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = $this->prefix($this->destination);
        $existing = array_fill_keys($this->physicalTables($this->destination), true);
        $expectedExisting = [];
        $nonEmpty = [];
        foreach (array_keys($this->schema->tables()) as $logicalName) {
            $physical = $prefix . $logicalName;
            if (!isset($existing[$physical])) {
                continue;
            }
            $expectedExisting[] = $physical;
            $count = (int) $this->destination->query(
                'SELECT COUNT(*) FROM ' . $this->quote($physical, $driver)
            )->fetchColumn();
            if ($count > 0) {
                $nonEmpty[] = $physical;
            }
        }
        if ($nonEmpty !== [] && !$managedRetry) {
            throw new DatabaseAdapterException(
                'Destination contains existing OpenConcept data and will not be overwritten.',
                'destination_not_empty'
            );
        }
        return [
            'mode' => $nonEmpty !== [] ? 'resume' : ($expectedExisting === [] ? 'new' : 'empty_schema'),
            'existing_tables' => count($expectedExisting),
            'non_empty_tables' => count($nonEmpty),
        ];
    }

    /**
     * Prove every existing relation in the managed namespace before an
     * adapter executes CREATE IF NOT EXISTS. Exact partial Core state is a
     * valid retry input; drift and stale/unknown plugin relations are not.
     */
    private function assertDestinationInventory(): void
    {
        try {
            $driver = (string) $this->destination->getAttribute(PDO::ATTR_DRIVER_NAME);
            $catalog = CoreSchemaCatalog::load();
            $signature = new CoreSchemaSignature();
            (new CoreSchemaTriggerVerifier())->assertNoneForDestination($this->destination);
            $expected = array_fill_keys(array_keys($this->schema->tables()), true);
            $presentCore = [];
            foreach ($signature->relations($this->destination) as $relation) {
                if (($relation['scoped'] ?? false) !== true
                    || ($relation['classification'] ?? null) === 'engine_metadata') {
                    continue;
                }
                $logicalName = (string) ($relation['logical_name'] ?? '');
                $classification = (string) ($relation['classification'] ?? 'unclassified');
                $allowed = match ($classification) {
                    'core' => isset($expected[$logicalName]),
                    'plugin' => isset($expected[$logicalName]) && str_starts_with($logicalName, 'plugin_'),
                    'adapter_metadata' => $driver === 'mysql' && $logicalName === 'workspace_identity',
                    default => false,
                };
                if (!$allowed) {
                    throw new CoreSchemaException([
                        'reason' => 'destination_contains_unowned_relation',
                        'driver' => $driver,
                        'relation' => $logicalName,
                        'classification' => $classification,
                    ]);
                }
                if ($classification === 'core') {
                    $presentCore[] = $logicalName;
                    continue;
                }
                if ($classification === 'plugin' && ($relation['type'] ?? null) !== 'table') {
                    throw new CoreSchemaException([
                        'reason' => 'destination_plugin_relation_type_mismatch',
                        'driver' => $driver,
                        'relation' => $logicalName,
                    ]);
                }
            }
            if ($presentCore !== []) {
                $presentCore = array_values(array_unique($presentCore, SORT_STRING));
                (new CoreSchemaVerifier($catalog, $signature))->verifyTables(
                    $this->destination,
                    $presentCore,
                    false
                );
            }
        } catch (CoreSchemaException $exception) {
            throw new DatabaseAdapterException(
                'The destination namespace does not match the canonical Core schema.',
                $exception->failureCode(),
                $exception
            );
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The destination schema inventory could not be verified.',
                'canonical_schema_mismatch',
                $exception
            );
        }
    }

    /**
     * Copies every logical table under source and destination snapshots,
     * preserves explicit IDs, and validates before committing destination.
     *
     * @return array<string, mixed>
     */
    public function migrate(): array
    {
        $sourceDriver = (string) $this->source->getAttribute(PDO::ATTR_DRIVER_NAME);
        $destinationDriver = (string) $this->destination->getAttribute(PDO::ATTR_DRIVER_NAME);
        $destinationForeignKeysDisabled = false;
        $sourceStarted = false;
        $destinationStarted = false;
        try {
            $this->beginSourceSnapshot($sourceDriver);
            $sourceStarted = true;
            if ($destinationDriver === 'mysql') {
                $this->destination->exec('SET FOREIGN_KEY_CHECKS = 0');
                $destinationForeignKeysDisabled = true;
            }
            $this->destination->beginTransaction();
            $destinationStarted = true;
            if ($destinationDriver === 'pgsql') {
                $this->destination->exec('SET CONSTRAINTS ALL DEFERRED');
            }

            $this->clearDestination($destinationDriver);
            $copiedRows = [];
            $orderedTables = $this->schema->orderedTables();
            $totalTables = count($orderedTables);
            $this->checkpoint('copying', [
                'completed_tables' => 0,
                'total_tables' => $totalTables,
            ]);
            foreach ($orderedTables as $logicalName) {
                $copiedRows[$logicalName] = $this->copyTable($logicalName, $sourceDriver, $destinationDriver);
                $this->checkpoint('copying', [
                    'completed_tables' => count($copiedRows),
                    'total_tables' => $totalTables,
                    'table' => $logicalName,
                    'rows' => array_sum($copiedRows),
                ]);
            }
            if ($destinationDriver === 'pgsql') {
                $this->resetPostgreSqlIdentities();
            }

            $this->checkpoint('validating', [
                'validated_tables' => 0,
                'total_tables' => $totalTables,
            ]);
            $validation = $this->validate($sourceDriver, $destinationDriver, $copiedRows);
            $this->writeMigrationMetadata($destinationDriver, $sourceDriver);
            $postMetadataValidation = $this->validateAfterMigrationMetadata(
                $sourceDriver,
                $destinationDriver
            );
            $validation = array_merge($validation, $postMetadataValidation);
            $validation['schema_version'] = true;
            $validation['migration_version'] = true;
            $validation['required_settings'] = true;
            $this->checkpoint('committing_destination', [
                'total_tables' => $totalTables,
                'rows' => array_sum($copiedRows),
            ]);
            $this->destination->commit();
            $destinationStarted = false;
            $this->commitSourceSnapshot($sourceDriver);
            $sourceStarted = false;
            return [
                'tables' => count($copiedRows),
                'rows' => array_sum($copiedRows),
                'row_counts' => $copiedRows,
                'validation' => $validation,
                'schema_version' => LogicalDatabaseSchema::VERSION,
                'migration_version' => 1,
            ];
        } catch (Throwable $exception) {
            if ($destinationStarted && $this->destination->inTransaction()) {
                $this->destination->rollBack();
            }
            if ($sourceStarted) {
                $this->rollBackSourceSnapshot($sourceDriver);
            }
            if ($exception instanceof DatabaseAdapterException) {
                throw $exception;
            }
            throw new DatabaseAdapterException('Database migration failed.', 'migration_failed', $exception);
        } finally {
            if ($destinationForeignKeysDisabled) {
                try {
                    $this->destination->exec('SET FOREIGN_KEY_CHECKS = 1');
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Initializes a destination as a valid but data-empty canonical database.
     * Only immutable schema/migration attestations are written; no source row
     * is copied. This mode is intentionally limited to an already provisioned
     * PostgreSQL destination selected by the PostgreSQL replacement workflow.
     *
     * @return array<string, mixed>
     */
    public function initializeEmpty(): array
    {
        $sourceDriver = (string) $this->source->getAttribute(PDO::ATTR_DRIVER_NAME);
        $destinationDriver = (string) $this->destination->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($sourceDriver !== 'pgsql' || $destinationDriver !== 'pgsql') {
            throw new DatabaseAdapterException(
                'An empty canonical destination is supported only for PostgreSQL-to-PostgreSQL replacement.',
                'invalid_source_backend'
            );
        }
        if ($this->destination->inTransaction()) {
            throw new LogicException('Destination database already has an active transaction.');
        }

        try {
            $this->destination->beginTransaction();
            $this->destination->exec('SET CONSTRAINTS ALL DEFERRED');
            $this->clearDestination($destinationDriver);
            $this->checkpoint('initializing_empty_destination', [
                'total_tables' => count($this->schema->orderedTables()),
            ]);
            $validation = $this->validateEmptyDestination($destinationDriver);
            $this->writeMigrationMetadata($destinationDriver, $sourceDriver);
            $this->writeEmptyCanonicalAttestation($destinationDriver);
            $this->validateEmptyCanonicalAttestation($destinationDriver);
            $validation['schema_version'] = true;
            $validation['migration_version'] = true;
            $validation['required_settings'] = true;
            $validation['source_data_copied'] = false;
            $this->destination->commit();
            return [
                'tables' => count($this->schema->orderedTables()),
                'rows' => 0,
                'row_counts' => array_fill_keys($this->schema->orderedTables(), 0),
                'validation' => $validation,
                'schema_version' => LogicalDatabaseSchema::VERSION,
                'migration_version' => 1,
                'copy_canonical' => false,
            ];
        } catch (Throwable $exception) {
            if ($this->destination->inTransaction()) {
                $this->destination->rollBack();
            }
            if ($exception instanceof DatabaseAdapterException) {
                throw $exception;
            }
            throw new DatabaseAdapterException(
                'Empty PostgreSQL canonical initialization failed.',
                'migration_failed',
                $exception
            );
        }
    }

    private function beginSourceSnapshot(string $driver): void
    {
        if ($this->source->inTransaction()) {
            throw new LogicException('Source database already has an active transaction.');
        }
        if ($driver === 'sqlite') {
            $this->source->exec('BEGIN IMMEDIATE');
            return;
        }
        if ($driver === 'mysql') {
            $this->source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        if ($driver === 'pgsql') {
            // PostgreSQL defaults to READ COMMITTED, where every statement may
            // observe a different committed state. Start one read-only
            // repeatable-read transaction so all copied tables and their
            // validation digests are taken from the same source snapshot.
            $this->source->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            return;
        }
        $this->source->beginTransaction();
    }

    private function commitSourceSnapshot(string $driver): void
    {
        // BEGIN IMMEDIATE is deliberately used for SQLite so writers cannot
        // change the canonical source while its tables are copied. Some
        // pdo_sqlite builds do not expose SQL-started transactions through
        // PDO::inTransaction()/commit(), so finish it with matching SQL.
        if ($driver === 'sqlite') {
            $this->source->exec('COMMIT');
            return;
        }
        $this->source->commit();
    }

    private function rollBackSourceSnapshot(string $driver): void
    {
        try {
            if ($driver === 'sqlite') {
                $this->source->exec('ROLLBACK');
                return;
            }
            if ($this->source->inTransaction()) {
                $this->source->rollBack();
            }
        } catch (Throwable) {
            // Preserve the migration exception. A rollback failure is never
            // allowed to replace the original validation/connection failure.
        }
    }

    private function clearDestination(string $driver): void
    {
        $prefix = $this->prefix($this->destination);
        foreach ($this->schema->reverseOrderedTables() as $logicalName) {
            $this->destination->exec(
                'DELETE FROM ' . $this->quote($prefix . $logicalName, $driver)
            );
        }
    }

    private function copyTable(string $logicalName, string $sourceDriver, string $destinationDriver): int
    {
        $table = $this->schema->table($logicalName);
        $sourcePhysical = (string) $table['physical_name'];
        $destinationPhysical = $this->prefix($this->destination) . $logicalName;
        $columns = array_keys($table['columns']);
        if ($columns === []) {
            throw new DatabaseAdapterException('Logical table has no columns.', 'invalid_schema');
        }
        $sourceColumns = implode(', ', array_map(
            fn (string $column): string => $this->quote($column, $sourceDriver),
            $columns
        ));
        $orderColumns = array_values($table['primary_key'] ?? []);
        $selectSql = 'SELECT ' . $sourceColumns . ' FROM ' . $this->quote($sourcePhysical, $sourceDriver);
        if ($orderColumns !== []) {
            $selectSql .= ' ORDER BY ' . implode(', ', array_map(
                fn (string $column): string => $this->quote($column, $sourceDriver),
                $orderColumns
            ));
        }
        $rows = $this->source->query($selectSql);
        $insertSql = 'INSERT INTO ' . $this->quote($destinationPhysical, $destinationDriver)
            . ' (' . implode(', ', array_map(
                fn (string $column): string => $this->quote($column, $destinationDriver),
                $columns
            )) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $insert = $this->destination->prepare($insertSql);
        $count = 0;
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = $row[$column] ?? null;
            }
            $insert->execute($values);
            $count++;
        }
        return $count;
    }

    /** @param array<string, int> $copiedRows @return array<string, mixed> */
    private function validate(string $sourceDriver, string $destinationDriver, array $copiedRows): array
    {
        $destinationPrefix = $this->prefix($this->destination);
        $destinationTables = (new DatabaseSchemaInspector())->inspect($this->destination)->tables();
        $tableDigests = [];
        $nullChecks = 0;
        foreach ($this->schema->orderedTables() as $logicalName) {
            $table = $this->schema->table($logicalName);
            $destinationDefinition = $destinationTables[$logicalName] ?? null;
            if (!is_array($destinationDefinition)) {
                throw new DatabaseAdapterException('Destination table validation failed.', 'validation_failed');
            }
            if (array_values($destinationDefinition['primary_key'] ?? []) !== array_values($table['primary_key'] ?? [])) {
                throw new DatabaseAdapterException('Destination primary-key validation failed.', 'validation_failed');
            }
            foreach (array_keys($table['columns']) as $column) {
                if (!isset($destinationDefinition['columns'][$column])) {
                    throw new DatabaseAdapterException('Destination column validation failed.', 'validation_failed');
                }
            }
            $sourcePhysical = (string) $table['physical_name'];
            $destinationPhysical = $destinationPrefix . $logicalName;
            $destinationCount = (int) $this->destination->query(
                'SELECT COUNT(*) FROM ' . $this->quote($destinationPhysical, $destinationDriver)
            )->fetchColumn();
            if ($destinationCount !== $copiedRows[$logicalName]) {
                throw new DatabaseAdapterException('Destination row count validation failed.', 'validation_failed');
            }
            foreach (array_keys($table['columns']) as $column) {
                $sourceNulls = $this->nullCount($this->source, $sourcePhysical, $column, $sourceDriver);
                $destinationNulls = $this->nullCount($this->destination, $destinationPhysical, $column, $destinationDriver);
                if ($sourceNulls !== $destinationNulls) {
                    throw new DatabaseAdapterException('Destination NULL validation failed.', 'validation_failed');
                }
                $nullChecks++;
            }
            $destinationTable = $table;
            $destinationTable['physical_name'] = $destinationPhysical;
            // Normalize each side according to its actual physical type. A
            // source SQLite TEXT may intentionally project to canonical
            // PostgreSQL NUMERIC, JSONB, VARCHAR, or another driver profile;
            // reusing the source declaration would miss destination-native
            // boolean, numeric-scale, or representation semantics.
            foreach (array_keys($table['columns']) as $column) {
                $destinationTable['columns'][$column]['type'] = (string) (
                    $destinationDefinition['columns'][$column]['type'] ?? ''
                );
            }
            $temporalColumns = $this->temporalColumns(
                $table,
                $destinationTable,
                $sourceDriver,
                $destinationDriver
            );
            $sourceDigest = $this->tableDigest(
                $this->source,
                $table,
                $sourcePhysical,
                $sourceDriver,
                [],
                $temporalColumns
            );
            $destinationDigest = $this->tableDigest(
                $this->destination,
                $destinationTable,
                $destinationPhysical,
                $destinationDriver,
                [],
                $temporalColumns
            );
            if (!hash_equals($sourceDigest, $destinationDigest)) {
                throw new DatabaseAdapterException(
                    'Destination data digest validation failed for logical table ' . $logicalName . '.',
                    'validation_failed'
                );
            }
            $tableDigests[$logicalName] = $destinationDigest;
            $this->checkpoint('validating', [
                'validated_tables' => count($tableDigests),
                'total_tables' => count($this->schema->orderedTables()),
                'table' => $logicalName,
            ]);
        }

        $foreignKeyChecks = 0;
        foreach ($this->schema->tables() as $logicalName => $table) {
            foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
                $definitionFound = false;
                foreach ($destinationTables[$logicalName]['foreign_keys'] ?? [] as $destinationForeignKey) {
                    if ((string) ($destinationForeignKey['column'] ?? '') === (string) $foreignKey['column']
                        && (string) ($destinationForeignKey['referenced_table'] ?? '') === (string) $foreignKey['referenced_table']
                        && (string) ($destinationForeignKey['referenced_column'] ?? '') === (string) $foreignKey['referenced_column']) {
                        $definitionFound = true;
                        break;
                    }
                }
                if (!$definitionFound) {
                    throw new DatabaseAdapterException('Destination foreign-key definition validation failed.', 'validation_failed');
                }
                $childTable = $destinationPrefix . $logicalName;
                $parentTable = $destinationPrefix . (string) $foreignKey['referenced_table'];
                $childColumn = (string) $foreignKey['column'];
                $parentColumn = (string) $foreignKey['referenced_column'];
                $sql = 'SELECT COUNT(*) FROM ' . $this->quote($childTable, $destinationDriver) . ' child LEFT JOIN '
                    . $this->quote($parentTable, $destinationDriver) . ' parent ON child.'
                    . $this->quote($childColumn, $destinationDriver) . ' = parent.'
                    . $this->quote($parentColumn, $destinationDriver) . ' WHERE child.'
                    . $this->quote($childColumn, $destinationDriver) . ' IS NOT NULL AND parent.'
                    . $this->quote($parentColumn, $destinationDriver) . ' IS NULL';
                if ((int) $this->destination->query($sql)->fetchColumn() !== 0) {
                    throw new DatabaseAdapterException('Destination foreign-key validation failed.', 'validation_failed');
                }
                $foreignKeyChecks++;
            }
        }
        return [
            'tables' => count($tableDigests),
            'row_counts' => true,
            'table_definitions' => true,
            'primary_keys' => true,
            'primary_and_data_digests' => true,
            'null_checks' => $nullChecks,
            'foreign_key_checks' => $foreignKeyChecks,
        ];
    }

    /** @return array<string, mixed> */
    private function validateEmptyDestination(string $destinationDriver): array
    {
        $destinationPrefix = $this->prefix($this->destination);
        $destinationTables = (new DatabaseSchemaInspector())->inspect($this->destination)->tables();
        $validated = 0;
        foreach ($this->schema->orderedTables() as $logicalName) {
            $table = $this->schema->table($logicalName);
            $destinationDefinition = $destinationTables[$logicalName] ?? null;
            if (!is_array($destinationDefinition)) {
                throw new DatabaseAdapterException('Empty destination table validation failed.', 'validation_failed');
            }
            if (array_values($destinationDefinition['primary_key'] ?? [])
                !== array_values($table['primary_key'] ?? [])) {
                throw new DatabaseAdapterException(
                    'Empty destination primary-key validation failed.',
                    'validation_failed'
                );
            }
            foreach (array_keys($table['columns']) as $column) {
                if (!isset($destinationDefinition['columns'][$column])) {
                    throw new DatabaseAdapterException(
                        'Empty destination column validation failed.',
                        'validation_failed'
                    );
                }
            }
            $physical = $destinationPrefix . $logicalName;
            if ((int) $this->destination->query(
                'SELECT COUNT(*) FROM ' . $this->quote($physical, $destinationDriver)
            )->fetchColumn() !== 0) {
                throw new DatabaseAdapterException(
                    'Empty destination contains canonical data.',
                    'validation_failed'
                );
            }
            $validated++;
            $this->checkpoint('validating', [
                'phase' => 'empty_destination',
                'validated_tables' => $validated,
                'total_tables' => count($this->schema->orderedTables()),
                'table' => $logicalName,
            ]);
        }
        return [
            'tables' => $validated,
            'row_counts' => true,
            'table_definitions' => true,
            'primary_keys' => true,
            'empty_destination' => true,
        ];
    }

    /** @param array<string, scalar|null> $details */
    private function checkpoint(string $stage, array $details = []): void
    {
        if ($this->checkpoint !== null) {
            ($this->checkpoint)($stage, $details);
        }
    }

    /**
     * Migration metadata is itself an untrusted write boundary: a pre-existing
     * trigger could mutate another table after the first digest pass while
     * preserving row counts. Re-read every migrated Core/plugin value inside
     * the still-open destination transaction before commit. The three metadata
     * rows are the only intentional source/destination difference.
     *
     * @return array<string, mixed>
     */
    private function validateAfterMigrationMetadata(
        string $sourceDriver,
        string $destinationDriver
    ): array {
        $metadata = [
            'database.schema_version' => (string) LogicalDatabaseSchema::VERSION,
            'database.migration_version' => '1',
            'database.migrated_from' => $sourceDriver,
        ];
        $excludedSettings = array_keys($metadata);
        $destinationPrefix = $this->prefix($this->destination);
        $destinationTables = (new DatabaseSchemaInspector())->inspect($this->destination)->tables();
        $nullChecks = 0;
        $digests = [];
        foreach ($this->schema->orderedTables() as $logicalName) {
            $table = $this->schema->table($logicalName);
            $destinationDefinition = $destinationTables[$logicalName] ?? null;
            if (!is_array($destinationDefinition)) {
                throw new DatabaseAdapterException(
                    'Post-metadata destination table validation failed.',
                    'validation_failed'
                );
            }
            $sourcePhysical = (string) $table['physical_name'];
            $destinationPhysical = $destinationPrefix . $logicalName;
            $excluded = $logicalName === 'system_settings' ? $excludedSettings : [];
            $sourceCount = $this->rowCountExcluding(
                $this->source,
                $table,
                $sourcePhysical,
                $sourceDriver,
                $excluded
            );
            $destinationCount = $this->rowCountExcluding(
                $this->destination,
                $table,
                $destinationPhysical,
                $destinationDriver,
                $excluded
            );
            if ($sourceCount !== $destinationCount) {
                throw new DatabaseAdapterException(
                    'Post-metadata row count validation failed for logical table ' . $logicalName . '.',
                    'validation_failed'
                );
            }
            foreach (array_keys($table['columns']) as $column) {
                $sourceNulls = $this->nullCount(
                    $this->source,
                    $sourcePhysical,
                    $column,
                    $sourceDriver,
                    $table,
                    $excluded
                );
                $destinationNulls = $this->nullCount(
                    $this->destination,
                    $destinationPhysical,
                    $column,
                    $destinationDriver,
                    $table,
                    $excluded
                );
                if ($sourceNulls !== $destinationNulls) {
                    throw new DatabaseAdapterException(
                        'Post-metadata NULL validation failed for logical table ' . $logicalName . '.',
                        'validation_failed'
                    );
                }
                $nullChecks++;
            }
            $destinationTable = $table;
            $destinationTable['physical_name'] = $destinationPhysical;
            foreach (array_keys($table['columns']) as $column) {
                $destinationTable['columns'][$column]['type'] = (string) (
                    $destinationDefinition['columns'][$column]['type'] ?? ''
                );
            }
            $temporalColumns = $this->temporalColumns(
                $table,
                $destinationTable,
                $sourceDriver,
                $destinationDriver
            );
            $sourceDigest = $this->tableDigest(
                $this->source,
                $table,
                $sourcePhysical,
                $sourceDriver,
                $excluded,
                $temporalColumns
            );
            $destinationDigest = $this->tableDigest(
                $this->destination,
                $destinationTable,
                $destinationPhysical,
                $destinationDriver,
                $excluded,
                $temporalColumns
            );
            if (!hash_equals($sourceDigest, $destinationDigest)) {
                throw new DatabaseAdapterException(
                    'Post-metadata data digest validation failed for logical table ' . $logicalName . '.',
                    'validation_failed'
                );
            }
            $digests[$logicalName] = $destinationDigest;
            $this->checkpoint('validating', [
                'phase' => 'post_metadata',
                'validated_tables' => count($digests),
                'total_tables' => count($this->schema->orderedTables()),
                'table' => $logicalName,
            ]);
        }

        $settingsTable = $this->schema->table('system_settings');
        $destinationSettings = $destinationPrefix . 'system_settings';
        $destinationTotal = $this->rowCountExcluding(
            $this->destination,
            $settingsTable,
            $destinationSettings,
            $destinationDriver,
            []
        );
        $sourceNonMetadata = $this->rowCountExcluding(
            $this->source,
            $settingsTable,
            (string) $settingsTable['physical_name'],
            $sourceDriver,
            $excludedSettings
        );
        if ($destinationTotal !== $sourceNonMetadata + count($metadata)) {
            throw new DatabaseAdapterException(
                'Post-metadata settings inventory validation failed.',
                'validation_failed'
            );
        }
        $quotedSettings = $this->quote($destinationSettings, $destinationDriver);
        foreach ($metadata as $key => $expectedValue) {
            $statement = $this->destination->prepare(
                "SELECT setting_value FROM {$quotedSettings} WHERE setting_key = ?"
            );
            $statement->execute([$key]);
            $values = $statement->fetchAll(PDO::FETCH_COLUMN);
            if (count($values) !== 1 || (string) $values[0] !== $expectedValue) {
                throw new DatabaseAdapterException(
                    'Post-metadata attestation value validation failed.',
                    'validation_failed'
                );
            }
        }
        return [
            'post_metadata_row_counts' => true,
            'post_metadata_null_checks' => $nullChecks,
            'post_metadata_primary_and_data_digests' => count($digests),
            'post_metadata_settings' => true,
        ];
    }

    /**
     * @param array<string, mixed> $table
     * @param array<string, true> $temporalColumns
     */
    private function tableDigest(
        PDO $pdo,
        array $table,
        string $physicalName,
        string $driver,
        array $excludedPrimaryValues = [],
        array $temporalColumns = []
    ): string
    {
        $columns = array_keys($table['columns']);
        $sql = 'SELECT ' . implode(', ', array_map(
            fn (string $column): string => $this->quote($column, $driver),
            $columns
        )) . ' FROM ' . $this->quote($physicalName, $driver);
        $parameters = [];
        if ($excludedPrimaryValues !== []) {
            $primary = array_values($table['primary_key'] ?? []);
            if (count($primary) !== 1) {
                throw new DatabaseAdapterException(
                    'Filtered digest requires a single-column primary key.',
                    'invalid_schema'
                );
            }
            $sql .= ' WHERE ' . $this->quote($primary[0], $driver) . ' NOT IN ('
                . implode(', ', array_fill(0, count($excludedPrimaryValues), '?')) . ')';
            $parameters = array_values($excludedPrimaryValues);
        }
        $order = array_values($table['primary_key'] ?? []);
        if ($order === []) {
            $order = $columns;
        }
        $sql .= ' ORDER BY ' . implode(', ', $this->digestOrderExpressions($table, $order, $driver));
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
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
                    $value,
                    isset($temporalColumns[$column])
                );
                hash_update($hash, 'V' . strlen($value) . ':' . $value . ';');
            }
            hash_update($hash, "\n");
        }
        return hash_final($hash);
    }

    /**
     * SQL collations are not portable: SQLite BINARY, MySQL unicode_ci, and a
     * PostgreSQL locale can all produce different row order for the same text
     * primary key. Digest ordering therefore pins textual keys to their UTF-8
     * byte sequence and makes NULL placement explicit.
     *
     * @param array<string, mixed> $table
     * @param list<string> $columns
     * @return list<string>
     */
    private function digestOrderExpressions(array $table, array $columns, string $driver): array
    {
        $expressions = [];
        foreach ($columns as $column) {
            $quoted = $this->quote($column, $driver);
            $expressions[] = 'CASE WHEN ' . $quoted . ' IS NULL THEN 0 ELSE 1 END ASC';
            $type = strtoupper((string) ($table['columns'][$column]['type'] ?? ''));
            $textual = preg_match('/\b(?:CHAR|VARCHAR|TEXT|CLOB|ENUM|SET|UUID|DATE|TIME)\b/', $type) === 1
                || str_contains($type, 'CHARACTER VARYING');
            if (!$textual) {
                $expressions[] = $quoted . ' ASC';
                continue;
            }
            $expressions[] = match ($driver) {
                'sqlite' => 'CAST(' . $quoted . ' AS BLOB) ASC',
                'mysql' => 'CAST(' . $quoted . ' AS BINARY) ASC',
                'pgsql' => "convert_to(CAST({$quoted} AS TEXT), 'UTF8') ASC",
                default => throw new DatabaseAdapterException(
                    'Unsupported digest ordering driver.',
                    'driver_mismatch'
                ),
            };
        }
        return $expressions;
    }

    /**
     * Return columns whose representation may differ solely because at least
     * one endpoint exposes a driver-native timestamp/datetime physical type.
     * SQLite has no native temporal storage class, even when a column happens
     * to be declared DATETIME. A plugin-owned TEXT-to-TEXT value therefore
     * remains byte-exact; column naming alone never enables normalization.
     *
     * @param array<string, mixed> $sourceTable
     * @param array<string, mixed> $destinationTable
     * @return array<string, true>
     */
    private function temporalColumns(
        array $sourceTable,
        array $destinationTable,
        string $sourceDriver,
        string $destinationDriver
    ): array
    {
        $columns = [];
        foreach (array_keys($sourceTable['columns'] ?? []) as $column) {
            $sourceType = (string) ($sourceTable['columns'][$column]['type'] ?? '');
            $destinationType = (string) ($destinationTable['columns'][$column]['type'] ?? '');
            if ($this->isNativeDateTimeType($sourceDriver, $sourceType)
                || $this->isNativeDateTimeType($destinationDriver, $destinationType)) {
                $columns[(string) $column] = true;
            }
        }
        return $columns;
    }

    private function isNativeDateTimeType(string $driver, string $type): bool
    {
        return match ($driver) {
            'mysql' => preg_match('/\b(?:TIMESTAMP|DATETIME)\b/i', $type) === 1,
            'pgsql' => preg_match('/\bTIMESTAMP\b/i', $type) === 1,
            // SQLite's declared type controls affinity, not a native temporal
            // value representation. Treating it as native could hide a real
            // TEXT change in a plugin-owned table.
            'sqlite' => false,
            default => false,
        };
    }

    private function normalizedDigestValue(
        string $column,
        string $type,
        mixed $value,
        bool $normalizeTemporal = false
    ): string
    {
        // pdo_pgsql exposes native BOOLEAN false as false (which casts to an
        // empty string), while SQLite/MySQL expose the same logical value as
        // 0. Preserve logical equality before converting database scalars to
        // their digest representation.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        $value = (string) $value;
        // Native drivers render the same timezone-free local timestamp with
        // different separators and may omit insignificant trailing fractional
        // zeroes. Canonicalize only the exact lossless 0..6 digit grammar. A
        // seventh digit, timezone, offset, whitespace, or malformed value is
        // deliberately left byte-exact so rounding/truncation still fails.
        if ($normalizeTemporal
            && preg_match(
                '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?$/D',
                $value,
                $matches
            ) === 1) {
            $value = $matches[1] . ' ' . $matches[2] . '.'
                . str_pad((string) ($matches[3] ?? ''), 6, '0', STR_PAD_RIGHT);
        }
        if (!str_ends_with(strtolower($column), '_json') && !str_contains(strtoupper($type), 'JSON')) {
            $normalizedType = strtoupper(preg_replace('/\s+/', ' ', trim($type)) ?? trim($type));
            if (preg_match(
                '/\b(?:TINYINT|SMALLINT|MEDIUMINT|INT|INTEGER|BIGINT|DECIMAL|NUMERIC|REAL|DOUBLE|FLOAT)\b/',
                $normalizedType
            ) === 1) {
                return $this->normalizedNumericDigestValue($value);
            }
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

    private function normalizedNumericDigestValue(string $value): string
    {
        $candidate = trim($value);
        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]*))?(?:[eE]([+-]?[0-9]+))?$/D', $candidate, $match) !== 1) {
            return $value;
        }
        $fraction = (string) ($match[3] ?? '');
        $exponent = isset($match[4]) && $match[4] !== '' ? (int) $match[4] : 0;
        // Database numeric declarations in the canonical schema are bounded.
        // Refuse pathological exponent expansion rather than allocating an
        // attacker-controlled string during validation.
        if (abs($exponent) > 4096) {
            return strtolower($candidate);
        }
        $digits = (string) $match[2] . $fraction;
        $scale = strlen($fraction) - $exponent;
        if ($scale <= 0) {
            $integer = $digits . str_repeat('0', -$scale);
            $decimal = '';
        } elseif (strlen($digits) <= $scale) {
            $integer = '0';
            $decimal = str_repeat('0', $scale - strlen($digits)) . $digits;
        } else {
            $integer = substr($digits, 0, -$scale);
            $decimal = substr($digits, -$scale);
        }
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $decimal = rtrim($decimal, '0');
        $sign = (string) $match[1] === '-' ? '-' : '';
        if ($integer === '0' && $decimal === '') {
            $sign = '';
        }
        return $sign . $integer . ($decimal === '' ? '' : '.' . $decimal);
    }

    /** @param array<string, mixed>|null $definition */
    private function nullCount(
        PDO $pdo,
        string $table,
        string $column,
        string $driver,
        ?array $definition = null,
        array $excludedPrimaryValues = []
    ): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote($table, $driver)
            . ' WHERE ' . $this->quote($column, $driver) . ' IS NULL';
        $parameters = [];
        if ($excludedPrimaryValues !== []) {
            $primary = array_values($definition['primary_key'] ?? []);
            if (count($primary) !== 1) {
                throw new DatabaseAdapterException(
                    'Filtered NULL validation requires a single-column primary key.',
                    'invalid_schema'
                );
            }
            $sql .= ' AND ' . $this->quote($primary[0], $driver) . ' NOT IN ('
                . implode(', ', array_fill(0, count($excludedPrimaryValues), '?')) . ')';
            $parameters = array_values($excludedPrimaryValues);
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $table */
    private function rowCountExcluding(
        PDO $pdo,
        array $table,
        string $physicalName,
        string $driver,
        array $excludedPrimaryValues
    ): int {
        $sql = 'SELECT COUNT(*) FROM ' . $this->quote($physicalName, $driver);
        $parameters = [];
        if ($excludedPrimaryValues !== []) {
            $primary = array_values($table['primary_key'] ?? []);
            if (count($primary) !== 1) {
                throw new DatabaseAdapterException(
                    'Filtered row validation requires a single-column primary key.',
                    'invalid_schema'
                );
            }
            $sql .= ' WHERE ' . $this->quote($primary[0], $driver) . ' NOT IN ('
                . implode(', ', array_fill(0, count($excludedPrimaryValues), '?')) . ')';
            $parameters = array_values($excludedPrimaryValues);
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    private function resetPostgreSqlIdentities(): void
    {
        $prefix = $this->prefix($this->destination);
        foreach ($this->schema->tables() as $logicalName => $table) {
            foreach ($table['columns'] as $column) {
                if (!($column['auto_increment'] ?? false)) {
                    continue;
                }
                $physical = $prefix . $logicalName;
                $columnName = (string) $column['name'];
                $sequenceStatement = $this->destination->prepare('SELECT pg_get_serial_sequence(?, ?)');
                $sequenceStatement->execute([$physical, $columnName]);
                $sequence = $sequenceStatement->fetchColumn();
                if (!is_string($sequence) || $sequence === '') {
                    continue;
                }
                $max = (int) $this->destination->query(
                    'SELECT COALESCE(MAX(' . $this->quote($columnName, 'pgsql') . '), 0) FROM '
                    . $this->quote($physical, 'pgsql')
                )->fetchColumn();
                $setValue = $this->destination->prepare('SELECT setval(?::regclass, ?, ?)');
                $setValue->execute([$sequence, max(1, $max), $max > 0 ? 'true' : 'false']);
            }
        }
    }

    private function writeMigrationMetadata(string $destinationDriver, string $sourceDriver): void
    {
        $table = $this->quote($this->prefix($this->destination) . 'system_settings', $destinationDriver);
        $delete = $this->destination->prepare("DELETE FROM {$table} WHERE setting_key = ?");
        $insert = $this->destination->prepare(
            "INSERT INTO {$table} (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)"
        );
        $settings = [
            'database.schema_version' => (string) LogicalDatabaseSchema::VERSION,
            'database.migration_version' => '1',
            'database.migrated_from' => $sourceDriver,
        ];
        foreach ($settings as $key => $value) {
            $delete->execute([$key]);
            $insert->execute([$key, $value]);
            $verify = $this->destination->prepare("SELECT setting_value FROM {$table} WHERE setting_key = ?");
            $verify->execute([$key]);
            if ((string) $verify->fetchColumn() !== $value) {
                throw new DatabaseAdapterException('Migration metadata validation failed.', 'validation_failed');
            }
        }
    }

    private function writeEmptyCanonicalAttestation(string $destinationDriver): void
    {
        $table = $this->quote($this->prefix($this->destination) . 'system_settings', $destinationDriver);
        $delete = $this->destination->prepare("DELETE FROM {$table} WHERE setting_key = ?");
        $insert = $this->destination->prepare(
            "INSERT INTO {$table} (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)"
        );
        $settings = [
            CoreSchemaRuntime::FINGERPRINT_SETTING => CoreSchemaCatalog::load()->artifactHash(),
            CoreSchemaRuntime::RAG_VERSION_SETTING => (string) CoreSchemaRuntime::RAG_SCHEMA_VERSION,
        ];
        foreach ($settings as $key => $value) {
            $delete->execute([$key]);
            $insert->execute([$key, $value]);
        }
    }

    private function validateEmptyCanonicalAttestation(string $destinationDriver): void
    {
        $prefix = $this->prefix($this->destination);
        $settings = [
            CoreSchemaRuntime::VERSION_SETTING => (string) LogicalDatabaseSchema::VERSION,
            CoreSchemaRuntime::FINGERPRINT_SETTING => CoreSchemaCatalog::load()->artifactHash(),
            CoreSchemaRuntime::RAG_VERSION_SETTING => (string) CoreSchemaRuntime::RAG_SCHEMA_VERSION,
            'database.migration_version' => '1',
            'database.migrated_from' => 'pgsql',
        ];
        $table = $this->quote($prefix . 'system_settings', $destinationDriver);
        if ((int) $this->destination->query("SELECT COUNT(*) FROM {$table}")->fetchColumn()
            !== count($settings)) {
            throw new DatabaseAdapterException(
                'Empty canonical metadata inventory validation failed.',
                'validation_failed'
            );
        }
        $read = $this->destination->prepare(
            "SELECT setting_value FROM {$table} WHERE setting_key = ?"
        );
        foreach ($settings as $key => $expected) {
            $read->execute([$key]);
            $values = $read->fetchAll(PDO::FETCH_COLUMN);
            if (count($values) !== 1 || !hash_equals($expected, (string) $values[0])) {
                throw new DatabaseAdapterException(
                    'Empty canonical metadata validation failed.',
                    'validation_failed'
                );
            }
        }
        foreach ($this->schema->orderedTables() as $logicalName) {
            if ($logicalName === 'system_settings') {
                continue;
            }
            if ((int) $this->destination->query(
                'SELECT COUNT(*) FROM ' . $this->quote($prefix . $logicalName, $destinationDriver)
            )->fetchColumn() !== 0) {
                throw new DatabaseAdapterException(
                    'Empty canonical destination changed during attestation.',
                    'validation_failed'
                );
            }
        }
    }

    /** @return list<string> */
    private function physicalTables(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = match ($driver) {
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN),
            'mysql' => $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),
            'pgsql' => $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN),
            default => throw new DatabaseAdapterException('Unsupported destination driver.', 'driver_mismatch'),
        };
        return array_values(array_map('strval', $rows));
    }

    private function prefix(PDO $pdo): string
    {
        return method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
    }

    private function quote(string $identifier, string $driver): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException('Database identifier is invalid.', 'invalid_schema');
        }
        return $driver === 'pgsql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }
}
