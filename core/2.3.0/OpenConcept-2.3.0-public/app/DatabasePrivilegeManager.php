<?php

declare(strict_types=1);

/**
 * Persists the database permission contract, verifies the active principal,
 * and prepares administrator-reviewed recovery SQL. It never executes GRANT.
 */
final class DatabasePrivilegeManager
{
    private const FORMAT_VERSION = 1;

    private string $directory;

    public function __construct(string $storagePath)
    {
        $this->directory = rtrim($storagePath, "/\\") . DIRECTORY_SEPARATOR . '.database-adapters';
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function verify(PDO $pdo, string $backend, array $config): array
    {
        $context = $this->context($pdo, $backend, $config);
        $definition = $this->definition($backend, $context);
        $definitionPath = $this->persistDefinition($backend, $definition);

        if ($backend === 'sqlite') {
            return $definition;
        }

        try {
            $missing = $backend === 'mysql'
                ? $this->missingMySqlPrivileges($pdo, $definition)
                : $this->missingPostgreSqlPrivileges($pdo, $definition);
        } catch (DatabaseAdapterException $exception) {
            if ($exception->failureCode() !== 'permissions_insufficient') {
                throw $exception;
            }
            $missing = $this->requiredPrivilegeLabels($definition);
        }
        if ($missing === []) {
            $this->recordResolved($backend, $definition);
            return $definition;
        }

        $recovery = $this->persistRecoverySql($backend, $definition, $missing);
        $details = [
            'diagnostic_category' => 'permissions_insufficient',
            'permission_definition' => $this->relativePath($definitionPath),
            'permission_definition_sha256' => (string) $definition['definition_sha256'],
            'recovery_sql' => $this->relativePath((string) $recovery['path']),
            'recovery_sql_sha256' => (string) $recovery['sha256'],
            'missing_privileges' => $missing,
            'automatic_grant' => false,
        ];
        throw new DatabaseAdapterException(
            'The database principal does not satisfy the saved OpenConcept permission definition.',
            'permissions_insufficient',
            null,
            $details
        );
    }

    /**
     * Builds a reviewable recovery artifact when authentication succeeded but
     * the server rejected access before a database session could be opened.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function prepareConnectionPermissionFailure(
        string $backend,
        array $config,
        string $username
    ): array {
        $database = trim((string) ($config['database'] ?? $this->dsnValue((string) ($config['dsn'] ?? ''), $backend === 'postgresql' ? 'dbname' : 'dbname')));
        if ($database === '') {
            throw new DatabaseAdapterException(
                'The recovery permission definition requires an explicit database name.',
                'permission_definition_unavailable'
            );
        }
        $context = [
            'database' => $database,
            'schema' => $backend === 'postgresql' ? 'public' : $database,
            'table_prefix' => (string) ($config['prefix'] ?? 'openconcept_'),
            'principal' => $backend === 'mysql' ? $username . '@<review-account-host>' : $username,
        ];
        $definition = $this->definition($backend, $context);
        $definitionPath = $this->persistDefinition($backend, $definition);
        $missing = [];
        foreach ((array) ($definition['requirements']['database'] ?? []) as $privilege) {
            $missing[] = 'database:' . strtoupper((string) $privilege);
        }
        if ($backend === 'postgresql') {
            foreach ((array) ($definition['requirements']['schema'] ?? []) as $privilege) {
                $missing[] = 'schema:' . strtoupper((string) $privilege);
            }
        }
        $recovery = $this->persistRecoverySql($backend, $definition, $missing);
        return [
            'diagnostic_category' => 'permissions_insufficient',
            'permission_definition' => $this->relativePath($definitionPath),
            'permission_definition_sha256' => (string) $definition['definition_sha256'],
            'recovery_sql' => $this->relativePath((string) $recovery['path']),
            'recovery_sql_sha256' => (string) $recovery['sha256'],
            'missing_privileges' => $missing,
            'principal_host_requires_review' => $backend === 'mysql',
            'automatic_grant' => false,
        ];
    }

    /** @return list<string> */
    public function backupFiles(string $backend): array
    {
        $path = $this->definitionPath($backend);
        return is_file($path) ? [$path] : [];
    }

    /** @param array<string, scalar|array<array-key, scalar>|null> $detail */
    public function audit(
        string $action,
        string $backend,
        array $detail = [],
        string $actor = 'openconcept_system'
    ): void
    {
        $this->ensureDirectory($this->directory);
        $path = $this->directory . DIRECTORY_SEPARATOR . 'recovery-audit.jsonl';
        $entry = [
            'timestamp' => gmdate('c'),
            'action' => $this->safeToken($action, 'database_recovery_event'),
            'backend' => $this->safeToken($backend, 'database'),
            'actor' => $this->safeActor($actor),
            'detail' => $detail,
        ];
        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $handle = @fopen($path, 'ab');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open the database recovery audit log.');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Could not persist the database recovery audit event.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /**
     * Records an administrator's assertion that the reviewed SQL was executed.
     * This method verifies the generated artifact but deliberately performs no
     * database operation; the next successful startup records independent
     * permission re-verification.
     */
    public function acknowledgeRecoveryExecution(
        string $backend,
        string $sqlSha256,
        string $executedBy
    ): void {
        $backend = $this->safeToken($backend, 'database');
        $sqlSha256 = strtolower(trim($sqlSha256));
        if (!in_array($backend, ['mysql', 'postgresql'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $sqlSha256) !== 1) {
            throw new InvalidArgumentException('The database recovery acknowledgement is invalid.');
        }
        $statusPath = $this->statusPath($backend);
        $status = $this->readJson($statusPath);
        $relativePath = $status['recovery_sql_path'] ?? null;
        if (($status['state'] ?? null) !== 'permissions_insufficient'
            || !is_string($relativePath)
            || !hash_equals((string) ($status['recovery_sql_sha256'] ?? ''), $sqlSha256)) {
            throw new RuntimeException('No matching pending database permission recovery exists.');
        }
        $sqlPath = $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $actualHash = is_file($sqlPath) ? hash_file('sha256', $sqlPath) : false;
        if (!is_string($actualHash) || !hash_equals($sqlSha256, strtolower($actualHash))) {
            throw new RuntimeException('The pending database permission recovery SQL failed hash verification.');
        }
        $actor = $this->safeActor($executedBy);
        $acknowledgedAt = gmdate('c');
        $this->audit('database_permission_recovery_execution_acknowledged', $backend, [
            'definition_sha256' => (string) ($status['definition_sha256'] ?? ''),
            'recovery_sql_sha256' => $sqlSha256,
            'administrator_asserted_manual_execution' => true,
            'automatic_grant' => false,
            'acknowledged_at' => $acknowledgedAt,
        ], $actor);
        $status['execution_acknowledgement'] = [
            'actor' => $actor,
            'recovery_sql_sha256' => $sqlSha256,
            'acknowledged_at' => $acknowledgedAt,
        ];
        $this->writeJsonAtomic($statusPath, $status);
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>
     */
    private function definition(string $backend, array $context): array
    {
        $requirements = match ($backend) {
            'mysql' => [
                'database' => [
                    'SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP',
                    'ALTER', 'INDEX', 'REFERENCES', 'TRIGGER',
                ],
            ],
            'postgresql' => [
                'database' => ['CONNECT'],
                'schema' => ['USAGE', 'CREATE'],
                'tables' => ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'],
                'sequences' => ['USAGE', 'SELECT', 'UPDATE'],
                'object_ownership' => ['OWNER_OR_OWNER_ROLE_MEMBERSHIP'],
                'recovery_defaults' => ['TABLES', 'SEQUENCES'],
            ],
            'sqlite' => [
                'file' => ['READ', 'WRITE'],
            ],
            default => throw new DatabaseAdapterException('Unsupported database permission definition.', 'invalid_configuration'),
        };
        $definition = [
            'format_version' => self::FORMAT_VERSION,
            'backend' => $backend,
            'database' => $context['database'],
            'schema' => $context['schema'],
            'table_prefix' => $context['table_prefix'],
            'principal' => $context['principal'],
            'requirements' => $requirements,
            'recovery_policy' => [
                'administrator_review_required' => true,
                'automatic_grant' => false,
                'audit_log' => '.database-adapters/recovery-audit.jsonl',
            ],
        ];
        $definition['definition_sha256'] = hash(
            'sha256',
            json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
        return $definition;
    }

    /** @param array<string, mixed> $config @return array<string, string> */
    private function context(PDO $pdo, string $backend, array $config): array
    {
        $prefix = method_exists($pdo, 'tablePrefix')
            ? (string) $pdo->tablePrefix()
            : (string) ($config['prefix'] ?? '');
        if ($backend === 'sqlite') {
            $database = (string) ($config['path'] ?? $config['dsn'] ?? 'sqlite');
            try {
                foreach ($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (($row['name'] ?? null) === 'main' && is_string($row['file'] ?? null) && $row['file'] !== '') {
                        $database = $row['file'];
                        break;
                    }
                }
            } catch (Throwable) {
                // The configured path remains sufficient for a file-backed
                // permission definition when database_list is unavailable.
            }
            return [
                'database' => $database,
                'schema' => 'main',
                'table_prefix' => $prefix,
                'principal' => 'filesystem_identity',
            ];
        }
        try {
            if ($backend === 'mysql') {
                return [
                    'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
                    'schema' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
                    'table_prefix' => $prefix,
                    'principal' => (string) $pdo->query('SELECT CURRENT_USER()')->fetchColumn(),
                ];
            }
            if ($backend === 'postgresql') {
                return [
                    'database' => (string) $pdo->query('SELECT current_database()')->fetchColumn(),
                    'schema' => (string) $pdo->query('SELECT current_schema()')->fetchColumn(),
                    'table_prefix' => $prefix,
                    'principal' => (string) $pdo->query('SELECT current_user')->fetchColumn(),
                ];
            }
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The active database identity could not be inspected.',
                $this->isPermissionError($exception, $backend) ? 'permissions_insufficient' : 'connection_failed',
                $exception
            );
        }
        throw new DatabaseAdapterException('Unsupported database permission backend.', 'invalid_configuration');
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function missingMySqlPrivileges(PDO $pdo, array $definition): array
    {
        $principal = (string) $definition['principal'];
        $separator = strrpos($principal, '@');
        if ($separator === false) {
            throw new DatabaseAdapterException('The MySQL principal identity is invalid.', 'permissions_insufficient');
        }
        $user = substr($principal, 0, $separator);
        $host = substr($principal, $separator + 1);
        $grantee = "'" . str_replace("'", "''", $user) . "'@'" . str_replace("'", "''", $host) . "'";
        try {
            $statement = $pdo->prepare(
                'SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ? '
                . 'UNION SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES '
                . 'WHERE GRANTEE = ? AND TABLE_SCHEMA = ?'
            );
            $statement->execute([$grantee, $grantee, (string) $definition['database']]);
            $actual = array_fill_keys(array_map('strtoupper', array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN))), true);
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'MySQL grants could not be inspected.',
                $this->isPermissionError($exception, 'mysql') ? 'permissions_insufficient' : 'connection_failed',
                $exception
            );
        }
        $missing = [];
        foreach ((array) ($definition['requirements']['database'] ?? []) as $privilege) {
            if (!isset($actual[strtoupper((string) $privilege)])) {
                $missing[] = 'database:' . strtoupper((string) $privilege);
            }
        }
        return $missing;
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function missingPostgreSqlPrivileges(PDO $pdo, array $definition): array
    {
        $missing = [];
        try {
            foreach ((array) ($definition['requirements']['database'] ?? []) as $privilege) {
                $statement = $pdo->prepare('SELECT has_database_privilege(current_user, current_database(), ?)');
                $statement->execute([(string) $privilege]);
                if (!$this->databaseBoolean($statement->fetchColumn())) {
                    $missing[] = 'database:' . strtoupper((string) $privilege);
                }
            }
            foreach ((array) ($definition['requirements']['schema'] ?? []) as $privilege) {
                $statement = $pdo->prepare('SELECT has_schema_privilege(current_user, current_schema(), ?)');
                $statement->execute([(string) $privilege]);
                if (!$this->databaseBoolean($statement->fetchColumn())) {
                    $missing[] = 'schema:' . strtoupper((string) $privilege);
                }
            }
            $relations = $pdo->prepare(
                "SELECT c.oid, c.relname, c.relkind, c.relowner, pg_get_userbyid(c.relowner) AS owner_name "
                . "FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace "
                . "WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p', 'S') "
                . "AND LEFT(c.relname, char_length(?)) = ?"
            );
            $relations->execute([(string) $definition['table_prefix'], (string) $definition['table_prefix']]);
            foreach ($relations->fetchAll(PDO::FETCH_ASSOC) as $relation) {
                $name = (string) ($relation['relname'] ?? '');
                $kind = (string) ($relation['relkind'] ?? '');
                $privileges = $kind === 'S'
                    ? (array) ($definition['requirements']['sequences'] ?? [])
                    : (array) ($definition['requirements']['tables'] ?? []);
                foreach ($privileges as $privilege) {
                    $function = $kind === 'S' ? 'has_sequence_privilege' : 'has_table_privilege';
                    $statement = $pdo->prepare("SELECT {$function}(current_user, CAST(? AS oid), ?)");
                    $statement->execute([(int) ($relation['oid'] ?? 0), (string) $privilege]);
                    if (!$this->databaseBoolean($statement->fetchColumn())) {
                        $missing[] = ($kind === 'S' ? 'sequence:' : 'table:') . $name . ':' . strtoupper((string) $privilege);
                    }
                }
                $ownerCheck = $pdo->prepare("SELECT pg_has_role(current_user, CAST(? AS oid), 'USAGE')");
                $ownerCheck->execute([(int) ($relation['relowner'] ?? 0)]);
                if (!$this->databaseBoolean($ownerCheck->fetchColumn())) {
                    $missing[] = 'owner_role:' . (string) ($relation['owner_name'] ?? 'unknown');
                }
            }
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'PostgreSQL grants could not be inspected.',
                $this->isPermissionError($exception, 'postgresql') ? 'permissions_insufficient' : 'connection_failed',
                $exception
            );
        }
        return array_values(array_unique($missing));
    }

    /**
     * @param array<string, mixed> $definition
     * @param list<string> $missing
     * @return array{path: string, sha256: string}
     */
    private function persistRecoverySql(string $backend, array $definition, array $missing): array
    {
        $statusPath = $this->statusPath($backend);
        $status = $this->readJson($statusPath);
        $missingHash = hash('sha256', json_encode($missing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (($status['state'] ?? null) === 'permissions_insufficient'
            && ($status['definition_sha256'] ?? null) === $definition['definition_sha256']
            && ($status['missing_sha256'] ?? null) === $missingHash
            && is_string($status['recovery_sql_path'] ?? null)
            && is_file($this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $status['recovery_sql_path']))) {
            $path = $this->directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $status['recovery_sql_path']);
            return ['path' => $path, 'sha256' => (string) hash_file('sha256', $path)];
        }

        $recoveryDirectory = $this->directory . DIRECTORY_SEPARATOR . 'recovery';
        $this->ensureDirectory($recoveryDirectory);
        $name = gmdate('Ymd\THis\Z') . '-' . $backend . '-'
            . substr((string) $definition['definition_sha256'], 0, 12) . '-'
            . substr($missingHash, 0, 12) . '.sql';
        $path = $recoveryDirectory . DIRECTORY_SEPARATOR . $name;
        $sql = $this->recoverySql($backend, $definition, $missing);
        $this->writeExclusive($path, $sql);
        $sha256 = hash('sha256', $sql);
        $status = [
            'state' => 'permissions_insufficient',
            'backend' => $backend,
            'definition_sha256' => $definition['definition_sha256'],
            'missing_sha256' => $missingHash,
            'recovery_sql_path' => 'recovery/' . $name,
            'recovery_sql_sha256' => $sha256,
            'detected_at' => gmdate('c'),
        ];
        $this->writeJsonAtomic($statusPath, $status);
        $this->audit('database_permission_recovery_sql_generated', $backend, [
            'definition_sha256' => (string) $definition['definition_sha256'],
            'recovery_sql' => 'recovery/' . $name,
            'recovery_sql_sha256' => $sha256,
            'missing_privileges' => $missing,
            'automatic_grant' => false,
        ]);
        return ['path' => $path, 'sha256' => $sha256];
    }

    /** @param array<string, mixed> $definition @param list<string> $missing */
    private function recoverySql(string $backend, array $definition, array $missing): string
    {
        $header = "-- OpenConcept administrator-reviewed database permission recovery\n"
            . '-- Definition SHA-256: ' . $definition['definition_sha256'] . "\n"
            . "-- This file was generated after a failed permission check.\n"
            . "-- Review the database, schema, principal, and scope before executing it.\n"
            . "-- OpenConcept never executes this GRANT SQL automatically.\n"
            . '-- Missing checks: ' . implode(', ', $missing) . "\n\n";
        if ($backend === 'mysql') {
            $database = $this->quoteMySqlIdentifier((string) $definition['database']);
            $principal = $this->quoteMySqlPrincipal((string) $definition['principal']);
            $privileges = implode(', ', (array) $definition['requirements']['database']);
            $review = str_contains((string) $definition['principal'], '<review-account-host>')
                ? "-- REQUIRED: replace <review-account-host> with the account host shown by the DB administrator.\n"
                : '';
            return $header . $review . "GRANT {$privileges} ON {$database}.* TO {$principal};\n"
                . "-- Re-run OpenConcept and confirm the permissions_insufficient diagnostic is cleared.\n";
        }

        $database = $this->quotePostgreSqlIdentifier((string) $definition['database']);
        $schema = $this->quotePostgreSqlIdentifier((string) $definition['schema']);
        $principal = $this->quotePostgreSqlIdentifier((string) $definition['principal']);
        $tablePrivileges = implode(', ', (array) $definition['requirements']['tables']);
        $sequencePrivileges = implode(', ', (array) $definition['requirements']['sequences']);
        $sql = $header
            . "GRANT CONNECT ON DATABASE {$database} TO {$principal};\n"
            . "GRANT USAGE, CREATE ON SCHEMA {$schema} TO {$principal};\n"
            . "GRANT {$tablePrivileges} ON ALL TABLES IN SCHEMA {$schema} TO {$principal};\n"
            . "GRANT {$sequencePrivileges} ON ALL SEQUENCES IN SCHEMA {$schema} TO {$principal};\n"
            . "ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT {$tablePrivileges} ON TABLES TO {$principal};\n"
            . "ALTER DEFAULT PRIVILEGES IN SCHEMA {$schema} GRANT {$sequencePrivileges} ON SEQUENCES TO {$principal};\n";
        $owners = [];
        foreach ($missing as $item) {
            if (str_starts_with($item, 'owner_role:')) {
                $owners[] = substr($item, strlen('owner_role:'));
            }
        }
        foreach (array_unique($owners) as $owner) {
            if ($owner !== '' && $owner !== 'unknown') {
                $sql .= 'GRANT ' . $this->quotePostgreSqlIdentifier($owner) . " TO {$principal};\n";
            }
        }
        return $sql . "-- Re-run OpenConcept and confirm the permissions_insufficient diagnostic is cleared.\n";
    }

    /** @param array<string, mixed> $definition */
    private function recordResolved(string $backend, array $definition): void
    {
        $path = $this->statusPath($backend);
        $previous = $this->readJson($path);
        if (($previous['state'] ?? null) === 'permissions_insufficient') {
            $this->audit('database_permission_recovery_verified', $backend, [
                'definition_sha256' => (string) $definition['definition_sha256'],
                'previous_recovery_sql_sha256' => (string) ($previous['recovery_sql_sha256'] ?? ''),
                'automatic_grant' => false,
            ]);
        }
        $next = [
            'state' => 'verified',
            'backend' => $backend,
            'definition_sha256' => $definition['definition_sha256'],
            'verified_at' => gmdate('c'),
        ];
        if ($previous !== $next && (($previous['state'] ?? null) !== 'verified'
                || ($previous['definition_sha256'] ?? null) !== $definition['definition_sha256'])) {
            $this->writeJsonAtomic($path, $next);
        }
    }

    /** @param array<string, mixed> $definition */
    private function persistDefinition(string $backend, array $definition): string
    {
        $path = $this->definitionPath($backend);
        $existing = $this->readJson($path);
        if ($existing !== $definition) {
            $this->writeJsonAtomic($path, $definition);
        }
        return $path;
    }

    private function definitionPath(string $backend): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'permission-definitions'
            . DIRECTORY_SEPARATOR . $this->safeToken($backend, 'database') . '.json';
    }

    private function statusPath(string $backend): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . 'permission-status-'
            . $this->safeToken($backend, 'database') . '.json';
    }

    private function relativePath(string $path): string
    {
        $relative = substr($path, strlen($this->directory) + 1);
        return '.database-adapters/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    }

    private function quoteMySqlIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new DatabaseAdapterException('The MySQL recovery database is invalid.', 'invalid_configuration');
        }
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteMySqlPrincipal(string $principal): string
    {
        $separator = strrpos($principal, '@');
        if ($separator === false) {
            throw new DatabaseAdapterException('The MySQL recovery principal is invalid.', 'invalid_configuration');
        }
        $user = str_replace("'", "''", substr($principal, 0, $separator));
        $host = str_replace("'", "''", substr($principal, $separator + 1));
        return "'{$user}'@'{$host}'";
    }

    private function quotePostgreSqlIdentifier(string $identifier): string
    {
        if ($identifier === '' || str_contains($identifier, "\0")) {
            throw new DatabaseAdapterException('The PostgreSQL recovery identifier is invalid.', 'invalid_configuration');
        }
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function dsnValue(string $dsn, string $key): string
    {
        if ($dsn === '') {
            return '';
        }
        $body = str_contains($dsn, ':') ? substr($dsn, strpos($dsn, ':') + 1) : $dsn;
        foreach (explode(';', $body) as $part) {
            $separator = strpos($part, '=');
            if ($separator !== false && strtolower(trim(substr($part, 0, $separator))) === strtolower($key)) {
                return trim(substr($part, $separator + 1));
            }
        }
        return '';
    }

    private function databaseBoolean(mixed $value): bool
    {
        return $value === true || in_array(strtolower((string) $value), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function requiredPrivilegeLabels(array $definition): array
    {
        $labels = [];
        foreach (['database', 'schema', 'tables', 'sequences', 'object_ownership'] as $scope) {
            foreach ((array) ($definition['requirements'][$scope] ?? []) as $privilege) {
                $labels[] = $scope . ':' . strtoupper((string) $privilege);
            }
        }
        return $labels;
    }

    private function isPermissionError(Throwable $exception, string $backend): bool
    {
        $sqlState = strtoupper((string) $exception->getCode());
        $driverCode = $exception instanceof PDOException && is_array($exception->errorInfo ?? null)
            ? (int) ($exception->errorInfo[1] ?? 0)
            : 0;
        $message = strtolower($exception->getMessage());
        if ($backend === 'mysql') {
            return $driverCode === 1044 || $driverCode === 1142 || $driverCode === 1143 || $driverCode === 1227
                || str_contains($message, 'command denied') || str_contains($message, 'access denied');
        }
        return $sqlState === '42501' || str_contains($message, 'permission denied')
            || str_contains($message, 'must be owner');
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1 ? $value : $fallback;
    }

    private function safeActor(string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        if ($value === '' || strlen($value) > 160
            || preg_match('/\b(password|passwd|pwd|secret|token)\s*[:=]/iu', $value) === 1) {
            throw new InvalidArgumentException('The database recovery audit actor is invalid.');
        }
        return $value;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $value */
    private function writeJsonAtomic(string $path, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $this->writeExclusive($temporary, $json);
        if (is_file($path) && !unlink($path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not replace the database permission state.');
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not commit the database permission state.');
        }
        @chmod($path, 0600);
    }

    private function writeExclusive(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create the database recovery artifact.');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Could not persist the database recovery artifact.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the database permission directory.');
        }
        @chmod($directory, 0700);
    }
}
