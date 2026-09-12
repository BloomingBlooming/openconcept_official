<?php

declare(strict_types=1);

require_once __DIR__ . '/CanonicalReadSnapshotService.php';

/**
 * Explicit optional supplement, NOT a canonical original owner. It preserves
 * accepted OCR/LLM text with its recorded source version, evidence metadata,
 * and approval provenance. Raw files remain owned by Core's files scope.
 * Pending candidates, plugin secrets, jobs and RAG indexes are never selected.
 */
final class AcceptedContentSnapshotProvider implements CanonicalSnapshotProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function id(): string
    {
        return 'extracted-content';
    }

    public function describe(): array
    {
        $catalog = CoreSchemaCatalog::load();
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return [
            'version' => '1', 'consistency' => 'application_writer_barrier_v1',
            'classification' => 'accepted_extraction_supplement', 'default_included' => false,
            'includes_original_bytes' => false, 'original_bytes_scope' => 'core.files',
            'scope' => ['source_type' => 'file', 'state' => 'accepted'],
            'schema' => [
                'accepted_content' => $catalog->table($driver, 'extension_search_content'),
                'source_files' => $catalog->table($driver, 'files'),
            ],
        ];
    }

    public function records(array $context): iterable
    {
        if (($context['consistency'] ?? '') !== 'application_writer_barrier_v1') {
            throw new CanonicalSnapshotException('provider_snapshot_context_invalid');
        }
        $description = $this->describe();
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = $driver === 'mysql' ? '`' : '"';
        foreach (['accepted_content', 'source_files'] as $type) {
            $alias = $type === 'accepted_content' ? 'e' : 'f';
            $columns = implode(', ', array_map(static fn (array $column): string => $alias . '.' . $quote . $column['name'] . $quote, $description['schema'][$type]['columns']));
            if ($type === 'accepted_content') {
                $sql = 'SELECT ' . $columns . " FROM extension_search_content e WHERE e.source_type = 'file' AND e.state = 'accepted'";
            } else {
                $cast = $driver === 'mysql' ? 'CHAR' : 'TEXT';
                $sql = 'SELECT DISTINCT ' . $columns . ' FROM files f JOIN extension_search_content e ON e.source_id = CAST(f.id AS ' . $cast . ") WHERE e.source_type = 'file' AND e.state = 'accepted'";
            }
            for ($offset = 0; ; $offset += 250) {
                $statement = $this->pdo->query($sql . ' ORDER BY ' . $alias . '.id LIMIT 250 OFFSET ' . $offset);
                $count = 0;
                while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                    $count++;
                    yield ['type' => $type, 'data' => $row];
                }
                $statement->closeCursor();
                if ($count < 250) {
                    break;
                }
            }
        }
    }

    public function attachments(array $context): iterable
    {
        return [];
    }

    public function openAttachment(string $id, array $context): mixed
    {
        throw new CanonicalSnapshotException('supplement_has_no_original_bytes', 404);
    }
}
