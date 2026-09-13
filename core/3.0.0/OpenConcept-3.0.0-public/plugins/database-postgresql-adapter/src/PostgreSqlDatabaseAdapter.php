<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/app/CoreSchemaPostgreSqlIndexes.php';

final class PostgreSqlDatabaseAdapter implements
    DatabaseAdapterInterface,
    DatabaseCapabilityProviderInterface,
    DatabaseSetupCapabilityProvisionerInterface
{
    private const CORE_PREFIX_TOKEN = '{{OPENCONCEPT_PREFIX}}';

    public function id(): string
    {
        return 'postgresql';
    }

    public function driver(): string
    {
        return 'pgsql';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function defaultTablePrefix(): string
    {
        return 'openconcept_';
    }

    public function capabilities(PDO $pdo): DatabaseCapabilitiesInterface
    {
        return new PostgreSqlDatabaseCapabilities($pdo);
    }

    public function normalizeConfig(array $config): array
    {
        $prefix = trim((string) ($config['prefix'] ?? $this->defaultTablePrefix()));
        $requirePgvector = $config['require_pgvector'] ?? true;
        if (!is_bool($requirePgvector)) {
            throw new DatabaseAdapterException(
                'The PostgreSQL pgvector requirement must be a boolean.',
                'invalid_configuration'
            );
        }
        $dsn = trim((string) ($config['dsn'] ?? ''));
        if ($dsn !== '') {
            if (!str_starts_with(strtolower($dsn), 'pgsql:') || preg_match('/[\x00\r\n]/', $dsn) === 1) {
                throw new DatabaseAdapterException('PostgreSQL DSN is invalid.', 'invalid_configuration');
            }
            return ['dsn' => $dsn, 'prefix' => $prefix, 'require_pgvector' => $requirePgvector];
        }
        $host = trim((string) ($config['host'] ?? ''));
        $database = trim((string) ($config['database'] ?? ''));
        $sslMode = strtolower(trim((string) ($config['sslmode'] ?? 'prefer')));
        $connectTimeout = filter_var($config['connect_timeout'] ?? 5, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 30],
        ]);
        $port = filter_var($config['port'] ?? $this->defaultPort(), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($host === '' || strlen($host) > 255 || preg_match('/[;=\x00-\x1F]/', $host) === 1
            || preg_match('/^[A-Za-z0-9_$-]{1,63}$/D', $database) !== 1 || $port === false
            || $connectTimeout === false
            || !in_array($sslMode, ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new DatabaseAdapterException('PostgreSQL connection settings are invalid.', 'invalid_configuration');
        }
        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $database,
            'prefix' => $prefix,
            'sslmode' => $sslMode,
            'connect_timeout' => (int) $connectTimeout,
            'require_pgvector' => $requirePgvector,
        ];
    }

    public function connect(array $config, array $credentials = []): PrefixedPDO
    {
        if (!in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new DatabaseAdapterException('PDO PostgreSQL driver is not installed.', 'driver_missing');
        }
        $config = $this->normalizeConfig($config);
        $username = (string) ($credentials['username'] ?? '');
        $password = (string) ($credentials['password'] ?? '');
        if ($username === '') {
            throw new DatabaseAdapterException('PostgreSQL user ID is required.', 'invalid_configuration');
        }
        if (!isset($config['dsn'])) {
            $this->assertHostResolves((string) $config['host']);
            $this->assertTcpReachable((string) $config['host'], (int) $config['port'], (int) $config['connect_timeout']);
        }
        $dsn = (string) ($config['dsn'] ?? sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d;application_name=OpenConcept',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['sslmode'],
            $config['connect_timeout']
        ));
        try {
            return new PrefixedPDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_CASE => PDO::CASE_LOWER,
            ], (string) $config['prefix']);
        } catch (Throwable $exception) {
            $failure = $this->classifyConnectionFailure($exception);
            throw new DatabaseAdapterException($failure['message'], $failure['code'], $exception);
        }
    }

    public function diagnose(PDO $pdo): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new DatabaseAdapterException('The connection is not PostgreSQL.', 'driver_mismatch');
        }
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : $this->defaultTablePrefix();
        $suffix = substr(bin2hex(random_bytes(8)), 0, 12);
        $parent = substr($prefix . 'adapter_probe_parent_' . $suffix, 0, 63);
        $child = substr($prefix . 'adapter_probe_child_' . $suffix, 0, 63);
        $parentQuoted = $this->quote($parent);
        $childQuoted = $this->quote($child);
        try {
            $version = (string) $pdo->query("SELECT current_setting('server_version')")->fetchColumn();
            $encoding = (string) $pdo->query("SELECT current_setting('server_encoding')")->fetchColumn();
            $tlsValue = $pdo->query(
                'SELECT COALESCE((SELECT ssl FROM pg_stat_ssl WHERE pid = pg_backend_pid()), false)'
            )->fetchColumn();
            $tlsActive = $tlsValue === true || in_array(strtolower((string) $tlsValue), ['1', 't', 'true', 'yes', 'on'], true);
            if (strtoupper($encoding) !== 'UTF8') {
                throw new DatabaseAdapterException('PostgreSQL server encoding must be UTF8.', 'environment_incompatible');
            }
            $pdo->exec("CREATE TABLE {$parentQuoted} (id BIGINT PRIMARY KEY, payload JSONB NOT NULL, external_id UUID NOT NULL)");
            $pdo->exec("CREATE TABLE {$childQuoted} (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, parent_id BIGINT NOT NULL, enabled SMALLINT NOT NULL DEFAULT 1, created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP, CONSTRAINT "
                . $this->quote($this->name($child, 'fk')) . " FOREIGN KEY (parent_id) REFERENCES {$parentQuoted}(id) DEFERRABLE INITIALLY DEFERRED)");
            $pdo->exec('CREATE INDEX ' . $this->quote($this->name($child, 'idx')) . " ON {$childQuoted}(parent_id, created_at)");
            $pdo->exec("ALTER TABLE {$childQuoted} ADD COLUMN note VARCHAR(32) NULL");
            $pdo->beginTransaction();
            $pdo->exec("INSERT INTO {$parentQuoted} (id, payload, external_id) VALUES (1, '{\"ok\":true}'::jsonb, '00000000-0000-0000-0000-000000000001'::uuid) ON CONFLICT (id) DO UPDATE SET payload = EXCLUDED.payload");
            $pdo->exec("INSERT INTO {$childQuoted} (parent_id, enabled) VALUES (1, 1)");
            $pdo->exec("UPDATE {$childQuoted} SET note = 'checked' WHERE parent_id = 1");
            $pdo->exec("DELETE FROM {$childQuoted} WHERE parent_id = 1");
            $pdo->rollBack();
            if ((int) $pdo->query("SELECT COUNT(*) FROM {$parentQuoted}")->fetchColumn() !== 0) {
                throw new DatabaseAdapterException('PostgreSQL transaction rollback check failed.', 'environment_incompatible');
            }
            $foreignKeyRejected = false;
            $pdo->beginTransaction();
            try {
                $pdo->exec("INSERT INTO {$childQuoted} (parent_id, enabled) VALUES (999, 1)");
                $pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
            } catch (PDOException) {
                $foreignKeyRejected = true;
            } finally {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
            if (!$foreignKeyRejected) {
                throw new DatabaseAdapterException('PostgreSQL foreign-key enforcement is disabled.', 'environment_incompatible');
            }
            $availableExtensions = $pdo->query(
                "SELECT name FROM pg_available_extensions WHERE name IN ('vector', 'pgcrypto') ORDER BY name"
            )->fetchAll(PDO::FETCH_COLUMN);
            $capabilities = $this->capabilities($pdo);
            $installedExtensions = [];
            foreach (['pgcrypto', 'vector'] as $extension) {
                $extensionVersion = $capabilities->extensionVersion($extension);
                if ($extensionVersion !== null) {
                    $installedExtensions[$extension] = $extensionVersion;
                }
            }
            $pgvectorVersion = $installedExtensions['vector'] ?? null;
            $pgvectorAvailable = $pgvectorVersion !== null
                || in_array('vector', $availableExtensions, true);
            return [
                'connection' => 'ok',
                'driver' => 'pdo_pgsql',
                'server_version' => $version,
                'database' => (string) $pdo->query('SELECT current_database()')->fetchColumn(),
                'character_encoding' => $encoding,
                'schema' => (string) $pdo->query('SELECT current_schema()')->fetchColumn(),
                'tls_active' => $tlsActive,
                'transactions' => true,
                'foreign_keys' => true,
                'jsonb' => true,
                'uuid' => true,
                'timestamp' => true,
                'upsert' => true,
                'available_optional_extensions' => array_values(array_map('strval', $availableExtensions)),
                'installed_optional_extensions' => $installedExtensions,
                'vector_search' => $pgvectorVersion !== null,
                'pgvector_status' => $pgvectorVersion !== null
                    ? 'installed'
                    : ($pgvectorAvailable ? 'available' : 'missing'),
                'pgvector_version' => $pgvectorVersion,
                'compatible' => true,
            ];
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new DatabaseAdapterException('PostgreSQL capability check failed.', 'capability_check_failed', $exception);
        } finally {
            try {
                $pdo->exec("DROP TABLE IF EXISTS {$childQuoted}");
                $pdo->exec("DROP TABLE IF EXISTS {$parentQuoted}");
            } catch (Throwable) {
            }
        }
    }

    public function prepareSetupCapabilities(PDO $pdo, array $config, array $diagnostics): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new DatabaseAdapterException(
                'PostgreSQL capability preparation requires a PostgreSQL connection.',
                'driver_mismatch'
            );
        }
        $required = $config['require_pgvector'] ?? true;
        if (!is_bool($required)) {
            throw new DatabaseAdapterException(
                'The PostgreSQL pgvector requirement must be a boolean.',
                'invalid_configuration'
            );
        }
        if (!$required) {
            return $diagnostics;
        }

        $status = $this->pgvectorDiagnosticStatus($diagnostics);
        if ($status === 'installed') {
            return $diagnostics;
        }
        if ($status === 'missing') {
            throw new DatabaseAdapterException(
                'The PostgreSQL server does not provide the pgvector extension package.',
                'pgvector_package_missing',
                null,
                $this->pgvectorFailureDetails('missing', false, false)
            );
        }

        try {
            $pdo->exec('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (Throwable $exception) {
            $classification = $this->classifyPgvectorActivationFailure($exception);
            throw new DatabaseAdapterException(
                $classification['message'],
                $classification['code'],
                $exception,
                $this->pgvectorFailureDetails(
                    $classification['code'] === 'pgvector_package_missing' ? 'missing' : 'available',
                    $classification['code'] !== 'pgvector_package_missing',
                    false
                )
            );
        }

        $version = $this->capabilities($pdo)->extensionVersion('vector');
        if ($version === null) {
            throw new DatabaseAdapterException(
                'PostgreSQL did not report pgvector as installed after activation.',
                'pgvector_activation_failed',
                null,
                $this->pgvectorFailureDetails('available', true, false)
            );
        }
        $installed = is_array($diagnostics['installed_optional_extensions'] ?? null)
            ? $diagnostics['installed_optional_extensions']
            : [];
        $installed['vector'] = $version;
        $available = is_array($diagnostics['available_optional_extensions'] ?? null)
            ? array_values(array_map('strval', $diagnostics['available_optional_extensions']))
            : [];
        if (!in_array('vector', $available, true)) {
            $available[] = 'vector';
            sort($available, SORT_STRING);
        }
        $diagnostics['available_optional_extensions'] = $available;
        $diagnostics['installed_optional_extensions'] = $installed;
        $diagnostics['vector_search'] = true;
        $diagnostics['pgvector_status'] = 'installed';
        $diagnostics['pgvector_version'] = $version;
        $diagnostics['pgvector_activation'] = 'enabled';
        return $diagnostics;
    }

    /** @param array<string, mixed> $diagnostics */
    private function pgvectorDiagnosticStatus(array $diagnostics): string
    {
        $status = strtolower(trim((string) ($diagnostics['pgvector_status'] ?? '')));
        if (in_array($status, ['installed', 'available', 'missing'], true)) {
            return $status;
        }
        $installed = is_array($diagnostics['installed_optional_extensions'] ?? null)
            ? $diagnostics['installed_optional_extensions']
            : [];
        if (isset($installed['vector']) && trim((string) $installed['vector']) !== '') {
            return 'installed';
        }
        $available = is_array($diagnostics['available_optional_extensions'] ?? null)
            ? array_map('strval', $diagnostics['available_optional_extensions'])
            : [];
        return in_array('vector', $available, true) ? 'available' : 'missing';
    }

    /** @return array{code: string, message: string} */
    private function classifyPgvectorActivationFailure(Throwable $exception): array
    {
        $sqlState = strtoupper(trim((string) $exception->getCode()));
        if ($exception instanceof PDOException
            && is_array($exception->errorInfo ?? null)
            && is_string($exception->errorInfo[0] ?? null)) {
            $sqlState = strtoupper(trim((string) $exception->errorInfo[0]));
        }
        $message = strtolower($exception->getMessage());
        if ($sqlState === '42501'
            || str_contains($message, 'permission denied')
            || str_contains($message, 'must be superuser')
            || str_contains($message, 'not permitted to create extension')) {
            return [
                'code' => 'pgvector_permission_denied',
                'message' => 'The PostgreSQL principal is not permitted to enable pgvector.',
            ];
        }
        if ($sqlState === '0A000'
            || str_contains($message, 'extension "vector" is not available')
            || str_contains($message, 'could not open extension control file')) {
            return [
                'code' => 'pgvector_package_missing',
                'message' => 'The PostgreSQL server does not provide the pgvector extension package.',
            ];
        }
        return [
            'code' => 'pgvector_activation_failed',
            'message' => 'PostgreSQL could not enable the pgvector extension.',
        ];
    }

    /** @return array{capability: string, status: string, required: bool, available: bool, installed: bool} */
    private function pgvectorFailureDetails(string $status, bool $available, bool $installed): array
    {
        return [
            'capability' => 'pgvector',
            'status' => $status,
            'required' => true,
            'available' => $available,
            'installed' => $installed,
        ];
    }

    private function assertHostResolves(string $host): void
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false || strtolower($literal) === 'localhost') {
            return;
        }
        $resolved = @gethostbynamel($literal);
        if (is_array($resolved) && $resolved !== []) {
            return;
        }
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($literal, DNS_AAAA);
            if (is_array($records) && $records !== []) {
                return;
            }
        }
        throw new DatabaseAdapterException(
            'The PostgreSQL host name could not be resolved.',
            'dns_resolution_failed'
        );
    }

    private function assertTcpReachable(string $host, int $port, int $timeout): void
    {
        $literal = trim($host, '[]');
        $targetHost = filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '[' . $literal . ']'
            : $literal;
        $socket = @stream_socket_client(
            'tcp://' . $targetHost . ':' . $port,
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new DatabaseAdapterException(
                'The PostgreSQL TCP endpoint is unreachable.',
                'tcp_connection_failed'
            );
        }
        fclose($socket);
    }

    /** @return array{code: string, message: string} */
    private function classifyConnectionFailure(Throwable $exception): array
    {
        $sqlState = strtoupper(trim((string) $exception->getCode()));
        $message = strtolower($exception->getMessage());
        if (str_starts_with($sqlState, '28') || str_contains($message, 'password authentication failed')
            || str_contains($message, 'no pg_hba.conf entry')) {
            return ['code' => 'authentication_failed', 'message' => 'PostgreSQL authentication was rejected.'];
        }
        if ($sqlState === '42501' || str_contains($message, 'permission denied for database')) {
            return ['code' => 'permissions_insufficient', 'message' => 'The PostgreSQL principal cannot access the configured database.'];
        }
        if ($sqlState === '3D000' || str_contains($message, 'database') && str_contains($message, 'does not exist')) {
            return ['code' => 'database_not_found', 'message' => 'The configured PostgreSQL database does not exist.'];
        }
        if (str_contains($message, 'ssl') || str_contains($message, 'tls') || str_contains($message, 'certificate')) {
            return ['code' => 'tls_negotiation_failed', 'message' => 'PostgreSQL TLS negotiation or certificate verification failed.'];
        }
        if (str_contains($message, 'could not translate host name') || str_contains($message, 'name or service not known')) {
            return ['code' => 'dns_resolution_failed', 'message' => 'The PostgreSQL host name could not be resolved.'];
        }
        if (str_contains($message, 'connection refused') || str_contains($message, 'timeout expired')
            || str_contains($message, 'no route to host') || str_contains($message, 'network is unreachable')) {
            return ['code' => 'tcp_connection_failed', 'message' => 'The PostgreSQL TCP endpoint is unreachable.'];
        }
        return ['code' => 'connection_failed', 'message' => 'Could not establish the PostgreSQL connection.'];
    }

    public function provision(PDO $pdo, string $applicationRoot, LogicalDatabaseSchema $sourceSchema): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new DatabaseAdapterException('PostgreSQL schema requires a PostgreSQL connection.', 'driver_mismatch');
        }
        $prefix = $this->provisionCanonicalCore($pdo, $applicationRoot);

        foreach ($sourceSchema->orderedTables() as $logicalName) {
            if (in_array($logicalName, PrefixedPDO::logicalTables(), true)) {
                continue;
            }
            if (preg_match('/^plugin_[a-z0-9_]+$/D', $logicalName) !== 1) {
                throw new DatabaseAdapterException(
                    'PostgreSQL provisioning received an unowned non-plugin relation.',
                    'canonical_schema_mismatch'
                );
            }
            $this->createPluginTable($pdo, $sourceSchema->table($logicalName), $prefix);
        }
    }

    /**
     * Applies the same immutable Core DDL used by runtime provisioning.
     * Catalog generation calls this entrypoint so its PostgreSQL profile can
     * never be produced from a different schema source.
     */
    public function provisionCanonicalCore(PDO $pdo, string $applicationRoot): string
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new DatabaseAdapterException('PostgreSQL schema requires a PostgreSQL connection.', 'driver_mismatch');
        }
        $prefix = method_exists($pdo, 'tablePrefix')
            ? (string) $pdo->tablePrefix()
            : $this->defaultTablePrefix();
        $this->provisionCoreSchema($pdo, $applicationRoot, $prefix);
        return $prefix;
    }

    private function provisionCoreSchema(PDO $pdo, string $applicationRoot, string $prefix): void
    {
        $path = rtrim($applicationRoot, "/\\") . DIRECTORY_SEPARATOR . 'database'
            . DIRECTORY_SEPARATOR . 'postgresql.sql';
        $template = file_get_contents($path);
        if (!is_string($template)) {
            throw new DatabaseAdapterException('PostgreSQL schema definition is unavailable.', 'schema_missing');
        }
        if ($prefix === '' || preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new DatabaseAdapterException('PostgreSQL table prefix is invalid.', 'invalid_configuration');
        }
        if (!str_contains($template, self::CORE_PREFIX_TOKEN)
            || preg_match('/\{\{[A-Z][A-Z0-9_]*\}\}/D', str_replace(self::CORE_PREFIX_TOKEN, '', $template)) === 1) {
            throw new DatabaseAdapterException('PostgreSQL schema template is invalid.', 'canonical_schema_mismatch');
        }

        $replacementCount = 0;
        $rendered = preg_replace_callback(
            '/"\{\{OPENCONCEPT_PREFIX\}\}([a-z][a-z0-9_]*)"/D',
            static function (array $match) use ($prefix, &$replacementCount): string {
                $replacementCount++;
                return '"' . $prefix . (string) $match[1] . '"';
            },
            $template
        );
        if (!is_string($rendered)
            || $replacementCount === 0
            || str_contains($rendered, self::CORE_PREFIX_TOKEN)) {
            throw new DatabaseAdapterException(
                'PostgreSQL schema template uses its prefix token outside a quoted identifier.',
                'canonical_schema_mismatch'
            );
        }

        $expected = [];
        foreach (PrefixedPDO::logicalTables() as $logicalName) {
            $physicalName = $prefix . $logicalName;
            if (strlen($physicalName) > 63) {
                throw new DatabaseAdapterException(
                    'PostgreSQL table prefix is too long for the Core schema.',
                    'invalid_configuration'
                );
            }
            $expected[$physicalName] = true;
        }

        $seen = [];
        $knownExpressionIndexes = [
            $prefix . 'i_052' => [
                'table' => $prefix . 'pages',
                'expression' => CoreSchemaPostgreSqlIndexes::expressionSql(
                    CoreSchemaPostgreSqlIndexes::PAGES_SEARCH_EXPRESSION
                ),
            ],
            $prefix . 'i_054' => [
                'table' => $prefix . 'rag_chunks',
                'expression' => CoreSchemaPostgreSqlIndexes::expressionSql(
                    CoreSchemaPostgreSqlIndexes::RAG_CHUNKS_SEARCH_EXPRESSION
                ),
            ],
        ];
        $statements = $this->splitSqlStatements($rendered);
        foreach ($statements as $statement) {
            if (preg_match('/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+"([a-z][a-z0-9_]*)"\s*\(/iD', $statement, $match) === 1) {
                $physicalName = strtolower((string) $match[1]);
                if (!isset($expected[$physicalName]) || isset($seen[$physicalName])) {
                    throw new DatabaseAdapterException(
                        'PostgreSQL schema template contains an unexpected Core table.',
                        'canonical_schema_mismatch'
                    );
                }
                $seen[$physicalName] = true;
            } elseif (preg_match(
                '/^CREATE\s+INDEX\s+IF\s+NOT\s+EXISTS\s+"([a-z][a-z0-9_]*)"\s+ON\s+"([a-z][a-z0-9_]*)"\s+USING\s+GIN\s*\(([\s\S]+)\)$/iD',
                $statement,
                $match
            ) === 1) {
                $indexName = strtolower((string) $match[1]);
                $physicalName = strtolower((string) $match[2]);
                $definition = $knownExpressionIndexes[$indexName] ?? null;
                $actualExpression = preg_replace('/\s+/', ' ', trim((string) $match[3])) ?? '';
                $expectedExpression = is_array($definition)
                    ? (preg_replace('/\s+/', ' ', trim((string) $definition['expression'])) ?? '')
                    : '';
                if (!is_array($definition)
                    || $physicalName !== $definition['table']
                    || !hash_equals($expectedExpression, $actualExpression)) {
                    throw new DatabaseAdapterException(
                        'PostgreSQL schema template contains an unsupported expression index.',
                        'canonical_schema_mismatch'
                    );
                }
            } elseif (preg_match(
                '/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+"[a-z][a-z0-9_]*"\s+ON\s+"([a-z][a-z0-9_]*)"\s*\(/iD',
                $statement,
                $match
            ) === 1) {
                if (!isset($expected[strtolower((string) $match[1])])) {
                    throw new DatabaseAdapterException(
                        'PostgreSQL schema template contains an unexpected Core index.',
                        'canonical_schema_mismatch'
                    );
                }
            } else {
                throw new DatabaseAdapterException(
                    'PostgreSQL schema template contains an unsupported statement.',
                    'canonical_schema_mismatch'
                );
            }
        }
        if (count($seen) !== count($expected)
            || array_diff_key($expected, $seen) !== []
            || array_diff_key($seen, $expected) !== []) {
            throw new DatabaseAdapterException(
                'PostgreSQL schema template does not contain the complete Core table set.',
                'canonical_schema_mismatch'
            );
        }
        // Validate the complete template and exact Core table set before the
        // first DDL write. A malformed later statement must never leave an
        // apparently usable partial canonical schema behind.
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    /** @return list<string> */
    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $state = 'normal';
        $dollarDelimiter = '';
        $blockDepth = 0;
        $length = strlen($sql);
        for ($position = 0; $position < $length; $position++) {
            $character = $sql[$position];
            $next = $position + 1 < $length ? $sql[$position + 1] : '';
            if ($state === 'line_comment') {
                if ($character === "\n") {
                    $buffer .= "\n";
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                if ($character === '/' && $next === '*') {
                    $blockDepth++;
                    $position++;
                } elseif ($character === '*' && $next === '/') {
                    $blockDepth--;
                    $position++;
                    if ($blockDepth === 0) {
                        $state = 'normal';
                    }
                } elseif ($character === "\n") {
                    $buffer .= "\n";
                }
                continue;
            }
            if ($state === 'dollar') {
                if (substr($sql, $position, strlen($dollarDelimiter)) === $dollarDelimiter) {
                    $buffer .= $dollarDelimiter;
                    $position += strlen($dollarDelimiter) - 1;
                    $state = 'normal';
                } else {
                    $buffer .= $character;
                }
                continue;
            }
            if ($state === 'single' || $state === 'double') {
                $quote = $state === 'single' ? "'" : '"';
                $buffer .= $character;
                if ($character === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $position++;
                    } else {
                        $state = 'normal';
                    }
                }
                continue;
            }

            if ($character === '-' && $next === '-') {
                $state = 'line_comment';
                $position++;
                continue;
            }
            if ($character === '/' && $next === '*') {
                $state = 'block_comment';
                $blockDepth = 1;
                $position++;
                continue;
            }
            if ($character === "'") {
                $state = 'single';
                $buffer .= $character;
                continue;
            }
            if ($character === '"') {
                $state = 'double';
                $buffer .= $character;
                continue;
            }
            if ($character === '$'
                && preg_match('/\G(\$[A-Za-z_][A-Za-z0-9_]*\$|\$\$)/', $sql, $match, 0, $position) === 1) {
                $dollarDelimiter = (string) $match[1];
                $buffer .= $dollarDelimiter;
                $position += strlen($dollarDelimiter) - 1;
                $state = 'dollar';
                continue;
            }
            if ($character === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $character;
        }
        if (!in_array($state, ['normal', 'line_comment'], true)) {
            throw new DatabaseAdapterException('PostgreSQL schema template is unterminated.', 'canonical_schema_mismatch');
        }
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        if ($statements === []) {
            throw new DatabaseAdapterException('PostgreSQL schema template is empty.', 'canonical_schema_mismatch');
        }
        return $statements;
    }

    /** @param array<string, mixed> $table */
    private function createPluginTable(PDO $pdo, array $table, string $prefix): void
    {
        $logicalName = (string) ($table['logical_name'] ?? '');
        if (preg_match('/^plugin_[a-z0-9_]+$/D', $logicalName) !== 1) {
            throw new DatabaseAdapterException('PostgreSQL plugin table name is invalid.', 'canonical_schema_mismatch');
        }
        $physical = $prefix . $logicalName;
        $primary = array_values($table['primary_key'] ?? []);
        $definitions = [];
        foreach ($table['columns'] as $column) {
            $name = (string) $column['name'];
            $definition = $this->quote($name) . ' ' . $this->postgresType((string) $column['type']);
            if (($column['auto_increment'] ?? false) && count($primary) === 1) {
                $definition .= ' GENERATED BY DEFAULT AS IDENTITY';
            }
            $definition .= ($column['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
            $default = $this->postgresDefault($column['default'] ?? null);
            if ($default !== null && !($column['auto_increment'] ?? false)) {
                $definition .= ' DEFAULT ' . $default;
            }
            $definitions[] = $definition;
        }
        if ($primary !== []) {
            $definitions[] = 'PRIMARY KEY (' . implode(', ', array_map($this->quote(...), $primary)) . ')';
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $this->quote($physical) . " (\n    "
            . implode(",\n    ", $definitions) . "\n)");

        foreach ($table['foreign_keys'] ?? [] as $position => $foreignKey) {
            $constraint = $this->name($physical, 'fk_' . $position);
            if ($this->constraintExists($pdo, $physical, $constraint)) {
                continue;
            }
            $referenced = $prefix . (string) $foreignKey['referenced_table'];
            $pdo->exec('ALTER TABLE ' . $this->quote($physical) . ' ADD CONSTRAINT ' . $this->quote($constraint)
                . ' FOREIGN KEY (' . $this->quote((string) $foreignKey['column']) . ') REFERENCES '
                . $this->quote($referenced) . '(' . $this->quote((string) $foreignKey['referenced_column']) . ')'
                . $this->referentialActions($foreignKey) . ' DEFERRABLE INITIALLY DEFERRED');
        }
        foreach ($table['indexes'] ?? [] as $position => $index) {
            $columns = array_values($index['columns'] ?? []);
            if ($columns === [] || $columns === $primary) {
                continue;
            }
            $name = $this->name($physical, 'idx_' . $position);
            if (strtoupper((string) ($index['type'] ?? '')) === 'FULLTEXT') {
                $document = implode(" || ' ' || ", array_map(
                    fn (string $column): string => "COALESCE(" . $this->quote($column) . "::text, '')",
                    $columns
                ));
                $pdo->exec('CREATE INDEX IF NOT EXISTS ' . $this->quote($name) . ' ON '
                    . $this->quote($physical) . " USING GIN (to_tsvector('simple', {$document}))");
                continue;
            }
            $columnSql = implode(', ', array_map($this->quote(...), $columns));
            $pdo->exec('CREATE ' . (($index['unique'] ?? false) ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS '
                . $this->quote($name) . ' ON ' . $this->quote($physical) . ' (' . $columnSql . ')');
        }
    }

    private function postgresType(string $sourceType): string
    {
        $type = strtoupper($sourceType);
        if (str_contains($type, 'BIGINT')) {
            return 'BIGINT';
        }
        if (str_contains($type, 'TINYINT') || str_contains($type, 'SMALLINT')) {
            return 'SMALLINT';
        }
        if (preg_match('/(?:MEDIUM)?INT/', $type) === 1 || $type === 'INTEGER') {
            return 'INTEGER';
        }
        if (preg_match('/(?:VAR)?CHAR\s*\((\d+)\)/', $type, $matches) === 1) {
            return 'VARCHAR(' . min(10485760, max(1, (int) $matches[1])) . ')';
        }
        if (str_contains($type, 'BLOB') || str_contains($type, 'BINARY')) {
            return 'BYTEA';
        }
        if (str_contains($type, 'JSON')) {
            return 'JSONB';
        }
        if (str_contains($type, 'TIMESTAMP') || str_contains($type, 'DATETIME')) {
            return 'TIMESTAMP WITHOUT TIME ZONE';
        }
        if (preg_match('/DECIMAL\s*\((\d+)\s*,\s*(\d+)\)/', $type, $matches) === 1) {
            return 'NUMERIC(' . (int) $matches[1] . ',' . (int) $matches[2] . ')';
        }
        if (str_contains($type, 'DOUBLE') || str_contains($type, 'FLOAT') || str_contains($type, 'REAL')) {
            return 'DOUBLE PRECISION';
        }
        if (str_contains($type, 'UUID')) {
            return 'UUID';
        }
        return 'TEXT';
    }

    private function postgresDefault(mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        $value = trim((string) $default);
        $value = preg_replace('/^\((.*)\)$/s', '$1', $value) ?? $value;
        if (preg_match('/^CURRENT_TIMESTAMP(?:\([0-6]?\))?$/iD', $value) === 1) {
            return 'CURRENT_TIMESTAMP';
        }
        if (preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $value) === 1) {
            return $value;
        }
        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return "'" . str_replace("'", "''", substr($value, 1, -1)) . "'";
        }
        if (preg_match('/[\x00-\x1F]/', $value) === 1) {
            return null;
        }
        return "'" . str_replace("'", "''", $value) . "'";
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

    private function constraintExists(PDO $pdo, string $table, string $constraint): bool
    {
        $statement = $pdo->prepare(<<<'SQL'
SELECT 1
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
JOIN pg_namespace n ON n.oid = t.relnamespace
WHERE n.nspname = current_schema() AND t.relname = ? AND c.conname = ?
LIMIT 1
SQL);
        $statement->execute([$table, $constraint]);
        return $statement->fetchColumn() !== false;
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException('PostgreSQL identifier is invalid.', 'invalid_schema');
        }
        return '"' . $identifier . '"';
    }

    private function name(string $table, string $purpose): string
    {
        return substr($table . '_' . $purpose, 0, 53) . '_' . substr(hash('sha256', $table . ':' . $purpose), 0, 8);
    }
}
