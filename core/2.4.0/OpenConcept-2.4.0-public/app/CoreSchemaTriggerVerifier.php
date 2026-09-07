<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/PrefixedPDO.php';

/**
 * Verifies operational write-fence triggers separately from the immutable
 * Core table artifact. No trigger may alter the canonical shape proof.
 */
final class CoreSchemaTriggerVerifier
{
    private const WRITER_GENERATION = 'normalized-department-acl-v1';

    /** @var array<string, string> */
    private const CORE_FENCE_TABLES = [
        'users' => 'users',
        'pages' => 'pages',
        'rag' => 'rag_source_documents',
    ];

    /** @var list<string> */
    private const EVENTS = ['INSERT', 'UPDATE', 'DELETE'];

    /** @return array<string, mixed> */
    public function verify(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = $this->prefix($pdo);
        $triggers = $this->managedTriggers($pdo, $driver, $prefix);
        if ($driver !== 'mysql') {
            if ($triggers !== []) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_trigger_mismatch',
                    'driver' => $driver,
                    'policy' => 'no_user_triggers',
                    'triggers' => $this->triggerReport($triggers),
                ]);
            }
            return $this->report($driver, $prefix, $triggers, []);
        }

        $verifyOnly = defined('OPENCONCEPT_PLUGIN_VERIFY_ONLY')
            && constant('OPENCONCEPT_PLUGIN_VERIFY_ONLY') === true;
        $temporaryRows = array_values(array_filter(
            $triggers,
            static fn (array $row): bool => str_starts_with(
                (string) $row['trigger_name'],
                $prefix . 'deploy_gate_'
            ) || str_starts_with((string) $row['trigger_name'], $prefix . 'deploy_pg_')
        ));
        $token = null;
        if ($temporaryRows !== []) {
            $tokens = [];
            foreach ($temporaryRows as $row) {
                if (preg_match(
                    "/@openconcept_deployment_gate_token\s*,\s*''\)\s*<>\s*'([a-f0-9]{32})'/i",
                    (string) $row['action_statement'],
                    $match
                ) !== 1) {
                    throw new CoreSchemaException([
                        'reason' => 'canonical_trigger_mismatch',
                        'driver' => 'mysql',
                        'policy' => 'deployment_token_missing',
                        'trigger' => (string) $row['trigger_name'],
                    ]);
                }
                $tokens[$match[1]] = true;
            }
            if (count($tokens) !== 1) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_trigger_mismatch',
                    'driver' => 'mysql',
                    'policy' => 'deployment_token_inconsistent',
                ]);
            }
            $token = (string) array_key_first($tokens);
        }
        $sessionDeploymentToken = $token === null
            ? ''
            : (string) $pdo->query("SELECT COALESCE(@openconcept_deployment_gate_token, '')")->fetchColumn();
        $temporaryTriggersAllowed = $verifyOnly
            || ($token !== null && hash_equals($token, $sessionDeploymentToken));

        $sets = [
            'stable_core_writer_fence' => [
                'prefix' => $prefix . 'writer_fence_',
                'allowed' => true,
                'expected' => $this->coreTriggerMap($prefix, false, null),
            ],
            'stable_plugin_writer_fence' => [
                'prefix' => $prefix . 'writer_pg_',
                'allowed' => true,
                'expected' => $this->pluginTriggerMap($pdo, $prefix, false, null),
            ],
            'temporary_core_deployment_gate' => [
                'prefix' => $prefix . 'deploy_gate_',
                'allowed' => $temporaryTriggersAllowed,
                'expected' => $this->coreTriggerMap($prefix, true, $token),
            ],
            'temporary_plugin_deployment_gate' => [
                'prefix' => $prefix . 'deploy_pg_',
                'allowed' => $temporaryTriggersAllowed,
                'expected' => $this->pluginTriggerMap($pdo, $prefix, true, $token),
            ],
        ];
        $actualByName = [];
        foreach ($triggers as $trigger) {
            $actualByName[(string) $trigger['trigger_name']] = $trigger;
        }
        $accepted = [];
        $activeSets = [];
        foreach ($sets as $setName => $set) {
            $present = array_filter(
                $actualByName,
                static fn (array $row, string $name): bool => str_starts_with(
                    $name,
                    (string) $set['prefix']
                ),
                ARRAY_FILTER_USE_BOTH
            );
            if ($present === []) {
                $activeSets[$setName] = false;
                continue;
            }
            if ($set['allowed'] !== true) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_trigger_mismatch',
                    'driver' => 'mysql',
                    'policy' => 'temporary_trigger_outside_verify_only',
                    'trigger_set' => $setName,
                ]);
            }
            $expected = $set['expected'];
            $actualNames = array_keys($present);
            $expectedNames = array_keys($expected);
            sort($actualNames, SORT_STRING);
            sort($expectedNames, SORT_STRING);
            if ($actualNames !== $expectedNames) {
                throw new CoreSchemaException([
                    'reason' => 'canonical_trigger_mismatch',
                    'driver' => 'mysql',
                    'policy' => 'trigger_set_incomplete',
                    'trigger_set' => $setName,
                    'actual_names' => $actualNames,
                    'expected_names' => $expectedNames,
                ]);
            }
            foreach ($expected as $name => $definition) {
                $this->assertDefinition($present[$name], $definition, $setName);
                $accepted[$name] = true;
            }
            $activeSets[$setName] = true;
        }
        $unknown = array_values(array_diff(array_keys($actualByName), array_keys($accepted)));
        sort($unknown, SORT_STRING);
        if ($unknown !== []) {
            throw new CoreSchemaException([
                'reason' => 'canonical_trigger_mismatch',
                'driver' => 'mysql',
                'policy' => 'unknown_managed_trigger',
                'triggers' => $unknown,
            ]);
        }
        return $this->report($driver, $prefix, $triggers, $activeSets);
    }

    /** @return array<string, mixed> */
    public function assertNoneForDestination(PDO $pdo): array
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = $this->prefix($pdo);
        $triggers = $this->managedTriggers($pdo, $driver, $prefix);
        if ($triggers !== []) {
            throw new CoreSchemaException([
                'reason' => 'canonical_trigger_mismatch',
                'driver' => $driver,
                'policy' => 'destination_triggers_forbidden',
                'triggers' => $this->triggerReport($triggers),
            ]);
        }
        return $this->report($driver, $prefix, [], []);
    }

    /** @return list<array<string, string>> */
    private function managedTriggers(PDO $pdo, string $driver, string $prefix): array
    {
        try {
            $rows = match ($driver) {
                'sqlite' => $pdo->query(<<<'SQL'
SELECT name AS trigger_name, tbl_name AS event_object_table,
       '' AS event_manipulation, '' AS action_timing,
       'ROW' AS action_orientation, sql AS action_statement,
       'main' AS inventory_schema
FROM sqlite_schema
WHERE type = 'trigger'
UNION ALL
SELECT name AS trigger_name, tbl_name AS event_object_table,
       '' AS event_manipulation, '' AS action_timing,
       'ROW' AS action_orientation, sql AS action_statement,
       'temp' AS inventory_schema
FROM sqlite_temp_schema
WHERE type = 'trigger'
ORDER BY trigger_name, inventory_schema
SQL)->fetchAll(PDO::FETCH_ASSOC),
                'mysql' => $pdo->query(<<<'SQL'
SELECT trigger_name, event_object_table, event_manipulation,
       action_timing, action_orientation, action_statement
FROM information_schema.triggers
WHERE trigger_schema = DATABASE()
ORDER BY CAST(trigger_name AS BINARY)
SQL)->fetchAll(PDO::FETCH_ASSOC),
                'pgsql' => $pdo->query(<<<'SQL'
SELECT trigger_row.tgname AS trigger_name,
       table_row.relname AS event_object_table,
       '' AS event_manipulation, '' AS action_timing,
       'ROW' AS action_orientation,
       pg_get_triggerdef(trigger_row.oid, true) AS action_statement
FROM pg_trigger trigger_row
JOIN pg_class table_row ON table_row.oid = trigger_row.tgrelid
JOIN pg_namespace namespace_row ON namespace_row.oid = table_row.relnamespace
WHERE namespace_row.nspname = current_schema()
  AND NOT trigger_row.tgisinternal
ORDER BY trigger_row.tgname
SQL)->fetchAll(PDO::FETCH_ASSOC),
                default => throw new CoreSchemaException([
                    'reason' => 'driver_profile_unsupported',
                    'driver' => $driver,
                ]),
            };
        } catch (CoreSchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CoreSchemaException([
                'reason' => 'trigger_inventory_failed',
                'driver' => $driver,
            ], $exception);
        }
        $registered = array_fill_keys(PrefixedPDO::logicalTables(), true);
        $triggers = [];
        foreach ($rows as $rawRow) {
            $row = array_change_key_case($rawRow, CASE_LOWER);
            $physicalTable = (string) ($row['event_object_table'] ?? '');
            $scoped = $prefix === '' || str_starts_with($physicalTable, $prefix);
            if (!$scoped) {
                continue;
            }
            $logicalTable = $prefix === ''
                ? strtolower($physicalTable)
                : strtolower(substr($physicalTable, strlen($prefix)));
            if (!isset($registered[$logicalTable])
                && !str_starts_with($logicalTable, 'plugin_')
                && $logicalTable !== 'workspace_identity') {
                continue;
            }
            $triggers[] = [
                'trigger_name' => (string) ($row['trigger_name'] ?? ''),
                'event_object_table' => $physicalTable,
                'event_manipulation' => strtoupper((string) ($row['event_manipulation'] ?? '')),
                'action_timing' => strtoupper((string) ($row['action_timing'] ?? '')),
                'action_orientation' => strtoupper((string) ($row['action_orientation'] ?? '')),
                'action_statement' => (string) ($row['action_statement'] ?? ''),
            ];
        }
        usort($triggers, static fn (array $left, array $right): int => strcmp(
            $left['trigger_name'],
            $right['trigger_name']
        ));
        return $triggers;
    }

    /** @return array<string, array<string, string>> */
    private function coreTriggerMap(string $prefix, bool $temporary, ?string $token): array
    {
        $map = [];
        $namePrefix = $prefix . ($temporary ? 'deploy_gate_' : 'writer_fence_');
        foreach (self::CORE_FENCE_TABLES as $label => $logicalTable) {
            foreach (self::EVENTS as $event) {
                $name = $namePrefix . $label . '_' . strtolower($event);
                $action = $temporary
                    ? $this->gateAction(
                        "COALESCE(@openconcept_deployment_gate_token, '') <> '" . (string) $token . "'",
                        'OpenConcept deployment write gate'
                    )
                    : $this->gateAction(
                        "COALESCE(@openconcept_writer_generation, '') <> '" . self::WRITER_GENERATION . "'",
                        'OpenConcept writer generation mismatch'
                    );
                $map[$name] = $this->definition($prefix . $logicalTable, $event, $action);
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @return array<string, array<string, string>> */
    private function pluginTriggerMap(PDO $pdo, string $prefix, bool $temporary, ?string $token): array
    {
        $tables = [];
        $statement = $pdo->query(
            "SELECT table_name FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' ORDER BY table_name"
        );
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (is_string($table) && str_starts_with($table, $prefix . 'plugin_')) {
                $tables[] = $table;
            }
        }
        $tables[] = $prefix . 'system_settings';
        $tables = array_values(array_unique($tables, SORT_STRING));
        sort($tables, SORT_STRING);
        $map = [];
        $namePrefix = $prefix . ($temporary ? 'deploy_pg_' : 'writer_pg_');
        foreach ($tables as $table) {
            $settingsScope = $table === $prefix . 'system_settings';
            foreach (self::EVENTS as $event) {
                $name = $namePrefix . strtolower($event[0]) . '_'
                    . substr(hash('sha256', $table . "\0" . $event), 0, 32);
                $mismatch = $temporary
                    ? "COALESCE(@openconcept_deployment_gate_token, '') <> '" . (string) $token . "'"
                    : "COALESCE(@openconcept_writer_generation, '') <> '" . self::WRITER_GENERATION . "'";
                $condition = $mismatch;
                if ($settingsScope) {
                    $prefixCheck = "CAST(LEFT(%s.setting_key, CHAR_LENGTH('plugin.')) AS BINARY) = CAST('plugin.' AS BINARY)";
                    $settingsCondition = match ($event) {
                        'INSERT' => sprintf($prefixCheck, 'NEW'),
                        'DELETE' => sprintf($prefixCheck, 'OLD'),
                        'UPDATE' => '(' . sprintf($prefixCheck, 'OLD') . ' OR '
                            . sprintf($prefixCheck, 'NEW') . ')',
                    };
                    $condition = $settingsCondition . ' AND ' . $mismatch;
                }
                $message = $temporary
                    ? 'OpenConcept plugin deployment write gate'
                    : 'OpenConcept plugin writer generation mismatch';
                $map[$name] = $this->definition(
                    $table,
                    $event,
                    $this->gateAction($condition, $message)
                );
            }
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @return array<string, string> */
    private function definition(string $table, string $event, string $action): array
    {
        return [
            'table' => $table,
            'event' => $event,
            'timing' => 'BEFORE',
            'orientation' => 'ROW',
            'action' => $action,
        ];
    }

    private function gateAction(string $condition, string $message): string
    {
        return "BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
    }

    /** @param array<string, string> $actual @param array<string, string> $expected */
    private function assertDefinition(array $actual, array $expected, string $set): void
    {
        if ((string) $actual['event_object_table'] !== $expected['table']
            || (string) $actual['event_manipulation'] !== $expected['event']
            || (string) $actual['action_timing'] !== $expected['timing']
            || (string) $actual['action_orientation'] !== $expected['orientation']
            || $this->normalizeSql((string) $actual['action_statement'])
                !== $this->normalizeSql($expected['action'])) {
            throw new CoreSchemaException([
                'reason' => 'canonical_trigger_mismatch',
                'driver' => 'mysql',
                'policy' => 'trigger_definition_drift',
                'trigger_set' => $set,
                'trigger' => (string) $actual['trigger_name'],
            ]);
        }
    }

    /** @param list<array<string, string>> $triggers @param array<string, bool> $sets */
    private function report(string $driver, string $prefix, array $triggers, array $sets): array
    {
        return [
            'driver' => $driver,
            'prefix' => $prefix,
            'artifact_evidence_used' => false,
            'target_trigger_count' => count($triggers),
            'active_trigger_sets' => $sets,
            'triggers' => $this->triggerReport($triggers),
        ];
    }

    /** @param list<array<string, string>> $triggers @return list<array<string, string>> */
    private function triggerReport(array $triggers): array
    {
        return array_map(static fn (array $trigger): array => [
            'name' => (string) $trigger['trigger_name'],
            'table' => (string) $trigger['event_object_table'],
            'event' => (string) $trigger['event_manipulation'],
            'timing' => (string) $trigger['action_timing'],
            'orientation' => (string) $trigger['action_orientation'],
        ], $triggers);
    }

    private function normalizeSql(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', trim($sql)));
    }

    private function prefix(PDO $pdo): string
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new CoreSchemaException(['reason' => 'table_prefix_invalid']);
        }
        return $prefix;
    }
}
