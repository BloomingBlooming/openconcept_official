<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaSignature.php';
require_once __DIR__ . '/CoreSchemaTriggerVerifier.php';

/**
 * Compares a live database with its exact driver profile in the active Core schema.
 */
final class CoreSchemaVerifier
{
    private CoreSchemaCatalog $catalog;
    private CoreSchemaSignature $signature;

    public function __construct(
        ?CoreSchemaCatalog $catalog = null,
        ?CoreSchemaSignature $signature = null
    ) {
        $this->catalog = $catalog ?? CoreSchemaCatalog::load();
        $this->signature = $signature ?? new CoreSchemaSignature();
    }

    /** @return array<string, mixed> */
    public function verify(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, CoreSchemaSignature::supportedDrivers(), true)) {
            throw new CoreSchemaException([
                'reason' => 'driver_profile_unsupported',
                'driver' => $driver,
            ]);
        }
        if (!$this->catalog->hasProfile($driver)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_profile_missing',
                'driver' => $driver,
            ]);
        }
        $report = $this->verifyTables($pdo, $this->catalog->tableNames($driver), true);
        $report['trigger_verification'] = $this->verifyOperationalTriggers($pdo);
        return $report;
    }

    /** @return array<string, mixed> */
    public function verifyOperationalTriggers(PDO $pdo): array
    {
        return (new CoreSchemaTriggerVerifier())->verify($pdo);
    }

    /**
     * Verify a selected Core ownership boundary without treating other known
     * Core tables as proof for the selection. Markers are never consulted.
     *
     * @param list<string> $logicalNames
     * @return array<string, mixed>
     */
    public function verifyTables(PDO $pdo, array $logicalNames, bool $rejectExtra = false): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, CoreSchemaSignature::supportedDrivers(), true)) {
            throw new CoreSchemaException([
                'reason' => 'driver_profile_unsupported',
                'driver' => $driver,
            ]);
        }
        if (!$this->catalog->hasProfile($driver)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_profile_missing',
                'driver' => $driver,
            ]);
        }

        $catalogNames = $this->catalog->tableNames($driver);
        $selection = $this->normalizeSelection($logicalNames, $catalogNames);
        try {
            $inspection = $this->signature->inspect($pdo, $selection);
        } catch (CoreSchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CoreSchemaException([
                'reason' => 'live_schema_inspection_failed',
                'driver' => $driver,
            ], $exception);
        }

        $relationsByLogicalName = [];
        $inventoryMismatches = [];
        $classified = [
            'plugin' => [],
            'adapter_metadata' => [],
            'engine_metadata' => [],
            'unclassified' => [],
            'external' => [],
        ];
        foreach ($inspection['relations'] as $relation) {
            if (($relation['scoped'] ?? false) === true) {
                $logicalName = (string) $relation['logical_name'];
                if (isset($relationsByLogicalName[$logicalName])) {
                    $inventoryMismatches[] = [
                        'kind' => 'duplicate_scoped_relation',
                        'table' => $logicalName,
                    ];
                }
                $expectedPhysicalName = (string) $inspection['prefix'] . $logicalName;
                if (!isset($relationsByLogicalName[$logicalName])
                    || (string) ($relation['physical_name'] ?? '') === $expectedPhysicalName) {
                    $relationsByLogicalName[$logicalName] = $relation;
                }
            }
            $classification = (string) ($relation['classification'] ?? 'unclassified');
            if (isset($classified[$classification])) {
                $classified[$classification][] = $this->relationReport($relation);
            }
        }
        foreach ($classified as &$relations) {
            usort($relations, static fn (array $left, array $right): int => strcmp($left['physical_name'], $right['physical_name']));
        }
        unset($relations);

        $mismatches = $inventoryMismatches;
        $selectedSet = array_fill_keys($selection, true);
        foreach ($selection as $logicalName) {
            $relation = $relationsByLogicalName[$logicalName] ?? null;
            if (!is_array($relation)) {
                $mismatches[] = ['kind' => 'missing_core_relation', 'table' => $logicalName];
                continue;
            }
            if (($relation['type'] ?? null) !== 'table') {
                $mismatches[] = [
                    'kind' => 'core_relation_type_drift',
                    'table' => $logicalName,
                    'actual_type' => (string) ($relation['type'] ?? ''),
                ];
                continue;
            }
            $actual = $inspection['tables'][$logicalName] ?? null;
            if (!is_array($actual)) {
                $mismatches[] = ['kind' => 'missing_core_relation', 'table' => $logicalName];
                continue;
            }
            $expected = $this->catalog->table($driver, $logicalName);
            array_push($mismatches, ...$this->compareTable($logicalName, $expected, $actual));
        }

        if ($rejectExtra) {
            foreach ($relationsByLogicalName as $logicalName => $relation) {
                if (($relation['classification'] ?? null) === 'core' && !isset($selectedSet[$logicalName])) {
                    $mismatches[] = ['kind' => 'extra_core_relation', 'table' => $logicalName];
                }
            }
            foreach ($classified['unclassified'] as $relation) {
                $mismatches[] = [
                    'kind' => 'unclassified_relation',
                    'table' => (string) $relation['logical_name'],
                    'relation_type' => (string) $relation['type'],
                ];
            }
        }
        usort($mismatches, static function (array $left, array $right): int {
            return strcmp(
                CoreSchemaCatalog::canonicalJson($left),
                CoreSchemaCatalog::canonicalJson($right)
            );
        });

        $report = [
            'driver' => $driver,
            'profile' => $driver,
            'schema_version' => $this->catalog->schemaVersion(),
            'artifact_hash' => $this->catalog->artifactHash(),
            'verified_core_tables' => $selection,
            'reject_extra_core_relations' => $rejectExtra,
            'marker_evidence_used' => false,
            'plugin_relations' => $classified['plugin'],
            'adapter_metadata_relations' => $classified['adapter_metadata'],
            'engine_metadata_relations' => $classified['engine_metadata'],
            'unclassified_relations' => $classified['unclassified'],
            'external_relations' => $classified['external'],
            'mismatches' => $mismatches,
        ];
        if ($mismatches !== []) {
            throw new CoreSchemaException($report);
        }
        return $report;
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $actual
     * @return list<array<string, mixed>>
     */
    private function compareTable(string $logicalName, array $expected, array $actual): array
    {
        $mismatches = [];
        if (($actual['relation'] ?? null) !== ($expected['relation'] ?? null)) {
            $mismatches[] = ['kind' => 'core_relation_type_drift', 'table' => $logicalName];
        }

        $expectedColumns = $this->columnsByName($expected['columns'] ?? []);
        $actualColumns = $this->columnsByName($actual['columns'] ?? []);
        foreach (array_diff(array_keys($expectedColumns), array_keys($actualColumns)) as $column) {
            $mismatches[] = ['kind' => 'missing_core_column', 'table' => $logicalName, 'column' => $column];
        }
        foreach (array_diff(array_keys($actualColumns), array_keys($expectedColumns)) as $column) {
            $mismatches[] = ['kind' => 'extra_core_column', 'table' => $logicalName, 'column' => $column];
        }
        foreach (array_intersect(array_keys($expectedColumns), array_keys($actualColumns)) as $column) {
            if (!$this->semanticallyEqual($expectedColumns[$column], $actualColumns[$column])) {
                $mismatches[] = ['kind' => 'core_column_drift', 'table' => $logicalName, 'column' => $column];
            }
        }
        $expectedOrder = array_values(array_map('strval', array_column($expected['columns'] ?? [], 'name')));
        $actualOrder = array_values(array_map('strval', array_column($actual['columns'] ?? [], 'name')));
        if ($expectedOrder !== $actualOrder) {
            $mismatches[] = ['kind' => 'core_column_order_drift', 'table' => $logicalName];
        }
        if (($expected['primary_key'] ?? null) !== ($actual['primary_key'] ?? null)) {
            $mismatches[] = ['kind' => 'core_primary_key_drift', 'table' => $logicalName];
        }
        if (!$this->semanticallyEqual(
            $expected['primary_key_options'] ?? null,
            $actual['primary_key_options'] ?? null
        )) {
            $mismatches[] = ['kind' => 'core_primary_key_options_drift', 'table' => $logicalName];
        }
        if (!$this->semanticallyEqual($expected['foreign_keys'] ?? null, $actual['foreign_keys'] ?? null)) {
            $mismatches[] = ['kind' => 'core_foreign_key_drift', 'table' => $logicalName];
        }
        if (!$this->semanticallyEqual($expected['indexes'] ?? null, $actual['indexes'] ?? null)) {
            $mismatches[] = ['kind' => 'core_index_drift', 'table' => $logicalName];
        }
        if (!$this->semanticallyEqual($expected['checks'] ?? null, $actual['checks'] ?? null)) {
            $mismatches[] = ['kind' => 'core_check_constraint_drift', 'table' => $logicalName];
        }
        if (!$this->semanticallyEqual($expected['options'] ?? null, $actual['options'] ?? null)) {
            $mismatches[] = ['kind' => 'core_table_options_drift', 'table' => $logicalName];
        }
        return $mismatches;
    }

    /** @param mixed $columns @return array<string, array<string, mixed>> */
    private function columnsByName(mixed $columns): array
    {
        if (!is_array($columns)) {
            return [];
        }
        $byName = [];
        foreach ($columns as $column) {
            if (is_array($column) && is_string($column['name'] ?? null)) {
                $byName[$column['name']] = $column;
            }
        }
        return $byName;
    }

    private function semanticallyEqual(mixed $expected, mixed $actual): bool
    {
        if (!is_array($expected) || !is_array($actual)) {
            return $expected === $actual;
        }
        return CoreSchemaCatalog::canonicalJson($expected)
            === CoreSchemaCatalog::canonicalJson($actual);
    }

    /** @param array<string, mixed> $relation @return array<string, string> */
    private function relationReport(array $relation): array
    {
        return [
            'logical_name' => (string) ($relation['logical_name'] ?? ''),
            'physical_name' => (string) ($relation['physical_name'] ?? ''),
            'type' => (string) ($relation['type'] ?? ''),
        ];
    }

    /** @param list<string> $selection @param list<string> $catalogNames @return list<string> */
    private function normalizeSelection(array $selection, array $catalogNames): array
    {
        if ($selection === []) {
            throw new CoreSchemaException(['reason' => 'core_table_selection_empty']);
        }
        $normalized = [];
        foreach ($selection as $logicalName) {
            if (!is_string($logicalName) || !in_array($logicalName, $catalogNames, true)) {
                throw new CoreSchemaException([
                    'reason' => 'core_table_selection_invalid',
                    'table' => is_scalar($logicalName) ? (string) $logicalName : '',
                ]);
            }
            $normalized[] = $logicalName;
        }
        $normalized = array_values(array_unique($normalized, SORT_STRING));
        sort($normalized, SORT_STRING);
        return $normalized;
    }
}
