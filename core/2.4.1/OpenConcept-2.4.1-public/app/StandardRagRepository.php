<?php

declare(strict_types=1);

final class StandardRagRepository
{
    private string $chunks;
    private string $embeddings;

    public function __construct(private readonly PDO $pdo)
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        $this->chunks = $prefix . 'plugin_rag_standard_chunks';
        $this->embeddings = $prefix . 'plugin_rag_pgvector_embeddings';
        foreach ([$this->chunks, $this->embeddings] as $table) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $table) !== 1) {
                throw new RuntimeException('Standard RAG table prefix is invalid.');
            }
        }
    }

    public function migrate(): void
    {
        $this->assertPostgreSql();
        $chunks = $this->q($this->chunks);
        $embeddings = $this->q($this->embeddings);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$chunks} (
    index_generation_id VARCHAR(68) NOT NULL,
    chunk_id VARCHAR(64) NOT NULL,
    document_id VARCHAR(190) NOT NULL,
    revision_id VARCHAR(190) NOT NULL,
    workspace_id VARCHAR(190) NOT NULL,
    block_ids_json TEXT NOT NULL,
    title TEXT NOT NULL,
    chunk_text TEXT NOT NULL,
    language VARCHAR(35) NOT NULL,
    tags_json TEXT NOT NULL,
    search_text TEXT NOT NULL,
    visibility VARCHAR(32) NOT NULL,
    allowed_principals TEXT NOT NULL,
    content_hash VARCHAR(80) NOT NULL,
    source_updated_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (index_generation_id, chunk_id)
);
CREATE TABLE IF NOT EXISTS {$embeddings} (
    index_generation_id VARCHAR(68) NOT NULL,
    chunk_id VARCHAR(64) NOT NULL,
    embedding_provider_id VARCHAR(128) NOT NULL,
    embedding_model_id VARCHAR(190) NOT NULL,
    embedding_model_revision VARCHAR(190) NULL,
    embedding_dimensions INTEGER NOT NULL,
    distance_metric VARCHAR(32) NOT NULL,
    embedding vector NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (index_generation_id, chunk_id)
);
CREATE INDEX IF NOT EXISTS {$this->q($this->indexName('chunks_document'))}
    ON {$chunks} (index_generation_id, document_id);
CREATE INDEX IF NOT EXISTS {$this->q($this->indexName('chunks_workspace'))}
    ON {$chunks} (index_generation_id, workspace_id);
SQL);
    }

    public function rebuildVectorIndex(string $generationId, int $dimensions, string $distanceMetric): void
    {
        $this->assertGeneration($generationId);
        if ($dimensions < 1 || $dimensions > 2000) {
            throw new RuntimeException('pgvector HNSW supports at most 2000 Dense dimensions in this configuration.');
        }
        $operatorClass = match ($distanceMetric) {
            'cosine' => 'vector_cosine_ops',
            'l2' => 'vector_l2_ops',
            'inner_product' => 'vector_ip_ops',
            default => throw new InvalidArgumentException('Unsupported pgvector distance metric.'),
        };
        $index = $this->q($this->indexName('hnsw_' . substr(hash('sha256', $generationId), 0, 12)));
        $table = $this->q($this->embeddings);
        $generation = $this->pdo->quote($generationId);
        $this->pdo->exec("DROP INDEX IF EXISTS {$index}");
        $this->pdo->exec(
            "CREATE INDEX {$index} ON {$table} USING hnsw ((embedding::vector({$dimensions})) {$operatorClass}) "
            . "WHERE index_generation_id = {$generation}"
        );
    }

    /** @param list<array<string, mixed>> $chunks @param list<list<float>> $vectors */
    public function replaceDocument(
        DocumentSnapshot $document,
        IndexGeneration $generation,
        array $chunks,
        array $vectors
    ): void {
        if (count($chunks) !== count($vectors)) {
            throw new InvalidArgumentException('Standard RAG chunk and embedding counts differ.');
        }
        $configuration = $generation->configuration;
        $dimensions = $configuration->embeddingDimensions;
        if ($dimensions === null) {
            throw new InvalidArgumentException('Standard RAG generation dimensions are required.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->deleteDocument($document->documentId, $generation, false);
            $chunkTable = $this->q($this->chunks);
            $embeddingTable = $this->q($this->embeddings);
            $insertChunk = $this->pdo->prepare(<<<SQL
INSERT INTO {$chunkTable}
    (index_generation_id, chunk_id, document_id, revision_id, workspace_id, block_ids_json,
     title, chunk_text, language, tags_json, search_text, visibility, allowed_principals,
     content_hash, source_updated_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $insertEmbedding = $this->pdo->prepare(<<<SQL
INSERT INTO {$embeddingTable}
    (index_generation_id, chunk_id, embedding_provider_id, embedding_model_id,
     embedding_model_revision, embedding_dimensions, distance_metric, embedding)
VALUES (?, ?, ?, ?, ?, ?, ?, CAST(? AS vector))
SQL);
            $permissions = $document->permissions;
            $principals = is_array($permissions['allowed_principals'] ?? null)
                ? array_values(array_filter($permissions['allowed_principals'], 'is_string'))
                : [];
            $principalText = '|' . implode('|', array_map(
                static fn (string $value): string => str_replace('|', '', $value),
                $principals
            )) . '|';
            foreach ($chunks as $index => $chunk) {
                $vector = $vectors[$index];
                if (count($vector) !== $dimensions) {
                    throw new RuntimeException('Standard RAG embedding dimensions do not match the generation.');
                }
                $insertChunk->execute([
                    $generation->id,
                    (string) $chunk['chunk_id'],
                    $document->documentId,
                    $document->revisionId,
                    $document->workspaceId,
                    json_encode($chunk['block_ids'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $document->title,
                    (string) $chunk['text'],
                    $document->language,
                    json_encode($document->tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    trim($document->title . "\n" . implode(' ', $document->tags) . "\n" . (string) $chunk['text']),
                    (string) ($permissions['visibility'] ?? 'restricted'),
                    $principalText,
                    'sha256:' . $document->contentHash,
                    $document->updatedAt->format('Y-m-d H:i:s'),
                ]);
                $insertEmbedding->execute([
                    $generation->id,
                    (string) $chunk['chunk_id'],
                    (string) $configuration->embeddingProviderId,
                    (string) $configuration->embeddingModelId,
                    $configuration->embeddingModelRevision,
                    $dimensions,
                    $configuration->distanceMetric,
                    $this->vectorText($vector),
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function deleteDocument(string $documentId, IndexGeneration $generation, bool $transaction = true): void
    {
        if ($transaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $embeddingTable = $this->q($this->embeddings);
            $chunkTable = $this->q($this->chunks);
            $deleteEmbedding = $this->pdo->prepare(<<<SQL
DELETE FROM {$embeddingTable}
WHERE index_generation_id = ? AND chunk_id IN (
    SELECT chunk_id FROM {$chunkTable} WHERE index_generation_id = ? AND document_id = ?
)
SQL);
            $deleteEmbedding->execute([$generation->id, $generation->id, $documentId]);
            $deleteChunks = $this->pdo->prepare(
                "DELETE FROM {$chunkTable} WHERE index_generation_id = ? AND document_id = ?"
            );
            $deleteChunks->execute([$generation->id, $documentId]);
            if ($transaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($transaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{dense: list<array<string, mixed>>, lexical: list<array<string, mixed>>} */
    public function candidates(
        RagSearchRequest $request,
        AccessScope $scope,
        array $queryVector,
        bool $trigramAvailable
    ): array {
        $configuration = $request->generation->configuration;
        $dimensions = $configuration->embeddingDimensions;
        if ($dimensions === null || count($queryVector) !== $dimensions) {
            throw new RuntimeException('Standard RAG query embedding dimensions are invalid.');
        }
        [$permissionSql, $permissionParameters] = $this->permissionFilter($scope);
        $chunks = $this->q($this->chunks);
        $embeddings = $this->q($this->embeddings);
        $operator = match ($configuration->distanceMetric) {
            'cosine' => '<=>',
            'l2' => '<->',
            'inner_product' => '<#>',
            default => throw new InvalidArgumentException('Unsupported pgvector distance metric.'),
        };
        $rawScore = match ($configuration->distanceMetric) {
            'cosine' => "1 - (e.embedding::vector({$dimensions}) <=> CAST(? AS vector))",
            'l2' => "-(e.embedding::vector({$dimensions}) <-> CAST(? AS vector))",
            'inner_product' => "-(e.embedding::vector({$dimensions}) <#> CAST(? AS vector))",
        };
        $candidateLimit = min(500, max($request->limit * 4, 20));
        $dense = $this->pdo->prepare(<<<SQL
SELECT c.*, {$rawScore} AS raw_score
FROM {$chunks} c
JOIN {$embeddings} e
  ON e.index_generation_id = c.index_generation_id AND e.chunk_id = c.chunk_id
WHERE c.index_generation_id = ? AND c.workspace_id = ? {$permissionSql}
ORDER BY e.embedding::vector({$dimensions}) {$operator} CAST(? AS vector), c.chunk_id
LIMIT {$candidateLimit}
SQL);
        $vector = $this->vectorText($queryVector);
        $dense->execute([$vector, $request->generation->id, $scope->workspaceId, ...$permissionParameters, $vector]);
        if ($trigramAvailable) {
            $lexicalScore = 'similarity(c.search_text, ?)';
            $lexicalWhere = 'AND similarity(c.search_text, ?) > 0';
            $lexicalParameters = [$request->query, $request->generation->id, $scope->workspaceId, ...$permissionParameters, $request->query];
        } else {
            $lexicalScore = 'CASE WHEN LOWER(c.search_text) LIKE LOWER(?) THEN 1.0 ELSE 0.0 END';
            $lexicalWhere = 'AND LOWER(c.search_text) LIKE LOWER(?)';
            $pattern = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->query) . '%';
            $lexicalParameters = [$pattern, $request->generation->id, $scope->workspaceId, ...$permissionParameters, $pattern];
        }
        $lexical = $this->pdo->prepare(<<<SQL
SELECT c.*, {$lexicalScore} AS raw_score
FROM {$chunks} c
WHERE c.index_generation_id = ? AND c.workspace_id = ? {$permissionSql} {$lexicalWhere}
ORDER BY raw_score DESC, c.chunk_id
LIMIT {$candidateLimit}
SQL);
        $lexical->execute($lexicalParameters);
        return ['dense' => $dense->fetchAll(), 'lexical' => $lexical->fetchAll()];
    }

    /** @return array{0: string, 1: list<string>} */
    private function permissionFilter(AccessScope $scope): array
    {
        $role = (string) ($scope->attributes['role'] ?? '');
        if (in_array($role, ['admin', 'content_manager'], true)) {
            return ['', []];
        }
        $conditions = ["c.visibility IN ('public', 'company')", "c.allowed_principals LIKE ? ESCAPE '!'"];
        $parameters = ['%|user:' . $scope->userId . '|%'];
        $department = trim((string) ($scope->attributes['department'] ?? ''));
        if ($department !== '') {
            $conditions[] = "c.allowed_principals LIKE ? ESCAPE '!'";
            $safeDepartment = str_replace(
                ['!', '%', '_', '|'],
                ['!!', '!%', '!_', ''],
                $department
            );
            $parameters[] = '%|group:' . $safeDepartment . '|%';
        }
        return [' AND (' . implode(' OR ', $conditions) . ')', $parameters];
    }

    /** @param list<float> $vector */
    private function vectorText(array $vector): string
    {
        return '[' . implode(',', array_map(static fn (float $value): string => sprintf('%.12F', $value), $vector)) . ']';
    }

    private function assertPostgreSql(): void
    {
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('OpenConcept Standard RAG requires PostgreSQL.');
        }
    }

    private function assertGeneration(string $generationId): void
    {
        if (preg_match('/^gen_[a-z0-9]{12,64}$/D', $generationId) !== 1) {
            throw new InvalidArgumentException('Standard RAG generation ID is invalid.');
        }
    }

    private function q(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Standard RAG database identifier is invalid.');
        }
        return '"' . $identifier . '"';
    }

    private function indexName(string $purpose): string
    {
        return substr($this->chunks . '_' . preg_replace('/[^a-z0-9_]/', '_', $purpose), 0, 50)
            . '_' . substr(hash('sha256', $this->chunks . ':' . $purpose), 0, 10);
    }
}
