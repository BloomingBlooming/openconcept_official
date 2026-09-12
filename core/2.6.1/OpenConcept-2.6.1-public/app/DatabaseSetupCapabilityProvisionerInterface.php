<?php

declare(strict_types=1);

/**
 * Optional adapter contract for preparing destination capabilities before a
 * canonical migration writes application schema or data.
 *
 * Implementations may make narrowly scoped, idempotent changes to the
 * destination connection. They must fail before canonical cutover when a
 * required capability cannot be prepared.
 */
interface DatabaseSetupCapabilityProvisionerInterface
{
    /**
     * @param array<string, mixed> $config Normalized, non-secret adapter configuration.
     * @param array<string, mixed> $diagnostics Diagnostics from the same destination connection.
     * @return array<string, mixed> Updated diagnostics after capability preparation.
     */
    public function prepareSetupCapabilities(PDO $pdo, array $config, array $diagnostics): array;
}
