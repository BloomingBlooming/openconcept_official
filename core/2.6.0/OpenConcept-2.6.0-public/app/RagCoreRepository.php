<?php

declare(strict_types=1);

final class RagCoreRepository
{
    /** @var array<string, string> */
    private array $tables;

    public function __construct(private readonly PDO $pdo)
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new RuntimeException('RAG Core database table prefix is invalid.');
        }
        $this->tables = [
            'engines' => $prefix . 'rag_index_engines',
            'generations' => $prefix . 'rag_index_generations',
            'sync' => $prefix . 'rag_index_sync',
            'jobs' => $prefix . 'rag_index_jobs',
            'runs' => $prefix . 'rag_index_runs',
            'settings' => $prefix . 'rag_settings',
        ];
        foreach ($this->tables as $table) {
            if (strlen($table) > 63) {
                throw new RuntimeException('RAG Core physical table name exceeds the portable identifier limit.');
            }
        }
    }

    public function migrate(): void
    {
        $this->adoptLegacyPluginTables();
        match ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
            'mysql' => $this->migrateMySql(),
            'pgsql' => $this->migratePostgreSql(),
            'sqlite' => $this->migrateSqlite(),
            default => throw new RuntimeException('RAG Core does not support the active database driver.'),
        };
        $this->ensureGenerationColumns();
        $this->verifySchema();
    }

    /**
     * Read-only validation used on every marker-current boot. Keep this
     * targeted to the six Current RAG control-plane tables so ordinary
     * requests do not inspect the complete canonical database schema.
     */
    public function verifySchema(): void
    {
        $columns = [
            'engines' => [
                'engine_instance_id', 'plugin_id', 'configuration_json', 'enabled', 'status',
                'active_generation_id', 'created_at', 'updated_at',
            ],
            'generations' => [
                'index_generation_id', 'engine_instance_id', 'engine_plugin_id', 'engine_plugin_version',
                'chunker_id', 'chunker_version', 'embedding_provider_id', 'embedding_model_id',
                'embedding_model_revision', 'embedding_dimensions', 'distance_metric', 'configuration_json',
                'source_snapshot_json', 'metrics_json', 'failure_code', 'status', 'activated_at', 'created_at',
            ],
            'sync' => [
                'engine_instance_id', 'document_id', 'revision_id', 'content_hash', 'indexed_at',
                'status', 'error_code', 'updated_at',
            ],
            'jobs' => [
                'job_id', 'engine_instance_id', 'index_generation_id', 'job_type', 'target_id', 'revision_id',
                'content_hash', 'snapshot_fingerprint', 'indexed_items', 'status', 'attempts', 'max_attempts',
                'next_retry_at', 'locked_at', 'locked_by', 'error_code', 'created_at', 'updated_at', 'completed_at',
            ],
            'runs' => [
                'run_id', 'engine_instance_id', 'query_hash', 'status', 'started_at', 'completed_at', 'error_code',
            ],
            'settings' => ['setting_key', 'setting_value', 'updated_at'],
        ];
        foreach ($columns as $tableKey => $tableColumns) {
            $table = $this->q($this->tables[$tableKey]);
            $statement = $this->pdo->query(
                'SELECT ' . implode(', ', $tableColumns) . " FROM {$table} WHERE 1 = 0"
            );
            $statement->closeCursor();
        }

        $primaryKeys = [
            'engines' => ['engine_instance_id'],
            'generations' => ['index_generation_id'],
            'sync' => ['engine_instance_id', 'document_id'],
            'jobs' => ['job_id'],
            'runs' => ['run_id'],
            'settings' => ['setting_key'],
        ];
        foreach ($primaryKeys as $tableKey => $expected) {
            if ($this->primaryKeyColumns($this->tables[$tableKey]) !== $expected) {
                throw new RuntimeException('RAG Core primary-key schema is invalid.');
            }
        }
    }

    /**
     * Preview builds stored RAG Core state under plugin_rag_* names. Adopt
     * those tables in place so making RAG Core built-in does not discard
     * settings, generations, synchronization state, or queued work.
     */
    private function adoptLegacyPluginTables(): void
    {
        $prefix = method_exists($this->pdo, 'tablePrefix') ? (string) $this->pdo->tablePrefix() : '';
        $legacy = [
            'engines' => $prefix . 'plugin_rag_engines',
            'generations' => $prefix . 'plugin_rag_generations',
            'sync' => $prefix . 'plugin_rag_sync',
            'jobs' => $prefix . 'plugin_rag_jobs',
            'runs' => $prefix . 'plugin_rag_runs',
            'settings' => $prefix . 'plugin_rag_settings',
        ];
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $existence = [];
        foreach ($legacy as $key => $legacyTable) {
            $coreTable = $this->tables[$key];
            $legacyExists = $this->tableExists($legacyTable);
            $coreExists = $this->tableExists($coreTable);
            $existence[$key] = [$legacyExists, $coreExists];
            if ($legacyExists && $coreExists) {
                throw new RuntimeException(
                    'RAG Core legacy and canonical tables both exist for the same control-plane state.'
                );
            }
        }

        // Preflight every pair and build the complete rename set before
        // changing any relation. MySQL can rename all six atomically in one
        // metadata statement; SQLite/PostgreSQL keep the set in one DDL
        // transaction. A process failure must never expose mixed ownership.
        $renames = [];
        foreach ($legacy as $key => $legacyTable) {
            [$legacyExists] = $existence[$key];
            if (!$legacyExists) {
                continue;
            }
            $renames[] = [$legacyTable, $this->tables[$key]];
        }
        if ($renames === []) {
            return;
        }
        if ($driver === 'mysql') {
            $clauses = array_map(
                fn (array $rename): string => $this->q($rename[0]) . ' TO ' . $this->q($rename[1]),
                $renames
            );
            $this->pdo->exec('RENAME TABLE ' . implode(', ', $clauses));
            return;
        }
        if (!in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException('RAG Core does not support the active database driver.');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('RAG Core legacy adoption cannot start inside a transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            foreach ($renames as [$legacyTable, $coreTable]) {
                $this->pdo->exec(
                    'ALTER TABLE ' . $this->q($legacyTable) . ' RENAME TO ' . $this->q($coreTable)
                );
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function tableExists(string $table): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->pdo->prepare(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?"
            );
        } elseif ($driver === 'mysql') {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = ?
SQL);
        } else {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM information_schema.tables
WHERE table_schema = current_schema() AND table_name = ?
SQL);
        }
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $configuration */
    public function saveEngineInstance(
        string $instanceId,
        string $pluginId,
        array $configuration = [],
        bool $enabled = true
    ): void {
        $this->assertId($instanceId, 'engine instance');
        $this->assertId($pluginId, 'plugin');
        $this->rejectSecrets($configuration);
        $json = json_encode(
            $configuration,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $table = $this->q($this->tables['engines']);
        $exists = $this->pdo->prepare("SELECT 1 FROM {$table} WHERE engine_instance_id = ?");
        $exists->execute([$instanceId]);
        if ($exists->fetchColumn() !== false) {
            $statement = $this->pdo->prepare(
                "UPDATE {$table} SET plugin_id = ?, configuration_json = ?, enabled = ?, status = 'ready', updated_at = ? WHERE engine_instance_id = ?"
            );
            $statement->execute([$pluginId, $json, $enabled ? 1 : 0, $this->now(), $instanceId]);
            return;
        }
        $statement = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (engine_instance_id, plugin_id, configuration_json, enabled, status, created_at, updated_at)
VALUES (?, ?, ?, ?, 'ready', ?, ?)
SQL);
        $now = $this->now();
        $statement->execute([$instanceId, $pluginId, $json, $enabled ? 1 : 0, $now, $now]);
    }

    /** @return list<array<string, mixed>> */
    public function engineInstances(bool $enabledOnly = false): array
    {
        $table = $this->q($this->tables['engines']);
        $sql = "SELECT engine_instance_id, plugin_id, configuration_json, enabled, status, active_generation_id, created_at, updated_at FROM {$table}";
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY engine_instance_id';
        $rows = $this->pdo->query($sql)->fetchAll();
        foreach ($rows as &$row) {
            $configuration = json_decode((string) ($row['configuration_json'] ?? '{}'), true);
            $row['configuration'] = is_array($configuration) ? $configuration : [];
            unset($row['configuration_json']);
            $row['enabled'] = (int) ($row['enabled'] ?? 0) === 1;
        }
        unset($row);
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function engineInstance(string $instanceId): ?array
    {
        foreach ($this->engineInstances() as $engine) {
            if (hash_equals((string) $engine['engine_instance_id'], $instanceId)) {
                return $engine;
            }
        }
        return null;
    }

    public function setEngineEnabled(string $instanceId, bool $enabled): void
    {
        $this->assertId($instanceId, 'engine instance');
        $table = $this->q($this->tables['engines']);
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET enabled = ?, status = ?, updated_at = ? WHERE engine_instance_id = ?"
        );
        $statement->execute([$enabled ? 1 : 0, $enabled ? 'ready' : 'disabled', $this->now(), $instanceId]);
    }

    public function setEngineStatus(string $instanceId, string $status, ?bool $enabled = null): void
    {
        $this->assertId($instanceId, 'engine instance');
        if (!in_array($status, ['ready', 'disabled', 'building', 'waiting_for_embedding', 'error'], true)) {
            throw new InvalidArgumentException('RAG engine status is invalid.');
        }
        $table = $this->q($this->tables['engines']);
        if ($enabled === null) {
            $statement = $this->pdo->prepare(
                "UPDATE {$table} SET status = ?, updated_at = ? WHERE engine_instance_id = ?"
            );
            $statement->execute([$status, $this->now(), $instanceId]);
            return;
        }
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET status = ?, enabled = ?, updated_at = ? WHERE engine_instance_id = ?"
        );
        $statement->execute([$status, $enabled ? 1 : 0, $this->now(), $instanceId]);
    }

    /** @param array<string, mixed> $configuration */
    public function saveConfiguration(array $configuration): void
    {
        $this->rejectSecrets($configuration);
        $json = json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $table = $this->q($this->tables['settings']);
        $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE setting_key = ?");
        $insert = $this->pdo->prepare(
            "INSERT INTO {$table} (setting_key, setting_value, updated_at) VALUES (?, ?, ?)"
        );
        $this->pdo->beginTransaction();
        try {
            $delete->execute(['configuration']);
            $insert->execute(['configuration', $json, $this->now()]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $configuration */
    public function validateConfiguration(array $configuration): void
    {
        $this->rejectSecrets($configuration);
    }

    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $table = $this->q($this->tables['settings']);
        $statement = $this->pdo->prepare("SELECT setting_value FROM {$table} WHERE setting_key = ?");
        $statement->execute(['configuration']);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $sourceSnapshot */
    public function saveGeneration(
        string $engineInstanceId,
        IndexGeneration $generation,
        array $sourceSnapshot = []
    ): void {
        $this->assertId($engineInstanceId, 'engine instance');
        $configuration = $generation->configuration;
        $this->rejectSecrets($configuration->toArray());
        $table = $this->q($this->tables['generations']);
        $statement = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (index_generation_id, engine_instance_id, engine_plugin_id, engine_plugin_version,
     chunker_id, chunker_version, embedding_provider_id, embedding_model_id,
     embedding_model_revision, embedding_dimensions, distance_metric, configuration_json,
     source_snapshot_json, metrics_json, status, activated_at, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?)
SQL);
        $statement->execute([
            $generation->id,
            $engineInstanceId,
            $configuration->enginePluginId,
            $configuration->enginePluginVersion,
            $configuration->chunkerId,
            $configuration->chunkerVersion,
            $configuration->embeddingProviderId,
            $configuration->embeddingModelId,
            $configuration->embeddingModelRevision,
            $configuration->embeddingDimensions,
            $configuration->distanceMetric,
            json_encode($configuration->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($sourceSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            json_encode($generation->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $generation->status,
            $this->now(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function generationRow(string $generationId): ?array
    {
        $table = $this->q($this->tables['generations']);
        $statement = $this->pdo->prepare("SELECT * FROM {$table} WHERE index_generation_id = ?");
        $statement->execute([$generationId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        foreach (['configuration_json' => 'configuration', 'source_snapshot_json' => 'source_snapshot', 'metrics_json' => 'metrics'] as $field => $target) {
            $decoded = json_decode((string) ($row[$field] ?? '{}'), true);
            $row[$target] = is_array($decoded) ? $decoded : [];
            unset($row[$field]);
        }
        return $row;
    }

    /** @return list<array<string, mixed>> */
    public function generations(string $engineInstanceId): array
    {
        $this->assertId($engineInstanceId, 'engine instance');
        $table = $this->q($this->tables['generations']);
        $statement = $this->pdo->prepare(
            "SELECT index_generation_id FROM {$table} WHERE engine_instance_id = ? ORDER BY created_at DESC, index_generation_id DESC"
        );
        $statement->execute([$engineInstanceId]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $generationId) {
            $row = $this->generationRow((string) $generationId);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function activeGeneration(string $engineInstanceId): ?array
    {
        foreach ($this->generations($engineInstanceId) as $generation) {
            if ((string) ($generation['status'] ?? '') === 'active') {
                return $generation;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    public function latestBuildingGeneration(string $engineInstanceId): ?array
    {
        foreach ($this->generations($engineInstanceId) as $generation) {
            if (in_array((string) ($generation['status'] ?? ''), ['building', 'ready'], true)) {
                return $generation;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $metrics */
    public function markGeneration(
        string $generationId,
        string $status,
        array $metrics = [],
        ?string $failureCode = null
    ): void {
        if (!in_array($status, ['building', 'ready', 'active', 'failed', 'retired'], true)) {
            throw new InvalidArgumentException('RAG generation status is invalid.');
        }
        $table = $this->q($this->tables['generations']);
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET status = ?, metrics_json = ?, failure_code = ? WHERE index_generation_id = ?"
        );
        $statement->execute([
            $status,
            json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $failureCode,
            $generationId,
        ]);
    }

    public function activateGeneration(string $engineInstanceId, string $generationId): void
    {
        $this->assertId($engineInstanceId, 'engine instance');
        if ($this->pdo->inTransaction()) {
            throw new LogicException('RAG generation activation requires its own transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $generations = $this->q($this->tables['generations']);
            $target = $this->pdo->prepare(
                "SELECT * FROM {$generations} WHERE engine_instance_id = ? AND index_generation_id = ?"
            );
            $target->execute([$engineInstanceId, $generationId]);
            $row = $target->fetch();
            if (!is_array($row) || !in_array((string) $row['status'], ['ready', 'retired'], true)) {
                throw new RuntimeException('Only ready or retired RAG generations can be activated.');
            }
            $this->pdo->prepare(
                "UPDATE {$generations} SET status = 'retired' WHERE engine_instance_id = ? AND status = 'active'"
            )->execute([$engineInstanceId]);
            $now = $this->now();
            $this->pdo->prepare(
                "UPDATE {$generations} SET status = 'active', activated_at = ? WHERE index_generation_id = ?"
            )->execute([$now, $generationId]);
            $engines = $this->q($this->tables['engines']);
            $this->pdo->prepare(<<<SQL
UPDATE {$engines}
SET plugin_id = ?, configuration_json = ?, enabled = 1, status = 'ready',
    active_generation_id = ?, updated_at = ?
WHERE engine_instance_id = ?
SQL)->execute([
                (string) $row['engine_plugin_id'],
                (string) $row['configuration_json'],
                $generationId,
                $now,
                $engineInstanceId,
            ]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function enqueueSnapshot(DocumentSnapshot $snapshot, bool $force = false): int
    {
        $queued = 0;
        foreach ($this->engineInstances() as $engine) {
            $instanceId = (string) $engine['engine_instance_id'];
            $generationIds = $this->synchronizationGenerationIds($engine);
            if ($generationIds === [] && (bool) $engine['enabled']) {
                $generationIds = [null];
            }
            foreach ($generationIds as $generationId) {
                if ($this->enqueueJob(
                    $instanceId,
                    'synchronize',
                    $snapshot->documentId,
                    $snapshot->revisionId,
                    $snapshot->contentHash,
                    $this->snapshotFingerprint($snapshot),
                    $generationId,
                    $force
                )) {
                    $queued++;
                }
            }
        }
        return $queued;
    }

    public function enqueueDelete(string $documentId, string $revisionId): int
    {
        $queued = 0;
        foreach ($this->engineInstances() as $engine) {
            $instanceId = (string) $engine['engine_instance_id'];
            $generationIds = $this->synchronizationGenerationIds($engine);
            if ($generationIds === [] && (bool) $engine['enabled']) {
                $generationIds = [null];
            }
            foreach ($generationIds as $generationId) {
                if ($this->enqueueJob(
                    $instanceId,
                    'delete',
                    $documentId,
                    $revisionId,
                    '',
                    hash('sha256', 'delete|' . $documentId . '|' . $revisionId),
                    $generationId
                )) {
                    $queued++;
                }
            }
        }
        return $queued;
    }

    public function enqueueGenerationSnapshot(
        string $engineInstanceId,
        string $generationId,
        DocumentSnapshot $snapshot
    ): bool {
        return $this->enqueueJob(
            $engineInstanceId,
            'synchronize',
            $snapshot->documentId,
            $snapshot->revisionId,
            $snapshot->contentHash,
            $this->snapshotFingerprint($snapshot),
            $generationId
        );
    }

    /** @return array<string, int> */
    public function generationJobCounts(string $generationId): array
    {
        $table = $this->q($this->tables['jobs']);
        $statement = $this->pdo->prepare(
            "SELECT status, COUNT(*) AS total, SUM(indexed_items) AS indexed_total FROM {$table} WHERE index_generation_id = ? GROUP BY status"
        );
        $statement->execute([$generationId]);
        $result = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0, 'documents' => 0, 'chunks' => 0];
        foreach ($statement->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $result)) {
                $result[$status] = (int) $row['total'];
            }
        }
        // Incremental reprocessing leaves historical completed jobs in the
        // generation. Metrics describe the latest settled state per document,
        // rather than summing every historical chunk count.
        $latest = $this->pdo->prepare(<<<SQL
SELECT j.job_type, j.status, j.indexed_items
FROM {$table} j
JOIN (
    SELECT target_id, MAX(job_id) AS job_id
    FROM {$table}
    WHERE index_generation_id = ? AND status IN ('completed', 'skipped')
    GROUP BY target_id
) current_job ON current_job.job_id = j.job_id
WHERE j.index_generation_id = ?
SQL);
        $latest->execute([$generationId, $generationId]);
        foreach ($latest->fetchAll() as $job) {
            if ((string) ($job['job_type'] ?? '') !== 'synchronize') {
                continue;
            }
            $result['documents']++;
            $result['chunks'] += max(0, (int) ($job['indexed_items'] ?? 0));
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    public function claimNextJob(string $workerId): ?array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('RAG Core job claim cannot run inside another transaction.');
        }
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sqliteTransaction = $driver === 'sqlite';
        $transactionActive = false;
        $started = $sqliteTransaction
            ? $this->pdo->exec('BEGIN IMMEDIATE') !== false
            : $this->pdo->beginTransaction();
        if (!$started) {
            throw new RuntimeException('RAG Core job claim transaction could not start.');
        }
        // pdo_sqlite does not consistently expose a SQL-started BEGIN
        // IMMEDIATE through inTransaction(). Track ownership explicitly so
        // every exit path can close the same transaction boundary it opened.
        $transactionActive = true;
        try {
            $jobs = $this->q($this->tables['jobs']);
            $engines = $this->q($this->tables['engines']);
            $generations = $this->q($this->tables['generations']);
            $recover = $this->pdo->prepare(<<<SQL
UPDATE {$jobs}
SET status = 'queued', locked_at = NULL, locked_by = NULL, next_retry_at = ?, updated_at = ?
WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < ?
SQL);
            $now = $this->now();
            $recover->execute([$now, $now, date('Y-m-d H:i:s', time() - 15 * 60)]);
            $sql = <<<SQL
SELECT j.*, e.plugin_id, e.configuration_json,
       g.engine_plugin_id AS generation_plugin_id,
       g.configuration_json AS generation_configuration_json,
       g.status AS generation_status
FROM {$jobs} j
JOIN {$engines} e ON e.engine_instance_id = j.engine_instance_id
LEFT JOIN {$generations} g
  ON g.index_generation_id = j.index_generation_id
WHERE j.status = 'queued' AND j.next_retry_at <= ?
  AND (
    (j.index_generation_id IS NULL AND e.enabled = 1)
    OR g.status IN ('building', 'ready')
    OR (g.status = 'active' AND e.enabled = 1)
  )
ORDER BY j.job_id
LIMIT 1
SQL;
            if ($driver === 'pgsql') {
                // PostgreSQL rejects an unqualified FOR UPDATE when the query
                // contains a nullable LEFT JOIN. Only the queue row needs to
                // be locked before it is claimed.
                $sql .= ' FOR UPDATE OF j';
            } elseif ($driver === 'mysql') {
                $sql .= ' FOR UPDATE';
            }
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$now]);
            $job = $statement->fetch();
            if (!is_array($job)) {
                $this->finishJobClaimTransaction($sqliteTransaction, true);
                $transactionActive = false;
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE {$jobs} SET status = 'processing', attempts = attempts + 1, locked_at = ?, locked_by = ?, updated_at = ? WHERE job_id = ? AND status = 'queued'"
            );
            $now = $this->now();
            $update->execute([$now, $this->clean($workerId, 120), $now, (int) $job['job_id']]);
            if ($update->rowCount() !== 1) {
                $this->finishJobClaimTransaction($sqliteTransaction, false);
                $transactionActive = false;
                return null;
            }
            $this->finishJobClaimTransaction($sqliteTransaction, true);
            $transactionActive = false;
            $job['attempts'] = (int) $job['attempts'] + 1;
            $generationConfiguration = (string) ($job['generation_configuration_json'] ?? '');
            $configuration = json_decode(
                $generationConfiguration !== '' ? $generationConfiguration : (string) $job['configuration_json'],
                true
            );
            $job['configuration'] = is_array($configuration) ? $configuration : [];
            if ((string) ($job['generation_plugin_id'] ?? '') !== '') {
                $job['plugin_id'] = (string) $job['generation_plugin_id'];
            }
            unset($job['configuration_json'], $job['generation_configuration_json']);
            return $job;
        } catch (Throwable $exception) {
            if ($transactionActive) {
                try {
                    $this->finishJobClaimTransaction($sqliteTransaction, false);
                    $transactionActive = false;
                } catch (Throwable $rollbackException) {
                    throw new RuntimeException(
                        'RAG Core job claim rollback failed: ' . $rollbackException->getMessage(),
                        0,
                        $exception
                    );
                }
            }
            throw $exception;
        }
    }

    private function finishJobClaimTransaction(bool $sqliteTransaction, bool $commit): void
    {
        if ($sqliteTransaction) {
            $boundary = $commit ? 'COMMIT' : 'ROLLBACK';
            if ($this->pdo->exec($boundary) === false) {
                throw new RuntimeException("RAG Core SQLite job claim {$boundary} failed.");
            }
            return;
        }
        $finished = $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        if (!$finished) {
            $boundary = $commit ? 'commit' : 'rollback';
            throw new RuntimeException("RAG Core job claim {$boundary} failed.");
        }
    }

    public function completeJob(int $jobId, string $status = 'completed', int $indexedItems = 0): void
    {
        if (!in_array($status, ['completed', 'skipped'], true) || $indexedItems < 0) {
            throw new InvalidArgumentException('RAG Core job completion status is invalid.');
        }
        $table = $this->q($this->tables['jobs']);
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET status = ?, indexed_items = ?, locked_at = NULL, locked_by = NULL, error_code = NULL, completed_at = ?, updated_at = ? WHERE job_id = ?"
        );
        $now = $this->now();
        $statement->execute([$status, $indexedItems, $now, $now, $jobId]);
    }

    public function failJob(int $jobId, string $errorCode): void
    {
        $table = $this->q($this->tables['jobs']);
        $statement = $this->pdo->prepare("SELECT attempts, max_attempts FROM {$table} WHERE job_id = ?");
        $statement->execute([$jobId]);
        $job = $statement->fetch();
        if (!is_array($job)) {
            return;
        }
        $attempts = (int) $job['attempts'];
        $status = $attempts >= (int) $job['max_attempts'] ? 'failed' : 'queued';
        $retry = date('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** max(0, $attempts - 1))));
        $update = $this->pdo->prepare(<<<SQL
UPDATE {$table}
SET status = ?, next_retry_at = ?, locked_at = NULL, locked_by = NULL,
    error_code = ?, completed_at = ?, updated_at = ?
WHERE job_id = ?
SQL);
        $now = $this->now();
        $update->execute([
            $status,
            $retry,
            $this->clean($errorCode, 120),
            $status === 'failed' ? $now : null,
            $now,
            $jobId,
        ]);
    }

    public function recordSync(
        string $engineInstanceId,
        string $documentId,
        string $revisionId,
        string $contentHash,
        string $status,
        ?string $errorCode = null
    ): void {
        $table = $this->q($this->tables['sync']);
        $find = $this->pdo->prepare(
            "SELECT 1 FROM {$table} WHERE engine_instance_id = ? AND document_id = ?"
        );
        $find->execute([$engineInstanceId, $documentId]);
        $now = $this->now();
        if ($find->fetchColumn() !== false) {
            $update = $this->pdo->prepare(<<<SQL
UPDATE {$table}
SET revision_id = ?, content_hash = ?, indexed_at = ?, status = ?, error_code = ?, updated_at = ?
WHERE engine_instance_id = ? AND document_id = ?
SQL);
            $update->execute([
                $revisionId, $contentHash, $now, $status, $errorCode, $now,
                $engineInstanceId, $documentId,
            ]);
            return;
        }
        $insert = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (engine_instance_id, document_id, revision_id, content_hash, indexed_at, status, error_code, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $insert->execute([
            $engineInstanceId, $documentId, $revisionId, $contentHash,
            $now, $status, $errorCode, $now,
        ]);
    }

    public function beginRun(string $engineInstanceId, string $queryHash): int
    {
        $this->assertId($engineInstanceId, 'engine instance');
        if (preg_match('/^[a-f0-9]{64}$/D', $queryHash) !== 1) {
            throw new InvalidArgumentException('RAG run query hash is invalid.');
        }
        $table = $this->q($this->tables['runs']);
        $now = $this->now();
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $statement = $this->pdo->prepare(<<<SQL
INSERT INTO {$table} (engine_instance_id, query_hash, status, started_at)
VALUES (?, ?, 'processing', ?)
RETURNING run_id
SQL);
            $statement->execute([$engineInstanceId, $queryHash, $now]);
            return (int) $statement->fetchColumn();
        }
        $statement = $this->pdo->prepare(<<<SQL
INSERT INTO {$table} (engine_instance_id, query_hash, status, started_at)
VALUES (?, ?, 'processing', ?)
SQL);
        $statement->execute([$engineInstanceId, $queryHash, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, string $status, ?string $errorCode = null): void
    {
        if ($runId < 1 || !in_array($status, ['completed', 'failed'], true)) {
            throw new InvalidArgumentException('RAG run completion is invalid.');
        }
        $table = $this->q($this->tables['runs']);
        $statement = $this->pdo->prepare(
            "UPDATE {$table} SET status = ?, completed_at = ?, error_code = ? WHERE run_id = ? AND status = 'processing'"
        );
        $statement->execute([
            $status,
            $this->now(),
            $errorCode === null ? null : $this->clean($errorCode, 120),
            $runId,
        ]);
    }

    /** @return array<string, int> */
    public function jobCounts(): array
    {
        $table = $this->q($this->tables['jobs']);
        $result = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($this->pdo->query("SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status")->fetchAll() as $row) {
            $status = (string) ($row['status'] ?? '');
            if (array_key_exists($status, $result)) {
                $result[$status] = (int) $row['total'];
            }
        }
        return $result;
    }

    /** @return array<string, string> */
    public function tableNames(): array
    {
        return $this->tables;
    }

    /** @param array<string, mixed> $engine @return list<string> */
    private function synchronizationGenerationIds(array $engine): array
    {
        $ids = [];
        foreach ($this->generations((string) $engine['engine_instance_id']) as $generation) {
            $status = (string) ($generation['status'] ?? '');
            if (in_array($status, ['building', 'ready'], true)
                || ($status === 'active' && (bool) $engine['enabled'])) {
                $ids[] = (string) $generation['index_generation_id'];
            }
        }
        return array_values(array_unique($ids));
    }

    private function enqueueJob(
        string $engineInstanceId,
        string $jobType,
        string $targetId,
        string $revisionId,
        string $contentHash,
        string $snapshotFingerprint,
        ?string $generationId = null,
        bool $force = false
    ): bool {
        if (!in_array($jobType, ['synchronize', 'delete'], true)
            || $targetId === '' || strlen($targetId) > 160 || strlen($revisionId) > 160
            || preg_match('/^[a-f0-9]{64}$/D', $snapshotFingerprint) !== 1) {
            throw new InvalidArgumentException('RAG Core job identity is invalid.');
        }
        $table = $this->q($this->tables['jobs']);
        // PostgreSQL cannot infer the type of a NULL placeholder used only in
        // `? IS NULL`. Select the nullable-generation predicate structurally
        // so every bound placeholder has a column type context.
        $generationPredicate = $generationId === null
            ? 'index_generation_id IS NULL'
            : 'index_generation_id = ?';
        $findParameters = [$engineInstanceId, $jobType, $targetId, $revisionId, $snapshotFingerprint];
        if ($generationId !== null) {
            $findParameters[] = $generationId;
        }
        $find = $this->pdo->prepare(<<<SQL
SELECT job_id, status FROM {$table}
WHERE engine_instance_id = ? AND job_type = ? AND target_id = ? AND revision_id = ? AND snapshot_fingerprint = ?
  AND {$generationPredicate}
SQL);
        $find->execute($findParameters);
        $existing = $find->fetch();
        if (is_array($existing)) {
            if (in_array((string) ($existing['status'] ?? ''), ['queued', 'processing'], true)) {
                return false;
            }
            $latestParameters = [$engineInstanceId, $targetId];
            if ($generationId !== null) {
                $latestParameters[] = $generationId;
            }
            $latest = $this->pdo->prepare(<<<SQL
SELECT MAX(job_id) FROM {$table}
WHERE engine_instance_id = ? AND target_id = ?
  AND {$generationPredicate}
SQL);
            $latest->execute($latestParameters);
            if (!$force && (int) $latest->fetchColumn() <= (int) $existing['job_id']) {
                return false;
            }
            $now = $this->now();
            $reset = $this->pdo->prepare(<<<SQL
UPDATE {$table}
SET content_hash = ?, status = 'queued', attempts = 0, next_retry_at = ?,
    indexed_items = 0, locked_at = NULL, locked_by = NULL, error_code = NULL, completed_at = NULL, updated_at = ?
WHERE job_id = ? AND status NOT IN ('queued', 'processing')
SQL);
            $reset->execute([$contentHash, $now, $now, (int) $existing['job_id']]);
            return $reset->rowCount() === 1;
        }
        $now = $this->now();
        $insert = $this->pdo->prepare(<<<SQL
INSERT INTO {$table}
    (engine_instance_id, index_generation_id, job_type, target_id, revision_id, content_hash, snapshot_fingerprint, status,
     attempts, max_attempts, next_retry_at, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, 'queued', 0, 3, ?, ?, ?)
SQL);
        try {
            $insert->execute([
                $engineInstanceId, $generationId, $jobType, $targetId, $revisionId, $contentHash, $snapshotFingerprint,
                $now, $now, $now,
            ]);
            return true;
        } catch (PDOException $exception) {
            $find->execute($findParameters);
            if ($find->fetch() === false) {
                throw $exception;
            }
            return false;
        }
    }

    private function snapshotFingerprint(DocumentSnapshot $snapshot): string
    {
        return hash('sha256', implode('|', [
            $snapshot->revisionId,
            $snapshot->contentHash,
            $snapshot->aclVersion,
        ]));
    }

    private function migrateSqlite(): void
    {
        $t = array_map($this->q(...), $this->tables);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$t['engines']} (
    engine_instance_id TEXT PRIMARY KEY,
    plugin_id TEXT NOT NULL,
    configuration_json TEXT NOT NULL DEFAULT '{}',
    enabled INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'disabled',
    active_generation_id TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS {$t['generations']} (
    index_generation_id TEXT PRIMARY KEY,
    engine_instance_id TEXT NOT NULL,
    engine_plugin_id TEXT NULL,
    engine_plugin_version TEXT NULL,
    chunker_id TEXT NULL,
    chunker_version TEXT NULL,
    embedding_provider_id TEXT NULL,
    embedding_model_id TEXT NULL,
    embedding_model_revision TEXT NULL,
    embedding_dimensions INTEGER NULL,
    distance_metric TEXT NULL,
    configuration_json TEXT NOT NULL DEFAULT '{}',
    source_snapshot_json TEXT NOT NULL DEFAULT '{}',
    metrics_json TEXT NOT NULL DEFAULT '{}',
    failure_code TEXT NULL,
    status TEXT NOT NULL,
    activated_at TEXT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS {$t['sync']} (
    engine_instance_id TEXT NOT NULL,
    document_id TEXT NOT NULL,
    revision_id TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    indexed_at TEXT NULL,
    status TEXT NOT NULL,
    error_code TEXT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY (engine_instance_id, document_id)
);
CREATE TABLE IF NOT EXISTS {$t['jobs']} (
    job_id INTEGER PRIMARY KEY AUTOINCREMENT,
    engine_instance_id TEXT NOT NULL,
    index_generation_id TEXT NULL,
    job_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    revision_id TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    snapshot_fingerprint TEXT NOT NULL,
    indexed_items INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    next_retry_at TEXT NOT NULL,
    locked_at TEXT NULL,
    locked_by TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    UNIQUE (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint)
);
CREATE TABLE IF NOT EXISTS {$t['runs']} (
    run_id INTEGER PRIMARY KEY AUTOINCREMENT,
    engine_instance_id TEXT NOT NULL,
    query_hash TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    error_code TEXT NULL
);
CREATE TABLE IF NOT EXISTS {$t['settings']} (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS {$this->q($this->indexName('jobs_ready'))} ON {$t['jobs']} (status, next_retry_at, job_id);
SQL);
    }

    private function migrateMySql(): void
    {
        $t = array_map($this->q(...), $this->tables);
        $statements = [
            "CREATE TABLE IF NOT EXISTS {$t['engines']} (engine_instance_id VARCHAR(64) PRIMARY KEY, plugin_id VARCHAR(64) NOT NULL, configuration_json TEXT NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL DEFAULT 'disabled', active_generation_id VARCHAR(68) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$t['generations']} (index_generation_id VARCHAR(68) PRIMARY KEY, engine_instance_id VARCHAR(64) NOT NULL, engine_plugin_id VARCHAR(64) NULL, engine_plugin_version VARCHAR(64) NULL, chunker_id VARCHAR(128) NULL, chunker_version VARCHAR(64) NULL, embedding_provider_id VARCHAR(128) NULL, embedding_model_id VARCHAR(190) NULL, embedding_model_revision VARCHAR(190) NULL, embedding_dimensions INT NULL, distance_metric VARCHAR(32) NULL, configuration_json LONGTEXT NOT NULL, source_snapshot_json LONGTEXT NOT NULL, metrics_json LONGTEXT NOT NULL, failure_code VARCHAR(120) NULL, status VARCHAR(32) NOT NULL, activated_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, INDEX (engine_instance_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$t['sync']} (engine_instance_id VARCHAR(64) NOT NULL, document_id VARCHAR(160) NOT NULL, revision_id VARCHAR(160) NOT NULL, content_hash CHAR(64) NOT NULL, indexed_at DATETIME(6) NULL, status VARCHAR(32) NOT NULL, error_code VARCHAR(120) NULL, updated_at DATETIME(6) NOT NULL, PRIMARY KEY (engine_instance_id, document_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$t['jobs']} (job_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, engine_instance_id VARCHAR(64) NOT NULL, index_generation_id VARCHAR(68) NULL, job_type VARCHAR(32) NOT NULL, target_id VARCHAR(160) NOT NULL, revision_id VARCHAR(160) NOT NULL, content_hash CHAR(64) NOT NULL, snapshot_fingerprint CHAR(64) NOT NULL, indexed_items INT NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL DEFAULT 'queued', attempts INT NOT NULL DEFAULT 0, max_attempts INT NOT NULL DEFAULT 3, next_retry_at DATETIME(6) NOT NULL, locked_at DATETIME(6) NULL, locked_by VARCHAR(120) NULL, error_code VARCHAR(120) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, UNIQUE KEY uq_rag_core_job_v3 (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint), INDEX idx_rag_core_ready (status, next_retry_at, job_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$t['runs']} (run_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, engine_instance_id VARCHAR(64) NOT NULL, query_hash CHAR(64) NOT NULL, status VARCHAR(32) NOT NULL, started_at DATETIME(6) NOT NULL, completed_at DATETIME(6) NULL, error_code VARCHAR(120) NULL, INDEX (engine_instance_id, started_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS {$t['settings']} (setting_key VARCHAR(120) PRIMARY KEY, setting_value LONGTEXT NOT NULL, updated_at DATETIME(6) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }
    }

    private function migratePostgreSql(): void
    {
        $t = array_map($this->q(...), $this->tables);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$t['engines']} (
    engine_instance_id VARCHAR(64) PRIMARY KEY, plugin_id VARCHAR(64) NOT NULL,
    configuration_json TEXT NOT NULL, enabled SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'disabled', active_generation_id VARCHAR(68) NULL,
    created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS {$t['generations']} (
    index_generation_id VARCHAR(68) PRIMARY KEY, engine_instance_id VARCHAR(64) NOT NULL,
    engine_plugin_id VARCHAR(64) NULL, engine_plugin_version VARCHAR(64) NULL,
    chunker_id VARCHAR(128) NULL, chunker_version VARCHAR(64) NULL,
    embedding_provider_id VARCHAR(128) NULL, embedding_model_id VARCHAR(190) NULL,
    embedding_model_revision VARCHAR(190) NULL, embedding_dimensions INTEGER NULL,
    distance_metric VARCHAR(32) NULL, configuration_json TEXT NOT NULL,
    source_snapshot_json TEXT NOT NULL, metrics_json TEXT NOT NULL,
    failure_code VARCHAR(120) NULL, status VARCHAR(32) NOT NULL,
    activated_at TIMESTAMP NULL, created_at TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS {$t['sync']} (
    engine_instance_id VARCHAR(64) NOT NULL, document_id VARCHAR(160) NOT NULL,
    revision_id VARCHAR(160) NOT NULL, content_hash VARCHAR(64) NOT NULL, indexed_at TIMESTAMP NULL,
    status VARCHAR(32) NOT NULL, error_code VARCHAR(120) NULL, updated_at TIMESTAMP NOT NULL,
    PRIMARY KEY (engine_instance_id, document_id)
);
CREATE TABLE IF NOT EXISTS {$t['jobs']} (
    job_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    engine_instance_id VARCHAR(64) NOT NULL, index_generation_id VARCHAR(68) NULL,
    job_type VARCHAR(32) NOT NULL,
    target_id VARCHAR(160) NOT NULL, revision_id VARCHAR(160) NOT NULL, content_hash VARCHAR(64) NOT NULL,
    snapshot_fingerprint VARCHAR(64) NOT NULL,
    indexed_items INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL DEFAULT 'queued', attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3, next_retry_at TIMESTAMP NOT NULL,
    locked_at TIMESTAMP NULL, locked_by VARCHAR(120) NULL, error_code VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL, updated_at TIMESTAMP NOT NULL, completed_at TIMESTAMP NULL,
    UNIQUE (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint)
);
CREATE TABLE IF NOT EXISTS {$t['runs']} (
    run_id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
    engine_instance_id VARCHAR(64) NOT NULL, query_hash VARCHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL, started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL, error_code VARCHAR(120) NULL
);
CREATE TABLE IF NOT EXISTS {$t['settings']} (
    setting_key VARCHAR(120) PRIMARY KEY, setting_value TEXT NOT NULL, updated_at TIMESTAMP NOT NULL
);
SQL);
        $this->ensurePostgreSqlSemanticIndex(
            'generations',
            ['engine_instance_id', 'status'],
            'engine_status'
        );
        $this->ensurePostgreSqlReadyJobsIndex();
        $this->ensurePostgreSqlSemanticIndex(
            'runs',
            ['engine_instance_id', 'started_at'],
            'engine_started'
        );
    }

    private function ensureGenerationColumns(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $definitions = match ($driver) {
            'sqlite' => [
                'engines' => ['active_generation_id' => 'TEXT NULL'],
                'generations' => [
                    'engine_plugin_id' => 'TEXT NULL',
                    'engine_plugin_version' => 'TEXT NULL',
                    'chunker_id' => 'TEXT NULL',
                    'chunker_version' => 'TEXT NULL',
                    'embedding_provider_id' => 'TEXT NULL',
                    'embedding_model_id' => 'TEXT NULL',
                    'embedding_model_revision' => 'TEXT NULL',
                    'embedding_dimensions' => 'INTEGER NULL',
                    'distance_metric' => 'TEXT NULL',
                    'configuration_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'source_snapshot_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'metrics_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'failure_code' => 'TEXT NULL',
                ],
                'jobs' => [
                    'index_generation_id' => 'TEXT NULL',
                    'snapshot_fingerprint' => "TEXT NOT NULL DEFAULT ''",
                    'indexed_items' => 'INTEGER NOT NULL DEFAULT 0',
                ],
            ],
            'mysql' => [
                'engines' => ['active_generation_id' => 'VARCHAR(68) NULL'],
                'generations' => [
                    'engine_plugin_id' => 'VARCHAR(64) NULL',
                    'engine_plugin_version' => 'VARCHAR(64) NULL',
                    'chunker_id' => 'VARCHAR(128) NULL',
                    'chunker_version' => 'VARCHAR(64) NULL',
                    'embedding_provider_id' => 'VARCHAR(128) NULL',
                    'embedding_model_id' => 'VARCHAR(190) NULL',
                    'embedding_model_revision' => 'VARCHAR(190) NULL',
                    'embedding_dimensions' => 'INT NULL',
                    'distance_metric' => 'VARCHAR(32) NULL',
                    'configuration_json' => "LONGTEXT NOT NULL DEFAULT ('{}')",
                    'source_snapshot_json' => "LONGTEXT NOT NULL DEFAULT ('{}')",
                    'metrics_json' => "LONGTEXT NOT NULL DEFAULT ('{}')",
                    'failure_code' => 'VARCHAR(120) NULL',
                ],
                'jobs' => [
                    'index_generation_id' => 'VARCHAR(68) NULL',
                    'snapshot_fingerprint' => "CHAR(64) NOT NULL DEFAULT ''",
                    'indexed_items' => 'INT NOT NULL DEFAULT 0',
                ],
            ],
            'pgsql' => [
                'engines' => ['active_generation_id' => 'VARCHAR(68) NULL'],
                'generations' => [
                    'engine_plugin_id' => 'VARCHAR(64) NULL',
                    'engine_plugin_version' => 'VARCHAR(64) NULL',
                    'chunker_id' => 'VARCHAR(128) NULL',
                    'chunker_version' => 'VARCHAR(64) NULL',
                    'embedding_provider_id' => 'VARCHAR(128) NULL',
                    'embedding_model_id' => 'VARCHAR(190) NULL',
                    'embedding_model_revision' => 'VARCHAR(190) NULL',
                    'embedding_dimensions' => 'INTEGER NULL',
                    'distance_metric' => 'VARCHAR(32) NULL',
                    'configuration_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'source_snapshot_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'metrics_json' => "TEXT NOT NULL DEFAULT '{}'",
                    'failure_code' => 'VARCHAR(120) NULL',
                ],
                'jobs' => [
                    'index_generation_id' => 'VARCHAR(68) NULL',
                    'snapshot_fingerprint' => "VARCHAR(64) NOT NULL DEFAULT ''",
                    'indexed_items' => 'INTEGER NOT NULL DEFAULT 0',
                ],
            ],
            default => [],
        };
        foreach ($definitions as $tableKey => $columns) {
            $table = $this->tables[$tableKey];
            foreach ($columns as $column => $definition) {
                if (!$this->columnExists($table, $column)) {
                    $this->pdo->exec(
                        'ALTER TABLE ' . $this->q($table) . ' ADD COLUMN ' . $this->q($column) . ' ' . $definition
                    );
                }
            }
        }
        if (in_array($driver, ['mysql', 'pgsql'], true)) {
            $this->dropMigrationDefaults();
        }
        if ($driver === 'sqlite') {
            $this->upgradeSqliteJobGenerationUniqueness();
        }
        $this->upgradeJobGenerationUniqueness();
    }

    /**
     * Missing legacy columns need temporary defaults so populated tables can
     * be advanced safely. The canonical MySQL/PostgreSQL profiles do not own
     * those defaults, so remove them before exact verification and attestation.
     */
    private function dropMigrationDefaults(): void
    {
        foreach ([
            'engines' => ['configuration_json'],
            'generations' => ['configuration_json', 'source_snapshot_json', 'metrics_json'],
            'jobs' => ['snapshot_fingerprint'],
        ] as $tableKey => $columns) {
            foreach ($columns as $column) {
                $this->pdo->exec(
                    'ALTER TABLE ' . $this->q($this->tables[$tableKey])
                    . ' ALTER COLUMN ' . $this->q($column) . ' DROP DEFAULT'
                );
            }
        }
    }

    private function upgradeSqliteJobGenerationUniqueness(): void
    {
        $table = $this->tables['jobs'];
        $statement = $this->pdo->prepare(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?"
        );
        $statement->execute([$table]);
        $sql = (string) ($statement->fetchColumn() ?: '');
        $statement->closeCursor();
        if (preg_match(
            '/UNIQUE\s*\(\s*engine_instance_id\s*,\s*index_generation_id\s*,\s*job_type\s*,\s*target_id\s*,\s*revision_id\s*,\s*snapshot_fingerprint\s*\)/i',
            $sql
        ) === 1 || stripos($sql, 'UNIQUE') === false) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('RAG Core SQLite job migration requires its own transaction.');
        }
        $temporary = substr($table, 0, 46) . '_v2_' . substr(hash('sha256', $table), 0, 8);
        $jobs = $this->q($table);
        $temp = $this->q($temporary);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec(<<<SQL
CREATE TABLE {$temp} (
    job_id INTEGER PRIMARY KEY AUTOINCREMENT,
    engine_instance_id TEXT NOT NULL,
    index_generation_id TEXT NULL,
    job_type TEXT NOT NULL,
    target_id TEXT NOT NULL,
    revision_id TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    snapshot_fingerprint TEXT NOT NULL,
    indexed_items INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'queued',
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 3,
    next_retry_at TEXT NOT NULL,
    locked_at TEXT NULL,
    locked_by TEXT NULL,
    error_code TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT NULL,
    UNIQUE (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint)
);
INSERT INTO {$temp}
    (job_id, engine_instance_id, index_generation_id, job_type, target_id, revision_id,
     content_hash, snapshot_fingerprint, indexed_items, status, attempts, max_attempts, next_retry_at, locked_at, locked_by,
     error_code, created_at, updated_at, completed_at)
SELECT job_id, engine_instance_id, index_generation_id, job_type, target_id, revision_id,
       content_hash, snapshot_fingerprint, indexed_items, status, attempts, max_attempts, next_retry_at, locked_at, locked_by,
       error_code, created_at, updated_at, completed_at
FROM {$jobs};
DROP TABLE {$jobs};
ALTER TABLE {$temp} RENAME TO {$this->q($table)};
SQL);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS ' . $this->q($this->indexName('jobs_ready'))
            . ' ON ' . $this->q($table) . ' (status, next_retry_at, job_id)'
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            foreach ($this->pdo->query('PRAGMA table_info(' . $this->q($table) . ')')->fetchAll() as $row) {
                if ((string) ($row['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
SQL);
        } else {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1 FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?
SQL);
        }
        $statement->execute([$table, $column]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Static PostgreSQL provisioning uses a stable catalog index name while
     * repository-only migrations use a prefix-derived name. Index names are
     * not schema semantics, so detect the exact ready-queue index definition
     * in pg_catalog before deciding whether a second index is necessary.
     */
    private function ensurePostgreSqlReadyJobsIndex(): void
    {
        $table = $this->tables['jobs'];
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT 1
FROM pg_catalog.pg_index AS index_row
JOIN pg_catalog.pg_class AS table_relation ON table_relation.oid = index_row.indrelid
JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid = table_relation.relnamespace
JOIN pg_catalog.pg_class AS index_relation ON index_relation.oid = index_row.indexrelid
JOIN pg_catalog.pg_am AS access_method ON access_method.oid = index_relation.relam
WHERE table_namespace.nspname = current_schema()
  AND table_relation.relname = ?
  AND access_method.amname = 'btree'
  AND index_row.indisunique = FALSE
  AND index_row.indisprimary = FALSE
  AND index_row.indisvalid = TRUE
  AND index_row.indisready = TRUE
  AND index_row.indpred IS NULL
  AND index_row.indexprs IS NULL
  AND index_row.indnkeyatts = 3
  AND index_row.indnatts = 3
  -- pg_get_indexdef(column) emits COLLATE/opclass/order/null modifiers when
  -- they differ from the column's default BTREE semantics. Bare identifiers
  -- therefore prove the exact three default key definitions as well as order.
  AND pg_catalog.pg_get_indexdef(index_row.indexrelid, 1, TRUE) = 'status'
  AND pg_catalog.pg_get_indexdef(index_row.indexrelid, 2, TRUE) = 'next_retry_at'
  AND pg_catalog.pg_get_indexdef(index_row.indexrelid, 3, TRUE) = 'job_id'
LIMIT 1
SQL);
        $statement->execute([$table]);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        if ($exists) {
            return;
        }

        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS ' . $this->q($this->indexName('jobs_ready'))
            . ' ON ' . $this->q($table) . ' (status, next_retry_at, job_id)'
        );
    }

    /**
     * Ensure a canonical non-unique BTREE index without depending on its
     * physical name. Static provisioning and repository-only upgrades use
     * different stable naming schemes, but must converge on one semantic
     * index rather than creating duplicate access paths.
     *
     * @param list<string> $columns
     */
    private function ensurePostgreSqlSemanticIndex(string $tableKey, array $columns, string $purpose): void
    {
        if (!isset($this->tables[$tableKey]) || $columns === []
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $purpose) !== 1) {
            throw new LogicException('RAG Core PostgreSQL index definition is invalid.');
        }
        $columnPredicates = [];
        foreach ($columns as $position => $column) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1) {
                throw new LogicException('RAG Core PostgreSQL index column is invalid.');
            }
            $columnPredicates[] = '  AND pg_catalog.pg_get_indexdef(index_row.indexrelid, '
                . ($position + 1) . ", TRUE) = '" . $column . "'";
        }
        $columnCount = count($columns);
        if ($columnCount > 32) {
            throw new LogicException('RAG Core PostgreSQL index width is invalid.');
        }
        $columnPredicateSql = implode("\n", $columnPredicates);

        $statement = $this->pdo->prepare(<<<SQL
SELECT 1
FROM pg_catalog.pg_index AS index_row
JOIN pg_catalog.pg_class AS table_relation ON table_relation.oid = index_row.indrelid
JOIN pg_catalog.pg_namespace AS table_namespace ON table_namespace.oid = table_relation.relnamespace
JOIN pg_catalog.pg_class AS index_relation ON index_relation.oid = index_row.indexrelid
JOIN pg_catalog.pg_am AS access_method ON access_method.oid = index_relation.relam
WHERE table_namespace.nspname = current_schema()
  AND table_relation.relname = ?
  AND access_method.amname = 'btree'
  AND index_row.indisunique = FALSE
  AND index_row.indisprimary = FALSE
  AND index_row.indisvalid = TRUE
  AND index_row.indisready = TRUE
  AND index_row.indpred IS NULL
  AND index_row.indexprs IS NULL
  AND index_row.indnkeyatts = {$columnCount}
  AND index_row.indnatts = {$columnCount}
{$columnPredicateSql}
LIMIT 1
SQL);
        $table = $this->tables[$tableKey];
        $statement->execute([$table]);
        $exists = $statement->fetchColumn() !== false;
        $statement->closeCursor();
        if ($exists) {
            return;
        }

        $indexName = substr($table . '_' . $purpose, 0, 54)
            . '_' . substr(hash('sha256', $table . ':' . $purpose), 0, 8);
        $this->pdo->exec(
            'CREATE INDEX IF NOT EXISTS ' . $this->q($indexName)
            . ' ON ' . $this->q($table) . ' (' . implode(', ', array_map($this->q(...), $columns)) . ')'
        );
    }

    /** @return list<string> */
    private function primaryKeyColumns(string $table): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $statement = $this->pdo->query(
                'PRAGMA table_info(' . $this->q($table) . ')'
            );
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();
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
            throw new RuntimeException('RAG Core schema verification does not support the active database driver.');
        }

        // Constructor-resolved names are already physical when a PDO prefix
        // is active; metadata APIs do not pass through SQL identifier rewrite.
        $statement->execute([$table]);
        $primary = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $position = (int) ($row['ordinal_position'] ?? 0);
            if ($position > 0) {
                $primary[$position] = (string) ($row['column_name'] ?? '');
            }
        }
        $statement->closeCursor();
        ksort($primary);
        return array_values($primary);
    }

    private function upgradeJobGenerationUniqueness(): void
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $table = $this->tables['jobs'];
        if ($driver === 'mysql') {
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT index_name FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?
SQL);
            foreach (['uq_rag_core_job', 'uq_rag_core_job_v2', $this->indexName('job_generation_unique')] as $obsolete) {
                $statement->execute([$table, $obsolete]);
                if ($statement->fetchColumn() !== false) {
                    $this->pdo->exec('ALTER TABLE ' . $this->q($table) . ' DROP INDEX ' . $this->q($obsolete));
                }
            }
            foreach (['uq_rag_core_job_v3', $this->indexName('job_snapshot_unique')] as $current) {
                $statement->execute([$table, $current]);
                if ($statement->fetchColumn() !== false) {
                    return;
                }
            }
            $index = $this->q($this->indexName('job_snapshot_unique'));
            try {
                $this->pdo->exec(
                    "CREATE UNIQUE INDEX {$index} ON " . $this->q($table)
                    . ' (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint)'
                );
            } catch (PDOException $exception) {
                if (!str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                    throw $exception;
                }
            }
            return;
        }
        if ($driver === 'pgsql') {
            $constraints = $this->pdo->prepare(<<<'SQL'
SELECT con.conname, pg_get_constraintdef(con.oid) AS definition
FROM pg_constraint con
JOIN pg_class rel ON rel.oid = con.conrelid
JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
WHERE nsp.nspname = current_schema() AND rel.relname = ? AND con.contype = 'u'
SQL);
            $constraints->execute([$table]);
            $hasCurrentConstraint = false;
            foreach ($constraints->fetchAll() as $constraint) {
                $name = (string) ($constraint['conname'] ?? '');
                $definition = strtolower((string) ($constraint['definition'] ?? ''));
                if (str_contains($definition, 'snapshot_fingerprint')) {
                    $hasCurrentConstraint = true;
                } elseif ($name !== '') {
                    $this->pdo->exec('ALTER TABLE ' . $this->q($table) . ' DROP CONSTRAINT ' . $this->q($name));
                }
            }
            $this->pdo->exec(
                'DROP INDEX IF EXISTS ' . $this->q($this->indexName('job_generation_unique'))
            );
            if ($hasCurrentConstraint) {
                return;
            }
            $this->pdo->exec(
                'CREATE UNIQUE INDEX IF NOT EXISTS ' . $this->q($this->indexName('job_snapshot_unique'))
                . ' ON ' . $this->q($table)
                . ' (engine_instance_id, index_generation_id, job_type, target_id, revision_id, snapshot_fingerprint)'
            );
        }
    }

    /** @param array<string, mixed> $value */
    private function rejectSecrets(array $value, string $path = 'configuration'): void
    {
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if ($keyText === 'use_shared_api_key' && is_bool($item)) {
                continue;
            }
            if (preg_match('/(?:password|passwd|secret|api[_-]?key|access[_-]?token|credential)/', $keyText) === 1) {
                $isReference = preg_match('/(?:_env|_ref|_setting)$/', $keyText) === 1;
                if (!$isReference) {
                    throw new InvalidArgumentException('RAG configuration must reference secrets by environment setting, not store them.');
                }
                if (str_ends_with($keyText, '_env')
                    && (!is_string($item) || preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $item) !== 1)) {
                    throw new InvalidArgumentException('RAG secret environment reference is invalid.');
                }
            }
            if (is_array($item)) {
                $this->rejectSecrets($item, $path . '.' . $keyText);
            }
        }
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('RAG Core database identifier is invalid.');
        }
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql'
            ? '"' . $identifier . '"'
            : '`' . $identifier . '`';
    }

    private function indexName(string $purpose): string
    {
        return substr($this->tables['jobs'] . '_' . $purpose, 0, 54)
            . '_' . substr(hash('sha256', $this->tables['jobs'] . ':' . $purpose), 0, 8);
    }

    private function assertId(string $id, string $kind): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('RAG ' . $kind . ' ID is invalid.');
        }
    }

    private function clean(string $value, int $max): string
    {
        $value = preg_replace('/[\x00-\x1F]+/', ' ', trim($value)) ?? trim($value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
