<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaPostgreSqlIndexes.php';
require_once __DIR__ . '/PrefixedPDO.php';

/**
 * Produces deterministic, driver-specific physical schema signatures.
 *
 * It never reads database.schema_version (or any other marker). Only live
 * catalogs are evidence. Cross-driver type projection is intentionally absent.
 */
final class CoreSchemaSignature
{
    /** @var array<string, true> */
    private const MARIADB_JSON_COLUMNS = [
        'ai_chat_turns.sources_json' => true,
        'audit_logs.detail_json' => true,
        'pages.blocks_json' => true,
        'pages.manual_tags_json' => true,
        'pages.tags_json' => true,
        'pages.translation_block_map_json' => true,
        'rag_block_metadata.entities_json' => true,
        'rag_block_metadata.keywords_json' => true,
        'rag_chunks.heading_path_json' => true,
        'rag_chunks.keywords_json' => true,
        'rag_chunks.unit_ids_json' => true,
        'rag_page_profiles.entities_json' => true,
        'rag_page_profiles.keywords_json' => true,
        'rag_page_profiles.questions_json' => true,
        'rag_page_profiles.tags_json' => true,
        'rag_source_documents.blocks_json' => true,
        'rag_source_documents.tags_json' => true,
        'rag_source_units.heading_path_json' => true,
        'revisions.blocks_json' => true,
        'revisions.meta_json' => true,
    ];

    /** @var array<string, true> */
    private const MARIADB_TIMESTAMP_PROJECTIONS = [
        'rag_jobs.available_at' => true,
        'rag_source_documents.source_created_at' => true,
        'rag_source_documents.source_updated_at' => true,
    ];

    /** @return list<string> */
    public static function supportedDrivers(): array
    {
        return CoreSchemaCatalog::REQUIRED_PROFILES;
    }

    /**
     * @param list<string> $logicalNames
     * @return array{
     *   driver: string,
     *   prefix: string,
     *   relations: list<array<string, mixed>>,
     *   tables: array<string, array<string, mixed>>
     * }
     */
    public function inspect(PDO $pdo, array $logicalNames): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, self::supportedDrivers(), true)) {
            throw new CoreSchemaException([
                'reason' => 'driver_profile_unsupported',
                'driver' => $driver,
            ]);
        }
        $logicalNames = $this->normalizeLogicalNames($logicalNames);
        $mySqlServerFlavor = $driver === 'mysql'
            ? $this->mySqlServerFlavor($pdo)
            : 'mysql';
        $this->assertConstraintEnforcementSession(
            $pdo,
            $driver,
            $mySqlServerFlavor === 'mariadb'
        );
        $prefix = $this->prefix($pdo);
        $relations = $this->relationInventory($pdo, $driver, $prefix);
        $relationsByPhysicalName = [];
        foreach ($relations as $relation) {
            $relationsByPhysicalName[$relation['physical_name']] = $relation;
        }
        $corePhysicalNames = array_map(
            static fn (string $logicalName): string => $prefix . $logicalName,
            $logicalNames
        );
        $mySqlInspectionMetadata = $driver === 'mysql'
            ? $this->mySqlInspectionMetadata(
                $pdo,
                $corePhysicalNames,
                $mySqlServerFlavor === 'mariadb'
            )
            : [];
        $this->assertNoTemporaryCoreShadows(
            $pdo,
            $driver,
            $corePhysicalNames,
            $relationsByPhysicalName
        );

        $tables = [];
        foreach ($logicalNames as $logicalName) {
            $physicalName = $prefix . $logicalName;
            $relation = $relationsByPhysicalName[$physicalName] ?? null;
            if (!is_array($relation) || ($relation['type'] ?? null) !== 'table') {
                continue;
            }
            $tables[$logicalName] = match ($driver) {
                'sqlite' => $this->inspectSqliteTable($pdo, $logicalName, $physicalName, $prefix),
                'mysql' => $this->inspectMySqlTable(
                    $pdo,
                    $logicalName,
                    $physicalName,
                    $prefix,
                    $mySqlServerFlavor === 'mariadb',
                    $mySqlInspectionMetadata
                ),
                'pgsql' => $this->inspectPostgreSqlTable($pdo, $logicalName, $physicalName, $prefix),
            };
        }
        ksort($tables, SORT_STRING);
        return [
            'driver' => $driver,
            'prefix' => $prefix,
            'relations' => $relations,
            'tables' => $tables,
        ];
    }

    /**
     * @param list<string> $logicalNames
     * @return array<string, array<string, mixed>>
     */
    public function capture(PDO $pdo, array $logicalNames): array
    {
        $logicalNames = $this->normalizeLogicalNames($logicalNames);
        $inspection = $this->inspect($pdo, $logicalNames);
        $captured = array_keys($inspection['tables']);
        if ($captured !== $logicalNames) {
            throw new CoreSchemaException([
                'reason' => 'fresh_schema_incomplete',
                'driver' => $inspection['driver'],
                'missing_tables' => array_values(array_diff($logicalNames, $captured)),
            ]);
        }
        return $inspection['tables'];
    }

    /**
     * @return list<array{physical_name: string, logical_name: string, type: string, classification: string, scoped: bool}>
     */
    public function relations(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, self::supportedDrivers(), true)) {
            throw new CoreSchemaException(['reason' => 'driver_profile_unsupported', 'driver' => $driver]);
        }
        $prefix = $this->prefix($pdo);
        $relations = $this->relationInventory($pdo, $driver, $prefix);
        $relationsByPhysicalName = [];
        foreach ($relations as $relation) {
            $relationsByPhysicalName[$relation['physical_name']] = $relation;
        }
        $this->assertNoTemporaryCoreShadows(
            $pdo,
            $driver,
            array_map(
                static fn (string $logicalName): string => $prefix . $logicalName,
                PrefixedPDO::logicalTables()
            ),
            $relationsByPhysicalName
        );
        return $relations;
    }

    /** @return array<string, mixed> */
    private function inspectSqliteTable(PDO $pdo, string $logicalName, string $physicalName, string $prefix): array
    {
        $quoted = $this->quote($physicalName, 'sqlite');
        $statement = $pdo->prepare("SELECT sql FROM sqlite_schema WHERE type = 'table' AND name = ?");
        $statement->execute([$physicalName]);
        $createSql = $statement->fetchColumn();
        if (!is_string($createSql) || $createSql === '') {
            throw new CoreSchemaException(['reason' => 'schema_introspection_failed', 'driver' => 'sqlite', 'table' => $logicalName]);
        }

        $columnRows = $this->normalizeCatalogRows(
            $pdo->query("PRAGMA table_xinfo({$quoted})")->fetchAll()
        );
        $primary = [];
        foreach ($columnRows as $column) {
            if ((int) ($column['hidden'] ?? 0) !== 0) {
                throw new CoreSchemaException(['reason' => 'generated_column_unsupported', 'driver' => 'sqlite', 'table' => $logicalName]);
            }
            $position = (int) $column['pk'];
            if ($position > 0) {
                $primary[$position] = (string) $column['name'];
            }
        }
        ksort($primary, SORT_NUMERIC);
        $primaryKey = array_values($primary);
        $withoutRowId = preg_match('/\bWITHOUT\s+ROWID\b/i', $createSql) === 1;
        $hasAutoincrement = preg_match('/\bAUTOINCREMENT\b/i', $createSql) === 1;
        $sqliteCollations = $this->sqliteColumnCollations(
            $createSql,
            array_values(array_map(static fn (array $column): string => strtolower((string) $column['name']), $columnRows))
        );

        $columns = [];
        foreach ($columnRows as $column) {
            $name = strtolower((string) $column['name']);
            $type = $this->normalizeType((string) $column['type']);
            $primaryPosition = (int) $column['pk'];
            $identity = 'none';
            if (!$withoutRowId && count($primaryKey) === 1 && $primaryPosition === 1 && $type === 'INTEGER') {
                $identity = $hasAutoincrement ? 'autoincrement' : 'rowid';
            }
            $columns[] = [
                'name' => $name,
                'type' => $type === '' ? 'BLOB' : $type,
                'type_family' => $this->sqliteAffinity($type),
                'collation' => $sqliteCollations[$name] ?? 'binary',
                'nullable' => (int) $column['notnull'] !== 1 && $primaryPosition === 0,
                'default' => $this->normalizeSqliteDefault($column['dflt_value'] ?? null),
                'identity' => $identity,
                'primary_position' => $primaryPosition,
            ];
        }

        $foreignKeyGroups = [];
        foreach ($this->normalizeCatalogRows(
            $pdo->query("PRAGMA foreign_key_list({$quoted})")->fetchAll()
        ) as $foreignKey) {
            $id = (int) $foreignKey['id'];
            $sequence = (int) $foreignKey['seq'];
            $referencedTable = $this->logicalReference((string) $foreignKey['table'], $prefix);
            $foreignKeyGroups[$id]['columns'][$sequence] = strtolower((string) $foreignKey['from']);
            $foreignKeyGroups[$id]['referenced_columns'][$sequence] = strtolower((string) $foreignKey['to']);
            $foreignKeyGroups[$id]['referenced_table'] = $referencedTable;
            $foreignKeyGroups[$id]['on_update'] = strtoupper((string) $foreignKey['on_update']);
            $foreignKeyGroups[$id]['on_delete'] = strtoupper((string) $foreignKey['on_delete']);
            $foreignKeyGroups[$id]['match'] = strtoupper((string) $foreignKey['match']);
        }
        $foreignKeys = $this->sqliteForeignKeys(
            $createSql,
            $prefix,
            $foreignKeyGroups,
            $logicalName
        );

        $indexes = [];
        foreach ($this->normalizeCatalogRows(
            $pdo->query("PRAGMA index_list({$quoted})")->fetchAll()
        ) as $index) {
            $originCode = strtolower((string) ($index['origin'] ?? 'c'));
            if ($originCode === 'pk') {
                continue;
            }
            $indexName = (string) $index['name'];
            $indexColumns = [];
            foreach ($this->normalizeCatalogRows(
                $pdo->query('PRAGMA index_xinfo(' . $this->quote($indexName, 'sqlite') . ')')->fetchAll()
            ) as $indexColumn) {
                if ((int) ($indexColumn['key'] ?? 1) !== 1) {
                    continue;
                }
                $columnName = $indexColumn['name'] ?? null;
                if (!is_string($columnName) || (int) ($indexColumn['cid'] ?? -1) < 0) {
                    throw new CoreSchemaException(['reason' => 'index_expression_unsupported', 'driver' => 'sqlite', 'table' => $logicalName]);
                }
                $direction = (int) ($indexColumn['desc'] ?? 0) === 1 ? 'DESC' : 'ASC';
                $indexColumns[(int) $indexColumn['seqno']] = [
                    'name' => strtolower($columnName),
                    'collation' => strtoupper((string) ($indexColumn['coll'] ?? '')),
                    'direction' => $direction,
                    'nulls_order' => $direction === 'DESC' ? 'LAST' : 'FIRST',
                    'length' => null,
                    'operator_class' => '',
                    'included' => false,
                ];
            }
            ksort($indexColumns, SORT_NUMERIC);
            if ($indexColumns === []) {
                throw new CoreSchemaException(['reason' => 'index_columns_missing', 'driver' => 'sqlite', 'table' => $logicalName]);
            }
            $partial = (int) ($index['partial'] ?? 0) === 1;
            $indexes[] = [
                'unique' => (int) $index['unique'] === 1,
                'origin' => $originCode === 'u' ? 'unique_constraint' : 'explicit',
                'type' => 'BTREE',
                'partial' => $partial,
                'predicate' => $partial ? $this->sqliteIndexPredicate($pdo, $indexName) : null,
                'deferrable' => false,
                'initially_deferred' => false,
                'validated' => true,
                'nulls_not_distinct' => false,
                'columns' => array_values($indexColumns),
            ];
        }
        $this->sortSemanticList($indexes);
        $checks = $this->sqliteCheckConstraints($createSql);

        return [
            'relation' => 'table',
            'options' => $this->sqliteTableOptions($createSql),
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'primary_key_options' => $this->immediatePrimaryKeyOptions(),
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'checks' => $checks,
        ];
    }

    /** @return array<string, mixed> */
    private function inspectMySqlTable(
        PDO $pdo,
        string $logicalName,
        string $physicalName,
        string $prefix,
        bool $mariaDb,
        array $inspectionMetadata
    ): array
    {
        $createSql = $this->mySqlCreateTableSql($pdo, $physicalName, $logicalName);
        $sqlMode = $inspectionMetadata['sql_mode'] ?? null;
        if (!is_string($sqlMode)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $backslashEscapes = !in_array(
            'NO_BACKSLASH_ESCAPES',
            array_map('strtoupper', array_filter(array_map('trim', explode(',', $sqlMode)))),
            true
        );

        $tableOptionRows = $inspectionMetadata['table_options'][$physicalName] ?? [];
        $tableOptionRow = count($tableOptionRows) === 1 ? $tableOptionRows[0] : null;
        if (!is_array($tableOptionRow)
            || !is_string($tableOptionRow['engine'] ?? null)
            || !is_string($tableOptionRow['table_collation'] ?? null)
            || !is_string($tableOptionRow['row_format'] ?? null)
            || !is_string($tableOptionRow['create_options'] ?? null)
            || !is_string($tableOptionRow['character_set_name'] ?? null)) {
            throw new CoreSchemaException(['reason' => 'schema_introspection_failed', 'driver' => 'mysql', 'table' => $logicalName]);
        }
        $rowFormat = strtoupper(trim((string) $tableOptionRow['row_format']));
        $createOptions = strtoupper(trim(preg_replace(
            '/\s+/',
            ' ',
            (string) $tableOptionRow['create_options']
        ) ?? ''));
        if ($rowFormat === ''
            || preg_match('/^[A-Z0-9_]+$/D', $rowFormat) !== 1
            || $createOptions !== '') {
            throw new CoreSchemaException([
                'reason' => 'table_state_unsupported',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $this->assertMySqlCreateTableTail(
            $createSql,
            $backslashEscapes,
            $logicalName,
            $physicalName,
            (string) $tableOptionRow['engine'],
            (string) $tableOptionRow['character_set_name'],
            (string) $tableOptionRow['table_collation']
        );
        $tableOptions = [
            'engine' => strtoupper((string) $tableOptionRow['engine'])
                . ';ROW_FORMAT=' . $rowFormat
                . ';CREATE_OPTIONS=' . $createOptions,
            'character_set' => strtolower((string) $tableOptionRow['character_set_name']),
            'collation' => strtolower((string) $tableOptionRow['table_collation']),
        ];

        $primary = [];
        foreach (($inspectionMetadata['primary_keys'][$physicalName] ?? []) as $column) {
            $primary[(int) $column['ordinal_position']] = $this->mySqlCanonicalIdentifier(
                $column['column_name'] ?? null,
                $logicalName
            );
        }
        ksort($primary, SORT_NUMERIC);
        $primaryKey = array_values($primary);
        $primaryPositions = array_flip($primaryKey);

        $columnRows = $inspectionMetadata['columns'][$physicalName] ?? [];
        $mariaDbJsonColumns = $mariaDb
            ? $this->mariaDbJsonColumnEvidence($createSql, $backslashEscapes, $logicalName)
            : [];
        $columnTypeFamilies = [];
        foreach ($columnRows as $offset => $column) {
            $name = $column['column_name'] ?? null;
            $typeFamily = $column['data_type'] ?? null;
            $ordinalPosition = $column['ordinal_position'] ?? null;
            if (!is_string($name)
                || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) !== 1
                || !is_string($typeFamily)
                || (int) $ordinalPosition !== $offset + 1) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_catalog_invalid',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $name = $this->mySqlCanonicalIdentifier($name, $logicalName);
            if (array_key_exists($name, $columnTypeFamilies)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_catalog_ambiguous',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $columnTypeFamilies[$name] = isset($mariaDbJsonColumns[$name])
                ? 'JSON'
                : strtoupper($typeFamily);
        }
        $columnClauseEvidence = $this->mySqlColumnClauseEvidence(
            $createSql,
            $columnTypeFamilies,
            $backslashEscapes,
            $logicalName
        );
        $columns = [];
        foreach ($columnRows as $column) {
            $extra = strtoupper(trim(preg_replace(
                '/\s+/',
                ' ',
                (string) ($column['extra'] ?? '')
            ) ?? ''));
            // MySQL reports ordinary expression defaults (including
            // CURRENT_TIMESTAMP) as DEFAULT_GENERATED. That is not a
            // generated column and its expression is already signed through
            // column_default. Reject every other unmodelled column state.
            $onUpdate = match ($extra) {
                'DEFAULT_GENERATED ON UPDATE CURRENT_TIMESTAMP' => 'expression:CURRENT_TIMESTAMP',
                'DEFAULT_GENERATED ON UPDATE CURRENT_TIMESTAMP(6)' => 'expression:CURRENT_TIMESTAMP(6)',
                'ON UPDATE CURRENT_TIMESTAMP' => 'expression:CURRENT_TIMESTAMP',
                'ON UPDATE CURRENT_TIMESTAMP(6)' => 'expression:CURRENT_TIMESTAMP(6)',
                default => null,
            };
            if (trim((string) ($column['generation_expression'] ?? '')) !== ''
                || !in_array($extra, [
                    '',
                    'AUTO_INCREMENT',
                    'DEFAULT_GENERATED',
                    'DEFAULT_GENERATED ON UPDATE CURRENT_TIMESTAMP',
                    'DEFAULT_GENERATED ON UPDATE CURRENT_TIMESTAMP(6)',
                    'ON UPDATE CURRENT_TIMESTAMP',
                    'ON UPDATE CURRENT_TIMESTAMP(6)',
                ], true)) {
                throw new CoreSchemaException([
                    'reason' => 'column_state_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $name = $this->mySqlCanonicalIdentifier(
                $column['column_name'] ?? null,
                $logicalName
            );
            $typeFamily = isset($mariaDbJsonColumns[$name])
                ? 'JSON'
                : strtoupper((string) $column['data_type']);
            if (in_array($typeFamily, ['ENUM', 'SET'], true)) {
                // MySQL exposes ENUM/SET labels through utf8mb3 catalog text,
                // and SHOW CREATE also loses non-BMP labels before returning
                // the statement. These unsupported types cannot be signed.
                throw new CoreSchemaException([
                    'reason' => 'column_state_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $name,
                ]);
            }
            $primaryPosition = isset($primaryPositions[$name]) ? (int) $primaryPositions[$name] + 1 : 0;
            $clauseEvidence = $columnClauseEvidence[$name] ?? null;
            if (!is_array($clauseEvidence)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_clause_missing',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $name,
                ]);
            }
            $informationDefault = $column['column_default'] ?? null;
            $this->assertMySqlInformationDefaultMatchesClause(
                $informationDefault,
                $clauseEvidence,
                $typeFamily,
                $logicalName,
                $name,
                $mariaDb,
                $backslashEscapes
            );
            if (($clauseEvidence['auto_increment'] ?? null) !== ($extra === 'AUTO_INCREMENT')
                || (($clauseEvidence['on_update'] ?? null) !== $onUpdate)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_clause_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $name,
                ]);
            }
            $default = $clauseEvidence['default'] ?? null;
            if ($mariaDb
                && $logicalName === 'pages'
                && $name === 'icon'
                && $default === 'string:?'
                && $this->normalizeType((string) $column['column_type']) === 'VARCHAR(32)') {
                // MariaDB 10.4 stores the utf8mb4 default correctly, but both
                // SHOW CREATE and INFORMATION_SCHEMA project non-BMP text to
                // "?". This is the sole Core default affected by that catalog
                // limitation, so keep the projection deliberately exact.
                $default = "string:\xF0\x9F\x93\x84";
            }
            if (is_string($clauseEvidence['on_update'] ?? null)) {
                // Keep ON UPDATE in the signed default state so removing or
                // adding it cannot hide behind the same column default.
                $default = ($default ?? 'null') . ';on_update:' . $clauseEvidence['on_update'];
            }
            $mariaDbTimestampProjection = $mariaDb
                && isset(self::MARIADB_TIMESTAMP_PROJECTIONS[$logicalName . '.' . $name])
                && strtoupper((string) $column['data_type']) === 'DATETIME'
                && $this->normalizeType((string) $column['column_type']) === 'DATETIME(6)'
                && strtoupper((string) $column['is_nullable']) === 'NO'
                && ($clauseEvidence['default_kind'] ?? null) === 'absent'
                && ($clauseEvidence['on_update'] ?? null) === null
                && $extra === '';
            $columnType = isset($mariaDbJsonColumns[$name])
                ? 'JSON'
                : ($mariaDbTimestampProjection
                    ? 'TIMESTAMP(6)'
                    : $this->normalizeMySqlColumnTypeForServer(
                        (string) $column['column_type'],
                        $mariaDb
                    ));
            if (isset($mariaDbJsonColumns[$name])
                && (strtoupper((string) $column['data_type']) !== 'LONGTEXT'
                    || $this->normalizeType((string) $column['column_type']) !== 'LONGTEXT'
                    || strtolower((string) ($column['collation_name'] ?? '')) !== 'utf8mb4_bin')) {
                throw new CoreSchemaException([
                    'reason' => 'mariadb_json_alias_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $name,
                ]);
            }
            $columns[] = [
                'name' => $name,
                'type' => $columnType,
                'type_family' => $mariaDbTimestampProjection ? 'TIMESTAMP' : $typeFamily,
                'collation' => isset($mariaDbJsonColumns[$name])
                    ? null
                    : (is_string($column['collation_name'] ?? null)
                    ? strtolower((string) $column['collation_name'])
                    : null),
                'nullable' => strtoupper((string) $column['is_nullable']) === 'YES' && $primaryPosition === 0,
                'default' => $default,
                'identity' => $extra === 'AUTO_INCREMENT'
                    ? 'autoincrement'
                    : 'none',
                'primary_position' => $primaryPosition,
            ];
        }

        $foreignKeyGroups = [];
        foreach (($inspectionMetadata['foreign_keys'][$physicalName] ?? []) as $foreignKey) {
            if (!is_string($foreignKey['table_schema'] ?? null)
                || !is_string($foreignKey['referenced_table_schema'] ?? null)
                || $foreignKey['referenced_table_schema'] !== $foreignKey['table_schema']) {
                throw new CoreSchemaException([
                    'reason' => 'foreign_key_target_outside_scope',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $key = (string) $foreignKey['constraint_name'];
            $position = (int) $foreignKey['ordinal_position'];
            $foreignKeyGroups[$key]['columns'][$position] = $this->mySqlCanonicalIdentifier(
                $foreignKey['column_name'] ?? null,
                $logicalName
            );
            $foreignKeyGroups[$key]['referenced_columns'][$position] = $this->mySqlCanonicalIdentifier(
                $foreignKey['referenced_column_name'] ?? null,
                $logicalName
            );
            $foreignKeyGroups[$key]['referenced_table'] = $this->mySqlLogicalReference(
                $foreignKey['referenced_table_name'] ?? null,
                $prefix,
                $logicalName
            );
            $updateRule = strtoupper((string) $foreignKey['update_rule']);
            $deleteRule = strtoupper((string) $foreignKey['delete_rule']);
            $foreignKeyGroups[$key]['on_update'] = $mariaDb && $updateRule === 'RESTRICT'
                ? 'NO ACTION'
                : $updateRule;
            $foreignKeyGroups[$key]['on_delete'] = $mariaDb && $deleteRule === 'RESTRICT'
                ? 'NO ACTION'
                : $deleteRule;
            $foreignKeyGroups[$key]['match'] = 'SIMPLE';
            // MySQL foreign-key constraints are not deferrable.
            $foreignKeyGroups[$key]['deferrable'] = false;
            $foreignKeyGroups[$key]['initially_deferred'] = false;
            $foreignKeyGroups[$key]['validated'] = true;
        }
        $foreignKeys = $this->normalizeForeignKeyGroups($foreignKeyGroups);

        $uniqueConstraints = array_fill_keys(array_map(
            static fn (array $constraint): string => (string) ($constraint['constraint_name'] ?? ''),
            $inspectionMetadata['unique_constraints'][$physicalName] ?? []
        ), true);
        $indexGroups = [];
        foreach (($inspectionMetadata['indexes'][$physicalName] ?? []) as $index) {
            if (strtoupper((string) ($index['is_visible'] ?? '')) !== 'YES') {
                throw new CoreSchemaException([
                    'reason' => 'index_state_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $indexName = (string) $index['index_name'];
            $columnName = $index['column_name'] ?? null;
            if (!is_string($columnName) || $columnName === '') {
                throw new CoreSchemaException(['reason' => 'index_expression_unsupported', 'driver' => 'mysql', 'table' => $logicalName]);
            }
            $indexGroups[$indexName]['unique'] = (int) $index['non_unique'] === 0;
            $indexGroups[$indexName]['origin'] = isset($uniqueConstraints[$indexName])
                ? 'unique_constraint'
                : 'explicit';
            $indexGroups[$indexName]['type'] = strtoupper((string) $index['index_type']);
            $indexGroups[$indexName]['partial'] = false;
            $indexGroups[$indexName]['predicate'] = null;
            $indexGroups[$indexName]['deferrable'] = false;
            $indexGroups[$indexName]['initially_deferred'] = false;
            $indexGroups[$indexName]['validated'] = true;
            $indexGroups[$indexName]['nulls_not_distinct'] = false;
            $direction = strtoupper((string) ($index['collation'] ?? 'A')) === 'D' ? 'DESC' : 'ASC';
            $indexGroups[$indexName]['columns'][(int) $index['seq_in_index']] = [
                'name' => $this->mySqlCanonicalIdentifier($columnName, $logicalName),
                'collation' => '',
                'direction' => $direction,
                'nulls_order' => $direction === 'DESC' ? 'LAST' : 'FIRST',
                'length' => $index['sub_part'] === null ? null : (int) $index['sub_part'],
                'operator_class' => '',
                'included' => false,
            ];
        }
        $indexes = $this->normalizeIndexGroups($indexGroups);

        $ddlChecks = $this->mySqlCheckClauseEvidence(
            $createSql,
            $backslashEscapes,
            $logicalName,
            $mariaDbJsonColumns,
            $mariaDb
        );
        $checks = [];
        $pendingMariaDbJsonChecks = $mariaDbJsonColumns;
        foreach (($inspectionMetadata['checks'][$physicalName] ?? []) as $check) {
            $constraintName = $check['constraint_name'] ?? null;
            if ($mariaDb
                && is_string($constraintName)
                && isset($pendingMariaDbJsonChecks[$constraintName])) {
                $expectedExpression = 'json_valid(`' . str_replace('`', '``', $constraintName) . '`)';
                $informationExpression = $this->mySqlCanonicalTokenSequence(
                    $this->mySqlSqlTokens(
                        (string) ($check['check_clause'] ?? ''),
                        $backslashEscapes,
                        $logicalName
                    )
                );
                if (!hash_equals($expectedExpression, strtolower($informationExpression))
                    || strtoupper((string) ($check['enforced'] ?? '')) !== 'YES') {
                    throw new CoreSchemaException([
                        'reason' => 'mariadb_json_alias_check_mismatch',
                        'driver' => 'mysql',
                        'table' => $logicalName,
                        'column' => $constraintName,
                    ]);
                }
                unset($pendingMariaDbJsonChecks[$constraintName]);
                continue;
            }
            if (!is_string($constraintName)
                || !array_key_exists($constraintName, $ddlChecks)
                || !is_string($check['check_clause'] ?? null)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_check_clause_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $expression = $ddlChecks[$constraintName];
            unset($ddlChecks[$constraintName]);
            $informationExpression = $this->mySqlCanonicalTokenSequence(
                $this->mySqlSqlTokens(
                    (string) $check['check_clause'],
                    $backslashEscapes,
                    $logicalName
                )
            );
            if ($mariaDb) {
                $informationExpression = '(' . $informationExpression . ')';
            }
            if (!hash_equals(
                $this->mySqlUtf8mb3TextProjection($expression),
                $informationExpression
            )) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_check_clause_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $checks[] = [
                'expression' => $expression,
                'enforced' => strtoupper((string) $check['enforced']) === 'YES',
                'validated' => true,
            ];
        }
        if ($ddlChecks !== [] || $pendingMariaDbJsonChecks !== []) {
            throw new CoreSchemaException([
                'reason' => 'mysql_check_clause_catalog_mismatch',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $this->sortSemanticList($checks);

        return [
            'relation' => 'table',
            'options' => $tableOptions,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'primary_key_options' => $this->immediatePrimaryKeyOptions(),
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'checks' => $checks,
        ];
    }

    /**
     * MariaDB 10.4 materializes the information_schema constraint views for
     * every query. Fetch the expensive foreign-key and CHECK catalogs once per
     * exact inspection, then preserve the same per-table validation below.
     *
     * @param list<string> $physicalNames
     * @return array{
     *   sql_mode: string,
     *   table_options: array<string, list<array<string, mixed>>>,
     *   primary_keys: array<string, list<array<string, mixed>>>,
     *   columns: array<string, list<array<string, mixed>>>,
     *   foreign_keys: array<string, list<array<string, mixed>>>,
     *   unique_constraints: array<string, list<array<string, mixed>>>,
     *   indexes: array<string, list<array<string, mixed>>>,
     *   checks: array<string, list<array<string, mixed>>>
     * }
     */
    private function mySqlInspectionMetadata(PDO $pdo, array $physicalNames, bool $mariaDb): array
    {
        $sqlMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!is_string($sqlMode)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
            ]);
        }
        if ($physicalNames === []) {
            return [
                'sql_mode' => $sqlMode,
                'table_options' => [],
                'primary_keys' => [],
                'columns' => [],
                'foreign_keys' => [],
                'unique_constraints' => [],
                'indexes' => [],
                'checks' => [],
            ];
        }

        $expectedNames = array_fill_keys($physicalNames, true);
        $placeholders = implode(', ', array_fill(0, count($physicalNames), '?'));

        $tableOptionStatement = $pdo->prepare(<<<SQL
SELECT table_row.table_name, table_row.engine, table_row.table_collation,
       table_row.row_format, table_row.create_options,
       collation_row.character_set_name
FROM information_schema.tables table_row
LEFT JOIN information_schema.collation_character_set_applicability collation_row
  ON collation_row.collation_name = table_row.table_collation
WHERE table_row.table_schema = DATABASE()
  AND table_row.table_name IN ({$placeholders})
ORDER BY table_row.table_name
SQL);
        $tableOptionStatement->execute($physicalNames);
        $tableOptions = $this->groupMySqlInspectionRows(
            $tableOptionStatement->fetchAll(),
            $expectedNames
        );

        $primaryStatement = $pdo->prepare(<<<SQL
SELECT table_name, column_name, ordinal_position
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND constraint_name = 'PRIMARY'
ORDER BY table_name, ordinal_position
SQL);
        $primaryStatement->execute($physicalNames);
        $primaryKeys = $this->groupMySqlInspectionRows(
            $primaryStatement->fetchAll(),
            $expectedNames
        );

        $columnStatement = $pdo->prepare(<<<SQL
SELECT table_name, column_name, column_type, data_type, is_nullable,
       column_default, extra, generation_expression, collation_name,
       ordinal_position
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
ORDER BY table_name, ordinal_position
SQL);
        $columnStatement->execute($physicalNames);
        $columns = $this->groupMySqlInspectionRows(
            $columnStatement->fetchAll(),
            $expectedNames
        );

        $foreignKeyStatement = $pdo->prepare(<<<SQL
SELECT k.table_name, k.constraint_name, k.ordinal_position, k.column_name,
       k.table_schema, k.referenced_table_schema,
       k.referenced_table_name, k.referenced_column_name,
       r.update_rule, r.delete_rule
FROM information_schema.key_column_usage k
JOIN information_schema.referential_constraints r
  ON r.constraint_schema = k.constraint_schema
 AND r.table_name = k.table_name
 AND r.constraint_name = k.constraint_name
WHERE k.table_schema = DATABASE() AND k.table_name IN ({$placeholders})
  AND k.referenced_table_name IS NOT NULL
ORDER BY k.table_name, k.constraint_name, k.ordinal_position
SQL);
        $foreignKeyStatement->execute($physicalNames);
        $foreignKeys = $this->groupMySqlInspectionRows(
            $foreignKeyStatement->fetchAll(),
            $expectedNames
        );

        $uniqueConstraintStatement = $pdo->prepare(<<<SQL
SELECT table_name, constraint_name
FROM information_schema.table_constraints
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND constraint_type = 'UNIQUE'
ORDER BY table_name, constraint_name
SQL);
        $uniqueConstraintStatement->execute($physicalNames);
        $uniqueConstraints = $this->groupMySqlInspectionRows(
            $uniqueConstraintStatement->fetchAll(),
            $expectedNames
        );

        $indexStatement = $pdo->prepare($mariaDb ? <<<SQL
SELECT table_name, index_name, non_unique, index_type, seq_in_index,
       column_name, collation, sub_part, 'YES' AS is_visible
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND index_name <> 'PRIMARY'
ORDER BY table_name, index_name, seq_in_index
SQL
            : <<<SQL
SELECT table_name, index_name, non_unique, index_type, seq_in_index,
       column_name, collation, sub_part, is_visible
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND index_name <> 'PRIMARY'
ORDER BY table_name, index_name, seq_in_index
SQL);
        $indexStatement->execute($physicalNames);
        $indexes = $this->groupMySqlInspectionRows(
            $indexStatement->fetchAll(),
            $expectedNames
        );

        $checkStatement = $pdo->prepare($mariaDb ? <<<SQL
SELECT constraint_row.table_name, constraint_row.constraint_name,
       check_row.check_clause, 'YES' AS enforced
FROM information_schema.table_constraints constraint_row
JOIN information_schema.check_constraints check_row
  ON check_row.constraint_schema = constraint_row.constraint_schema
 AND check_row.table_name = constraint_row.table_name
 AND check_row.constraint_name = constraint_row.constraint_name
WHERE constraint_row.table_schema = DATABASE()
  AND constraint_row.table_name IN ({$placeholders})
  AND constraint_row.constraint_type = 'CHECK'
ORDER BY constraint_row.table_name, constraint_row.constraint_name
SQL
            : <<<SQL
SELECT constraint_row.table_name, constraint_row.constraint_name,
       check_row.check_clause, constraint_row.enforced
FROM information_schema.table_constraints constraint_row
JOIN information_schema.check_constraints check_row
  ON check_row.constraint_schema = constraint_row.constraint_schema
 AND check_row.constraint_name = constraint_row.constraint_name
WHERE constraint_row.table_schema = DATABASE()
  AND constraint_row.table_name IN ({$placeholders})
  AND constraint_row.constraint_type = 'CHECK'
ORDER BY constraint_row.table_name, constraint_row.constraint_name
SQL);
        $checkStatement->execute($physicalNames);
        $checks = $this->groupMySqlInspectionRows(
            $checkStatement->fetchAll(),
            $expectedNames
        );

        return [
            'sql_mode' => $sqlMode,
            'table_options' => $tableOptions,
            'primary_keys' => $primaryKeys,
            'columns' => $columns,
            'foreign_keys' => $foreignKeys,
            'unique_constraints' => $uniqueConstraints,
            'indexes' => $indexes,
            'checks' => $checks,
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, true> $expectedNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupMySqlInspectionRows(array $rows, array $expectedNames): array
    {
        $grouped = [];
        foreach ($this->normalizeCatalogRows($rows) as $row) {
            $tableName = $row['table_name'] ?? null;
            if (!is_string($tableName) || !isset($expectedNames[$tableName])) {
                throw new CoreSchemaException([
                    'reason' => 'schema_introspection_failed',
                    'driver' => 'mysql',
                ]);
            }
            unset($row['table_name']);
            $grouped[$tableName][] = $row;
        }
        return $grouped;
    }

    /** @return array<string, mixed> */
    private function inspectPostgreSqlTable(PDO $pdo, string $logicalName, string $physicalName, string $prefix): array
    {
        $tableOptionStatement = $pdo->prepare(<<<'SQL'
SELECT table_row.relpersistence, table_row.relrowsecurity, table_row.relforcerowsecurity,
       table_row.reltablespace,
       COALESCE(toast_row.reltablespace, 0) AS toast_tablespace,
       COALESCE(cardinality(table_row.reloptions), 0)
         + COALESCE(cardinality(toast_row.reloptions), 0) AS reloption_count,
       access_method.amname AS access_method,
       (SELECT COUNT(*) FROM pg_inherits inheritance_row
        WHERE inheritance_row.inhrelid = table_row.oid
           OR inheritance_row.inhparent = table_row.oid) AS inheritance_count,
       (SELECT COUNT(*) FROM pg_rewrite rewrite_row
        WHERE rewrite_row.ev_class = table_row.oid
          AND rewrite_row.rulename <> '_RETURN') AS rewrite_rule_count
FROM pg_class table_row
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
LEFT JOIN pg_am access_method ON access_method.oid = table_row.relam
LEFT JOIN pg_class toast_row ON toast_row.oid = table_row.reltoastrelid
WHERE namespace_row.nspname = current_schema() AND table_row.relname = ?
SQL);
        $tableOptionStatement->execute([$physicalName]);
        $tableOptionRow = $this->normalizeCatalogRow($tableOptionStatement->fetch());
        $persistence = match ((string) ($tableOptionRow['relpersistence'] ?? '')) {
            'p' => 'PERMANENT',
            'u' => 'UNLOGGED',
            't' => 'TEMPORARY',
            default => throw new CoreSchemaException([
                'reason' => 'table_state_unsupported',
                'driver' => 'pgsql',
                'table' => $logicalName,
            ]),
        };
        $accessMethod = strtoupper((string) ($tableOptionRow['access_method'] ?? ''));
        if ($accessMethod === ''
            || preg_match('/^[A-Z][A-Z0-9_]{0,62}$/D', $accessMethod) !== 1
            || (int) ($tableOptionRow['reltablespace'] ?? -1) !== 0
            || (int) ($tableOptionRow['toast_tablespace'] ?? -1) !== 0
            || (int) ($tableOptionRow['reloption_count'] ?? -1) !== 0
            || (int) ($tableOptionRow['inheritance_count'] ?? -1) !== 0
            || (int) ($tableOptionRow['rewrite_rule_count'] ?? -1) !== 0) {
            throw new CoreSchemaException([
                'reason' => 'table_state_unsupported',
                'driver' => 'pgsql',
                'table' => $logicalName,
            ]);
        }
        $tableOptions = [
            'engine' => $persistence
                . ';AM=' . $accessMethod
                . ';RLS=' . ($this->databaseBoolean($tableOptionRow['relrowsecurity'] ?? false) ? 'ON' : 'OFF')
                . ';FORCE_RLS=' . ($this->databaseBoolean($tableOptionRow['relforcerowsecurity'] ?? false) ? 'ON' : 'OFF'),
            'character_set' => null,
            'collation' => null,
        ];

        $primaryStatement = $pdo->prepare(<<<'SQL'
SELECT attribute.attname AS column_name, key_column.ordinality AS ordinal_position,
       primary_constraint.condeferrable AS is_deferrable,
       primary_constraint.condeferred AS is_initially_deferred,
       primary_constraint.convalidated AS is_validated
FROM pg_index index_row
JOIN pg_class table_row ON table_row.oid = index_row.indrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
JOIN pg_constraint primary_constraint
  ON primary_constraint.conindid = index_row.indexrelid AND primary_constraint.contype = 'p'
JOIN LATERAL unnest(index_row.indkey) WITH ORDINALITY AS key_column(attnum, ordinality) ON true
JOIN pg_attribute attribute ON attribute.attrelid = table_row.oid AND attribute.attnum = key_column.attnum
WHERE namespace_row.nspname = current_schema() AND table_row.relname = ? AND index_row.indisprimary
ORDER BY key_column.ordinality
SQL);
        $primaryStatement->execute([$physicalName]);
        $primary = [];
        $primaryKeyOptions = null;
        foreach ($this->normalizeCatalogRows($primaryStatement->fetchAll()) as $column) {
            $primary[(int) $column['ordinal_position']] = $this->postgreSqlCanonicalIdentifier(
                $column['column_name'] ?? null,
                $logicalName
            );
            $rowOptions = [
                'deferrable' => $this->databaseBoolean($column['is_deferrable'] ?? false),
                'initially_deferred' => $this->databaseBoolean($column['is_initially_deferred'] ?? false),
                'validated' => $this->databaseBoolean($column['is_validated'] ?? false),
            ];
            if ($primaryKeyOptions !== null && $primaryKeyOptions !== $rowOptions) {
                throw new CoreSchemaException([
                    'reason' => 'primary_key_introspection_failed',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            $primaryKeyOptions = $rowOptions;
        }
        ksort($primary, SORT_NUMERIC);
        $primaryKey = array_values($primary);
        $primaryKeyOptions ??= $this->immediatePrimaryKeyOptions();
        $primaryPositions = array_flip($primaryKey);

        $columnStatement = $pdo->prepare(<<<'SQL'
SELECT attribute.attname AS column_name,
       format_type(attribute.atttypid, attribute.atttypmod) AS formatted_type,
       type_row.typname AS type_family,
       attribute.attnotnull,
       attribute.attidentity,
       attribute.attgenerated,
       pg_get_expr(default_row.adbin, default_row.adrelid) AS column_default,
       CASE
           WHEN attribute.attcollation = 0 THEN NULL
           WHEN collation_row.collname = 'default' THEN 'default'
           ELSE collation_namespace.nspname || '.' || collation_row.collname
       END AS collation_name,
       attribute.attnum AS ordinal_position
FROM pg_attribute attribute
JOIN pg_class table_row ON table_row.oid = attribute.attrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
JOIN pg_type type_row ON type_row.oid = attribute.atttypid
LEFT JOIN pg_attrdef default_row
  ON default_row.adrelid = attribute.attrelid AND default_row.adnum = attribute.attnum
LEFT JOIN pg_collation collation_row ON collation_row.oid = attribute.attcollation
LEFT JOIN pg_namespace collation_namespace ON collation_namespace.oid = collation_row.collnamespace
WHERE namespace_row.nspname = current_schema() AND table_row.relname = ?
  AND attribute.attnum > 0 AND NOT attribute.attisdropped
ORDER BY attribute.attnum
SQL);
        $columnStatement->execute([$physicalName]);
        $columns = [];
        foreach ($this->normalizeCatalogRows($columnStatement->fetchAll()) as $column) {
            if ((string) ($column['attgenerated'] ?? '') !== '') {
                throw new CoreSchemaException([
                    'reason' => 'generated_column_unsupported',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            $name = $this->postgreSqlCanonicalIdentifier(
                $column['column_name'] ?? null,
                $logicalName
            );
            $primaryPosition = isset($primaryPositions[$name]) ? (int) $primaryPositions[$name] + 1 : 0;
            $identityCode = (string) ($column['attidentity'] ?? '');
            $default = $column['column_default'] ?? null;
            $identity = match ($identityCode) {
                'a' => 'identity_always',
                'd' => 'identity_by_default',
                default => is_string($default) && str_starts_with(strtolower($default), 'nextval(')
                    ? 'sequence'
                    : 'none',
            };
            $columns[] = [
                'name' => $name,
                'type' => $this->normalizeType((string) $column['formatted_type']),
                'type_family' => strtoupper((string) $column['type_family']),
                'collation' => is_string($column['collation_name'] ?? null)
                    ? strtolower((string) $column['collation_name'])
                    : null,
                'nullable' => !$this->databaseBoolean($column['attnotnull']) && $primaryPosition === 0,
                'default' => $this->normalizePostgreSqlDefault($default, $identity),
                'identity' => $identity,
                'primary_position' => $primaryPosition,
            ];
        }

        $foreignKeyStatement = $pdo->prepare(<<<'SQL'
SELECT constraint_row.conname AS constraint_name,
       child_key.position AS ordinal_position,
       child_attribute.attname AS column_name,
       child_namespace.nspname AS child_schema_name,
       parent_namespace.nspname AS referenced_schema_name,
       parent.relname AS referenced_table_name,
       parent_attribute.attname AS referenced_column_name,
       constraint_row.confdeltype AS delete_action,
       constraint_row.confupdtype AS update_action,
       constraint_row.confmatchtype AS match_type,
       constraint_row.condeferrable AS is_deferrable,
       constraint_row.condeferred AS is_initially_deferred,
       constraint_row.convalidated AS is_validated,
       trigger_state.trigger_count,
       trigger_state.all_enabled AS all_triggers_enabled
FROM pg_constraint constraint_row
JOIN pg_class child ON child.oid = constraint_row.conrelid
JOIN pg_namespace child_namespace ON child_namespace.oid = child.relnamespace
JOIN pg_class parent ON parent.oid = constraint_row.confrelid
JOIN pg_namespace parent_namespace ON parent_namespace.oid = parent.relnamespace
JOIN LATERAL (
    SELECT COUNT(*) AS trigger_count,
           COALESCE(bool_and(trigger_row.tgenabled = 'O'), false) AS all_enabled
    FROM pg_trigger trigger_row
    WHERE trigger_row.tgconstraint = constraint_row.oid
) trigger_state ON true
JOIN LATERAL unnest(constraint_row.conkey) WITH ORDINALITY AS child_key(attnum, position) ON true
JOIN LATERAL unnest(constraint_row.confkey) WITH ORDINALITY AS parent_key(attnum, position)
  ON parent_key.position = child_key.position
JOIN pg_attribute child_attribute
  ON child_attribute.attrelid = child.oid AND child_attribute.attnum = child_key.attnum
JOIN pg_attribute parent_attribute
  ON parent_attribute.attrelid = parent.oid AND parent_attribute.attnum = parent_key.attnum
WHERE constraint_row.contype = 'f'
  AND child_namespace.nspname = current_schema() AND child.relname = ?
ORDER BY constraint_row.conname, child_key.position
SQL);
        $foreignKeyStatement->execute([$physicalName]);
        $foreignKeyGroups = [];
        foreach ($this->normalizeCatalogRows($foreignKeyStatement->fetchAll()) as $foreignKey) {
            if (!is_string($foreignKey['child_schema_name'] ?? null)
                || !is_string($foreignKey['referenced_schema_name'] ?? null)
                || $foreignKey['referenced_schema_name'] !== $foreignKey['child_schema_name']) {
                throw new CoreSchemaException([
                    'reason' => 'foreign_key_target_outside_scope',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            if ((int) ($foreignKey['trigger_count'] ?? 0) < 4
                || !$this->databaseBoolean($foreignKey['all_triggers_enabled'] ?? false)) {
                throw new CoreSchemaException([
                    'reason' => 'constraint_enforcement_disabled',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            $key = (string) $foreignKey['constraint_name'];
            $position = (int) $foreignKey['ordinal_position'];
            $foreignKeyGroups[$key]['columns'][$position] = $this->postgreSqlCanonicalIdentifier(
                $foreignKey['column_name'] ?? null,
                $logicalName
            );
            $foreignKeyGroups[$key]['referenced_columns'][$position] = $this->postgreSqlCanonicalIdentifier(
                $foreignKey['referenced_column_name'] ?? null,
                $logicalName
            );
            $foreignKeyGroups[$key]['referenced_table'] = $this->logicalReference((string) $foreignKey['referenced_table_name'], $prefix);
            $foreignKeyGroups[$key]['on_update'] = $this->postgreSqlReferentialAction((string) $foreignKey['update_action']);
            $foreignKeyGroups[$key]['on_delete'] = $this->postgreSqlReferentialAction((string) $foreignKey['delete_action']);
            $foreignKeyGroups[$key]['match'] = $this->postgreSqlMatch((string) $foreignKey['match_type']);
            $foreignKeyGroups[$key]['deferrable'] = $this->databaseBoolean($foreignKey['is_deferrable'] ?? false);
            $foreignKeyGroups[$key]['initially_deferred'] = $this->databaseBoolean($foreignKey['is_initially_deferred'] ?? false);
            $foreignKeyGroups[$key]['validated'] = $this->databaseBoolean($foreignKey['is_validated'] ?? false);
        }
        $foreignKeys = $this->normalizeForeignKeyGroups($foreignKeyGroups);

        $indexStatement = $pdo->prepare(<<<'SQL'
SELECT index_class.relname AS index_name,
       index_row.indisunique,
       index_row.indisprimary,
       index_row.indisvalid,
       index_row.indisready,
       index_row.indislive,
       index_row.indcheckxmin,
       index_row.indisexclusion,
       index_row.indisreplident,
       index_row.indisclustered,
       index_class.reltablespace,
       index_class.reloptions IS NULL AS no_reloptions,
       COALESCE((to_jsonb(index_row)->>'indnullsnotdistinct')::boolean, false)
           AS indnullsnotdistinct,
       access_method.amname AS index_type,
       index_row.indpred IS NOT NULL AS is_partial,
       pg_get_expr(index_row.indpred, index_row.indrelid) AS predicate,
       pg_get_expr(index_row.indexprs, index_row.indrelid, true) AS index_expressions,
       index_row.indnkeyatts AS key_count,
       key_column.ordinality,
       key_column.attnum,
       attribute.attname AS column_name,
       collation_row.collname AS collation_name,
       operator_class.opcname AS operator_class,
       CASE WHEN (index_row.indoption[(key_column.ordinality - 1)::integer] & 1) = 1
            THEN 'DESC' ELSE 'ASC' END AS direction,
       key_column.ordinality > index_row.indnkeyatts AS is_included,
       CASE
           WHEN key_column.ordinality > index_row.indnkeyatts THEN NULL
           WHEN (index_row.indoption[(key_column.ordinality - 1)::integer] & 2) = 2
               THEN 'FIRST'
           ELSE 'LAST'
       END AS nulls_order,
       unique_constraint.oid IS NOT NULL AS is_unique_constraint,
       unique_constraint.condeferrable AS is_deferrable,
       unique_constraint.condeferred AS is_initially_deferred,
       unique_constraint.convalidated AS is_validated,
       NOT EXISTS (
           SELECT 1
           FROM pg_depend dependency_row
           WHERE dependency_row.classid = 'pg_class'::regclass
             AND dependency_row.objid = index_row.indexrelid
             AND dependency_row.objsubid = 0
             AND NOT (
                 (
                     dependency_row.refclassid = 'pg_class'::regclass
                     AND dependency_row.refobjid = index_row.indrelid
                     AND dependency_row.refobjsubid >= 0
                     AND dependency_row.deptype = 'a'
                 )
                 OR (
                     unique_constraint.oid IS NOT NULL
                     AND dependency_row.refclassid = 'pg_constraint'::regclass
                     AND dependency_row.refobjid = unique_constraint.oid
                     AND dependency_row.refobjsubid = 0
                     AND dependency_row.deptype = 'i'
                 )
             )
       ) AS dependencies_supported,
       COALESCE((
           SELECT array_to_string(ARRAY(
               SELECT CASE
                   WHEN dependency_column.refobjsubid = 0 THEN '*'
                   ELSE dependency_attribute.attname
               END
               FROM pg_depend dependency_column
               LEFT JOIN pg_attribute dependency_attribute
                 ON dependency_attribute.attrelid = index_row.indrelid
                AND dependency_attribute.attnum = dependency_column.refobjsubid
               WHERE dependency_column.classid = 'pg_class'::regclass
                 AND dependency_column.objid = index_row.indexrelid
                 AND dependency_column.objsubid = 0
                 AND dependency_column.refclassid = 'pg_class'::regclass
                 AND dependency_column.refobjid = index_row.indrelid
                 AND dependency_column.deptype = 'a'
               ORDER BY dependency_column.refobjsubid
           ), pg_catalog.chr(31))
       ), '') AS relation_dependencies,
       (
           SELECT index_row.indexprs::text LIKE
               ('({FUNCEXPR :funcid ' || builtin_function.oid::text || ' %')
           FROM pg_proc builtin_function
           JOIN pg_namespace builtin_namespace
             ON builtin_namespace.oid = builtin_function.pronamespace
           WHERE builtin_namespace.nspname = 'pg_catalog'
             AND builtin_function.proname = 'to_tsvector'
             AND pg_get_function_identity_arguments(builtin_function.oid) = 'regconfig, text'
             AND builtin_function.prorettype = 'pg_catalog.tsvector'::regtype
             AND builtin_function.provolatile = 'i'
       ) AS builtin_tsvector_bound,
       (
           SELECT COUNT(*) = 1
           FROM pg_ts_config builtin_configuration
           JOIN pg_namespace builtin_namespace
             ON builtin_namespace.oid = builtin_configuration.cfgnamespace
           WHERE builtin_namespace.nspname = 'pg_catalog'
             AND builtin_configuration.cfgname = 'simple'
       ) AS builtin_simple_available,
       COALESCE(
           (
               length(index_row.indexprs::text)
               - length(replace(index_row.indexprs::text, ':opno ', ''))
           ) / length(':opno '),
           0
       ) AS expression_operator_count,
       COALESCE((
           SELECT (
               length(index_row.indexprs::text)
               - length(replace(
                   index_row.indexprs::text,
                   ':opno ' || builtin_operator.oid::text || ' ',
                   ''
               ))
           ) / length(':opno ' || builtin_operator.oid::text || ' ')
           FROM pg_operator builtin_operator
           JOIN pg_namespace builtin_namespace
             ON builtin_namespace.oid = builtin_operator.oprnamespace
           WHERE builtin_namespace.nspname = 'pg_catalog'
             AND builtin_operator.oprname = '||'
             AND builtin_operator.oprleft = 'pg_catalog.text'::regtype
             AND builtin_operator.oprright = 'pg_catalog.text'::regtype
             AND builtin_operator.oprresult = 'pg_catalog.text'::regtype
       ), 0) AS builtin_textcat_operator_count
FROM pg_index index_row
JOIN pg_class table_row ON table_row.oid = index_row.indrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
JOIN pg_class index_class ON index_class.oid = index_row.indexrelid
JOIN pg_am access_method ON access_method.oid = index_class.relam
JOIN LATERAL unnest(index_row.indkey) WITH ORDINALITY AS key_column(attnum, ordinality) ON true
LEFT JOIN pg_attribute attribute
  ON attribute.attrelid = table_row.oid AND attribute.attnum = key_column.attnum
LEFT JOIN pg_collation collation_row
  ON collation_row.oid = index_row.indcollation[(key_column.ordinality - 1)::integer]
LEFT JOIN pg_opclass operator_class
  ON operator_class.oid = index_row.indclass[(key_column.ordinality - 1)::integer]
LEFT JOIN pg_constraint unique_constraint
  ON unique_constraint.conindid = index_row.indexrelid AND unique_constraint.contype = 'u'
WHERE namespace_row.nspname = current_schema() AND table_row.relname = ?
ORDER BY index_class.relname, key_column.ordinality
SQL);
        $indexStatement->execute([$physicalName]);
        $indexGroups = [];
        foreach ($this->normalizeCatalogRows($indexStatement->fetchAll()) as $index) {
            if (!$this->databaseBoolean($index['indisvalid'] ?? false)
                || !$this->databaseBoolean($index['indisready'] ?? false)
                || !$this->databaseBoolean($index['indislive'] ?? false)
                || $this->databaseBoolean($index['indcheckxmin'] ?? false)
                || $this->databaseBoolean($index['indisexclusion'] ?? false)
                || $this->databaseBoolean($index['indisreplident'] ?? false)
                || $this->databaseBoolean($index['indisclustered'] ?? false)
                || (int) ($index['reltablespace'] ?? -1) !== 0
                || !$this->databaseBoolean($index['no_reloptions'] ?? false)) {
                throw new CoreSchemaException([
                    'reason' => 'index_state_unsupported',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            if ($this->databaseBoolean($index['indisprimary'])) {
                continue;
            }
            if (!$this->databaseBoolean($index['dependencies_supported'] ?? false)) {
                throw new CoreSchemaException([
                    'reason' => 'index_dependency_unsupported',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            $columnName = $index['column_name'] ?? null;
            $attributeNumber = (int) $index['attnum'];
            $expressionIdentity = null;
            if ($attributeNumber <= 0) {
                if ($columnName !== null && $columnName !== '') {
                    throw new CoreSchemaException([
                        'reason' => 'index_expression_unsupported',
                        'driver' => 'pgsql',
                        'table' => $logicalName,
                    ]);
                }
                $expressionIdentity = CoreSchemaPostgreSqlIndexes::recognizeExpression(
                    $logicalName,
                    (string) ($index['index_expressions'] ?? '')
                );
                $actualDependencies = (string) ($index['relation_dependencies'] ?? '') === ''
                    ? []
                    : explode("\x1f", (string) $index['relation_dependencies']);
                $expectedOperatorCount = CoreSchemaPostgreSqlIndexes::expressionOperatorCount(
                    $expressionIdentity
                );
                if (!$this->databaseBoolean($index['builtin_tsvector_bound'] ?? false)
                    || !$this->databaseBoolean($index['builtin_simple_available'] ?? false)
                    || (int) ($index['expression_operator_count'] ?? -1) !== $expectedOperatorCount
                    || (int) ($index['builtin_textcat_operator_count'] ?? -1) !== $expectedOperatorCount
                    || $actualDependencies !== CoreSchemaPostgreSqlIndexes::expressionDependencyColumns(
                        $expressionIdentity
                    )) {
                    throw new CoreSchemaException([
                        'reason' => 'index_expression_dependency_unsupported',
                        'driver' => 'pgsql',
                        'table' => $logicalName,
                    ]);
                }
            } elseif (!is_string($columnName) || $columnName === '') {
                throw new CoreSchemaException([
                    'reason' => 'index_expression_unsupported',
                    'driver' => 'pgsql',
                    'table' => $logicalName,
                ]);
            }
            $indexName = (string) $index['index_name'];
            $partial = $this->databaseBoolean($index['is_partial']);
            $indexGroups[$indexName]['unique'] = $this->databaseBoolean($index['indisunique']);
            $indexGroups[$indexName]['origin'] = $this->databaseBoolean($index['is_unique_constraint'])
                ? 'unique_constraint'
                : 'explicit';
            $indexGroups[$indexName]['type'] = strtoupper((string) $index['index_type']);
            $indexGroups[$indexName]['partial'] = $partial;
            $indexGroups[$indexName]['predicate'] = $partial
                ? $this->normalizeExpression((string) $index['predicate'])
                : null;
            $isUniqueConstraint = $this->databaseBoolean($index['is_unique_constraint']);
            $indexGroups[$indexName]['deferrable'] = $isUniqueConstraint
                && $this->databaseBoolean($index['is_deferrable'] ?? false);
            $indexGroups[$indexName]['initially_deferred'] = $isUniqueConstraint
                && $this->databaseBoolean($index['is_initially_deferred'] ?? false);
            $indexGroups[$indexName]['validated'] = $isUniqueConstraint
                ? $this->databaseBoolean($index['is_validated'] ?? false)
                : true;
            $indexGroups[$indexName]['nulls_not_distinct'] = $this->databaseBoolean(
                $index['indnullsnotdistinct'] ?? false
            );
            $indexColumn = [
                'name' => $expressionIdentity === null
                    ? $this->postgreSqlCanonicalIdentifier($columnName, $logicalName)
                    : null,
                'collation' => (string) ($index['collation_name'] ?? ''),
                'direction' => (string) $index['direction'],
                'nulls_order' => $index['nulls_order'] ?? null,
                'length' => null,
                'operator_class' => (string) ($index['operator_class'] ?? ''),
                'included' => $this->databaseBoolean($index['is_included']),
            ];
            if ($expressionIdentity !== null) {
                $indexColumn['expression'] = $expressionIdentity;
                if ((int) ($index['key_count'] ?? 0) !== 1
                    || strtoupper((string) $index['index_type']) !== 'GIN'
                    || $this->databaseBoolean($index['indisunique'])
                    || $this->databaseBoolean($index['is_unique_constraint'])
                    || $this->databaseBoolean($index['is_partial'])
                    || $this->databaseBoolean($index['is_included'])
                    || (string) ($index['operator_class'] ?? '') !== 'tsvector_ops'
                    || (string) ($index['collation_name'] ?? '') !== '') {
                    throw new CoreSchemaException([
                        'reason' => 'index_expression_unsupported',
                        'driver' => 'pgsql',
                        'table' => $logicalName,
                    ]);
                }
            }
            $indexGroups[$indexName]['columns'][(int) $index['ordinality']] = $indexColumn;
        }
        $indexes = $this->normalizeIndexGroups($indexGroups);

        $checkStatement = $pdo->prepare(<<<'SQL'
SELECT constraint_row.conname AS constraint_name,
       pg_get_expr(constraint_row.conbin, constraint_row.conrelid, true) AS check_expression,
       constraint_row.convalidated
FROM pg_constraint constraint_row
JOIN pg_class table_row ON table_row.oid = constraint_row.conrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
WHERE namespace_row.nspname = current_schema()
  AND table_row.relname = ?
  AND constraint_row.contype = 'c'
ORDER BY constraint_row.conname
SQL);
        $checkStatement->execute([$physicalName]);
        $checks = [];
        foreach ($this->normalizeCatalogRows($checkStatement->fetchAll()) as $check) {
            $checks[] = [
                'expression' => $this->normalizeExpression((string) $check['check_expression']),
                'enforced' => true,
                'validated' => $this->databaseBoolean($check['convalidated'] ?? false),
            ];
        }
        $this->sortSemanticList($checks);

        return [
            'relation' => 'table',
            'options' => $tableOptions,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'primary_key_options' => $primaryKeyOptions,
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'checks' => $checks,
        ];
    }

    /**
     * @return list<array{physical_name: string, logical_name: string, type: string, classification: string, scoped: bool}>
     */
    private function relationInventory(PDO $pdo, string $driver, string $prefix): array
    {
        $rows = match ($driver) {
            'sqlite' => $pdo->query(
                "SELECT name AS physical_name, type AS relation_type FROM sqlite_schema "
                . "WHERE type IN ('table', 'view') ORDER BY name"
            )->fetchAll(),
            'mysql' => $pdo->query(<<<'SQL'
SELECT table_name AS physical_name, table_type AS relation_type
FROM information_schema.tables
WHERE table_schema = DATABASE()
ORDER BY table_name
SQL)->fetchAll(),
            'pgsql' => $pdo->query(<<<'SQL'
SELECT class_row.relname AS physical_name, class_row.relkind AS relation_type
FROM pg_class class_row
JOIN pg_namespace namespace_row ON namespace_row.oid = class_row.relnamespace
WHERE namespace_row.nspname = current_schema()
  AND class_row.relkind IN ('r', 'p', 'v', 'm')
ORDER BY class_row.relname
SQL)->fetchAll(),
        };
        $rows = $this->normalizeCatalogRows($rows);
        $registered = array_fill_keys(PrefixedPDO::logicalTables(), true);
        $relations = [];
        foreach ($rows as $row) {
            $physicalName = (string) $row['physical_name'];
            $scoped = $prefix === '' || str_starts_with($physicalName, $prefix);
            $logicalName = $scoped && $prefix !== ''
                ? substr($physicalName, strlen($prefix))
                : $physicalName;
            $logicalName = strtolower($logicalName);
            $classification = match (true) {
                $driver === 'sqlite' && str_starts_with($physicalName, 'sqlite_') => 'engine_metadata',
                !$scoped => 'external',
                isset($registered[$logicalName]) => 'core',
                str_starts_with($logicalName, 'plugin_') => 'plugin',
                $logicalName === 'workspace_identity' => 'adapter_metadata',
                default => 'unclassified',
            };
            $relations[] = [
                'physical_name' => $physicalName,
                'logical_name' => $logicalName,
                'type' => $this->relationType($driver, (string) $row['relation_type']),
                'classification' => $classification,
                'scoped' => $scoped,
            ];
        }
        usort($relations, static fn (array $left, array $right): int => strcmp($left['physical_name'], $right['physical_name']));
        return $relations;
    }

    /**
     * @param list<string> $physicalNames
     * @param array<string, array<string, mixed>> $persistentRelations
     */
    private function assertNoTemporaryCoreShadows(
        PDO $pdo,
        string $driver,
        array $physicalNames,
        array $persistentRelations
    ): void {
        if ($physicalNames === []) {
            return;
        }

        $shadows = [];
        if ($driver === 'sqlite') {
            $placeholders = implode(', ', array_fill(0, count($physicalNames), '?'));
            $statement = $pdo->prepare(
                "SELECT name FROM sqlite_temp_master WHERE type IN ('table', 'view') "
                . "AND lower(name) IN ({$placeholders}) ORDER BY lower(name), name"
            );
            $statement->execute(array_map('strtolower', $physicalNames));
            $shadows = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        } elseif ($driver === 'pgsql') {
            $placeholders = implode(', ', array_fill(0, count($physicalNames), '?'));
            $statement = $pdo->prepare(
                "SELECT class_row.relname FROM pg_class class_row "
                . "WHERE class_row.relnamespace = pg_my_temp_schema() "
                . "AND class_row.relkind IN ('r', 'p', 'v', 'm', 'f') "
                . "AND class_row.relname IN ({$placeholders}) ORDER BY class_row.relname"
            );
            $statement->execute($physicalNames);
            $shadows = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        } else {
            // MySQL deliberately omits session TEMPORARY tables from
            // INFORMATION_SCHEMA. SHOW CREATE resolves the session shadow and
            // identifies it explicitly. Missing persistent Core relations are
            // already rejected by inventory, so only inventory-present names
            // need this additional probe.
            foreach ($physicalNames as $physicalName) {
                if (!isset($persistentRelations[$physicalName])) {
                    continue;
                }
                $showCreate = $pdo->query(
                    'SHOW CREATE TABLE ' . $this->quote($physicalName, 'mysql')
                );
                $row = $this->normalizeCatalogRow(
                    $showCreate instanceof PDOStatement ? $showCreate->fetch() : false
                );
                if (!is_array($row)) {
                    throw new CoreSchemaException([
                        'reason' => 'schema_introspection_failed',
                        'driver' => 'mysql',
                    ]);
                }
                $createStatements = array_values(array_filter(
                    $row,
                    static fn (mixed $value): bool => is_string($value)
                        && preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $value) === 1
                ));
                if (count($createStatements) !== 1) {
                    throw new CoreSchemaException([
                        'reason' => 'schema_introspection_failed',
                        'driver' => 'mysql',
                    ]);
                }
                if (preg_match('/^\s*CREATE\s+TEMPORARY\s+TABLE\b/i', $createStatements[0]) === 1) {
                    $shadows[] = $physicalName;
                }
            }
        }

        if ($shadows !== []) {
            sort($shadows, SORT_STRING);
            throw new CoreSchemaException([
                'reason' => 'temporary_core_relation_shadow',
                'driver' => $driver,
                'relations' => $shadows,
            ]);
        }
    }

    private function relationType(string $driver, string $type): string
    {
        return match ($driver) {
            'sqlite' => strtolower($type),
            'mysql' => strtoupper($type) === 'BASE TABLE' ? 'table' : 'view',
            'pgsql' => match ($type) {
                'r' => 'table',
                'p' => 'partitioned_table',
                'm' => 'materialized_view',
                default => 'view',
            },
        };
    }

    /** @param array<string, array<string, mixed>> $groups @return list<array<string, mixed>> */
    private function normalizeForeignKeyGroups(array $groups): array
    {
        $foreignKeys = [];
        foreach ($groups as $foreignKey) {
            ksort($foreignKey['columns'], SORT_NUMERIC);
            ksort($foreignKey['referenced_columns'], SORT_NUMERIC);
            $foreignKey['columns'] = array_values($foreignKey['columns']);
            $foreignKey['referenced_columns'] = array_values($foreignKey['referenced_columns']);
            $foreignKeys[] = $foreignKey;
        }
        $this->sortSemanticList($foreignKeys);
        return $foreignKeys;
    }

    /** @param array<string, array<string, mixed>> $groups @return list<array<string, mixed>> */
    private function normalizeIndexGroups(array $groups): array
    {
        $indexes = [];
        foreach ($groups as $index) {
            ksort($index['columns'], SORT_NUMERIC);
            $index['columns'] = array_values($index['columns']);
            $indexes[] = $index;
        }
        $this->sortSemanticList($indexes);
        return $indexes;
    }

    /** @param list<array<string, mixed>> $values */
    private function sortSemanticList(array &$values): void
    {
        usort(
            $values,
            static fn (array $left, array $right): int => strcmp(
                CoreSchemaCatalog::canonicalJson($left),
                CoreSchemaCatalog::canonicalJson($right)
            )
        );
    }

    private function sqliteIndexPredicate(PDO $pdo, string $indexName): string
    {
        $statement = $pdo->prepare("SELECT sql FROM sqlite_schema WHERE type = 'index' AND name = ?");
        $statement->execute([$indexName]);
        $sql = $statement->fetchColumn();
        if (!is_string($sql) || preg_match('/\bWHERE\b([\s\S]+)$/i', $sql, $matches) !== 1) {
            throw new CoreSchemaException(['reason' => 'partial_index_predicate_missing', 'driver' => 'sqlite']);
        }
        return $this->normalizeExpression($matches[1]);
    }

    private function normalizeSqliteDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $value = $this->unwrapParentheses(trim((string) $default));
        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }
        if ($this->isQuoted($value)) {
            return 'string:' . $this->decodeSqlString($value);
        }
        if ($this->isNumber($value)) {
            return 'number:' . $this->normalizeNumber($value);
        }
        return 'expression:' . $this->normalizeExpression($value);
    }

    private function normalizeMySqlDefault(mixed $default, string $typeFamily): ?string
    {
        if ($default === null) {
            return null;
        }
        $value = trim((string) $default);
        if (preg_match('/^CURRENT_(?:DATE|TIME|TIMESTAMP)(?:\(\))?$/iD', $value) === 1) {
            return 'expression:' . strtoupper(str_replace('()', '', $value));
        }
        if (preg_match('/^CURRENT_TIMESTAMP\(6\)$/iD', $value) === 1) {
            return 'expression:CURRENT_TIMESTAMP(6)';
        }
        if (in_array($typeFamily, [
            'BIGINT', 'BIT', 'DECIMAL', 'DOUBLE', 'FLOAT', 'INT', 'INTEGER',
            'MEDIUMINT', 'NUMERIC', 'REAL', 'SMALLINT', 'TINYINT',
        ], true) && $this->isNumber($value)) {
            return 'number:' . $this->normalizeNumber($value);
        }
        return 'string:' . $value;
    }

    private function mySqlServerFlavor(PDO $pdo): string
    {
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        if (!is_string($version) || trim($version) === '') {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
            ]);
        }
        if (stripos($version, 'mariadb') === false) {
            return 'mysql';
        }
        if (preg_match('/([0-9]+\.[0-9]+\.[0-9]+)-MariaDB/i', $version, $matches) !== 1
            || version_compare($matches[1], '10.4.0', '<')
            || version_compare($matches[1], '10.5.0', '>=')) {
            throw new CoreSchemaException([
                'reason' => 'server_version_unsupported',
                'driver' => 'mysql',
                'server_flavor' => 'mariadb',
            ]);
        }
        return 'mariadb';
    }

    private function normalizeMySqlColumnTypeForServer(string $columnType, bool $mariaDb): string
    {
        $normalized = $this->normalizeType($columnType);
        if (!$mariaDb
            || preg_match(
                '/^(BIGINT|INT|INTEGER|MEDIUMINT|SMALLINT|TINYINT)\(([0-9]+)\)(UNSIGNED)?$/D',
                $normalized,
                $matches
            ) !== 1
            || ($matches[1] === 'TINYINT'
                && $matches[2] === '1'
                && ($matches[3] ?? '') === '')) {
            return $normalized;
        }
        return $matches[1] . (($matches[3] ?? '') === 'UNSIGNED' ? ' UNSIGNED' : '');
    }

    /** @return array<string, true> */
    private function mariaDbJsonColumnEvidence(
        string $createSql,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $columns = [];
        foreach ($this->mySqlDefinitionSegments($createSql, $backslashEscapes, $logicalName) as $definition) {
            $tokens = $this->mySqlSqlTokens($definition, $backslashEscapes, $logicalName);
            if (($tokens[0]['kind'] ?? null) !== 'identifier') {
                continue;
            }
            $columnName = strtolower((string) ($tokens[0]['value'] ?? ''));
            if (!isset(self::MARIADB_JSON_COLUMNS[$logicalName . '.' . $columnName])) {
                continue;
            }
            $keywords = static fn (array $token, string $keyword): bool =>
                ($token['kind'] ?? null) === 'word'
                    && strtoupper((string) ($token['value'] ?? '')) === $keyword;
            $prefixMatches = $keywords($tokens[1] ?? [], 'LONGTEXT')
                && $keywords($tokens[2] ?? [], 'CHARACTER')
                && $keywords($tokens[3] ?? [], 'SET')
                && $keywords($tokens[4] ?? [], 'UTF8MB4')
                && $keywords($tokens[5] ?? [], 'COLLATE')
                && $keywords($tokens[6] ?? [], 'UTF8MB4_BIN');
            $nullabilityEnd = null;
            if ($keywords($tokens[7] ?? [], 'NOT') && $keywords($tokens[8] ?? [], 'NULL')) {
                $nullabilityEnd = 9;
            } elseif ($keywords($tokens[7] ?? [], 'DEFAULT') && $keywords($tokens[8] ?? [], 'NULL')) {
                $nullabilityEnd = 9;
            }
            $suffixMatches = is_int($nullabilityEnd)
                && $keywords($tokens[$nullabilityEnd] ?? [], 'CHECK')
                && ($tokens[$nullabilityEnd + 1]['kind'] ?? null) === 'symbol'
                && ($tokens[$nullabilityEnd + 1]['value'] ?? null) === '('
                && $keywords($tokens[$nullabilityEnd + 2] ?? [], 'JSON_VALID')
                && ($tokens[$nullabilityEnd + 3]['kind'] ?? null) === 'symbol'
                && ($tokens[$nullabilityEnd + 3]['value'] ?? null) === '('
                && ($tokens[$nullabilityEnd + 4]['kind'] ?? null) === 'identifier'
                && strtolower((string) ($tokens[$nullabilityEnd + 4]['value'] ?? '')) === $columnName
                && ($tokens[$nullabilityEnd + 5]['kind'] ?? null) === 'symbol'
                && ($tokens[$nullabilityEnd + 5]['value'] ?? null) === ')'
                && ($tokens[$nullabilityEnd + 6]['kind'] ?? null) === 'symbol'
                && ($tokens[$nullabilityEnd + 6]['value'] ?? null) === ')'
                && count($tokens) === $nullabilityEnd + 7;
            if (!$prefixMatches || !$suffixMatches) {
                throw new CoreSchemaException([
                    'reason' => 'mariadb_json_alias_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $columnName,
                ]);
            }
            $columns[$columnName] = true;
        }
        return $columns;
    }

    private function mySqlCreateTableSql(PDO $pdo, string $physicalName, string $logicalName): string
    {
        $sessionState = $this->normalizeCatalogRow($pdo->query(<<<'SQL'
SELECT @@SESSION.character_set_results AS character_set_results,
       @@SESSION.sql_quote_show_create AS sql_quote_show_create,
       @@SESSION.sql_mode AS sql_mode
SQL)->fetch());
        $originalResultsCharacterSet = $sessionState['character_set_results'] ?? null;
        $originalQuoteShowCreate = $sessionState['sql_quote_show_create'] ?? null;
        $originalSqlMode = $sessionState['sql_mode'] ?? null;
        if ($originalResultsCharacterSet !== null
            && (!is_string($originalResultsCharacterSet)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $originalResultsCharacterSet) !== 1)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        if (!in_array($originalQuoteShowCreate, [0, 1, '0', '1'], true)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        if (!is_string($originalSqlMode)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $sqlModes = array_values(array_filter(array_map('trim', explode(',', $originalSqlMode))));
        foreach ($sqlModes as $sqlMode) {
            if (preg_match('/^[A-Z0-9_]+$/D', $sqlMode) !== 1) {
                throw new CoreSchemaException([
                    'reason' => 'schema_introspection_failed',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
        }
        $showSqlMode = implode(',', array_values(array_filter(
            $sqlModes,
            static fn (string $sqlMode): bool => $sqlMode !== 'ANSI_QUOTES'
        )));
        $row = null;
        $pdo->exec('SET SESSION character_set_results = binary');
        try {
            $pdo->exec('SET SESSION sql_quote_show_create = 1');
            try {
                $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($showSqlMode));
                try {
                    $statement = $pdo->query(
                        'SHOW CREATE TABLE ' . $this->quote($physicalName, 'mysql')
                    );
                    $row = $this->normalizeCatalogRow(
                        $statement instanceof PDOStatement ? $statement->fetch() : false
                    );
                } finally {
                    $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($originalSqlMode));
                }
            } finally {
                $pdo->exec(
                    'SET SESSION sql_quote_show_create = ' . (int) $originalQuoteShowCreate
                );
            }
        } finally {
            $pdo->exec(
                $originalResultsCharacterSet === null
                    ? 'SET SESSION character_set_results = NULL'
                    : 'SET SESSION character_set_results = ' . $originalResultsCharacterSet
            );
        }
        if (!is_array($row)) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $createStatements = array_values(array_filter(
            $row,
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\b/i', $value) === 1
        ));
        if (count($createStatements) !== 1
            || preg_match('/^\s*CREATE\s+TEMPORARY\s+TABLE\b/i', $createStatements[0]) === 1) {
            throw new CoreSchemaException([
                'reason' => 'schema_introspection_failed',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        return $createStatements[0];
    }

    /**
     * SHOW CREATE is the only complete evidence for several table options.
     * Keep the accepted tail deliberately small: the auto-increment counter
     * is operational state, while every material Core table option is
     * independently compared with INFORMATION_SCHEMA. Any extra clause
     * (partitioning, comments, encryption, compression, explicit row format,
     * engine attributes, or a future unsupported option) stops inspection.
     */
    private function assertMySqlCreateTableTail(
        string $createSql,
        bool $backslashEscapes,
        string $logicalName,
        string $physicalName,
        string $engine,
        string $characterSet,
        string $collation
    ): void {
        $length = strlen($createSql);
        $open = null;
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($createSql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($createSql[$offset])) {
                $offset = $this->mySqlSkipQuoted(
                    $createSql,
                    $offset,
                    $backslashEscapes,
                    $logicalName,
                    ''
                );
                continue;
            }
            if ($createSql[$offset] === '(') {
                $open = $offset;
                break;
            }
            $offset++;
        }
        if (!is_int($open)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_table_tail_unsupported',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $header = trim(substr($createSql, 0, $open));
        $quotedPhysicalName = str_replace('`', '``', $physicalName);
        if (preg_match(
            '/^CREATE\s+TABLE\s+`' . preg_quote($quotedPhysicalName, '/') . '`$/iD',
            $header
        ) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'mysql_table_tail_unsupported',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        [, $afterDefinition] = $this->mySqlParenthesizedExpression(
            $createSql,
            $open,
            $backslashEscapes,
            $logicalName
        );
        $tail = substr($createSql, $afterDefinition);
        if (preg_match(
            '/^\s*ENGINE\s*=\s*([A-Za-z0-9_]+)'
                . '(?:\s+AUTO_INCREMENT\s*=\s*[1-9][0-9]*)?'
                . '\s+DEFAULT\s+CHARSET\s*=\s*([A-Za-z0-9_]+)'
                . '\s+COLLATE\s*=\s*([A-Za-z0-9_]+)\s*$/iD',
            $tail,
            $matches
        ) !== 1
            || strcasecmp($matches[1], $engine) !== 0
            || strcasecmp($matches[2], $characterSet) !== 0
            || strcasecmp($matches[3], $collation) !== 0) {
            throw new CoreSchemaException([
                'reason' => 'mysql_table_tail_unsupported',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
    }

    private function assertConstraintEnforcementSession(
        PDO $pdo,
        string $driver,
        bool $mariaDb = false
    ): void
    {
        if ($driver === 'sqlite') {
            $foreignKeys = $pdo->query('PRAGMA foreign_keys')->fetchColumn();
            $ignoreChecks = $pdo->query('PRAGMA ignore_check_constraints')->fetchColumn();
            if ((int) $foreignKeys === 1 && (int) $ignoreChecks === 0) {
                return;
            }
            throw new CoreSchemaException([
                'reason' => 'constraint_enforcement_disabled',
                'driver' => 'sqlite',
            ]);
        }
        if ($driver === 'pgsql') {
            $replicationRole = $pdo->query(
                "SELECT current_setting('session_replication_role')"
            )->fetchColumn();
            if ($replicationRole === 'origin') {
                return;
            }
            throw new CoreSchemaException([
                'reason' => 'constraint_enforcement_disabled',
                'driver' => 'pgsql',
            ]);
        }
        $row = $this->normalizeCatalogRow($pdo->query(<<<'SQL'
SELECT @@SESSION.foreign_key_checks AS foreign_key_checks,
       @@SESSION.unique_checks AS unique_checks
SQL)->fetch());
        if (!is_array($row)
            || (int) ($row['foreign_key_checks'] ?? 0) !== 1
            || (int) ($row['unique_checks'] ?? 0) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'constraint_enforcement_disabled',
                'driver' => 'mysql',
            ]);
        }
        if ($mariaDb
            && (int) $pdo->query('SELECT @@SESSION.check_constraint_checks')->fetchColumn() !== 1) {
            throw new CoreSchemaException([
                'reason' => 'constraint_enforcement_disabled',
                'driver' => 'mysql',
            ]);
        }
    }

    /**
     * INFORMATION_SCHEMA.COLUMNS.COLUMN_DEFAULT is utf8mb3 on supported
     * MySQL releases and replaces non-BMP text with "?". SHOW CREATE TABLE is
     * the lossless source of the default expression; I_S remains independent
     * evidence for the exact ordered column set and null/non-null default
     * state. Every clause must map one-to-one or inspection stops.
     *
     * @param array<string, string> $columnTypeFamilies ordered name => type family
     * @return array<string, array{
     *   has_default: bool,
     *   default: ?string,
     *   default_kind: string,
     *   on_update: ?string,
     *   auto_increment: bool
     * }>
     */
    private function mySqlColumnClauseEvidence(
        string $createSql,
        array $columnTypeFamilies,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $evidence = [];
        foreach ($this->mySqlDefinitionSegments($createSql, $backslashEscapes, $logicalName) as $definition) {
            $tokens = $this->mySqlSqlTokens($definition, $backslashEscapes, $logicalName);
            if (($tokens[0]['kind'] ?? null) !== 'identifier') {
                continue;
            }
            $name = strtolower((string) ($tokens[0]['value'] ?? ''));
            if (!array_key_exists($name, $columnTypeFamilies)
                || array_key_exists($name, $evidence)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_clause_ambiguous',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $name,
                ]);
            }
            $evidence[$name] = $this->mySqlParseColumnClause(
                $tokens,
                $columnTypeFamilies[$name],
                $logicalName,
                $name
            );
        }
        if (array_keys($evidence) !== array_keys($columnTypeFamilies)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_column_clause_catalog_mismatch',
                'driver' => 'mysql',
                'table' => $logicalName,
                'catalog_columns' => array_keys($columnTypeFamilies),
                'ddl_columns' => array_keys($evidence),
            ]);
        }
        return $evidence;
    }

    /** @return array<string, string> constraint name => lossless normalized expression */
    private function mySqlCheckClauseEvidence(
        string $createSql,
        bool $backslashEscapes,
        string $logicalName,
        array $mariaDbJsonColumns = [],
        bool $mariaDb = false
    ): array {
        $checks = [];
        foreach ($this->mySqlDefinitionSegments($createSql, $backslashEscapes, $logicalName) as $definition) {
            $tokens = $this->mySqlSqlTokens($definition, $backslashEscapes, $logicalName);
            $checkPositions = [];
            $depth = 0;
            foreach ($tokens as $position => $token) {
                if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === '(') {
                    $depth++;
                    continue;
                }
                if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ')') {
                    $depth--;
                    continue;
                }
                if ($depth === 0 && $this->mySqlTokenIsKeyword($token, 'CHECK')) {
                    $checkPositions[] = $position;
                }
            }
            if ($checkPositions === []) {
                continue;
            }
            $columnName = ($tokens[0]['kind'] ?? null) === 'identifier'
                ? strtolower((string) ($tokens[0]['value'] ?? ''))
                : '';
            if ($columnName !== '' && isset($mariaDbJsonColumns[$columnName])) {
                continue;
            }
            if (count($checkPositions) !== 1
                || !$this->mySqlTokenIsKeyword($tokens[0] ?? null, 'CONSTRAINT')
                || ($tokens[1]['kind'] ?? null) !== 'identifier') {
                throw new CoreSchemaException([
                    'reason' => 'mysql_check_clause_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $constraintName = (string) ($tokens[1]['value'] ?? '');
            $checkPosition = $checkPositions[0];
            $open = $checkPosition + 1;
            if (($tokens[$open]['kind'] ?? null) !== 'symbol'
                || ($tokens[$open]['value'] ?? null) !== '(') {
                throw new CoreSchemaException([
                    'reason' => 'mysql_check_clause_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $close = $this->mySqlMatchingParenthesisToken(
                $tokens,
                $open,
                $logicalName,
                $constraintName
            );
            $expression = '';
            $expressionTokens = array_slice($tokens, $open + 1, $close - $open - 1);
            $expression = $this->mySqlCanonicalTokenSequence($expressionTokens);
            if ($mariaDb) {
                $expression = '(' . $expression . ')';
            }
            if ($constraintName === ''
                || $expression === ''
                || preg_match('/[^\x00-\x7F]/', $expression) === 1
                || array_key_exists($constraintName, $checks)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_check_clause_unsupported',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                ]);
            }
            $checks[$constraintName] = $expression;
        }
        ksort($checks, SORT_STRING);
        return $checks;
    }

    /**
     * @param list<array{kind: string, value: string, raw: string}> $tokens
     * @return array{has_default: bool, default: ?string, default_kind: string, on_update: ?string, auto_increment: bool}
     */
    private function mySqlParseColumnClause(
        array $tokens,
        string $typeFamily,
        string $logicalName,
        string $columnName
    ): array {
        if (count($tokens) < 2) {
            throw $this->mySqlColumnClauseException($logicalName, $columnName);
        }
        $defaultPositions = [];
        $onUpdatePositions = [];
        $autoIncrementPositions = [];
        $depth = 0;
        for ($position = 1, $count = count($tokens); $position < $count; $position++) {
            $token = $tokens[$position];
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === '(') {
                $depth++;
                continue;
            }
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ')') {
                $depth--;
                if ($depth < 0) {
                    throw $this->mySqlColumnClauseException($logicalName, $columnName);
                }
                continue;
            }
            if ($depth !== 0 || ($token['kind'] ?? null) !== 'word') {
                continue;
            }
            $keyword = strtoupper((string) ($token['value'] ?? ''));
            if ($keyword === 'DEFAULT') {
                $defaultPositions[] = $position;
            } elseif ($keyword === 'AUTO_INCREMENT') {
                $autoIncrementPositions[] = $position;
            } elseif ($keyword === 'ON'
                && $this->mySqlTokenIsKeyword($tokens[$position + 1] ?? null, 'UPDATE')) {
                $onUpdatePositions[] = $position;
            }
        }
        if ($depth !== 0
            || count($defaultPositions) > 1
            || count($onUpdatePositions) > 1
            || count($autoIncrementPositions) > 1) {
            throw $this->mySqlColumnClauseException($logicalName, $columnName);
        }

        $default = null;
        $defaultKind = 'absent';
        if ($defaultPositions !== []) {
            [$default, $defaultKind, $next] = $this->mySqlDefaultAtom(
                $tokens,
                $defaultPositions[0] + 1,
                $typeFamily,
                $logicalName,
                $columnName
            );
            $this->assertMySqlClauseBoundary($tokens, $next, $logicalName, $columnName);
        }

        $onUpdate = null;
        if ($onUpdatePositions !== []) {
            [$onUpdate, $onUpdateKind, $next] = $this->mySqlDefaultAtom(
                $tokens,
                $onUpdatePositions[0] + 2,
                'TIMESTAMP',
                $logicalName,
                $columnName
            );
            if ($onUpdateKind !== 'expression'
                || !is_string($onUpdate)
                || !str_starts_with($onUpdate, 'expression:CURRENT_TIMESTAMP')) {
                throw $this->mySqlColumnClauseException($logicalName, $columnName);
            }
            $this->assertMySqlClauseBoundary($tokens, $next, $logicalName, $columnName);
        }

        return [
            'has_default' => $defaultPositions !== [],
            'default' => $default,
            'default_kind' => $defaultKind,
            'on_update' => $onUpdate,
            'auto_increment' => $autoIncrementPositions !== [],
        ];
    }

    /**
     * @param list<array{kind: string, value: string, raw: string}> $tokens
     * @return array{0: ?string, 1: string, 2: int}
     */
    private function mySqlDefaultAtom(
        array $tokens,
        int $offset,
        string $typeFamily,
        string $logicalName,
        string $columnName
    ): array {
        $token = $tokens[$offset] ?? null;
        if (!is_array($token)) {
            throw $this->mySqlColumnClauseException($logicalName, $columnName);
        }
        $kind = (string) ($token['kind'] ?? '');
        $value = (string) ($token['value'] ?? '');
        if ($kind === 'string') {
            $normalized = in_array($typeFamily, [
                'BIGINT', 'BIT', 'DECIMAL', 'DOUBLE', 'FLOAT', 'INT', 'INTEGER',
                'MEDIUMINT', 'NUMERIC', 'REAL', 'SMALLINT', 'TINYINT',
            ], true) && $this->isNumber($value)
                ? 'number:' . $this->normalizeNumber($value)
                : 'string:' . $value;
            return [$normalized, 'literal', $offset + 1];
        }
        if ($kind === 'hex') {
            $hex = substr($value, 2);
            $decoded = $hex !== '' && strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
            if (!is_string($decoded)
                || !in_array($typeFamily, [
                    'CHAR', 'ENUM', 'LONGTEXT', 'MEDIUMTEXT', 'SET', 'TEXT',
                    'TINYTEXT', 'VARCHAR',
                ], true)
                || preg_match('//u', $decoded) !== 1) {
                throw $this->mySqlColumnClauseException($logicalName, $columnName);
            }
            return ['string:' . $decoded, 'literal', $offset + 1];
        }
        if ($kind === 'number') {
            return [$this->normalizeMySqlDefault($value, $typeFamily), 'literal', $offset + 1];
        }
        if ($kind === 'word' && strtoupper($value) === 'NULL') {
            return [null, 'null', $offset + 1];
        }
        if ($kind === 'word'
            && preg_match('/^CURRENT_(?:DATE|TIME|TIMESTAMP)$/iD', $value) === 1) {
            $expression = strtoupper($value);
            $next = $offset + 1;
            if (($tokens[$next]['kind'] ?? null) === 'symbol'
                && ($tokens[$next]['value'] ?? null) === '(') {
                $close = $this->mySqlMatchingParenthesisToken($tokens, $next, $logicalName, $columnName);
                $inside = array_slice($tokens, $next + 1, $close - $next - 1);
                if (count($inside) > 1
                    || ($inside !== [] && ($inside[0]['kind'] ?? null) !== 'number')) {
                    throw $this->mySqlColumnClauseException($logicalName, $columnName);
                }
                $expression .= '(' . ($inside[0]['raw'] ?? '') . ')';
                $next = $close + 1;
            }
            return ['expression:' . $this->normalizeExpression($expression), 'expression', $next];
        }
        if ($kind === 'symbol' && $value === '(') {
            $close = $this->mySqlMatchingParenthesisToken($tokens, $offset, $logicalName, $columnName);
            $raw = $this->mySqlCanonicalTokenSequence(
                array_slice($tokens, $offset + 1, $close - $offset - 1)
            );
            if ($raw === '') {
                throw $this->mySqlColumnClauseException($logicalName, $columnName);
            }
            return ['expression:' . $raw, 'expression', $close + 1];
        }
        throw $this->mySqlColumnClauseException($logicalName, $columnName);
    }

    /** @param list<array{kind: string, value: string, raw: string}> $tokens */
    private function mySqlMatchingParenthesisToken(
        array $tokens,
        int $open,
        string $logicalName,
        string $columnName
    ): int {
        $depth = 0;
        for ($position = $open, $count = count($tokens); $position < $count; $position++) {
            if (($tokens[$position]['kind'] ?? null) !== 'symbol') {
                continue;
            }
            if (($tokens[$position]['value'] ?? null) === '(') {
                $depth++;
            } elseif (($tokens[$position]['value'] ?? null) === ')') {
                $depth--;
                if ($depth === 0) {
                    return $position;
                }
            }
        }
        throw $this->mySqlColumnClauseException($logicalName, $columnName);
    }

    /** @param list<array{kind: string, value: string, raw: string}> $tokens */
    private function assertMySqlClauseBoundary(
        array $tokens,
        int $offset,
        string $logicalName,
        string $columnName
    ): void {
        if (!isset($tokens[$offset])) {
            return;
        }
        $allowed = [
            'AUTO_INCREMENT', 'CHECK', 'COLLATE', 'COLUMN_FORMAT', 'COMMENT',
            'INVISIBLE', 'ON', 'PRIMARY', 'REFERENCES', 'SRID', 'STORAGE',
            'UNIQUE', 'VISIBLE',
        ];
        if (($tokens[$offset]['kind'] ?? null) !== 'word'
            || !in_array(strtoupper((string) ($tokens[$offset]['value'] ?? '')), $allowed, true)) {
            throw $this->mySqlColumnClauseException($logicalName, $columnName);
        }
    }

    /**
     * @param array{has_default: bool, default: ?string, default_kind: string, on_update: ?string, auto_increment: bool} $clauseEvidence
     */
    private function assertMySqlInformationDefaultMatchesClause(
        mixed $informationDefault,
        array $clauseEvidence,
        string $typeFamily,
        string $logicalName,
        string $columnName,
        bool $mariaDb = false,
        bool $backslashEscapes = true
    ): void {
        if ($informationDefault !== null && !is_string($informationDefault)) {
            throw $this->mySqlColumnClauseException($logicalName, $columnName);
        }
        $kind = $clauseEvidence['default_kind'];
        $clauseDefault = $clauseEvidence['default'];
        if ($kind === 'absent' || $kind === 'null') {
            if ($informationDefault !== null
                && !($mariaDb && $informationDefault === 'NULL')) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_default_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $columnName,
                ]);
            }
            return;
        }
        if (!is_string($informationDefault) || !is_string($clauseDefault)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_column_default_catalog_mismatch',
                'driver' => 'mysql',
                'table' => $logicalName,
                'column' => $columnName,
            ]);
        }
        $informationLiteral = $informationDefault;
        if ($mariaDb && str_starts_with($informationLiteral, "'")) {
            try {
                [$decoded, $end] = $this->mySqlQuotedString(
                    $informationLiteral,
                    0,
                    $backslashEscapes,
                    $logicalName
                );
            } catch (CoreSchemaException) {
                $decoded = null;
                $end = -1;
            }
            if (!is_string($decoded) || $end !== strlen($informationLiteral)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_column_default_catalog_mismatch',
                    'driver' => 'mysql',
                    'table' => $logicalName,
                    'column' => $columnName,
                ]);
            }
            $informationLiteral = $decoded;
        }
        $informationNormalized = match (true) {
            $kind === 'expression' => 'expression:'
                . $this->normalizeExpression($this->unwrapParentheses(trim($informationLiteral))),
            str_starts_with($clauseDefault, 'string:') => 'string:' . $informationLiteral,
            default => $this->normalizeMySqlDefault($informationLiteral, $typeFamily),
        };
        $expectedInformation = $this->mySqlUtf8mb3DefaultProjection($clauseDefault);
        if (!is_string($informationNormalized)
            || !hash_equals($expectedInformation, $informationNormalized)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_column_default_catalog_mismatch',
                'driver' => 'mysql',
                'table' => $logicalName,
                'column' => $columnName,
            ]);
        }
    }

    private function mySqlUtf8mb3DefaultProjection(string $default): string
    {
        if (!str_starts_with($default, 'string:')) {
            return $default;
        }
        $value = substr($default, strlen('string:'));
        return 'string:' . $this->mySqlUtf8mb3TextProjection($value);
    }

    private function mySqlUtf8mb3TextProjection(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'mysql_catalog_text_encoding_invalid',
                'driver' => 'mysql',
            ]);
        }
        $projected = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $value);
        if (!is_string($projected)) {
            throw new CoreSchemaException([
                'reason' => 'mysql_catalog_text_encoding_invalid',
                'driver' => 'mysql',
            ]);
        }
        return $projected;
    }

    private function mySqlColumnClauseException(string $logicalName, string $columnName): CoreSchemaException
    {
        return new CoreSchemaException([
            'reason' => 'mysql_column_clause_unsupported',
            'driver' => 'mysql',
            'table' => $logicalName,
            'column' => $columnName,
        ]);
    }

    /** @return list<string> */
    private function mySqlDefinitionSegments(
        string $createSql,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $length = strlen($createSql);
        $open = null;
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($createSql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($createSql[$offset])) {
                $offset = $this->mySqlSkipQuoted(
                    $createSql,
                    $offset,
                    $backslashEscapes,
                    $logicalName,
                    ''
                );
                continue;
            }
            if ($createSql[$offset] === '(') {
                $open = $offset;
                break;
            }
            $offset++;
        }
        if (!is_int($open)) {
            throw $this->mySqlColumnClauseException($logicalName, '');
        }
        [$body] = $this->mySqlParenthesizedExpression(
            $createSql,
            $open,
            $backslashEscapes,
            $logicalName
        );
        $segments = [];
        $start = 0;
        $depth = 0;
        $length = strlen($body);
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($body, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($body[$offset])) {
                $offset = $this->mySqlSkipQuoted(
                    $body,
                    $offset,
                    $backslashEscapes,
                    $logicalName,
                    ''
                );
                continue;
            }
            if ($body[$offset] === '(') {
                $depth++;
            } elseif ($body[$offset] === ')') {
                $depth--;
            } elseif ($body[$offset] === ',' && $depth === 0) {
                $segments[] = trim(substr($body, $start, $offset - $start));
                $start = $offset + 1;
            }
            if ($depth < 0) {
                throw $this->mySqlColumnClauseException($logicalName, '');
            }
            $offset++;
        }
        if ($depth !== 0) {
            throw $this->mySqlColumnClauseException($logicalName, '');
        }
        $segments[] = trim(substr($body, $start));
        return $segments;
    }

    /** @return array{0: string, 1: int} */
    private function mySqlParenthesizedExpression(
        string $sql,
        int $open,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $depth = 1;
        $length = strlen($sql);
        for ($offset = $open + 1; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($sql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($sql[$offset])) {
                $offset = $this->mySqlSkipQuoted(
                    $sql,
                    $offset,
                    $backslashEscapes,
                    $logicalName,
                    ''
                );
                continue;
            }
            if ($sql[$offset] === '(') {
                $depth++;
            } elseif ($sql[$offset] === ')') {
                $depth--;
                if ($depth === 0) {
                    return [substr($sql, $open + 1, $offset - $open - 1), $offset + 1];
                }
            }
            $offset++;
        }
        throw $this->mySqlColumnClauseException($logicalName, '');
    }

    /** @return list<array{kind: string, value: string, raw: string}> */
    private function mySqlSqlTokens(
        string $sql,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $tokens = [];
        $leading = '';
        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            if (ctype_space($sql[$offset])) {
                $leading .= $sql[$offset];
                $offset++;
                continue;
            }
            $commentEnd = $this->skipSqlComment($sql, $offset);
            if ($commentEnd !== null) {
                $leading .= ' ';
                $offset = $commentEnd;
                continue;
            }
            if ($sql[$offset] === '`') {
                $start = $offset;
                $offset = $this->mySqlSkipQuoted($sql, $offset, false, $logicalName, '');
                $raw = substr($sql, $start, $offset - $start);
                $tokens[] = [
                    'kind' => 'identifier',
                    'value' => str_replace('``', '`', substr($raw, 1, -1)),
                    'raw' => $leading . $raw,
                    'leading' => $leading,
                    'token_raw' => $raw,
                ];
                $leading = '';
                continue;
            }
            if ($sql[$offset] === "'") {
                [$decoded, $offset, $raw] = $this->mySqlQuotedString(
                    $sql,
                    $offset,
                    $backslashEscapes,
                    $logicalName
                );
                $tokens[] = [
                    'kind' => 'string',
                    'value' => $decoded,
                    'raw' => $leading . $raw,
                    'leading' => $leading,
                    'token_raw' => $raw,
                ];
                $leading = '';
                continue;
            }
            if (preg_match('/\G0x[0-9A-Fa-f]+\b/i', $sql, $match, 0, $offset) === 1) {
                $tokens[] = [
                    'kind' => 'hex',
                    'value' => $match[0],
                    'raw' => $leading . $match[0],
                    'leading' => $leading,
                    'token_raw' => $match[0],
                ];
                $leading = '';
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match(
                '/\G[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?/i',
                $sql,
                $match,
                0,
                $offset
            ) === 1) {
                $tokens[] = [
                    'kind' => 'number',
                    'value' => $match[0],
                    'raw' => $leading . $match[0],
                    'leading' => $leading,
                    'token_raw' => $match[0],
                ];
                $leading = '';
                $offset += strlen($match[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $match, 0, $offset) === 1) {
                $tokens[] = [
                    'kind' => 'word',
                    'value' => $match[0],
                    'raw' => $leading . $match[0],
                    'leading' => $leading,
                    'token_raw' => $match[0],
                ];
                $leading = '';
                $offset += strlen($match[0]);
                continue;
            }
            if ($this->isSqlQuote($sql[$offset])) {
                throw $this->mySqlColumnClauseException($logicalName, '');
            }
            $tokens[] = [
                'kind' => 'symbol',
                'value' => $sql[$offset],
                'raw' => $leading . $sql[$offset],
                'leading' => $leading,
                'token_raw' => $sql[$offset],
            ];
            $leading = '';
            $offset++;
        }
        return $tokens;
    }

    /** @return array{0: string, 1: int, 2: string} */
    private function mySqlQuotedString(
        string $sql,
        int $open,
        bool $backslashEscapes,
        string $logicalName
    ): array {
        $decoded = '';
        $length = strlen($sql);
        for ($offset = $open + 1; $offset < $length; $offset++) {
            $character = $sql[$offset];
            if ($backslashEscapes && $character === '\\') {
                if ($offset + 1 >= $length) {
                    throw $this->mySqlColumnClauseException($logicalName, '');
                }
                $escape = $sql[++$offset];
                $decoded .= match ($escape) {
                    '0' => "\0",
                    'b' => "\x08",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'Z' => "\x1A",
                    '\\' => '\\',
                    "'" => "'",
                    '"' => '"',
                    default => $escape,
                };
                continue;
            }
            if ($character !== "'") {
                $decoded .= $character;
                continue;
            }
            if (($sql[$offset + 1] ?? '') === "'") {
                $decoded .= "'";
                $offset++;
                continue;
            }
            $end = $offset + 1;
            return [$decoded, $end, substr($sql, $open, $end - $open)];
        }
        throw $this->mySqlColumnClauseException($logicalName, '');
    }

    private function mySqlSkipQuoted(
        string $sql,
        int $open,
        bool $backslashEscapes,
        string $logicalName,
        string $columnName
    ): int {
        $quote = $sql[$open];
        $close = $quote === '[' ? ']' : $quote;
        $length = strlen($sql);
        for ($offset = $open + 1; $offset < $length; $offset++) {
            if ($backslashEscapes && $quote !== '`' && $sql[$offset] === '\\') {
                $offset++;
                continue;
            }
            if ($sql[$offset] !== $close) {
                continue;
            }
            if (($sql[$offset + 1] ?? '') === $close) {
                $offset++;
                continue;
            }
            return $offset + 1;
        }
        throw $this->mySqlColumnClauseException($logicalName, $columnName);
    }

    /** @param array{kind: string, value: string, raw: string}|null $token */
    private function mySqlTokenIsKeyword(?array $token, string $keyword): bool
    {
        return ($token['kind'] ?? null) === 'word'
            && strtoupper((string) ($token['value'] ?? '')) === $keyword;
    }

    /** @param list<array<string, mixed>> $tokens */
    private function mySqlCanonicalTokenSequence(array $tokens): string
    {
        $normalized = '';
        foreach ($tokens as $token) {
            $tokenRaw = $token['token_raw'] ?? null;
            $leading = $token['leading'] ?? null;
            if (!is_string($tokenRaw) || !is_string($leading)) {
                throw new CoreSchemaException([
                    'reason' => 'mysql_expression_token_invalid',
                    'driver' => 'mysql',
                ]);
            }
            if ($normalized !== '' && $leading !== '') {
                $normalized .= ' ';
            }
            $normalized .= $tokenRaw;
        }
        return trim($normalized);
    }

    private function normalizePostgreSqlDefault(mixed $default, string $identity): ?string
    {
        if ($default === null || str_starts_with($identity, 'identity_')) {
            return null;
        }
        $value = trim((string) $default);
        if ($identity === 'sequence') {
            return 'expression:sequence';
        }
        if (preg_match("/^'((?:''|[^'])*)'(?:::[A-Za-z0-9_ .\\[\\]\"]+)?$/D", $value, $matches) === 1) {
            return 'string:' . str_replace("''", "'", $matches[1]);
        }
        if (preg_match('/^([+-]?[0-9]+(?:\.[0-9]+)?)(?:::[A-Za-z0-9_ .]+)?$/D', $value, $matches) === 1) {
            return 'number:' . $this->normalizeNumber($matches[1]);
        }
        if (preg_match('/^CURRENT_(?:DATE|TIME|TIMESTAMP)(?:\(\))?$/iD', $value) === 1) {
            return 'expression:' . strtoupper(str_replace('()', '', $value));
        }
        return 'expression:' . $this->normalizeExpression($value);
    }

    private function normalizeExpression(string $expression): string
    {
        $expression = trim($expression, " \t\n\r\0\x0B;");
        $expression = preg_replace('/\s+/', ' ', $expression) ?? $expression;
        if (preg_match('/^CURRENT_(?:DATE|TIME|TIMESTAMP)(?:\(\)|\(6\))?$/iD', $expression) === 1) {
            return strtoupper(str_replace('()', '', $expression));
        }
        return $expression;
    }

    /** @return array{deferrable: false, initially_deferred: false, validated: true} */
    private function immediatePrimaryKeyOptions(): array
    {
        return [
            'deferrable' => false,
            'initially_deferred' => false,
            'validated' => true,
        ];
    }

    /** @return array{engine: string, character_set: null, collation: null} */
    private function sqliteTableOptions(string $createSql): array
    {
        [, $afterBody] = $this->sqliteCreateTableBody($createSql);
        $tokens = $this->sqliteSqlTokens(substr($createSql, $afterBody));
        $strict = false;
        $withoutRowId = false;
        $expectOption = true;
        $sawOption = false;
        for ($position = 0, $count = count($tokens); $position < $count; $position++) {
            $token = $tokens[$position];
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ';') {
                if ($position !== array_key_last($tokens)) {
                    throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
                }
                continue;
            }
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ',') {
                if ($expectOption) {
                    throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
                }
                $expectOption = true;
                continue;
            }
            if (!$expectOption) {
                throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
            }
            if ($this->sqliteTokenIsKeyword($token, 'STRICT')) {
                if ($strict) {
                    throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
                }
                $strict = true;
                $sawOption = true;
                $expectOption = false;
                continue;
            }
            if ($this->sqliteTokenIsKeyword($token, 'WITHOUT')) {
                $next = $tokens[$position + 1] ?? null;
                if ($withoutRowId || !$this->sqliteTokenIsKeyword($next, 'ROWID')) {
                    throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
                }
                $withoutRowId = true;
                $sawOption = true;
                $position++;
                $expectOption = false;
                continue;
            }
            throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
        }
        if ($expectOption && $sawOption) {
            throw new CoreSchemaException(['reason' => 'sqlite_table_options_unsupported', 'driver' => 'sqlite']);
        }

        return [
            'engine' => ($strict ? 'STRICT ' : '') . ($withoutRowId ? 'WITHOUT ROWID' : 'ROWID'),
            'character_set' => null,
            'collation' => null,
        ];
    }

    /**
     * SQLite's PRAGMA foreign_key_list intentionally loses MATCH and timing
     * syntax. Reconstruct those attributes from the live CREATE TABLE text,
     * then bind every DDL clause one-to-one to its PRAGMA semantic identity.
     * A guess or positional fallback would turn ambiguous DDL into evidence.
     *
     * @param array<int, array<string, mixed>> $pragmaGroups
     * @return list<array<string, mixed>>
     */
    private function sqliteForeignKeys(
        string $createSql,
        string $prefix,
        array $pragmaGroups,
        string $logicalName
    ): array {
        $pragmaForeignKeys = [];
        foreach ($pragmaGroups as $foreignKey) {
            ksort($foreignKey['columns'], SORT_NUMERIC);
            ksort($foreignKey['referenced_columns'], SORT_NUMERIC);
            $foreignKey['columns'] = array_values(array_map('strtolower', $foreignKey['columns']));
            $foreignKey['referenced_columns'] = array_values(array_map('strtolower', $foreignKey['referenced_columns']));
            if ($foreignKey['columns'] === []
                || count($foreignKey['columns']) !== count($foreignKey['referenced_columns'])
                || in_array('', $foreignKey['referenced_columns'], true)) {
                throw new CoreSchemaException([
                    'reason' => 'sqlite_foreign_key_introspection_failed',
                    'driver' => 'sqlite',
                    'table' => $logicalName,
                ]);
            }
            $pragmaForeignKeys[] = $foreignKey;
        }

        $ddlForeignKeys = $this->sqliteForeignKeyDefinitions($createSql, $prefix, $logicalName);
        $ddlBySemanticKey = [];
        foreach ($ddlForeignKeys as $foreignKey) {
            $key = $this->sqliteForeignKeySemanticKey($foreignKey);
            if (isset($ddlBySemanticKey[$key])) {
                throw new CoreSchemaException([
                    'reason' => 'sqlite_foreign_key_mapping_ambiguous',
                    'driver' => 'sqlite',
                    'table' => $logicalName,
                ]);
            }
            $ddlBySemanticKey[$key] = $foreignKey;
        }

        $resolved = [];
        $seenPragmaKeys = [];
        foreach ($pragmaForeignKeys as $foreignKey) {
            $key = $this->sqliteForeignKeySemanticKey($foreignKey);
            if (isset($seenPragmaKeys[$key]) || !isset($ddlBySemanticKey[$key])) {
                throw new CoreSchemaException([
                    'reason' => 'sqlite_foreign_key_mapping_ambiguous',
                    'driver' => 'sqlite',
                    'table' => $logicalName,
                ]);
            }
            $seenPragmaKeys[$key] = true;
            $ddl = $ddlBySemanticKey[$key];
            if (($foreignKey['on_update'] ?? null) !== $ddl['on_update']
                || ($foreignKey['on_delete'] ?? null) !== $ddl['on_delete']) {
                throw new CoreSchemaException([
                    'reason' => 'sqlite_foreign_key_metadata_conflict',
                    'driver' => 'sqlite',
                    'table' => $logicalName,
                ]);
            }
            $foreignKey['match'] = $ddl['match'];
            $foreignKey['deferrable'] = $ddl['deferrable'];
            $foreignKey['initially_deferred'] = $ddl['initially_deferred'];
            $foreignKey['validated'] = true;
            $resolved[] = $foreignKey;
        }
        if (count($seenPragmaKeys) !== count($ddlBySemanticKey)) {
            throw new CoreSchemaException([
                'reason' => 'sqlite_foreign_key_mapping_incomplete',
                'driver' => 'sqlite',
                'table' => $logicalName,
            ]);
        }
        $this->sortSemanticList($resolved);
        return $resolved;
    }

    /** @param array<string, mixed> $foreignKey */
    private function sqliteForeignKeySemanticKey(array $foreignKey): string
    {
        return CoreSchemaCatalog::canonicalJson([
            'columns' => array_values($foreignKey['columns'] ?? []),
            'referenced_columns' => array_values($foreignKey['referenced_columns'] ?? []),
            'referenced_table' => (string) ($foreignKey['referenced_table'] ?? ''),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function sqliteForeignKeyDefinitions(
        string $createSql,
        string $prefix,
        string $logicalName
    ): array {
        $foreignKeys = [];
        foreach ($this->sqliteDefinitionSegments($createSql) as $definition) {
            $tokens = $this->sqliteSqlTokens($definition);
            if ($tokens === []) {
                continue;
            }
            $offset = 0;
            if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'CONSTRAINT')) {
                $offset++;
                $constraintName = $tokens[$offset] ?? null;
                if (!is_array($constraintName)
                    || !in_array($constraintName['kind'] ?? null, ['identifier', 'word'], true)
                    || (string) ($constraintName['value'] ?? '') === ''
                    || str_contains((string) $constraintName['value'], "\0")) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                $offset++;
            }

            $localColumns = [];
            $referencesOffset = null;
            if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'FOREIGN')) {
                if (!$this->sqliteTokenIsKeyword($tokens[$offset + 1] ?? null, 'KEY')) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                $offset += 2;
                [$localColumns, $offset] = $this->sqliteIdentifierList($tokens, $offset, $logicalName);
                if (!$this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'REFERENCES')) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                $referencesOffset = $offset;
            } else {
                // A non-FK table constraint cannot contain a top-level
                // REFERENCES clause. Quoted strings and nested CHECK/default
                // expressions are excluded by token kind/depth below.
                if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'PRIMARY')
                    || $this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'UNIQUE')
                    || $this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'CHECK')) {
                    continue;
                }
                $localColumns[] = strtolower($this->sqliteTokenIdentifier($tokens[0] ?? null, $logicalName));
                $depth = 0;
                foreach ($tokens as $position => $token) {
                    if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === '(') {
                        $depth++;
                        continue;
                    }
                    if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ')') {
                        $depth--;
                        if ($depth < 0) {
                            throw $this->sqliteForeignKeyDdlException($logicalName);
                        }
                        continue;
                    }
                    if ($depth === 0 && $this->sqliteTokenIsKeyword($token, 'REFERENCES')) {
                        if ($referencesOffset !== null) {
                            throw new CoreSchemaException([
                                'reason' => 'sqlite_foreign_key_mapping_ambiguous',
                                'driver' => 'sqlite',
                                'table' => $logicalName,
                            ]);
                        }
                        $referencesOffset = $position;
                    }
                }
                if ($depth !== 0) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                if ($referencesOffset === null) {
                    continue;
                }
            }

            $offset = $referencesOffset + 1;
            $physicalReferencedTable = $this->sqliteTokenIdentifier($tokens[$offset] ?? null, $logicalName);
            $referencedTable = $this->logicalReference($physicalReferencedTable, $prefix);
            $offset++;
            [$referencedColumns, $offset] = $this->sqliteIdentifierList($tokens, $offset, $logicalName);

            $onUpdate = 'NO ACTION';
            $onDelete = 'NO ACTION';
            $match = 'NONE';
            $deferrable = false;
            $initiallyDeferred = false;
            $seen = [];
            $depth = 0;
            for ($position = $offset, $count = count($tokens); $position < $count; $position++) {
                $token = $tokens[$position];
                if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === '(') {
                    $depth++;
                    continue;
                }
                if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ')') {
                    $depth--;
                    if ($depth < 0) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    continue;
                }
                if ($depth !== 0) {
                    continue;
                }
                if ($this->sqliteTokenIsKeyword($token, 'ON')
                    && ($this->sqliteTokenIsKeyword($tokens[$position + 1] ?? null, 'UPDATE')
                        || $this->sqliteTokenIsKeyword($tokens[$position + 1] ?? null, 'DELETE'))) {
                    $kind = strtoupper((string) ($tokens[$position + 1]['value'] ?? ''));
                    if (isset($seen['on_' . strtolower($kind)])) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    [$action, $position] = $this->sqliteForeignKeyAction($tokens, $position + 2, $logicalName);
                    $seen['on_' . strtolower($kind)] = true;
                    if ($kind === 'UPDATE') {
                        $onUpdate = $action;
                    } else {
                        $onDelete = $action;
                    }
                    continue;
                }
                if ($this->sqliteTokenIsKeyword($token, 'MATCH')) {
                    if (isset($seen['match'])) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    $matchName = strtoupper($this->sqliteTokenIdentifier($tokens[$position + 1] ?? null, $logicalName));
                    if (!in_array($matchName, ['NONE', 'SIMPLE', 'FULL', 'PARTIAL'], true)) {
                        throw new CoreSchemaException([
                            'reason' => 'sqlite_foreign_key_match_unsupported',
                            'driver' => 'sqlite',
                            'table' => $logicalName,
                        ]);
                    }
                    $match = $matchName;
                    $seen['match'] = true;
                    $position++;
                    continue;
                }
                $notDeferrable = $this->sqliteTokenIsKeyword($token, 'NOT')
                    && $this->sqliteTokenIsKeyword($tokens[$position + 1] ?? null, 'DEFERRABLE');
                if ($this->sqliteTokenIsKeyword($token, 'DEFERRABLE') || $notDeferrable) {
                    if (isset($seen['deferrable'])) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    $deferrable = !$notDeferrable;
                    $seen['deferrable'] = true;
                    if ($notDeferrable) {
                        $position++;
                    }
                    continue;
                }
                if ($this->sqliteTokenIsKeyword($token, 'INITIALLY')) {
                    if (!isset($seen['deferrable']) || isset($seen['initially'])) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    if ($this->sqliteTokenIsKeyword($tokens[$position + 1] ?? null, 'DEFERRED')) {
                        if (!$deferrable) {
                            throw $this->sqliteForeignKeyDdlException($logicalName);
                        }
                        $initiallyDeferred = true;
                    } elseif (!$this->sqliteTokenIsKeyword($tokens[$position + 1] ?? null, 'IMMEDIATE')) {
                        throw $this->sqliteForeignKeyDdlException($logicalName);
                    }
                    $seen['initially'] = true;
                    $position++;
                }
            }
            if ($depth !== 0) {
                throw $this->sqliteForeignKeyDdlException($logicalName);
            }

            $foreignKeys[] = [
                'columns' => array_values(array_map('strtolower', $localColumns)),
                'referenced_columns' => array_values(array_map('strtolower', $referencedColumns)),
                'referenced_table' => strtolower($referencedTable),
                'on_update' => $onUpdate,
                'on_delete' => $onDelete,
                'match' => $match,
                'deferrable' => $deferrable,
                'initially_deferred' => $initiallyDeferred,
                'validated' => true,
            ];
        }
        return $foreignKeys;
    }

    /** @param list<array{kind: string, value: string}> $tokens @return array{0: list<string>, 1: int} */
    private function sqliteIdentifierList(array $tokens, int $offset, string $logicalName): array
    {
        if (($tokens[$offset]['kind'] ?? null) !== 'symbol' || ($tokens[$offset]['value'] ?? null) !== '(') {
            throw $this->sqliteForeignKeyDdlException($logicalName);
        }
        $offset++;
        $identifiers = [];
        $expectIdentifier = true;
        for ($count = count($tokens); $offset < $count; $offset++) {
            $token = $tokens[$offset];
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ')') {
                if ($expectIdentifier || $identifiers === []) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                return [$identifiers, $offset + 1];
            }
            if (($token['kind'] ?? null) === 'symbol' && ($token['value'] ?? null) === ',') {
                if ($expectIdentifier) {
                    throw $this->sqliteForeignKeyDdlException($logicalName);
                }
                $expectIdentifier = true;
                continue;
            }
            if (!$expectIdentifier) {
                throw $this->sqliteForeignKeyDdlException($logicalName);
            }
            $identifiers[] = strtolower($this->sqliteTokenIdentifier($token, $logicalName));
            $expectIdentifier = false;
        }
        throw $this->sqliteForeignKeyDdlException($logicalName);
    }

    /** @param list<array{kind: string, value: string}> $tokens @return array{0: string, 1: int} */
    private function sqliteForeignKeyAction(array $tokens, int $offset, string $logicalName): array
    {
        if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'CASCADE')
            || $this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'RESTRICT')) {
            return [strtoupper((string) $tokens[$offset]['value']), $offset];
        }
        if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'NO')
            && $this->sqliteTokenIsKeyword($tokens[$offset + 1] ?? null, 'ACTION')) {
            return ['NO ACTION', $offset + 1];
        }
        if ($this->sqliteTokenIsKeyword($tokens[$offset] ?? null, 'SET')
            && ($this->sqliteTokenIsKeyword($tokens[$offset + 1] ?? null, 'NULL')
                || $this->sqliteTokenIsKeyword($tokens[$offset + 1] ?? null, 'DEFAULT'))) {
            return ['SET ' . strtoupper((string) $tokens[$offset + 1]['value']), $offset + 1];
        }
        throw $this->sqliteForeignKeyDdlException($logicalName);
    }

    /** @param array{kind: string, value: string}|null $token */
    private function sqliteTokenIsKeyword(?array $token, string $keyword): bool
    {
        return ($token['kind'] ?? null) === 'word'
            && strtoupper((string) ($token['value'] ?? '')) === $keyword;
    }

    /** @param array{kind: string, value: string}|null $token */
    private function sqliteTokenIdentifier(?array $token, string $logicalName): string
    {
        if (!is_array($token) || !in_array($token['kind'] ?? null, ['identifier', 'word'], true)) {
            throw $this->sqliteForeignKeyDdlException($logicalName);
        }
        $identifier = (string) ($token['value'] ?? '');
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $identifier) !== 1) {
            throw $this->sqliteForeignKeyDdlException($logicalName);
        }
        return $identifier;
    }

    private function sqliteForeignKeyDdlException(string $logicalName): CoreSchemaException
    {
        return new CoreSchemaException([
            'reason' => 'sqlite_foreign_key_ddl_unsupported',
            'driver' => 'sqlite',
            'table' => $logicalName,
        ]);
    }

    /** @return list<array{kind: string, value: string}> */
    private function sqliteSqlTokens(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            if (ctype_space($sql[$offset])) {
                $offset++;
                continue;
            }
            $commentEnd = $this->skipSqlComment($sql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($sql[$offset])) {
                $start = $offset;
                $offset = $this->skipSqlQuoted($sql, $offset);
                $quoted = substr($sql, $start, $offset - $start);
                if ($quoted[0] === "'") {
                    $tokens[] = ['kind' => 'string', 'value' => $quoted];
                    continue;
                }
                $close = $quoted[0] === '[' ? ']' : $quoted[0];
                $value = substr($quoted, 1, -1);
                $value = str_replace($close . $close, $close, $value);
                $tokens[] = ['kind' => 'identifier', 'value' => $value];
                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $match, 0, $offset) === 1) {
                $tokens[] = ['kind' => 'word', 'value' => $match[0]];
                $offset += strlen($match[0]);
                continue;
            }
            $tokens[] = ['kind' => 'symbol', 'value' => $sql[$offset]];
            $offset++;
        }
        return $tokens;
    }

    /** @return list<array{expression: string, enforced: bool, validated: bool}> */
    private function sqliteCheckConstraints(string $createSql): array
    {
        $checks = [];
        $length = strlen($createSql);
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($createSql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($createSql[$offset])) {
                $offset = $this->skipSqlQuoted($createSql, $offset);
                continue;
            }
            if (strncasecmp(substr($createSql, $offset, 5), 'CHECK', 5) !== 0
                || ($offset > 0 && preg_match('/[A-Za-z0-9_]/', $createSql[$offset - 1]) === 1)
                || ($offset + 5 < $length && preg_match('/[A-Za-z0-9_]/', $createSql[$offset + 5]) === 1)) {
                $offset++;
                continue;
            }
            $open = $offset + 5;
            while ($open < $length && ctype_space($createSql[$open])) {
                $open++;
            }
            if ($open >= $length || $createSql[$open] !== '(') {
                throw new CoreSchemaException(['reason' => 'check_constraint_introspection_failed', 'driver' => 'sqlite']);
            }
            [$expression, $offset] = $this->sqlParenthesizedExpression($createSql, $open);
            $checks[] = [
                'expression' => $this->normalizeExpression($expression),
                'enforced' => true,
                'validated' => true,
            ];
        }
        $this->sortSemanticList($checks);
        return $checks;
    }

    /** @param list<string> $columnNames @return array<string, string> */
    private function sqliteColumnCollations(string $createSql, array $columnNames): array
    {
        $collations = array_fill_keys($columnNames, 'binary');
        foreach ($this->sqliteDefinitionSegments($createSql) as $definition) {
            $definition = ltrim($definition);
            if ($definition === '') {
                continue;
            }
            $name = '';
            $remainder = '';
            if (preg_match('/^"((?:[^"]|"")*)"\s+([\s\S]*)$/D', $definition, $match) === 1) {
                $name = str_replace('""', '"', $match[1]);
                $remainder = $match[2];
            } elseif (preg_match('/^`((?:[^`]|``)*)`\s+([\s\S]*)$/D', $definition, $match) === 1) {
                $name = str_replace('``', '`', $match[1]);
                $remainder = $match[2];
            } elseif (preg_match('/^\[((?:[^\]]|\]\])*)\]\s+([\s\S]*)$/D', $definition, $match) === 1) {
                $name = str_replace(']]', ']', $match[1]);
                $remainder = $match[2];
            } elseif (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s+([\s\S]*)$/D', $definition, $match) === 1) {
                $name = $match[1];
                $remainder = $match[2];
            }
            $name = strtolower($name);
            if (!array_key_exists($name, $collations)) {
                continue;
            }
            if (preg_match(
                '/\bCOLLATE\s+(?:"((?:[^"]|"")*)"|`((?:[^`]|``)*)`|\[((?:[^\]]|\]\])*)\]|([A-Za-z_][A-Za-z0-9_]*))/i',
                $remainder,
                $match
            ) !== 1) {
                continue;
            }
            $collation = match (true) {
                ($match[1] ?? '') !== '' => str_replace('""', '"', $match[1]),
                ($match[2] ?? '') !== '' => str_replace('``', '`', $match[2]),
                ($match[3] ?? '') !== '' => str_replace(']]', ']', $match[3]),
                default => (string) ($match[4] ?? ''),
            };
            if ($collation === '') {
                throw new CoreSchemaException(['reason' => 'column_collation_introspection_failed', 'driver' => 'sqlite']);
            }
            $collations[$name] = strtolower($collation);
        }
        return $collations;
    }

    /** @return list<string> */
    private function sqliteDefinitionSegments(string $createSql): array
    {
        [$body] = $this->sqliteCreateTableBody($createSql);
        $segments = [];
        $start = 0;
        $depth = 0;
        $length = strlen($body);
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($body, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($body[$offset])) {
                $offset = $this->skipSqlQuoted($body, $offset);
                continue;
            }
            if ($body[$offset] === '(') {
                $depth++;
            } elseif ($body[$offset] === ')') {
                $depth--;
            } elseif ($body[$offset] === ',' && $depth === 0) {
                $segments[] = trim(substr($body, $start, $offset - $start));
                $start = $offset + 1;
            }
            $offset++;
        }
        if ($depth !== 0) {
            throw new CoreSchemaException(['reason' => 'schema_introspection_failed', 'driver' => 'sqlite']);
        }
        $segments[] = trim(substr($body, $start));
        return $segments;
    }

    /** @return array{0: string, 1: int} body and offset after its closing parenthesis */
    private function sqliteCreateTableBody(string $createSql): array
    {
        $length = strlen($createSql);
        for ($offset = 0; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($createSql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($createSql[$offset])) {
                $offset = $this->skipSqlQuoted($createSql, $offset);
                continue;
            }
            if ($createSql[$offset] === '(') {
                return $this->sqlParenthesizedExpression($createSql, $offset);
            }
            $offset++;
        }
        throw new CoreSchemaException(['reason' => 'schema_introspection_failed', 'driver' => 'sqlite']);
    }

    /** @return array{0: string, 1: int} expression and offset after the closing parenthesis */
    private function sqlParenthesizedExpression(string $sql, int $open): array
    {
        if (($sql[$open] ?? '') !== '(') {
            throw new CoreSchemaException(['reason' => 'schema_introspection_failed']);
        }
        $depth = 1;
        $length = strlen($sql);
        for ($offset = $open + 1; $offset < $length;) {
            $commentEnd = $this->skipSqlComment($sql, $offset);
            if ($commentEnd !== null) {
                $offset = $commentEnd;
                continue;
            }
            if ($this->isSqlQuote($sql[$offset])) {
                $offset = $this->skipSqlQuoted($sql, $offset);
                continue;
            }
            if ($sql[$offset] === '(') {
                $depth++;
            } elseif ($sql[$offset] === ')') {
                $depth--;
                if ($depth === 0) {
                    return [substr($sql, $open + 1, $offset - $open - 1), $offset + 1];
                }
            }
            $offset++;
        }
        throw new CoreSchemaException(['reason' => 'schema_introspection_failed']);
    }

    private function skipSqlComment(string $sql, int $offset): ?int
    {
        $length = strlen($sql);
        if (($sql[$offset] ?? '') === '-' && ($sql[$offset + 1] ?? '') === '-') {
            $newline = strpos($sql, "\n", $offset + 2);
            return $newline === false ? $length : $newline + 1;
        }
        if (($sql[$offset] ?? '') !== '/' || ($sql[$offset + 1] ?? '') !== '*') {
            return null;
        }
        $depth = 1;
        for ($position = $offset + 2; $position < $length - 1; $position++) {
            if ($sql[$position] === '/' && $sql[$position + 1] === '*') {
                $depth++;
                $position++;
                continue;
            }
            if ($sql[$position] === '*' && $sql[$position + 1] === '/') {
                $depth--;
                $position++;
                if ($depth === 0) {
                    return $position + 1;
                }
            }
        }
        throw new CoreSchemaException(['reason' => 'schema_introspection_failed', 'driver' => 'sqlite']);
    }

    private function isSqlQuote(string $character): bool
    {
        return in_array($character, ["'", '"', '`', '['], true);
    }

    private function skipSqlQuoted(string $sql, int $open): int
    {
        $quote = $sql[$open];
        $close = $quote === '[' ? ']' : $quote;
        $length = strlen($sql);
        for ($offset = $open + 1; $offset < $length; $offset++) {
            if ($sql[$offset] !== $close) {
                continue;
            }
            if ($offset + 1 < $length && $sql[$offset + 1] === $close) {
                $offset++;
                continue;
            }
            return $offset + 1;
        }
        throw new CoreSchemaException(['reason' => 'schema_introspection_failed']);
    }

    private function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));
        $type = preg_replace('/\s+/', ' ', $type) ?? $type;
        $type = preg_replace('/\s*([(),])\s*/', '$1', $type) ?? $type;
        return $type;
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || strtolower((string) $value) === 't';
    }

    private function sqliteAffinity(string $type): string
    {
        $type = strtoupper($type);
        return match (true) {
            str_contains($type, 'INT') => 'INTEGER',
            str_contains($type, 'CHAR'), str_contains($type, 'CLOB'), str_contains($type, 'TEXT') => 'TEXT',
            $type === '' || str_contains($type, 'BLOB') => 'BLOB',
            str_contains($type, 'REAL'), str_contains($type, 'FLOA'), str_contains($type, 'DOUB') => 'REAL',
            default => 'NUMERIC',
        };
    }

    private function normalizeNumber(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match('/^([+-]?)([0-9]+)$/D', $value, $matches) === 1) {
            $digits = ltrim($matches[2], '0');
            $digits = $digits === '' ? '0' : $digits;
            return ($matches[1] === '-' && $digits !== '0' ? '-' : '') . $digits;
        }
        return $value;
    }

    private function isNumber(string $value): bool
    {
        return preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?$/iD', $value) === 1;
    }

    private function isQuoted(string $value): bool
    {
        return strlen($value) >= 2
            && (($value[0] === "'" && str_ends_with($value, "'"))
                || ($value[0] === '"' && str_ends_with($value, '"')));
    }

    private function decodeSqlString(string $value): string
    {
        $quote = $value[0];
        return str_replace($quote . $quote, $quote, substr($value, 1, -1));
    }

    private function unwrapParentheses(string $value): string
    {
        while (strlen($value) >= 2 && $value[0] === '(' && str_ends_with($value, ')')) {
            $depth = 0;
            $balanced = true;
            $length = strlen($value);
            for ($index = 0; $index < $length; $index++) {
                if ($value[$index] === '(') {
                    $depth++;
                } elseif ($value[$index] === ')') {
                    $depth--;
                    if ($depth === 0 && $index !== $length - 1) {
                        $balanced = false;
                        break;
                    }
                }
            }
            if (!$balanced || $depth !== 0) {
                break;
            }
            $value = trim(substr($value, 1, -1));
        }
        return $value;
    }

    private function postgreSqlReferentialAction(string $action): string
    {
        return match ($action) {
            'c' => 'CASCADE',
            'n' => 'SET NULL',
            'd' => 'SET DEFAULT',
            'r' => 'RESTRICT',
            default => 'NO ACTION',
        };
    }

    private function postgreSqlCanonicalIdentifier(mixed $identifier, string $logicalName): string
    {
        if (!is_string($identifier)
            || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'postgresql_identifier_unsupported',
                'driver' => 'pgsql',
                'table' => $logicalName,
            ]);
        }
        return $identifier;
    }

    private function mySqlCanonicalIdentifier(mixed $identifier, string $logicalName): string
    {
        if (!is_string($identifier)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'mysql_identifier_unsupported',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        return $identifier;
    }

    private function mySqlLogicalReference(
        mixed $physicalName,
        string $prefix,
        string $logicalName
    ): string {
        if (!is_string($physicalName)
            || ($prefix !== '' && !str_starts_with($physicalName, $prefix))) {
            throw new CoreSchemaException([
                'reason' => 'foreign_key_target_outside_scope',
                'driver' => 'mysql',
                'table' => $logicalName,
            ]);
        }
        $reference = $prefix === '' ? $physicalName : substr($physicalName, strlen($prefix));
        return $this->mySqlCanonicalIdentifier($reference, $logicalName);
    }

    private function postgreSqlMatch(string $match): string
    {
        return match ($match) {
            'f' => 'FULL',
            'p' => 'PARTIAL',
            default => 'SIMPLE',
        };
    }

    private function logicalReference(string $physicalName, string $prefix): string
    {
        if ($prefix !== '') {
            if (!str_starts_with($physicalName, $prefix)) {
                throw new CoreSchemaException(['reason' => 'foreign_key_target_outside_scope']);
            }
            $physicalName = substr($physicalName, strlen($prefix));
        }
        $logicalName = strtolower($physicalName);
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $logicalName) !== 1) {
            throw new CoreSchemaException(['reason' => 'foreign_key_target_invalid']);
        }
        return $logicalName;
    }

    /** @param list<string> $logicalNames @return list<string> */
    private function normalizeLogicalNames(array $logicalNames): array
    {
        if ($logicalNames === []) {
            throw new CoreSchemaException(['reason' => 'core_table_selection_empty']);
        }
        $normalized = [];
        foreach ($logicalNames as $logicalName) {
            if (!is_string($logicalName)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $logicalName) !== 1) {
                throw new CoreSchemaException(['reason' => 'core_table_selection_invalid']);
            }
            $normalized[] = $logicalName;
        }
        $normalized = array_values(array_unique($normalized, SORT_STRING));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /** @return array<string, mixed>|null */
    private function normalizeCatalogRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $normalized = [];
        foreach ($row as $key => $value) {
            // FETCH_BOTH may include numeric aliases for the same fields.
            // Only catalog labels are schema evidence, and their casing is
            // explicitly independent of the caller's PDO::ATTR_CASE mode.
            if (!is_string($key)) {
                continue;
            }
            $normalizedKey = strtolower($key);
            if (array_key_exists($normalizedKey, $normalized)) {
                throw new CoreSchemaException([
                    'reason' => 'schema_catalog_label_ambiguous',
                    'label' => $normalizedKey,
                ]);
            }
            $normalized[$normalizedKey] = $value;
        }
        if ($row !== [] && $normalized === []) {
            throw new CoreSchemaException(['reason' => 'schema_catalog_labels_missing']);
        }
        return $normalized;
    }

    /** @param array<int, mixed> $rows @return list<array<string, mixed>> */
    private function normalizeCatalogRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalizedRow = $this->normalizeCatalogRow($row);
            if (!is_array($normalizedRow)) {
                throw new CoreSchemaException(['reason' => 'schema_catalog_row_invalid']);
            }
            $normalized[] = $normalizedRow;
        }
        return $normalized;
    }

    private function prefix(PDO $pdo): string
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new CoreSchemaException(['reason' => 'table_prefix_invalid']);
        }
        return $prefix;
    }

    private function quote(string $identifier, string $driver): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $identifier) !== 1) {
            throw new CoreSchemaException(['reason' => 'schema_identifier_invalid']);
        }
        return $driver === 'pgsql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }
}
