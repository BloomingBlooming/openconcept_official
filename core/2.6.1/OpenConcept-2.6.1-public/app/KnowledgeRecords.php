<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRepository.php';

/** Immutable originals in the existing, canonical Core record store. */
final class KnowledgeRecords
{
    public const SCOPE = 'core:knowledge';
    public const IMPORT_FORMAT = 'openconcept-conversation';
    public const MAX_IMPORT_BYTES = 4194304;
    private PluginApiRepository $records;

    public function __construct(private readonly PDO $pdo)
    {
        $this->records = new PluginApiRepository($pdo);
    }

    public static function canonicalJson(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) { return $item; }
            if (!array_is_list($item)) { ksort($item, SORT_STRING); }
            return array_map($sort, $item);
        };
        return json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function append(string $collection, string $id, array $value): bool
    {
        if (!in_array($collection, ['messages', 'imports', 'relations', 'turns'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Invalid knowledge record identity.', 422);
        }
        $old = $this->records->get(self::SCOPE, $collection, $id);
        if ($old !== null) {
            // The first capture time belongs to the original import, not a retry.
            $before = $old['value']; $after = $value;
            unset($before['recorded_at'], $after['recorded_at']);
            if (!hash_equals(hash('sha256', self::canonicalJson($before)), hash('sha256', self::canonicalJson($after)))) {
                throw new PluginApiConflict('An existing original cannot be overwritten.', 409);
            }
            return false;
        }
        $this->records->put(self::SCOPE, $collection, $id, $value, 0);
        return true;
    }

    public function get(string $collection, string $id): ?array
    {
        return $this->records->get(self::SCOPE, $collection, $id)['value'] ?? null;
    }

    public static function messageId(int $owner, string $source, string $conversation, string $message): string
    {
        return hash('sha256', self::canonicalJson([$owner, $source, $conversation, $message]));
    }

    /** Source timestamps require an explicit offset; missing values remain unknown. */
    public static function timestamp(mixed $value): ?string
    {
        if ($value === null) { return null; }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new InvalidArgumentException('A source timestamp must be ISO 8601 with its time zone, or null.', 422);
        }
        try { $date = new DateTimeImmutable($value); }
        catch (Throwable $error) { throw new InvalidArgumentException('Invalid source timestamp.', 422, $error); }
        $errors = DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
            throw new InvalidArgumentException('Invalid source timestamp.', 422);
        }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    public function import(string $raw, array $actor): array
    {
        if ((int) ($actor['id'] ?? 0) < 1 || strlen($raw) > self::MAX_IMPORT_BYTES || !preg_match('//u', $raw)) {
            throw new InvalidArgumentException('The conversation archive is invalid or too large.', 422);
        }
        // Decode objects first: an object must never silently become a message list.
        try { $input = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { throw new InvalidArgumentException('Invalid conversation JSON.', 422, $error); }
        self::assertUniqueKeys($raw);
        if (!$input instanceof stdClass || ($input->format ?? '') !== self::IMPORT_FORMAT || ($input->version ?? null) !== 1
            || !is_array($input->messages ?? null) || count($input->messages) > 10000 || $input->messages === []) {
            throw new InvalidArgumentException('Expected openconcept-conversation version 1 with a message list.', 422);
        }
        $source = self::identifier($input->source ?? null);
        $conversation = self::identifier($input->conversation_id ?? null);
        if (!is_string($input->title ?? null) || mb_strlen($input->title) > 1000) {
            throw new InvalidArgumentException('A conversation title is required.', 422);
        }
        $owner = (int) $actor['id']; $now = gmdate('Y-m-d\TH:i:s\Z');
        $prepared = []; $sequences = []; $messageIds = [];
        foreach ($input->messages as $message) {
            if (!$message instanceof stdClass || !is_string($message->text ?? null)
                || strlen($message->text) > 1048576 || !is_int($message->sequence ?? null) || $message->sequence < 0
                || $message->sequence > 2147483647 || isset($sequences[$message->sequence])
                || !in_array($message->role ?? null, ['user', 'assistant', 'system', 'developer', 'tool', 'unknown'], true)) {
                throw new InvalidArgumentException('Invalid message, role, or duplicate sequence.', 422);
            }
            $externalId = self::identifier($message->id ?? null);
            if (isset($messageIds[$externalId])) { throw new InvalidArgumentException('Duplicate source message ID.', 422); }
            $messageIds[$externalId] = true; $sequences[$message->sequence] = true;
            $parent = isset($message->parent_id) ? self::identifier($message->parent_id) : null;
            $related = isset($message->relates_to) ? self::identifier($message->relates_to) : null;
            $kind = $message->kind ?? 'statement';
            if (!in_array($kind, ['statement', 'proposal', 'report', 'observation', 'verification', 'correction', 'retraction', 'decision'], true)
                || (in_array($kind, ['verification', 'correction', 'retraction'], true) && $related === null)) {
                throw new InvalidArgumentException('A correction, retraction, or verification requires relates_to.', 422);
            }
            if (isset($message->speaker) && (!is_string($message->speaker) || mb_strlen($message->speaker) > 1000)) {
                throw new InvalidArgumentException('Invalid speaker.', 422);
            }
            $id = self::messageId($owner, $source, $conversation, $externalId);
            $prepared[$id] = [
                'id' => $id, 'owner_id' => $owner, 'source' => $source, 'conversation_id' => $conversation,
                'title' => $input->title, 'external_id' => $externalId, 'role' => $message->role,
                'speaker' => $message->speaker ?? null, 'kind' => $kind, 'text' => $message->text,
                'sequence' => $message->sequence, 'parent_id' => $parent, 'relates_to' => $related,
                'occurred_at' => self::timestamp($message->occurred_at ?? null),
                'source_timestamp' => $message->occurred_at ?? null, 'recorded_at' => $now,
                'content_hash' => hash('sha256', $message->text),
                'source_metadata' => json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR),
            ];
        }
        foreach ($prepared as $row) {
            foreach (['parent_id', 'relates_to'] as $relation) {
                if ($row[$relation] === null) { continue; }
                $targetId = self::messageId($owner, $source, $conversation, $row[$relation]);
                $target = $prepared[$targetId] ?? $this->get('messages', $targetId);
                if ($target === null || $target['sequence'] >= $row['sequence']) {
                    throw new InvalidArgumentException('Relations must reference an earlier message in the same conversation.', 422);
                }
            }
        }
        // Sequence is part of the original identity; a later import cannot insert
        // a different message at an already occupied source sequence.
        return $this->records->transaction(function () use ($prepared, $owner, $source, $conversation, $raw, $now): array {
            $this->lockOwner($owner);
            $bySequence = [];
            foreach ($prepared as $candidate) { $bySequence[$candidate['sequence']] = $candidate['id']; }
            $query = $this->pdo->prepare('SELECT payload_json FROM extension_records WHERE scope = ? AND collection = ?');
            $query->execute([self::SCOPE, 'messages']);
            while ($stored = $query->fetch(PDO::FETCH_ASSOC)) {
                $row = json_decode($stored['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                if ($row['owner_id'] !== $owner || $row['source'] !== $source || $row['conversation_id'] !== $conversation) { continue; }
                if (isset($bySequence[$row['sequence']]) && $bySequence[$row['sequence']] !== $row['id']) {
                    throw new PluginApiConflict('The source sequence already belongs to another message.', 409);
                }
            }
            $query->closeCursor();
            $added = 0;
            foreach ($prepared as $id => $row) { $added += $this->append('messages', $id, $row) ? 1 : 0; }
            $hash = hash('sha256', $raw); $importId = hash('sha256', $owner . ':' . $hash);
            $this->append('imports', $importId, ['id' => $importId, 'owner_id' => $owner, 'raw_json' => $raw,
                'sha256' => $hash, 'source' => $source, 'conversation_id' => $conversation, 'recorded_at' => $now]);
            return ['import_id' => $importId, 'added' => $added, 'existing' => count($prepared) - $added,
                'source_sha256' => $hash, 'message_ids' => array_keys($prepared)];
        });
    }

    /** Local annotations are separate signed-in-user records, never message edits. */
    public function annotate(string $targetId, string $kind, string $text, array $actor): array
    {
        if (!in_array($kind, ['correction', 'retraction', 'verification', 'decision', 'observation'], true)
            || trim($text) === '' || strlen($text) > 1048576) {
            throw new InvalidArgumentException('Invalid additional record.', 422);
        }
        require_once __DIR__ . '/KnowledgeHistoryService.php';
        $reference = str_contains($targetId, ':') ? $targetId : 'message:' . $targetId;
        (new KnowledgeHistoryService($this->pdo))->record($reference, $actor);
        $id = hash('sha256', random_bytes(32));
        $row = ['id' => $id, 'owner_id' => (int) $actor['id'], 'target_reference' => $reference, 'kind' => $kind,
            'role' => 'user', 'speaker' => (string) ($actor['name'] ?? ''), 'text' => $text,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'), 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'content_hash' => hash('sha256', $text)];
        $this->append('relations', $id, $row);
        return $row;
    }

    public function turnRelation(int $turnId, int $previousTurnId, int $owner, string $kind = 'action_result'): void
    {
        $this->append('turns', hash('sha256', 'ai-turn:' . $turnId), [
            'turn_id' => $turnId, 'previous_turn_id' => $previousTurnId, 'owner_id' => $owner,
            'kind' => $kind, 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    private function lockOwner(int $id): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->prepare('UPDATE users SET id = id WHERE id = ?')->execute([$id]);
        } else {
            $query = $this->pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE'); $query->execute([$id]); $query->fetchColumn();
        }
    }

    private static function identifier(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '' || strlen($value) > 500 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid source identifier.', 422);
        }
        return $value;
    }

    /** Run after strict JSON decoding; reject last-key-wins ambiguity in originals. */
    public static function assertUniqueKeys(string $json): void
    {
        $stack = [];
        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($char === '"') {
                $start = $i++;
                while ($i < $length && $json[$i] !== '"') { if ($json[$i] === '\\') { $i++; } $i++; }
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['object'] && $stack[$top]['key']) {
                    $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
                    if (isset($stack[$top]['seen'][$key])) { throw new InvalidArgumentException('Duplicate JSON key in original archive.', 422); }
                    $stack[$top]['seen'][$key] = true;
                }
            } elseif ($char === '{' || $char === '[') {
                $stack[] = ['object' => $char === '{', 'key' => $char === '{', 'seen' => []];
            } elseif ($char === '}' || $char === ']') { array_pop($stack); }
            elseif ($stack !== [] && ($char === ':' || $char === ',')) {
                $top = count($stack) - 1;
                if ($stack[$top]['object']) { $stack[$top]['key'] = $char === ','; }
            }
        }
    }
}
