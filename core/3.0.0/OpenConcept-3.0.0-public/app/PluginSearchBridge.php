<?php

declare(strict_types=1);

/** Core-owned accepted extension content in the normal AI search route. */
final class PluginSearchBridge
{
    private const CHUNK_CHARACTERS = 4000;
    private const CHUNK_STRIDE = 3920;

    public function __construct(private readonly PDO $pdo, private readonly PluginApiRegistry $registry, private readonly ?string $applicationUrl = null)
    {
    }

    public function register(): void
    {
        Hooks::addFilter('ai_search_extension_candidates', fn(array $candidates, array $terms, array $actor, int $limit, string $url): array => $this->candidates($candidates, $terms, $actor, $limit, $url));
        Hooks::addFilter('ai_search_extension_evidence_valid', fn(mixed $valid, array $source, array $actor): mixed => ($source['source_scope'] ?? '') === 'extension_content' ? $this->evidenceValid($source, $actor) : $valid);
    }

    /** Stream accepted rows so a number of large attachments cannot exhaust PHP memory. */
    public function candidates(array $existing, array $terms, array $actor, int $limit, string $url = ''): array
    {
        $needles = array_values(array_unique(array_filter(array_map(static fn(mixed $term): string => mb_strtolower(trim((string) $term)), $terms), static fn(string $term): bool => mb_strlen($term) >= 2)));
        if ($needles === []) { return $existing; }
        $needles = array_slice($needles, 0, 16);
        $clauses = []; $parameters = [];
        foreach ($needles as $needle) {
            $clauses[] = "body_text LIKE ? ESCAPE '!'";
            $parameters[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle) . '%';
        }
        $query = $this->pdo->prepare('SELECT * FROM extension_search_content WHERE state = \'accepted\' AND (' . implode(' OR ', $clauses) . ') ORDER BY updated_at DESC, id LIMIT 160');
        $query->execute($parameters);
        $combined = $existing;
        $limit = max(1, min(20, $limit));
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            $source = $this->registry->source($row, $actor);
            if ($source === null || !($source['current_published'] ?? false) || !($source['readable'] ?? false)) { continue; }
            $name = (string) ($source['filename'] ?? $source['title'] ?? '');
            $title = mb_strtolower($name);
            $reference = array_intersect_key($row, array_flip(['source_type', 'source_id', 'source_version', 'content_hash', 'page_id']));
            $chunks = mb_str_split($row['body_text'], self::CHUNK_STRIDE, 'UTF-8');
            foreach ($chunks as $index => $part) {
                $chunk = $part . (isset($chunks[$index + 1]) ? mb_substr($chunks[$index + 1], 0, self::CHUNK_CHARACTERS - self::CHUNK_STRIDE, 'UTF-8') : '');
                $lower = mb_strtolower($chunk);
                $score = 0.0;
                foreach ($needles as $needle) {
                    if (str_contains($title, $needle)) { $score += 2.0; }
                    if (str_contains($lower, $needle)) { $score += 1.0 + min(4, substr_count($lower, $needle)) * 0.2; }
                }
                if ($score <= 0) { continue; }
                $chunkHash = hash('sha256', $chunk);
                $chunkId = 'oc-extension-' . substr(hash('sha256', $row['id'] . ':' . $row['revision'] . ':' . $index . ':' . $chunkHash), 0, 40);
                $pageId = (int) ($source['page_id'] ?? 0);
                $baseUrl = rtrim($this->applicationUrl ?? $url, '/');
                $sourceUrl = (string) ($source['url'] ?? $source['download_url'] ?? ($pageId > 0 ? $baseUrl . '/#page-' . $pageId : ''));
                $combined[] = [
                    'internal_id' => 0, 'document_id' => 'oc-extension-document-' . $row['id'], 'chunk_id' => $chunkId,
                    'page_id' => $pageId, 'file_id' => $source['source_type'] === 'file' ? (int) $source['source_id'] : 0,
                    'page_title' => $name, 'chunk_title' => $name . ' / ' . ($index + 1),
                    'chunk_summary' => mb_substr($chunk, 0, 200), 'content' => $chunk, 'tags' => [],
                    'source_scope' => 'extension_content', 'source_hash' => $row['content_hash'], 'score' => $score, 'url' => $sourceUrl,
                    'extension_content_id' => $row['id'], 'extension_content_revision' => (int) $row['revision'],
                    'extension_source' => $reference, 'extension_chunk_index' => $index, 'extension_chunk_hash' => $chunkHash,
                ];
            }
            usort($combined, self::compare(...));
            $combined = array_slice($combined, 0, $limit);
        }
        usort($combined, self::compare(...));
        return array_slice($combined, 0, $limit);
    }

    private static function compare(array $left, array $right): int
    {
        return (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0)
            ?: strcmp((string) ($left['chunk_id'] ?? ''), (string) ($right['chunk_id'] ?? ''));
    }

    public function evidenceValid(array $proof, array $actor): bool
    {
        if (($proof['source_scope'] ?? '') !== 'extension_content' || !is_array($proof['extension_source'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['extension_content_id'] ?? ''))
            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($proof['extension_chunk_hash'] ?? ''))
            || !is_int($proof['extension_chunk_index'] ?? null) || $proof['extension_chunk_index'] < 0
            || $proof['extension_chunk_index'] > 10000 || (int) ($proof['extension_content_revision'] ?? 0) < 1) { return false; }
        $query = $this->pdo->prepare('SELECT id, source_type, source_id, source_version, content_hash, page_id, revision, body_text FROM extension_search_content WHERE id = ? AND revision = ? AND state = \'accepted\'');
        $query->execute([$proof['extension_content_id'], (int) $proof['extension_content_revision']]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals(PluginApiRepository::sourceKey($row), PluginApiRepository::sourceKey($proof['extension_source']))) { return false; }
        $source = $this->registry->source($row, $actor);
        if ($source === null || !($source['current_published'] ?? false) || !($source['readable'] ?? false)
            || (int) ($proof['page_id'] ?? 0) !== (int) ($source['page_id'] ?? 0)) { return false; }
        $index = $proof['extension_chunk_index'];
        $chunk = mb_substr($row['body_text'], $index * self::CHUNK_STRIDE, self::CHUNK_CHARACTERS, 'UTF-8');
        $hash = hash('sha256', $chunk);
        $id = 'oc-extension-' . substr(hash('sha256', $row['id'] . ':' . $row['revision'] . ':' . $index . ':' . $hash), 0, 40);
        return $chunk !== '' && hash_equals($hash, $proof['extension_chunk_hash']) && hash_equals($id, (string) ($proof['chunk_id'] ?? ''));
    }
}
