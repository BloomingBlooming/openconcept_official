<?php

declare(strict_types=1);

require_once __DIR__ . '/KnowledgeHistoryService.php';

final class KnowledgeSearchBridge
{
    public function __construct(private readonly PDO $pdo) {}

    public function register(): void
    {
        Hooks::addFilter('ai_search_extension_candidates', fn(array $existing, array $terms, array $actor, int $limit, string $url): array => $this->candidates($existing, $terms, $actor, $limit, $url));
        Hooks::addFilter('ai_search_extension_evidence_valid', fn(mixed $valid, array $proof, array $actor): mixed =>
            ($proof['source_scope'] ?? '') === 'knowledge_history' ? $this->evidenceValid($proof, $actor) : $valid);
    }

    public function candidates(array $existing, array $terms, array $actor, int $limit, string $url, array $filters = []): array
    {
        $service = new KnowledgeHistoryService($this->pdo);
        $terms = array_slice(array_values(array_unique(array_filter($terms, static fn($term): bool => is_string($term) && mb_strlen(trim($term)) >= 2))), 0, 8);
        if ($terms === []) { return $existing; }
        $filters = array_intersect_key($filters, array_flip(['type', 'from', 'to', 'date_basis', 'page_id']));
        // An annotation belongs to a particular saved event, even when its text repeats.
        $annotated = [];
        $query = $this->pdo->query("SELECT payload_json FROM extension_records WHERE scope = 'core:knowledge' AND collection = 'relations'");
        while ($json = $query->fetchColumn()) {
            $relation = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
            if ((int) ($relation['owner_id'] ?? 0) === (int) $actor['id']) { $annotated[$relation['target_reference'] ?? ''] = true; }
        }
        $groups = []; $cursor = ''; $deadline = microtime(true) + 15;
        do {
            $result = $service->scan($actor, [...$filters, 'cursor' => $cursor, 'limit' => 500], $terms);
            foreach ($result['items'] as $entry) {
                if ($entry['type'] === 'file_version') { continue; }
                // Only equivalent versions of the SAME page/speaker can share a candidate.
                // Conversations and annotated revisions retain their individual identity.
                $key = $entry['type'] === 'page_revision' && !isset($annotated[$entry['reference']])
                    ? hash('sha256', json_encode([$entry['page_id'], $entry['title'], $entry['content_hash'], $entry['speaker']], JSON_THROW_ON_ERROR))
                    : $entry['reference'];
                if (isset($groups[$key])) { $groups[$key]['count']++; continue; }
                $groups[$key] = ['entry' => $entry, 'count' => 1];
            }
            $cursor = $result['scan_cursor'];
            if ($cursor !== null && microtime(true) >= $deadline) {
                throw new RuntimeException('Historical search did not finish. Narrow the date range and try again.', 422);
            }
        } while ($cursor !== null);
        $found = [];
        foreach ($groups as $group) {
            if (microtime(true) >= $deadline) { throw new RuntimeException('Historical search did not finish. Narrow the date range and try again.', 422); }
            $record = $service->record($group['entry']['reference'], $actor);
            $positions = [];
            foreach ($terms as $term) { $position = mb_stripos($record['text'], $term); if ($position !== false) { $positions[] = $position; } }
            sort($positions);
            $offsets = [];
            foreach ($positions as $position) {
                if ($offsets === [] || $position >= $offsets[count($offsets) - 1] + 3500) { $offsets[] = max(0, $position - 800); }
            }
            if ($offsets === []) { $offsets[] = 0; }
            foreach ($offsets as $offset) {
            $text = mb_substr($record['text'], $offset, 3500);
            $id = 'oc-history-' . hash('sha256', $record['reference'] . ':' . $record['content_hash'] . ':' . $offset);
            $score = 0.0;
            foreach ($terms as $needle) { if (mb_stripos($record['title'] . "\n" . $text, $needle) !== false) { $score += 1.5; } }
            $found[] = [
                    'internal_id' => 0, 'document_id' => 'history:' . $record['reference'], 'chunk_id' => $id,
                    'page_id' => (int) ($record['page_id'] ?? 0), 'file_id' => 0,
                    'page_title' => $record['title'], 'chunk_title' => $record['type'], 'chunk_summary' => mb_substr($text, 0, 200),
                    'content' => $text, 'tags' => [], 'source_scope' => 'knowledge_history', 'source_hash' => $record['content_hash'],
                    'score' => $score, 'url' => rtrim($url, '/') . '/history.php?record=' . rawurlencode($record['reference']),
                    'history_reference' => $record['reference'], 'history_content_hash' => $record['content_hash'], 'history_offset' => $offset,
                    'history_metadata' => array_intersect_key($record, array_flip(['type', 'role', 'speaker', 'kind', 'sequence', 'occurred_at', 'recorded_at', 'relations']))
                        + ['matching_revision_count' => $record['type'] === 'page_revision' ? $group['count'] : 1],
                ];
            }
        }
        $all = [...$existing, ...$found];
        usort($all, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['chunk_id'], $b['chunk_id']));
        return array_slice($all, 0, max(1, min(20, $limit)));
    }

    public function evidenceValid(array $proof, array $actor): bool
    {
        if (!is_string($proof['history_reference'] ?? null) || !is_string($proof['history_content_hash'] ?? null)
            || !is_int($proof['history_offset'] ?? null) || $proof['history_offset'] < 0) { return false; }
        try { $record = (new KnowledgeHistoryService($this->pdo))->record($proof['history_reference'], $actor); }
        catch (Throwable) { return false; }
        $id = 'oc-history-' . hash('sha256', $record['reference'] . ':' . $record['content_hash'] . ':' . $proof['history_offset']);
        return hash_equals($record['content_hash'], $proof['history_content_hash'])
            && hash_equals($id, (string) ($proof['chunk_id'] ?? ''))
            && mb_substr($record['text'], $proof['history_offset'], 3500) !== ''
            && (int) ($record['page_id'] ?? 0) === (int) ($proof['page_id'] ?? 0);
    }
}
