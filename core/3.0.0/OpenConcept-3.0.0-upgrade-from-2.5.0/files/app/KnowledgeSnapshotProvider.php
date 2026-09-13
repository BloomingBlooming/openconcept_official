<?php

declare(strict_types=1);

require_once __DIR__ . '/KnowledgeHistoryService.php';

/** Originals retained outside the page tables, including disabled voice plugins. */
final class KnowledgeSnapshotProvider implements CanonicalSnapshotProviderInterface
{
    public function __construct(private readonly PDO $pdo, private readonly string $uploads) {}
    public function id(): string { return 'knowledge-history'; }
    public function describe(): array
    {
        $envelope = ['columns' => [['name' => 'id'], ['name' => 'payload_json']], 'primary_key' => ['id']];
        return ['version' => '1', 'consistency' => 'application_writer_barrier_v1', 'classification' => 'canonical_originals',
            'default_included' => true, 'includes_original_bytes' => true,
            'schema' => ['extension_records' => CoreSchemaCatalog::load()->table((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'extension_records'),
                'voice_conversations' => $envelope, 'voice_messages' => $envelope]];
    }

    public function records(array $context): iterable
    {
        $this->assertContext($context);
        $sql = "SELECT * FROM extension_records WHERE (scope = 'core:knowledge' AND collection IN ('messages', 'imports', 'relations', 'turns')) OR (scope = 'core:sources' AND collection = 'file-versions') ORDER BY scope, collection, record_key";
        foreach ($this->read($sql) as $row) { yield ['type' => 'extension_records', 'data' => $row]; }
        $tables = KnowledgeHistoryService::voiceTables($this->pdo);
        if ($tables !== null) {
            foreach (['conversations' => 'voice_conversations', 'messages' => 'voice_messages'] as $table => $type) {
                foreach ($this->read('SELECT * FROM ' . $tables[$table] . ' ORDER BY id') as $row) {
                    yield ['type' => $type, 'data' => ['id' => $row['id'], 'payload_json' => KnowledgeRecords::canonicalJson($row)]];
                }
            }
        }
    }

    public function attachments(array $context): iterable
    {
        $this->assertContext($context);
        foreach ($this->read("SELECT record_key, payload_json FROM extension_records WHERE scope = 'core:sources' AND collection = 'file-versions' ORDER BY record_key") as $row) {
            $value = json_decode($row['payload_json'], true, 64, JSON_THROW_ON_ERROR);
            $file = $value['file']; $source = $value['source'];
            yield ['id' => $row['record_key'], 'version' => $source['source_version'], 'name' => $file['original_name'],
                'mime_type' => $file['mime_type'], 'size_bytes' => (int) $file['size_bytes'], 'sha256' => $source['content_hash'],
                'reference' => ['file_id' => (int) $file['id'], 'stored_name' => $file['stored_name'], 'file_version_key' => $row['record_key']]];
        }
    }

    public function openAttachment(string $id, array $context): mixed
    {
        $this->assertContext($context);
        $record = (new PluginApiRepository($this->pdo))->get('core:sources', 'file-versions', $id);
        if ($record === null) { throw new CanonicalSnapshotException('historical_original_missing'); }
        $path = KnowledgeFileVersions::path($this->uploads, (string) $record['value']['file']['stored_name']);
        $handle = @fopen($path, 'rb');
        if ($handle === false) { throw new CanonicalSnapshotException('historical_original_missing'); }
        return $handle;
    }

    private function read(string $sql): iterable
    {
        for ($offset = 0; ; $offset += 250) {
            $query = $this->pdo->query($sql . ' LIMIT 250 OFFSET ' . $offset);
            $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            yield from $rows;
            if (count($rows) < 250) { break; }
        }
    }
    private function assertContext(array $context): void
    {
        if (($context['consistency'] ?? '') !== 'application_writer_barrier_v1') {
            throw new CanonicalSnapshotException('provider_snapshot_context_invalid');
        }
    }
}
