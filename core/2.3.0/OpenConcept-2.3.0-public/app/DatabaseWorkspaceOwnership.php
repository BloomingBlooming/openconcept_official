<?php

declare(strict_types=1);

/**
 * Owns a collision-free MySQL table namespace for one OpenConcept workspace.
 *
 * The database-level named lock serializes independent installations that
 * target the same database and prefix. The prefixed identity table is the
 * durable ownership proof and stores the exact physical-table manifest used
 * by the fail-closed deletion path.
 */
final class DatabaseWorkspaceOwnership
{
    private const IDENTITY_LOGICAL_TABLE = 'workspace_identity';

    private string $workspaceId;
    private string $prefix;
    private string $identityTable;
    private string $databaseName;
    private string $lockName;
    private bool $locked = false;

    /** @param array{workspace_id: string, table_prefix: string} $identity */
    public function __construct(private readonly PDO $pdo, array $identity)
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new DatabaseAdapterException('Workspace ownership requires MySQL.', 'driver_mismatch');
        }
        $this->workspaceId = (string) ($identity['workspace_id'] ?? '');
        $this->prefix = strtolower((string) ($identity['table_prefix'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/D', $this->workspaceId) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $this->prefix) !== 1) {
            throw new DatabaseAdapterException('Workspace ownership identity is invalid.', 'workspace_identity_invalid');
        }
        if (method_exists($pdo, 'tablePrefix') && (string) $pdo->tablePrefix() !== $this->prefix) {
            throw new DatabaseAdapterException(
                'The database connection prefix does not match the workspace identity.',
                'workspace_identity_conflict'
            );
        }
        $this->identityTable = $this->identifier($this->prefix . self::IDENTITY_LOGICAL_TABLE);
        $databaseName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($databaseName) || $databaseName === '') {
            throw new DatabaseAdapterException('The active MySQL database name is unavailable.', 'environment_incompatible');
        }
        $this->databaseName = $databaseName;
        $this->lockName = 'openconcept_workspace_' . substr(
            hash('sha256', strtolower($databaseName) . ':' . $this->prefix),
            0,
            40
        );
    }

    public function acquire(int $timeoutSeconds = 30): void
    {
        if ($this->locked) {
            throw new LogicException('The workspace ownership lock is already held.');
        }
        $statement = $this->pdo->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$this->lockName, max(1, min(60, $timeoutSeconds))]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new DatabaseAdapterException(
                'Could not acquire the shared database workspace lock.',
                'workspace_lock_unavailable'
            );
        }
        $this->locked = true;
    }

    public function release(): void
    {
        if (!$this->locked) {
            return;
        }
        try {
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$this->lockName]);
        } finally {
            $this->locked = false;
        }
    }

    /**
     * Claim an unused prefix, or adopt only an explicitly allowed legacy or
     * managed-retry table subset. Callers must hold the shared lock.
     *
     * @return array<string, mixed>
     */
    public function reserve(LogicalDatabaseSchema $schema, bool $allowExisting): array
    {
        $this->requireLock();
        $expected = $this->expectedApplicationTables($schema);
        $relations = $this->prefixedRelations();
        if (isset($relations[$this->identityTable])) {
            $this->assertNoViews($relations);
            $record = $this->readRecord();
            $this->assertRecordOwner($record);
            if ($record['status'] === 'active') {
                return $this->verifyRecord($relations, true);
            }
            if ($record['status'] !== 'reserved') {
                throw new DatabaseAdapterException(
                    'Workspace ownership is not available for migration.',
                    'workspace_identity_incomplete'
                );
            }
            $allowed = $expected;
            $allowed[] = $this->identityTable;
            $existing = array_keys($relations);
            if (array_diff($existing, $allowed) !== []) {
                throw new DatabaseAdapterException(
                    'The reserved workspace contains an unexpected database object.',
                    'workspace_manifest_mismatch'
                );
            }
            return [
                'workspace_id' => $this->workspaceId,
                'table_prefix' => $this->prefix,
                'identity_table' => $this->identityTable,
                'status' => 'reserved',
                'existing_tables' => count($existing) - 1,
            ];
        }
        $existing = array_keys($relations);
        $unexpected = array_values(array_diff($existing, $expected));
        if ($unexpected !== [] || (!$allowExisting && $existing !== [])) {
            throw new DatabaseAdapterException(
                'The application-assigned table prefix is already owned or contains unowned database objects.',
                'workspace_prefix_conflict'
            );
        }

        $identity = $this->quote($this->identityTable);
        $this->pdo->exec(<<<SQL
CREATE TABLE {$identity} (
    workspace_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    table_prefix VARCHAR(41) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    owned_tables_json JSON NOT NULL,
    owned_tables_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $manifest = [$this->identityTable];
        $encoded = $this->encodedManifest($manifest);
        $insert = $this->pdo->prepare(
            "INSERT INTO {$identity} (workspace_id, table_prefix, status, owned_tables_json, owned_tables_sha256) VALUES (?, ?, 'reserved', ?, ?)"
        );
        $insert->execute([$this->workspaceId, $this->prefix, $encoded, hash('sha256', $encoded)]);
        return [
            'workspace_id' => $this->workspaceId,
            'table_prefix' => $this->prefix,
            'identity_table' => $this->identityTable,
            'status' => 'reserved',
            'existing_tables' => count($existing),
        ];
    }

    /** @return array<string, mixed> */
    public function finalize(LogicalDatabaseSchema $schema): array
    {
        $this->requireLock();
        $expected = $this->expectedApplicationTables($schema);
        $expected[] = $this->identityTable;
        sort($expected, SORT_STRING);
        $relations = $this->prefixedRelations();
        $actual = array_keys($relations);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new DatabaseAdapterException(
                'The workspace table manifest does not match the provisioned database objects.',
                'workspace_manifest_mismatch'
            );
        }
        $this->assertNoViews($relations);
        $this->assertRecordOwner($this->readRecord());
        $encoded = $this->encodedManifest($expected);
        $update = $this->pdo->prepare(
            'UPDATE ' . $this->quote($this->identityTable)
            . " SET status = 'active', owned_tables_json = ?, owned_tables_sha256 = ? WHERE workspace_id = ? AND table_prefix = ?"
        );
        $update->execute([$encoded, hash('sha256', $encoded), $this->workspaceId, $this->prefix]);
        return $this->verifyRecord($this->prefixedRelations(), true);
    }

    /** @return array<string, mixed> */
    public function verify(): array
    {
        return $this->verifyRecord($this->prefixedRelations(), true);
    }

    /**
     * Extend an already verified manifest after trusted in-process plugin
     * schema boot. Every resulting physical table must be represented by the
     * inspected logical schema; additions, removals, and renames are recorded
     * only while the pre-change manifest proof and shared DB lock are held.
     *
     * @param array<string, mixed> $verifiedBefore
     * @return array<string, mixed>
     */
    public function reconcileTrustedSchema(
        LogicalDatabaseSchema $schema,
        array $verifiedBefore
    ): array {
        $this->requireLock();
        if (($verifiedBefore['status'] ?? null) !== 'active'
            || !hash_equals($this->workspaceId, (string) ($verifiedBefore['workspace_id'] ?? ''))
            || !hash_equals($this->prefix, (string) ($verifiedBefore['table_prefix'] ?? ''))) {
            throw new DatabaseAdapterException(
                'The pre-change workspace ownership proof is invalid.',
                'workspace_identity_invalid'
            );
        }

        $record = $this->readRecord();
        $this->assertRecordOwner($record);
        if ($record['status'] !== 'active'
            || !hash_equals(
                (string) ($verifiedBefore['owned_tables_sha256'] ?? ''),
                $record['owned_tables_sha256']
            )) {
            throw new DatabaseAdapterException(
                'Workspace ownership changed during trusted schema boot.',
                'workspace_manifest_mismatch'
            );
        }
        $before = $this->decodedManifest($record);
        $expected = $this->expectedApplicationTables($schema);
        $expected[] = $this->identityTable;
        sort($expected, SORT_STRING);
        $relations = $this->prefixedRelations();
        $this->assertNoViews($relations);
        $actual = array_keys($relations);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new DatabaseAdapterException(
                'Trusted schema boot produced an unrecognized database object.',
                'workspace_manifest_mismatch'
            );
        }
        $added = array_values(array_diff($expected, $before));
        $removed = array_values(array_diff($before, $expected));
        if ($added === [] && $removed === []) {
            return array_merge($this->verifyRecord($relations, true), [
                'changed' => false,
                'added_tables' => 0,
                'removed_tables' => 0,
            ]);
        }

        $encoded = $this->encodedManifest($expected);
        $update = $this->pdo->prepare(
            'UPDATE ' . $this->quote($this->identityTable)
            . ' SET owned_tables_json = ?, owned_tables_sha256 = ? WHERE workspace_id = ? AND table_prefix = ? AND status = ?'
        );
        $update->execute([
            $encoded,
            hash('sha256', $encoded),
            $this->workspaceId,
            $this->prefix,
            'active',
        ]);
        return array_merge($this->verifyRecord($this->prefixedRelations(), true), [
            'changed' => true,
            'added_tables' => count($added),
            'removed_tables' => count($removed),
        ]);
    }

    /**
     * Return the exact deletion plan and its required confirmation token.
     * Nothing is deleted by this method.
     *
     * @return array<string, mixed>
     */
    public function deletionPlan(): array
    {
        $this->acquire();
        try {
            $record = $this->verifiedDeletionRecord();
            $owned = $record['owned_tables'];
            $incoming = $this->incomingForeignKeys($owned);
            if ($incoming !== []) {
                throw new DatabaseAdapterException(
                    'Tables outside this workspace reference its owned tables.',
                    'workspace_external_dependency'
                );
            }
            return [
                'workspace_id' => $this->workspaceId,
                'table_prefix' => $this->prefix,
                'database' => $this->databaseName,
                'tables' => $owned,
                'table_count' => count($owned),
                'manifest_sha256' => $record['manifest_sha256'],
                'confirmation_token' => $this->confirmationToken($record['manifest_sha256']),
            ];
        } finally {
            $this->release();
        }
    }

    /**
     * Delete only the exact, ownership-verified manifest. The identity table
     * is always dropped last so an interrupted deletion can safely resume.
     *
     * @return array{workspace_id: string, table_prefix: string, deleted_tables: int}
     */
    public function deleteOwnedTables(string $confirmationToken): array
    {
        $this->acquire();
        try {
            $record = $this->verifiedDeletionRecord();
            if (!hash_equals($this->confirmationToken($record['manifest_sha256']), $confirmationToken)) {
                throw new DatabaseAdapterException(
                    'The workspace deletion confirmation token is invalid.',
                    'workspace_delete_confirmation_required'
                );
            }
            $incoming = $this->incomingForeignKeys($record['owned_tables']);
            if ($incoming !== []) {
                throw new DatabaseAdapterException(
                    'Tables outside this workspace reference its owned tables.',
                    'workspace_external_dependency'
                );
            }
            $identity = $this->quote($this->identityTable);
            $markDeleting = $this->pdo->prepare(
                "UPDATE {$identity} SET status = 'deleting' WHERE workspace_id = ? AND table_prefix = ?"
            );
            $markDeleting->execute([$this->workspaceId, $this->prefix]);

            $existing = array_keys($this->prefixedRelations());
            $toDelete = array_values(array_intersect($record['owned_tables'], $existing));
            $toDelete = array_values(array_filter(
                $toDelete,
                fn (string $table): bool => $table !== $this->identityTable
            ));
            $deleted = 0;
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($toDelete as $table) {
                    $this->pdo->exec('DROP TABLE ' . $this->quote($table));
                    $deleted++;
                }
            } finally {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            if ($this->tableExists($this->identityTable)) {
                $this->pdo->exec('DROP TABLE ' . $identity);
                $deleted++;
            }
            return [
                'workspace_id' => $this->workspaceId,
                'table_prefix' => $this->prefix,
                'deleted_tables' => $deleted,
            ];
        } finally {
            $this->release();
        }
    }

    /** @return array<string, mixed> */
    private function verifyRecord(array $relations, bool $requireActive): array
    {
        if (!isset($relations[$this->identityTable])) {
            throw new DatabaseAdapterException('Workspace ownership metadata is missing.', 'workspace_identity_missing');
        }
        $this->assertNoViews($relations);
        $record = $this->readRecord();
        $this->assertRecordOwner($record);
        if ($requireActive && $record['status'] !== 'active') {
            throw new DatabaseAdapterException('Workspace ownership is not active.', 'workspace_identity_incomplete');
        }
        $manifest = $this->decodedManifest($record);
        $actual = array_keys($relations);
        sort($actual, SORT_STRING);
        if ($manifest !== $actual) {
            throw new DatabaseAdapterException(
                'The database contains objects outside the owned workspace manifest.',
                'workspace_manifest_mismatch'
            );
        }
        return [
            'workspace_id' => $this->workspaceId,
            'table_prefix' => $this->prefix,
            'identity_table' => $this->identityTable,
            'status' => $record['status'],
            'table_count' => count($manifest),
            'owned_tables_sha256' => $record['owned_tables_sha256'],
            'verified_at' => gmdate('c'),
        ];
    }

    /** @return array{owned_tables: list<string>, manifest_sha256: string} */
    private function verifiedDeletionRecord(): array
    {
        $this->requireLock();
        $relations = $this->prefixedRelations();
        if (!isset($relations[$this->identityTable])) {
            throw new DatabaseAdapterException('Workspace ownership metadata is missing.', 'workspace_identity_missing');
        }
        $this->assertNoViews($relations);
        $record = $this->readRecord();
        $this->assertRecordOwner($record);
        if (!in_array($record['status'], ['active', 'deleting'], true)) {
            throw new DatabaseAdapterException('Workspace ownership is not deletable.', 'workspace_identity_incomplete');
        }
        $manifest = $this->decodedManifest($record);
        $actual = array_keys($relations);
        sort($actual, SORT_STRING);
        if ($record['status'] === 'active' && $actual !== $manifest) {
            throw new DatabaseAdapterException('Workspace deletion manifest does not match the database.', 'workspace_manifest_mismatch');
        }
        if (array_diff($actual, $manifest) !== [] || !in_array($this->identityTable, $actual, true)) {
            throw new DatabaseAdapterException('Workspace deletion found an unowned database object.', 'workspace_manifest_mismatch');
        }
        return ['owned_tables' => $manifest, 'manifest_sha256' => $record['owned_tables_sha256']];
    }

    /** @return array<string, string> */
    private function prefixedRelations(): array
    {
        $rows = $this->pdo->query(
            'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name'
        )->fetchAll();
        $relations = [];
        foreach ($rows as $row) {
            $name = (string) ($row['table_name'] ?? '');
            if (str_starts_with($name, $this->prefix)) {
                $relations[$name] = strtoupper((string) ($row['table_type'] ?? ''));
            }
        }
        ksort($relations, SORT_STRING);
        return $relations;
    }

    /** @return list<string> */
    private function expectedApplicationTables(LogicalDatabaseSchema $schema): array
    {
        if (isset($schema->tables()[self::IDENTITY_LOGICAL_TABLE])) {
            throw new DatabaseAdapterException('The workspace identity table name is reserved.', 'invalid_schema');
        }
        $tables = [];
        foreach (array_keys($schema->tables()) as $logicalName) {
            $tables[] = $this->identifier($this->prefix . $logicalName);
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @param array<string, string> $relations */
    private function assertNoViews(array $relations): void
    {
        foreach ($relations as $type) {
            if ($type !== 'BASE TABLE') {
                throw new DatabaseAdapterException(
                    'The workspace prefix is used by a non-table database object.',
                    'workspace_prefix_conflict'
                );
            }
        }
    }

    /** @return array{workspace_id: string, table_prefix: string, status: string, owned_tables_json: string, owned_tables_sha256: string} */
    private function readRecord(): array
    {
        try {
            $rows = $this->pdo->query(
                'SELECT workspace_id, table_prefix, status, owned_tables_json, owned_tables_sha256 FROM '
                . $this->quote($this->identityTable)
            )->fetchAll();
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'Workspace ownership metadata could not be read.',
                'workspace_identity_invalid',
                $exception
            );
        }
        if (count($rows) !== 1) {
            throw new DatabaseAdapterException('Workspace ownership metadata is invalid.', 'workspace_identity_invalid');
        }
        $row = $rows[0];
        return [
            'workspace_id' => (string) ($row['workspace_id'] ?? ''),
            'table_prefix' => (string) ($row['table_prefix'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'owned_tables_json' => (string) ($row['owned_tables_json'] ?? ''),
            'owned_tables_sha256' => (string) ($row['owned_tables_sha256'] ?? ''),
        ];
    }

    /** @param array<string, string> $record */
    private function assertRecordOwner(array $record): void
    {
        if (!hash_equals($this->workspaceId, $record['workspace_id'])
            || !hash_equals($this->prefix, $record['table_prefix'])) {
            throw new DatabaseAdapterException(
                'The table prefix belongs to a different OpenConcept workspace.',
                'workspace_prefix_conflict'
            );
        }
    }

    /** @param array<string, string> $record @return list<string> */
    private function decodedManifest(array $record): array
    {
        try {
            $tables = json_decode($record['owned_tables_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException('Workspace table manifest is invalid.', 'workspace_manifest_invalid', $exception);
        }
        if (!is_array($tables) || !array_is_list($tables) || $tables === []) {
            throw new DatabaseAdapterException('Workspace table manifest is invalid.', 'workspace_manifest_invalid');
        }
        $validated = [];
        foreach ($tables as $table) {
            if (!is_string($table)
                || $this->identifier($table) !== $table
                || !str_starts_with($table, $this->prefix)) {
                throw new DatabaseAdapterException('Workspace table manifest is invalid.', 'workspace_manifest_invalid');
            }
            $validated[] = $table;
        }
        $validated = array_values(array_unique($validated));
        sort($validated, SORT_STRING);
        if (!in_array($this->identityTable, $validated, true)) {
            throw new DatabaseAdapterException('Workspace table manifest omits its identity table.', 'workspace_manifest_invalid');
        }
        // MySQL JSON columns may normalize insignificant whitespace when the
        // value is read back. Authenticate the canonical table list rather
        // than the database driver's serialized representation.
        if (!hash_equals(
            hash('sha256', $this->encodedManifest($validated)),
            $record['owned_tables_sha256']
        )) {
            throw new DatabaseAdapterException('Workspace table manifest authentication failed.', 'workspace_manifest_invalid');
        }
        return $validated;
    }

    /** @param list<string> $tables */
    private function encodedManifest(array $tables): string
    {
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        return json_encode($tables, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $owned @return list<array{table: string, referenced_table: string, constraint: string}> */
    private function incomingForeignKeys(array $owned): array
    {
        $ownedLookup = array_fill_keys($owned, true);
        $rows = $this->pdo->query(<<<'SQL'
SELECT table_name, referenced_table_name, constraint_name
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE()
  AND referenced_table_schema = DATABASE()
  AND referenced_table_name IS NOT NULL
SQL)->fetchAll();
        $incoming = [];
        foreach ($rows as $row) {
            $table = (string) ($row['table_name'] ?? '');
            $referenced = (string) ($row['referenced_table_name'] ?? '');
            if (isset($ownedLookup[$referenced]) && !isset($ownedLookup[$table])) {
                $incoming[] = [
                    'table' => $table,
                    'referenced_table' => $referenced,
                    'constraint' => (string) ($row['constraint_name'] ?? ''),
                ];
            }
        }
        return $incoming;
    }

    private function confirmationToken(string $manifestSha256): string
    {
        return 'DELETE:' . $this->workspaceId . ':' . $manifestSha256;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? AND table_type = ?'
        );
        $statement->execute([$table, 'BASE TABLE']);
        return (int) $statement->fetchColumn() === 1;
    }

    private function requireLock(): void
    {
        if (!$this->locked) {
            throw new LogicException('The shared database workspace lock is required.');
        }
    }

    private function identifier(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $identifier) !== 1) {
            throw new DatabaseAdapterException(
                'The application-assigned prefix produces an invalid or overlong MySQL identifier.',
                'workspace_identifier_too_long'
            );
        }
        return $identifier;
    }

    private function quote(string $identifier): string
    {
        return '`' . $this->identifier($identifier) . '`';
    }
}
