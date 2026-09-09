<?php

declare(strict_types=1);

/**
 * Product-specific database behavior exposed to the OpenConcept core.
 *
 * Adapter discovery happens before the regular plugin boot sequence because
 * the canonical database must be known before system_settings can be read.
 */
interface DatabaseAdapterInterface
{
    public function id(): string;

    public function driver(): string;

    public function defaultPort(): int;

    public function defaultTablePrefix(): string;

    /** @param array<string, mixed> $config @return array<string, mixed> */
    public function normalizeConfig(array $config): array;

    /**
     * @param array<string, mixed> $config
     * @param array{username?: string, password?: string} $credentials
     */
    public function connect(array $config, array $credentials = []): PrefixedPDO;

    /** @return array<string, scalar|array<array-key, scalar>|null> */
    public function diagnose(PDO $pdo): array;

    public function provision(PDO $pdo, string $applicationRoot, LogicalDatabaseSchema $sourceSchema): void;
}
