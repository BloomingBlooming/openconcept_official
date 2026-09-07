<?php

declare(strict_types=1);

/** Creates one manifest-bound backup set for the canonical database. */
final class DatabaseBackupService
{
    public function __construct(
        private readonly Database $database,
        private readonly string $applicationRoot
    ) {
    }

    public function create(string $outputDirectory): string
    {
        if ($this->database->isManualExternalBackend()) {
            throw new DatabaseAdapterException(
                'A complete credential.key backup requires an adapter-managed canonical database.',
                'backup_requires_managed_adapter'
            );
        }
        $outputDirectory = rtrim($outputDirectory, "/\\");
        if ($outputDirectory === '' || str_contains($outputDirectory, "\0")) {
            throw new InvalidArgumentException('The database backup output directory is invalid.');
        }
        $this->ensureDirectory($outputDirectory);
        $resolvedOutput = realpath($outputDirectory);
        $public = realpath($this->applicationRoot . DIRECTORY_SEPARATOR . 'public');
        $published = realpath($this->applicationRoot . DIRECTORY_SEPARATOR . 'published');
        if (!is_string($resolvedOutput)
            || (is_string($public) && $this->pathWithin($resolvedOutput, $public))
            || (is_string($published) && $this->pathWithin($resolvedOutput, $published))) {
            throw new DatabaseAdapterException(
                'Database backups cannot be written below a public or published web directory.',
                'backup_output_not_private'
            );
        }
        $backupId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $name = 'openconcept-database-backup-' . $backupId;
        $stage = $outputDirectory . DIRECTORY_SEPARATOR . '.' . $name . '.stage';
        $final = $outputDirectory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($stage) || file_exists($final)) {
            throw new RuntimeException('The generated database backup path already exists.');
        }
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('Could not create the database backup stage.');
        }

        try {
            $backend = $this->database->backendId();
            $files = [];
            foreach ($this->database->adapterStateStore()->backupInventory($backend) as $kind => $source) {
                $destinationName = match ($kind) {
                    'adapter_state' => 'adapter-state.json',
                    'credential_key' => 'credential.key',
                    'workspace_identity' => 'workspace-identity.json',
                    default => throw new LogicException('Unknown adapter backup artifact.'),
                };
                $destination = $stage . DIRECTORY_SEPARATOR . $destinationName;
                $this->copyPrivate($source, $destination);
                $files[$destinationName] = $this->fileEvidence($destination);
            }

            if ($backend === 'sqlite') {
                $this->database->privilegeManager()->verify($this->database->pdo(), 'sqlite', [
                    'path' => $this->database->storagePath() . DIRECTORY_SEPARATOR . 'openconcept.sqlite',
                    'prefix' => '',
                ]);
            }
            $definitions = $this->database->privilegeManager()->backupFiles($backend);
            if (count($definitions) !== 1) {
                throw new RuntimeException('The active database permission definition is unavailable.');
            }
            $definitionDestination = $stage . DIRECTORY_SEPARATOR . 'permission-definition.json';
            $this->copyPrivate($definitions[0], $definitionDestination);
            $files['permission-definition.json'] = $this->fileEvidence($definitionDestination);

            $dumpName = $backend === 'sqlite' ? 'database.sqlite' : 'database.sql';
            $dumpPath = $stage . DIRECTORY_SEPARATOR . $dumpName;
            $this->dumpDatabase($backend, $dumpPath, $stage);
            @chmod($dumpPath, 0600);
            $files[$dumpName] = $this->fileEvidence($dumpPath);

            $manifest = [
                'format_version' => 1,
                'backup_id' => $backupId,
                'created_at' => gmdate('c'),
                'backend' => $backend,
                'canonical_state_generation' => (int) ($this->database->adapterStateStore()->load()['generation'] ?? 0),
                'consistency_method' => match ($backend) {
                    'sqlite' => 'sqlite_vacuum_into_snapshot',
                    'mysql' => 'mysqldump_single_transaction',
                    'postgresql' => 'pg_dump_mvcc_snapshot',
                    default => 'unknown',
                },
                'contains_plaintext_database_data' => true,
                'credential_key_is_sensitive' => isset($files['credential.key']),
                'files' => $files,
                'complete' => true,
            ];
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $this->writePrivate($stage . DIRECTORY_SEPARATOR . 'manifest.json', $manifestJson);
            $this->writePrivate(
                $stage . DIRECTORY_SEPARATOR . 'RESTORE-README.txt',
                "Keep this directory as one indivisible backup set.\n"
                . "Verify every SHA-256 entry in manifest.json before restore.\n"
                . "credential.key and adapter-state.json must be restored together; neither is useful alone.\n"
                . "The database dump contains application data in plaintext and must be protected accordingly.\n"
            );
            $manifestHash = (string) hash_file('sha256', $stage . DIRECTORY_SEPARATOR . 'manifest.json');
            $this->database->privilegeManager()->audit('database_backup_prepared', $backend, [
                'backup_id' => $backupId,
                'manifest_sha256' => $manifestHash,
                'adapter_state_generation' => (int) $manifest['canonical_state_generation'],
            ]);
            if (!rename($stage, $final)) {
                throw new RuntimeException('Could not commit the complete database backup set.');
            }
            @chmod($final, 0700);
            try {
                $this->database->privilegeManager()->audit('database_backup_created', $backend, [
                    'backup_id' => $backupId,
                    'manifest_sha256' => $manifestHash,
                    'adapter_state_generation' => (int) $manifest['canonical_state_generation'],
                ]);
            } catch (Throwable $auditException) {
                error_log('OpenConcept database backup committed, but final audit append failed: ' . $auditException->getMessage());
            }
            return $final;
        } catch (Throwable $exception) {
            $this->removeStage($stage);
            throw $exception;
        }
    }

    private function dumpDatabase(string $backend, string $destination, string $stage): void
    {
        if ($backend === 'sqlite') {
            $quoted = $this->database->pdo()->quote($destination);
            $this->database->pdo()->exec('VACUUM main INTO ' . $quoted);
        } else {
            $state = $this->database->adapterStateStore()->load();
            $adapter = $state['adapters'][$backend] ?? null;
            if (!is_array($adapter) || !is_array($adapter['config'] ?? null)) {
                throw new RuntimeException('The canonical adapter backup configuration is incomplete.');
            }
            $config = $adapter['config'];
            $credentials = $this->database->adapterStateStore()->credentials($backend);
            if ($backend === 'mysql') {
                $this->dumpMySql($config, $credentials, $destination, $stage);
            } elseif ($backend === 'postgresql') {
                $this->dumpPostgreSql($config, $credentials, $destination);
            } else {
                throw new RuntimeException('Unsupported canonical database backup backend.');
            }
        }
        if (!is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('The canonical database dump is missing or empty.');
        }
    }

    /** @param array<string, mixed> $config @param array{username: string, password: string} $credentials */
    private function dumpMySql(array $config, array $credentials, string $destination, string $stage): void
    {
        $parts = $this->connectionParts($config, 'mysql');
        $optionPath = $stage . DIRECTORY_SEPARATOR . '.mysql-backup.cnf';
        $option = "[client]\n"
            . 'user="' . $this->mysqlOptionValue($credentials['username']) . "\"\n"
            . 'password="' . $this->mysqlOptionValue($credentials['password']) . "\"\n"
            . 'host="' . $this->mysqlOptionValue($parts['host']) . "\"\n"
            . 'port=' . $parts['port'] . "\n"
            . "default-character-set=utf8mb4\n";
        $this->writePrivate($optionPath, $option);
        try {
            $tables = $this->mysqlTables((string) ($config['prefix'] ?? 'openconcept_'));
            $binary = trim((string) getenv('OPENCONCEPT_MYSQLDUMP_BINARY')) ?: 'mysqldump';
            $command = array_merge([
                $binary,
                '--defaults-extra-file=' . $optionPath,
                '--single-transaction',
                '--quick',
                '--skip-lock-tables',
                '--hex-blob',
                '--result-file=' . $destination,
                $parts['database'],
            ], $tables);
            $this->run($command, 'mysqldump');
        } finally {
            if (is_file($optionPath)) {
                @unlink($optionPath);
            }
        }
    }

    /** @param array<string, mixed> $config @param array{username: string, password: string} $credentials */
    private function dumpPostgreSql(array $config, array $credentials, string $destination): void
    {
        $parts = $this->connectionParts($config, 'postgresql');
        $binary = trim((string) getenv('OPENCONCEPT_PG_DUMP_BINARY')) ?: 'pg_dump';
        $schema = (string) $this->database->pdo()->query('SELECT current_schema()')->fetchColumn();
        $prefix = (string) ($config['prefix'] ?? 'openconcept_');
        $command = [
            $binary,
            '--format=plain',
            '--no-owner',
            '--no-privileges',
            '--host=' . $parts['host'],
            '--port=' . $parts['port'],
            '--username=' . $credentials['username'],
            '--dbname=' . $parts['database'],
            '--schema=' . $schema,
            '--table=' . $schema . '.' . $prefix . '*',
            '--file=' . $destination,
        ];
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['PGPASSWORD'] = $credentials['password'];
        if (isset($config['sslmode'])) {
            $environment['PGSSLMODE'] = (string) $config['sslmode'];
        }
        $this->run($command, 'pg_dump', $environment);
    }

    /** @return list<string> */
    private function mysqlTables(string $prefix): array
    {
        $rows = $this->database->pdo()->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];
        foreach ($rows as $row) {
            $name = (string) ($row[0] ?? '');
            $kind = strtoupper((string) ($row[1] ?? 'BASE TABLE'));
            if ($kind === 'BASE TABLE' && str_starts_with($name, $prefix)) {
                $tables[] = $name;
            }
        }
        sort($tables, SORT_STRING);
        if ($tables === []) {
            throw new RuntimeException('No canonical MySQL tables were found for the configured prefix.');
        }
        return $tables;
    }

    /** @param array<string, mixed> $config @return array{host: string, port: string, database: string} */
    private function connectionParts(array $config, string $backend): array
    {
        $dsn = (string) ($config['dsn'] ?? '');
        $parts = [];
        if ($dsn !== '') {
            $body = substr($dsn, strpos($dsn, ':') + 1);
            foreach (explode(';', $body) as $part) {
                $separator = strpos($part, '=');
                if ($separator !== false) {
                    $parts[strtolower(trim(substr($part, 0, $separator)))] = trim(substr($part, $separator + 1));
                }
            }
        }
        $host = (string) ($config['host'] ?? $parts['host'] ?? '');
        $port = (string) ($config['port'] ?? $parts['port'] ?? ($backend === 'mysql' ? '3306' : '5432'));
        $database = (string) ($config['database'] ?? $parts['dbname'] ?? '');
        if ($host === '' || $database === '' || preg_match('/^[0-9]{1,5}$/D', $port) !== 1) {
            throw new RuntimeException('The canonical database backup connection is incomplete.');
        }
        return ['host' => $host, 'port' => $port, 'database' => $database];
    }

    private function mysqlOptionValue(string $value): string
    {
        return str_replace(["\\", "\"", "\n", "\r"], ["\\\\", "\\\"", "\\n", "\\r"], $value);
    }

    private function pathWithin(string $path, string $parent): bool
    {
        $path = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR));
        $parent = strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $parent), DIRECTORY_SEPARATOR));
        return $path === $parent || str_starts_with($path, $parent . DIRECTORY_SEPARATOR);
    }

    /** @param list<string> $command @param array<string, string>|null $environment */
    private function run(array $command, string $label, ?array $environment = null): void
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->applicationRoot, $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start {$label}.");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $message = trim((string) $stderr);
            $message = preg_replace('/\b(password|passwd|pwd)\s*[:=]\s*\S+/iu', '$1=[redacted]', $message) ?? '';
            throw new RuntimeException("{$label} failed with exit code {$exit}: " . substr($message, 0, 500));
        }
        if ($stdout !== '' && $label === 'pg_dump') {
            throw new RuntimeException('pg_dump returned unexpected standard output.');
        }
    }

    /** @return array{size: int, sha256: string} */
    private function fileEvidence(string $path): array
    {
        $size = filesize($path);
        $sha256 = hash_file('sha256', $path);
        if (!is_int($size) || !is_string($sha256)) {
            throw new RuntimeException('Could not attest a database backup artifact.');
        }
        return ['size' => $size, 'sha256' => $sha256];
    }

    private function copyPrivate(string $source, string $destination): void
    {
        if (!is_file($source) || !copy($source, $destination)) {
            throw new RuntimeException('Could not copy a database backup artifact.');
        }
        @chmod($destination, 0600);
    }

    private function writePrivate(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create a database backup artifact.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Could not persist a database backup artifact.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create the database backup output directory.');
        }
    }

    private function removeStage(string $stage): void
    {
        if (!is_dir($stage) || is_link($stage)) {
            return;
        }
        foreach (scandir($stage) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $stage . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($stage);
    }
}
