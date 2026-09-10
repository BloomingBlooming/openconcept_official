<?php

declare(strict_types=1);

final class MySqlDatabaseAdapter implements DatabaseAdapterInterface
{
    public function id(): string
    {
        return 'mysql';
    }

    public function driver(): string
    {
        return 'mysql';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function defaultTablePrefix(): string
    {
        return 'openconcept_';
    }

    public function normalizeConfig(array $config): array
    {
        $prefix = trim((string) ($config['prefix'] ?? $this->defaultTablePrefix()));
        $dsn = trim((string) ($config['dsn'] ?? ''));
        if ($dsn !== '') {
            if (!str_starts_with(strtolower($dsn), 'mysql:') || preg_match('/[\x00\r\n]/', $dsn) === 1) {
                throw new DatabaseAdapterException('MySQL DSN is invalid.', 'invalid_configuration');
            }
            return ['dsn' => $dsn, 'prefix' => $prefix];
        }
        $host = trim((string) ($config['host'] ?? ''));
        $database = trim((string) ($config['database'] ?? ''));
        $port = filter_var($config['port'] ?? $this->defaultPort(), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($host === '' || strlen($host) > 255 || preg_match('/[;=\x00-\x1F]/', $host) === 1
            || preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $database) !== 1 || $port === false) {
            throw new DatabaseAdapterException('MySQL connection settings are invalid.', 'invalid_configuration');
        }
        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'prefix' => $prefix,
            'charset' => 'utf8mb4',
        ];
    }

    public function connect(array $config, array $credentials = []): PrefixedPDO
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseAdapterException('PDO MySQL driver is not installed.', 'driver_missing');
        }
        $config = $this->normalizeConfig($config);
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        if ($username === '') {
            throw new DatabaseAdapterException('MySQL user ID is required.', 'invalid_configuration');
        }
        $dsn = (string) ($config['dsn'] ?? sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        ));
        try {
            $pdo = new PrefixedPDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_CASE => PDO::CASE_LOWER,
            ], (string) $config['prefix']);
            $this->ensureStrictSqlMode($pdo);
            return $pdo;
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = $this->classifyConnectionFailure($exception);
            throw new DatabaseAdapterException($failure['message'], $failure['code'], $exception);
        }
    }

    /** @return array{code: string, message: string} */
    private function classifyConnectionFailure(Throwable $exception): array
    {
        $sqlState = strtoupper(trim((string) $exception->getCode()));
        $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo ?? null)
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;
        $message = strtolower($exception->getMessage());
        if ($sqlState === '28000' || in_array($driverCode, [1045, 1698], true)) {
            return ['code' => 'authentication_failed', 'message' => 'MySQL authentication was rejected.'];
        }
        if ($driverCode === 1044) {
            return ['code' => 'permissions_insufficient', 'message' => 'The MySQL principal cannot access the configured database.'];
        }
        if ($sqlState === '42000' && str_contains($message, 'access denied')) {
            return ['code' => 'permissions_insufficient', 'message' => 'The MySQL principal lacks a required database permission.'];
        }
        if ($driverCode === 1049 || str_contains($message, 'unknown database')) {
            return ['code' => 'database_not_found', 'message' => 'The configured MySQL database does not exist.'];
        }
        if ($driverCode === 2026 || str_contains($message, 'ssl connection error')
            || str_contains($message, 'certificate')) {
            return ['code' => 'tls_negotiation_failed', 'message' => 'MySQL TLS negotiation or certificate verification failed.'];
        }
        if ($driverCode === 2005 || str_contains($message, 'name or service not known')
            || str_contains($message, 'getaddrinfo')) {
            return ['code' => 'dns_resolution_failed', 'message' => 'The MySQL host name could not be resolved.'];
        }
        if (in_array($driverCode, [2002, 2003, 2006, 2013], true)
            || str_contains($message, 'connection refused') || str_contains($message, 'timed out')
            || str_contains($message, 'no route to host')) {
            return ['code' => 'tcp_connection_failed', 'message' => 'The MySQL TCP endpoint is unreachable.'];
        }
        return ['code' => 'connection_failed', 'message' => 'Could not establish the MySQL connection.'];
    }

    public function diagnose(PDO $pdo): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DatabaseAdapterException('The connection is not MySQL.', 'driver_mismatch');
        }
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : $this->defaultTablePrefix();
        $suffix = substr(bin2hex(random_bytes(8)), 0, 12);
        $parent = substr($prefix . 'adapter_probe_parent_' . $suffix, 0, 63);
        $child = substr($prefix . 'adapter_probe_child_' . $suffix, 0, 63);
        $parentQuoted = $this->quote($parent);
        $childQuoted = $this->quote($child);
        try {
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $sqlMode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
            $strictWrites = $this->hasStrictSqlMode($sqlMode);
            if (!$strictWrites) {
                throw new DatabaseAdapterException(
                    'MySQL strict write validation is required.',
                    'environment_incompatible'
                );
            }
            $mariaDbVersion = $this->mariaDbVersion($version);
            if (stripos($version, 'mariadb') !== false
                && ($mariaDbVersion === null
                    || version_compare($mariaDbVersion, '10.4.0', '<')
                    || version_compare($mariaDbVersion, '10.5.0', '>='))) {
                throw new DatabaseAdapterException(
                    'MariaDB 10.4 is required.',
                    'environment_incompatible'
                );
            }
            if ($mariaDbVersion !== null
                && (int) $pdo->query('SELECT @@SESSION.check_constraint_checks')->fetchColumn() !== 1) {
                throw new DatabaseAdapterException(
                    'MariaDB CHECK constraint enforcement is disabled.',
                    'environment_incompatible'
                );
            }
            $charset = (string) $pdo->query('SELECT @@character_set_database')->fetchColumn();
            $collation = (string) $pdo->query('SELECT @@collation_database')->fetchColumn();
            $engines = $pdo->query('SHOW ENGINES')->fetchAll();
            $innodb = false;
            foreach ($engines as $engine) {
                if (strtolower((string) ($engine['engine'] ?? '')) === 'innodb'
                    && in_array(strtoupper((string) ($engine['support'] ?? '')), ['YES', 'DEFAULT'], true)) {
                    $innodb = true;
                }
            }
            if (!$innodb) {
                throw new DatabaseAdapterException('MySQL requires InnoDB.', 'environment_incompatible');
            }
            $pdo->exec("CREATE TABLE {$parentQuoted} (id BIGINT UNSIGNED NOT NULL PRIMARY KEY, payload JSON NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE {$childQuoted} (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, available_at DATETIME(6) NOT NULL, created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), CONSTRAINT "
                . $this->quote(substr($child . '_fk', 0, 63)) . " FOREIGN KEY (parent_id) REFERENCES {$parentQuoted}(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE INDEX " . $this->quote(substr($child . '_idx', 0, 63)) . " ON {$childQuoted}(parent_id, created_at)");
            $pdo->exec("ALTER TABLE {$childQuoted} ADD COLUMN note VARCHAR(32) NULL");
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO {$parentQuoted} (id, payload) VALUES (1, JSON_OBJECT('ok', TRUE))");
            $pdo->exec("INSERT INTO {$parentQuoted} (id, payload) VALUES (1, JSON_OBJECT('upsert', TRUE)) ON DUPLICATE KEY UPDATE payload = VALUES(payload)");
            $pdo->exec("INSERT INTO {$childQuoted} (parent_id, enabled, available_at) VALUES (1, 1, CURRENT_TIMESTAMP(6))");
            $pdo->exec("UPDATE {$childQuoted} SET note = 'checked' WHERE parent_id = 1");
            $pdo->exec("DELETE FROM {$childQuoted} WHERE parent_id = 1");
            $pdo->rollBack();
            if ((int) $pdo->query("SELECT COUNT(*) FROM {$parentQuoted}")->fetchColumn() !== 0) {
                throw new DatabaseAdapterException('MySQL transaction rollback check failed.', 'environment_incompatible');
            }
            $foreignKeyRejected = false;
            try {
                $pdo->exec("INSERT INTO {$childQuoted} (parent_id, enabled, available_at) VALUES (999, 1, CURRENT_TIMESTAMP(6))");
            } catch (PDOException) {
                $foreignKeyRejected = true;
            }
            if (!$foreignKeyRejected) {
                throw new DatabaseAdapterException('MySQL foreign-key enforcement is disabled.', 'environment_incompatible');
            }
            return [
                'connection' => 'ok',
                'driver' => 'pdo_mysql',
                'server_version' => $version,
                'server_flavor' => $mariaDbVersion === null ? 'mysql' : 'mariadb',
                'mariadb_version' => $mariaDbVersion,
                'character_set' => $charset,
                'collation' => $collation,
                'utf8mb4' => true,
                'innodb' => true,
                'transactions' => true,
                'strict_writes' => true,
                'foreign_keys' => true,
                'check_constraints' => true,
                'json' => true,
                'upsert' => true,
                'compatible' => true,
            ];
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new DatabaseAdapterException('MySQL capability check failed.', 'capability_check_failed', $exception);
        } finally {
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$childQuoted}");
                $pdo->exec("DROP TABLE IF EXISTS {$parentQuoted}");
            } catch (Throwable) {
                // The public error stays credential-free. A leftover uniquely
                // named probe table is safe for an administrator to remove.
            }
        }
    }

    public function provision(PDO $pdo, string $applicationRoot, LogicalDatabaseSchema $sourceSchema): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DatabaseAdapterException('MySQL schema requires a MySQL connection.', 'driver_mismatch');
        }
        $path = rtrim($applicationRoot, "/\\") . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'mysql.sql';
        $schema = file_get_contents($path);
        if (!is_string($schema)) {
            throw new DatabaseAdapterException('MySQL schema definition is unavailable.', 'schema_missing');
        }
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $schema = $this->projectCoreSchemaForServer($schema, $version);
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : $this->defaultTablePrefix();
        $schema = str_replace('openconcept_', $prefix, $schema);
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
        foreach ($sourceSchema->orderedTables() as $logicalName) {
            if (in_array($logicalName, PrefixedPDO::logicalTables(), true)) {
                continue;
            }
            $this->createPluginTable($pdo, $sourceSchema->table($logicalName), $prefix);
        }
    }

    private function mariaDbVersion(string $serverVersion): ?string
    {
        if (stripos($serverVersion, 'mariadb') === false) {
            return null;
        }
        return preg_match('/([0-9]+\.[0-9]+\.[0-9]+)-MariaDB/i', $serverVersion, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function ensureStrictSqlMode(PDO $pdo): void
    {
        $sqlMode = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!is_string($sqlMode)) {
            throw new DatabaseAdapterException(
                'MySQL SQL mode could not be inspected.',
                'environment_incompatible'
            );
        }
        if ($this->hasStrictSqlMode($sqlMode)) {
            return;
        }
        $modes = array_values(array_filter(array_map('trim', explode(',', $sqlMode))));
        foreach ($modes as $mode) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', $mode) !== 1) {
                throw new DatabaseAdapterException(
                    'MySQL SQL mode is unsupported.',
                    'environment_incompatible'
                );
            }
        }
        $modes[] = 'STRICT_TRANS_TABLES';
        $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote(implode(',', $modes)));
        $verified = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        if (!is_string($verified) || !$this->hasStrictSqlMode($verified)) {
            throw new DatabaseAdapterException(
                'MySQL strict write validation could not be enabled.',
                'environment_incompatible'
            );
        }
    }

    private function hasStrictSqlMode(string $sqlMode): bool
    {
        $modes = array_map(
            'strtoupper',
            array_filter(array_map('trim', explode(',', $sqlMode)))
        );
        return in_array('STRICT_TRANS_TABLES', $modes, true)
            || in_array('STRICT_ALL_TABLES', $modes, true);
    }

    private function projectCoreSchemaForServer(string $schema, string $serverVersion): string
    {
        $mariaDbVersion = $this->mariaDbVersion($serverVersion);
        if ($mariaDbVersion === null) {
            if (stripos($serverVersion, 'mariadb') !== false) {
                throw new DatabaseAdapterException(
                    'MariaDB version could not be determined.',
                    'environment_incompatible'
                );
            }
            return $schema;
        }
        if (version_compare($mariaDbVersion, '10.4.0', '<')
            || version_compare($mariaDbVersion, '10.5.0', '>=')) {
            throw new DatabaseAdapterException(
                'MariaDB 10.4 is required.',
                'environment_incompatible'
            );
        }

        $projections = [
            '    source_created_at TIMESTAMP(6) NOT NULL,'
                => '    source_created_at DATETIME(6) NOT NULL,',
            '    source_updated_at TIMESTAMP(6) NOT NULL,'
                => '    source_updated_at DATETIME(6) NOT NULL,',
            '    available_at TIMESTAMP(6) NOT NULL,'
                => '    available_at DATETIME(6) NOT NULL,',
        ];
        foreach ($projections as $mysqlDefinition => $mariaDbDefinition) {
            if (substr_count($schema, $mysqlDefinition) !== 1) {
                throw new DatabaseAdapterException(
                    'MariaDB schema projection no longer matches the canonical DDL.',
                    'schema_projection_failed'
                );
            }
            $schema = str_replace($mysqlDefinition, $mariaDbDefinition, $schema);
        }
        return $schema;
    }

    /** @param array<string, mixed> $table */
    private function createPluginTable(PDO $pdo, array $table, string $prefix): void
    {
        $physical = $prefix . (string) $table['logical_name'];
        $primary = array_values($table['primary_key'] ?? []);
        $indexedColumns = [];
        foreach ($table['indexes'] ?? [] as $index) {
            foreach ($index['columns'] ?? [] as $column) {
                $indexedColumns[(string) $column] = true;
            }
        }
        foreach ($table['foreign_keys'] ?? [] as $foreignKey) {
            $indexedColumns[(string) $foreignKey['column']] = true;
        }
        $definitions = [];
        $currentTimestampColumns = [];
        foreach ($table['columns'] as $column) {
            $name = (string) $column['name'];
            $type = strtoupper((string) $column['type']);
            $rawDefault = $column['default'] ?? null;
            $hasCurrentTimestampDefault = $this->currentTimestampPrecision($rawDefault) !== null;
            if ($hasCurrentTimestampDefault) {
                $currentTimestampColumns[$name] = [
                    'nullable' => (bool) ($column['nullable'] ?? true),
                    // Generation 4 recognized only the unqualified expression.
                    // Do not label an unrelated DATETIME(0) column as a state
                    // that the old adapter could have created.
                    'legacy_allowed' => is_string($rawDefault)
                        && preg_match('/^CURRENT_TIMESTAMP(?:\(\))?$/iD', trim($rawDefault)) === 1,
                ];
            }
            $isKey = in_array($name, $primary, true) || isset($indexedColumns[$name]);
            $mysqlType = match (true) {
                str_contains($type, 'INT') => 'BIGINT UNSIGNED',
                str_contains($type, 'REAL'), str_contains($type, 'FLOA'), str_contains($type, 'DOUB') => 'DOUBLE',
                str_contains($type, 'BLOB') => 'LONGBLOB',
                $hasCurrentTimestampDefault => 'DATETIME(6)',
                $isKey || $column['default'] !== null => 'VARCHAR(191)',
                default => 'LONGTEXT',
            };
            $definition = $this->quote($name) . ' ' . $mysqlType;
            if (($column['auto_increment'] ?? false) && count($primary) === 1) {
                $definition .= ' NOT NULL AUTO_INCREMENT';
            } elseif (!($column['nullable'] ?? true)) {
                $definition .= ' NOT NULL';
            } else {
                $definition .= ' NULL';
            }
            $default = $this->mysqlDefault($rawDefault, $mysqlType);
            if ($default !== null) {
                $definition .= ' DEFAULT ' . $default;
            }
            $definitions[] = $definition;
        }
        if ($primary !== []) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map($this->quote(...), $primary)) . ')';
        }
        foreach ($table['foreign_keys'] ?? [] as $position => $foreignKey) {
            $referenced = $prefix . (string) $foreignKey['referenced_table'];
            $definitions[] = 'CONSTRAINT ' . $this->quote($this->constraintName($physical, 'fk_' . $position))
                . ' FOREIGN KEY (' . $this->quote((string) $foreignKey['column']) . ') REFERENCES '
                . $this->quote($referenced) . '(' . $this->quote((string) $foreignKey['referenced_column']) . ')'
                . $this->referentialActions($foreignKey);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->quote($physical) . " (\n    "
            . implode(",\n    ", $definitions) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensurePluginCurrentTimestampPrecision($pdo, $physical, $currentTimestampColumns);
        foreach ($table['indexes'] ?? [] as $position => $index) {
            $columns = array_values($index['columns'] ?? []);
            if ($columns === [] || $columns === $primary) {
                continue;
            }
            $name = $this->constraintName($physical, 'idx_' . $position);
            $sql = 'CREATE ' . (($index['unique'] ?? false) ? 'UNIQUE ' : '') . 'INDEX '
                . $this->quote($name) . ' ON ' . $this->quote($physical) . ' ('
                . implode(', ', array_map($this->quote(...), $columns)) . ')';
            try {
                $pdo->exec($sql);
            } catch (PDOException $exception) {
                $duplicateIndex = (string) $exception->getCode() === '42000'
                    && str_contains(strtolower($exception->getMessage()), 'duplicate key name');
                if (!$duplicateIndex) {
                    throw $exception;
                }
            }
        }
    }

    private function mysqlDefault(mixed $default, string $type): ?string
    {
        if ($default === null || in_array($type, ['LONGTEXT', 'LONGBLOB'], true)) {
            return null;
        }
        $value = trim((string) $default);
        if ($this->currentTimestampPrecision($value) !== null) {
            return 'CURRENT_TIMESTAMP(6)';
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            return strtoupper($value);
        }
        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return "'" . str_replace("'", "''", substr($value, 1, -1)) . "'";
        }
        return null;
    }

    private function currentTimestampPrecision(mixed $default): ?int
    {
        if (!is_string($default)
            || preg_match(
                '/^CURRENT_TIMESTAMP(?:\(([0-6]?)\))?$/iD',
                trim($default),
                $matches
            ) !== 1) {
            return null;
        }
        return isset($matches[1]) && $matches[1] !== '' ? (int) $matches[1] : 0;
    }

    /**
     * A managed retry may reuse tables created by the generation-4 adapter.
     * CREATE IF NOT EXISTS cannot widen those columns, so classify the live
     * definition before copying data. One ALTER covers every affected column
     * in the table; MySQL consequently persists either the complete old table
     * or the complete target table across a process/server failure.
     *
     * @param array<string,array{nullable:bool,legacy_allowed:bool}> $expected
     */
    private function ensurePluginCurrentTimestampPrecision(
        PDO $pdo,
        string $physicalTable,
        array $expected
    ): void {
        if ($expected === []) {
            return;
        }

        $states = $this->pluginCurrentTimestampStates($pdo, $physicalTable, $expected);
        $old = array_keys(array_filter($states, static fn (string $state): bool => $state === 'old'));
        $target = array_keys(array_filter($states, static fn (string $state): bool => $state === 'target'));
        if (count($target) === count($expected)) {
            return;
        }
        if (count($old) !== count($expected) || $target !== []) {
            throw new DatabaseAdapterException(
                'A MySQL plugin timestamp column does not match an exact supported adapter generation.',
                'plugin_temporal_schema_mismatch'
            );
        }

        $modifications = [];
        foreach ($expected as $column => $definition) {
            $modifications[] = 'MODIFY COLUMN ' . $this->quote($column) . ' DATETIME(6) '
                . ($definition['nullable'] ? 'NULL' : 'NOT NULL')
                . ' DEFAULT CURRENT_TIMESTAMP(6)';
        }
        try {
            $pdo->exec(
                'ALTER TABLE ' . $this->quote($physicalTable) . ' ' . implode(', ', $modifications)
            );
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The MySQL plugin timestamp precision upgrade failed.',
                'plugin_temporal_upgrade_failed',
                $exception
            );
        }

        $verified = $this->pluginCurrentTimestampStates($pdo, $physicalTable, $expected);
        if (count(array_filter($verified, static fn (string $state): bool => $state === 'target'))
            !== count($expected)) {
            throw new DatabaseAdapterException(
                'The MySQL plugin timestamp precision upgrade could not be verified.',
                'plugin_temporal_upgrade_verification_failed'
            );
        }
    }

    /**
     * @param array<string,array{nullable:bool,legacy_allowed:bool}> $expected
     * @return array<string,'old'|'target'|'unknown'>
     */
    private function pluginCurrentTimestampStates(
        PDO $pdo,
        string $physicalTable,
        array $expected
    ): array {
        $mariaDb = $this->mariaDbVersion((string) $pdo->query('SELECT VERSION()')->fetchColumn()) !== null;
        $inspect = $pdo->prepare(<<<'SQL'
SELECT data_type, column_type, is_nullable, column_default, datetime_precision,
       extra, generation_expression, character_set_name, collation_name, column_comment
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
SQL);
        $states = [];
        foreach ($expected as $column => $definition) {
            $inspect->execute([$physicalTable, $column]);
            $rows = $inspect->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== 1 || !is_array($rows[0])) {
                $states[$column] = 'unknown';
                continue;
            }
            $states[$column] = $this->pluginCurrentTimestampState(
                array_change_key_case($rows[0], CASE_LOWER),
                $definition,
                $mariaDb
            );
        }
        return $states;
    }

    /**
     * @param array<string,mixed> $actual
     * @param array{nullable:bool,legacy_allowed:bool} $expected
     * @return 'old'|'target'|'unknown'
     */
    private function pluginCurrentTimestampState(
        array $actual,
        array $expected,
        bool $mariaDb = false
    ): string
    {
        $nullable = strtoupper((string) ($actual['is_nullable'] ?? ''));
        $extra = strtoupper(trim((string) ($actual['extra'] ?? '')));
        if (strtolower((string) ($actual['data_type'] ?? '')) !== 'datetime'
            || $nullable !== ($expected['nullable'] ? 'YES' : 'NO')
            || $extra !== ($mariaDb ? '' : 'DEFAULT_GENERATED')
            || trim((string) ($actual['generation_expression'] ?? '')) !== ''
            || ($actual['character_set_name'] ?? null) !== null
            || ($actual['collation_name'] ?? null) !== null
            || (string) ($actual['column_comment'] ?? '') !== '') {
            return 'unknown';
        }

        $columnType = strtolower(trim((string) ($actual['column_type'] ?? '')));
        $precision = filter_var($actual['datetime_precision'] ?? null, FILTER_VALIDATE_INT);
        $defaultPrecision = $this->currentTimestampPrecision($actual['column_default'] ?? null);
        if ($expected['legacy_allowed']
            && $columnType === 'datetime'
            && $precision === 0
            && $defaultPrecision === 0) {
            return 'old';
        }
        if ($columnType === 'datetime(6)'
            && $precision === 6
            && $defaultPrecision === 6) {
            return 'target';
        }
        return 'unknown';
    }

    /** @param array<string, mixed> $foreignKey */
    private function referentialActions(array $foreignKey): string
    {
        $sql = '';
        foreach (['on_delete' => ' ON DELETE ', 'on_update' => ' ON UPDATE '] as $key => $keyword) {
            $action = strtoupper((string) ($foreignKey[$key] ?? 'NO ACTION'));
            if (in_array($action, ['CASCADE', 'SET NULL', 'RESTRICT', 'NO ACTION'], true)) {
                $sql .= $keyword . $action;
            }
        }
        return $sql;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException('MySQL identifier is invalid.', 'invalid_schema');
        }
        return '`' . $identifier . '`';
    }

    private function constraintName(string $table, string $purpose): string
    {
        return substr($table . '_' . $purpose, 0, 54) . '_' . substr(hash('sha256', $table . ':' . $purpose), 0, 8);
    }
}
