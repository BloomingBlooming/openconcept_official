<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaMySqlGeneration5Upgrade.php';
require_once __DIR__ . '/CoreSchemaPostgreSqlIndexes.php';
require_once __DIR__ . '/CoreSchemaSignature.php';
require_once __DIR__ . '/CoreSchemaVerifier.php';
require_once __DIR__ . '/LogicalDatabaseSchema.php';
require_once __DIR__ . '/PrefixedPDO.php';
require_once __DIR__ . '/RagCoreRepository.php';
require_once __DIR__ . '/RetrievalRouteStateRepository.php';

/**
 * The sole runtime authority for the mandatory canonical database schema.
 *
 * A schema marker is migration metadata, never evidence that the live shape
 * is valid. Every current boot is checked against the checked-in, driver-
 * specific catalog. Only recognized predecessor units may be advanced, and the
 * catalog fingerprint/version are persisted last after exact verification.
 */
final class CoreSchemaRuntime
{
    public const VERSION_SETTING = 'database.schema_version';
    public const FINGERPRINT_SETTING = 'database.schema_fingerprint';
    public const RAG_VERSION_SETTING = 'rag.core_schema_version';
    public const RAG_SCHEMA_VERSION = 2;

    private const SQLITE_LEASE_SETTING = 'database.schema_migration_lease';

    /** @var list<string> */
    private const RAG_TABLES = [
        'rag_index_engines',
        'rag_index_generations',
        'rag_index_sync',
        'rag_index_jobs',
        'rag_index_runs',
        'rag_settings',
    ];

    /** @var list<string> */
    private const LEGACY_RAG_TABLES = [
        'plugin_rag_engines',
        'plugin_rag_generations',
        'plugin_rag_sync',
        'plugin_rag_jobs',
        'plugin_rag_runs',
        'plugin_rag_settings',
    ];

    /** @var list<string> */
    private const ROUTE_TABLES = [
        'rag_route_state',
        'rag_route_audit',
    ];

    private readonly CoreSchemaVerifier $verifier;
    private bool $baseProvisioningRequired = false;
    private ?CoreSchemaCatalog $generation3Catalog = null;
    private ?CoreSchemaCatalog $generation4Catalog = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly CoreSchemaCatalog $catalog
    ) {
        $this->verifier = new CoreSchemaVerifier($catalog);
    }

    /**
     * Run before any legacy SQLite CREATE/ALTER path. A v3 database is never
     * auto-repaired: drift or missing attestation stops before old migrations
     * can conceal it. Read-only deployment verification also stops on an
     * upgrade requirement before making a schema write.
     *
     * @return array<string, mixed>|null Exact report for an already-current DB.
     */
    public function preflight(bool $writesAllowed): ?array
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return $this->inspectPreflight($writesAllowed);
        }

        // Keep schema inspection and attestation markers in one read snapshot.
        // A competing first boot can otherwise commit between the version and
        // fingerprint reads and make a healthy database look inconsistent.
        // SAVEPOINT also nests inside provisionFreshSqlite's BEGIN IMMEDIATE,
        // which PDO::inTransaction() does not detect on every SQLite build.
        $savepoint = 'core_schema_preflight_' . bin2hex(random_bytes(8));
        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        try {
            return $this->inspectPreflight($writesAllowed);
        } finally {
            // Inspection is read-only. Release only our snapshot, preserving
            // any transaction (and pending writes) owned by the caller.
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        }
    }

    /** @return array<string, mixed>|null */
    private function inspectPreflight(bool $writesAllowed): ?array
    {
        $this->baseProvisioningRequired = false;
        $version = $this->storedVersion(self::VERSION_SETTING);
        $this->assertSupportedVersion($version);
        if ($version === $this->catalog->schemaVersion()) {
            if ($this->setting(CoreSchemaMySqlGeneration5Upgrade::INTENT_SETTING) !== null) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_schema_upgrade_intent_after_activation',
                    'schema_version' => $version,
                ]);
            }
            $report = $this->verifier->verify($this->pdo);
            $this->assertFingerprint();
            $ragVersion = $this->storedVersion(self::RAG_VERSION_SETTING);
            if ($ragVersion !== self::RAG_SCHEMA_VERSION) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_rag_schema_marker_mismatch',
                    'stored_rag_schema_version' => $ragVersion,
                    'target_rag_schema_version' => self::RAG_SCHEMA_VERSION,
                ]);
            }
            return $report;
        }

        if (!$writesAllowed) {
            throw new CoreSchemaException([
                'reason' => 'canonical_schema_upgrade_required',
                'stored_schema_version' => $version,
                'target_schema_version' => $this->catalog->schemaVersion(),
            ]);
        }

        if ($version === 3) {
            $this->assertSealedGeneration3UpgradeSource();
            return null;
        }
        if ($version === 4 && $this->catalog->schemaVersion() === 5) {
            $this->assertSealedGeneration4UpgradeSourceOrProgress();
            return null;
        }

        // V2.1 upgrades only the recognized V2 Core boundary. A missing Core
        // table is corruption, not an invitation for CREATE IF NOT EXISTS to
        // reconstruct an apparently valid but data-losing database. The sole
        // zero-table exception is a genuinely fresh SQLite installation.
        $this->assertLegacyBaseEligibility();

        $storedFingerprint = $this->setting(self::FINGERPRINT_SETTING);
        if ($storedFingerprint !== null && $storedFingerprint !== '') {
            throw new CoreSchemaException([
                'reason' => hash_equals($this->catalog->artifactHash(), $storedFingerprint)
                    ? 'canonical_schema_attestation_inconsistent'
                    : 'legacy_schema_fingerprint_unrecognized',
                'stored_schema_version' => $version,
            ]);
        }
        return null;
    }

    public function requiresBaseProvisioning(): bool
    {
        return $this->baseProvisioningRequired;
    }

    /**
     * Provision the Base 28 of a genuinely empty SQLite database while an
     * SQLite write transaction serializes competing first boots. This is the
     * only path allowed to execute the legacy CREATE/ALTER bundle.
     *
     * @param callable(): void $provisioner
     */
    public function provisionFreshSqlite(callable $provisioner): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || !$this->baseProvisioningRequired) {
            throw new CoreSchemaException(['reason' => 'fresh_base_provisioning_not_authorized']);
        }
        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'fresh_base_provisioning_inside_transaction']);
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            // A competing first boot may have completed while BEGIN IMMEDIATE
            // waited. Re-evaluate under the database write lock.
            $current = $this->preflight(true);
            if (!is_array($current) && $this->baseProvisioningRequired) {
                $provisioner();
                $this->verifier->verifyTables($this->pdo, $this->baseTables());
            }
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException([
                'reason' => 'fresh_base_provisioning_failed',
            ], $exception);
        }
    }

    private function assertLegacyBaseEligibility(): void
    {
        $baseTables = $this->baseTables();
        $basePresent = $this->presentTables($baseTables);
        if ($basePresent !== [] && count($basePresent) !== count($baseTables)) {
            throw new CoreSchemaException([
                'reason' => 'partial_base_core_schema',
                'present_tables' => $basePresent,
                'missing_tables' => array_values(array_diff($baseTables, $basePresent)),
            ]);
        }
        $knownNonBase = array_merge(self::RAG_TABLES, self::ROUTE_TABLES, self::LEGACY_RAG_TABLES);
        $presentNonBase = $this->presentTables($knownNonBase);
        $ownedRelations = [];
        $unclassifiedRelations = [];
        foreach ((new CoreSchemaSignature())->relations($this->pdo) as $relation) {
            if (($relation['scoped'] ?? false) !== true
                || ($relation['classification'] ?? null) === 'engine_metadata') {
                continue;
            }
            $ownedRelations[] = (string) ($relation['logical_name'] ?? '');
            if (($relation['classification'] ?? null) === 'unclassified') {
                $unclassifiedRelations[] = (string) ($relation['logical_name'] ?? '');
            }
        }
        if ($unclassifiedRelations !== []) {
            throw new CoreSchemaException([
                'reason' => 'legacy_schema_contains_unclassified_relations',
                'relations' => array_values(array_unique($unclassifiedRelations)),
            ]);
        }
        if (count($basePresent) === count($baseTables)) {
            // V2.0 and V2.1 share this Base 28 physical contract. A healthy
            // legacy installation therefore verifies exactly before any new
            // RAG/Route DDL. Missing columns or indexes are drift, not an
            // implicit legacy migration candidate.
            $this->verifier->verifyTables($this->pdo, $baseTables);
            return;
        }
        if ($presentNonBase !== [] || $ownedRelations !== []) {
            throw new CoreSchemaException([
                'reason' => 'base_core_schema_missing_with_existing_relations',
                'present_non_base_tables' => $presentNonBase,
                'scoped_relations' => array_values(array_unique($ownedRelations)),
            ]);
        }
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new CoreSchemaException([
                'reason' => 'external_canonical_schema_not_provisioned',
            ]);
        }
        $this->baseProvisioningRequired = true;
    }

    /**
     * Upgrade a recognized old schema, attest it, and verify it again.
     *
     * @return array<string, mixed>
     */
    public function boot(bool $writesAllowed): array
    {
        $current = $this->preflight($writesAllowed);
        if (is_array($current)) {
            return $current;
        }

        // A MySQL v4->v5 migration spans 32 independently atomic ALTER TABLE
        // statements. Drain the exact Database lifetime lease before taking
        // GET_LOCK so a waiter cannot retain a shared writer lease while it
        // waits behind the migration owner. The same PDO is downgraded to a
        // shared lease after both success and ordinary failure.
        $mysqlWriterDrain = $this->requiresMySqlGeneration5Upgrade();
        if ($mysqlWriterDrain) {
            if (!$this->pdo instanceof PrefixedPDO) {
                throw new CoreSchemaException(['reason' => 'schema_migration_writer_drain_unavailable']);
            }
            try {
                $this->pdo->beginAdapterCutover();
            } catch (Throwable $exception) {
                if ($exception instanceof CoreSchemaException) {
                    throw $exception;
                }
                throw new CoreSchemaException([
                    'reason' => 'schema_migration_writer_drain_unavailable',
                ], $exception);
            }
        }

        $release = null;
        try {
            $release = $this->acquireMigrationLock();
            // Another request may have completed the migration while this one
            // was acquiring the database-scoped lock.
            $current = $this->preflight($writesAllowed);
            if (is_array($current)) {
                return $current;
            }

            $globalVersion = $this->storedVersion(self::VERSION_SETTING);
            $generationStages = [];
            if ($globalVersion === 3) {
                $generation4 = $this->upgradeSealedGeneration3ToGeneration4Atomically();
                if ($this->catalog->schemaVersion() === 4) {
                    return $generation4;
                }
                $generationStages[] = $generation4['migration'] ?? [];
                $globalVersion = $this->storedVersion(self::VERSION_SETTING);
            }
            if ($globalVersion === 4 && $this->catalog->schemaVersion() === 5) {
                $generation5 = $this->upgradeSealedGeneration4ToGeneration5();
                if ($generationStages === []) {
                    return $generation5;
                }
                $stage5 = $generation5['migration'] ?? [];
                $generation5['migration'] = [
                    'from_schema_version' => 3,
                    'to_schema_version' => 5,
                    // Each sealed predecessor step is atomic (or, for the
                    // MySQL physical widening, explicitly resumable), but the
                    // complete v3->v5 chain crosses the committed v4 marker.
                    // Do not describe that two-stage chain as one transaction.
                    'atomic' => false,
                    'physical_atomic' => false,
                    'activation_atomic' => false,
                    'crash_resumable' => true,
                    'stages' => array_merge($generationStages, [$stage5]),
                ];
                return $generation5;
            }
            $this->verifier->verifyTables($this->pdo, $this->baseTables());
            // Trigger side effects must be rejected before the first RAG/Route
            // CREATE or legacy data-adoption write, not merely by the final
            // full-schema proof after those writes have already executed.
            $this->verifier->verifyOperationalTriggers($this->pdo);

            $ragPlan = $this->planRagUpgrade($globalVersion);
            $routePlan = $this->planRouteUpgrade($globalVersion);

            if ($ragPlan === 'create' || $ragPlan === 'adopt_legacy') {
                try {
                    (new RagCoreRepository($this->pdo))->migrate();
                } catch (Throwable $exception) {
                    throw new CoreSchemaException([
                        'reason' => 'rag_core_schema_migration_failed',
                        'migration_plan' => $ragPlan,
                    ], $exception);
                }
            }
            if ($routePlan === 'create') {
                try {
                    (new RetrievalRouteStateRepository($this->pdo))->migrate();
                } catch (Throwable $exception) {
                    throw new CoreSchemaException([
                        'reason' => 'retrieval_route_schema_migration_failed',
                    ], $exception);
                }
            }

            // The exact live shape, not the migration methods or markers, is
            // the proof used to authorize the v3 attestation.
            $report = $this->verifier->verify($this->pdo);
            $this->writeAttestation();
            $this->assertFingerprint();
            if ($this->storedVersion(self::VERSION_SETTING) !== $this->catalog->schemaVersion()
                || $this->storedVersion(self::RAG_VERSION_SETTING) !== self::RAG_SCHEMA_VERSION) {
                throw new CoreSchemaException(['reason' => 'canonical_schema_attestation_not_persisted']);
            }

            // Detect a concurrent or trigger-driven DDL change that happened
            // between the pre-attestation verification and metadata commit.
            return $this->verifier->verify($this->pdo) + [
                'migration' => [
                    'from_schema_version' => $globalVersion,
                    'rag' => $ragPlan,
                    'route' => $routePlan,
                ],
            ];
        } finally {
            try {
                if ($release instanceof Closure) {
                    $release();
                }
            } finally {
                // Never strand the process-wide EX writer drain merely because
                // advisory-lock cleanup failed. Either cleanup failure remains
                // fail-closed, but both release paths are always attempted.
                if ($mysqlWriterDrain) {
                    try {
                        $this->pdo->finishAdapterCutover(false);
                    } catch (Throwable $exception) {
                        throw new CoreSchemaException([
                            'reason' => 'schema_migration_writer_drain_release_failed',
                        ], $exception);
                    }
                }
            }
        }
    }

    private function requiresMySqlGeneration5Upgrade(): bool
    {
        if ($this->catalog->schemaVersion() !== 5
            || (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }
        return in_array($this->storedVersion(self::VERSION_SETTING), [3, 4], true);
    }

    /** @return list<string> */
    private function baseTables(): array
    {
        return array_values(array_diff(
            PrefixedPDO::logicalTables(),
            self::RAG_TABLES,
            self::ROUTE_TABLES
        ));
    }

    private function planRagUpgrade(int $globalVersion): string
    {
        $ragVersion = $this->storedVersion(self::RAG_VERSION_SETTING);
        if ($ragVersion > self::RAG_SCHEMA_VERSION) {
            throw new CoreSchemaException([
                'reason' => 'newer_rag_core_schema',
                'stored_rag_schema_version' => $ragVersion,
            ]);
        }

        $corePresent = $this->presentTables(self::RAG_TABLES);
        $legacyPresent = $this->presentTables(self::LEGACY_RAG_TABLES);
        if ($legacyPresent !== [] && count($legacyPresent) !== count(self::LEGACY_RAG_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'partial_legacy_rag_schema',
                'present_tables' => $legacyPresent,
            ]);
        }
        if ($legacyPresent !== [] && $corePresent !== []) {
            throw new CoreSchemaException([
                'reason' => 'ambiguous_rag_schema_ownership',
                'core_tables' => $corePresent,
                'legacy_tables' => $legacyPresent,
            ]);
        }
        if ($corePresent !== [] && count($corePresent) !== count(self::RAG_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'partial_rag_core_schema',
                'present_tables' => $corePresent,
            ]);
        }

        if ($globalVersion >= 2 && count($corePresent) !== count(self::RAG_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'attested_schema_missing_rag_core',
                'stored_schema_version' => $globalVersion,
            ]);
        }
        if ($ragVersion > 0 && count($corePresent) !== count(self::RAG_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'rag_marker_without_required_tables',
                'stored_rag_schema_version' => $ragVersion,
            ]);
        }
        if (count($corePresent) === count(self::RAG_TABLES)) {
            $this->verifier->verifyTables($this->pdo, self::RAG_TABLES);
            return 'attest_existing';
        }
        if (count($legacyPresent) === count(self::LEGACY_RAG_TABLES)) {
            if ($ragVersion !== 0 || $globalVersion >= 2) {
                throw new CoreSchemaException(['reason' => 'legacy_rag_schema_not_migratable']);
            }
            return 'adopt_legacy';
        }
        return 'create';
    }

    private function planRouteUpgrade(int $globalVersion): string
    {
        $present = $this->presentTables(self::ROUTE_TABLES);
        if ($present !== [] && count($present) !== count(self::ROUTE_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'partial_retrieval_route_schema',
                'present_tables' => $present,
            ]);
        }
        if ($globalVersion >= 2 && count($present) !== count(self::ROUTE_TABLES)) {
            throw new CoreSchemaException([
                'reason' => 'attested_schema_missing_retrieval_route',
                'stored_schema_version' => $globalVersion,
            ]);
        }
        if (count($present) === count(self::ROUTE_TABLES)) {
            $this->verifier->verifyTables($this->pdo, self::ROUTE_TABLES);
            return 'attest_existing';
        }
        return 'create';
    }

    /** @param list<string> $logicalNames @return list<string> */
    private function presentTables(array $logicalNames): array
    {
        $present = [];
        foreach ($logicalNames as $logicalName) {
            if ($this->tableExists($logicalName)) {
                $present[] = $logicalName;
            }
        }
        sort($present, SORT_STRING);
        return $present;
    }

    private function assertSupportedVersion(int $version): void
    {
        if ($version > $this->catalog->schemaVersion()) {
            throw new CoreSchemaException([
                'reason' => 'newer_canonical_schema',
                'stored_schema_version' => $version,
                'target_schema_version' => $this->catalog->schemaVersion(),
            ]);
        }
    }

    private function assertSealedGeneration3UpgradeSource(): void
    {
        $this->assertSealedUpgradeSource($this->generation3Catalog());
    }

    private function generation3Catalog(): CoreSchemaCatalog
    {
        return $this->generation3Catalog ??= CoreSchemaCatalog::loadHistorical(3);
    }

    private function assertSealedGeneration4UpgradeSourceOrProgress(): void
    {
        $catalog = $this->generation4Catalog();
        $this->assertFingerprintFor($catalog);
        $this->assertRagVersion();
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            (new CoreSchemaMySqlGeneration5Upgrade($this->pdo, $catalog, $this->catalog))->preflight();
            return;
        }
        (new CoreSchemaVerifier($catalog))->verify($this->pdo);
    }

    private function generation4Catalog(): CoreSchemaCatalog
    {
        if ($this->catalog->schemaVersion() === 4) {
            return $this->catalog;
        }
        return $this->generation4Catalog ??= CoreSchemaCatalog::loadHistorical(4);
    }

    private function assertSealedUpgradeSource(CoreSchemaCatalog $catalog): void
    {
        $this->assertFingerprintFor($catalog);
        (new CoreSchemaVerifier($catalog))->verify($this->pdo);
        $this->assertRagVersion();
    }

    private function assertRagVersion(): void
    {
        $ragVersion = $this->storedVersion(self::RAG_VERSION_SETTING);
        if ($ragVersion !== self::RAG_SCHEMA_VERSION) {
            throw new CoreSchemaException([
                'reason' => 'canonical_rag_schema_marker_mismatch',
                'stored_rag_schema_version' => $ragVersion,
                'target_rag_schema_version' => self::RAG_SCHEMA_VERSION,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function upgradeSealedGeneration3ToGeneration4Atomically(): array
    {
        $this->assertSealedGeneration3UpgradeSource();
        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_generation_upgrade_inside_transaction']);
        }

        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $generation4 = $this->generation4Catalog();
        $generation4Verifier = new CoreSchemaVerifier($generation4);
        $this->pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $this->lockPostgreSqlCoreTables($this->generation3Catalog()->tableNames('pgsql'));
            }
            // The preflight proof is deliberately repeated after the
            // transaction (and PostgreSQL table locks) begins. No DDL is
            // authorized from stale, pre-lock evidence.
            $this->assertSealedGeneration3UpgradeSource();
            if ($driver === 'pgsql') {
                CoreSchemaPostgreSqlIndexes::createGeneration4($this->pdo, $this->prefix());
            }
            $generation4Verifier->verify($this->pdo);
            $this->writeAttestationValuesFor($generation4);
            $this->assertFingerprintFor($generation4);
            if ($this->storedVersion(self::VERSION_SETTING) !== $generation4->schemaVersion()
                || $this->storedVersion(self::RAG_VERSION_SETTING) !== self::RAG_SCHEMA_VERSION) {
                throw new CoreSchemaException(['reason' => 'canonical_schema_attestation_not_persisted']);
            }
            // Prove the final physical state and operational trigger inventory
            // while every PostgreSQL CREATE INDEX and every marker update are
            // still protected by the same transaction.
            $generation4Verifier->verify($this->pdo);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException([
                'reason' => 'canonical_generation_upgrade_failed',
                'from_schema_version' => 3,
                'target_schema_version' => 4,
            ], $exception);
        }

        return $generation4Verifier->verify($this->pdo) + [
            'migration' => [
                'from_schema_version' => 3,
                'to_schema_version' => 4,
                'atomic' => true,
                'physical_atomic' => true,
                'activation_atomic' => true,
                'postgresql_indexes_added' => $driver === 'pgsql'
                    ? count(CoreSchemaPostgreSqlIndexes::generation4Additions())
                    : 0,
            ],
        ];
    }

    /**
     * Backward-compatible private entrypoint retained for focused reflection
     * tests written when generation 4 was current.
     *
     * @return array<string,mixed>
     */
    private function upgradeSealedGeneration3Atomically(): array
    {
        return $this->upgradeSealedGeneration3ToGeneration4Atomically();
    }

    /** @return array<string,mixed> */
    private function upgradeSealedGeneration4ToGeneration5(): array
    {
        if ($this->catalog->schemaVersion() !== 5) {
            throw new CoreSchemaException(['reason' => 'canonical_generation_upgrade_target_invalid']);
        }
        $source = $this->generation4Catalog();
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $upgrade = new CoreSchemaMySqlGeneration5Upgrade($this->pdo, $source, $this->catalog);
            return $upgrade->upgrade(
                function (): void {
                    $this->writeAttestationValuesFor($this->catalog);
                },
                function (): void {
                    $this->assertAttestationFor($this->catalog);
                }
            );
        }

        $this->assertSealedUpgradeSource($source);
        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_generation_upgrade_inside_transaction']);
        }
        $targetVerifier = new CoreSchemaVerifier($this->catalog);
        $this->pdo->beginTransaction();
        try {
            if ($driver === 'pgsql') {
                $this->lockPostgreSqlCoreTables($source->tableNames('pgsql'));
            } elseif ($driver !== 'sqlite') {
                throw new CoreSchemaException(['reason' => 'driver_profile_unsupported', 'driver' => $driver]);
            }
            // Re-prove sealed v4 after the database write/table locks begin.
            $this->assertSealedUpgradeSource($source);
            // SQLite TEXT and PostgreSQL TIMESTAMP WITHOUT TIME ZONE already
            // preserve six fractional digits; v5 changes no physical object.
            $targetVerifier->verify($this->pdo);
            $this->writeAttestationValuesFor($this->catalog);
            $this->assertAttestationFor($this->catalog);
            $targetVerifier->verify($this->pdo);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException([
                'reason' => 'canonical_generation_upgrade_failed',
                'from_schema_version' => 4,
                'target_schema_version' => 5,
            ], $exception);
        }

        return $targetVerifier->verify($this->pdo) + [
            'migration' => [
                'from_schema_version' => 4,
                'to_schema_version' => 5,
                'atomic' => true,
                'physical_atomic' => true,
                'activation_atomic' => true,
                'changed_tables' => 0,
                'changed_columns' => 0,
            ],
        ];
    }

    /** @param list<string> $logicalNames */
    private function lockPostgreSqlCoreTables(array $logicalNames): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || !$this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_schema_lock_context_invalid']);
        }
        sort($logicalNames, SORT_STRING);
        $prefix = $this->prefix();
        $quoted = [];
        foreach ($logicalNames as $logicalName) {
            $physicalName = $prefix . $logicalName;
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $physicalName) !== 1) {
                throw new CoreSchemaException(['reason' => 'postgresql_identifier_unsupported']);
            }
            $quoted[] = '"' . $physicalName . '"';
        }
        if ($quoted === []) {
            throw new CoreSchemaException(['reason' => 'canonical_schema_lock_set_empty']);
        }
        $this->pdo->exec('LOCK TABLE ' . implode(', ', $quoted) . ' IN ACCESS EXCLUSIVE MODE');
    }

    private function assertFingerprint(): void
    {
        $this->assertFingerprintFor($this->catalog);
    }

    private function assertFingerprintFor(CoreSchemaCatalog $catalog): void
    {
        $fingerprint = $this->setting(self::FINGERPRINT_SETTING);
        if (!is_string($fingerprint)
            || !hash_equals($catalog->artifactHash(), $fingerprint)) {
            throw new CoreSchemaException([
                'reason' => 'canonical_schema_fingerprint_mismatch',
                'schema_version' => $catalog->schemaVersion(),
            ]);
        }
    }

    private function assertAttestationFor(CoreSchemaCatalog $catalog): void
    {
        $this->assertFingerprintFor($catalog);
        $this->assertRagVersion();
        if ($this->storedVersion(self::VERSION_SETTING) !== $catalog->schemaVersion()) {
            throw new CoreSchemaException(['reason' => 'canonical_schema_attestation_not_persisted']);
        }
    }

    private function writeAttestation(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'schema_attestation_inside_transaction']);
        }
        $this->pdo->beginTransaction();
        try {
            $this->writeAttestationValues();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof CoreSchemaException) {
                throw $exception;
            }
            throw new CoreSchemaException(['reason' => 'schema_attestation_write_failed'], $exception);
        }
    }

    private function writeAttestationValues(): void
    {
        $this->writeAttestationValuesFor($this->catalog);
    }

    private function writeAttestationValuesFor(CoreSchemaCatalog $catalog): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'schema_attestation_outside_transaction']);
        }
        $this->writeSetting(self::RAG_VERSION_SETTING, (string) self::RAG_SCHEMA_VERSION);
        $this->writeSetting(self::FINGERPRINT_SETTING, $catalog->artifactHash());
        // Global version is deliberately last. A current version always means
        // the complete shape and fingerprint were already written.
        $this->writeSetting(self::VERSION_SETTING, (string) $catalog->schemaVersion());
    }

    /** @return Closure(): void */
    private function acquireMigrationLock(): Closure
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $databaseScope = match ($driver) {
            'mysql' => (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn(),
            'pgsql' => (string) $this->pdo->query('SELECT current_database()')->fetchColumn(),
            default => 'sqlite-local',
        };
        $lockName = 'openconcept_core_schema_' . substr(
            hash('sha256', $databaseScope . ':' . $this->prefix() . ':' . $this->catalog->schemaVersion()),
            0,
            32
        );
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
            $statement->execute([$lockName]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new CoreSchemaException(['reason' => 'schema_migration_lock_unavailable']);
            }
            return function () use ($lockName): void {
                try {
                    $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                    $statement->execute([$lockName]);
                } catch (Throwable) {
                }
            };
        }
        if ($driver === 'pgsql') {
            $statement = $this->pdo->prepare('SELECT pg_try_advisory_lock(hashtext(?))');
            $statement->execute([$lockName]);
            $locked = $statement->fetchColumn();
            if (!in_array(strtolower((string) $locked), ['1', 't', 'true'], true)) {
                throw new CoreSchemaException(['reason' => 'schema_migration_lock_unavailable']);
            }
            return function () use ($lockName): void {
                try {
                    $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))');
                    $statement->execute([$lockName]);
                } catch (Throwable) {
                }
            };
        }
        if ($driver !== 'sqlite') {
            throw new CoreSchemaException(['reason' => 'driver_profile_unsupported', 'driver' => $driver]);
        }

        $token = bin2hex(random_bytes(16));
        $lease = json_encode([
            'token' => $token,
            'target_schema_version' => $this->catalog->schemaVersion(),
            'acquired_at' => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $deadline = microtime(true) + 30.0;
        while (true) {
            if ($this->tryAcquireSqliteMigrationLease($lease)) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new CoreSchemaException(['reason' => 'schema_migration_lock_unavailable']);
            }
            usleep(100_000);
        }

        return function () use ($lease): void {
            try {
                $this->pdo->exec('BEGIN IMMEDIATE');
                $statement = $this->pdo->prepare(
                    'DELETE FROM system_settings WHERE setting_key = ? AND setting_value = ?'
                );
                $statement->execute([self::SQLITE_LEASE_SETTING, $lease]);
                $this->pdo->exec('COMMIT');
            } catch (Throwable) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
        };
    }

    private function tryAcquireSqliteMigrationLease(string $lease): bool
    {
        $this->validateSqliteMigrationLease($lease);
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $existing = $this->setting(self::SQLITE_LEASE_SETTING);
            if (is_string($existing) && $existing !== '') {
                $this->validateSqliteMigrationLease($existing);
                $this->pdo->exec('COMMIT');
                return false;
            }
            $this->writeSetting(self::SQLITE_LEASE_SETTING, $lease);
            $this->pdo->exec('COMMIT');
            return true;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    }

    private function validateSqliteMigrationLease(string $lease): void
    {
        try {
            $decoded = json_decode($lease, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new CoreSchemaException(['reason' => 'schema_migration_lease_invalid'], $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new CoreSchemaException(['reason' => 'schema_migration_lease_invalid']);
        }
        $keys = array_keys($decoded);
        sort($keys, SORT_STRING);
        if ($keys !== ['acquired_at', 'target_schema_version', 'token']
            || !is_string($decoded['token'])
            || preg_match('/^[a-f0-9]{32}$/D', $decoded['token']) !== 1
            || !is_int($decoded['target_schema_version'])
            || $decoded['target_schema_version'] !== $this->catalog->schemaVersion()
            || !is_int($decoded['acquired_at'])
            || $decoded['acquired_at'] < 1) {
            throw new CoreSchemaException(['reason' => 'schema_migration_lease_invalid']);
        }
    }

    private function storedVersion(string $setting): int
    {
        $value = $this->setting($setting);
        if ($value === null || $value === '') {
            return 0;
        }
        if (preg_match('/^[0-9]{1,9}$/D', $value) !== 1
            || (string) (int) $value !== $value) {
            throw new CoreSchemaException([
                'reason' => 'schema_version_marker_invalid',
                'setting' => $setting,
            ]);
        }
        return (int) $value;
    }

    private function setting(string $key): ?string
    {
        if (!$this->tableExists('system_settings')) {
            return null;
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT setting_value FROM system_settings WHERE setting_key = ?'
            );
            $statement->execute([$key]);
            $value = $statement->fetchColumn();
            return $value === false || $value === null ? null : (string) $value;
        } catch (Throwable $exception) {
            throw new CoreSchemaException(['reason' => 'schema_metadata_unreadable'], $exception);
        }
    }

    private function writeSetting(string $key, string $value): void
    {
        $sql = match ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'sqlite' => <<<'SQL'
INSERT INTO system_settings (setting_key, setting_value, updated_at)
VALUES (?, ?, CURRENT_TIMESTAMP)
ON CONFLICT(setting_key) DO UPDATE
SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP
SQL,
            'mysql' => <<<'SQL'
INSERT INTO system_settings (setting_key, setting_value, updated_at)
VALUES (?, ?, CURRENT_TIMESTAMP)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
SQL,
            'pgsql' => <<<'SQL'
INSERT INTO system_settings (setting_key, setting_value, updated_at)
VALUES (?, ?, CURRENT_TIMESTAMP)
ON CONFLICT (setting_key) DO UPDATE
SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP
SQL,
            default => throw new CoreSchemaException(['reason' => 'driver_profile_unsupported']),
        };
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$key, $value]);
    }

    private function tableExists(string $logicalName): bool
    {
        $physicalName = $this->prefix() . $logicalName;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $statement = match ($driver) {
            'sqlite' => $this->pdo->prepare(
                "SELECT 1 FROM sqlite_schema WHERE type = 'table' AND name = ?"
            ),
            'mysql' => $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables '
                . "WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'"
            ),
            'pgsql' => $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables '
                . "WHERE table_schema = current_schema() AND table_name = ? AND table_type = 'BASE TABLE'"
            ),
            default => throw new CoreSchemaException(['reason' => 'driver_profile_unsupported', 'driver' => $driver]),
        };
        $statement->execute([$physicalName]);
        return $statement->fetchColumn() !== false;
    }

    private function prefix(): string
    {
        $prefix = method_exists($this->pdo, 'tablePrefix')
            ? (string) $this->pdo->tablePrefix()
            : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new CoreSchemaException(['reason' => 'table_prefix_invalid']);
        }
        return $prefix;
    }
}
