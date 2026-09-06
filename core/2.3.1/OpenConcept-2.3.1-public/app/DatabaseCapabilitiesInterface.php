<?php

declare(strict_types=1);

/**
 * Read-only capabilities of the already authenticated canonical connection.
 *
 * Plugins receive this object instead of database credentials. Extension
 * checks describe installed extensions, not extensions merely available on
 * the server, so a positive result is safe to use for feature activation.
 */
interface DatabaseCapabilitiesInterface
{
    public function driverName(): string;

    public function supportsExtension(string $name): bool;

    public function extensionVersion(string $name): ?string;

    public function supportsTransactions(): bool;

    public function supportsVectorSearch(): bool;
}
