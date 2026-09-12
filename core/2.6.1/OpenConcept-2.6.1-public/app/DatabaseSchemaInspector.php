<?php

declare(strict_types=1);

final class DatabaseSchemaInspector
{
    public function inspect(PDO $pdo): LogicalDatabaseSchema
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return match ($driver) {
            'sqlite' => $this->inspectSqlite($pdo),
            'mysql' => $this->inspectMySql($pdo),
            'pgsql' => $this->inspectPostgreSql($pdo),
            default => throw new DatabaseAdapterException('Unsupported schema inspection driver.', 'driver_mismatch'),
        };
    }

    private function inspectSqlite(PDO $pdo): LogicalDatabaseSchema
    {
        $prefix = $this->prefix($pdo);
        $names = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $tables = [];
        foreach ($names as $physicalName) {
            $physicalName = (string) $physicalName;
            $logicalName = $this->logicalName($physicalName, $prefix);
            if ($logicalName === null) {
                continue;
            }
            $quoted = $this->quote($physicalName, 'sqlite');
            $columnRows = $pdo->query("PRAGMA table_info({$quoted})")->fetchAll();
            $columns = [];
            $primary = [];
            foreach ($columnRows as $column) {
                $name = (string) $column['name'];
                $pkPosition = (int) $column['pk'];
                $columns[$name] = [
                    'name' => $name,
                    'type' => strtoupper(trim((string) $column['type'])),
                    'nullable' => (int) $column['notnull'] !== 1 && $pkPosition === 0,
                    'default' => $column['dflt_value'],
                    'primary_position' => $pkPosition,
                    'auto_increment' => $pkPosition > 0 && str_contains(strtoupper((string) $column['type']), 'INT'),
                ];
                if ($pkPosition > 0) {
                    $primary[$pkPosition] = $name;
                }
            }
            ksort($primary);

            $foreignKeys = [];
            foreach ($pdo->query("PRAGMA foreign_key_list({$quoted})")->fetchAll() as $foreignKey) {
                $referenced = $this->logicalName((string) $foreignKey['table'], $prefix);
                if ($referenced === null) {
                    continue;
                }
                $foreignKeys[] = [
                    'column' => (string) $foreignKey['from'],
                    'referenced_table' => $referenced,
                    'referenced_column' => (string) $foreignKey['to'],
                    'on_delete' => strtoupper((string) $foreignKey['on_delete']),
                    'on_update' => strtoupper((string) $foreignKey['on_update']),
                ];
            }

            $indexes = [];
            foreach ($pdo->query("PRAGMA index_list({$quoted})")->fetchAll() as $index) {
                $indexName = (string) $index['name'];
                $indexColumns = [];
                foreach ($pdo->query('PRAGMA index_info(' . $this->quote($indexName, 'sqlite') . ')')->fetchAll() as $indexColumn) {
                    $indexColumns[(int) $indexColumn['seqno']] = (string) $indexColumn['name'];
                }
                ksort($indexColumns);
                if ($indexColumns !== []) {
                    $indexes[] = [
                        'name' => $indexName,
                        'unique' => (int) $index['unique'] === 1,
                        'columns' => array_values($indexColumns),
                    ];
                }
            }
            $tables[$logicalName] = [
                'logical_name' => $logicalName,
                'physical_name' => $physicalName,
                'columns' => $columns,
                'primary_key' => array_values($primary),
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes,
            ];
        }
        return new LogicalDatabaseSchema($tables);
    }

    private function inspectMySql(PDO $pdo): LogicalDatabaseSchema
    {
        $prefix = $this->prefix($pdo);
        $names = $pdo->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name'
        )->fetchAll(PDO::FETCH_COLUMN);
        $logicalNames = [];
        foreach ($names as $physicalName) {
            $physicalName = (string) $physicalName;
            $logicalName = $this->logicalName($physicalName, $prefix);
            if ($logicalName !== null) {
                $logicalNames[$physicalName] = $logicalName;
            }
        }

        $columnsByTable = [];
        $primaryByTable = [];
        $foreignKeysByTable = [];
        $indexesByTable = [];
        if ($logicalNames !== []) {
            $physicalNames = array_keys($logicalNames);
            $expectedNames = array_fill_keys($physicalNames, true);
            $placeholders = implode(', ', array_fill(0, count($physicalNames), '?'));

            $columnsStatement = $pdo->prepare(<<<SQL
SELECT table_name, column_name, column_type, is_nullable, column_default,
       extra, ordinal_position, column_key
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
ORDER BY table_name, ordinal_position
SQL);
            $columnsStatement->execute($physicalNames);
            $columnsByTable = $this->groupMySqlRows($columnsStatement->fetchAll(), $expectedNames);

            $primaryStatement = $pdo->prepare(<<<SQL
SELECT table_name, column_name, ordinal_position
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND constraint_name = 'PRIMARY'
ORDER BY table_name, ordinal_position
SQL);
            $primaryStatement->execute($physicalNames);
            $primaryByTable = $this->groupMySqlRows($primaryStatement->fetchAll(), $expectedNames);

            $foreignKeyStatement = $pdo->prepare(<<<SQL
SELECT k.table_name, k.column_name, k.referenced_table_name,
       k.referenced_column_name, r.delete_rule, r.update_rule
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
            $foreignKeysByTable = $this->groupMySqlRows($foreignKeyStatement->fetchAll(), $expectedNames);

            $indexStatement = $pdo->prepare(<<<SQL
SELECT table_name, index_name, non_unique, column_name, seq_in_index, index_type
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name IN ({$placeholders})
  AND index_name <> 'PRIMARY'
ORDER BY table_name, index_name, seq_in_index
SQL);
            $indexStatement->execute($physicalNames);
            $indexesByTable = $this->groupMySqlRows($indexStatement->fetchAll(), $expectedNames);
        }

        $tables = [];
        foreach ($logicalNames as $physicalName => $logicalName) {
            $columns = [];
            $primary = [];
            foreach (($columnsByTable[$physicalName] ?? []) as $column) {
                $name = (string) $column['column_name'];
                $columns[$name] = [
                    'name' => $name,
                    'type' => strtoupper((string) $column['column_type']),
                    'nullable' => strtoupper((string) $column['is_nullable']) === 'YES',
                    'default' => $column['column_default'],
                    'primary_position' => 0,
                    'auto_increment' => str_contains(strtolower((string) $column['extra']), 'auto_increment'),
                ];
            }
            foreach (($primaryByTable[$physicalName] ?? []) as $column) {
                $position = (int) $column['ordinal_position'];
                $name = (string) $column['column_name'];
                $primary[$position] = $name;
                if (isset($columns[$name])) {
                    $columns[$name]['primary_position'] = $position;
                }
            }
            ksort($primary);

            $foreignKeys = [];
            foreach (($foreignKeysByTable[$physicalName] ?? []) as $foreignKey) {
                $referenced = $this->logicalName((string) $foreignKey['referenced_table_name'], $prefix);
                if ($referenced === null) {
                    continue;
                }
                $foreignKeys[] = [
                    'column' => (string) $foreignKey['column_name'],
                    'referenced_table' => $referenced,
                    'referenced_column' => (string) $foreignKey['referenced_column_name'],
                    'on_delete' => strtoupper((string) $foreignKey['delete_rule']),
                    'on_update' => strtoupper((string) $foreignKey['update_rule']),
                ];
            }

            $indexGroups = [];
            foreach (($indexesByTable[$physicalName] ?? []) as $index) {
                $name = (string) $index['index_name'];
                $indexGroups[$name]['name'] = $name;
                $indexGroups[$name]['unique'] = (int) $index['non_unique'] === 0;
                $indexGroups[$name]['type'] = strtoupper((string) $index['index_type']);
                $indexGroups[$name]['columns'][(int) $index['seq_in_index']] = (string) $index['column_name'];
            }
            $indexes = [];
            foreach ($indexGroups as $index) {
                ksort($index['columns']);
                $index['columns'] = array_values($index['columns']);
                $indexes[] = $index;
            }
            $tables[$logicalName] = [
                'logical_name' => $logicalName,
                'physical_name' => $physicalName,
                'columns' => $columns,
                'primary_key' => array_values($primary),
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes,
            ];
        }
        return new LogicalDatabaseSchema($tables);
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, true> $expectedNames
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupMySqlRows(array $rows, array $expectedNames): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new DatabaseAdapterException('MySQL schema metadata is invalid.', 'invalid_schema');
            }
            $tableName = $row['table_name'] ?? null;
            if (!is_string($tableName) || !isset($expectedNames[$tableName])) {
                throw new DatabaseAdapterException('MySQL schema metadata is out of scope.', 'invalid_schema');
            }
            unset($row['table_name']);
            $grouped[$tableName][] = $row;
        }
        return $grouped;
    }

    private function inspectPostgreSql(PDO $pdo): LogicalDatabaseSchema
    {
        $prefix = $this->prefix($pdo);
        $names = $pdo->query(<<<'SQL'
SELECT table_name
FROM information_schema.tables
WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
ORDER BY table_name
SQL)->fetchAll(PDO::FETCH_COLUMN);
        $tables = [];
        foreach ($names as $physicalName) {
            $physicalName = (string) $physicalName;
            $logicalName = $this->logicalName($physicalName, $prefix);
            if ($logicalName === null) {
                continue;
            }
            $columnsStatement = $pdo->prepare(<<<'SQL'
SELECT column_name, data_type, udt_name, is_nullable, column_default, identity_generation, ordinal_position
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = ?
ORDER BY ordinal_position
SQL);
            $columnsStatement->execute([$physicalName]);
            $columns = [];
            foreach ($columnsStatement->fetchAll() as $column) {
                $name = (string) $column['column_name'];
                $columns[$name] = [
                    'name' => $name,
                    'type' => strtoupper((string) ($column['data_type'] ?: $column['udt_name'])),
                    'nullable' => strtoupper((string) $column['is_nullable']) === 'YES',
                    'default' => $column['column_default'],
                    'primary_position' => 0,
                    'auto_increment' => (string) ($column['identity_generation'] ?? '') !== ''
                        || str_contains((string) ($column['column_default'] ?? ''), 'nextval('),
                ];
            }
            $primaryStatement = $pdo->prepare(<<<'SQL'
SELECT a.attname AS column_name, key_column.ordinality AS ordinal_position
FROM pg_index i
JOIN pg_class c ON c.oid = i.indrelid
JOIN pg_namespace n ON n.oid = c.relnamespace
JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS key_column(attnum, ordinality) ON true
JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = key_column.attnum
WHERE n.nspname = current_schema() AND c.relname = ? AND i.indisprimary
ORDER BY key_column.ordinality
SQL);
            $primaryStatement->execute([$physicalName]);
            $primary = [];
            foreach ($primaryStatement->fetchAll() as $column) {
                $position = (int) $column['ordinal_position'];
                $name = (string) $column['column_name'];
                $primary[$position] = $name;
                if (isset($columns[$name])) {
                    $columns[$name]['primary_position'] = $position;
                }
            }
            ksort($primary);

            $foreignKeyStatement = $pdo->prepare(<<<'SQL'
SELECT child_attribute.attname AS column_name,
       parent.relname AS referenced_table_name,
       parent_attribute.attname AS referenced_column_name,
       constraint_row.confdeltype AS delete_action,
       constraint_row.confupdtype AS update_action
FROM pg_constraint constraint_row
JOIN pg_class child ON child.oid = constraint_row.conrelid
JOIN pg_namespace child_namespace ON child_namespace.oid = child.relnamespace
JOIN pg_class parent ON parent.oid = constraint_row.confrelid
JOIN LATERAL unnest(constraint_row.conkey) WITH ORDINALITY AS child_key(attnum, position) ON true
JOIN LATERAL unnest(constraint_row.confkey) WITH ORDINALITY AS parent_key(attnum, position)
  ON parent_key.position = child_key.position
JOIN pg_attribute child_attribute ON child_attribute.attrelid = child.oid AND child_attribute.attnum = child_key.attnum
JOIN pg_attribute parent_attribute ON parent_attribute.attrelid = parent.oid AND parent_attribute.attnum = parent_key.attnum
WHERE constraint_row.contype = 'f'
  AND child_namespace.nspname = current_schema()
  AND child.relname = ?
ORDER BY constraint_row.conname, child_key.position
SQL);
            $foreignKeyStatement->execute([$physicalName]);
            $foreignKeys = [];
            foreach ($foreignKeyStatement->fetchAll() as $foreignKey) {
                $referenced = $this->logicalName((string) $foreignKey['referenced_table_name'], $prefix);
                if ($referenced === null) {
                    continue;
                }
                $foreignKeys[] = [
                    'column' => (string) $foreignKey['column_name'],
                    'referenced_table' => $referenced,
                    'referenced_column' => (string) $foreignKey['referenced_column_name'],
                    'on_delete' => $this->postgreSqlReferentialAction((string) $foreignKey['delete_action']),
                    'on_update' => $this->postgreSqlReferentialAction((string) $foreignKey['update_action']),
                ];
            }

            $indexStatement = $pdo->prepare(<<<'SQL'
SELECT index_relation.relname AS index_name,
       index_definition.indisunique AS is_unique,
       access_method.amname AS index_method,
       index_key.ordinality AS ordinal_position,
       table_attribute.attname AS column_name,
       collation_row.collname AS collation_name,
       CASE WHEN (COALESCE(index_key.options, 0) & 1) = 1 THEN 'DESC' ELSE 'ASC' END AS direction,
       index_key.ordinality > index_definition.indnkeyatts AS is_included,
       CASE
           WHEN index_key.attnum = 0
           THEN pg_get_indexdef(index_definition.indexrelid, index_key.ordinality::INTEGER, true)
           ELSE NULL
       END AS expression_sql,
       index_definition.indpred IS NOT NULL AS is_partial,
       pg_get_expr(index_definition.indpred, index_definition.indrelid, true) AS predicate_sql,
       unique_constraint.oid IS NOT NULL AS is_unique_constraint
FROM pg_index index_definition
JOIN pg_class table_relation ON table_relation.oid = index_definition.indrelid
JOIN pg_namespace table_namespace ON table_namespace.oid = table_relation.relnamespace
JOIN pg_class index_relation ON index_relation.oid = index_definition.indexrelid
JOIN pg_am access_method ON access_method.oid = index_relation.relam
JOIN LATERAL unnest(
    index_definition.indkey::SMALLINT[],
    index_definition.indcollation::OID[],
    index_definition.indoption::SMALLINT[]
) WITH ORDINALITY AS index_key(attnum, collation_oid, options, ordinality) ON true
LEFT JOIN pg_attribute table_attribute
  ON table_attribute.attrelid = table_relation.oid
 AND table_attribute.attnum = index_key.attnum
LEFT JOIN pg_collation collation_row ON collation_row.oid = index_key.collation_oid
LEFT JOIN pg_constraint unique_constraint
  ON unique_constraint.conindid = index_definition.indexrelid
 AND unique_constraint.contype = 'u'
WHERE table_namespace.nspname = current_schema()
  AND table_relation.relname = ?
  AND NOT index_definition.indisprimary
ORDER BY index_relation.relname, index_key.ordinality
SQL);
            $indexStatement->execute([$physicalName]);
            $indexGroups = [];
            foreach ($indexStatement->fetchAll() as $index) {
                $name = (string) ($index['index_name'] ?? '');
                if ($name === '') {
                    throw new DatabaseAdapterException(
                        'PostgreSQL index metadata is invalid.',
                        'invalid_schema'
                    );
                }
                if (!isset($indexGroups[$name])) {
                    $indexGroups[$name] = [
                        'name' => $name,
                        'unique' => $this->databaseBoolean($index['is_unique'] ?? false),
                        'origin' => $this->databaseBoolean($index['is_unique_constraint'] ?? false)
                            ? 'unique_constraint'
                            : 'explicit',
                        'type' => strtoupper((string) ($index['index_method'] ?? '')),
                        'columns' => [],
                        'column_details' => [],
                        'include_columns' => [],
                        'expressions' => [],
                        'partial' => $this->databaseBoolean($index['is_partial'] ?? false),
                        'predicate' => $index['predicate_sql'] === null
                            ? null
                            : trim((string) $index['predicate_sql']),
                    ];
                }
                $position = (int) ($index['ordinal_position'] ?? 0);
                if ($position < 1) {
                    throw new DatabaseAdapterException(
                        'PostgreSQL index column ordering is invalid.',
                        'invalid_schema'
                    );
                }
                $columnName = (string) ($index['column_name'] ?? '');
                $expression = trim((string) ($index['expression_sql'] ?? ''));
                $included = $this->databaseBoolean($index['is_included'] ?? false);
                if ($included) {
                    if ($columnName === '') {
                        throw new DatabaseAdapterException(
                            'PostgreSQL included index expression is unsupported.',
                            'invalid_schema'
                        );
                    }
                    $indexGroups[$name]['include_columns'][$position] = $columnName;
                    continue;
                }
                if ($columnName === '') {
                    if ($expression === '') {
                        throw new DatabaseAdapterException(
                            'PostgreSQL expression index metadata is invalid.',
                            'invalid_schema'
                        );
                    }
                    // Expression and partial indexes are represented explicitly
                    // so strict consumers can fail closed instead of silently
                    // treating them as ordinary column indexes.
                    $indexGroups[$name]['expressions'][$position] = $expression;
                    continue;
                }
                $indexGroups[$name]['columns'][$position] = $columnName;
                $indexGroups[$name]['column_details'][$position] = [
                    'name' => $columnName,
                    'collation' => (string) ($index['collation_name'] ?? ''),
                    'direction' => strtoupper((string) ($index['direction'] ?? 'ASC')),
                ];
            }
            $indexes = [];
            foreach ($indexGroups as $index) {
                foreach (['columns', 'column_details', 'include_columns', 'expressions'] as $orderedKey) {
                    ksort($index[$orderedKey], SORT_NUMERIC);
                    $index[$orderedKey] = array_values($index[$orderedKey]);
                }
                $indexes[] = $index;
            }
            $tables[$logicalName] = [
                'logical_name' => $logicalName,
                'physical_name' => $physicalName,
                'columns' => $columns,
                'primary_key' => array_values($primary),
                'foreign_keys' => $foreignKeys,
                'indexes' => $indexes,
            ];
        }
        return new LogicalDatabaseSchema($tables);
    }

    private function prefix(PDO $pdo): string
    {
        return method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
    }

    private function logicalName(string $physicalName, string $prefix): ?string
    {
        $name = strtolower($physicalName);
        if ($prefix !== '') {
            if (!str_starts_with($name, strtolower($prefix))) {
                return null;
            }
            $name = substr($name, strlen($prefix));
        }
        $coreTables = method_exists(PrefixedPDO::class, 'logicalTables') ? PrefixedPDO::logicalTables() : [];
        if (!in_array($name, $coreTables, true) && !str_starts_with($name, 'plugin_')) {
            return null;
        }
        return preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) === 1 ? $name : null;
    }

    private function quote(string $identifier, string $driver): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException('Database identifier is invalid.', 'invalid_schema');
        }
        return $driver === 'pgsql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
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

    private function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 't', 'true', 'yes', 'on'], true);
    }
}
