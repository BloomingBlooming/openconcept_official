<?php

declare(strict_types=1);

final class RagPipeline
{
    public const PROMPT_VERSION = 'rag-v2-metadata-2026-08-24';
    public const SOURCE_SCHEMA_VERSION = 4;
    private const PAGE_TITLE_BLOCK_ID = '__page_title__';

    private PDO $pdo;
    private ?OpenAIClient $client;

    public function __construct(PDO $pdo, ?OpenAIClient $client = null)
    {
        $this->pdo = $pdo;
        $this->client = $client;
    }

    /** @return array{state: string, job_id?: int, source_hash?: string, effort?: string} */
    public function queuePage(int $pageId, bool $force = false): array
    {
        // Manual tags are user-owned source data. Normalize their identities
        // independently of page publication and never rewrite the page values.
        $this->syncManualTags($pageId);
        $page = null;
        $units = [];
        $sourceHash = '';
        $effort = 'low';
        $snapshotStored = false;
        for ($snapshotAttempt = 0; $snapshotAttempt < 3; $snapshotAttempt++) {
            $page = $this->loadPage($pageId);
            if (!$page) {
                throw new RuntimeException('RAG対象のページが見つかりません。');
            }
            if ((string) $page['status'] !== 'published' || $page['archived_at'] !== null) {
                if ($this->deactivatePage($pageId)) {
                    return ['state' => 'inactive'];
                }
                // The unlocked read was stale and the page became publishable.
                // Retry from the live generation instead of deactivating it.
                continue;
            }

            $units = $this->extractUnits($page);
            $sourceHash = $this->sourceHash($page, $units);
            $effort = self::routeEffort($units);
            if ($this->storeSourceSnapshot($page, $units, $sourceHash)) {
                $snapshotStored = true;
                break;
            }
        }
        if (!$snapshotStored) {
            return ['state' => 'stale', 'source_hash' => $sourceHash, 'effort' => $effort];
        }
        if (class_exists(Hooks::class, false)) {
            Hooks::action('rag_document_snapshot_updated', $pageId, $sourceHash);
        }

        if (!$force) {
            $profile = $this->pdo->prepare(
                'SELECT 1 FROM rag_page_profiles WHERE page_id = ? AND source_hash = ? AND prompt_version = ?'
            );
            $profile->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
            $activeChunks = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM rag_chunks
WHERE page_id = ? AND source_hash = ? AND prompt_version = ? AND is_active = 1
SQL);
            $activeChunks->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
            if ((bool) $profile->fetchColumn() && (int) $activeChunks->fetchColumn() > 0) {
                return ['state' => 'current', 'source_hash' => $sourceHash, 'effort' => $effort];
            }
        }

        $existing = $this->pdo->prepare('SELECT id, status FROM rag_jobs WHERE page_id = ? AND source_hash = ? AND prompt_version = ?');
        $existing->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
        $job = $existing->fetch();
        if ($job) {
            if ($force || in_array((string) $job['status'], ['completed', 'failed', 'skipped'], true)) {
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE rag_jobs
SET status = 'queued', reasoning_effort = ?, attempts = 0, available_at = ?, locked_at = NULL,
    locked_by = NULL, last_error = NULL, completed_at = NULL, updated_at = ?
WHERE id = ?
SQL);
                $now = $this->now();
                $update->execute([$effort, $now, $now, (int) $job['id']]);
                return ['state' => 'queued', 'job_id' => (int) $job['id'], 'source_hash' => $sourceHash, 'effort' => $effort];
            }
            return ['state' => (string) $job['status'], 'job_id' => (int) $job['id'], 'source_hash' => $sourceHash, 'effort' => $effort];
        }

        $now = $this->now();
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_jobs
    (page_id, source_hash, prompt_version, status, reasoning_effort, attempts, max_attempts, available_at, created_at, updated_at)
VALUES (?, ?, ?, 'queued', ?, 0, 3, ?, ?, ?)
SQL);
        try {
            $insert->execute([$pageId, $sourceHash, self::PROMPT_VERSION, $effort, $now, $now, $now]);
            $jobId = (int) $this->pdo->lastInsertId();
        } catch (PDOException $exception) {
            $existing->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
            $job = $existing->fetch();
            if (!$job) {
                throw $exception;
            }
            $jobId = (int) $job['id'];
        }

        return ['state' => 'queued', 'job_id' => $jobId, 'source_hash' => $sourceHash, 'effort' => $effort];
    }

    public function queueAllPublished(bool $force = false): int
    {
        $ids = $this->pdo->query("SELECT id FROM pages WHERE status = 'published' AND archived_at IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        $queued = 0;
        foreach ($ids as $id) {
            $result = $this->queuePage((int) $id, $force);
            if ($result['state'] === 'queued') {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Queue only published pages whose persisted update timestamp is newer
     * than the latest source snapshot examined by the RAG pipeline.
     *
     * The normal save path still queues immediately. This timestamp scan is a
     * recovery net for the login-triggered worker and avoids re-reading
     * unchanged pages.
     */
    public function queueUpdatedPublished(): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT p.id, p.updated_at
FROM pages p
LEFT JOIN rag_source_documents d ON d.page_id = p.id AND d.is_current = 1
WHERE p.status = 'published'
  AND p.archived_at IS NULL
  AND (
      d.id IS NULL
      OR d.source_schema_version <> ?
      OR p.updated_at > d.source_updated_at
      OR NOT EXISTS (
          SELECT 1
          FROM rag_page_profiles rp
          WHERE rp.page_id = p.id AND rp.source_hash = d.source_hash AND rp.prompt_version = ?
      )
      OR NOT EXISTS (
          SELECT 1
          FROM rag_chunks c
          WHERE c.page_id = p.id AND c.source_hash = d.source_hash
            AND c.prompt_version = ? AND c.is_active = 1
      )
  )
ORDER BY p.updated_at, p.id
SQL);
        $statement->execute([self::SOURCE_SCHEMA_VERSION, self::PROMPT_VERSION, self::PROMPT_VERSION]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        $queued = 0;
        foreach ($ids as $id) {
            $result = $this->queuePage((int) $id);
            if ($result['state'] === 'queued') {
                $queued++;
            }
        }
        return $queued;
    }

    /**
     * Deactivate current RAG generations whose live page is no longer eligible
     * for publication. The candidate read is intentionally only a hint:
     * deactivatePage() locks and re-checks every live page before mutating its
     * source and derived rows, so a concurrent republish remains current.
     */
    public function deactivateInactiveCurrentSources(): int
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT DISTINCT d.page_id
FROM rag_source_documents d
LEFT JOIN pages p ON p.id = d.page_id
WHERE d.is_current = 1
  AND (
      p.id IS NULL
      OR p.status <> 'published'
      OR p.archived_at IS NOT NULL
  )
ORDER BY d.page_id
SQL);
        $deactivated = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $pageId) {
            if ($this->deactivatePage((int) $pageId)) {
                $deactivated++;
            }
        }
        return $deactivated;
    }

    public function deactivatePage(int $pageId): bool
    {
        if ($pageId < 1) {
            return true;
        }
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A RAG deactivation transaction is already active.');
        }
        $this->beginWriteTransaction($driver);
        try {
            $livePage = $this->loadPage($pageId, true);
            if ($livePage
                && (string) $livePage['status'] === 'published'
                && $livePage['archived_at'] === null) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }
            $currentSource = $this->pdo->prepare(
                'SELECT source_hash FROM rag_source_documents WHERE page_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1'
            );
            $currentSource->execute([$pageId]);
            $deletedRevisionId = $currentSource->fetchColumn();
            $this->pdo->prepare('UPDATE rag_source_documents SET is_current = 0 WHERE page_id = ? AND is_current = 1')->execute([$pageId]);
            $this->pdo->prepare('UPDATE rag_chunks SET is_active = 0, updated_at = ? WHERE page_id = ? AND is_active = 1')->execute([$this->now(), $pageId]);
            $this->pdo->prepare('UPDATE rag_generated_tags SET is_active = 0 WHERE page_id = ? AND is_active = 1')->execute([$pageId]);
            $this->pdo->prepare("UPDATE rag_page_tag_links SET is_active = 0, updated_at = ? WHERE page_id = ? AND source = 'ai' AND is_active = 1")
                ->execute([$this->now(), $pageId]);
            $this->pdo->prepare('UPDATE rag_block_metadata SET is_active = 0, updated_at = ? WHERE page_id = ? AND is_active = 1')
                ->execute([$this->now(), $pageId]);
            $this->commitWriteTransaction($driver);
            if (is_string($deletedRevisionId) && $deletedRevisionId !== '' && class_exists(Hooks::class, false)) {
                Hooks::action('rag_document_snapshot_deleted', $pageId, $deletedRevisionId);
            }
            return true;
        } catch (Throwable $exception) {
            $this->rollBackWriteTransactionAfterFailure($driver);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function pageStatus(int $pageId): array
    {
        $job = $this->pdo->prepare(<<<'SQL'
SELECT j.id, j.source_hash, j.prompt_version, j.status, j.reasoning_effort,
       j.attempts, j.max_attempts, j.available_at, j.last_error,
       j.created_at, j.updated_at, j.completed_at
FROM rag_jobs j
JOIN rag_source_documents d
  ON d.page_id = j.page_id
 AND d.source_hash = j.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE j.page_id = ? AND j.prompt_version = ?
ORDER BY j.id DESC
LIMIT 1
SQL);
        $job->execute([self::SOURCE_SCHEMA_VERSION, $pageId, self::PROMPT_VERSION]);
        $profile = $this->pdo->prepare(<<<'SQL'
SELECT rp.language, rp.summary, rp.short_summary, rp.model, rp.reasoning_effort,
       rp.prompt_version, rp.input_tokens, rp.output_tokens, rp.latency_ms, rp.analyzed_at
FROM rag_page_profiles rp
JOIN rag_source_documents d
  ON d.page_id = rp.page_id
 AND d.source_hash = rp.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE rp.page_id = ?
  AND rp.prompt_version = ?
SQL);
        $profile->execute([self::SOURCE_SCHEMA_VERSION, $pageId, self::PROMPT_VERSION]);
        $chunks = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM rag_chunks c
JOIN rag_source_documents d
  ON d.page_id = c.page_id
 AND d.source_hash = c.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE c.page_id = ? AND c.prompt_version = ? AND c.is_active = 1
SQL);
        $chunks->execute([self::SOURCE_SCHEMA_VERSION, $pageId, self::PROMPT_VERSION]);
        return [
            'job' => $job->fetch() ?: null,
            'profile' => $profile->fetch() ?: null,
            'active_chunks' => (int) $chunks->fetchColumn(),
        ];
    }

    /** @return array{processed: int, completed: int, retried: int, failed: int, skipped: int} */
    public function processQueued(int $limit = 10, ?string $workerId = null): array
    {
        return $this->processMatchingQueued($limit, $workerId, null);
    }

    /** @return array{processed: int, completed: int, retried: int, failed: int, skipped: int} */
    public function processQueuedForPage(int $pageId, int $limit = 10, ?string $workerId = null): array
    {
        return $this->processMatchingQueued($limit, $workerId, max(1, $pageId));
    }

    /** @return array{processed: int, completed: int, retried: int, failed: int, skipped: int} */
    private function processMatchingQueued(int $limit, ?string $workerId, ?int $pageId): array
    {
        if ($this->client === null) {
            throw new RuntimeException('RAGワーカーには OpenAIClient が必要です。');
        }
        $limit = max(1, min(100, $limit));
        $workerId = $workerId ?: (gethostname() ?: 'worker') . '-' . getmypid();
        $result = ['processed' => 0, 'completed' => 0, 'retried' => 0, 'failed' => 0, 'skipped' => 0];

        for ($index = 0; $index < $limit; $index++) {
            $job = $this->claimNextJob($workerId, $pageId);
            if (!$job) {
                break;
            }
            $result['processed']++;
            try {
                $state = $this->processJob($job);
                $result[$state]++;
            } catch (Throwable $exception) {
                $state = $this->failJob($job, $exception);
                $result[$state]++;
            }
        }
        return $result;
    }

    /** @param array<int, array<string, mixed>> $units */
    public static function routeEffort(array $units): string
    {
        $characters = 0;
        $complex = 0;
        foreach ($units as $unit) {
            $characters += self::textLength((string) ($unit['text'] ?? ''));
            if (in_array((string) ($unit['type'] ?? ''), ['table', 'code'], true)) {
                $complex++;
            }
        }
        if ($characters <= 3000 && count($units) <= 18 && $complex === 0) {
            return 'low';
        }
        if ($characters > 15000 || count($units) > 80 || $complex > 5) {
            return 'high';
        }
        return 'medium';
    }

    /** @param array<string, mixed> $page @return array<int, array{id: string, block_id: string, type: string, text: string, heading_path: array<int, string>}> */
    public function extractUnits(array $page): array
    {
        $units = [];
        $title = trim((string) ($page['title'] ?? ''));
        if ($title !== '') {
            $units[] = [
                'id' => 'unit-page-title',
                'block_id' => self::PAGE_TITLE_BLOCK_ID,
                'type' => 'title',
                'text' => $title,
                'heading_path' => [],
            ];
        }

        $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
        if (!is_array($blocks)) {
            $blocks = [];
        }
        $headingPath = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $text = $this->blockText($block);
            if ($text === '') {
                continue;
            }
            if (preg_match('/^heading([123])$/', $type, $match)) {
                $level = (int) $match[1];
                $headingPath = array_slice($headingPath, 0, $level - 1);
                $headingPath[$level - 1] = $text;
                $headingPath = array_values($headingPath);
            }
            $rawId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($block['id'] ?? '')) ?: 'block';
            $parts = $this->splitText($text, 1400);
            foreach ($parts as $partIndex => $part) {
                $units[] = [
                    'id' => 'unit-' . $index . '-' . $rawId . '-' . ($partIndex + 1),
                    'block_id' => $rawId,
                    'type' => $type,
                    'text' => $part,
                    'heading_path' => $headingPath,
                ];
            }
        }
        return $units;
    }

    /** @return array<string, mixed>|null */
    private function loadPage(int $pageId, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
SELECT id, title, category, tags_json, manual_tags_json, blocks_json, plain_text, status, visibility, access_department,
       author_id, updated_by, archived_at, created_at, updated_at, published_at, language_code, translation_group_id,
       source_page_id, source_revision, translation_status, content_revision,
       (SELECT source.content_revision FROM pages source WHERE source.id = pages.source_page_id) AS current_source_revision
FROM pages WHERE id = ?
SQL;
        if ($forUpdate && (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$pageId]);
        return $statement->fetch() ?: null;
    }

    /** @param array<string, mixed> $page @return array<int, string> */
    private function accessDepartments(array $page): array
    {
        $statement = $this->pdo->prepare('SELECT department FROM page_access_departments WHERE page_id = ? ORDER BY department');
        $statement->execute([(int) $page['id']]);
        $values = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($values === []) {
            $legacy = $this->cleanText((string) ($page['access_department'] ?? ''), 120);
            if ($legacy === '' && (int) ($page['author_id'] ?? 0) > 0) {
                $author = $this->pdo->prepare('SELECT department FROM users WHERE id = ?');
                $author->execute([(int) $page['author_id']]);
                $legacy = $this->cleanText((string) ($author->fetchColumn() ?: ''), 120);
            }
            $values = $legacy === '' ? [] : [$legacy];
        }

        $departments = [];
        foreach ($values as $value) {
            $department = $this->cleanText((string) $value, 120);
            if ($department !== '' && !in_array($department, $departments, true)) {
                $departments[] = $department;
            }
        }
        sort($departments, SORT_STRING);
        return $departments;
    }

    /** @param array<string, mixed> $page @return array<int, string> */
    private function manualTags(array $page): array
    {
        $manualSource = json_decode((string) ($page['manual_tags_json'] ?? ''), true);
        if (!is_array($manualSource)) {
            $manualSource = json_decode((string) ($page['tags_json'] ?? '[]'), true);
        }
        $tags = [];
        $seen = [];
        foreach (array_slice(is_array($manualSource) ? $manualSource : [], 0, 12) as $tag) {
            $name = $this->cleanText((string) $tag, 40);
            $normalized = $this->normalizeTag($name);
            if ($name !== '' && $normalized !== '' && !isset($seen[$normalized])) {
                $tags[] = $name;
                $seen[$normalized] = true;
            }
        }

        return $tags;
    }

    /**
     * Refresh canonical identities for user-owned tags without changing the
     * page's manual_tags_json or visible tags_json values.
     */
    public function syncManualTags(int $pageId): void
    {
        if ($pageId < 1) {
            return;
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A manual tag synchronization transaction is already active.');
        }
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->beginWriteTransaction($driver);
        try {
            $page = $this->loadPage($pageId, true);
            if ($page) {
                $this->replaceManualTagLinks($page);
            }
            $this->commitWriteTransaction($driver);
        } catch (Throwable $exception) {
            $this->rollBackWriteTransactionAfterFailure($driver);
            throw $exception;
        }
    }

    /** @return array{manual_tags: array<int, array<string, mixed>>, topics: array<int, array<string, mixed>>, entities: array<int, array<string, mixed>>, projects: array<int, array<string, mixed>>, locations: array<int, array<string, mixed>>, status: ?array<string, mixed>} */
    public function pageMetadata(int $pageId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT l.metadata_field, l.source, l.confidence, t.id AS tag_id, t.canonical_name, t.tag_type
FROM rag_page_tag_links l
JOIN rag_canonical_tags t ON t.id = l.tag_id
WHERE l.page_id = ?
  AND l.is_active = 1
  AND (
      l.source = 'manual'
      OR (
          l.source = 'ai'
          AND l.prompt_version = ?
          AND EXISTS (
              SELECT 1
              FROM rag_source_documents d
              WHERE d.page_id = l.page_id
                AND d.source_hash = l.source_hash
                AND d.is_current = 1
                AND d.source_schema_version = ?
          )
      )
  )
ORDER BY CASE l.source WHEN 'manual' THEN 0 ELSE 1 END, l.metadata_field, COALESCE(l.confidence, 1) DESC, t.id
SQL);
        $statement->execute([$pageId, self::PROMPT_VERSION, self::SOURCE_SCHEMA_VERSION]);
        $metadata = [
            'manual_tags' => [],
            'topics' => [],
            'entities' => [],
            'projects' => [],
            'locations' => [],
            'status' => null,
        ];
        $fieldMap = [
            'manual_tag' => 'manual_tags',
            'topic' => 'topics',
            'entity' => 'entities',
            'project' => 'projects',
            'location' => 'locations',
            'status' => 'status',
        ];
        foreach ($statement->fetchAll() as $row) {
            $target = $fieldMap[(string) $row['metadata_field']] ?? null;
            if ($target === null) {
                continue;
            }
            $item = [
                'tag_id' => (int) $row['tag_id'],
                'canonical_name' => (string) $row['canonical_name'],
                'type' => (string) $row['tag_type'],
                'source' => (string) $row['source'],
                'confidence' => $row['confidence'] === null ? null : (float) $row['confidence'],
            ];
            if ($target === 'status') {
                $metadata['status'] ??= $item;
            } else {
                $metadata[$target][] = $item;
            }
        }
        return $metadata;
    }

    /** @param array<string, mixed> $page */
    private function replaceManualTagLinks(array $page): void
    {
        $pageId = (int) $page['id'];
        $this->pdo->prepare("DELETE FROM rag_page_tag_links WHERE page_id = ? AND source = 'manual'")
            ->execute([$pageId]);
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_page_tag_links
    (page_id, source_hash, prompt_version, tag_id, metadata_field, source, confidence, is_active, created_at, updated_at)
VALUES (?, '', '', ?, 'manual_tag', 'manual', NULL, 1, ?, ?)
SQL);
        $now = $this->now();
        foreach ($this->manualTags($page) as $manualTag) {
            $canonical = $this->resolveCanonicalTag($manualTag, 'general', 'manual');
            if ($canonical === null) {
                continue;
            }
            $insert->execute([$pageId, $canonical['tag_id'], $now, $now]);
        }
    }

    /** @param array<string, mixed> $page @param array<int, array<string, mixed>> $units */
    private function sourceHash(array $page, array $units): string
    {
        return hash('sha256', json_encode([
            'source_schema_version' => self::SOURCE_SCHEMA_VERSION,
            'title' => (string) $page['title'],
            'category' => (string) $page['category'],
            'manual_tags' => $this->manualTags($page),
            'blocks_json' => json_decode((string) $page['blocks_json'], true) ?: [],
            'language_code' => (string) ($page['language_code'] ?? 'und'),
            'translation_group_id' => $this->translationGroupId($page),
            'source_page_id' => $page['source_page_id'] === null ? null : (int) $page['source_page_id'],
            'translation_role' => $page['source_page_id'] === null ? 'original' : 'translation',
            'translation_status' => $this->ragTranslationStatus($page),
            'source_revision' => $page['source_revision'] === null ? null : (int) $page['source_revision'],
            'content_revision' => (int) ($page['content_revision'] ?? 1),
            'units' => array_map(static fn(array $unit): array => [
                'id' => $unit['id'],
                'block_id' => $unit['block_id'],
                'type' => $unit['type'],
                'text' => $unit['text'],
                'heading_path' => $unit['heading_path'],
            ], $units),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $page */
    private function translationGroupId(array $page): string
    {
        $group = trim((string) ($page['translation_group_id'] ?? ''));
        if ($group !== '') {
            return $group;
        }
        $sourceId = (int) ($page['source_page_id'] ?? 0);
        return 'page-' . ($sourceId > 0 ? $sourceId : (int) ($page['id'] ?? 0));
    }

    /** @param array<string, mixed> $page */
    private function ragTranslationStatus(array $page): string
    {
        if ((int) ($page['source_page_id'] ?? 0) < 1) {
            return 'original';
        }
        if ((int) ($page['source_revision'] ?? 0) < (int) ($page['current_source_revision'] ?? 1)) {
            return 'outdated';
        }
        $status = (string) ($page['translation_status'] ?? 'machine_translated');
        return in_array($status, ['machine_translated', 'human_edited'], true) ? $status : 'machine_translated';
    }

    /** @param array<string, mixed> $page @param array<int, array<string, mixed>> $units */
    private function storeSourceSnapshot(array $page, array $units, string $sourceHash): bool
    {
        $pageId = (int) $page['id'];
        $now = $this->now();
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A RAG source snapshot transaction is already active.');
        }
        $this->beginWriteTransaction($driver);
        try {
            $livePage = $this->loadPage($pageId, true);
            if (!$livePage
                || (string) $livePage['status'] !== 'published'
                || $livePage['archived_at'] !== null) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }
            $liveUnits = $this->extractUnits($livePage);
            $liveSourceHash = $this->sourceHash($livePage, $liveUnits);
            if (!hash_equals($sourceHash, $liveSourceHash)
                || !hash_equals((string) ($page['updated_at'] ?? ''), (string) ($livePage['updated_at'] ?? ''))) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }

            $currentSql = <<<'SQL'
SELECT id, source_hash, source_updated_at
FROM rag_source_documents
WHERE page_id = ? AND is_current = 1
ORDER BY source_updated_at DESC, id DESC
SQL;
            if ($driver !== 'sqlite') {
                $currentSql .= ' FOR UPDATE';
            }
            $current = $this->pdo->prepare($currentSql);
            $current->execute([$pageId]);
            foreach ($current->fetchAll() as $currentSource) {
                if (strcmp((string) ($currentSource['source_updated_at'] ?? ''), (string) $livePage['updated_at']) > 0) {
                    $this->rollBackWriteTransaction($driver);
                    return false;
                }
            }

            // The row lock serializes this ACL read with every supported page
            // writer. The snapshot therefore uses one live page generation,
            // even when an older queue call reached this method late.
            $page = $livePage;
            $units = $liveUnits;
            $sourceHash = $liveSourceHash;
            $accessDepartments = $this->accessDepartments($page);
            $accessDepartment = $accessDepartments[0] ?? $this->cleanText((string) ($page['access_department'] ?? ''), 120);
            $this->pdo->prepare('UPDATE rag_source_documents SET is_current = 0 WHERE page_id = ? AND is_current = 1')->execute([$pageId]);
            $find = $this->pdo->prepare('SELECT id FROM rag_source_documents WHERE page_id = ? AND source_hash = ?');
            $find->execute([$pageId, $sourceHash]);
            $sourceDocumentId = (int) ($find->fetchColumn() ?: 0);
            if ($sourceDocumentId > 0) {
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE rag_source_documents
SET source_schema_version = ?, title = ?, category = ?, tags_json = ?, blocks_json = ?, plain_text = ?, visibility = ?, access_department = ?,
    author_id = ?, updated_by = ?, source_updated_at = ?, published_at = ?, language_code = ?, translation_group_id = ?,
    source_page_id = ?, translation_role = ?, translation_status = ?, source_revision = ?, content_revision = ?,
    is_current = 1, captured_at = ?
WHERE id = ?
SQL);
                $update->execute([
                    self::SOURCE_SCHEMA_VERSION, $page['title'], $page['category'], $page['tags_json'], $page['blocks_json'], $page['plain_text'],
                    $page['visibility'], $accessDepartment, $page['author_id'], $page['updated_by'],
                    $page['updated_at'], $page['published_at'], (string) ($page['language_code'] ?? 'und'),
                    $this->translationGroupId($page), $page['source_page_id'] === null ? null : (int) $page['source_page_id'],
                    $page['source_page_id'] === null ? 'original' : 'translation', $this->ragTranslationStatus($page),
                    $page['source_revision'] === null ? null : (int) $page['source_revision'], (int) ($page['content_revision'] ?? 1),
                    $now, $sourceDocumentId,
                ]);
            } else {
                $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_source_documents
    (page_id, source_hash, source_schema_version, title, category, tags_json, blocks_json, plain_text,
     visibility, access_department, author_id, updated_by, source_created_at, source_updated_at,
     published_at, language_code, translation_group_id, source_page_id, translation_role, translation_status,
     source_revision, content_revision, is_current, captured_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
SQL);
                $insert->execute([
                    $pageId, $sourceHash, self::SOURCE_SCHEMA_VERSION, $page['title'], $page['category'], $page['tags_json'], $page['blocks_json'],
                    $page['plain_text'], $page['visibility'], $accessDepartment, $page['author_id'], $page['updated_by'],
                    $page['created_at'], $page['updated_at'], $page['published_at'], (string) ($page['language_code'] ?? 'und'),
                    $this->translationGroupId($page), $page['source_page_id'] === null ? null : (int) $page['source_page_id'],
                    $page['source_page_id'] === null ? 'original' : 'translation', $this->ragTranslationStatus($page),
                    $page['source_revision'] === null ? null : (int) $page['source_revision'], (int) ($page['content_revision'] ?? 1), $now,
                ]);
                $sourceDocumentId = (int) $this->pdo->lastInsertId();
                $insertUnit = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_source_units
    (source_document_id, unit_index, unit_key, block_type, heading_path_json, content, content_sha256, char_count)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL);
                foreach ($units as $index => $unit) {
                    $content = (string) $unit['text'];
                    $insertUnit->execute([
                        $sourceDocumentId, $index, $unit['id'], $unit['type'], json_encode($unit['heading_path'], $jsonFlags),
                        $content, hash('sha256', $content), self::textLength($content),
                    ]);
                }
            }

            // Unit content remains a versioned derivative, while block_id is
            // the stable pointer back to the OpenConcept source block. The
            // reserved page-title identifier keeps title-only chunks linked to
            // their page without pretending that the title is a stored block.
            $this->pdo->prepare(<<<'SQL'
DELETE FROM rag_source_unit_blocks
WHERE source_unit_id IN (
    SELECT id FROM rag_source_units WHERE source_document_id = ?
)
SQL)->execute([$sourceDocumentId]);
            $sourceUnits = $this->pdo->prepare(
                'SELECT id, unit_index FROM rag_source_units WHERE source_document_id = ? ORDER BY unit_index'
            );
            $sourceUnits->execute([$sourceDocumentId]);
            $insertUnitBlock = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_source_unit_blocks (source_unit_id, page_id, block_id, created_at)
VALUES (?, ?, ?, ?)
SQL);
            foreach ($sourceUnits->fetchAll() as $sourceUnit) {
                $unitIndex = (int) $sourceUnit['unit_index'];
                $blockId = $this->cleanBlockId((string) ($units[$unitIndex]['block_id'] ?? self::PAGE_TITLE_BLOCK_ID));
                $insertUnitBlock->execute([(int) $sourceUnit['id'], $pageId, $blockId, $now]);
            }

            $this->pdo->prepare('DELETE FROM rag_source_access_members WHERE source_document_id = ?')->execute([$sourceDocumentId]);
            $members = $this->pdo->prepare('SELECT user_id FROM page_access_members WHERE page_id = ? ORDER BY user_id');
            $members->execute([$pageId]);
            $insertMember = $this->pdo->prepare('INSERT INTO rag_source_access_members (source_document_id, user_id) VALUES (?, ?)');
            foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                $insertMember->execute([$sourceDocumentId, (int) $userId]);
            }
            $this->pdo->prepare('DELETE FROM rag_source_access_departments WHERE source_document_id = ?')->execute([$sourceDocumentId]);
            $insertDepartment = $this->pdo->prepare('INSERT INTO rag_source_access_departments (source_document_id, department) VALUES (?, ?)');
            foreach ($accessDepartments as $department) {
                $insertDepartment->execute([$sourceDocumentId, $department]);
            }
            $this->pdo->prepare('UPDATE rag_block_metadata SET is_active = 0, updated_at = ? WHERE page_id = ? AND source_hash <> ? AND is_active = 1')
                ->execute([$now, $pageId, $sourceHash]);
            $this->pdo->prepare("UPDATE rag_page_tag_links SET is_active = 0, updated_at = ? WHERE page_id = ? AND source = 'ai' AND source_hash <> ? AND is_active = 1")
                ->execute([$now, $pageId, $sourceHash]);
            $this->commitWriteTransaction($driver);
            return true;
        } catch (Throwable $exception) {
            $this->rollBackWriteTransactionAfterFailure($driver);
            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    private function claimNextJob(string $workerId, ?int $pageId = null): ?array
    {
        $now = $this->now();
        $stale = date('Y-m-d H:i:s', time() - 15 * 60);
        $recover = $this->pdo->prepare(<<<'SQL'
UPDATE rag_jobs SET status = 'queued', locked_at = NULL, locked_by = NULL, available_at = ?, updated_at = ?
WHERE status = 'processing' AND locked_at IS NOT NULL AND locked_at < ?
SQL);
        $recover->execute([$now, $now, $stale]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $select = $this->pdo->prepare(
                "SELECT * FROM rag_jobs WHERE status = 'queued' AND available_at <= ?"
                . ($pageId === null ? '' : ' AND page_id = ?')
                . ' ORDER BY available_at, id LIMIT 1'
            );
            $select->execute($pageId === null ? [$now] : [$now, $pageId]);
            $job = $select->fetch();
            if (!$job) {
                return null;
            }
            $claim = $this->pdo->prepare(<<<'SQL'
UPDATE rag_jobs SET status = 'processing', attempts = attempts + 1, locked_at = ?, locked_by = ?, updated_at = ?
WHERE id = ? AND status = 'queued'
SQL);
            $claim->execute([$now, $workerId, $now, (int) $job['id']]);
            if ($claim->rowCount() === 1) {
                $job['status'] = 'processing';
                $job['attempts'] = (int) $job['attempts'] + 1;
                $job['locked_at'] = $now;
                $job['locked_by'] = $workerId;
                return $job;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $job */
    private function processJob(array $job): string
    {
        $page = $this->loadPage((int) $job['page_id']);
        if (!$page || (string) $page['status'] !== 'published' || $page['archived_at'] !== null) {
            $deactivated = $this->deactivatePage((int) $job['page_id']);
            if (!$deactivated) {
                $this->finishJob((int) $job['id'], 'skipped', '処理中にページが公開されたため、最新版を再登録しました。');
                $this->queuePage((int) $job['page_id']);
                return 'skipped';
            }
            $this->finishJob((int) $job['id'], 'skipped', 'ページが非公開または削除済みです。');
            return 'skipped';
        }

        $units = $this->extractUnits($page);
        $currentHash = $this->sourceHash($page, $units);
        if (!hash_equals((string) $job['source_hash'], $currentHash)) {
            $this->finishJob((int) $job['id'], 'skipped', '新しい版が保存されたため処理対象から外しました。');
            $this->queuePage((int) $page['id']);
            return 'skipped';
        }

        $effort = in_array((string) $job['reasoning_effort'], ['low', 'medium', 'high'], true)
            ? (string) $job['reasoning_effort']
            : self::routeEffort($units);
        $canonicalTags = $this->canonicalTagCatalog($page, $units);
        $document = [
            'page_id' => (int) $page['id'],
            'title' => (string) $page['title'],
            'category' => (string) $page['category'],
            // Generated tags belong to a completed source generation and can
            // change while this job waits. Only the manual tags captured in
            // the job page snapshot are safe analysis input.
            'existing_tags' => $this->manualTags($page),
            'canonical_tags' => $canonicalTags,
            'units' => $units,
        ];
        $response = $this->client->analyze($document, $effort);
        $analysis = $this->sanitizeAnalysis($response['data']);
        $analysis['_allowed_canonical_tag_ids'] = array_map('intval', array_column($canonicalTags, 'tag_id'));
        $chunks = $this->buildChunks($units, $analysis['chunks'], $analysis['keywords']);
        if (!$this->persistAnalysis($page, $currentHash, $effort, $analysis, $chunks, $response)) {
            $this->finishJob((int) $job['id'], 'skipped', '処理中に新しい版が保存されたため結果を破棄しました。');
            $this->queuePage((int) $page['id']);
            return 'skipped';
        }
        $this->finishJob((int) $job['id'], 'completed');
        return 'completed';
    }

    /** @param array<string, mixed> $job */
    private function failJob(array $job, Throwable $exception): string
    {
        $message = $this->sliceText(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $exception->getMessage()) ?? 'RAG処理に失敗しました。', 1000);
        $attempts = (int) $job['attempts'];
        $maxAttempts = max(1, (int) $job['max_attempts']);
        $now = $this->now();
        if ($attempts < $maxAttempts) {
            $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
            $availableAt = date('Y-m-d H:i:s', time() + $delay);
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE rag_jobs SET status = 'queued', available_at = ?, locked_at = NULL, locked_by = NULL,
    last_error = ?, updated_at = ? WHERE id = ?
SQL);
            $update->execute([$availableAt, $message, $now, (int) $job['id']]);
            return 'retried';
        }
        $this->finishJob((int) $job['id'], 'failed', $message);
        return 'failed';
    }

    private function finishJob(int $jobId, string $status, ?string $error = null): void
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE rag_jobs SET status = ?, locked_at = NULL, locked_by = NULL, last_error = ?,
    completed_at = ?, updated_at = ? WHERE id = ?
SQL);
        $statement->execute([$status, $error, $now, $now, $jobId]);
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function sanitizeAnalysis(array $raw): array
    {
        $tags = [];
        $seenTags = [];
        foreach (array_slice((array) ($raw['tags'] ?? []), 0, 20) as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $name = $this->cleanText((string) ($tag['name'] ?? ''), 40);
            $normalized = $this->normalizeTag($name);
            if ($name === '' || $normalized === '' || isset($seenTags[$normalized])) {
                continue;
            }
            $seenTags[$normalized] = true;
            $tags[] = [
                'name' => $name,
                'relevance' => max(0.0, min(1.0, (float) ($tag['relevance'] ?? 0.5))),
                'canonical_tag_id' => max(0, (int) ($tag['canonical_tag_id'] ?? 0)),
            ];
        }

        $topics = $this->sanitizeMetadataList((array) ($raw['topics'] ?? []), 20, 'topic');
        $entities = $this->sanitizeMetadataList((array) ($raw['entities'] ?? []), 40, 'entity');
        $projects = $this->sanitizeMetadataList((array) ($raw['projects'] ?? []), 20, 'project');
        $locations = $this->sanitizeMetadataList((array) ($raw['locations'] ?? []), 20, 'location');

        $status = [];
        $rawStatus = (array) ($raw['status'] ?? []);
        if (isset($rawStatus[0]) && is_array($rawStatus[0])) {
            $value = (string) ($rawStatus[0]['value'] ?? '');
            $statusConfidence = max(0.0, min(1.0, (float) ($rawStatus[0]['confidence'] ?? 0.5)));
            if ($statusConfidence >= 0.7 && in_array($value, ['considering', 'decided', 'completed', 'deprecated'], true)) {
                $status[] = [
                    'name' => $value,
                    'type' => 'status',
                    'confidence' => $statusConfidence,
                    'canonical_tag_id' => 0,
                ];
            }
        }

        $chunks = [];
        foreach (array_slice((array) ($raw['chunks'] ?? []), 0, 100) as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }
            $chunks[] = [
                'title' => $this->cleanText((string) ($chunk['title'] ?? ''), 160),
                'unit_ids' => array_values(array_filter(array_map(static fn($id): string => (string) $id, (array) ($chunk['unit_ids'] ?? [])))),
                'summary' => $this->cleanText((string) ($chunk['summary'] ?? ''), 1000),
                'keywords' => $this->cleanStringList((array) ($chunk['keywords'] ?? []), 20, 80),
            ];
        }

        $blockMetadata = [];
        $seenBlocks = [];
        foreach (array_slice((array) ($raw['block_metadata'] ?? []), 0, 100) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockId = $this->cleanBlockId((string) ($block['block_id'] ?? ''));
            if ($blockId === '' || $blockId === self::PAGE_TITLE_BLOCK_ID || isset($seenBlocks[$blockId])) {
                continue;
            }
            $summary = $this->cleanText((string) ($block['summary'] ?? ''), 1000);
            $blockKeywords = $this->cleanStringList((array) ($block['keywords'] ?? []), 20, 80);
            $blockEntities = $this->sanitizeMetadataList((array) ($block['entities'] ?? []), 20, 'entity');
            if ($summary === '' && $blockKeywords === [] && $blockEntities === []) {
                continue;
            }
            $seenBlocks[$blockId] = true;
            $blockMetadata[] = [
                'block_id' => $blockId,
                'summary' => $summary,
                'keywords' => $blockKeywords,
                'entities' => $blockEntities,
            ];
        }

        return [
            'language' => $this->cleanText((string) ($raw['language'] ?? ''), 40) ?: 'und',
            'summary' => $this->cleanText((string) ($raw['summary'] ?? ''), 5000),
            'short_summary' => $this->cleanText((string) ($raw['short_summary'] ?? ''), 500),
            'tags' => $tags,
            'keywords' => $this->cleanStringList((array) ($raw['keywords'] ?? []), 50, 120),
            'topics' => $topics,
            'entities' => $entities,
            'projects' => $projects,
            'locations' => $locations,
            'status' => $status,
            'questions' => $this->cleanStringList((array) ($raw['questions'] ?? []), 30, 300),
            'chunks' => $chunks,
            'block_metadata' => $blockMetadata,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $suggested
     * @param array<int, string> $globalKeywords
     * @return array<int, array<string, mixed>>
     */
    private function buildChunks(array $units, array $suggested, array $globalKeywords): array
    {
        $expected = array_column($units, 'id');
        $flattened = [];
        foreach ($suggested as $chunk) {
            foreach ((array) ($chunk['unit_ids'] ?? []) as $id) {
                $flattened[] = $id;
            }
        }
        $valid = $expected !== [] && $flattened === $expected && count($suggested) > 0;
        if (!$valid) {
            $suggested = $this->fallbackChunkPlan($units, $globalKeywords);
        }

        $byId = [];
        foreach ($units as $unit) {
            $byId[(string) $unit['id']] = $unit;
        }
        $chunks = [];
        foreach ($suggested as $chunkIndex => $chunk) {
            $chunkUnits = [];
            foreach ((array) $chunk['unit_ids'] as $id) {
                if (isset($byId[$id])) {
                    $chunkUnits[] = $byId[$id];
                }
            }
            if (!$chunkUnits) {
                continue;
            }
            $content = implode("\n\n", array_column($chunkUnits, 'text'));
            $first = $chunkUnits[0];
            $blockIds = [];
            foreach ($chunkUnits as $chunkUnit) {
                $blockId = $this->cleanBlockId((string) ($chunkUnit['block_id'] ?? self::PAGE_TITLE_BLOCK_ID));
                if ($blockId !== '' && !in_array($blockId, $blockIds, true)) {
                    $blockIds[] = $blockId;
                }
            }
            $chunks[] = [
                'chunk_index' => $chunkIndex,
                'chunk_key' => hash('sha256', implode('|', array_column($chunkUnits, 'id'))),
                'title' => $this->cleanText((string) ($chunk['title'] ?? ''), 160) ?: $this->sliceText((string) $first['text'], 120),
                'heading_path' => (array) ($first['heading_path'] ?? []),
                'unit_ids' => array_column($chunkUnits, 'id'),
                'block_ids' => $blockIds,
                'content' => $content,
                'summary' => $this->cleanText((string) ($chunk['summary'] ?? ''), 1000) ?: $this->sliceText($content, 300),
                'keywords' => $this->cleanStringList((array) ($chunk['keywords'] ?? []), 20, 80),
                'char_count' => self::textLength($content),
                'token_estimate' => (int) ceil(self::textLength($content) / 2.5),
            ];
        }
        return $chunks;
    }

    /** @param array<int, array<string, mixed>> $units @param array<int, string> $keywords @return array<int, array<string, mixed>> */
    private function fallbackChunkPlan(array $units, array $keywords): array
    {
        $groups = [];
        $current = [];
        $length = 0;
        foreach ($units as $unit) {
            $unitLength = self::textLength((string) $unit['text']);
            $isHeading = str_starts_with((string) $unit['type'], 'heading');
            if ($current && (($length + $unitLength) > 2600 || ($isHeading && $length >= 900))) {
                $groups[] = $current;
                $current = [];
                $length = 0;
            }
            $current[] = $unit;
            $length += $unitLength;
        }
        if ($current) {
            $groups[] = $current;
        }

        return array_map(function (array $group) use ($keywords): array {
            $heading = array_values(array_filter(array_column($group, 'text'), static fn(string $text, int $index): bool => str_starts_with((string) $group[$index]['type'], 'heading'), ARRAY_FILTER_USE_BOTH));
            $content = implode("\n\n", array_column($group, 'text'));
            return [
                'title' => $heading[0] ?? $this->sliceText((string) $group[0]['text'], 120),
                'unit_ids' => array_column($group, 'id'),
                'summary' => $this->sliceText($content, 300),
                'keywords' => array_slice($keywords, 0, 10),
            ];
        }, $groups);
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $analysis
     * @param array<int, array<string, mixed>> $chunks
     * @param array<string, mixed> $response
     */
    private function persistAnalysis(array $page, string $sourceHash, string $effort, array $analysis, array $chunks, array $response): bool
    {
        $pageId = (int) $page['id'];
        $now = $this->now();
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($this->pdo->inTransaction()) {
            throw new LogicException('A RAG analysis persistence transaction is already active.');
        }
        $this->beginWriteTransaction($driver);
        try {
            $livePage = $this->loadPage($pageId, true);
            if (!$livePage
                || (string) $livePage['status'] !== 'published'
                || $livePage['archived_at'] !== null) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }
            $liveUnits = $this->extractUnits($livePage);
            $liveSourceHash = $this->sourceHash($livePage, $liveUnits);
            if (!hash_equals($sourceHash, $liveSourceHash)) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }

            $currentSourceSql = <<<'SQL'
SELECT id, source_hash, source_schema_version
FROM rag_source_documents
WHERE page_id = ? AND is_current = 1
ORDER BY id
SQL;
            if ($driver !== 'sqlite') {
                $currentSourceSql .= ' FOR UPDATE';
            }
            $currentSource = $this->pdo->prepare($currentSourceSql);
            $currentSource->execute([$pageId]);
            $currentSourceRows = $currentSource->fetchAll();
            if (count($currentSourceRows) !== 1
                || !hash_equals($sourceHash, (string) $currentSourceRows[0]['source_hash'])
                || (int) ($currentSourceRows[0]['source_schema_version'] ?? 0) !== self::SOURCE_SCHEMA_VERSION) {
                $this->rollBackWriteTransaction($driver);
                return false;
            }

            $page = $livePage;
            $analysis = $this->resolveAnalysisCanonicalTags($analysis);
            $this->pdo->prepare('DELETE FROM rag_page_profiles WHERE page_id = ?')->execute([$pageId]);
            $profile = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_page_profiles
    (page_id, source_hash, language, summary, short_summary, tags_json, keywords_json, entities_json, questions_json,
     model, reasoning_effort, prompt_version, response_id, input_chars, input_tokens, output_tokens, latency_ms, analyzed_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $inputChars = self::textLength((string) $page['title']) + self::textLength((string) $page['plain_text']);
            $profile->execute([
                $pageId, $sourceHash, $analysis['language'], $analysis['summary'], $analysis['short_summary'],
                json_encode($analysis['tags'], $jsonFlags), json_encode($analysis['keywords'], $jsonFlags),
                json_encode($analysis['entities'], $jsonFlags), json_encode($analysis['questions'], $jsonFlags),
                $response['model'], $effort, self::PROMPT_VERSION, $response['response_id'], $inputChars,
                (int) $response['input_tokens'], (int) $response['output_tokens'], (int) $response['latency_ms'], $now,
            ]);

            $this->pdo->prepare('UPDATE rag_chunks SET is_active = 0, updated_at = ? WHERE page_id = ? AND is_active = 1')->execute([$now, $pageId]);
            $this->pdo->prepare('DELETE FROM rag_chunks WHERE page_id = ? AND source_hash = ? AND prompt_version = ?')->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
            $insertChunk = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_chunks
    (page_id, source_hash, prompt_version, chunk_index, chunk_key, title, heading_path_json, unit_ids_json,
     content, chunk_summary, keywords_json, search_text, token_estimate, char_count, model, reasoning_effort, is_active, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
SQL);
            $insertChunkBlock = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_chunk_blocks (chunk_id, page_id, block_id, source_hash)
VALUES (?, ?, ?, ?)
SQL);
            foreach ($chunks as $chunk) {
                $searchText = implode("\n", [
                    (string) $page['title'],
                    (string) $chunk['title'],
                    (string) $chunk['summary'],
                    implode(' ', (array) $chunk['keywords']),
                    (string) $chunk['content'],
                ]);
                $insertChunk->execute([
                    $pageId, $sourceHash, self::PROMPT_VERSION, $chunk['chunk_index'], $chunk['chunk_key'], $chunk['title'],
                    json_encode($chunk['heading_path'], $jsonFlags), json_encode($chunk['unit_ids'], $jsonFlags), $chunk['content'],
                    $chunk['summary'], json_encode($chunk['keywords'], $jsonFlags), $searchText, $chunk['token_estimate'], $chunk['char_count'],
                    $response['model'], $effort, $now, $now,
                ]);
                $chunkId = (int) $this->pdo->lastInsertId();
                foreach ((array) ($chunk['block_ids'] ?? []) as $blockId) {
                    $insertChunkBlock->execute([$chunkId, $pageId, $blockId, $sourceHash]);
                }
            }

            $this->replaceBlockMetadata(
                $pageId,
                $sourceHash,
                $liveUnits,
                $analysis['block_metadata'],
                (string) $response['model'],
                $now
            );
            $this->replaceAiMetadataLinks($pageId, $sourceHash, $analysis, $now);
            $this->replaceGeneratedTags($page, $sourceHash, $analysis['tags'], (string) $response['model'], $now);
            $this->commitWriteTransaction($driver);
            return true;
        } catch (Throwable $exception) {
            $this->rollBackWriteTransactionAfterFailure($driver);
            throw $exception;
        }
    }

    private function beginWriteTransaction(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            return;
        }
        $this->pdo->beginTransaction();
    }

    private function commitWriteTransaction(string $driver): void
    {
        // BEGIN IMMEDIATE is issued as SQL so SQLite takes the write lock
        // before the live-generation checks. Some container pdo_sqlite builds
        // do not expose that SQL-started transaction to PDO::commit(), so its
        // completion must use the matching SQL boundary as well.
        if ($driver === 'sqlite') {
            $this->pdo->exec('COMMIT');
            return;
        }
        $this->pdo->commit();
    }

    private function rollBackWriteTransaction(string $driver): void
    {
        if ($driver === 'sqlite') {
            $this->pdo->exec('ROLLBACK');
            return;
        }
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function rollBackWriteTransactionAfterFailure(string $driver): void
    {
        try {
            $this->rollBackWriteTransaction($driver);
        } catch (Throwable) {
            // Preserve the write failure. A boundary failure after a completed
            // transaction must not replace the original exception either.
        }
    }

    /** @param array<string, mixed> $analysis @return array<string, mixed> */
    private function resolveAnalysisCanonicalTags(array $analysis): array
    {
        $allowedCandidateIds = array_fill_keys(
            array_map('intval', (array) ($analysis['_allowed_canonical_tag_ids'] ?? [])),
            true
        );
        unset($analysis['_allowed_canonical_tag_ids']);
        $fields = [
            'topics' => 'topic',
            'entities' => 'entity',
            'projects' => 'project',
            'locations' => 'location',
            'status' => 'status',
            // Human-navigation tags are intentionally last so a more
            // specific structured role wins when the same new concept occurs
            // in both outputs.
            'tags' => 'general',
        ];
        foreach ($fields as $field => $defaultType) {
            $resolved = [];
            $seen = [];
            foreach ((array) ($analysis[$field] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $candidateId = max(0, (int) ($item['canonical_tag_id'] ?? 0));
                if (!isset($allowedCandidateIds[$candidateId])) {
                    $candidateId = 0;
                }
                $canonical = $this->resolveCanonicalTag(
                    (string) ($item['name'] ?? ''),
                    (string) ($item['type'] ?? $defaultType),
                    'ai',
                    $candidateId
                );
                if ($canonical === null || isset($seen[$canonical['tag_id']])) {
                    continue;
                }
                $seen[$canonical['tag_id']] = true;
                $item['name'] = $canonical['canonical_name'];
                $item['canonical_name'] = $canonical['canonical_name'];
                $item['canonical_tag_id'] = $canonical['tag_id'];
                $item['type'] = $canonical['tag_type'];
                $resolved[] = $item;
            }
            $analysis[$field] = $resolved;
        }

        foreach ((array) ($analysis['block_metadata'] ?? []) as $index => $block) {
            if (!is_array($block)) {
                unset($analysis['block_metadata'][$index]);
                continue;
            }
            $resolvedEntities = [];
            $seen = [];
            foreach ((array) ($block['entities'] ?? []) as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $candidateId = max(0, (int) ($entity['canonical_tag_id'] ?? 0));
                if (!isset($allowedCandidateIds[$candidateId])) {
                    $candidateId = 0;
                }
                $canonical = $this->resolveCanonicalTag(
                    (string) ($entity['name'] ?? ''),
                    (string) ($entity['type'] ?? 'entity'),
                    'ai',
                    $candidateId
                );
                if ($canonical === null || isset($seen[$canonical['tag_id']])) {
                    continue;
                }
                $seen[$canonical['tag_id']] = true;
                $entity['name'] = $canonical['canonical_name'];
                $entity['canonical_name'] = $canonical['canonical_name'];
                $entity['canonical_tag_id'] = $canonical['tag_id'];
                $entity['type'] = $canonical['tag_type'];
                $resolvedEntities[] = $entity;
            }
            $analysis['block_metadata'][$index]['entities'] = $resolvedEntities;
        }
        $analysis['block_metadata'] = array_values((array) ($analysis['block_metadata'] ?? []));
        return $analysis;
    }

    /** @param array<string, mixed> $analysis */
    private function replaceAiMetadataLinks(int $pageId, string $sourceHash, array $analysis, string $now): void
    {
        $this->pdo->prepare("UPDATE rag_page_tag_links SET is_active = 0, updated_at = ? WHERE page_id = ? AND source = 'ai' AND is_active = 1")
            ->execute([$now, $pageId]);
        $this->pdo->prepare(
            "DELETE FROM rag_page_tag_links WHERE page_id = ? AND source_hash = ? AND prompt_version = ? AND source = 'ai'"
        )->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
        $manual = $this->pdo->prepare(
            "SELECT tag_id FROM rag_page_tag_links WHERE page_id = ? AND source = 'manual' AND is_active = 1"
        );
        $manual->execute([$pageId]);
        $manualIds = array_fill_keys(array_map('intval', $manual->fetchAll(PDO::FETCH_COLUMN)), true);
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_page_tag_links
    (page_id, source_hash, prompt_version, tag_id, metadata_field, source, confidence, is_active, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, 'ai', ?, 1, ?, ?)
SQL);
        $fieldMap = [
            'tags' => 'generated_tag',
            'topics' => 'topic',
            'entities' => 'entity',
            'projects' => 'project',
            'locations' => 'location',
            'status' => 'status',
        ];
        $seen = [];
        foreach ($fieldMap as $analysisField => $metadataField) {
            foreach ((array) ($analysis[$analysisField] ?? []) as $item) {
                $tagId = max(0, (int) ($item['canonical_tag_id'] ?? 0));
                if ($tagId < 1 || ($metadataField === 'generated_tag' && isset($manualIds[$tagId]))) {
                    continue;
                }
                $key = $metadataField . ':' . $tagId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $confidence = max(0.0, min(1.0, (float) ($item['confidence'] ?? $item['relevance'] ?? 0.5)));
                $insert->execute([
                    $pageId, $sourceHash, self::PROMPT_VERSION, $tagId, $metadataField, $confidence, $now, $now,
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $units
     * @param array<int, array<string, mixed>> $blockMetadata
     */
    private function replaceBlockMetadata(
        int $pageId,
        string $sourceHash,
        array $units,
        array $blockMetadata,
        string $model,
        string $now
    ): void {
        $this->pdo->prepare('UPDATE rag_block_metadata SET is_active = 0, updated_at = ? WHERE page_id = ? AND is_active = 1')
            ->execute([$now, $pageId]);
        $this->pdo->prepare('DELETE FROM rag_block_metadata WHERE page_id = ? AND source_hash = ? AND prompt_version = ?')
            ->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);

        $blockTypes = [];
        foreach ($units as $unit) {
            $blockId = $this->cleanBlockId((string) ($unit['block_id'] ?? ''));
            if ($blockId !== '' && $blockId !== self::PAGE_TITLE_BLOCK_ID) {
                $blockTypes[$blockId] = $this->cleanText((string) ($unit['type'] ?? 'paragraph'), 40) ?: 'paragraph';
            }
        }
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_block_metadata
    (page_id, source_hash, prompt_version, block_id, block_type, summary, keywords_json, entities_json,
     model, is_active, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
SQL);
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        foreach ($blockMetadata as $metadata) {
            $blockId = $this->cleanBlockId((string) ($metadata['block_id'] ?? ''));
            if (!isset($blockTypes[$blockId])) {
                continue;
            }
            $insert->execute([
                $pageId,
                $sourceHash,
                self::PROMPT_VERSION,
                $blockId,
                $blockTypes[$blockId],
                (string) ($metadata['summary'] ?? ''),
                json_encode((array) ($metadata['keywords'] ?? []), $jsonFlags),
                json_encode((array) ($metadata['entities'] ?? []), $jsonFlags),
                $model,
                $now,
                $now,
            ]);
        }
    }

    /**
     * Search the existing canonical catalog before analysis. Frequently used
     * identities and names already present in the page are favored so prompt
     * size remains bounded as the catalog grows.
     *
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $units
     * @return array<int, array<string, mixed>>
     */
    private function canonicalTagCatalog(array $page, array $units): array
    {
        $rows = $this->pdo->query(<<<'SQL'
SELECT t.id, t.canonical_name, t.normalized_name, t.tag_type, COUNT(l.id) AS usage_count
FROM rag_canonical_tags t
LEFT JOIN rag_page_tag_links l ON l.tag_id = t.id AND l.is_active = 1
GROUP BY t.id, t.canonical_name, t.normalized_name, t.tag_type
ORDER BY usage_count DESC, t.updated_at DESC, t.id
LIMIT 500
SQL)->fetchAll();
        if ($rows === []) {
            return [];
        }
        $ids = array_map('intval', array_column($rows, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $aliases = $this->pdo->prepare(
            "SELECT tag_id, alias, normalized_alias FROM rag_tag_aliases WHERE tag_id IN ({$placeholders}) ORDER BY id"
        );
        $aliases->execute($ids);
        $aliasMap = [];
        foreach ($aliases->fetchAll() as $alias) {
            $tagId = (int) $alias['tag_id'];
            if (count($aliasMap[$tagId] ?? []) < 8) {
                $aliasMap[$tagId][] = [
                    'alias' => (string) $alias['alias'],
                    'normalized' => (string) $alias['normalized_alias'],
                ];
            }
        }

        $pageText = implode(' ', [
            (string) ($page['title'] ?? ''),
            implode(' ', $this->manualTags($page)),
            implode(' ', array_map(static fn(array $unit): string => (string) ($unit['text'] ?? ''), $units)),
        ]);
        $normalizedPage = $this->normalizeTag($pageText);
        $relevant = [];
        $fallback = [];
        foreach ($rows as $row) {
            $tagId = (int) $row['id'];
            $names = [(string) $row['normalized_name']];
            foreach ($aliasMap[$tagId] ?? [] as $alias) {
                $names[] = $alias['normalized'];
            }
            $matches = false;
            foreach ($names as $name) {
                if (self::textLength($name) >= 2 && str_contains($normalizedPage, $name)) {
                    $matches = true;
                    break;
                }
            }
            if ($matches) {
                $relevant[] = $row;
            } else {
                $fallback[] = $row;
            }
        }
        $selected = array_slice([...$relevant, ...$fallback], 0, 200);
        return array_map(static function (array $row) use ($aliasMap): array {
            $tagId = (int) $row['id'];
            return [
                'tag_id' => $tagId,
                'canonical_name' => (string) $row['canonical_name'],
                'type' => (string) $row['tag_type'],
                'aliases' => array_column($aliasMap[$tagId] ?? [], 'alias'),
            ];
        }, $selected);
    }

    /** @return array{tag_id: int, canonical_name: string, tag_type: string}|null */
    private function resolveCanonicalTag(string $name, string $type, string $source, int $candidateId = 0): ?array
    {
        $name = preg_replace('/^#+/u', '', $this->cleanText($name, 120)) ?? '';
        $normalized = $this->normalizeTag($name);
        if ($name === '' || $normalized === '') {
            return null;
        }
        $source = $source === 'manual' ? 'manual' : 'ai';
        if ($candidateId > 0) {
            $candidate = $this->pdo->prepare(
                'SELECT id, canonical_name, normalized_name, tag_type FROM rag_canonical_tags WHERE id = ?'
            );
            $candidate->execute([$candidateId]);
            $row = $candidate->fetch();
            if ($row) {
                $this->registerCanonicalAlias((int) $row['id'], (string) $row['normalized_name'], $name, $source);
                return [
                    'tag_id' => (int) $row['id'],
                    'canonical_name' => (string) $row['canonical_name'],
                    'tag_type' => (string) $row['tag_type'],
                ];
            }
        }

        $canonical = $this->pdo->prepare(
            'SELECT id, canonical_name, tag_type FROM rag_canonical_tags WHERE normalized_name = ?'
        );
        $canonical->execute([$normalized]);
        $row = $canonical->fetch();
        if (!$row) {
            $alias = $this->pdo->prepare(<<<'SQL'
SELECT t.id, t.canonical_name, t.tag_type
FROM rag_tag_aliases a
JOIN rag_canonical_tags t ON t.id = a.tag_id
WHERE a.normalized_alias = ?
SQL);
            $alias->execute([$normalized]);
            $row = $alias->fetch();
        }
        if ($row) {
            return [
                'tag_id' => (int) $row['id'],
                'canonical_name' => (string) $row['canonical_name'],
                'tag_type' => (string) $row['tag_type'],
            ];
        }

        try {
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_canonical_tags
    (canonical_name, normalized_name, tag_type, created_source, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?)
SQL);
            $now = $this->now();
            $insert->execute([$name, $normalized, $this->cleanTagType($type), $source, $now, $now]);
            return [
                'tag_id' => (int) $this->pdo->lastInsertId(),
                'canonical_name' => $name,
                'tag_type' => $this->cleanTagType($type),
            ];
        } catch (PDOException $exception) {
            $canonical->execute([$normalized]);
            $row = $canonical->fetch();
            if (!$row) {
                throw $exception;
            }
            return [
                'tag_id' => (int) $row['id'],
                'canonical_name' => (string) $row['canonical_name'],
                'tag_type' => (string) $row['tag_type'],
            ];
        }
    }

    private function registerCanonicalAlias(int $tagId, string $canonicalNormalized, string $alias, string $source): void
    {
        $alias = preg_replace('/^#+/u', '', $this->cleanText($alias, 120)) ?? '';
        $normalized = $this->normalizeTag($alias);
        if ($tagId < 1 || $alias === '' || $normalized === '' || hash_equals($canonicalNormalized, $normalized)) {
            return;
        }
        $canonicalCollision = $this->pdo->prepare('SELECT id FROM rag_canonical_tags WHERE normalized_name = ?');
        $canonicalCollision->execute([$normalized]);
        if ($canonicalCollision->fetchColumn() !== false) {
            return;
        }
        $aliasCollision = $this->pdo->prepare('SELECT tag_id FROM rag_tag_aliases WHERE normalized_alias = ?');
        $aliasCollision->execute([$normalized]);
        if ($aliasCollision->fetchColumn() !== false) {
            return;
        }
        try {
            $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_tag_aliases (tag_id, alias, normalized_alias, created_source, created_at)
VALUES (?, ?, ?, ?, ?)
SQL)->execute([$tagId, $alias, $normalized, $source === 'manual' ? 'manual' : 'ai', $this->now()]);
        } catch (PDOException) {
            // A concurrent resolver may have registered the same spelling.
        }
    }

    /** @param array<string, mixed> $page @param array<int, array{name: string, relevance: float}> $tags */
    private function replaceGeneratedTags(array $page, string $sourceHash, array $tags, string $model, string $now): void
    {
        $pageId = (int) $page['id'];
        $manualStatement = $this->pdo->prepare(
            'SELECT manual_tags_json, tags_json FROM pages WHERE id = ?'
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '')
        );
        $manualStatement->execute([$pageId]);
        $latestPageTags = $manualStatement->fetch() ?: $page;
        $manualSource = json_decode((string) ($latestPageTags['manual_tags_json'] ?? ''), true);
        if (!is_array($manualSource)) {
            // Compatibility fallback for a page created before the manual-tag
            // column was migrated. Preserve every visible tag in that case.
            $manualSource = json_decode((string) ($latestPageTags['tags_json'] ?? $page['tags_json']), true);
        }
        $manual = [];
        $seen = [];
        foreach (array_slice(is_array($manualSource) ? $manualSource : [], 0, 12) as $tag) {
            $name = $this->cleanText((string) $tag, 40);
            $normalized = $this->normalizeTag($name);
            if ($name !== '' && $normalized !== '' && !isset($seen[$normalized])) {
                $manual[] = $name;
                $seen[$normalized] = true;
            }
        }
        $manualCanonical = $this->pdo->prepare(
            "SELECT tag_id FROM rag_page_tag_links WHERE page_id = ? AND source = 'manual' AND is_active = 1"
        );
        $manualCanonical->execute([$pageId]);
        $manualCanonicalIds = array_fill_keys(array_map('intval', $manualCanonical->fetchAll(PDO::FETCH_COLUMN)), true);

        $this->pdo->prepare('UPDATE rag_generated_tags SET is_active = 0 WHERE page_id = ? AND is_active = 1')->execute([$pageId]);
        $this->pdo->prepare('DELETE FROM rag_generated_tags WHERE page_id = ? AND source_hash = ? AND prompt_version = ?')->execute([$pageId, $sourceHash, self::PROMPT_VERSION]);
        $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO rag_generated_tags
    (page_id, source_hash, prompt_version, tag, normalized_tag, relevance, model, is_active, created_at)
VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)
SQL);
        $displayTags = $manual;
        foreach (array_slice($tags, 0, 8) as $tag) {
            $canonicalTagId = max(0, (int) ($tag['canonical_tag_id'] ?? 0));
            $canonicalName = $this->cleanText((string) ($tag['canonical_name'] ?? $tag['name'] ?? ''), 120);
            $normalized = $this->normalizeTag($canonicalName);
            if ($normalized === '' || isset($seen[$normalized]) || isset($manualCanonicalIds[$canonicalTagId])) {
                continue;
            }
            $insert->execute([$pageId, $sourceHash, self::PROMPT_VERSION, $canonicalName, $normalized, $tag['relevance'], $model, $now]);
            $seen[$normalized] = true;
            if (count($displayTags) < 12) {
                $displayTags[] = $canonicalName;
            }
        }
        // Generated tags are derived data. Keep the page's user-facing
        // updated_at unchanged so the login-time timestamp scan does not queue
        // the page again just because the worker wrote its own output.
        $updatePage = $this->pdo->prepare('UPDATE pages SET tags_json = ?, updated_at = updated_at WHERE id = ?');
        $tagsJson = json_encode($displayTags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $updatePage->execute([$tagsJson, $pageId]);
        $this->pdo->prepare('UPDATE rag_source_documents SET tags_json = ? WHERE page_id = ? AND is_current = 1 AND source_schema_version = ?')
            ->execute([$tagsJson, $pageId, self::SOURCE_SCHEMA_VERSION]);
    }

    /** @param array<string, mixed> $block */
    private function blockText(array $block): string
    {
        $type = (string) ($block['type'] ?? 'paragraph');
        if ($type === 'divider') {
            return '';
        }
        if ($type === 'file') {
            $name = trim((string) ($block['file_name'] ?? ''));
            return $name === '' ? '' : '[添付ファイル] ' . $name;
        }
        if ($type === 'table') {
            $lines = [];
            foreach ((array) ($block['table_rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $cells = [];
                foreach ($row as $cell) {
                    $cells[] = $this->htmlToText((string) (is_array($cell) ? ($cell['content'] ?? '') : $cell));
                }
                $line = trim(implode(' | ', $cells));
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
            return trim(implode("\n", $lines));
        }
        return $this->htmlToText((string) ($block['content'] ?? ''));
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<br\s*\/?\s*>/iu', "\n", $html) ?? $html;
        $text = htmlspecialchars_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        return trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text);
    }

    /** @return array<int, string> */
    private function splitText(string $text, int $max): array
    {
        $parts = [];
        $remaining = trim($text);
        while (self::textLength($remaining) > $max) {
            $parts[] = trim($this->sliceText($remaining, $max));
            $remaining = trim($this->sliceFrom($remaining, $max));
        }
        if ($remaining !== '') {
            $parts[] = $remaining;
        }
        return $parts;
    }

    /** @return array<int, string> */
    private function cleanStringList(array $values, int $maxItems, int $maxLength): array
    {
        $result = [];
        $seen = [];
        foreach (array_slice($values, 0, $maxItems * 2) as $value) {
            $clean = $this->cleanText((string) $value, $maxLength);
            $key = $this->normalizeTag($clean);
            if ($clean !== '' && $key !== '' && !isset($seen[$key])) {
                $result[] = $clean;
                $seen[$key] = true;
                if (count($result) >= $maxItems) {
                    break;
                }
            }
        }
        return $result;
    }

    /** @return array<int, array{name: string, type: string, confidence: float, canonical_tag_id: int}> */
    private function sanitizeMetadataList(array $values, int $maxItems, string $defaultType): array
    {
        $result = [];
        $seen = [];
        foreach (array_slice($values, 0, $maxItems * 2) as $value) {
            if (!is_array($value)) {
                continue;
            }
            $name = preg_replace('/^#+/u', '', $this->cleanText((string) ($value['name'] ?? ''), 120)) ?? '';
            $normalized = $this->normalizeTag($name);
            $confidence = max(0.0, min(1.0, (float) ($value['confidence'] ?? 0.5)));
            $minimum = $defaultType === 'status' ? 0.7 : 0.5;
            if ($name === '' || $normalized === '' || $confidence < $minimum || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $result[] = [
                'name' => $name,
                'type' => $this->cleanTagType((string) ($value['type'] ?? $defaultType)),
                'confidence' => $confidence,
                'canonical_tag_id' => max(0, (int) ($value['canonical_tag_id'] ?? 0)),
            ];
            if (count($result) >= $maxItems) {
                break;
            }
        }
        return $result;
    }

    private function cleanBlockId(string $blockId): string
    {
        $blockId = $this->cleanText($blockId, 190);
        return $blockId === '' ? self::PAGE_TITLE_BLOCK_ID : $blockId;
    }

    private function cleanTagType(string $type): string
    {
        $type = strtolower(trim($type));
        $type = preg_replace('/[^a-z0-9_-]+/', '_', $type) ?? '';
        $type = trim($type, '_-');
        return substr($type === '' ? 'general' : $type, 0, 40);
    }

    private function cleanText(string $text, int $max): string
    {
        $text = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '');
        return $this->sliceText($text, $max);
    }

    private function normalizeTag(string $tag): string
    {
        if (class_exists('Normalizer')) {
            $tag = Normalizer::normalize($tag, Normalizer::FORM_KC) ?: $tag;
        }
        $tag = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
        return preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($tag)) ?? '';
    }

    private static function textLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $length = iconv_strlen($text, 'UTF-8');
            return $length === false ? strlen($text) : $length;
        }
        return strlen($text);
    }

    private function sliceText(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($text, 0, $length, 'UTF-8');
            return $result === false ? substr($text, 0, $length) : $result;
        }
        return substr($text, 0, $length);
    }

    private function sliceFrom(string $text, int $offset): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $offset, null, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($text, $offset, null, 'UTF-8');
            return $result === false ? substr($text, $offset) : $result;
        }
        return substr($text, $offset);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
