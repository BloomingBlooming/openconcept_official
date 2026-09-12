<?php

declare(strict_types=1);

final class StandardRagRetriever implements RagRetrieverInterface
{
    public const PROVIDER_ID = 'openconcept-rag-standard';

    public function __construct(
        private readonly StandardRagRepository $repository,
        private readonly BgeM3EmbeddingProvider $embeddings,
        private readonly DeterministicBlockChunker $chunker,
        private readonly DatabaseCapabilitiesInterface $databaseCapabilities
    ) {
    }

    public function capabilities(): RagCapabilities
    {
        return new RagCapabilities('1.0', [
            'document_upsert',
            'document_delete',
            'batch_upsert',
            'async_indexing',
            'metadata_filter',
            'permission_filter',
            'language_filter',
            'hybrid_search',
            'dense_search',
        ], [
            'max_batch_documents' => 100,
            'max_document_bytes' => 16777216,
        ]);
    }

    public function healthCheck(): HealthResult
    {
        if ($this->databaseCapabilities->driverName() !== 'pgsql') {
            return new HealthResult(false, 'postgresql_required');
        }
        if (!$this->databaseCapabilities->supportsExtension('vector')) {
            return new HealthResult(false, 'pgvector_missing');
        }
        $embeddingHealth = $this->embeddings->healthCheck();
        if (!$embeddingHealth->healthy) {
            return new HealthResult(false, $embeddingHealth->status, $embeddingHealth->details);
        }
        return new HealthResult(true, 'ready', [
            'pgvector_version' => $this->databaseCapabilities->extensionVersion('vector'),
            'pg_trgm_version' => $this->databaseCapabilities->extensionVersion('pg_trgm'),
            'embedding_dimensions' => $this->embeddings->capabilities()->dimensions,
        ]);
    }

    public function createIndex(IndexConfiguration $configuration): IndexGeneration
    {
        $health = $this->healthCheck();
        if (!$health->healthy) {
            throw new RuntimeException('OpenConcept Standard RAG is unavailable: ' . $health->status);
        }
        $embedding = $this->embeddings->capabilities();
        if ($embedding->dimensions === null) {
            $this->embeddings->embed(['OpenConcept Standard RAG dimension probe']);
            $embedding = $this->embeddings->capabilities();
        }
        if ($embedding->dimensions === null) {
            throw new RuntimeException('BGE-M3 embedding dimensions could not be determined.');
        }
        $resolved = new IndexConfiguration(
            self::PROVIDER_ID,
            '1.0.0',
            DeterministicBlockChunker::ID,
            DeterministicBlockChunker::VERSION,
            $embedding->providerId,
            $embedding->modelId,
            $embedding->modelRevision,
            $embedding->dimensions,
            $embedding->distanceMetric,
            $configuration->options
        );
        $generation = IndexGeneration::create($resolved);
        $this->repository->migrate();
        $this->repository->rebuildVectorIndex($generation->id, $embedding->dimensions, $embedding->distanceMetric);
        return $generation;
    }

    public function upsertDocument(DocumentSnapshot $document, IndexGeneration $generation): IndexResult
    {
        $chunks = $this->chunker->chunk($document);
        if ($chunks === []) {
            $this->repository->deleteDocument($document->documentId, $generation);
            return new IndexResult('skipped');
        }
        $vectors = [];
        foreach (array_chunk($chunks, $this->embeddings->batchSize()) as $batch) {
            $vectors = [...$vectors, ...$this->embeddings->embed(array_map(
                static fn (array $chunk): string => (string) $chunk['text'],
                $batch
            ))];
        }
        $this->repository->replaceDocument($document, $generation, $chunks, $vectors);
        return new IndexResult('completed', count($chunks));
    }

    public function deleteDocument(string $documentId, IndexGeneration $generation): void
    {
        $this->repository->deleteDocument($documentId, $generation);
    }

    public function search(RagSearchRequest $request, AccessScope $scope): EvidenceResponse
    {
        $queryVector = $this->embeddings->embed([$request->query])[0];
        $candidates = $this->repository->candidates(
            $request,
            $scope,
            $queryVector,
            $this->databaseCapabilities->supportsExtension('pg_trgm')
        );
        $rrfK = max(1, min(1000, (int) ($request->generation->configuration->options['rrf_k'] ?? 60)));
        $combined = ReciprocalRankFusion::combine($candidates, $rrfK);
        $items = [];
        foreach (array_slice($combined, 0, $request->limit, true) as $candidate) {
            $row = $candidate['row'];
            $blockIds = json_decode((string) $row['block_ids_json'], true);
            $tags = json_decode((string) $row['tags_json'], true);
            $items[] = new Evidence(
                (string) $row['document_id'],
                (string) $row['revision_id'],
                is_array($blockIds) ? array_values(array_filter($blockIds, 'is_string')) : [],
                (string) $row['chunk_text'],
                (float) $candidate['rrf'],
                (string) $candidate['methods'][0],
                [
                    'title' => (string) $row['title'],
                    'language' => (string) $row['language'],
                    'tags' => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
                    'retrieval_methods' => array_values($candidate['methods']),
                    'normalized_score' => null,
                    'source_url' => '/pages/' . rawurlencode((string) $row['document_id']),
                    'content_hash' => (string) $row['content_hash'],
                    'updated_at' => $this->rfc3339((string) $row['source_updated_at']),
                    'extensions' => ['method_scores' => $candidate['raw'], 'rrf_k' => $rrfK],
                ],
                (string) $row['chunk_id']
            );
        }
        return new EvidenceResponse(
            $request->resolvedQueryId(),
            $request->query,
            self::PROVIDER_ID,
            '1.0.0',
            $request->generation->id,
            $items,
            count($combined) > $request->limit,
            $this->databaseCapabilities->supportsExtension('pg_trgm') ? [] : ['pg_trgm_unavailable_fallback_used']
        );
    }

    private function rfc3339(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(DATE_ATOM);
        } catch (Throwable) {
            throw new RuntimeException('Standard RAG stored an invalid source timestamp.');
        }
    }
}
