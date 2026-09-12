<?php

declare(strict_types=1);

final class SqliteDatabaseAdapter implements DatabaseAdapterInterface
{
    public function id(): string
    {
        return 'sqlite';
    }

    public function driver(): string
    {
        return 'sqlite';
    }

    public function defaultPort(): int
    {
        return 0;
    }

    public function defaultTablePrefix(): string
    {
        return '';
    }

    public function normalizeConfig(array $config): array
    {
        $path = (string) ($config['path'] ?? '');
        $dsn = trim((string) ($config['dsn'] ?? ''));
        if ($dsn !== '') {
            if (!str_starts_with(strtolower($dsn), 'sqlite:')) {
                throw new DatabaseAdapterException('SQLite DSN is invalid.', 'invalid_configuration');
            }
            return ['dsn' => $dsn, 'prefix' => trim((string) ($config['prefix'] ?? ''))];
        }
        if ($path === '' || str_contains($path, "\0")) {
            throw new DatabaseAdapterException('SQLite database path is invalid.', 'invalid_configuration');
        }
        return ['path' => $path, 'prefix' => trim((string) ($config['prefix'] ?? ''))];
    }

    public function connect(array $config, array $credentials = []): PrefixedPDO
    {
        $config = $this->normalizeConfig($config);
        $dsn = (string) ($config['dsn'] ?? ('sqlite:' . $config['path']));
        try {
            $pdo = new PrefixedPDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ], (string) ($config['prefix'] ?? ''));
            $pdo->exec('PRAGMA foreign_keys = ON');
            return $pdo;
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException('Could not open the SQLite database.', 'connection_failed');
        }
    }

    public function diagnose(PDO $pdo): array
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new DatabaseAdapterException('The connection is not SQLite.', 'driver_mismatch');
        }
        $foreignKeys = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
        $integrity = strtolower((string) $pdo->query('PRAGMA integrity_check')->fetchColumn()) === 'ok';
        $foreignKeyViolations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
        if (!$foreignKeys || !$integrity || $foreignKeyViolations !== []) {
            throw new DatabaseAdapterException(
                'The SQLite source database did not pass its integrity checks.',
                'environment_incompatible'
            );
        }
        return [
            'connection' => 'ok',
            'driver' => 'pdo_sqlite',
            'foreign_keys' => true,
            'foreign_key_check' => true,
            'integrity_check' => true,
            'compatible' => true,
        ];
    }

    public function provision(PDO $pdo, string $applicationRoot, LogicalDatabaseSchema $sourceSchema): void
    {
        throw new DatabaseAdapterException(
            'SQLite is the built-in source database and is not a plugin migration destination.',
            'unsupported_migration'
        );
    }
}
