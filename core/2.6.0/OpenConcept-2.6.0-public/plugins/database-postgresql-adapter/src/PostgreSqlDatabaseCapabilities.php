<?php

declare(strict_types=1);

final class PostgreSqlDatabaseCapabilities implements DatabaseCapabilitiesInterface
{
    public function __construct(private readonly PDO $pdo)
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new DatabaseAdapterException(
                'PostgreSQL capabilities require a PostgreSQL connection.',
                'driver_mismatch'
            );
        }
    }

    public function driverName(): string
    {
        return 'pgsql';
    }

    public function supportsExtension(string $name): bool
    {
        return $this->extensionVersion($name) !== null;
    }

    public function extensionVersion(string $name): ?string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $name) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT extversion FROM pg_extension WHERE extname = ? LIMIT 1');
        $statement->execute([$name]);
        $version = $statement->fetchColumn();
        return $version === false ? null : (string) $version;
    }

    public function supportsTransactions(): bool
    {
        return true;
    }

    public function supportsVectorSearch(): bool
    {
        return $this->supportsExtension('vector');
    }
}
