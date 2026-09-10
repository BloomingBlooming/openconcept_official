<?php

declare(strict_types=1);

require_once __DIR__ . '/KnowledgeRecords.php';
require_once __DIR__ . '/KnowledgeFileVersions.php';

/** Permission-aware historical reads. Current retrieval indexes are never evidence here. */
final class KnowledgeHistoryService
{
    public const TYPES = ['message', 'relation', 'page_revision', 'comment', 'ai_turn', 'voice_message', 'file_version'];
    private array $pages = [];
    private array $reading = [];

    public function __construct(private readonly PDO $pdo, private readonly string $uploads = '') {}

    public function search(array $actor, array $filters = []): array
    {
        $type = (string) ($filters['type'] ?? '');
        if ($type !== '' && !in_array($type, self::TYPES, true)) { throw new InvalidArgumentException('Invalid record type.', 422); }
        $query = trim((string) ($filters['q'] ?? ''));
        if (mb_strlen($query) > 1000) { throw new InvalidArgumentException('Search text is too long.', 422); }
        $terms = preg_split('/\s+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $basis = (string) ($filters['date_basis'] ?? 'recorded_at');
        if (!in_array($basis, ['recorded_at', 'occurred_at'], true)) { throw new InvalidArgumentException('Invalid date basis.', 422); }
        foreach (['from', 'to'] as $key) {
            if (($filters[$key] ?? '') !== '') {
                $date = (string) $filters[$key];
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
                    || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
                    throw new InvalidArgumentException('Invalid date filter.', 422);
                }
            }
        }
        if (($filters['from'] ?? '') !== '' && ($filters['to'] ?? '') !== '' && $filters['from'] > $filters['to']) {
            throw new InvalidArgumentException('The date range is reversed.', 422);
        }
        $items = []; $truncated = false; $this->pages = []; $scanned = 0; $deadline = microtime(true) + 5;
        foreach ($type === '' ? self::TYPES : [$type] as $kind) {
            foreach ($this->rows($kind, $actor) as $raw) {
                if (++$scanned > 20000 || microtime(true) > $deadline) { $truncated = true; break 2; }
                $record = $this->hydrate($kind, $raw, $actor);
                if ($record === null) { continue; }
                if ((int) ($filters['page_id'] ?? 0) > 0 && (int) ($record['page_id'] ?? 0) !== (int) $filters['page_id']) { continue; }
                $text = mb_strtolower($record['title'] . "\n" . $record['text']);
                foreach ($terms as $term) { if (!str_contains($text, $term)) { continue 2; } }
                $date = $record[$basis];
                if (($filters['from'] ?? '') !== '' && ($date === null || substr($date, 0, 10) < $filters['from'])) { continue; }
                if (($filters['to'] ?? '') !== '' && ($date === null || substr($date, 0, 10) > $filters['to'])) { continue; }
                $record['excerpt'] = mb_substr($record['text'], 0, 400);
                unset($record['text'], $record['data']);
                $items[] = $record;
                if (count($items) >= 2000) { $truncated = true; break 2; }
            }
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a[$basis] ?? '9999', $b[$basis] ?? '9999')
            ?: strcmp((string) ($a['conversation_key'] ?? ''), (string) ($b['conversation_key'] ?? ''))
            ?: (($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0)) ?: strcmp($a['reference'], $b['reference']));
        $offset = max(0, min(2000, (int) ($filters['offset'] ?? 0)));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
        $selected = array_slice($items, $offset, $limit);
        return ['items' => $selected, 'next_offset' => $offset + $limit < count($items) ? $offset + $limit : null,
            'truncated' => $truncated, 'date_basis' => $basis, 'matched_count' => count($items)];
    }

    public function record(string $reference, array $actor): array
    {
        if (isset($this->reading[$reference]) || count($this->reading) >= 16) { throw new RuntimeException('Historical reference cycle.', 409); }
        $this->reading[$reference] = true;
        try {
        if (!preg_match('/^([a-z_]+):([a-zA-Z0-9_-]{1,190})$/D', $reference, $parts) || !in_array($parts[1], self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid record reference.', 422);
        }
        foreach ($this->rows($parts[1], $actor, $parts[2]) as $row) {
            $record = $this->hydrate($parts[1], $row, $actor);
            if ($record !== null) {
                foreach ($this->rows('relation', $actor) as $relationRow) {
                    $relation = json_decode($relationRow['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                    if ((int) $relation['owner_id'] === (int) $actor['id']
                        && ($relation['target_reference'] ?? ('message:' . ($relation['target_id'] ?? ''))) === $reference) {
                        $record['relations'][] = ['kind' => $relation['kind'], 'reference' => 'relation:' . $relationRow['record_key']];
                    }
                }
                if ($record['type'] === 'message') {
                    $original = $record['data'];
                    foreach ($this->rows('message', $actor) as $messageRow) {
                        $message = json_decode($messageRow['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                        if ((int) $message['owner_id'] === (int) $actor['id'] && $message['source'] === $original['source']
                            && $message['conversation_id'] === $original['conversation_id'] && $message['relates_to'] === $original['external_id']) {
                            $record['relations'][] = ['kind' => $message['kind'], 'reference' => 'message:' . $messageRow['record_key']];
                        }
                    }
                } elseif ($record['type'] === 'ai_turn') {
                    $links = $this->pdo->query("SELECT payload_json FROM extension_records WHERE scope = 'core:knowledge' AND collection = 'turns'");
                    while ($link = $links->fetchColumn()) {
                        $value = json_decode($link, true, 64, JSON_THROW_ON_ERROR);
                        if ((int) $value['owner_id'] === (int) $actor['id'] && (int) $value['previous_turn_id'] === (int) $record['id']) {
                            $record['relations'][] = ['kind' => $value['kind'], 'reference' => 'ai_turn:' . $value['turn_id']];
                        }
                    }
                }
                return $record;
            }
        }
        throw new RuntimeException('Record unavailable.', 404);
        } finally { unset($this->reading[$reference]); }
    }

    public function file(string $reference, array $actor): array
    {
        $record = $this->record($reference, $actor);
        if ($record['type'] !== 'file_version') { throw new RuntimeException('Original unavailable.', 404); }
        $value = $record['data']; $file = $value['file'];
        $path = KnowledgeFileVersions::path($this->uploads, (string) $file['stored_name']);
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($hash) || !hash_equals((string) $value['source']['content_hash'], $hash)
            || filesize($path) !== (int) $file['size_bytes']) {
            throw new RuntimeException('Original integrity check failed.', 409);
        }
        return ['path' => $path, 'name' => $file['original_name'], 'mime' => $file['mime_type'], 'sha256' => $hash];
    }

    /** Search helpers use bound values; plugin table names are Core-defined. */
    private function rows(string $type, array $actor, ?string $id = null): iterable
    {
        $owner = (int) ($actor['id'] ?? 0);
        if ($owner < 1) { return; }
        $params = [];
        if (in_array($type, ['message', 'relation', 'file_version'], true)) {
            $collection = ['message' => 'messages', 'relation' => 'relations', 'file_version' => 'file-versions'][$type];
            $sql = 'SELECT record_key, payload_json, created_at FROM extension_records WHERE scope = ? AND collection = ?';
            $params = [$type === 'file_version' ? 'core:sources' : KnowledgeRecords::SCOPE, $collection];
            if ($id !== null) { $sql .= ' AND record_key = ?'; $params[] = $id; }
            $order = 'record_key';
        } elseif ($type === 'ai_turn') {
            $sql = 'SELECT t.*, c.user_id AS owner_id, c.title AS conversation_title FROM ai_chat_turns t JOIN ai_conversations c ON c.id = t.conversation_id WHERE c.user_id = ?';
            $params = [$owner]; if ($id !== null) { $sql .= ' AND t.id = ?'; $params[] = $id; } $order = 't.id';
        } elseif ($type === 'voice_message') {
            $tables = self::voiceTables($this->pdo);
            if ($tables === null) { return; }
            $sql = 'SELECT m.*, c.user_id AS owner_id, c.title AS conversation_title FROM ' . $tables['messages'] . ' m JOIN ' . $tables['conversations'] . ' c ON c.id = m.conversation_id WHERE c.user_id = ?';
            $params = [$owner]; if ($id !== null) { $sql .= ' AND m.id = ?'; $params[] = $id; } $order = 'm.id';
        } else {
            $table = $type === 'page_revision' ? 'revisions' : 'comments';
            $sql = 'SELECT * FROM ' . $table . ' WHERE 1 = 1';
            if ($id !== null) { $sql .= ' AND id = ?'; $params[] = $id; } $order = 'id';
        }
        for ($offset = 0; ; $offset += 250) {
            $statement = $this->pdo->prepare($sql . ' ORDER BY ' . $order . ' LIMIT 250 OFFSET ' . $offset);
            $statement->execute($params); $batch = $statement->fetchAll(PDO::FETCH_ASSOC); $statement->closeCursor();
            foreach ($batch as $row) { yield $row; }
            if (count($batch) < 250) { break; }
        }
    }

    private function hydrate(string $type, array $row, array $actor): ?array
    {
        $record = ['type' => $type, 'id' => (string) ($row['id'] ?? $row['record_key']), 'title' => '', 'text' => '',
            'occurred_at' => null, 'recorded_at' => (string) ($row['created_at'] ?? ''), 'page_id' => null,
            'role' => 'unknown', 'speaker' => null, 'kind' => 'statement', 'sequence' => null, 'relations' => [], 'data' => $row];
        if ($type === 'message' || $type === 'relation') {
            $value = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            if ((int) $value['owner_id'] !== (int) $actor['id']) { return null; }
            $record = array_replace($record, array_intersect_key($value, array_flip(['title', 'text', 'occurred_at', 'recorded_at', 'role', 'speaker', 'kind', 'sequence'])));
            $record['data'] = $value;
            if ($type === 'message') {
                $record['conversation_key'] = $value['source'] . ':' . $value['conversation_id'];
                foreach (['parent_id', 'relates_to'] as $relation) {
                    if ($value[$relation] !== null) {
                        $record['relations'][] = ['kind' => $relation, 'reference' => 'message:' . KnowledgeRecords::messageId((int) $actor['id'], $value['source'], $value['conversation_id'], $value[$relation])];
                    }
                }
            } else {
                $target = $value['target_reference'] ?? ('message:' . $value['target_id']);
                try { $this->record($target, $actor); } catch (Throwable) { return null; }
                $record['title'] = $value['kind'];
                $record['relations'][] = ['kind' => $value['kind'], 'reference' => $target];
            }
        } elseif ($type === 'file_version') {
            $value = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            $file = $value['file'] ?? null;
            if (!is_array($file) || !$this->fileReadable($file, $actor)) { return null; }
            $record['title'] = $record['text'] = (string) $file['original_name'];
            $record['page_id'] = $file['page_id']; $record['data'] = $value;
            $record['recorded_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $row['created_at']);
        } elseif ($type === 'ai_turn' || $type === 'voice_message') {
            if ((int) $row['owner_id'] !== (int) $actor['id']) { return null; }
            if ($type === 'ai_turn') {
                if (!$this->turnReadable($row, $actor)) { return null; }
                $record['text'] = "[user]\n" . $row['question'] . "\n\n[assistant]\n" . $row['answer'];
                $record['role'] = 'conversation'; $record['sequence'] = (int) $row['turn_index'];
                $record['speaker'] = 'user_id:' . $row['owner_id'] . ' / ' . ($row['model'] ?: 'assistant');
                $relation = (new KnowledgeRecords($this->pdo))->get('turns', hash('sha256', 'ai-turn:' . $row['id']));
                if ($relation !== null) { $record['relations'][] = ['kind' => $relation['kind'], 'reference' => 'ai_turn:' . $relation['previous_turn_id']]; }
            } else {
                $record['text'] = $row['text']; $record['role'] = $row['role']; $record['sequence'] = (int) $row['id'];
                $record['speaker'] = $row['role'] === 'user' ? 'user_id:' . $row['owner_id'] : ($row['model'] ?: null);
            }
            $record['title'] = (string) $row['conversation_title'];
            $record['conversation_key'] = $type . ':' . $row['conversation_id'];
            // Legacy created_at is the database capture timestamp. Never fabricate
            // an external utterance time or an offset which was not recorded.
        } else {
            $page = $this->page((int) $row['page_id']);
            if ($page === null || !canViewPage($this->pdo, $actor, $page)) { return null; }
            $record['page_id'] = (int) $row['page_id'];
            $record['title'] = (string) ($row['title'] ?? $page['title']); $record['role'] = 'user';
            $record['speaker'] = 'user_id:' . ($row['user_id'] ?? $row['created_by']);
            if ($type === 'comment') { $record['text'] = (string) $row['body']; }
            else {
                $metadata = json_decode($row['meta_json'], true) ?: [];
                $record['text'] = self::blockText((string) $row['blocks_json']);
                $record['data']['metadata'] = $metadata;
                // Expose the recorded original timestamp without pretending its
                // historical database timezone is known.
                $record['original_updated_at'] = $metadata['original_updated_at'] ?? null;
                foreach ($metadata['file_versions'] ?? [] as $file) {
                    $record['relations'][] = ['kind' => 'attachment', 'reference' => 'file_version:' . $file['key']];
                }
            }
        }
        $record['reference'] = $type . ':' . $record['id'];
        $record['content_hash'] = hash('sha256', $record['text']);
        return $record;
    }

    private function page(int $id): ?array
    {
        if (!array_key_exists($id, $this->pages)) {
            $statement = $this->pdo->prepare('SELECT p.*, u.department AS author_department FROM pages p JOIN users u ON u.id = p.author_id WHERE p.id = ?');
            $statement->execute([$id]); $this->pages[$id] = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        return $this->pages[$id];
    }

    private function fileReadable(array $file, array $actor): bool
    {
        if ((int) ($file['page_id'] ?? 0) > 0) {
            $page = $this->page((int) $file['page_id']);
            return $page !== null && canViewPage($this->pdo, $actor, $page);
        }
        return (int) ($file['uploaded_by'] ?? 0) === (int) $actor['id'] || ($actor['role'] ?? '') === 'admin';
    }

    private function turnReadable(array $row, array $actor): bool
    {
        $ids = [];
        if ((int) ($row['page_id'] ?? 0) > 0) { $ids[] = (int) $row['page_id']; }
        $sources = json_decode($row['sources_json'], true) ?: [];
        if (!empty($row['sufficient']) && $sources === []) { return false; }
        foreach ($sources as $source) {
            if (!is_array($source)) { return false; }
            if ((int) ($source['page_id'] ?? 0) > 0) { $ids[] = (int) $source['page_id']; }
            if (($source['source_scope'] ?? '') === 'knowledge_history') {
                try { $this->record((string) ($source['history_reference'] ?? ''), $actor); }
                catch (Throwable) { return false; }
            } elseif (($source['source_scope'] ?? '') === 'extension_content') {
                if (Hooks::filter('ai_search_extension_evidence_valid', null, $source, $actor) !== true) { return false; }
            } elseif ((int) ($source['file_id'] ?? 0) > 0) {
                $statement = $this->pdo->prepare('SELECT * FROM files WHERE id = ?'); $statement->execute([(int) $source['file_id']]);
                $file = $statement->fetch(PDO::FETCH_ASSOC);
                if (!is_array($file) || !$this->fileReadable($file, $actor)) { return false; }
            }
        }
        foreach (array_unique($ids) as $id) {
            $page = $this->page($id);
            if ($page === null || !canViewPage($this->pdo, $actor, $page)) { return false; }
        }
        return true;
    }

    public static function voiceTables(PDO $pdo): ?array
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? $pdo->tablePrefix() : '';
        if ($prefix !== '' && !preg_match('/^[a-z][a-z0-9_]*_$/D', $prefix)) { throw new RuntimeException('Invalid table prefix.'); }
        $names = ['conversations' => $prefix . 'plugin_voice_chats', 'messages' => $prefix . 'plugin_voice_msgs'];
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach ($names as $name) {
            $sql = match ($driver) {
                'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                'mysql' => 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                'pgsql' => 'SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = current_schema() AND tablename = ?',
                default => throw new RuntimeException('Unsupported database.'),
            };
            $statement = $pdo->prepare($sql); $statement->execute([$name]);
            if (!$statement->fetchColumn()) { return null; }
        }
        $quote = $driver === 'mysql' ? '`' : '"';
        return array_map(static fn(string $name): string => $quote . $name . $quote, $names);
    }

    public static function blockText(string $json): string
    {
        $blocks = json_decode($json, true) ?: [];
        return implode("\n", array_map(static function (array $block): string {
            if (($block['type'] ?? '') === 'table') {
                return implode("\n", array_map(static fn(array $row): string => implode(' | ', array_map(static fn($cell): string => strip_tags((string) $cell), $row)), $block['rows'] ?? []));
            }
            return html_entity_decode(strip_tags((string) ($block['html'] ?? $block['text'] ?? $block['content'] ?? $block['caption'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, array_filter($blocks, 'is_array')));
    }
}
