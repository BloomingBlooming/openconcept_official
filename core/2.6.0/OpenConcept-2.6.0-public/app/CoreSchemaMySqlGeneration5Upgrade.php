<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaSignature.php';
require_once __DIR__ . '/CoreSchemaTriggerVerifier.php';
require_once __DIR__ . '/CoreSchemaVerifier.php';

/**
 * Crash-resumable MySQL physical upgrade from sealed Core generation 4 to 5.
 *
 * MySQL cannot commit ALTER TABLE statements for 32 tables in one database
 * transaction. The application-wide writer lease and MySQL advisory lock are
 * therefore owned by CoreSchemaRuntime. This helper makes the physical part
 * honestly forward-only: it commits a deterministic intent before the first
 * ALTER, accepts each changed table only when it is exactly v4 or exactly v5,
 * and writes the v5 attestation only after every table verifies as v5.
 *
 * Each ALTER widens temporal precision and is atomic for its individual
 * table. A process failure can consequently leave only a known prefix of
 * complete v5 tables. Any within-table hybrid or unrelated drift is fatal.
 */
final class CoreSchemaMySqlGeneration5Upgrade
{
    public const INTENT_SETTING = 'database.schema_upgrade_intent';

    private const INTENT_FORMAT = 'openconcept-core-mysql-generation-upgrade';
    private const INTENT_FORMAT_VERSION = 1;
    private const SOURCE_GENERATION = 4;
    private const TARGET_GENERATION = 5;
    private const EXPECTED_CHANGED_TABLES = 32;
    private const EXPECTED_CHANGED_COLUMNS = 65;

    /**
     * @var array<string, list<array{
     *   name:string,
     *   definition:string,
     *   old:array<string,mixed>,
     *   new:array<string,mixed>
     * }>>
     */
    private array $plan;

    private string $planHash;
    private string $intentValue;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CoreSchemaCatalog $sourceCatalog,
        private readonly CoreSchemaCatalog $targetCatalog
    ) {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new CoreSchemaException(['reason' => 'driver_profile_unsupported', 'driver' => 'mysql']);
        }
        if ($sourceCatalog->schemaVersion() !== self::SOURCE_GENERATION
            || $targetCatalog->schemaVersion() !== self::TARGET_GENERATION) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_catalog_invalid',
                'from_schema_version' => $sourceCatalog->schemaVersion(),
                'target_schema_version' => $targetCatalog->schemaVersion(),
            ]);
        }

        $this->plan = $this->buildPlan();
        $planEvidence = [];
        foreach ($this->plan as $table => $columns) {
            $planEvidence[$table] = array_map(
                static fn (array $column): array => [
                    'name' => $column['name'],
                    'old_type' => $column['old']['type'],
                    'old_default' => $column['old']['default'],
                    'new_type' => $column['new']['type'],
                    'new_default' => $column['new']['default'],
                ],
                $columns
            );
        }
        $this->planHash = 'sha256:' . hash(
            'sha256',
            CoreSchemaCatalog::canonicalJson($planEvidence)
        );
        $this->intentValue = CoreSchemaCatalog::canonicalJson([
            'driver' => 'mysql',
            'format' => self::INTENT_FORMAT,
            'format_version' => self::INTENT_FORMAT_VERSION,
            'from_artifact_hash' => $sourceCatalog->artifactHash(),
            'from_schema_version' => self::SOURCE_GENERATION,
            'plan_hash' => $this->planHash,
            'target_artifact_hash' => $targetCatalog->artifactHash(),
            'target_schema_version' => self::TARGET_GENERATION,
        ]);
    }

    /**
     * Prove either an untouched sealed-v4 source or a resumable, exact
     * table-granular v4/v5 state accompanied by our deterministic intent.
     *
     * @return array{intent_present:bool,old_tables:int,new_tables:int,unchanged_tables:int}
     */
    public function preflight(): array
    {
        $storedIntent = $this->readIntent();
        if ($storedIntent === null) {
            (new CoreSchemaVerifier($this->sourceCatalog))->verify($this->pdo);
            return [
                'intent_present' => false,
                'old_tables' => count($this->plan),
                'new_tables' => 0,
                'unchanged_tables' => count($this->sourceCatalog->tableNames('mysql')) - count($this->plan),
            ];
        }
        if (!hash_equals($this->intentValue, $storedIntent)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_intent_mismatch',
                'from_schema_version' => self::SOURCE_GENERATION,
                'target_schema_version' => self::TARGET_GENERATION,
            ]);
        }
        return $this->classifyLiveTables() + ['intent_present' => true];
    }

    /**
     * Complete or resume the physical migration. The callbacks run inside the
     * final marker transaction; the first writes the three canonical markers
     * and the second proves that they were written in marker-last order.
     *
     * @param callable():void $writeAttestation
     * @param callable():void $assertAttestation
     * @param null|callable(string,int):void $afterTablePersisted Focused fault-boundary observer.
     * @return array<string,mixed>
     */
    public function upgrade(
        callable $writeAttestation,
        callable $assertAttestation,
        ?callable $afterTablePersisted = null
    ): array {
        $state = $this->preflight();
        if ($state['intent_present'] !== true) {
            $this->persistIntentAfterLockedRecheck();
            $state = $this->classifyLiveTables() + ['intent_present' => true];
        }

        $converted = 0;
        $alreadyConverted = 0;
        foreach ($this->plan as $logicalTable => $columns) {
            $tableState = $this->classifyOneTable($logicalTable);
            if ($tableState === 'new') {
                $alreadyConverted++;
                continue;
            }
            if ($tableState !== 'old') {
                // classifyOneTable currently returns only old/new or throws;
                // retain an explicit guard so future extensions stay closed.
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_table_hybrid',
                    'table' => $logicalTable,
                ]);
            }
            $definitions = implode(', ', array_map(
                static fn (array $column): string => 'MODIFY COLUMN '
                    . self::quoteIdentifier($column['name']) . ' ' . $column['definition'],
                $columns
            ));
            $physicalTable = $this->prefix() . $logicalTable;
            try {
                $this->pdo->exec(
                    'ALTER TABLE ' . self::quoteIdentifier($physicalTable) . ' ' . $definitions
                );
            } catch (Throwable $exception) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_ddl_failed',
                    'table' => $logicalTable,
                    'from_schema_version' => self::SOURCE_GENERATION,
                    'target_schema_version' => self::TARGET_GENERATION,
                ], $exception);
            }
            if ($this->classifyOneTable($logicalTable) !== 'new') {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_table_not_persisted',
                    'table' => $logicalTable,
                ]);
            }
            $converted++;
            if ($afterTablePersisted !== null) {
                $afterTablePersisted($logicalTable, $converted);
            }
        }

        $finalState = $this->classifyLiveTables();
        if ($finalState['old_tables'] !== 0
            || $finalState['new_tables'] !== count($this->plan)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_incomplete',
                'old_tables' => $finalState['old_tables'],
                'new_tables' => $finalState['new_tables'],
            ]);
        }
        $targetVerifier = new CoreSchemaVerifier($this->targetCatalog);
        $targetVerifier->verify($this->pdo);

        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_generation_upgrade_inside_transaction']);
        }
        $this->pdo->beginTransaction();
        try {
            // Repeat the complete physical proof inside the marker transaction.
            $targetVerifier->verify($this->pdo);
            $delete = $this->pdo->prepare(
                'DELETE FROM system_settings WHERE setting_key = ? AND setting_value = ?'
            );
            $delete->execute([self::INTENT_SETTING, $this->intentValue]);
            if ($delete->rowCount() !== 1) {
                throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_intent_release_failed']);
            }
            // Intent removal precedes the attestation writes so the global
            // schema version remains literally the final mutation. A failure
            // anywhere below rolls this DELETE back and preserves resumability.
            $writeAttestation();
            $assertAttestation();
            $targetVerifier->verify($this->pdo);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_attestation_failed',
                'from_schema_version' => self::SOURCE_GENERATION,
                'target_schema_version' => self::TARGET_GENERATION,
            ], $exception);
        }

        return $targetVerifier->verify($this->pdo) + [
            'migration' => [
                'from_schema_version' => self::SOURCE_GENERATION,
                'to_schema_version' => self::TARGET_GENERATION,
                'physical_atomic' => false,
                'activation_atomic' => true,
                'crash_resumable' => true,
                'plan_hash' => $this->planHash,
                'changed_tables' => count($this->plan),
                'changed_columns' => self::EXPECTED_CHANGED_COLUMNS,
                'converted_tables' => $converted,
                'already_converted_tables' => $alreadyConverted,
            ],
        ];
    }

    /** @return array<string,list<array{name:string,definition:string,old:array<string,mixed>,new:array<string,mixed>}>> */
    private function buildPlan(): array
    {
        $sourceTables = $this->sourceCatalog->tables('mysql');
        $targetTables = $this->targetCatalog->tables('mysql');
        if (array_keys($sourceTables) !== array_keys($targetTables)) {
            throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_table_set_changed']);
        }

        $plan = [];
        $changedColumnCount = 0;
        foreach ($sourceTables as $logicalTable => $sourceTable) {
            $targetTable = $targetTables[$logicalTable];
            $sourceWithoutColumns = $sourceTable;
            $targetWithoutColumns = $targetTable;
            unset($sourceWithoutColumns['columns'], $targetWithoutColumns['columns']);
            if (CoreSchemaCatalog::canonicalJson($sourceWithoutColumns)
                !== CoreSchemaCatalog::canonicalJson($targetWithoutColumns)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_non_temporal_drift',
                    'table' => $logicalTable,
                ]);
            }
            $sourceColumns = $sourceTable['columns'] ?? null;
            $targetColumns = $targetTable['columns'] ?? null;
            if (!is_array($sourceColumns) || !is_array($targetColumns)
                || count($sourceColumns) !== count($targetColumns)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_column_set_changed',
                    'table' => $logicalTable,
                ]);
            }
            foreach ($sourceColumns as $offset => $sourceColumn) {
                $targetColumn = $targetColumns[$offset] ?? null;
                if (!is_array($sourceColumn) || !is_array($targetColumn)
                    || ($sourceColumn['name'] ?? null) !== ($targetColumn['name'] ?? null)) {
                    throw new CoreSchemaException([
                        'reason' => 'mysql_generation_upgrade_column_set_changed',
                        'table' => $logicalTable,
                    ]);
                }
                if (CoreSchemaCatalog::canonicalJson($sourceColumn)
                    === CoreSchemaCatalog::canonicalJson($targetColumn)) {
                    continue;
                }
                $this->assertTemporalWidening($logicalTable, $sourceColumn, $targetColumn);
                $name = (string) $targetColumn['name'];
                $plan[$logicalTable][] = [
                    'name' => $name,
                    'definition' => $this->targetColumnDefinition($logicalTable, $targetColumn),
                    'old' => $sourceColumn,
                    'new' => $targetColumn,
                ];
                $changedColumnCount++;
            }
        }
        ksort($plan, SORT_STRING);
        if (count($plan) !== self::EXPECTED_CHANGED_TABLES
            || $changedColumnCount !== self::EXPECTED_CHANGED_COLUMNS) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_plan_cardinality_mismatch',
                'changed_tables' => count($plan),
                'changed_columns' => $changedColumnCount,
            ]);
        }
        return $plan;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $target */
    private function assertTemporalWidening(string $table, array $source, array $target): void
    {
        $name = (string) ($source['name'] ?? '');
        $sourceType = (string) ($source['type'] ?? '');
        $targetType = (string) ($target['type'] ?? '');
        if (!in_array($sourceType, ['TIMESTAMP', 'DATETIME'], true)
            || $targetType !== $sourceType . '(6)') {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_not_precision_widening',
                'table' => $table,
                'column' => $name,
            ]);
        }
        $sourceStable = $source;
        $targetStable = $target;
        unset($sourceStable['type'], $sourceStable['default']);
        unset($targetStable['type'], $targetStable['default']);
        if (CoreSchemaCatalog::canonicalJson($sourceStable)
            !== CoreSchemaCatalog::canonicalJson($targetStable)
            || ($target['default'] ?? null) !== $this->widenedDefault($source['default'] ?? null)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_not_precision_widening',
                'table' => $table,
                'column' => $name,
            ]);
        }
    }

    private function widenedDefault(mixed $sourceDefault): mixed
    {
        if ($sourceDefault === null) {
            return null;
        }
        if (!is_string($sourceDefault)
            || preg_match(
                '/^expression:CURRENT_TIMESTAMP(?:;on_update:expression:CURRENT_TIMESTAMP)?$/D',
                $sourceDefault
            ) !== 1) {
            return new stdClass();
        }
        return str_replace('CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP(6)', $sourceDefault);
    }

    /** @param array<string,mixed> $column */
    private function targetColumnDefinition(string $table, array $column): string
    {
        $type = (string) ($column['type'] ?? '');
        if (!in_array($type, ['TIMESTAMP(6)', 'DATETIME(6)'], true)
            || ($column['identity'] ?? null) !== 'none'
            || ($column['primary_position'] ?? null) !== 0
            || ($column['collation'] ?? null) !== null) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_target_column_invalid',
                'table' => $table,
                'column' => (string) ($column['name'] ?? ''),
            ]);
        }
        $definition = $type . (($column['nullable'] ?? null) === true ? ' NULL' : ' NOT NULL');
        $default = $column['default'] ?? null;
        if ($default === null) {
            return $definition;
        }
        if (!is_string($default)
            || preg_match(
                '/^expression:CURRENT_TIMESTAMP\(6\)(;on_update:expression:CURRENT_TIMESTAMP\(6\))?$/D',
                $default,
                $matches
            ) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_target_default_invalid',
                'table' => $table,
                'column' => (string) ($column['name'] ?? ''),
            ]);
        }
        $definition .= ' DEFAULT CURRENT_TIMESTAMP(6)';
        if (isset($matches[1]) && $matches[1] !== '') {
            $definition .= ' ON UPDATE CURRENT_TIMESTAMP(6)';
        }
        return $definition;
    }

    private function persistIntentAfterLockedRecheck(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_generation_upgrade_inside_transaction']);
        }
        $this->pdo->beginTransaction();
        try {
            (new CoreSchemaVerifier($this->sourceCatalog))->verify($this->pdo);
            if ($this->readIntent() !== null) {
                throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_intent_race']);
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO system_settings (setting_key, setting_value, updated_at) '
                . 'VALUES (?, ?, CURRENT_TIMESTAMP)'
            );
            $insert->execute([self::INTENT_SETTING, $this->intentValue]);
            if ($insert->rowCount() !== 1 || !hash_equals($this->intentValue, (string) $this->readIntent())) {
                throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_intent_not_persisted']);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_intent_write_failed'], $exception);
        }
    }

    /** @return array{old_tables:int,new_tables:int,unchanged_tables:int} */
    private function classifyLiveTables(): array
    {
        $tableNames = $this->targetCatalog->tableNames('mysql');
        $inspection = (new CoreSchemaSignature())->inspect($this->pdo, $tableNames);
        $this->assertRelationInventory($inspection, $tableNames);
        (new CoreSchemaTriggerVerifier())->verify($this->pdo);

        $old = 0;
        $new = 0;
        $unchanged = 0;
        foreach ($tableNames as $logicalTable) {
            $actual = $inspection['tables'][$logicalTable] ?? null;
            if (!is_array($actual)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_table_hybrid',
                    'table' => $logicalTable,
                    'state' => 'missing',
                ]);
            }
            $source = $this->sourceCatalog->table('mysql', $logicalTable);
            $target = $this->targetCatalog->table('mysql', $logicalTable);
            $matchesSource = CoreSchemaCatalog::canonicalJson($actual)
                === CoreSchemaCatalog::canonicalJson($source);
            $matchesTarget = CoreSchemaCatalog::canonicalJson($actual)
                === CoreSchemaCatalog::canonicalJson($target);
            if (!isset($this->plan[$logicalTable])) {
                if (!$matchesSource || !$matchesTarget) {
                    throw new CoreSchemaException([
                        'reason' => 'mysql_generation_upgrade_table_hybrid',
                        'table' => $logicalTable,
                        'state' => 'unchanged_table_drift',
                    ]);
                }
                $unchanged++;
                continue;
            }
            if ($matchesSource === $matchesTarget) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_table_hybrid',
                    'table' => $logicalTable,
                    'state' => $matchesSource ? 'catalog_ambiguous' : 'unknown',
                ]);
            }
            if ($matchesSource) {
                $old++;
            } else {
                $new++;
            }
        }
        return ['old_tables' => $old, 'new_tables' => $new, 'unchanged_tables' => $unchanged];
    }

    private function classifyOneTable(string $logicalTable): string
    {
        $inspection = (new CoreSchemaSignature())->inspect($this->pdo, [$logicalTable]);
        $actual = $inspection['tables'][$logicalTable] ?? null;
        if (!is_array($actual)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_table_hybrid',
                'table' => $logicalTable,
                'state' => 'missing',
            ]);
        }
        $source = $this->sourceCatalog->table('mysql', $logicalTable);
        $target = $this->targetCatalog->table('mysql', $logicalTable);
        if (CoreSchemaCatalog::canonicalJson($actual) === CoreSchemaCatalog::canonicalJson($source)) {
            return 'old';
        }
        if (CoreSchemaCatalog::canonicalJson($actual) === CoreSchemaCatalog::canonicalJson($target)) {
            return 'new';
        }
        throw new CoreSchemaException([
            'reason' => 'mysql_generation_upgrade_table_hybrid',
            'table' => $logicalTable,
            'state' => 'unknown',
        ]);
    }

    /** @param array<string,mixed> $inspection @param list<string> $expectedNames */
    private function assertRelationInventory(array $inspection, array $expectedNames): void
    {
        $prefix = (string) ($inspection['prefix'] ?? '');
        $expected = array_fill_keys($expectedNames, true);
        $seen = [];
        foreach ($inspection['relations'] ?? [] as $relation) {
            if (!is_array($relation) || ($relation['scoped'] ?? false) !== true) {
                continue;
            }
            $classification = (string) ($relation['classification'] ?? 'unclassified');
            $logicalName = (string) ($relation['logical_name'] ?? '');
            if ($classification === 'unclassified') {
                throw new CoreSchemaException([
                    'reason' => 'canonical_schema_mismatch',
                    'mismatches' => [[
                        'kind' => 'unclassified_relation',
                        'table' => $logicalName,
                    ]],
                ]);
            }
            if ($classification !== 'core') {
                continue;
            }
            if (!isset($expected[$logicalName])
                || isset($seen[$logicalName])
                || ($relation['type'] ?? null) !== 'table'
                || ($relation['physical_name'] ?? null) !== $prefix . $logicalName) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_generation_upgrade_relation_inventory_invalid',
                    'table' => $logicalName,
                ]);
            }
            $seen[$logicalName] = true;
        }
        $missing = array_values(array_diff($expectedNames, array_keys($seen)));
        if ($missing !== []) {
            throw new CoreSchemaException([
                'reason' => 'mysql_generation_upgrade_relation_inventory_invalid',
                'missing_tables' => $missing,
            ]);
        }
    }

    private function readIntent(): ?string
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT setting_value FROM system_settings WHERE setting_key = ?'
            );
            $statement->execute([self::INTENT_SETTING]);
            $value = $statement->fetchColumn();
            return $value === false || $value === null ? null : (string) $value;
        } catch (Throwable $exception) {
            throw new CoreSchemaException(['reason' => 'mysql_generation_upgrade_intent_unreadable'], $exception);
        }
    }

    private function prefix(): string
    {
        $prefix = method_exists($this->pdo, 'tablePrefix')
            ? (string) $this->pdo->tablePrefix()
            : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new CoreSchemaException(['reason' => 'table_prefix_invalid']);
        }
        return $prefix;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'mysql_identifier_unsupported',
                'identifier_hash' => hash('sha256', $identifier),
            ]);
        }
        return '`' . $identifier . '`';
    }
}
