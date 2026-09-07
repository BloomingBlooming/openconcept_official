<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaRuntime.php';
require_once __DIR__ . '/CoreSchemaSignature.php';
require_once __DIR__ . '/CoreSchemaVerifier.php';
require_once __DIR__ . '/PrefixedPDO.php';

/**
 * Fail-closed boundary for the retained, additive MySQL migration helpers.
 *
 * These helpers predate the current canonical Core artifact. They may still add a
 * small, explicitly registered set of legacy columns/tables, but they must
 * never act as a repair path for a database that already claims to be v3.
 */
final class LegacyMySqlCoreDdlGuard
{
    public const PROFILE_USER = 'user_profile';
    public const PROFILE_AI_CHAT = 'ai_chat';
    public const PROFILE_DEPARTMENT_ACL = 'multiple_department_access';
    public const PROFILE_TRANSLATION = 'translation';
    public const PROFILE_PUBLIC_SITE = 'public_site';
    public const PROFILE_DEPLOYMENT_INLINE = 'deployment_inline';

    /** @var array<string, list<string>> */
    private const PROFILE_TABLES = [
        self::PROFILE_USER => ['users'],
        self::PROFILE_AI_CHAT => ['ai_conversations', 'ai_chat_turns'],
        self::PROFILE_DEPARTMENT_ACL => [
            'page_access_departments',
            'rag_source_access_departments',
        ],
        self::PROFILE_TRANSLATION => ['users', 'pages', 'rag_source_documents'],
        self::PROFILE_PUBLIC_SITE => ['page_public_shares', 'page_public_share_pages'],
        self::PROFILE_DEPLOYMENT_INLINE => ['users', 'pages', 'rag_source_documents'],
    ];

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
    private const ROUTE_TABLES = ['rag_route_state', 'rag_route_audit'];

    private bool $released = false;
    private bool $finished = false;

    /**
     * @param array{version:int,fingerprint:?string,rag_version:int} $initialMarkers
     */
    private function __construct(
        private readonly PrefixedPDO $pdo,
        private readonly string $prefix,
        private readonly string $profile,
        private readonly CoreSchemaCatalog $catalog,
        private readonly CoreSchemaCatalog $validationCatalog,
        private readonly bool $current,
        private readonly array $initialMarkers,
        private readonly string $lockName
    ) {
    }

    public static function begin(PDO $pdo, string $prefix, string $profile): self
    {
        if (!isset(self::PROFILE_TABLES[$profile])) {
            throw new InvalidArgumentException('Unknown legacy Core DDL profile.');
        }
        if (!$pdo instanceof PrefixedPDO
            || (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->tablePrefix() !== $prefix) {
            throw new CoreSchemaException([
                'reason' => 'legacy_core_ddl_requires_prefixed_mysql',
                'profile' => $profile,
            ]);
        }
        // MySQL 8.4 may expose information_schema result labels in upper
        // case when PDO::ATTR_CASE is left at its driver default. Canonical
        // introspection uses stable lower-case labels, so pin the guarded
        // connection before any catalog evidence is read.
        if (!$pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER)) {
            throw new CoreSchemaException([
                'reason' => 'legacy_core_ddl_case_normalization_failed',
                'profile' => $profile,
            ]);
        }

        $catalog = CoreSchemaCatalog::load();
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new CoreSchemaException(['reason' => 'legacy_core_ddl_database_unresolved']);
        }
        $lockName = 'openconcept_legacy_core_ddl_' . substr(
            hash('sha256', $database . ':' . $prefix),
            0,
            24
        );
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 30)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new CoreSchemaException(['reason' => 'legacy_core_ddl_lock_unavailable']);
        }

        try {
            // No legacy helper may execute even additive DDL/data work while
            // an unrecognized Core/plugin operational trigger can add hidden
            // side effects. Current and legacy marker paths share this gate.
            (new CoreSchemaVerifier($catalog))->verifyOperationalTriggers($pdo);
            $markers = self::markers($pdo);
            $authorization = self::authorize($pdo, $catalog, $markers, $profile);
            return new self(
                $pdo,
                $prefix,
                $profile,
                $catalog,
                $authorization['validation_catalog'],
                $authorization['current'],
                $markers,
                $lockName
            );
        } catch (Throwable $exception) {
            self::releaseNamedLock($pdo, $lockName);
            throw $exception;
        }
    }

    /**
     * A current, exactly attested database must not receive even idempotent
     * CREATE/ALTER statements from the legacy helpers.
     */
    public function allowsSchemaWrites(): bool
    {
        return !$this->current;
    }

    public function validationSchemaVersion(): int
    {
        return $this->validationCatalog->schemaVersion();
    }

    /**
     * Re-prove a current schema after the helper's data-only work and ensure a
     * legacy helper did not create or alter canonical attestation metadata.
     */
    public function finish(): void
    {
        if ($this->finished) {
            throw new LogicException('Legacy Core DDL guard was already finished.');
        }
        try {
            $markers = self::markers($this->pdo);
            if ($this->current) {
                if ($markers !== $this->initialMarkers) {
                    throw new CoreSchemaException([
                        'reason' => 'canonical_schema_attestation_changed_during_legacy_helper',
                        'profile' => $this->profile,
                    ]);
                }
                (new CoreSchemaRuntime($this->pdo, $this->catalog))->preflight(false);
            } else {
                if ($markers !== $this->initialMarkers) {
                    throw new CoreSchemaException([
                        'reason' => 'legacy_schema_attestation_changed_during_helper',
                        'profile' => $this->profile,
                    ]);
                }
                // Only the tables owned by this helper are removed from the
                // legacy preflight comparison. Before reporting success they
                // must have converged completely to the canonical profile;
                // a familiar column name on an incompatible table is not
                // migration evidence.
                (new CoreSchemaVerifier($this->validationCatalog))->verifyTables(
                    $this->pdo,
                    self::PROFILE_TABLES[$this->profile],
                    false
                );
                // Re-run the read-only legacy boundary after MySQL's implicit
                // DDL commits so an unexpected relation cannot be concealed.
                self::authorizeLegacy(
                    $this->pdo,
                    $this->catalog,
                    $markers,
                    $this->profile,
                    $this->validationCatalog
                );
            }
            $this->finished = true;
        } finally {
            $this->release();
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * @param array{version:int,fingerprint:?string,rag_version:int} $markers
     * @return array{current:bool,validation_catalog:CoreSchemaCatalog}
     */
    private static function authorize(
        PrefixedPDO $pdo,
        CoreSchemaCatalog $catalog,
        array $markers,
        string $profile
    ): array {
        $version = $markers['version'];
        $fingerprint = $markers['fingerprint'];
        if ($version > $catalog->schemaVersion()) {
            throw new CoreSchemaException([
                'reason' => 'newer_canonical_schema',
                'stored_schema_version' => $version,
                'target_schema_version' => $catalog->schemaVersion(),
            ]);
        }
        if ($markers['rag_version'] > CoreSchemaRuntime::RAG_SCHEMA_VERSION) {
            throw new CoreSchemaException([
                'reason' => 'newer_rag_core_schema',
                'stored_rag_schema_version' => $markers['rag_version'],
            ]);
        }
        if ($version === $catalog->schemaVersion()) {
            if (!is_string($fingerprint) || $fingerprint === '') {
                throw new CoreSchemaException([
                    'reason' => 'canonical_schema_attestation_inconsistent',
                    'stored_schema_version' => $version,
                ]);
            }
            (new CoreSchemaRuntime($pdo, $catalog))->preflight(false);
            return ['current' => true, 'validation_catalog' => $catalog];
        }
        if (is_string($fingerprint) && $fingerprint !== '') {
            throw new CoreSchemaException([
                'reason' => 'legacy_schema_fingerprint_unrecognized',
                'stored_schema_version' => $version,
            ]);
        }
        $validationCatalog = self::authorizeLegacy($pdo, $catalog, $markers, $profile);
        return ['current' => false, 'validation_catalog' => $validationCatalog];
    }

    /**
     * @param array{version:int,fingerprint:?string,rag_version:int} $markers
     */
    private static function authorizeLegacy(
        PrefixedPDO $pdo,
        CoreSchemaCatalog $catalog,
        array $markers,
        string $profile,
        ?CoreSchemaCatalog $requiredCatalog = null
    ): CoreSchemaCatalog {
        if ($markers['version'] >= $catalog->schemaVersion()
            || (is_string($markers['fingerprint']) && $markers['fingerprint'] !== '')) {
            throw new CoreSchemaException([
                'reason' => 'legacy_core_ddl_not_authorized',
                'profile' => $profile,
            ]);
        }

        $signature = new CoreSchemaSignature();
        $unclassified = [];
        foreach ($signature->relations($pdo) as $relation) {
            if (($relation['scoped'] ?? false) === true
                && ($relation['classification'] ?? null) === 'unclassified') {
                $unclassified[] = (string) ($relation['logical_name'] ?? '');
            }
        }
        if ($unclassified !== []) {
            sort($unclassified, SORT_STRING);
            throw new CoreSchemaException([
                'reason' => 'legacy_schema_contains_unclassified_relations',
                'relations' => array_values(array_unique($unclassified)),
            ]);
        }

        $candidates = [$requiredCatalog ?? $catalog];
        if ($requiredCatalog === null && $catalog->schemaVersion() === 5) {
            $candidates[] = CoreSchemaCatalog::loadHistorical(4);
        }
        $lastFailure = null;
        foreach ($candidates as $candidate) {
            try {
                self::verifyLegacyShape($pdo, $candidate, $markers);
                return $candidate;
            } catch (CoreSchemaException $exception) {
                $lastFailure = $exception;
            }
        }
        throw $lastFailure ?? new CoreSchemaException([
            'reason' => 'legacy_schema_source_unrecognized',
            'profile' => $profile,
        ]);
    }

    private static function verifyLegacyShape(
        PrefixedPDO $pdo,
        CoreSchemaCatalog $catalog,
        array $markers
    ): void {
        $mutable = [];
        foreach (self::PROFILE_TABLES as $tables) {
            array_push($mutable, ...$tables);
        }
        $mutable = array_values(array_unique($mutable, SORT_STRING));
        $immutable = array_values(array_diff(
            $catalog->tableNames('mysql'),
            $mutable,
            self::RAG_TABLES,
            self::ROUTE_TABLES
        ));
        (new CoreSchemaVerifier($catalog))->verifyTables($pdo, $immutable, false);

        self::verifyOptionalUnit($pdo, $catalog, self::RAG_TABLES, 'rag_core');
        self::verifyOptionalUnit($pdo, $catalog, self::ROUTE_TABLES, 'retrieval_route');
        if ($markers['rag_version'] === CoreSchemaRuntime::RAG_SCHEMA_VERSION
            && self::presentTables($pdo, self::RAG_TABLES) === []) {
            throw new CoreSchemaException([
                'reason' => 'canonical_rag_schema_attestation_inconsistent',
            ]);
        }
    }

    /** @param list<string> $tables */
    private static function verifyOptionalUnit(
        PrefixedPDO $pdo,
        CoreSchemaCatalog $catalog,
        array $tables,
        string $unit
    ): void {
        $present = self::presentTables($pdo, $tables);
        if ($present === []) {
            return;
        }
        if (count($present) !== count($tables)) {
            throw new CoreSchemaException([
                'reason' => 'partial_legacy_core_unit',
                'unit' => $unit,
                'present_tables' => $present,
                'missing_tables' => array_values(array_diff($tables, $present)),
            ]);
        }
        (new CoreSchemaVerifier($catalog))->verifyTables($pdo, $tables, false);
    }

    /** @param list<string> $tables @return list<string> */
    private static function presentTables(PrefixedPDO $pdo, array $tables): array
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables '
            . "WHERE table_schema = DATABASE() AND table_name = ? AND table_type = 'BASE TABLE'"
        );
        $present = [];
        foreach ($tables as $table) {
            $statement->execute([$pdo->physicalTableName($table)]);
            if ($statement->fetchColumn() !== false) {
                $present[] = $table;
            }
        }
        sort($present, SORT_STRING);
        return $present;
    }

    /** @return array{version:int,fingerprint:?string,rag_version:int} */
    private static function markers(PrefixedPDO $pdo): array
    {
        try {
            $statement = $pdo->prepare(
                'SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN (?, ?, ?)'
            );
            $statement->execute([
                CoreSchemaRuntime::VERSION_SETTING,
                CoreSchemaRuntime::FINGERPRINT_SETTING,
                CoreSchemaRuntime::RAG_VERSION_SETTING,
            ]);
            $settings = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $exception) {
            throw new CoreSchemaException(['reason' => 'schema_metadata_unreadable'], $exception);
        }
        return [
            'version' => self::parseVersion(
                $settings[CoreSchemaRuntime::VERSION_SETTING] ?? null,
                CoreSchemaRuntime::VERSION_SETTING
            ),
            'fingerprint' => isset($settings[CoreSchemaRuntime::FINGERPRINT_SETTING])
                ? (string) $settings[CoreSchemaRuntime::FINGERPRINT_SETTING]
                : null,
            'rag_version' => self::parseVersion(
                $settings[CoreSchemaRuntime::RAG_VERSION_SETTING] ?? null,
                CoreSchemaRuntime::RAG_VERSION_SETTING
            ),
        ];
    }

    private static function parseVersion(mixed $value, string $setting): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (!is_string($value) || preg_match('/^[0-9]{1,9}$/D', $value) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'schema_version_marker_invalid',
                'setting' => $setting,
            ]);
        }
        return (int) $value;
    }

    private function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        self::releaseNamedLock($this->pdo, $this->lockName);
    }

    private static function releaseNamedLock(PrefixedPDO $pdo, string $lockName): void
    {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (Throwable) {
        }
    }
}
