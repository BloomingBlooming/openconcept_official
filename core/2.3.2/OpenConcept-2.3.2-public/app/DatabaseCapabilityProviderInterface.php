<?php

declare(strict_types=1);

/**
 * Optional adapter contract for connection-specific feature discovery.
 */
interface DatabaseCapabilityProviderInterface
{
    public function capabilities(PDO $pdo): DatabaseCapabilitiesInterface;
}
