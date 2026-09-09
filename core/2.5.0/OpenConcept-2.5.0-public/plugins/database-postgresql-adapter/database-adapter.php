<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/DatabaseSetupCapabilityProvisionerInterface.php';
require_once __DIR__ . '/src/PostgreSqlDatabaseCapabilities.php';
require_once __DIR__ . '/src/PostgreSqlDatabaseAdapter.php';

return new PostgreSqlDatabaseAdapter();
