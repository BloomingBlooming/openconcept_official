<?php

declare(strict_types=1);

final class RetrievalRouteStateConflict extends RuntimeException
{
}

final class RetrievalRouteStateRepository
{
    /** @var array{state: string, audit: string} */
    private array $tables;

    public function __construct(private readonly PDO $pdo)
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new RuntimeException('Retrieval route database table prefix is invalid.');
        }
        $this->tables = [
            'state' => $prefix . 'rag_route_state',
            'audit' => $prefix . 'rag_route_audit',
        ];
        foreach ($this->tables as $table) {
            if (strlen($table) > 63) {
                throw new RuntimeException('Retrieval route physical table name exceeds the portable identifier limit.');
            }
        }
    }

    /**
     * Idempotent, DB-only migration. This intentionally does not use the
     * Current Route filesystem migration lock.
     */
    public function migrate(): void
    {
        $this->assertNoActiveTransaction('migration');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $state = $this->q($this->tables['state']);
        $audit = $this->q($this->tables['audit']);
        $statements = match ($driver) {
            'sqlite' => [
                <<<SQL
CREATE TABLE IF NOT EXISTS {$state} (
    route_key VARCHAR(64) NOT NULL PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    current_path VARCHAR(32) NOT NULL,
    future_generation VARCHAR(190) NULL,
    config_revision BIGINT NOT NULL,
    rollback_readiness VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL
)
SQL,
                <<<SQL
CREATE TABLE IF NOT EXISTS {$audit} (
    route_key VARCHAR(64) NOT NULL,
    config_revision BIGINT NOT NULL,
    previous_revision BIGINT NOT NULL,
    actor_kind VARCHAR(32) NOT NULL,
    actor_user_id BIGINT NULL,
    transition_kind VARCHAR(64) NOT NULL,
    previous_state_json TEXT NULL,
    next_state_json TEXT NOT NULL,
    metadata_json TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL,
    PRIMARY KEY (route_key, config_revision)
)
SQL,
            ],
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS {$state} (route_key VARCHAR(64) PRIMARY KEY, mode VARCHAR(32) NOT NULL, current_path VARCHAR(32) NOT NULL, future_generation VARCHAR(190) NULL, config_revision BIGINT UNSIGNED NOT NULL, rollback_readiness VARCHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                "CREATE TABLE IF NOT EXISTS {$audit} (route_key VARCHAR(64) NOT NULL, config_revision BIGINT UNSIGNED NOT NULL, previous_revision BIGINT UNSIGNED NOT NULL, actor_kind VARCHAR(32) NOT NULL, actor_user_id BIGINT UNSIGNED NULL, transition_kind VARCHAR(64) NOT NULL, previous_state_json LONGTEXT NULL, next_state_json LONGTEXT NOT NULL, metadata_json LONGTEXT NOT NULL, created_at DATETIME(6) NOT NULL, PRIMARY KEY (route_key, config_revision)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            'pgsql' => [
                <<<SQL
CREATE TABLE IF NOT EXISTS {$state} (
    route_key VARCHAR(64) PRIMARY KEY,
    mode VARCHAR(32) NOT NULL,
    current_path VARCHAR(32) NOT NULL,
    future_generation VARCHAR(190) NULL,
    config_revision BIGINT NOT NULL,
    rollback_readiness VARCHAR(32) NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL
)
SQL,
                <<<SQL
CREATE TABLE IF NOT EXISTS {$audit} (
    route_key VARCHAR(64) NOT NULL,
    config_revision BIGINT NOT NULL,
    previous_revision BIGINT NOT NULL,
    actor_kind VARCHAR(32) NOT NULL,
    actor_user_id BIGINT NULL,
    transition_kind VARCHAR(64) NOT NULL,
    previous_state_json TEXT NULL,
    next_state_json TEXT NOT NULL,
    metadata_json TEXT NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    PRIMARY KEY (route_key, config_revision)
)
SQL,
            ],
            default => throw new RuntimeException('Retrieval route state does not support the active database driver.'),
        };
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
        $this->verifySchema();
    }

    /** Read-only validation for verify-only/deployment guard boot. */
    public function verify(): void
    {
        $this->verifySchema();
    }

    /** @param array<string, mixed> $legacyConfiguration */
    public function initializeFromLegacyConfiguration(
        array $legacyConfiguration,
        string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY
    ): RetrievalRouteState {
        // Reject caller-owned transactions before the route backfill starts;
        // initialization owns its row-level transaction boundary.
        $this->assertNoActiveTransaction('initialization');
        // CoreSchemaRuntime is the sole schema writer. Normal route
        // initialization must prove the already-provisioned tables instead
        // of hiding a missing/drifted canonical schema behind IF NOT EXISTS.
        $this->verify();
        $existing = $this->find($routeKey);
        if ($existing instanceof RetrievalRouteState) {
            return $existing;
        }

        $initial = RetrievalRouteState::fromLegacyConfiguration(
            $legacyConfiguration,
            $routeKey,
            $this->now()
        );
        $transactionStarted = false;
        $this->beginWriteTransaction();
        $transactionStarted = true;
        try {
            $existing = $this->findLocked($routeKey);
            if ($existing instanceof RetrievalRouteState) {
                $this->commitWriteTransaction();
                $transactionStarted = false;
                return $existing;
            }
            $table = $this->q($this->tables['state']);
            $insert = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (route_key, mode, current_path, future_generation, config_revision,
     rollback_readiness, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $insert->execute([
                $initial->routeKey,
                $initial->mode,
                $initial->currentPath,
                $initial->futureGeneration,
                $initial->configRevision,
                $initial->rollbackReadiness,
                $initial->createdAt,
                $initial->updatedAt,
            ]);
            $retrieval = is_array($legacyConfiguration['retrieval'] ?? null)
                ? $legacyConfiguration['retrieval']
                : [];
            $this->insertAudit(
                null,
                $initial,
                'system',
                null,
                'initialize_from_current',
                ['legacy_retrieval_mode' => (string) ($retrieval['mode'] ?? 'unconfigured')]
            );
            $this->commitWriteTransaction();
            $transactionStarted = false;
            return $initial;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $this->rollBackWriteTransaction();
            }
            // A concurrent initializer may have committed the same route. A
            // PostgreSQL unique violation aborts its transaction, so reread
            // only after rollback.
            $winner = $this->find($routeKey);
            if ($winner instanceof RetrievalRouteState) {
                return $winner;
            }
            throw $exception;
        }
    }

    public function find(string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY): ?RetrievalRouteState
    {
        $table = $this->q($this->tables['state']);
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE route_key = ?");
        $statement->execute([$routeKey]);
        $row = $statement->fetch();
        return is_array($row) ? RetrievalRouteState::fromRow($row) : null;
    }

    public function requireState(string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY): RetrievalRouteState
    {
        $state = $this->find($routeKey);
        if (!$state instanceof RetrievalRouteState) {
            throw new RuntimeException('Retrieval route state has not been initialized.');
        }
        return $state;
    }

    /**
     * @param callable(RetrievalRouteState): RetrievalRouteState $transition
     * @param array<string, mixed> $metadata
     */
    public function compareAndSwap(
        string $routeKey,
        int $expectedRevision,
        callable $transition,
        string $actorKind,
        ?int $actorUserId,
        string $transitionKind,
        array $metadata = []
    ): RetrievalRouteState {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('Expected route revision must be positive.');
        }
        $this->assertAuditIdentity($actorKind, $actorUserId, $transitionKind);
        $transactionStarted = false;
        $this->beginWriteTransaction();
        $transactionStarted = true;
        try {
            $previous = $this->findLocked($routeKey);
            if (!$previous instanceof RetrievalRouteState) {
                throw new RuntimeException('Retrieval route state has not been initialized.');
            }
            if ($previous->configRevision !== $expectedRevision) {
                throw new RetrievalRouteStateConflict('Retrieval route config revision is stale.');
            }
            $next = $transition($previous);
            if (!$next instanceof RetrievalRouteState
                || $next->routeKey !== $previous->routeKey
                || $next->configRevision !== $previous->configRevision + 1
                || $next->createdAt !== $previous->createdAt) {
                throw new LogicException('Retrieval route transition did not return the next aggregate revision.');
            }

            $table = $this->q($this->tables['state']);
            $update = $this->pdo->prepare(<<<SQL
UPDATE {$table}
SET mode = ?, current_path = ?, future_generation = ?, config_revision = ?,
    rollback_readiness = ?, updated_at = ?
WHERE route_key = ? AND config_revision = ?
SQL);
            $update->execute([
                $next->mode,
                $next->currentPath,
                $next->futureGeneration,
                $next->configRevision,
                $next->rollbackReadiness,
                $next->updatedAt,
                $previous->routeKey,
                $previous->configRevision,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RetrievalRouteStateConflict('Retrieval route config revision is stale.');
            }
            $this->insertAudit(
                $previous,
                $next,
                $actorKind,
                $actorUserId,
                $transitionKind,
                $metadata
            );
            $this->commitWriteTransaction();
            $transactionStarted = false;
            return $next;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                $this->rollBackWriteTransaction();
            }
            throw $exception;
        }
    }

    /** @return list<array<string, mixed>> */
    public function auditRows(string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY): array
    {
        $table = $this->q($this->tables['audit']);
        $statement = $this->pdo->prepare(
            "SELECT * FROM {$table} WHERE route_key = ? ORDER BY config_revision"
        );
        $statement->execute([$routeKey]);
        return $statement->fetchAll();
    }

    /** @return array{state: string, audit: string} */
    public function tableNames(): array
    {
        return $this->tables;
    }

    private function findLocked(string $routeKey): ?RetrievalRouteState
    {
        $table = $this->q($this->tables['state']);
        $sql = "SELECT * FROM {$table} WHERE route_key = ?";
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$routeKey]);
        $row = $statement->fetch();
        return is_array($row) ? RetrievalRouteState::fromRow($row) : null;
    }

    /** @param array<string, mixed> $metadata */
    private function insertAudit(
        ?RetrievalRouteState $previous,
        RetrievalRouteState $next,
        string $actorKind,
        ?int $actorUserId,
        string $transitionKind,
        array $metadata
    ): void {
        $this->assertAuditIdentity($actorKind, $actorUserId, $transitionKind);
        $previousJson = $previous === null ? null : $this->json($previous->toArray());
        $nextJson = $this->json($next->toArray());
        $metadataJson = $this->json($metadata);
        if (strlen($metadataJson) > 65535) {
            throw new InvalidArgumentException('Retrieval route audit metadata is too large.');
        }
        $table = $this->q($this->tables['audit']);
        $statement = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (route_key, config_revision, previous_revision, actor_kind, actor_user_id,
     transition_kind, previous_state_json, next_state_json, metadata_json, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $statement->execute([
            $next->routeKey,
            $next->configRevision,
            $previous?->configRevision ?? 0,
            $actorKind,
            $actorUserId,
            $transitionKind,
            $previousJson,
            $nextJson,
            $metadataJson,
            $next->updatedAt,
        ]);
    }

    private function assertAuditIdentity(string $actorKind, ?int $actorUserId, string $transitionKind): void
    {
        if (!in_array($actorKind, ['system', 'administrator'], true)) {
            throw new InvalidArgumentException('Retrieval route audit actor kind is invalid.');
        }
        if (($actorKind === 'administrator' && ($actorUserId ?? 0) < 1)
            || ($actorKind === 'system' && $actorUserId !== null)) {
            throw new InvalidArgumentException('Retrieval route audit actor identity is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $transitionKind) !== 1) {
            throw new InvalidArgumentException('Retrieval route transition kind is invalid.');
        }
    }

    private function beginWriteTransaction(): void
    {
        $this->assertNoActiveTransaction('transition');
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            return;
        }
        $this->pdo->beginTransaction();
    }

    private function commitWriteTransaction(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Some pdo_sqlite builds do not expose SQL-started transactions
            // through PDO::inTransaction()/commit(). Pair BEGIN IMMEDIATE with
            // SQL COMMIT, as DatabaseMigrationService does for snapshots.
            $this->pdo->exec('COMMIT');
            return;
        }
        $this->pdo->commit();
    }

    private function rollBackWriteTransaction(): void
    {
        try {
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->pdo->exec('ROLLBACK');
                return;
            }
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (Throwable) {
            // Preserve the transition/migration error that caused rollback.
        }
    }

    private function assertNoActiveTransaction(string $operation): void
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException(
                'Retrieval route ' . $operation . ' cannot run inside another transaction.'
            );
        }
    }

    private function verifySchema(): void
    {
        $state = $this->q($this->tables['state']);
        $audit = $this->q($this->tables['audit']);
        $this->pdo->query(
            "SELECT route_key, mode, current_path, future_generation, config_revision, rollback_readiness, created_at, updated_at FROM {$state} WHERE 1 = 0"
        );
        $this->pdo->query(
            "SELECT route_key, config_revision, previous_revision, actor_kind, actor_user_id, transition_kind, previous_state_json, next_state_json, metadata_json, created_at FROM {$audit} WHERE 1 = 0"
        );
        $expectedPrimaryKeys = [
            $this->tables['state'] => ['route_key'],
            $this->tables['audit'] => ['route_key', 'config_revision'],
        ];
        foreach ($expectedPrimaryKeys as $table => $expected) {
            if ($this->primaryKeyColumns($table) !== $expected) {
                throw new RuntimeException('Retrieval route primary-key schema is invalid.');
            }
        }
    }

    /** @return list<string> */
    private function primaryKeyColumns(string $table): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $rows = $this->pdo->query(
                'PRAGMA table_info(' . $this->q($table) . ')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $primary = [];
            foreach ($rows as $row) {
                $position = (int) ($row['pk'] ?? 0);
                if ($position > 0) {
                    $primary[$position] = (string) ($row['name'] ?? '');
                }
            }
            ksort($primary);
            return array_values($primary);
        }

        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT column_name, ordinal_position
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = ? AND constraint_name = 'PRIMARY'
ORDER BY ordinal_position
SQL);
        } elseif ($driver === 'pgsql') {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT key_column.column_name, key_column.ordinal_position
FROM information_schema.table_constraints AS table_constraint
JOIN information_schema.key_column_usage AS key_column
  ON key_column.constraint_catalog = table_constraint.constraint_catalog
 AND key_column.constraint_schema = table_constraint.constraint_schema
 AND key_column.constraint_name = table_constraint.constraint_name
 AND key_column.table_name = table_constraint.table_name
WHERE table_constraint.constraint_type = 'PRIMARY KEY'
  AND table_constraint.table_schema = current_schema()
  AND table_constraint.table_name = ?
ORDER BY key_column.ordinal_position
SQL);
        } else {
            throw new RuntimeException('Retrieval route schema verification does not support the active database driver.');
        }

        $statement->execute([$table]);
        $primary = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $position = (int) ($row['ordinal_position'] ?? 0);
            if ($position > 0) {
                $primary[$position] = (string) ($row['column_name'] ?? '');
            }
        }
        ksort($primary);
        return array_values($primary);
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new RuntimeException('Retrieval route SQL identifier is invalid.');
        }
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? '`' . $identifier . '`'
            : '"' . $identifier . '"';
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
