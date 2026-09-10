<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseEndpointVerifier.php';
require_once __DIR__ . '/DatabaseWorkspaceOwnership.php';
require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaRuntime.php';
require_once __DIR__ . '/DatabaseSetupCapabilityProvisionerInterface.php';
require_once __DIR__ . '/RagPluginContracts.php';
require_once __DIR__ . '/OpenConceptRagDocumentGateway.php';
require_once __DIR__ . '/RagCoreRepository.php';
require_once __DIR__ . '/StandardRagRepository.php';
require_once __DIR__ . '/RagProviderException.php';
require_once __DIR__ . '/SafeOutboundHttpClient.php';
require_once __DIR__ . '/AiProviderSettings.php';
require_once __DIR__ . '/BgeM3EmbeddingProvider.php';

final class DatabaseAdapterCoordinator
{
    public function __construct(
        private readonly Database $database,
        private readonly string $applicationRoot
    ) {
    }

    /** @return array<string, mixed> */
    public function status(string $adapterId): array
    {
        $state = $this->database->adapterStateStore()->publicStatus();
        $rawState = $this->database->adapterStateStore()->load();
        $adapterState = is_array($state['adapters'][$adapterId] ?? null) ? $state['adapters'][$adapterId] : [];
        $rawAdapterState = is_array($rawState['adapters'][$adapterId] ?? null) ? $rawState['adapters'][$adapterId] : [];
        $replacementState = is_array($rawAdapterState['replacement'] ?? null)
            ? $rawAdapterState['replacement']
            : [];
        $endpointConfig = is_array($rawAdapterState['config'] ?? null) ? $rawAdapterState['config'] : [];
        $diagnostics = is_array($adapterState['diagnostics'] ?? null) ? $adapterState['diagnostics'] : [];
        $canonicalBackend = $this->database->backendId();
        $manualCanonical = $adapterId === $canonicalBackend
            && $this->database->isManualExternalBackend();
        $manualMySql = $manualCanonical && $adapterId === 'mysql';
        $unavailableAfterPostgreSqlMigration = $adapterId === 'mysql'
            && $canonicalBackend === 'postgresql';
        $lifecycle = ($manualCanonical || $unavailableAfterPostgreSqlMigration)
            ? 'DISABLED'
            : (string) ($adapterState['lifecycle'] ?? 'INSTALLED');
        $replacedBy = is_string($adapterState['replaced_by'] ?? null)
            ? $adapterState['replaced_by']
            : ($unavailableAfterPostgreSqlMigration ? 'postgresql' : null);
        $retiredAfterMigration = $adapterId === 'mysql'
            && ($adapterState['retired_after_migration'] ?? false) === true;
        $connection = $manualCanonical
            ? 'ok'
            : ($lifecycle === 'ERROR' ? 'failed' : ($adapterState['diagnostics']['connection'] ?? null));
        $environment = $manualCanonical
            ? 'compatible'
            : (($adapterState['diagnostics']['compatible'] ?? false) ? 'compatible' : null);
        $managedCanonicalMySql = $adapterId === 'mysql'
            && $this->database->backendId() === 'mysql'
            && !$this->database->isManualExternalBackend();
        $workspaceProtected = $managedCanonicalMySql
            && is_array($rawAdapterState['workspace_ownership'] ?? null)
            && ($rawAdapterState['workspace_ownership']['status'] ?? null) === 'active';
        $canonicalPostgreSql = $adapterId === 'postgresql'
            && $this->database->backendId() === 'postgresql';
        $setupInput = null;
        if ($adapterId === 'mysql'
            && $this->database->backendId() === 'sqlite'
            && $lifecycle === 'ERROR'
            && $endpointConfig !== []) {
            $setupInput = [
                'host' => (string) ($endpointConfig['host'] ?? ''),
                'port' => (int) ($endpointConfig['port'] ?? 0),
                'database' => (string) ($endpointConfig['database'] ?? ''),
            ];
        } elseif ($adapterId === 'postgresql'
            && in_array($this->database->backendId(), ['sqlite', 'mysql'], true)
            && $lifecycle === 'ERROR'
            && $endpointConfig !== []) {
            $setupInput = [
                'host' => (string) ($endpointConfig['host'] ?? ''),
                'port' => (int) ($endpointConfig['port'] ?? 0),
                'database' => (string) ($endpointConfig['database'] ?? ''),
                'sslmode' => (string) ($endpointConfig['sslmode'] ?? 'prefer'),
                'require_pgvector' => ($endpointConfig['require_pgvector'] ?? true) === true,
            ];
        } elseif ($adapterId === 'postgresql'
            && $canonicalPostgreSql
            && ($replacementState['lifecycle'] ?? null) === 'ERROR'
            && is_array($replacementState['config'] ?? null)) {
            $replacementConfig = $replacementState['config'];
            $setupInput = [
                'host' => (string) ($replacementConfig['host'] ?? ''),
                'port' => (int) ($replacementConfig['port'] ?? 0),
                'database' => (string) ($replacementConfig['database'] ?? ''),
                'sslmode' => (string) ($replacementConfig['sslmode'] ?? 'prefer'),
                'require_pgvector' => ($replacementConfig['require_pgvector'] ?? true) === true,
                'copy_canonical' => ($replacementState['copy_canonical'] ?? true) === true,
            ];
        }
        if ($adapterId === 'postgresql'
            && $canonicalPostgreSql
            && $this->pgvectorDiagnosticState($diagnostics) === 'unknown') {
            try {
                $version = $this->database->capabilities()->extensionVersion('vector');
                if ($version !== null) {
                    $diagnostics['pgvector_status'] = 'installed';
                    $diagnostics['pgvector_version'] = $version;
                }
            } catch (Throwable) {
                // Status remains unknown when credential-free capability
                // discovery cannot complete on the active connection.
            }
        }
        $result = [
            'adapter' => $adapterId,
            'installed' => isset($this->database->adapterRegistry()->all()[$adapterId]),
            'lifecycle' => $lifecycle,
            'canonical_backend' => $canonicalBackend,
            'is_canonical' => $adapterId === $canonicalBackend,
            'available' => !$unavailableAfterPostgreSqlMigration,
            'unavailable_reason' => $unavailableAfterPostgreSqlMigration
                ? 'replaced_by_postgresql'
                : null,
            'manual_backend' => $this->database->isManualExternalBackend(),
            'manual_connection_detected' => $manualCanonical,
            'manual_mysql_detected' => $manualMySql,
            'replaced_by' => $replacedBy,
            'retired_after_migration' => $retiredAfterMigration,
            'can_configure' => ($adapterId === 'mysql' && $this->database->backendId() === 'sqlite')
                || ($adapterId === 'postgresql'
                    && in_array($this->database->backendId(), ['sqlite', 'mysql', 'postgresql'], true)),
            'connection' => $connection,
            'environment' => $environment,
            'operational' => $lifecycle === 'ACTIVE'
                && $connection === 'ok'
                && $environment === 'compatible'
                && (($managedCanonicalMySql && $workspaceProtected) || $canonicalPostgreSql),
            'workspace_protected' => $workspaceProtected,
            'migration' => $replacementState !== []
                ? ($replacementState['migration']['status'] ?? ($replacementState['lifecycle'] ?? null))
                : ($manualCanonical ? 'not_required' : ($adapterState['migration']['status'] ?? null)),
            'validation' => $replacementState['migration']['validation']
                ?? ($adapterState['migration']['validation'] ?? null),
            'last_error_code' => $replacementState['last_error_code']
                ?? ($adapterState['last_error_code'] ?? null),
            'last_error' => is_array($replacementState['last_error'] ?? null)
                ? $replacementState['last_error']
                : (is_array($adapterState['last_error'] ?? null) ? $adapterState['last_error'] : null),
            'error_log' => is_array($replacementState['error_log'] ?? null)
                ? array_values(array_slice($replacementState['error_log'], -20))
                : (is_array($adapterState['error_log'] ?? null)
                    ? array_values(array_slice($adapterState['error_log'], -20))
                    : []),
            'last_checked_at' => $adapterState['last_checked_at'] ?? null,
            'automatic_fallback' => false,
            'can_relocate_endpoint' => $managedCanonicalMySql && $lifecycle === 'ACTIVE' && $workspaceProtected,
            // Only non-secret setup fields are returned. The user ID remains
            // client-side and the password is never retained after failure.
            'setup_input' => $setupInput,
            'connection_info' => $adapterId === $this->database->backendId()
                ? $this->database->connectionInfo()
                : null,
            'endpoint' => $managedCanonicalMySql
                ? [
                    'host' => (string) ($endpointConfig['host'] ?? ''),
                    'port' => (int) ($endpointConfig['port'] ?? 0),
                ]
                : ($canonicalPostgreSql && $endpointConfig !== []
                    ? [
                        'host' => (string) ($endpointConfig['host'] ?? ''),
                        'port' => (int) ($endpointConfig['port'] ?? 0),
                        'database' => (string) ($endpointConfig['database'] ?? ''),
                        'sslmode' => (string) ($endpointConfig['sslmode'] ?? ''),
                    ]
                    : null),
        ];
        if ($adapterId === 'postgresql') {
            $result['pgvector'] = $this->publicPgvectorStatus(
                $diagnostics,
                ($endpointConfig['require_pgvector'] ?? true) === true
            );
            $result['postgresql_replacement'] = [
                'available' => $canonicalPostgreSql,
                'lifecycle' => $replacementState['lifecycle'] ?? null,
                'copy_canonical' => ($replacementState['copy_canonical'] ?? true) === true,
                'rag_rebuild' => is_array($adapterState['rag_rebuild'] ?? null)
                    ? $adapterState['rag_rebuild']
                    : null,
            ];
        }
        return $result;
    }

    /**
     * Safely changes only the host and port of the ACTIVE MySQL adapter.
     * The stored database, prefix, username, and password remain unchanged.
     *
     * @return array<string, mixed>
     */
    public function relocateMySqlEndpoint(
        string $host,
        mixed $port,
        string $username,
        string $password,
        ?callable $authorize = null,
        array $operationContext = []
    ): array {
        try {
        if ($this->database->backendId() !== 'mysql' || $this->database->isManualExternalBackend()) {
            throw new DatabaseAdapterException(
                'Only an ACTIVE adapter-managed MySQL backend can change its endpoint.',
                'invalid_source_backend'
            );
        }
        $stateStore = $this->database->adapterStateStore();
        $initialState = $stateStore->load();
        $initialAdapter = $initialState['adapters']['mysql'] ?? null;
        if (($initialState['canonical_backend'] ?? null) !== 'mysql'
            || !is_array($initialAdapter)
            || ($initialAdapter['lifecycle'] ?? null) !== 'ACTIVE'
            || !is_array($initialAdapter['config'] ?? null)) {
            throw new DatabaseAdapterException(
                'The MySQL adapter is not ACTIVE.',
                'not_active'
            );
        }
        $expectedGeneration = (int) ($initialState['generation'] ?? 0);
        $expectedConfig = $initialAdapter['config'];
        $adapter = $this->database->adapterRegistry()->get('mysql');
        $installationFingerprint = $this->database->adapterRegistry()->fingerprintFor('mysql');
        $candidateConfig = $expectedConfig;
        $candidateConfig['host'] = trim($host);
        $candidateConfig['port'] = $port;
        $candidateConfig = $adapter->normalizeConfig($candidateConfig);
        foreach ($expectedConfig as $key => $value) {
            if (in_array($key, ['host', 'port'], true)) {
                continue;
            }
            if (!array_key_exists($key, $candidateConfig) || $candidateConfig[$key] !== $value) {
                throw new DatabaseAdapterException(
                    'Only the database host and port may be changed.',
                    'invalid_configuration'
                );
            }
        }
        if ((string) ($candidateConfig['host'] ?? '') === (string) ($expectedConfig['host'] ?? '')
            && (int) ($candidateConfig['port'] ?? 0) === (int) ($expectedConfig['port'] ?? 0)) {
            throw new DatabaseAdapterException(
                'The MySQL host or port must be changed before confirmation.',
                'endpoint_unchanged'
            );
        }

        $storedCredentials = $stateStore->credentials('mysql');
        if (!hash_equals($storedCredentials['username'], trim($username))
            || !hash_equals($storedCredentials['password'], $password)) {
            throw new DatabaseAdapterException(
                'The current MySQL user ID or password is incorrect.',
                'credentials_mismatch'
            );
        }

        $this->database->beginAdapterCutover(array_merge([
            'adapter' => 'mysql',
            'operation' => 'endpoint_relocation',
        ], $operationContext));
        $activated = false;
        try {
            $this->database->adapterRegistry()->assertCurrent('mysql', $installationFingerprint);
            $this->database->updateAdapterCutoverStage('verifying_endpoint');
            $this->database->throwIfAdapterCutoverCancelled();
            $currentState = $stateStore->load();
            if ((int) ($currentState['generation'] ?? 0) !== $expectedGeneration) {
                throw new DatabaseAdapterException(
                    'The canonical database configuration changed while endpoint verification was waiting to start.',
                    'canonical_backend_changed'
                );
            }
            if ($authorize !== null) {
                $authorize();
            }
            $candidate = $adapter->connect($candidateConfig, $storedCredentials);
            $diagnostics = $adapter->diagnose($candidate);
            $identity = $stateStore->workspaceIdentity((string) ($expectedConfig['prefix'] ?? ''));
            $ownership = new DatabaseWorkspaceOwnership($candidate, $identity);
            $ownership->acquire();
            try {
                $ownershipVerification = $ownership->verify();
                $verification = (new DatabaseEndpointVerifier())->verify($this->database->pdo(), $candidate);
            } finally {
                $ownership->release();
            }
            $this->database->throwIfAdapterCutoverCancelled();
            $this->database->updateAdapterCutoverStage('activating', [], false);
            $this->database->throwIfAdapterCutoverCancelled();
            $stateStore->relocateEndpoint(
                'mysql',
                $expectedGeneration,
                $expectedConfig,
                $candidateConfig,
                $diagnostics,
                $verification
            );
            $activated = true;
            return [
                'adapter' => 'mysql',
                'lifecycle' => 'ACTIVE',
                'connection' => 'ok',
                'environment' => 'compatible',
                'endpoint' => [
                    'host' => (string) $candidateConfig['host'],
                    'port' => (int) $candidateConfig['port'],
                ],
                'verification' => $verification,
                'workspace_protection' => [
                    'status' => (string) $ownershipVerification['status'],
                    'table_count' => (int) $ownershipVerification['table_count'],
                ],
                'reload_required' => true,
                'automatic_fallback' => false,
            ];
        } finally {
            $this->database->finishAdapterCutover($activated);
        }
        } catch (Throwable $exception) {
            if ($exception instanceof DatabaseAdapterException
                && $exception->failureCode() === 'adapter_installation_changed') {
                throw $exception;
            }
            if ($this->database->backendId() === 'mysql' && !$this->database->isManualExternalBackend()) {
                $failureCode = $exception instanceof DatabaseAdapterException
                    ? $exception->failureCode()
                    : 'adapter_error';
                $message = $exception instanceof DatabaseAdapterException
                    ? $exception->getMessage()
                    : 'Database endpoint change failed.';
                try {
                    $this->database->adapterStateStore()->recordFailure(
                        'mysql',
                        'endpoint_relocation',
                        $failureCode,
                        $message,
                        false
                    );
                } catch (Throwable) {
                }
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $rawConfig
     * @return array<string, mixed>
     */
    public function setup(
        string $adapterId,
        array $rawConfig,
        string $username,
        string $password,
        ?callable $authorize = null,
        array $operationContext = [],
        bool $copyCanonical = true
    ): array
    {
        $sourceBackend = $this->database->backendId();
        $postgresqlReplacement = $adapterId === 'postgresql' && $sourceBackend === 'postgresql';
        if (!$copyCanonical && !$postgresqlReplacement) {
            throw new DatabaseAdapterException(
                'Skipping the canonical data copy is supported only for PostgreSQL-to-PostgreSQL replacement.',
                'invalid_source_backend'
            );
        }
        $this->assertTransition($adapterId);
        $adapter = $this->database->adapterRegistry()->get($adapterId);
        $installationFingerprint = $this->database->adapterRegistry()->fingerprintFor($adapterId);
        $stateStore = $this->database->adapterStateStore();
        $initialState = $stateStore->load();
        $sourceAdapterState = is_array($initialState['adapters'][$sourceBackend] ?? null)
            ? $initialState['adapters'][$sourceBackend]
            : [];
        $sourceEndpointConfig = is_array($sourceAdapterState['config'] ?? null)
            ? $sourceAdapterState['config']
            : [];
        $existingPrefix = null;
        if (in_array($sourceBackend, ['mysql', 'postgresql'], true)) {
            if ($this->database->isManualExternalBackend()) {
                $source = $this->database->pdo();
                $existingPrefix = method_exists($source, 'tablePrefix')
                    ? (string) $source->tablePrefix()
                    : null;
            } else {
                $currentConfig = $sourceAdapterState['config'] ?? null;
                if (is_array($currentConfig)) {
                    $existingPrefix = (string) ($currentConfig['prefix'] ?? '');
                }
            }
        }
        $identity = $stateStore->workspaceIdentity($existingPrefix);
        // The namespace belongs to the installation, not to setup-form input.
        $rawConfig['prefix'] = $identity['table_prefix'];
        $config = $adapter->normalizeConfig($rawConfig);
        if (isset($config['dsn'])) {
            throw new DatabaseAdapterException('Setup requires structured connection settings.', 'invalid_configuration');
        }
        $fingerprint = hash('sha256', json_encode(
            [$adapterId, $config, $username, $copyCanonical],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
        $priorAdapter = $stateStore->load()['adapters'][$adapterId] ?? null;
        $prior = $postgresqlReplacement && is_array($priorAdapter)
            ? ($priorAdapter['replacement'] ?? null)
            : $priorAdapter;
        $managedRetry = is_array($prior)
            && ($prior['migration_fingerprint'] ?? '') === $fingerprint
            && ($prior['managed_destination'] ?? false) === true;
        $setLifecycle = function (string $lifecycle, array $details) use (
            $stateStore,
            $adapterId,
            $postgresqlReplacement,
            $copyCanonical
        ): void {
            if ($postgresqlReplacement) {
                $stateStore->setReplacementLifecycle($adapterId, $lifecycle, array_merge($details, [
                    'copy_canonical' => $copyCanonical,
                ]));
                return;
            }
            $stateStore->setLifecycle($adapterId, $lifecycle, $details);
        };
        $this->database->beginAdapterCutover(array_merge([
            'adapter' => $adapterId,
            'operation' => 'setup',
        ], $operationContext));
        $activated = false;
        $disposition = null;
        $ownership = null;
        $ownershipReserved = false;
        $workspaceProtection = null;
        $sourceDiagnostics = null;
        $sourceCanonicalSchema = null;
        try {
            // A pending cutover can outlive an installation change while its
            // shared lease drains. Revalidate the loaded provider under EX,
            // before authorization side effects or any adapter state write.
            $this->database->adapterRegistry()->assertCurrent($adapterId, $installationFingerprint);
            $this->database->updateAdapterCutoverStage('checking_source');
            $this->database->throwIfAdapterCutoverCancelled();
            $currentCanonical = $this->database->isManualExternalBackend()
                ? $this->database->backendId()
                : (string) ($stateStore->load()['canonical_backend'] ?? 'sqlite');
            if ($currentCanonical !== $this->database->backendId()) {
                throw new DatabaseAdapterException(
                    'The canonical backend changed while this migration was waiting to start.',
                    'canonical_backend_changed'
                );
            }
            if ($authorize !== null) {
                $authorize();
            }
            $this->database->throwIfAdapterCutoverCancelled();
            $sourceCanonicalSchema = $this->verifyCanonicalEndpoint($this->database->pdo());
            $setLifecycle('CONFIGURING', [
                'config' => $config,
                'migration_fingerprint' => $fingerprint,
                'source_backend' => $this->database->backendId(),
                'last_error_code' => null,
                'last_error' => null,
            ]);
            if ($adapterId === 'postgresql') {
                $sourceBackend = $this->database->backendId();
                try {
                    $sourceDiagnostics = $this->database->adapterRegistry()
                        ->get($sourceBackend)
                        ->diagnose($this->database->pdo());
                } catch (Throwable $exception) {
                    throw new DatabaseAdapterException(
                        'The current source backend did not pass the required health check.',
                        'source_backend_unhealthy',
                        $exception
                    );
                }
                $setLifecycle('CONFIGURING', [
                    'source_diagnostics' => $sourceDiagnostics,
                    'migration_fingerprint' => $fingerprint,
                ]);
            }
            $this->database->updateAdapterCutoverStage('connecting_destination');
            $this->database->throwIfAdapterCutoverCancelled();
            $destination = $adapter->connect($config, ['username' => $username, 'password' => $password]);
            if ($adapterId === 'mysql') {
                // Prove the same permission contract used at runtime before
                // provisioning or committing the canonical backend switch.
                $this->database->privilegeManager()->verify($destination, $adapterId, $config);
            }
            if ($postgresqlReplacement) {
                $this->assertDistinctPostgreSqlDestination($destination, $sourceEndpointConfig, $config);
                if ($managedRetry) {
                    $this->dropManagedStandardRagTables($destination, (string) ($config['prefix'] ?? ''));
                }
            }
            $inspector = new DatabaseSchemaInspector();
            $sourceSchema = $this->migrationSchema(
                $inspector->inspect($this->database->pdo()),
                $postgresqlReplacement
            );
            $migration = new DatabaseMigrationService(
                $this->database->pdo(),
                $destination,
                $sourceSchema,
                function (string $stage, array $details): void {
                    $this->database->updateAdapterCutoverStage($stage, $details);
                    $this->database->throwIfAdapterCutoverCancelled();
                }
            );
            $this->database->updateAdapterCutoverStage('checking_destination');
            $this->database->throwIfAdapterCutoverCancelled();
            $disposition = $migration->destinationDisposition($managedRetry);
            if ($adapterId === 'mysql') {
                $ownership = new DatabaseWorkspaceOwnership($destination, $identity);
                $ownership->acquire();
                $workspaceProtection = $ownership->reserve($sourceSchema, $managedRetry);
                $ownershipReserved = true;
            }
            $setLifecycle('CHECKING', [
                'managed_destination' => true,
                'destination_mode' => $disposition['mode'],
                'migration_fingerprint' => $fingerprint,
            ]);
            $diagnostics = $adapter->diagnose($destination);
            $setLifecycle('CHECKING', [
                'diagnostics' => $diagnostics,
                'managed_destination' => true,
                'migration_fingerprint' => $fingerprint,
            ]);
            if ($adapter instanceof DatabaseSetupCapabilityProvisionerInterface) {
                $this->database->updateAdapterCutoverStage('preparing_destination_capabilities');
                $this->database->throwIfAdapterCutoverCancelled();
                $diagnostics = $adapter->prepareSetupCapabilities($destination, $config, $diagnostics);
                $setLifecycle('CHECKING', [
                    'diagnostics' => $diagnostics,
                    'managed_destination' => true,
                    'migration_fingerprint' => $fingerprint,
                ]);
            }
            $setLifecycle('MIGRATING', [
                'diagnostics' => $diagnostics,
                'managed_destination' => true,
                'migration_fingerprint' => $fingerprint,
                'migration' => ['status' => 'running', 'started_at' => gmdate('c')],
            ]);
            $this->database->updateAdapterCutoverStage('provisioning');
            $this->database->throwIfAdapterCutoverCancelled();
            $adapter->provision($destination, $this->applicationRoot, $sourceSchema);
            $this->database->throwIfAdapterCutoverCancelled();
            $setLifecycle('VALIDATING', [
                'managed_destination' => true,
                'migration_fingerprint' => $fingerprint,
                'migration' => ['status' => 'validating', 'started_at' => gmdate('c')],
            ]);
            $report = $copyCanonical ? $migration->migrate() : $migration->initializeEmpty();
            $report['copy_canonical'] = $copyCanonical;
            $this->database->updateAdapterCutoverStage('validating_schema');
            $this->database->throwIfAdapterCutoverCancelled();
            $sourceCanonicalSchema = $this->verifyCanonicalEndpoint($this->database->pdo());
            $destinationCanonicalSchema = $this->verifyCanonicalEndpoint($destination);
            $report['validation']['canonical_source_schema'] = true;
            $report['validation']['canonical_destination_schema'] = true;
            $report['canonical_schema'] = [
                'source' => $sourceCanonicalSchema,
                'destination' => $destinationCanonicalSchema,
            ];
            $ragRebuild = null;
            if ($postgresqlReplacement) {
                $ragRebuild = $this->preparePostgreSqlRagRebuild(
                    $destination,
                    $copyCanonical,
                    $sourceDiagnostics ?? [],
                    $diagnostics
                );
                $report['rag_rebuild'] = $ragRebuild;
            }
            if ($ownership !== null) {
                $workspaceProtection = $ownership->finalize($sourceSchema);
            }
            $this->database->throwIfAdapterCutoverCancelled();
            $this->database->updateAdapterCutoverStage('activating', [], false);
            $this->database->throwIfAdapterCutoverCancelled();
            if ($postgresqlReplacement && !$this->database->isManualExternalBackend()) {
                $activeSource = $stateStore->load()['adapters']['postgresql']['config'] ?? null;
                if (!is_array($activeSource) || $activeSource !== $sourceEndpointConfig) {
                    throw new DatabaseAdapterException(
                        'The canonical PostgreSQL configuration changed during replacement.',
                        'canonical_backend_changed'
                    );
                }
            }
            $publicConfig = $config;
            unset($publicConfig['dsn']);
            $stateStore->activate(
                $adapterId,
                $publicConfig,
                ['username' => $username, 'password' => $password],
                $diagnostics,
                $this->database->backendId()
            );
            // This is the irreversible cutover commit point. Any later local
            // lifecycle/reporting failure must not reopen the old source lease.
            $activated = true;
            $activeDetails = [
                'managed_destination' => true,
                'migration_fingerprint' => $fingerprint,
                'workspace_ownership' => $workspaceProtection,
                'migration' => [
                    'status' => 'completed',
                    'completed_at' => gmdate('c'),
                    'copy_canonical' => $copyCanonical,
                    'tables' => $report['tables'],
                    'rows' => $report['rows'],
                    'validation' => $report['validation'],
                ],
            ];
            if (is_array($ragRebuild)) {
                $activeDetails['rag_rebuild'] = $ragRebuild;
            }
            if (is_array($sourceDiagnostics)) {
                $activeDetails['source_diagnostics'] = $sourceDiagnostics;
            }
            if (is_array($sourceCanonicalSchema)) {
                $activeDetails['canonical_schema'] = $report['canonical_schema'];
            }
            $stateStore->setLifecycle($adapterId, 'ACTIVE', $activeDetails);
            $result = [
                'adapter' => $adapterId,
                'lifecycle' => 'ACTIVE',
                'canonical_backend' => $adapterId,
                'connection' => 'ok',
                'environment' => 'compatible',
                'migration' => $report,
                'copy_canonical' => $copyCanonical,
                'workspace_protected' => $workspaceProtection !== null,
                'reload_required' => true,
                'automatic_fallback' => false,
            ];
            if ($adapterId === 'postgresql') {
                $result['pgvector'] = $this->publicPgvectorStatus(
                    $diagnostics,
                    ($config['require_pgvector'] ?? true) === true
                );
            }
            return $result;
        } catch (Throwable $exception) {
            if ($exception instanceof DatabaseAdapterException
                && $exception->failureCode() === 'adapter_installation_changed') {
                // Detach preserves the previous adapter state. Do not recreate
                // an ERROR/configuration record for the removed installation.
                throw $exception;
            }
            $failureCode = $exception instanceof DatabaseAdapterException
                ? $exception->failureCode()
                : 'adapter_error';
            try {
                $message = $exception instanceof DatabaseAdapterException
                    ? $exception->getMessage()
                    : 'Database adapter setup failed.';
                $failureDetails = [
                    'migration_fingerprint' => $fingerprint,
                    'managed_destination' => $managedRetry || $ownershipReserved
                        || (is_array($disposition) && isset($disposition['mode'])),
                    'copy_canonical' => $copyCanonical,
                ];
                if ($postgresqlReplacement) {
                    $failureDetails['config'] = $config;
                    $stateStore->recordReplacementFailure(
                        $adapterId,
                        'replace_postgresql',
                        $failureCode,
                        $message,
                        $failureDetails
                    );
                } else {
                    $stateStore->recordFailure(
                        $adapterId,
                        'setup',
                        $failureCode,
                        $message,
                        true,
                        $failureDetails
                    );
                }
            } catch (Throwable) {
            }
            if ($exception instanceof DatabaseAdapterException) {
                throw $exception;
            }
            throw new DatabaseAdapterException('Database adapter setup failed.', $failureCode, $exception);
        } finally {
            if ($ownership !== null) {
                $ownership->release();
            }
            $this->database->finishAdapterCutover($activated);
        }
    }

    /** @return array<string, mixed> */
    public function healthCheck(string $adapterId): array
    {
        if ($this->database->backendId() !== $adapterId) {
            throw new DatabaseAdapterException('The adapter is not the canonical backend.', 'not_active');
        }
        $adapter = $this->database->adapterRegistry()->get($adapterId);
        try {
            $diagnostics = $adapter->diagnose($this->database->pdo());
            $canonicalSchema = $this->verifyCanonicalEndpoint($this->database->pdo());
            $workspaceProtection = null;
            if ($adapterId === 'mysql' && !$this->database->isManualExternalBackend()) {
                $state = $this->database->adapterStateStore()->load();
                $config = $state['adapters']['mysql']['config'] ?? null;
                if (!is_array($config)) {
                    throw new DatabaseAdapterException('The MySQL adapter state is incomplete.', 'not_active');
                }
                $identity = $this->database->adapterStateStore()->workspaceIdentity(
                    (string) ($config['prefix'] ?? '')
                );
                $ownership = new DatabaseWorkspaceOwnership($this->database->pdo(), $identity);
                $ownership->acquire();
                try {
                    $workspaceProtection = $ownership->verify();
                } finally {
                    $ownership->release();
                }
            }
            $this->database->adapterStateStore()->setLifecycle($adapterId, 'ACTIVE', [
                'diagnostics' => $diagnostics,
                'canonical_schema' => $canonicalSchema,
                'workspace_ownership' => $workspaceProtection,
                'last_error_code' => null,
                'last_error' => null,
            ]);
            $result = [
                'adapter' => $adapterId,
                'lifecycle' => 'ACTIVE',
                'diagnostics' => $diagnostics,
                'canonical_schema' => $canonicalSchema,
                'workspace_protected' => $workspaceProtection !== null,
            ];
            if ($adapterId === 'postgresql') {
                $state = $this->database->adapterStateStore()->load();
                $config = is_array($state['adapters']['postgresql']['config'] ?? null)
                    ? $state['adapters']['postgresql']['config']
                    : [];
                $result['pgvector'] = $this->publicPgvectorStatus(
                    $diagnostics,
                    ($config['require_pgvector'] ?? true) === true
                );
            }
            return $result;
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof DatabaseAdapterException
                ? $exception->failureCode()
                : 'connection_failed';
            $message = $exception instanceof DatabaseAdapterException
                ? $exception->getMessage()
                : 'Canonical database health check failed.';
            $this->database->adapterStateStore()->recordFailure(
                $adapterId,
                'health_check',
                $failureCode,
                $message,
                true
            );
            throw new DatabaseAdapterException('Canonical database health check failed.', $failureCode, $exception);
        }
    }

    /** @return array<string, mixed> */
    public function enablePostgreSqlPgvector(): array
    {
        if ($this->database->backendId() !== 'postgresql') {
            throw new DatabaseAdapterException(
                'PostgreSQL is not the canonical backend.',
                'not_active'
            );
        }
        $adapter = $this->database->adapterRegistry()->get('postgresql');
        if (!$adapter instanceof DatabaseSetupCapabilityProvisionerInterface) {
            throw new DatabaseAdapterException(
                'The PostgreSQL adapter cannot prepare pgvector.',
                'pgvector_activation_unsupported'
            );
        }

        $diagnostics = [];
        try {
            $diagnostics = $adapter->diagnose($this->database->pdo());
            $diagnostics = $adapter->prepareSetupCapabilities(
                $this->database->pdo(),
                ['require_pgvector' => true],
                $diagnostics
            );
            $stateStore = $this->database->adapterStateStore();
            $state = $stateStore->load();
            $activeConfig = is_array($state['adapters']['postgresql']['config'] ?? null)
                ? $state['adapters']['postgresql']['config']
                : null;
            if (is_array($activeConfig)) {
                $activeConfig['require_pgvector'] = true;
            }
            $details = [
                'diagnostics' => $diagnostics,
                'last_error_code' => null,
                'last_error' => null,
            ];
            if (is_array($activeConfig)) {
                $details['config'] = $activeConfig;
            }
            $stateStore->setLifecycle('postgresql', 'ACTIVE', $details);
            return [
                'pgvector' => $this->publicPgvectorStatus($diagnostics, true),
            ];
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof DatabaseAdapterException
                ? $exception->failureCode()
                : 'pgvector_activation_failed';
            $message = $exception instanceof DatabaseAdapterException
                ? $exception->getMessage()
                : 'PostgreSQL could not enable the pgvector extension.';
            $details = $diagnostics === [] ? [] : ['diagnostics' => $diagnostics];
            $this->database->adapterStateStore()->recordFailure(
                'postgresql',
                'enable_pgvector',
                $failureCode,
                $message,
                false,
                $details
            );
            if ($exception instanceof DatabaseAdapterException) {
                throw $exception;
            }
            throw new DatabaseAdapterException(
                'PostgreSQL could not enable the pgvector extension.',
                'pgvector_activation_failed',
                $exception,
                [
                    'capability' => 'pgvector',
                    'status' => 'available',
                    'required' => true,
                    'available' => true,
                    'installed' => false,
                ]
            );
        }
    }

    /** @param array<string, mixed> $diagnostics */
    private function publicPgvectorStatus(array $diagnostics, bool $required): array
    {
        $state = $this->pgvectorDiagnosticState($diagnostics);
        $version = $state === 'installed'
            ? trim((string) ($diagnostics['pgvector_version']
                ?? ($diagnostics['installed_optional_extensions']['vector'] ?? '')))
            : '';
        return [
            'status' => match ($state) {
                'installed' => 'ready',
                'available' => 'available_not_enabled',
                'missing' => 'package_missing',
                default => 'unknown',
            },
            'version' => $version === '' ? null : $version,
            'required' => $required,
            'required_by_default' => true,
        ];
    }

    /** @param array<string, mixed> $diagnostics */
    private function pgvectorDiagnosticState(array $diagnostics): string
    {
        $state = strtolower(trim((string) ($diagnostics['pgvector_status'] ?? '')));
        if (in_array($state, ['installed', 'available', 'missing'], true)) {
            return $state;
        }
        if (($diagnostics['vector_search'] ?? false) === true
            || trim((string) ($diagnostics['installed_optional_extensions']['vector'] ?? '')) !== '') {
            return 'installed';
        }
        if (is_array($diagnostics['available_optional_extensions'] ?? null)
            && in_array('vector', array_map('strval', $diagnostics['available_optional_extensions']), true)) {
            return 'available';
        }
        return $diagnostics === [] ? 'unknown' : 'missing';
    }

    private function assertTransition(string $adapterId): void
    {
        $source = $this->database->backendId();
        if ($adapterId === 'mysql' && $source !== 'sqlite') {
            throw new DatabaseAdapterException(
                $source === 'mysql' && $this->database->isManualExternalBackend()
                    ? 'A working manually configured MySQL backend was detected; migration is disabled.'
                    : 'MySQL adapter setup requires SQLite as the canonical backend.',
                'invalid_source_backend'
            );
        }
        if ($adapterId === 'postgresql' && !in_array($source, ['sqlite', 'mysql', 'postgresql'], true)) {
            throw new DatabaseAdapterException(
                'PostgreSQL adapter setup requires a healthy SQLite, MySQL, or PostgreSQL canonical backend.',
                'invalid_source_backend'
            );
        }
        if (!in_array($adapterId, ['mysql', 'postgresql'], true)) {
            throw new DatabaseAdapterException('Unsupported adapter transition.', 'unsupported_migration');
        }
    }

    /** @param array<string, mixed> $sourceConfig @param array<string, mixed> $destinationConfig */
    private function assertDistinctPostgreSqlDestination(
        PDO $destination,
        array $sourceConfig,
        array $destinationConfig
    ): void {
        $sourceEndpoint = [
            strtolower(trim((string) ($sourceConfig['host'] ?? ''))),
            (int) ($sourceConfig['port'] ?? 5432),
            strtolower(trim((string) ($sourceConfig['database'] ?? ''))),
        ];
        $destinationEndpoint = [
            strtolower(trim((string) ($destinationConfig['host'] ?? ''))),
            (int) ($destinationConfig['port'] ?? 5432),
            strtolower(trim((string) ($destinationConfig['database'] ?? ''))),
        ];
        if ($sourceConfig !== [] && $sourceEndpoint === $destinationEndpoint) {
            throw new DatabaseAdapterException(
                'The destination is the current canonical PostgreSQL database.',
                'endpoint_unchanged'
            );
        }

        $sourceIdentity = $this->postgresqlDatabaseIdentity($this->database->pdo());
        $destinationIdentity = $this->postgresqlDatabaseIdentity($destination);
        $sameDatabaseOid = $sourceIdentity['database_oid'] !== ''
            && hash_equals($sourceIdentity['database_oid'], $destinationIdentity['database_oid']);
        $sameCluster = $sourceIdentity['system_identifier'] !== ''
            && hash_equals($sourceIdentity['system_identifier'], $destinationIdentity['system_identifier']);
        $sameReportedServer = $sourceIdentity['server_address'] !== ''
            && hash_equals($sourceIdentity['server_address'], $destinationIdentity['server_address'])
            && $sourceIdentity['server_port'] === $destinationIdentity['server_port'];
        if ($sameDatabaseOid && ($sameCluster || $sameReportedServer)) {
            throw new DatabaseAdapterException(
                'The destination resolves to the current canonical PostgreSQL database.',
                'endpoint_unchanged'
            );
        }
    }

    /** @return array{database_oid: string, system_identifier: string, server_address: string, server_port: string} */
    private function postgresqlDatabaseIdentity(PDO $pdo): array
    {
        $row = $pdo->query(<<<'SQL'
SELECT (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS database_oid,
       COALESCE(inet_server_addr()::text, '') AS server_address,
       COALESCE(inet_server_port()::text, '') AS server_port
SQL)->fetch();
        if (!is_array($row)) {
            throw new DatabaseAdapterException(
                'PostgreSQL database identity could not be verified.',
                'endpoint_validation_failed'
            );
        }
        $systemIdentifier = '';
        try {
            $value = $pdo->query('SELECT system_identifier::text FROM pg_control_system()')->fetchColumn();
            $systemIdentifier = is_string($value) ? trim($value) : '';
        } catch (Throwable) {
            // Exact endpoint comparison and the server/database identity pair
            // remain available when pg_control_system() is restricted.
        }
        return [
            'database_oid' => trim((string) ($row['database_oid'] ?? '')),
            'system_identifier' => $systemIdentifier,
            'server_address' => trim((string) ($row['server_address'] ?? '')),
            'server_port' => trim((string) ($row['server_port'] ?? '')),
        ];
    }

    private function dropManagedStandardRagTables(PDO $destination, string $prefix): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new DatabaseAdapterException('PostgreSQL table prefix is invalid.', 'invalid_configuration');
        }
        foreach (['plugin_rag_pgvector_embeddings', 'plugin_rag_standard_chunks'] as $logicalName) {
            $destination->exec('DROP TABLE IF EXISTS "' . $prefix . $logicalName . '"');
        }
    }

    /**
     * Creates a fresh Standard RAG generation without copying any pgvector
     * rows. The worker processes the queued generation after cutover; the
     * copied, now-invalid active generation is disabled until activation of
     * the rebuilt generation.
     *
     * @param array<string, mixed> $sourceDiagnostics
     * @param array<string, mixed> $destinationDiagnostics
     * @return array<string, mixed>
     */
    private function preparePostgreSqlRagRebuild(
        PDO $destination,
        bool $copyCanonical,
        array $sourceDiagnostics,
        array $destinationDiagnostics
    ): array {
        $sourceHasPgvector = $this->pgvectorDiagnosticState($sourceDiagnostics) === 'installed';
        $destinationHasPgvector = $this->pgvectorDiagnosticState($destinationDiagnostics) === 'installed';
        $base = [
            'pgvector_copied' => false,
            'source_pgvector_detected' => $sourceHasPgvector,
            'destination_pgvector_ready' => $destinationHasPgvector,
        ];
        if (!$copyCanonical) {
            return $base + ['status' => 'not_required_empty_destination'];
        }
        if (!$destinationHasPgvector) {
            return $base + ['status' => 'unavailable', 'reason' => 'pgvector_missing'];
        }

        $repository = new RagCoreRepository($destination);
        $engine = $repository->engineInstance('primary');
        $generationRow = $repository->activeGeneration('primary');
        if (!is_array($engine) || !is_array($generationRow)
            || (string) ($generationRow['engine_plugin_id'] ?? '') !== 'openconcept-rag-standard') {
            return $base + ['status' => 'not_required_no_standard_generation'];
        }
        $configuration = is_array($generationRow['configuration'] ?? null)
            ? IndexConfiguration::fromArray($generationRow['configuration'])
            : throw new DatabaseAdapterException(
                'The copied Standard RAG generation configuration is unavailable.',
                'rag_rebuild_failed'
            );
        if ($configuration->embeddingDimensions === null) {
            throw new DatabaseAdapterException(
                'The copied Standard RAG generation has no embedding dimensions.',
                'rag_rebuild_failed'
            );
        }

        $embeddingConfiguration = is_array($configuration->options['embedding'] ?? null)
            ? $configuration->options['embedding']
            : [];
        $embeddingSettings = new AiProviderSettings(
            $this->applicationRoot . DIRECTORY_SEPARATOR . 'storage',
            AiProviderSettings::RAG_EMBEDDING_PROFILE
        );
        $embeddingConfiguration = RagCoreRuntime::resolveApiKeyConfiguration($embeddingConfiguration, $embeddingSettings);
        $embeddingHealth = (new BgeM3EmbeddingProvider($embeddingConfiguration))->healthCheck();
        if (!$embeddingHealth->healthy) {
            $repository->setEngineStatus('primary', 'waiting_for_embedding', false);
            if ($embeddingSettings->hasStoredSettings()) {
                $embeddingSettings->recordRuntimeStatus($embeddingHealth->status, $embeddingHealth->details);
            }
            return $base + [
                'status' => 'waiting_for_embedding',
                'reason' => $embeddingHealth->status,
                'details' => $embeddingHealth->details,
                'activation_required' => false,
            ];
        }
        if ($embeddingSettings->hasStoredSettings()) {
            $embeddingSettings->recordRuntimeStatus($embeddingHealth->status, $embeddingHealth->details);
        }

        $standard = new StandardRagRepository($destination);
        $standard->migrate();
        $generation = IndexGeneration::create($configuration, [
            'reason' => 'postgresql_canonical_replacement',
            'source_generation_id' => (string) ($generationRow['index_generation_id'] ?? ''),
            'started_at' => gmdate(DATE_ATOM),
        ]);
        $standard->rebuildVectorIndex(
            $generation->id,
            $configuration->embeddingDimensions,
            $configuration->distanceMetric
        );
        $repository->setEngineEnabled('primary', false);

        $settingsTable = method_exists($destination, 'tablePrefix')
            ? (string) $destination->tablePrefix() . 'system_settings'
            : 'system_settings';
        $workspaceStatement = $destination->prepare(
            'SELECT setting_value FROM "' . $settingsTable . '" WHERE setting_key = ?'
        );
        $workspaceStatement->execute(['rag.workspace_id']);
        $workspaceValue = (string) $workspaceStatement->fetchColumn();
        if (preg_match('/^[a-f0-9]{32}$/D', $workspaceValue) !== 1) {
            throw new DatabaseAdapterException(
                'The copied RAG workspace identity is unavailable.',
                'rag_rebuild_failed'
            );
        }
        $documents = new OpenConceptRagDocumentGateway(
            $destination,
            'workspace-' . $workspaceValue,
            static fn (array $user, array $page): bool => false
        );
        $repository->saveGeneration('primary', $generation, [
            'workspace_id' => $documents->workspaceId(),
            'started_at' => gmdate(DATE_ATOM),
            'reason' => 'postgresql_canonical_replacement',
        ]);
        $queued = 0;
        foreach ($documents->snapshots() as $snapshot) {
            if ($repository->enqueueGenerationSnapshot('primary', $generation->id, $snapshot)) {
                $queued++;
            }
        }
        $status = 'building';
        if ($queued === 0) {
            $repository->markGeneration($generation->id, 'ready', [
                'documents' => 0,
                'chunks' => 0,
                'completed' => 0,
                'failed' => 0,
            ]);
            $repository->activateGeneration('primary', $generation->id);
            $status = 'active';
        }
        return $base + [
            'status' => $status,
            'generation_id' => $generation->id,
            'queued_documents' => $queued,
            'activation_required' => $queued > 0,
        ];
    }

    /** @return array<string, mixed> */
    private function verifyCanonicalEndpoint(PDO $pdo): array
    {
        try {
            $catalog = CoreSchemaCatalog::load();
            $report = (new CoreSchemaRuntime($pdo, $catalog))->preflight(false);
            if (!is_array($report)) {
                throw new CoreSchemaException(['reason' => 'canonical_schema_upgrade_required']);
            }
            return [
                'driver' => (string) $report['driver'],
                'schema_version' => (int) $report['schema_version'],
                'rag_schema_version' => CoreSchemaRuntime::RAG_SCHEMA_VERSION,
                'artifact_hash' => (string) $report['artifact_hash'],
                'core_tables' => count($report['verified_core_tables'] ?? []),
                'shape' => true,
                'attestation' => true,
            ];
        } catch (CoreSchemaException $exception) {
            throw new DatabaseAdapterException(
                'The database does not match the canonical Core schema.',
                $exception->failureCode(),
                $exception
            );
        } catch (DatabaseAdapterException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The canonical Core schema could not be verified.',
                'canonical_schema_mismatch',
                $exception
            );
        }
    }

    private function migrationSchema(
        LogicalDatabaseSchema $inspected,
        bool $excludeStandardRagDerived = false
    ): LogicalDatabaseSchema
    {
        $core = array_fill_keys(PrefixedPDO::logicalTables(), true);
        $tables = [];
        foreach ($inspected->tables() as $logicalName => $table) {
            if ($excludeStandardRagDerived && in_array($logicalName, [
                'plugin_rag_standard_chunks',
                'plugin_rag_pgvector_embeddings',
            ], true)) {
                continue;
            }
            if (isset($core[$logicalName]) || str_starts_with($logicalName, 'plugin_')) {
                $tables[$logicalName] = $table;
                continue;
            }
            if ($logicalName === 'workspace_identity') {
                continue;
            }
            throw new DatabaseAdapterException(
                'The source database contains an unowned relation.',
                'canonical_schema_mismatch'
            );
        }
        return new LogicalDatabaseSchema($tables);
    }
}
