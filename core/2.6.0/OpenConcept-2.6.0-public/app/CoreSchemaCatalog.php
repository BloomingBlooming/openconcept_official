<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaGeneration.php';
require_once __DIR__ . '/CoreSchemaPostgreSqlIndexes.php';
require_once __DIR__ . '/CoreSchemaReleaseSeal.php';
require_once __DIR__ . '/PrefixedPDO.php';

/**
 * Loader and self-verifier for the data-only Core schema artifact.
 *
 * A profile is physical-driver specific. No type/default/index projection is
 * inferred between SQLite, MySQL, and PostgreSQL at verification time.
 */
final class CoreSchemaCatalog
{
    public const FORMAT = 'openconcept-core-schema';
    public const FORMAT_VERSION = 4;
    public const SCHEMA_VERSION = CoreSchemaGeneration::VERSION;

    /** @var array<int, string> */
    private const HISTORICAL_ARTIFACT_HASHES = [
        3 => 'sha256:c890cc690f0ce35deb352236c93220199e98836f00d278ffde6265f3e6f37b5e',
        4 => 'sha256:3a15a81f0b0b865dece2a79e697eb873e15b510ed16d69853091860c8ac24bb9',
        5 => 'sha256:a516e541230b4533f8d39ddf93884477651c10bebc2f9b66660f7932e5b84b07',
    ];

    /** @var array<string, list<string>> */
    private const GENERATION_FIVE_MYSQL_FSP6_DEFAULTS = [
        'ai_chat_turns' => ['created_at'],
        'ai_conversations' => ['created_at', 'updated_at'],
        'audit_logs' => ['created_at'],
        'comments' => ['created_at'],
        'files' => ['created_at'],
        'notifications' => ['created_at'],
        'page_access_departments' => ['created_at'],
        'page_access_members' => ['created_at'],
        'page_public_share_pages' => ['created_at'],
        'page_public_shares' => ['created_at', 'updated_at'],
        'pages' => ['created_at', 'updated_at'],
        'rag_block_metadata' => ['created_at', 'updated_at'],
        'rag_canonical_tags' => ['created_at', 'updated_at'],
        'rag_chunks' => ['created_at', 'updated_at'],
        'rag_generated_tags' => ['created_at'],
        'rag_jobs' => ['created_at', 'updated_at'],
        'rag_page_profiles' => ['analyzed_at'],
        'rag_page_tag_links' => ['created_at', 'updated_at'],
        'rag_source_documents' => ['captured_at'],
        'rag_source_unit_blocks' => ['created_at'],
        'rag_tag_aliases' => ['created_at'],
        'revisions' => ['created_at'],
        'system_settings' => ['updated_at'],
        'users' => ['created_at'],
    ];

    /** @var array<string, true> */
    private const GENERATION_FIVE_MYSQL_FSP6_ON_UPDATE = [
        'ai_conversations.updated_at' => true,
        'pages.updated_at' => true,
        'rag_block_metadata.updated_at' => true,
        'rag_canonical_tags.updated_at' => true,
        'rag_chunks.updated_at' => true,
        'rag_jobs.updated_at' => true,
        'rag_page_tag_links.updated_at' => true,
        'system_settings.updated_at' => true,
    ];

    /** @var list<string> */
    public const REQUIRED_PROFILES = ['mysql', 'pgsql', 'sqlite'];

    /** @param array<string, mixed> $artifact */
    private function __construct(private readonly array $artifact)
    {
    }

    public static function defaultPath(): string
    {
        return dirname(__DIR__) . '/database/schema/core-v' . self::SCHEMA_VERSION . '.json';
    }

    public static function load(?string $path = null): self
    {
        $path ??= self::defaultPath();
        $catalog = self::fromArray(self::readArray($path));
        CoreSchemaReleaseSeal::assertMatches($path, $catalog->artifactHash());
        return $catalog;
    }

    /**
     * Load an immutable predecessor solely to prove an explicitly supported
     * upgrade source. Runtime never treats this catalog as the current schema.
     */
    public static function loadHistorical(int $schemaVersion): self
    {
        $formatVersion = match ($schemaVersion) {
            3 => 3,
            4, 5 => 4,
            default => throw new CoreSchemaException([
                'reason' => 'historical_catalog_version_unsupported',
                'schema_version' => $schemaVersion,
            ]),
        };
        if ($schemaVersion >= self::SCHEMA_VERSION) {
            throw new CoreSchemaException([
                'reason' => 'historical_catalog_version_unsupported',
                'schema_version' => $schemaVersion,
            ]);
        }
        $path = dirname(__DIR__) . '/database/schema/core-v' . $schemaVersion . '.json';
        $catalog = self::validateArtifact(
            self::readArray($path),
            true,
            $schemaVersion,
            $formatVersion,
            true
        );
        self::assertHistoricalArtifactHash($catalog, $schemaVersion);
        CoreSchemaReleaseSeal::assertMatches($path, $catalog->artifactHash());
        return $catalog;
    }

    private static function assertHistoricalArtifactHash(self $catalog, int $schemaVersion): void
    {
        $expectedHash = self::HISTORICAL_ARTIFACT_HASHES[$schemaVersion] ?? null;
        if (!is_string($expectedHash)
            || !hash_equals($expectedHash, $catalog->artifactHash())) {
            throw new CoreSchemaException([
                'reason' => 'historical_catalog_hash_mismatch',
                'schema_version' => $schemaVersion,
            ]);
        }
    }

    /**
     * Tooling may read a current, structurally valid artifact before release
     * sealing. Runtime callers must use load(), which also requires the seal.
     */
    public static function loadForUpdate(?string $path = null): self
    {
        $path ??= self::defaultPath();
        return self::fromArrayForUpdate(self::readArray($path));
    }

    /** @return array<string, mixed> */
    private static function readArray(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_unavailable',
                'catalog' => basename($path),
            ]);
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 128, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new CoreSchemaException([
                'reason' => 'catalog_invalid_json',
                'catalog' => basename($path),
            ], $exception);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new CoreSchemaException(['reason' => 'catalog_invalid_shape']);
        }
        return $decoded;
    }

    /** @param array<string, mixed> $artifact */
    public static function fromArray(array $artifact, bool $requireComplete = true): self
    {
        return self::validateArtifact($artifact, $requireComplete);
    }

    /**
     * Tooling-only validation for the current generation. It may be incomplete
     * while profiles are generated, but may never use an older version,
     * format, or Core registration. Runtime callers must never use this path.
     *
     * @param array<string, mixed> $artifact
     */
    private static function fromArrayForUpdate(array $artifact): self
    {
        return self::validateArtifact($artifact, false);
    }

    /** @param array<string, mixed> $artifact */
    private static function validateArtifact(
        array $artifact,
        bool $requireComplete,
        int $expectedSchemaVersion = self::SCHEMA_VERSION,
        int $expectedFormatVersion = self::FORMAT_VERSION,
        bool $allowPreviousSignature = false
    ): self
    {
        $schemaVersion = $artifact['schema_version'] ?? null;
        $formatVersion = $artifact['format_version'] ?? null;
        if (($artifact['format'] ?? null) !== self::FORMAT
            || $formatVersion !== $expectedFormatVersion
            || $schemaVersion !== $expectedSchemaVersion) {
            throw new CoreSchemaException(['reason' => 'catalog_version_unsupported']);
        }
        self::assertExactKeys($artifact, [
            'artifact_hash',
            'cross_profile_exceptions',
            'format',
            'format_version',
            'profiles',
            'schema_version',
        ], 'catalog');

        $hash = $artifact['artifact_hash'] ?? null;
        if (!is_string($hash) || preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) !== 1) {
            throw new CoreSchemaException(['reason' => 'catalog_hash_invalid']);
        }
        if (!hash_equals(self::calculateArtifactHash($artifact), $hash)) {
            throw new CoreSchemaException(['reason' => 'catalog_hash_mismatch']);
        }

        $crossProfileExceptions = self::validateCrossProfileExceptions(
            $artifact['cross_profile_exceptions'] ?? null
        );

        $profiles = $artifact['profiles'] ?? null;
        if (!is_array($profiles) || ($profiles !== [] && array_is_list($profiles))) {
            throw new CoreSchemaException(['reason' => 'catalog_profiles_invalid']);
        }
        $normalizedProfiles = [];
        foreach ($profiles as $driver => $profile) {
            if (!is_string($driver) || !in_array($driver, self::REQUIRED_PROFILES, true)
                || !is_array($profile) || array_is_list($profile)) {
                throw new CoreSchemaException(['reason' => 'catalog_profile_unsupported']);
            }
            $normalizedProfiles[$driver] = self::validateProfile(
                $driver,
                $profile,
                $expectedSchemaVersion === self::SCHEMA_VERSION,
                $allowPreviousSignature
            );
        }
        ksort($normalizedProfiles, SORT_STRING);

        $present = array_keys($normalizedProfiles);
        $required = self::REQUIRED_PROFILES;
        sort($required, SORT_STRING);
        if ($requireComplete && $present !== $required) {
            throw new CoreSchemaException([
                'reason' => 'catalog_profiles_incomplete',
                'missing_profiles' => array_values(array_diff($required, $present)),
            ]);
        }
        if ($present === $required) {
            self::validateCrossProfileLogicalParity($normalizedProfiles, $crossProfileExceptions);
        }

        $artifact['cross_profile_exceptions'] = $crossProfileExceptions;
        $artifact['profiles'] = $normalizedProfiles;
        return new self(self::canonicalize($artifact));
    }

    /**
     * Merge one freshly inspected physical profile. The returned artifact is
     * self-hashed but may remain runtime-invalid until all profiles exist.
     *
     * @param array<string, array<string, mixed>> $tables
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    public static function mergeProfile(?array $existing, string $driver, array $tables): array
    {
        if (!in_array($driver, self::REQUIRED_PROFILES, true)) {
            throw new CoreSchemaException(['reason' => 'catalog_profile_unsupported']);
        }
        if ($existing === null) {
            $artifact = [
                'cross_profile_exceptions' => self::currentGenerationCrossProfileExceptions(),
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'schema_version' => self::SCHEMA_VERSION,
                'profiles' => [],
            ];
        } else {
            $existingCatalog = self::fromArrayForUpdate($existing);
            $presentProfiles = $existingCatalog->profileNames();
            $requiredProfiles = self::REQUIRED_PROFILES;
            sort($presentProfiles, SORT_STRING);
            sort($requiredProfiles, SORT_STRING);
            if ($presentProfiles === $requiredProfiles) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_generation_immutable',
                    'schema_version' => self::SCHEMA_VERSION,
                ]);
            }
            $artifact = $existingCatalog->toArray();
            unset($artifact['artifact_hash']);
            if (($artifact['cross_profile_exceptions'] ?? null) === [
                'checks' => [],
                'defaults' => [],
            ]) {
                // A first profile may have been generated immediately after
                // the generation bump but before this reviewed policy landed.
                $artifact['cross_profile_exceptions'] = self::currentGenerationCrossProfileExceptions();
            }
        }
        // Only an incomplete artifact of the code's current generation can be
        // extended. Prior and completed generations are immutable.
        $artifact['cross_profile_exceptions'] ??= [
            'checks' => [],
            'defaults' => [],
        ];
        $artifact['format_version'] = self::FORMAT_VERSION;
        $artifact['schema_version'] = self::SCHEMA_VERSION;
        ksort($tables, SORT_STRING);
        $artifact['profiles'][$driver] = [
            'core_table_count' => count($tables),
            'tables' => $tables,
        ];
        ksort($artifact['profiles'], SORT_STRING);
        $artifact['artifact_hash'] = self::calculateArtifactHash($artifact);
        return self::fromArrayForUpdate($artifact)->toArray();
    }

    /**
     * Seed only the reviewed generation-5 policy. Physical profile values are
     * still captured from fresh databases and must match these exact entries
     * before the artifact can become complete.
     *
     * @return array<string, mixed>
     */
    private static function currentGenerationCrossProfileExceptions(): array
    {
        if (self::SCHEMA_VERSION === 6) {
            // Generation 6 adds no defaults/checks; retain the sealed v5 policy verbatim.
            return self::loadHistorical(5)->toArray()['cross_profile_exceptions'];
        }
        if (self::SCHEMA_VERSION !== 5) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_policy_unavailable',
                'schema_version' => self::SCHEMA_VERSION,
            ]);
        }
        $historical = self::loadHistorical(4)->toArray();
        $exceptions = $historical['cross_profile_exceptions'] ?? null;
        if (!is_array($exceptions) || array_is_list($exceptions)) {
            throw new CoreSchemaException(['reason' => 'catalog_cross_profile_policy_invalid']);
        }
        foreach (self::GENERATION_FIVE_MYSQL_FSP6_DEFAULTS as $table => $columns) {
            foreach ($columns as $column) {
                $key = $table . '.' . $column;
                $mysqlDefault = 'expression:CURRENT_TIMESTAMP(6)';
                if (isset(self::GENERATION_FIVE_MYSQL_FSP6_ON_UPDATE[$key])) {
                    $mysqlDefault .= ';on_update:expression:CURRENT_TIMESTAMP(6)';
                }
                $exceptions['defaults'][$table][$column] = [
                    'profiles' => [
                        'mysql' => $mysqlDefault,
                        'pgsql' => 'expression:CURRENT_TIMESTAMP',
                        'sqlite' => 'expression:CURRENT_TIMESTAMP',
                    ],
                    'reason' => 'MySQL must spell fsp6 in the default and optional ON UPDATE clause; SQLite has no precision modifier and PostgreSQL records precision in the column type, so every driver value is pinned explicitly.',
                ];
            }
        }
        return self::canonicalize($exceptions);
    }

    /** @param array<string, mixed> $artifact */
    public static function calculateArtifactHash(array $artifact): string
    {
        unset($artifact['artifact_hash']);
        return 'sha256:' . hash('sha256', self::canonicalJson($artifact));
    }

    /** @param array<string, mixed>|list<mixed> $value */
    public static function canonicalJson(array $value, bool $pretty = false): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR
                | ($pretty ? JSON_PRETTY_PRINT : 0)
        );
    }

    public function schemaVersion(): int
    {
        return (int) $this->artifact['schema_version'];
    }

    public function artifactHash(): string
    {
        return (string) $this->artifact['artifact_hash'];
    }

    /** @return list<string> */
    public function profileNames(): array
    {
        return array_keys($this->artifact['profiles']);
    }

    public function hasProfile(string $driver): bool
    {
        return isset($this->artifact['profiles'][$driver]);
    }

    /** @return array{core_table_count: int, tables: array<string, array<string, mixed>>} */
    public function profile(string $driver): array
    {
        if (!isset($this->artifact['profiles'][$driver])) {
            throw new CoreSchemaException([
                'reason' => 'catalog_profile_missing',
                'driver' => $driver,
            ]);
        }
        return $this->artifact['profiles'][$driver];
    }

    /** @return list<string> */
    public function tableNames(string $driver): array
    {
        return array_keys($this->profile($driver)['tables']);
    }

    /** @return array<string, array<string, mixed>> */
    public function tables(string $driver): array
    {
        return $this->profile($driver)['tables'];
    }

    /** @return array<string, mixed> */
    public function table(string $driver, string $logicalName): array
    {
        $profile = $this->profile($driver);
        if (!isset($profile['tables'][$logicalName])) {
            throw new CoreSchemaException([
                'reason' => 'catalog_table_unknown',
                'driver' => $driver,
                'table' => $logicalName,
            ]);
        }
        return $profile['tables'][$logicalName];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->artifact;
    }

    /**
     * @param array<string, mixed> $profile
     * @return array{core_table_count: int, tables: array<string, array<string, mixed>>}
     */
    private static function validateProfile(
        string $driver,
        array $profile,
        bool $requireCurrentRegistration,
        bool $allowPreviousSignature
    ): array
    {
        self::assertExactKeys($profile, ['core_table_count', 'tables'], 'profile');
        $tables = $profile['tables'] ?? null;
        if (!is_array($tables) || array_is_list($tables) || $tables === []) {
            throw new CoreSchemaException(['reason' => 'catalog_tables_invalid', 'driver' => $driver]);
        }
        if (!is_int($profile['core_table_count'] ?? null)
            || $profile['core_table_count'] !== count($tables)) {
            throw new CoreSchemaException(['reason' => 'catalog_table_count_mismatch', 'driver' => $driver]);
        }

        $normalizedTables = [];
        foreach ($tables as $logicalName => $signature) {
            if (!is_string($logicalName) || !self::validIdentifier($logicalName)
                || !is_array($signature) || array_is_list($signature)) {
                throw new CoreSchemaException(['reason' => 'catalog_table_invalid', 'driver' => $driver]);
            }
            $normalizedTables[$logicalName] = self::validateTableSignature(
                $driver,
                $logicalName,
                $signature,
                $allowPreviousSignature
            );
        }
        ksort($normalizedTables, SORT_STRING);

        foreach ($normalizedTables as $logicalName => $signature) {
            foreach ($signature['foreign_keys'] as $foreignKey) {
                $target = $normalizedTables[$foreignKey['referenced_table']] ?? null;
                if (!is_array($target)) {
                    throw new CoreSchemaException([
                        'reason' => 'catalog_foreign_key_target_invalid',
                        'driver' => $driver,
                        'table' => $logicalName,
                    ]);
                }
                $targetColumns = array_fill_keys(
                    array_values(array_map('strval', array_column($target['columns'], 'name'))),
                    true
                );
                foreach ($foreignKey['referenced_columns'] as $referencedColumn) {
                    if (!isset($targetColumns[$referencedColumn])) {
                        throw new CoreSchemaException([
                            'reason' => 'catalog_foreign_key_target_column_invalid',
                            'driver' => $driver,
                            'table' => $logicalName,
                            'referenced_table' => $foreignKey['referenced_table'],
                            'referenced_column' => $referencedColumn,
                        ]);
                    }
                }
                if (!self::hasReferencedUniqueKey($target, $foreignKey['referenced_columns'])) {
                    throw new CoreSchemaException([
                        'reason' => 'catalog_foreign_key_target_key_invalid',
                        'driver' => $driver,
                        'table' => $logicalName,
                        'referenced_table' => $foreignKey['referenced_table'],
                    ]);
                }
            }
        }

        if ($requireCurrentRegistration) {
            $registered = self::registeredCoreTables();
            $catalogNames = array_keys($normalizedTables);
            if ($catalogNames !== $registered) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_registration_mismatch',
                    'driver' => $driver,
                    'missing_registered_tables' => array_values(array_diff($registered, $catalogNames)),
                    'unregistered_catalog_tables' => array_values(array_diff($catalogNames, $registered)),
                ]);
            }
        }
        return [
            'core_table_count' => count($normalizedTables),
            'tables' => $normalizedTables,
        ];
    }

    /**
     * @param array<string, mixed> $signature
     * @return array<string, mixed>
     */
    private static function validateTableSignature(
        string $driver,
        string $logicalName,
        array $signature,
        bool $allowPreviousSignature
    ): array
    {
        $currentTableKeys = [
            'checks', 'columns', 'foreign_keys', 'indexes', 'options', 'primary_key',
            'primary_key_options', 'relation',
        ];
        $actualTableKeys = array_keys($signature);
        sort($actualTableKeys, SORT_STRING);
        $sortedCurrentTableKeys = $currentTableKeys;
        sort($sortedCurrentTableKeys, SORT_STRING);
        $previousTableKeys = array_values(array_diff($currentTableKeys, ['primary_key_options']));
        sort($previousTableKeys, SORT_STRING);
        if ($actualTableKeys !== $sortedCurrentTableKeys
            && !($allowPreviousSignature && $actualTableKeys === $previousTableKeys)) {
            throw new CoreSchemaException(['reason' => 'catalog_shape_invalid', 'section' => 'table']);
        }
        if (($signature['relation'] ?? null) !== 'table') {
            throw new CoreSchemaException(['reason' => 'catalog_relation_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }

        $columns = $signature['columns'] ?? null;
        if (!is_array($columns) || !array_is_list($columns) || $columns === []) {
            throw new CoreSchemaException(['reason' => 'catalog_columns_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        $normalizedColumns = [];
        $columnNames = [];
        $primaryByPosition = [];
        foreach ($columns as $column) {
            if (!is_array($column) || array_is_list($column)) {
                throw new CoreSchemaException(['reason' => 'catalog_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            self::assertExactKeys($column, [
                'collation',
                'default',
                'identity',
                'name',
                'nullable',
                'primary_position',
                'type',
                'type_family',
            ], 'column');
            $name = $column['name'] ?? null;
            $type = $column['type'] ?? null;
            $typeFamily = $column['type_family'] ?? null;
            $nullable = $column['nullable'] ?? null;
            $collation = $column['collation'] ?? null;
            $default = $column['default'] ?? null;
            $identity = $column['identity'] ?? null;
            $primaryPosition = $column['primary_position'] ?? null;
            if (!is_string($name) || !self::validIdentifier($name)
                || isset($columnNames[$name])
                || !is_string($type) || $type === ''
                || !is_string($typeFamily) || $typeFamily === ''
                || !is_bool($nullable)
                || (!is_null($collation) && (!is_string($collation)
                    || preg_match('/^[A-Za-z0-9_.-]{1,190}$/D', $collation) !== 1))
                || (!is_null($default) && !is_string($default))
                || !in_array($identity, [
                    'none', 'rowid', 'autoincrement', 'identity_always',
                    'identity_by_default', 'sequence',
                ], true)
                || !is_int($primaryPosition) || $primaryPosition < 0) {
                throw new CoreSchemaException(['reason' => 'catalog_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            $columnNames[$name] = true;
            if ($primaryPosition > 0) {
                if (isset($primaryByPosition[$primaryPosition])) {
                    throw new CoreSchemaException(['reason' => 'catalog_primary_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
                }
                $primaryByPosition[$primaryPosition] = $name;
            }
            $normalizedColumns[] = $column;
        }

        $primaryKey = $signature['primary_key'] ?? null;
        if (!is_array($primaryKey) || !array_is_list($primaryKey)) {
            throw new CoreSchemaException(['reason' => 'catalog_primary_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        foreach ($primaryKey as $columnName) {
            if (!is_string($columnName) || !isset($columnNames[$columnName])) {
                throw new CoreSchemaException(['reason' => 'catalog_primary_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        }
        if (count($primaryKey) !== count(array_unique($primaryKey, SORT_STRING))) {
            throw new CoreSchemaException(['reason' => 'catalog_primary_key_duplicate', 'driver' => $driver, 'table' => $logicalName]);
        }
        ksort($primaryByPosition, SORT_NUMERIC);
        if (array_values($primaryByPosition) !== $primaryKey
            || ($primaryByPosition !== [] && array_keys($primaryByPosition) !== range(1, count($primaryByPosition)))) {
            throw new CoreSchemaException(['reason' => 'catalog_primary_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        $primaryKeyOptions = $signature['primary_key_options'] ?? null;
        if ($primaryKeyOptions !== null) {
            self::validateConstraintState($primaryKeyOptions, 'primary_key');
        } elseif (!$allowPreviousSignature) {
            throw new CoreSchemaException(['reason' => 'catalog_primary_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }

        $foreignKeys = $signature['foreign_keys'] ?? null;
        if (!is_array($foreignKeys) || !array_is_list($foreignKeys)) {
            throw new CoreSchemaException(['reason' => 'catalog_foreign_keys_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        foreach ($foreignKeys as $foreignKey) {
            self::validateForeignKey(
                $driver,
                $logicalName,
                $foreignKey,
                $columnNames,
                $allowPreviousSignature
            );
        }
        self::assertUniqueSemanticEntries($foreignKeys, 'foreign_key', $driver, $logicalName);

        $indexes = $signature['indexes'] ?? null;
        if (!is_array($indexes) || !array_is_list($indexes)) {
            throw new CoreSchemaException(['reason' => 'catalog_indexes_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        foreach ($indexes as $index) {
            self::validateIndex($driver, $logicalName, $index, $columnNames, $allowPreviousSignature);
        }
        self::assertUniqueSemanticEntries($indexes, 'index', $driver, $logicalName);

        $checks = $signature['checks'] ?? null;
        if (!is_array($checks) || !array_is_list($checks)) {
            throw new CoreSchemaException(['reason' => 'catalog_checks_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        foreach ($checks as $check) {
            if (!is_array($check) || array_is_list($check)) {
                throw new CoreSchemaException(['reason' => 'catalog_check_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            self::assertExactKeys($check, ['enforced', 'expression', 'validated'], 'check');
            if (!is_string($check['expression'] ?? null) || trim($check['expression']) === ''
                || ($check['enforced'] ?? null) !== true
                || ($check['validated'] ?? null) !== true) {
                throw new CoreSchemaException(['reason' => 'catalog_check_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        }
        self::assertUniqueSemanticEntries($checks, 'check', $driver, $logicalName);

        $options = $signature['options'] ?? null;
        if (!is_array($options) || array_is_list($options)) {
            throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        self::assertExactKeys($options, ['character_set', 'collation', 'engine'], 'table_options');
        foreach ($options as $value) {
            if (!is_null($value) && (!is_string($value) || $value === '')) {
                throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        }
        if ($driver === 'mysql') {
            if (!is_string($options['engine'])
                || !is_string($options['character_set'])
                || !is_string($options['collation'])) {
                throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        } elseif ($driver === 'sqlite') {
            $allowedSqliteEngines = [
                'ROWID',
                'STRICT ROWID',
                'WITHOUT ROWID',
                'STRICT WITHOUT ROWID',
            ];
            if ((!in_array($options['engine'], $allowedSqliteEngines, true)
                    && !($allowPreviousSignature && $options['engine'] === null))
                || $options['character_set'] !== null
                || $options['collation'] !== null) {
                throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        } elseif ($driver === 'pgsql') {
            if (($options['engine'] !== 'PERMANENT;AM=HEAP;RLS=OFF;FORCE_RLS=OFF'
                    && !($allowPreviousSignature && $options['engine'] === null))
                || $options['character_set'] !== null
                || $options['collation'] !== null) {
                throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        } else {
            throw new CoreSchemaException(['reason' => 'catalog_table_options_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }

        $normalized = [
            'relation' => 'table',
            'options' => $options,
            'columns' => $normalizedColumns,
            'primary_key' => $primaryKey,
            'foreign_keys' => $foreignKeys,
            'indexes' => $indexes,
            'checks' => $checks,
        ];
        if ($primaryKeyOptions !== null) {
            $normalized['primary_key_options'] = $primaryKeyOptions;
        }
        return $normalized;
    }

    /** @param mixed $foreignKey @param array<string, true> $columnNames */
    private static function validateForeignKey(
        string $driver,
        string $logicalName,
        mixed $foreignKey,
        array $columnNames,
        bool $allowPreviousSignature
    ): void {
        if (!is_array($foreignKey) || array_is_list($foreignKey)) {
            throw new CoreSchemaException(['reason' => 'catalog_foreign_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        $currentKeys = [
            'columns', 'deferrable', 'initially_deferred', 'match', 'on_delete', 'on_update',
            'referenced_columns', 'referenced_table', 'validated',
        ];
        $actualKeys = array_keys($foreignKey);
        sort($actualKeys, SORT_STRING);
        $sortedCurrentKeys = $currentKeys;
        sort($sortedCurrentKeys, SORT_STRING);
        $previousKeys = array_values(array_diff($currentKeys, ['validated']));
        sort($previousKeys, SORT_STRING);
        if ($actualKeys !== $sortedCurrentKeys
            && !($allowPreviousSignature && $actualKeys === $previousKeys)) {
            throw new CoreSchemaException(['reason' => 'catalog_shape_invalid', 'section' => 'foreign_key']);
        }
        $localColumns = $foreignKey['columns'] ?? null;
        $referencedColumns = $foreignKey['referenced_columns'] ?? null;
        $referencedTable = $foreignKey['referenced_table'] ?? null;
        if (!is_array($localColumns) || !array_is_list($localColumns) || $localColumns === []
            || !is_array($referencedColumns) || !array_is_list($referencedColumns)
            || count($localColumns) !== count($referencedColumns)
            || !is_string($referencedTable) || !self::validIdentifier($referencedTable)
            || !in_array($foreignKey['on_delete'] ?? null, self::referentialActions(), true)
            || !in_array($foreignKey['on_update'] ?? null, self::referentialActions(), true)
            || !is_bool($foreignKey['deferrable'] ?? null)
            || !is_bool($foreignKey['initially_deferred'] ?? null)
            || (($foreignKey['initially_deferred'] ?? false) && !($foreignKey['deferrable'] ?? false))
            || (array_key_exists('validated', $foreignKey) && $foreignKey['validated'] !== true)
            || !in_array($foreignKey['match'] ?? null, ['FULL', 'NONE', 'PARTIAL', 'SIMPLE'], true)) {
            throw new CoreSchemaException(['reason' => 'catalog_foreign_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        foreach ($localColumns as $offset => $columnName) {
            if (!is_string($columnName) || !isset($columnNames[$columnName])
                || !is_string($referencedColumns[$offset])
                || !self::validIdentifier($referencedColumns[$offset])) {
                throw new CoreSchemaException(['reason' => 'catalog_foreign_key_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        }
        if (count($localColumns) !== count(array_unique($localColumns, SORT_STRING))
            || count($referencedColumns) !== count(array_unique($referencedColumns, SORT_STRING))) {
            throw new CoreSchemaException(['reason' => 'catalog_foreign_key_column_duplicate', 'driver' => $driver, 'table' => $logicalName]);
        }
    }

    /** @param mixed $index @param array<string, true> $columnNames */
    private static function validateIndex(
        string $driver,
        string $logicalName,
        mixed $index,
        array $columnNames,
        bool $allowPreviousSignature
    ): void
    {
        if (!is_array($index) || array_is_list($index)) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        $currentKeys = [
            'columns', 'deferrable', 'initially_deferred', 'nulls_not_distinct',
            'origin', 'partial', 'predicate', 'type', 'unique', 'validated',
        ];
        $actualKeys = array_keys($index);
        sort($actualKeys, SORT_STRING);
        $sortedCurrentKeys = $currentKeys;
        sort($sortedCurrentKeys, SORT_STRING);
        $previousKeys = ['columns', 'origin', 'partial', 'predicate', 'type', 'unique'];
        sort($previousKeys, SORT_STRING);
        if ($actualKeys !== $sortedCurrentKeys
            && !($allowPreviousSignature && $actualKeys === $previousKeys)) {
            throw new CoreSchemaException(['reason' => 'catalog_shape_invalid', 'section' => 'index']);
        }
        $indexColumns = $index['columns'] ?? null;
        if (!is_bool($index['unique'] ?? null)
            || !in_array($index['origin'] ?? null, ['explicit', 'unique_constraint'], true)
            || !is_string($index['type'] ?? null) || $index['type'] === ''
            || !is_bool($index['partial'] ?? null)
            || (!is_null($index['predicate'] ?? null) && !is_string($index['predicate']))
            || !is_array($indexColumns) || !array_is_list($indexColumns) || $indexColumns === []) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        if (array_key_exists('deferrable', $index)) {
            self::validateConstraintState([
                'deferrable' => $index['deferrable'],
                'initially_deferred' => $index['initially_deferred'] ?? null,
                'validated' => $index['validated'] ?? null,
            ], 'index');
            if (!is_bool($index['nulls_not_distinct'] ?? null)) {
                throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            if (($index['origin'] ?? null) === 'explicit'
                && (($index['deferrable'] ?? false) || ($index['initially_deferred'] ?? false))) {
                throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
        } elseif (!$allowPreviousSignature) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        if (($index['partial'] === false && $index['predicate'] !== null)
            || ($index['partial'] === true && $index['predicate'] === null)) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        $seenIndexColumns = [];
        $sawIncludedColumn = false;
        foreach ($indexColumns as $indexColumn) {
            if (!is_array($indexColumn) || array_is_list($indexColumn)) {
                throw new CoreSchemaException(['reason' => 'catalog_index_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            $ordinaryIndexColumnKeys = [
                'collation', 'direction', 'included', 'length', 'name', 'nulls_order',
                'operator_class',
            ];
            $expressionIndexColumnKeys = array_merge($ordinaryIndexColumnKeys, ['expression']);
            $previousIndexColumnKeys = [
                'collation', 'direction', 'included', 'length', 'name', 'operator_class',
            ];
            $actualIndexColumnKeys = array_keys($indexColumn);
            sort($actualIndexColumnKeys, SORT_STRING);
            sort($ordinaryIndexColumnKeys, SORT_STRING);
            sort($expressionIndexColumnKeys, SORT_STRING);
            sort($previousIndexColumnKeys, SORT_STRING);
            $isExpressionColumn = $actualIndexColumnKeys === $expressionIndexColumnKeys;
            if ($actualIndexColumnKeys !== $ordinaryIndexColumnKeys
                && !$isExpressionColumn
                && !($allowPreviousSignature
                    && $actualIndexColumnKeys === $previousIndexColumnKeys)) {
                throw new CoreSchemaException(['reason' => 'catalog_shape_invalid', 'section' => 'index_column']);
            }
            $indexColumnName = $indexColumn['name'] ?? null;
            $expression = $indexColumn['expression'] ?? null;
            if (($isExpressionColumn
                    && ($driver !== 'pgsql'
                        || $indexColumnName !== null
                        || !is_string($expression)
                        || !CoreSchemaPostgreSqlIndexes::expressionBelongsToTable(
                            $logicalName,
                            $expression
                        )))
                || (!$isExpressionColumn
                    && (!is_string($indexColumnName) || !isset($columnNames[$indexColumnName])))
                || !is_string($indexColumn['collation'] ?? null)
                || !in_array($indexColumn['direction'] ?? null, ['ASC', 'DESC'], true)
                || (!is_null($indexColumn['length'] ?? null) && (!is_int($indexColumn['length']) || $indexColumn['length'] < 1))
                || !is_string($indexColumn['operator_class'] ?? null)
                || !is_bool($indexColumn['included'] ?? null)) {
                throw new CoreSchemaException(['reason' => 'catalog_index_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            if (array_key_exists('nulls_order', $indexColumn)) {
                $expectedFixedNullsOrder = $indexColumn['direction'] === 'DESC' ? 'LAST' : 'FIRST';
                if (($indexColumn['included'] === true && $indexColumn['nulls_order'] !== null)
                    || ($indexColumn['included'] === false
                        && !in_array($indexColumn['nulls_order'], ['FIRST', 'LAST'], true))
                    || (in_array($driver, ['mysql', 'sqlite'], true)
                        && $indexColumn['nulls_order'] !== $expectedFixedNullsOrder)) {
                    throw new CoreSchemaException(['reason' => 'catalog_index_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
                }
            } elseif (!$allowPreviousSignature) {
                throw new CoreSchemaException(['reason' => 'catalog_index_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            $semanticColumnKey = $isExpressionColumn
                ? 'expression:' . $expression
                : 'column:' . (string) $indexColumnName;
            if (isset($seenIndexColumns[$semanticColumnKey])
                || ($sawIncludedColumn && $indexColumn['included'] === false)) {
                throw new CoreSchemaException(['reason' => 'catalog_index_column_duplicate', 'driver' => $driver, 'table' => $logicalName]);
            }
            if ($isExpressionColumn
                && ($indexColumn['included'] !== false
                    || $indexColumn['length'] !== null
                    || $indexColumn['operator_class'] !== 'tsvector_ops'
                    || $indexColumn['collation'] !== '')) {
                throw new CoreSchemaException(['reason' => 'catalog_index_column_invalid', 'driver' => $driver, 'table' => $logicalName]);
            }
            $seenIndexColumns[$semanticColumnKey] = true;
            $sawIncludedColumn = $sawIncludedColumn || $indexColumn['included'];
        }
        $expressionColumns = array_values(array_filter(
            $indexColumns,
            static fn (array $column): bool => array_key_exists('expression', $column)
        ));
        if ($expressionColumns !== []
            && (count($expressionColumns) !== count($indexColumns)
                || count($expressionColumns) !== 1
                || $driver !== 'pgsql'
                || $index['type'] !== 'GIN'
                || $index['unique'] !== false
                || $index['origin'] !== 'explicit'
                || $index['partial'] !== false
                || ($index['nulls_not_distinct'] ?? false) !== false)) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        if (($index['origin'] ?? null) === 'unique_constraint'
            && (($index['unique'] ?? null) !== true || ($index['partial'] ?? null) !== false)) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
        if (($index['nulls_not_distinct'] ?? false) && ($index['unique'] ?? null) !== true) {
            throw new CoreSchemaException(['reason' => 'catalog_index_invalid', 'driver' => $driver, 'table' => $logicalName]);
        }
    }

    /** @param array<string, mixed> $target @param list<string> $referencedColumns */
    private static function hasReferencedUniqueKey(array $target, array $referencedColumns): bool
    {
        if (($target['primary_key'] ?? null) === $referencedColumns) {
            return true;
        }
        foreach ($target['indexes'] ?? [] as $index) {
            if (($index['unique'] ?? false) !== true || ($index['partial'] ?? true) !== false) {
                continue;
            }
            $keyColumns = [];
            $validCandidate = true;
            foreach ($index['columns'] ?? [] as $column) {
                if (($column['included'] ?? false) === true) {
                    continue;
                }
                if (($column['length'] ?? null) !== null) {
                    $validCandidate = false;
                    break;
                }
                $keyColumns[] = (string) ($column['name'] ?? '');
            }
            if ($validCandidate && $keyColumns === $referencedColumns) {
                return true;
            }
        }
        return false;
    }

    /**
     * Cross-driver default and CHECK differences are policy, not facts that a
     * generator may infer. Every exception names the exact three live values
     * and explains why the canonical profiles intentionally differ.
     *
     * @return array{
     *   checks: array<string, array{profiles: array<string, list<array<string, mixed>>>, reason: string}>,
     *   defaults: array<string, array<string, array{profiles: array<string, string|null>, reason: string}>>
     * }
     */
    private static function validateCrossProfileExceptions(mixed $exceptions): array
    {
        if (!is_array($exceptions) || array_is_list($exceptions)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => 'root',
            ]);
        }
        self::assertExactKeys($exceptions, ['checks', 'defaults'], 'cross_profile_exceptions');
        $defaultPolicies = $exceptions['defaults'] ?? null;
        $checkPolicies = $exceptions['checks'] ?? null;
        if (!is_array($defaultPolicies)
            || ($defaultPolicies !== [] && array_is_list($defaultPolicies))
            || !is_array($checkPolicies)
            || ($checkPolicies !== [] && array_is_list($checkPolicies))) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => 'collections',
            ]);
        }

        $normalizedDefaults = [];
        foreach ($defaultPolicies as $logicalName => $columns) {
            if (!is_string($logicalName) || !self::validIdentifier($logicalName)
                || !is_array($columns) || $columns === [] || array_is_list($columns)) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_cross_profile_exceptions_invalid',
                    'component' => 'default',
                    'table' => is_string($logicalName) ? $logicalName : null,
                ]);
            }
            foreach ($columns as $columnName => $entry) {
                if (!is_string($columnName) || !self::validIdentifier($columnName)) {
                    throw new CoreSchemaException([
                        'reason' => 'catalog_cross_profile_exceptions_invalid',
                        'component' => 'default',
                        'table' => $logicalName,
                    ]);
                }
                $normalizedDefaults[$logicalName][$columnName] = self::validateCrossProfileExceptionEntry(
                    $entry,
                    'default',
                    $logicalName,
                    $columnName
                );
            }
            ksort($normalizedDefaults[$logicalName], SORT_STRING);
        }
        ksort($normalizedDefaults, SORT_STRING);

        $normalizedChecks = [];
        foreach ($checkPolicies as $logicalName => $entry) {
            if (!is_string($logicalName) || !self::validIdentifier($logicalName)) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_cross_profile_exceptions_invalid',
                    'component' => 'check',
                ]);
            }
            $normalizedChecks[$logicalName] = self::validateCrossProfileExceptionEntry(
                $entry,
                'check',
                $logicalName,
                null
            );
        }
        ksort($normalizedChecks, SORT_STRING);

        return [
            'checks' => $normalizedChecks,
            'defaults' => $normalizedDefaults,
        ];
    }

    /**
     * @return array{profiles: array<string, mixed>, reason: string}
     */
    private static function validateCrossProfileExceptionEntry(
        mixed $entry,
        string $component,
        string $logicalName,
        ?string $columnName
    ): array {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => $component,
                'table' => $logicalName,
                'column' => $columnName,
            ]);
        }
        self::assertExactKeys($entry, ['profiles', 'reason'], 'cross_profile_exception');
        $reason = $entry['reason'] ?? null;
        if (!is_string($reason) || $reason === '' || trim($reason) !== $reason
            || strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reason) === 1) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => $component,
                'table' => $logicalName,
                'column' => $columnName,
            ]);
        }
        $profileValues = $entry['profiles'] ?? null;
        if (!is_array($profileValues) || array_is_list($profileValues)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => $component,
                'table' => $logicalName,
                'column' => $columnName,
            ]);
        }
        self::assertExactKeys($profileValues, self::REQUIRED_PROFILES, 'cross_profile_exception_profiles');

        $normalizedValues = [];
        foreach (self::REQUIRED_PROFILES as $driver) {
            $value = $profileValues[$driver];
            if ($component === 'default') {
                if (!is_null($value) && !is_string($value)) {
                    throw new CoreSchemaException([
                        'reason' => 'catalog_cross_profile_exceptions_invalid',
                        'component' => $component,
                        'table' => $logicalName,
                        'column' => $columnName,
                    ]);
                }
                $normalizedValues[$driver] = $value;
                continue;
            }
            $normalizedValues[$driver] = self::validateCrossProfileCheckList(
                $value,
                $driver,
                $logicalName
            );
        }

        return ['profiles' => $normalizedValues, 'reason' => $reason];
    }

    /** @return list<array{enforced: true, expression: string, validated: true}> */
    private static function validateCrossProfileCheckList(
        mixed $checks,
        string $driver,
        string $logicalName
    ): array {
        if (!is_array($checks) || !array_is_list($checks)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_cross_profile_exceptions_invalid',
                'component' => 'check',
                'driver' => $driver,
                'table' => $logicalName,
            ]);
        }
        foreach ($checks as $check) {
            if (!is_array($check) || array_is_list($check)) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_cross_profile_exceptions_invalid',
                    'component' => 'check',
                    'driver' => $driver,
                    'table' => $logicalName,
                ]);
            }
            self::assertExactKeys($check, ['enforced', 'expression', 'validated'], 'cross_profile_check');
            if (!is_string($check['expression'] ?? null) || trim($check['expression']) === ''
                || ($check['enforced'] ?? null) !== true
                || ($check['validated'] ?? null) !== true) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_cross_profile_exceptions_invalid',
                    'component' => 'check',
                    'driver' => $driver,
                    'table' => $logicalName,
                ]);
            }
        }
        self::assertUniqueSemanticEntries($checks, 'cross_profile_check', $driver, $logicalName);
        return $checks;
    }

    /**
     * Physical types/index details and FK timing are driver-specific. Defaults
     * and CHECK constraints must either be semantically equal or be pinned by
     * the explicit cross-profile policy above. The logical rows and
     * referential graph are never driver-specific.
     *
     * @param array<string, array{core_table_count: int, tables: array<string, array<string, mixed>>}> $profiles
     * @param array{
     *   checks: array<string, array{profiles: array<string, list<array<string, mixed>>>, reason: string}>,
     *   defaults: array<string, array<string, array{profiles: array<string, string|null>, reason: string}>>
     * } $exceptions
     */
    private static function validateCrossProfileLogicalParity(array $profiles, array $exceptions): void
    {
        $drivers = array_keys($profiles);
        sort($drivers, SORT_STRING);
        $baselineDriver = $drivers[0] ?? '';
        if ($baselineDriver === '') {
            throw new CoreSchemaException(['reason' => 'catalog_profiles_incomplete']);
        }
        $baselineTables = $profiles[$baselineDriver]['tables'];
        foreach ($drivers as $driver) {
            if ($driver === $baselineDriver) {
                continue;
            }
            foreach ($baselineTables as $logicalName => $baselineTable) {
                $candidateTable = $profiles[$driver]['tables'][$logicalName] ?? null;
                if (!is_array($candidateTable)) {
                    throw new CoreSchemaException([
                        'reason' => 'catalog_cross_profile_logical_mismatch',
                        'driver' => $driver,
                        'baseline_driver' => $baselineDriver,
                        'table' => $logicalName,
                        'component' => 'table',
                    ]);
                }
                $components = [
                    'columns' => [
                        self::logicalColumns($baselineTable['columns']),
                        self::logicalColumns($candidateTable['columns']),
                    ],
                    'primary_key' => [
                        $baselineTable['primary_key'],
                        $candidateTable['primary_key'],
                    ],
                    'foreign_keys' => [
                        self::logicalForeignKeys($baselineTable['foreign_keys']),
                        self::logicalForeignKeys($candidateTable['foreign_keys']),
                    ],
                    'unique_keys' => [
                        self::logicalUniqueKeys($baselineTable['indexes']),
                        self::logicalUniqueKeys($candidateTable['indexes']),
                    ],
                ];
                foreach ($components as $component => [$expected, $actual]) {
                    if (self::canonicalJson($expected) !== self::canonicalJson($actual)) {
                        throw new CoreSchemaException([
                            'reason' => 'catalog_cross_profile_logical_mismatch',
                            'driver' => $driver,
                            'baseline_driver' => $baselineDriver,
                            'table' => $logicalName,
                            'component' => $component,
                        ]);
                    }
                }
            }
        }
        self::validateCrossProfileDefaults($profiles, $exceptions['defaults']);
        self::validateCrossProfileChecks($profiles, $exceptions['checks']);
    }

    /**
     * @param array<string, array{core_table_count: int, tables: array<string, array<string, mixed>>}> $profiles
     * @param array<string, array<string, array{profiles: array<string, string|null>, reason: string}>> $policies
     */
    private static function validateCrossProfileDefaults(array $profiles, array $policies): void
    {
        $baselineTables = $profiles[self::REQUIRED_PROFILES[0]]['tables'];
        $seen = [];
        foreach ($baselineTables as $logicalName => $table) {
            foreach ($table['columns'] as $offset => $column) {
                $columnName = (string) $column['name'];
                $values = [];
                $semanticValues = [];
                foreach (self::REQUIRED_PROFILES as $driver) {
                    $candidate = $profiles[$driver]['tables'][$logicalName]['columns'][$offset] ?? null;
                    if (!is_array($candidate) || ($candidate['name'] ?? null) !== $columnName) {
                        throw new CoreSchemaException([
                            'reason' => 'catalog_cross_profile_logical_mismatch',
                            'driver' => $driver,
                            'table' => $logicalName,
                            'component' => 'columns',
                        ]);
                    }
                    $values[$driver] = $candidate['default'];
                    $semanticValues[] = self::semanticDefault((string) $driver, $candidate['default']);
                }
                $different = count(array_unique($semanticValues, SORT_STRING)) !== 1;
                $policy = $policies[$logicalName][$columnName] ?? null;
                if (!$different) {
                    if (is_array($policy)) {
                        self::crossProfilePolicyFailure(
                            'stale_exception',
                            'default',
                            $logicalName,
                            $columnName
                        );
                    }
                    continue;
                }
                if (!is_array($policy)) {
                    self::crossProfilePolicyFailure(
                        'missing_exception',
                        'default',
                        $logicalName,
                        $columnName
                    );
                }
                if (self::canonicalJson($policy['profiles']) !== self::canonicalJson($values)) {
                    self::crossProfilePolicyFailure(
                        'exception_value_mismatch',
                        'default',
                        $logicalName,
                        $columnName
                    );
                }
                $seen[$logicalName . "\0" . $columnName] = true;
            }
        }
        foreach ($policies as $logicalName => $columns) {
            foreach ($columns as $columnName => $_entry) {
                if (!isset($seen[$logicalName . "\0" . $columnName])) {
                    self::crossProfilePolicyFailure(
                        'unknown_exception',
                        'default',
                        $logicalName,
                        $columnName
                    );
                }
            }
        }
    }

    /**
     * @param array<string, array{core_table_count: int, tables: array<string, array<string, mixed>>}> $profiles
     * @param array<string, array{profiles: array<string, list<array<string, mixed>>>, reason: string}> $policies
     */
    private static function validateCrossProfileChecks(array $profiles, array $policies): void
    {
        $baselineTables = $profiles[self::REQUIRED_PROFILES[0]]['tables'];
        $seen = [];
        foreach ($baselineTables as $logicalName => $_table) {
            $values = [];
            $semanticValues = [];
            foreach (self::REQUIRED_PROFILES as $driver) {
                $values[$driver] = $profiles[$driver]['tables'][$logicalName]['checks'];
                $semanticValues[] = self::canonicalJson($values[$driver]);
            }
            $different = count(array_unique($semanticValues, SORT_STRING)) !== 1;
            $policy = $policies[$logicalName] ?? null;
            if (!$different) {
                if (is_array($policy)) {
                    self::crossProfilePolicyFailure('stale_exception', 'check', $logicalName, null);
                }
                continue;
            }
            if (!is_array($policy)) {
                self::crossProfilePolicyFailure('missing_exception', 'check', $logicalName, null);
            }
            if (self::canonicalJson($policy['profiles']) !== self::canonicalJson($values)) {
                self::crossProfilePolicyFailure(
                    'exception_value_mismatch',
                    'check',
                    $logicalName,
                    null
                );
            }
            $seen[$logicalName] = true;
        }
        foreach ($policies as $logicalName => $_entry) {
            if (!isset($seen[$logicalName])) {
                self::crossProfilePolicyFailure('unknown_exception', 'check', $logicalName, null);
            }
        }
    }

    private static function semanticDefault(string $driver, mixed $default): string
    {
        if ($default === null) {
            return 'null';
        }
        if (!is_string($default)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_column_invalid',
                'driver' => $driver,
            ]);
        }
        if (!str_starts_with($default, 'number:')) {
            return self::canonicalJson(['literal' => $default]);
        }
        return self::canonicalJson([
            'number' => self::normalizeSemanticNumber(substr($default, strlen('number:'))),
        ]);
    }

    /** Canonical scientific notation without floating-point conversion. */
    private static function normalizeSemanticNumber(string $value): string
    {
        $value = strtolower(trim($value));
        if (preg_match(
            '/^([+-]?)([0-9]+)(?:\.([0-9]+))?(?:e([+-]?[0-9]+))?$/D',
            $value,
            $matches
        ) !== 1) {
            return $value;
        }
        $exponentText = ltrim((string) ($matches[4] ?? '0'), '+-');
        if (strlen($exponentText) > 6) {
            return $value;
        }
        $integer = $matches[2];
        $fraction = $matches[3] ?? '';
        $digits = $integer . $fraction;
        $firstNonZero = strspn($digits, '0');
        if ($firstNonZero === strlen($digits)) {
            return '0';
        }
        $significant = rtrim(substr($digits, $firstNonZero), '0');
        $exponent = strlen($integer) - 1 - $firstNonZero + (int) ($matches[4] ?? 0);
        $coefficient = $significant[0];
        if (strlen($significant) > 1) {
            $coefficient .= '.' . substr($significant, 1);
        }
        $sign = $matches[1] === '-' ? '-' : '';
        return $sign . $coefficient . 'e' . $exponent;
    }

    private static function crossProfilePolicyFailure(
        string $issue,
        string $component,
        string $logicalName,
        ?string $columnName
    ): never {
        $details = [
            'reason' => 'catalog_cross_profile_policy_mismatch',
            'issue' => $issue,
            'component' => $component,
            'table' => $logicalName,
        ];
        if ($columnName !== null) {
            $details['column'] = $columnName;
        }
        throw new CoreSchemaException($details);
    }

    /** @param list<array<string, mixed>> $columns @return list<array{name: string, nullable: bool}> */
    private static function logicalColumns(array $columns): array
    {
        return array_values(array_map(
            static fn (array $column): array => [
                'name' => (string) ($column['name'] ?? ''),
                'nullable' => (bool) ($column['nullable'] ?? false),
            ],
            $columns
        ));
    }

    /** @param list<array<string, mixed>> $indexes @return list<list<string>> */
    private static function logicalUniqueKeys(array $indexes): array
    {
        $keys = [];
        foreach ($indexes as $index) {
            if (($index['unique'] ?? null) !== true || ($index['partial'] ?? null) !== false) {
                continue;
            }
            $columns = [];
            $fullColumnKey = true;
            foreach ($index['columns'] ?? [] as $column) {
                if (($column['included'] ?? false) === true
                    || ($column['length'] ?? null) !== null
                    || !is_string($column['name'] ?? null)) {
                    $fullColumnKey = false;
                    break;
                }
                $columns[] = $column['name'];
            }
            if (!$fullColumnKey || $columns === []) {
                continue;
            }
            sort($columns, SORT_STRING);
            $keys[self::canonicalJson($columns)] = $columns;
        }
        ksort($keys, SORT_STRING);
        return array_values($keys);
    }

    /** @param list<array<string, mixed>> $foreignKeys @return list<array<string, mixed>> */
    private static function logicalForeignKeys(array $foreignKeys): array
    {
        $logical = [];
        foreach ($foreignKeys as $foreignKey) {
            $logical[] = [
                'columns' => $foreignKey['columns'],
                'referenced_table' => $foreignKey['referenced_table'],
                'referenced_columns' => $foreignKey['referenced_columns'],
                'on_update' => $foreignKey['on_update'],
                'on_delete' => $foreignKey['on_delete'],
            ];
        }
        usort(
            $logical,
            static fn (array $left, array $right): int => strcmp(
                self::canonicalJson($left),
                self::canonicalJson($right)
            )
        );
        return $logical;
    }

    /** @param list<array<string, mixed>> $entries */
    private static function assertUniqueSemanticEntries(
        array $entries,
        string $section,
        string $driver,
        string $logicalName
    ): void {
        $seen = [];
        foreach ($entries as $entry) {
            $semanticKey = self::canonicalJson($entry);
            if (isset($seen[$semanticKey])) {
                throw new CoreSchemaException([
                    'reason' => 'catalog_semantic_entry_duplicate',
                    'section' => $section,
                    'driver' => $driver,
                    'table' => $logicalName,
                ]);
            }
            $seen[$semanticKey] = true;
        }
    }

    /** @return list<string> */
    private static function registeredCoreTables(): array
    {
        $registered = PrefixedPDO::logicalTables();
        if (count($registered) !== count(array_unique($registered, SORT_STRING))) {
            throw new CoreSchemaException(['reason' => 'registered_core_tables_duplicate']);
        }
        foreach ($registered as $logicalName) {
            if (!self::validIdentifier($logicalName)) {
                throw new CoreSchemaException(['reason' => 'registered_core_table_invalid']);
            }
        }
        sort($registered, SORT_STRING);
        return array_values($registered);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $section): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new CoreSchemaException(['reason' => 'catalog_shape_invalid', 'section' => $section]);
        }
    }

    /** @param array<string, mixed> $state */
    private static function validateConstraintState(array $state, string $section): void
    {
        self::assertExactKeys($state, ['deferrable', 'initially_deferred', 'validated'], $section);
        if (!is_bool($state['deferrable'] ?? null)
            || !is_bool($state['initially_deferred'] ?? null)
            || ($state['validated'] ?? null) !== true
            || (($state['initially_deferred'] ?? false) && !($state['deferrable'] ?? false))) {
            throw new CoreSchemaException(['reason' => 'catalog_constraint_state_invalid', 'section' => $section]);
        }
    }

    /** @return list<string> */
    private static function referentialActions(): array
    {
        return ['CASCADE', 'NO ACTION', 'RESTRICT', 'SET DEFAULT', 'SET NULL'];
    }

    private static function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) === 1;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }
        return $value;
    }
}
