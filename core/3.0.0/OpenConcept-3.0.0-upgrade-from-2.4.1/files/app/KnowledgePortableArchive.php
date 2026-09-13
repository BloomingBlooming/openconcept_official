<?php

declare(strict_types=1);

require_once __DIR__ . '/CanonicalReadSnapshotService.php';
require_once __DIR__ . '/KnowledgeHistoryService.php';

/** Streaming UTF-8 archive. JSONL + base64 originals require no ZIP extension. */
final class KnowledgePortableArchive
{
    public const FORMAT = 'openconcept-knowledge-archive';
    public const VERSION = 1;
    public const CORE_TABLES = ['users', 'pages', 'page_access_departments', 'page_access_members',
        'page_public_shares', 'page_public_share_pages', 'revisions', 'comments', 'files', 'ai_conversations', 'ai_chat_turns'];
    private const MAX_LINE = 16777216;
    private const MAX_BYTES = 3221225472;
    private static ?CoreSchemaCatalog $catalog = null;

    private static function catalog(): CoreSchemaCatalog { return self::$catalog ??= CoreSchemaCatalog::load(); }

    public function __construct(private readonly CanonicalReadSnapshotService $snapshots, private readonly string $issuer = 'knowledge-archive') {}

    public function create(array $actor, string $path): array
    {
        // The HTTP/CLI caller supplies a private output directory, never a user path.
        $output = @fopen($path, 'x+b');
        if ($output === false) { throw new RuntimeException('Archive output already exists or is unavailable.', 409); }
        @chmod($path, 0600); $snapshot = null; $success = false;
        try {
            $snapshot = $this->snapshots->begin($actor, $this->issuer, ['ttl_seconds' => 3600]);
            $hash = hash_init('sha256'); $count = 0;
            $this->write($output, $hash, ['kind' => 'header', 'format' => self::FORMAT, 'version' => self::VERSION,
                'created_at' => gmdate('c'), 'snapshot' => $snapshot, 'environment_recovery' => false]);
            $cursor = null;
            do {
                $batch = $this->snapshots->records($actor, $this->issuer, $snapshot['snapshot_id'], $cursor, 250);
                foreach ($batch['records'] as $record) { $this->write($output, $hash, ['kind' => 'record', 'value' => $record]); $count++; }
                $cursor = $batch['next_cursor'];
            } while (!$batch['done']);
            foreach ($snapshot['attachments'] as $attachment) {
                $offset = 0;
                do {
                    $part = $this->snapshots->attachment($actor, $this->issuer, $snapshot['snapshot_id'], $attachment['attachment_id'], $offset, 524288);
                    $this->write($output, $hash, ['kind' => 'original', 'id' => $attachment['attachment_id'], 'offset' => $offset, 'data_base64' => $part['data_base64']]);
                    $offset = $part['next_offset'];
                } while (!$part['done']);
            }
            $this->snapshots->finish($actor, $this->issuer, $snapshot['snapshot_id']);
            $digest = hash_final($hash);
            $this->write($output, null, ['kind' => 'complete', 'record_count' => $count, 'payload_sha256' => $digest, 'snapshot_verified' => true]);
            if (!fflush($output)) { throw new RuntimeException('Archive flush failed.'); }
            fclose($output); $output = null;
            $result = self::verify($path); $success = true;
            return $result + ['path' => $path];
        } finally {
            if (is_resource($output)) { fclose($output); }
            if (is_array($snapshot)) {
                try { $this->snapshots->close($actor, $this->issuer, $snapshot['snapshot_id']); }
                catch (Throwable) { /* Expiry cleanup remains available; preserve the original failure. */ }
            }
            if (!$success && is_file($path)) { @unlink($path); }
        }
    }

    private function write(mixed $output, mixed $hash, array $value): void
    {
        $line = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($line) > self::MAX_LINE) { throw new RuntimeException('Archive record exceeds the size limit.'); }
        for ($offset = 0; $offset < strlen($line); $offset += $written) {
            $written = fwrite($output, substr($line, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Archive write failed.'); }
        }
        if ($hash !== null) { hash_update($hash, $line); }
    }

    public static function verify(string $path): array { return self::scan($path); }

    /** A second pass during restore hashes the bytes again before the DB commit. */
    private static function scan(string $path, ?callable $recordConsumer = null, ?callable $originalConsumer = null): array
    {
        if (!is_file($path) || is_link($path) || filesize($path) > self::MAX_BYTES) { throw new RuntimeException('Invalid archive file.', 422); }
        $handle = fopen($path, 'rb');
        if ($handle === false) { throw new RuntimeException('Archive unavailable.', 422); }
        $hash = hash_init('sha256'); $header = null; $footer = null; $records = 0; $total = 0;
        $attachments = []; $originalHashes = []; $offsets = []; $counts = [];
        try {
            while (($line = fgets($handle, self::MAX_LINE + 1)) !== false) {
                $total += strlen($line);
                if (!str_ends_with($line, "\n") || $total > self::MAX_BYTES || $footer !== null) { throw new RuntimeException('Invalid or oversized archive stream.', 422); }
                $item = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                KnowledgeRecords::assertUniqueKeys($line);
                if (!is_array($item) || !is_string($item['kind'] ?? null)) { throw new RuntimeException('Invalid archive item.', 422); }
                if ($header === null) {
                    if ($item['kind'] !== 'header' || ($item['format'] ?? '') !== self::FORMAT || ($item['version'] ?? null) !== self::VERSION
                        || ($item['snapshot']['format'] ?? '') !== CanonicalReadSnapshotService::FORMAT
                        || ($item['snapshot']['contract_version'] ?? null) !== CanonicalReadSnapshotService::VERSION
                        || ($item['snapshot']['canonical_identity']['schema_version'] ?? null) !== self::catalog()->schemaVersion()
                        || ($item['snapshot']['canonical_identity']['schema_artifact_hash'] ?? '') !== self::catalog()->artifactHash()
                        || ($item['snapshot']['scope']['tables'] ?? null) !== self::CORE_TABLES
                        || ($item['snapshot']['scope']['providers'] ?? null) !== ['knowledge-history']
                        || ($item['snapshot']['scope']['include_attachments'] ?? null) !== true) {
                        throw new RuntimeException('Unsupported or incomplete canonical archive scope.', 422);
                    }
                    $header = $item;
                    foreach ($item['snapshot']['attachments'] ?? [] as $attachment) {
                        $id = $attachment['attachment_id'] ?? '';
                        if (!is_string($id) || !preg_match('/^[a-f0-9]{64}$/D', $id) || isset($attachments[$id])
                            || !in_array($attachment['owner'] ?? '', ['core', 'knowledge-history'], true)
                            || !is_int($attachment['size_bytes'] ?? null) || $attachment['size_bytes'] < 0
                            || !preg_match('/^[a-f0-9]{64}$/D', (string) ($attachment['sha256'] ?? ''))) {
                            throw new RuntimeException('Invalid original manifest.', 422);
                        }
                        $attachments[$id] = $attachment; $offsets[$id] = 0; $originalHashes[$id] = hash_init('sha256');
                    }
                } elseif ($item['kind'] === 'record') {
                    $record = $item['value'] ?? null;
                    self::validateRecord($record);
                    $records++; $key = $record['owner'] . ':' . $record['type']; $counts[$key] = ($counts[$key] ?? 0) + 1;
                    if ($records > 1000000) { throw new RuntimeException('Too many archive records.', 422); }
                    if ($recordConsumer !== null) { $recordConsumer($record); }
                } elseif ($item['kind'] === 'original') {
                    $id = $item['id'] ?? '';
                    if (!is_string($id) || !isset($attachments[$id]) || ($item['offset'] ?? null) !== $offsets[$id]
                        || !is_string($item['data_base64'] ?? null)) { throw new RuntimeException('Original segments are missing or out of order.', 422); }
                    $bytes = base64_decode($item['data_base64'], true);
                    if ($bytes === false || strlen($bytes) > 1048576) { throw new RuntimeException('Invalid original segment.', 422); }
                    $offsets[$id] += strlen($bytes);
                    if ($offsets[$id] > $attachments[$id]['size_bytes']) { throw new RuntimeException('Original size mismatch.', 422); }
                    hash_update($originalHashes[$id], $bytes);
                    if ($originalConsumer !== null) { $originalConsumer($attachments[$id], $bytes, $item['offset']); }
                } elseif ($item['kind'] === 'complete') {
                    $footer = $item;
                    if (($item['record_count'] ?? null) !== $records || $records !== ($header['snapshot']['record_count'] ?? null)
                        || ($item['snapshot_verified'] ?? false) !== true
                        || !hash_equals(hash_final($hash), (string) ($item['payload_sha256'] ?? ''))) {
                        throw new RuntimeException('Archive checksum or record count mismatch.', 422);
                    }
                    continue;
                } else { throw new RuntimeException('Unknown archive item.', 422); }
                hash_update($hash, $line);
            }
            if ($header === null || $footer === null) { throw new RuntimeException('Archive is incomplete.', 422); }
            $declaredCounts = $header['snapshot']['counts'] ?? [];
            if (!is_array($declaredCounts) || count($attachments) !== ($header['snapshot']['attachment_count'] ?? null)) {
                throw new RuntimeException('Canonical manifest totals are inconsistent.', 422);
            }
            foreach ($declaredCounts as $key => $count) {
                if (!is_int($count) || $count < 0 || ($counts[$key] ?? 0) !== $count) { throw new RuntimeException('Canonical table count mismatch.', 422); }
            }
            if (array_diff_key($counts, $declaredCounts) !== []) { throw new RuntimeException('An undeclared table was exported.', 422); }
            foreach ($attachments as $id => $attachment) {
                if ($offsets[$id] !== $attachment['size_bytes'] || !hash_equals($attachment['sha256'], hash_final($originalHashes[$id]))) {
                    throw new RuntimeException('Original checksum mismatch.', 422);
                }
            }
            return ['format' => self::FORMAT, 'version' => self::VERSION, 'record_count' => $records, 'counts' => $counts,
                'attachment_count' => count($attachments), 'payload_sha256' => $footer['payload_sha256'],
                'verified' => true, 'restore_tested' => false, 'environment_recovery' => false];
        } finally { fclose($handle); }
    }

    private static function validateRecord(mixed $record): void
    {
        if (!is_array($record) || !is_array($record['data'] ?? null)) { throw new RuntimeException('Invalid canonical record.', 422); }
        $owner = $record['owner'] ?? ''; $type = $record['type'] ?? '';
        if ($owner === 'core' && in_array($type, self::CORE_TABLES, true)) {
            $columns = array_column(self::catalog()->table('sqlite', $type)['columns'], 'name');
            $omitted = $type === 'users' ? ['password_hash', 'must_change_password'] : ($type === 'pages' ? ['plain_text', 'tags_json'] : []);
            $columns = array_values(array_diff($columns, $omitted));
        } elseif ($owner === 'knowledge-history' && $type === 'extension_records') {
            $columns = array_column(self::catalog()->table('sqlite', 'extension_records')['columns'], 'name');
            $row = $record['data'];
            if (!(($row['scope'] ?? '') === 'core:knowledge' && in_array($row['collection'] ?? '', ['messages', 'imports', 'relations', 'turns'], true))
                && !(($row['scope'] ?? '') === 'core:sources' && ($row['collection'] ?? '') === 'file-versions')) {
                throw new RuntimeException('Archive contains a noncanonical extension scope.', 422);
            }
        } elseif ($owner === 'knowledge-history' && in_array($type, ['voice_conversations', 'voice_messages'], true)) {
            $columns = ['id', 'payload_json'];
        } else { throw new RuntimeException('Archive contains an unsupported owner or table.', 422); }
        $keys = array_keys($record['data']); sort($keys); sort($columns);
        if ($keys !== $columns) { throw new RuntimeException('Canonical archive column mismatch.', 422); }
    }

    /** Restore only into an explicitly supplied empty, isolated SQLite destination. */
    public static function restore(string $path, Database $destination, string $uploads): array
    {
        $verified = self::verify($path); $pdo = $destination->pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') { throw new RuntimeException('Portable restore requires an empty isolated SQLite destination.', 422); }
        foreach ([...self::CORE_TABLES, 'extension_records'] as $table) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM "' . $table . '"')->fetchColumn() !== 0) { throw new RuntimeException('Restore refuses a nonempty canonical destination.', 409); }
        }
        $root = realpath($destination->storagePath());
        if (!is_string($root) || is_link($uploads) || (is_dir($uploads) && count(scandir($uploads) ?: []) > 2)) {
            throw new RuntimeException('Restore requires a new original storage directory.', 409);
        }
        $expected = $root . DIRECTORY_SEPARATOR . 'uploads';
        if (rtrim(str_replace('\\', '/', $uploads), '/') !== str_replace('\\', '/', $expected)) { throw new RuntimeException('Restore originals must be inside the isolated destination.', 409); }
        if (!is_dir($uploads) && !mkdir($uploads, 0700)) { throw new RuntimeException('Restore original storage unavailable.'); }
        if (($verified['counts']['knowledge-history:voice_conversations'] ?? 0) + ($verified['counts']['knowledge-history:voice_messages'] ?? 0) > 0) {
            require_once dirname(__DIR__) . '/plugins/voice-conversation/src/VoiceConversationRepository.php';
            (new VoiceConversationRepository($pdo))->migrate();
        }
        $voiceTables = KnowledgeHistoryService::voiceTables($pdo); $created = []; $originalNames = [];
        $pdo->beginTransaction(); $pdo->exec('PRAGMA defer_foreign_keys = ON');
        try {
            $restored = self::scan($path, function (array $record) use ($pdo, $voiceTables): void {
                $row = $record['data']; $type = $record['type']; $table = $type;
                if ($record['owner'] === 'knowledge-history' && str_starts_with($type, 'voice_')) {
                    $row = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
                    $table = $voiceTables[$type === 'voice_conversations' ? 'conversations' : 'messages'];
                    $allowed = $type === 'voice_conversations'
                        ? ['id', 'user_id', 'title', 'summary', 'summary_until_message_id', 'created_at', 'updated_at']
                        : ['id', 'conversation_id', 'role', 'text', 'input_source', 'model', 'response_id', 'input_tokens', 'output_tokens', 'created_at'];
                    $keys = array_keys($row); sort($keys); sort($allowed);
                    if ($keys !== $allowed) { throw new RuntimeException('Voice archive column mismatch.'); }
                } else { $table = '"' . $table . '"'; }
                $original = $row;
                if ($type === 'users') { $row['password_hash'] = '!restored-without-credentials'; $row['must_change_password'] = 1; }
                if ($type === 'pages') { $row['plain_text'] = KnowledgeHistoryService::blockText($row['blocks_json']); $row['tags_json'] = $row['manual_tags_json'] ?? '[]'; }
                $columns = array_keys($row);
                $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_map(static fn($column): string => '"' . $column . '"', $columns))
                    . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')';
                $pdo->prepare($sql)->execute(array_values($row));
                $primary = $type === 'extension_records' ? ['scope', 'collection', 'record_key']
                    : ($record['owner'] === 'core' ? self::catalog()->table('sqlite', $type)['primary_key'] : ['id']);
                $where = implode(' AND ', array_map(static fn($column): string => '"' . $column . '" = ?', $primary));
                $query = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $where); $query->execute(array_map(static fn($column) => $row[$column], $primary));
                $actual = $query->fetch(PDO::FETCH_ASSOC);
                foreach ($original as $column => $value) {
                    if (!is_array($actual) || !array_key_exists($column, $actual)
                        || (($value === null) !== ($actual[$column] === null)) || (string) $value !== (string) $actual[$column]) {
                        throw new RuntimeException('Restored record differs from its original.');
                    }
                }
            }, function (array $attachment, string $bytes, int $offset) use ($uploads, &$created, &$originalNames): void {
                $name = $attachment['owner'] === 'knowledge-history'
                    ? ($attachment['reference']['stored_name'] ?? '') : $attachment['version'];
                $file = KnowledgeFileVersions::path($uploads, $name);
                $id = $attachment['attachment_id'];
                if ($offset === 0) {
                    if (isset($originalNames[$name]) && $originalNames[$name] !== $attachment['sha256']) { throw new RuntimeException('Two originals have conflicting storage names.'); }
                    $originalNames[$name] = $attachment['sha256'];
                    // Multiple references may intentionally share identical bytes.
                    if (is_file($file) && !isset($created[$file])) { throw new RuntimeException('Restore would overwrite an existing file.'); }
                    $created[$file] = true;
                    if (file_put_contents($file, '') === false) { throw new RuntimeException('Original restore failed.'); }
                    @chmod($file, 0600);
                }
                if (file_put_contents($file, $bytes, FILE_APPEND) !== strlen($bytes)) { throw new RuntimeException('Original restore failed.'); }
            });
            if (!hash_equals($verified['payload_sha256'], $restored['payload_sha256'])) { throw new RuntimeException('Archive changed during restore.'); }
            foreach ($originalNames as $name => $hash) {
                if (!hash_equals($hash, (string) hash_file('sha256', KnowledgeFileVersions::path($uploads, $name)))) { throw new RuntimeException('Restored original differs.'); }
            }
            if ($pdo->query('PRAGMA foreign_key_check')->fetch() !== false || $pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('Restored canonical references or integrity failed.');
            }
            $destination->verifyCanonicalSchema();
            $pdo->commit();
            return [...$restored, 'restore_tested' => true, 'credentials_restored' => false, 'retrieval_rebuild_required' => true];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            foreach (array_keys($created) as $file) { if (is_file($file)) { @unlink($file); } }
            throw $error;
        }
    }
}
