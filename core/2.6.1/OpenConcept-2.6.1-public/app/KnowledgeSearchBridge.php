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
        $seen = []; $found = [];
        foreach (array_slice(array_values(array_unique($terms)), 0, 8) as $term) {
            if (!is_string($term) || mb_strlen(trim($term)) < 2) { continue; }
            $result = $service->search($actor, ['q' => $term, 'limit' => 30, ...$filters]);
            foreach ($result['items'] as $entry) {
                if ($entry['type'] === 'file_version' || isset($seen[$entry['reference']])) { continue; }
                $seen[$entry['reference']] = true;
                $record = $service->record($entry['reference'], $actor);
                $position = mb_stripos($record['text'], $term);
                $offset = $position === false ? 0 : max(0, $position - 800);
                $text = mb_substr($record['text'], $offset, 3500);
                $id = 'oc-history-' . hash('sha256', $record['reference'] . ':' . $record['content_hash'] . ':' . $offset);
                $score = 0.0;
                foreach ($terms as $needle) {
                    if (is_string($needle) && $needle !== '' && mb_stripos($record['title'] . '\n' . $text, $needle) !== false) { $score += 1.5; }
                }
                $found[] = [
                    'internal_id' => 0, 'document_id' => 'history:' . $record['reference'], 'chunk_id' => $id,
                    'page_id' => (int) ($record['page_id'] ?? 0), 'file_id' => 0,
                    'page_title' => $record['title'], 'chunk_title' => $record['type'], 'chunk_summary' => mb_substr($text, 0, 200),
                    'content' => $text, 'tags' => [], 'source_scope' => 'knowledge_history', 'source_hash' => $record['content_hash'],
                    'score' => $score, 'url' => rtrim($url, '/') . '/history.php?record=' . rawurlencode($record['reference']),
                    'history_reference' => $record['reference'], 'history_content_hash' => $record['content_hash'], 'history_offset' => $offset,
                    'history_metadata' => array_intersect_key($record, array_flip(['type', 'role', 'speaker', 'kind', 'sequence', 'occurred_at', 'recorded_at', 'relations'])),
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
