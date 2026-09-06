<?php

declare(strict_types=1);

final class DocumentBlock
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $blockId,
        public readonly string $type,
        public readonly string $text,
        public readonly int $position,
        public readonly array $metadata = []
    ) {
        if ($blockId === '' || strlen($blockId) > 190 || preg_match('/[\x00-\x1F]/', $blockId) === 1) {
            throw new InvalidArgumentException('RAG block ID is invalid.');
        }
        if ($type === '' || strlen($type) > 80 || $position < 0) {
            throw new InvalidArgumentException('RAG block metadata is invalid.');
        }
    }
}

final class DocumentSnapshot
{
    /**
     * @param list<string> $tags
     * @param list<DocumentBlock> $blocks
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $revisionId,
        public readonly string $workspaceId,
        public readonly string $title,
        public readonly string $language,
        public readonly array $tags,
        public readonly array $blocks,
        public readonly string $contentHash,
        public readonly string $aclVersion,
        public readonly DateTimeImmutable $updatedAt,
        public readonly array $permissions = []
    ) {
        if ($documentId === '' || $revisionId === '' || $workspaceId === '') {
            throw new InvalidArgumentException('RAG document identity is incomplete.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1) {
            throw new InvalidArgumentException('RAG content hash is invalid.');
        }
        foreach ($blocks as $block) {
            if (!$block instanceof DocumentBlock) {
                throw new InvalidArgumentException('RAG document blocks must use DocumentBlock.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'document_id' => $this->documentId,
            'revision_id' => $this->revisionId,
            'workspace_id' => $this->workspaceId,
            'title' => $this->title,
            'language' => $this->language,
            'tags' => $this->tags,
            'blocks' => array_map(static fn (DocumentBlock $block): array => [
                'block_id' => $block->blockId,
                'type' => $block->type,
                'position' => $block->position,
                'text' => $block->text,
                'metadata' => $block->metadata,
            ], $this->blocks),
            'permissions' => $this->permissions,
            'acl_version' => $this->aclVersion,
            'content_hash' => 'sha256:' . $this->contentHash,
            'updated_at' => $this->updatedAt->format(DATE_ATOM),
        ];
    }
}

final class AccessScope
{
    /** @param array<string, scalar|null> $attributes */
    public function __construct(
        public readonly int $userId,
        public readonly string $workspaceId,
        public readonly array $attributes = []
    ) {
        if ($userId < 1 || $workspaceId === '') {
            throw new InvalidArgumentException('RAG access scope is invalid.');
        }
    }
}

final class IndexContext
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public readonly string $engineInstanceId,
        public readonly ?string $indexGenerationId = null,
        public readonly array $configuration = []
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $engineInstanceId) !== 1) {
            throw new InvalidArgumentException('RAG engine instance ID is invalid.');
        }
    }
}

final class IndexResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $status,
        public readonly int $indexedItems = 0,
        public readonly array $metadata = []
    ) {
        if (!in_array($status, ['completed', 'skipped', 'failed'], true) || $indexedItems < 0) {
            throw new InvalidArgumentException('RAG index result is invalid.');
        }
    }
}

final class RetrievalQuery
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly string $text,
        public readonly int $limit = 10,
        public readonly array $filters = []
    ) {
        if (trim($text) === '' || self::textLength($text) > 4000 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('RAG retrieval query is invalid.');
        }
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

final class Evidence
{
    /**
     * @param list<string> $blockIds
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $documentId,
        public readonly string $revisionId,
        public readonly array $blockIds,
        public readonly string $excerpt,
        public readonly float $score,
        public readonly string $retrievalMethod,
        public readonly array $metadata = [],
        public readonly string $chunkId = ''
    ) {
        if ($documentId === '' || $revisionId === '' || $blockIds === []
            || $retrievalMethod === '' || strlen($retrievalMethod) > 80 || count($blockIds) > 100
            || (function_exists('mb_strlen') ? mb_strlen($excerpt, 'UTF-8') : strlen($excerpt)) > 20000) {
            throw new InvalidArgumentException('RAG evidence is incomplete.');
        }
        foreach ($blockIds as $blockId) {
            if (!is_string($blockId) || $blockId === '' || strlen($blockId) > 190
                || preg_match('/[\x00-\x1F]/', $blockId) === 1) {
                throw new InvalidArgumentException('RAG evidence contains an invalid block ID.');
            }
        }
        if (!is_finite($score)) {
            throw new InvalidArgumentException('RAG evidence score is invalid.');
        }
    }

    public function stableChunkId(): string
    {
        if ($this->chunkId !== '') {
            return $this->chunkId;
        }
        return 'chunk_' . substr(hash('sha256', implode('|', [
            $this->documentId,
            $this->revisionId,
            ...$this->blockIds,
        ])), 0, 24);
    }
}

final class HealthResult
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly bool $healthy,
        public readonly string $status,
        public readonly array $details = []
    ) {
    }
}

interface RetrieverProviderInterface
{
    public function index(DocumentSnapshot $document, IndexContext $context): IndexResult;

    public function delete(string $documentId, IndexContext $context): void;

    /** @return list<Evidence> */
    public function retrieve(RetrievalQuery $query, AccessScope $scope): array;

    public function healthCheck(): HealthResult;
}

final class RagContext
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        public readonly string $engineInstanceId,
        public readonly ?string $indexGenerationId = null,
        public readonly array $configuration = []
    ) {
    }
}

final class SyncResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $status,
        public readonly array $metadata = []
    ) {
        if (!in_array($status, ['completed', 'skipped', 'failed'], true)) {
            throw new InvalidArgumentException('RAG synchronization result is invalid.');
        }
    }
}

final class RagRequest
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $question,
        public readonly array $options = []
    ) {
        if (trim($question) === '' || (function_exists('mb_strlen') ? mb_strlen($question, 'UTF-8') : strlen($question)) > 4000) {
            throw new InvalidArgumentException('RAG request is invalid.');
        }
    }
}

final class RagResponse
{
    /**
     * @param list<Evidence> $evidence
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $evidence,
        public readonly string $status = 'completed',
        public readonly array $metadata = []
    ) {
        foreach ($evidence as $item) {
            if (!$item instanceof Evidence) {
                throw new InvalidArgumentException('RAG response evidence must use Evidence.');
            }
        }
    }
}

final class RagCapabilities
{
    /** @param list<string> $features @param array<string, int|float|string|bool|null> $limits */
    public function __construct(
        public readonly string $apiVersion,
        public readonly array $features = [],
        public readonly array $limits = []
    ) {
        if (preg_match('/^1(?:\.\d+)?$/D', $apiVersion) !== 1) {
            throw new InvalidArgumentException('Unsupported RAG API version declaration.');
        }
    }

    public function supports(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}

interface RagEngineInterface
{
    public function synchronize(DocumentSnapshot $document, RagContext $context): SyncResult;

    public function delete(string $documentId, RagContext $context): void;

    public function answer(RagRequest $request, AccessScope $scope): RagResponse;

    public function healthCheck(): HealthResult;

    public function capabilities(): RagCapabilities;
}

interface RagDocumentGatewayInterface
{
    public function workspaceId(): string;

    public function snapshot(string $documentId): ?DocumentSnapshot;

    /** @return iterable<DocumentSnapshot> */
    public function snapshots(): iterable;

    /**
     * Re-checks the live user, page hierarchy, ACL, revision, and cited blocks.
     *
     * @param list<Evidence> $evidence
     * @return list<Evidence>
     */
    public function verifyEvidence(array $evidence, AccessScope $scope): array;
}

final class IndexConfiguration
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $enginePluginId,
        public readonly string $enginePluginVersion,
        public readonly string $chunkerId,
        public readonly string $chunkerVersion,
        public readonly ?string $embeddingProviderId = null,
        public readonly ?string $embeddingModelId = null,
        public readonly ?string $embeddingModelRevision = null,
        public readonly ?int $embeddingDimensions = null,
        public readonly string $distanceMetric = 'cosine',
        public readonly array $options = []
    ) {
        foreach ([$enginePluginId, $chunkerId] as $id) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,127}$/D', $id) !== 1) {
                throw new InvalidArgumentException('RAG index configuration contains an invalid ID.');
            }
        }
        if ($enginePluginVersion === '' || $chunkerVersion === '') {
            throw new InvalidArgumentException('RAG index configuration versions are required.');
        }
        if ($embeddingDimensions !== null && ($embeddingDimensions < 1 || $embeddingDimensions > 65535)) {
            throw new InvalidArgumentException('RAG embedding dimensions are invalid.');
        }
        if (!in_array($distanceMetric, ['cosine', 'l2', 'inner_product'], true)) {
            throw new InvalidArgumentException('RAG distance metric is unsupported.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'engine_plugin_id' => $this->enginePluginId,
            'engine_plugin_version' => $this->enginePluginVersion,
            'chunker_id' => $this->chunkerId,
            'chunker_version' => $this->chunkerVersion,
            'embedding_provider_id' => $this->embeddingProviderId,
            'embedding_model_id' => $this->embeddingModelId,
            'embedding_model_revision' => $this->embeddingModelRevision,
            'embedding_dimensions' => $this->embeddingDimensions,
            'distance_metric' => $this->distanceMetric,
            'options' => $this->options,
        ];
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            (string) ($value['engine_plugin_id'] ?? ''),
            (string) ($value['engine_plugin_version'] ?? ''),
            (string) ($value['chunker_id'] ?? 'external'),
            (string) ($value['chunker_version'] ?? '1.0'),
            self::nullableString($value['embedding_provider_id'] ?? null),
            self::nullableString($value['embedding_model_id'] ?? null),
            self::nullableString($value['embedding_model_revision'] ?? null),
            isset($value['embedding_dimensions']) ? (int) $value['embedding_dimensions'] : null,
            (string) ($value['distance_metric'] ?? 'cosine'),
            is_array($value['options'] ?? null) ? $value['options'] : []
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}

final class IndexGeneration
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly IndexConfiguration $configuration,
        public readonly string $status = 'building',
        public readonly array $metadata = []
    ) {
        if (preg_match('/^gen_[a-z0-9]{12,64}$/D', $id) !== 1) {
            throw new InvalidArgumentException('RAG index generation ID is invalid.');
        }
        if (!in_array($status, ['building', 'ready', 'active', 'failed', 'retired'], true)) {
            throw new InvalidArgumentException('RAG index generation status is invalid.');
        }
    }

    public static function create(IndexConfiguration $configuration, array $metadata = []): self
    {
        return new self('gen_' . bin2hex(random_bytes(12)), $configuration, 'building', $metadata);
    }
}

final class RagSearchRequest
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly string $query,
        public readonly IndexGeneration $generation,
        public readonly int $limit = 10,
        public readonly array $filters = [],
        public readonly ?string $queryId = null
    ) {
        if (trim($query) === '' || (function_exists('mb_strlen') ? mb_strlen($query, 'UTF-8') : strlen($query)) > 4000
            || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('RAG search request is invalid.');
        }
    }

    public function resolvedQueryId(): string
    {
        if ($this->queryId !== null && preg_match('/^qry_[a-zA-Z0-9_-]{8,80}$/D', $this->queryId) === 1) {
            return $this->queryId;
        }
        return 'qry_' . bin2hex(random_bytes(12));
    }
}

final class EvidenceResponse
{
    /**
     * @param list<Evidence> $evidence
     * @param list<string> $warnings
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public readonly string $queryId,
        public readonly string $query,
        public readonly string $enginePluginId,
        public readonly string $enginePluginVersion,
        public readonly string $indexGeneration,
        public readonly array $evidence,
        public readonly bool $truncated = false,
        public readonly array $warnings = [],
        public readonly array $extensions = []
    ) {
        if (preg_match('/^qry_[a-zA-Z0-9_-]{8,80}$/D', $queryId) !== 1 || trim($query) === '') {
            throw new InvalidArgumentException('RAG evidence response identity is invalid.');
        }
        foreach ($evidence as $item) {
            if (!$item instanceof Evidence) {
                throw new InvalidArgumentException('RAG evidence response contains an invalid item.');
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $items = [];
        foreach ($this->evidence as $offset => $item) {
            $metadata = $item->metadata;
            $items[] = [
                'rank' => $offset + 1,
                'document_id' => $item->documentId,
                'revision_id' => $item->revisionId,
                'chunk_id' => $item->stableChunkId(),
                'block_ids' => $item->blockIds,
                'title' => (string) ($metadata['title'] ?? ''),
                'text' => $item->excerpt,
                'language' => (string) ($metadata['language'] ?? 'und'),
                'tags' => is_array($metadata['tags'] ?? null) ? array_values($metadata['tags']) : [],
                'retrieval_method' => is_array($metadata['retrieval_methods'] ?? null)
                    ? array_values($metadata['retrieval_methods'])
                    : [$item->retrievalMethod],
                'score' => [
                    'raw' => $item->score,
                    'normalized' => isset($metadata['normalized_score']) ? (float) $metadata['normalized_score'] : null,
                ],
                'source_url' => (string) ($metadata['source_url'] ?? ('/pages/' . rawurlencode($item->documentId))),
                'content_hash' => (string) ($metadata['content_hash'] ?? ''),
                'updated_at' => (string) ($metadata['updated_at'] ?? ''),
            ];
            if (is_array($metadata['extensions'] ?? null) && $metadata['extensions'] !== []) {
                $items[array_key_last($items)]['extensions'] = $metadata['extensions'];
            }
        }
        $resultStatus = $items === [] ? 'not_found' : 'found';
        return [
            'schema_version' => '1.0',
            'query_id' => $this->queryId,
            'query' => $this->query,
            'engine' => [
                'plugin_id' => $this->enginePluginId,
                'plugin_version' => $this->enginePluginVersion,
                'index_generation' => $this->indexGeneration,
            ],
            'result' => ['status' => $resultStatus, 'count' => count($items), 'truncated' => $this->truncated],
            'evidence' => $items,
            'warnings' => array_values($this->warnings),
            ...($this->extensions === [] ? [] : ['extensions' => $this->extensions]),
        ];
    }
}

interface RagRetrieverInterface
{
    public function capabilities(): RagCapabilities;

    public function healthCheck(): HealthResult;

    public function createIndex(IndexConfiguration $configuration): IndexGeneration;

    public function upsertDocument(DocumentSnapshot $document, IndexGeneration $generation): IndexResult;

    public function deleteDocument(string $documentId, IndexGeneration $generation): void;

    public function search(RagSearchRequest $request, AccessScope $scope): EvidenceResponse;
}

final class EmbeddingCapabilities
{
    public function __construct(
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly ?string $modelRevision,
        public readonly ?int $dimensions,
        public readonly string $distanceMetric = 'cosine'
    ) {
        if ($providerId === '' || $modelId === '' || ($dimensions !== null && $dimensions < 1)) {
            throw new InvalidArgumentException('Embedding capabilities are invalid.');
        }
    }
}

interface EmbeddingProviderInterface
{
    public function capabilities(): EmbeddingCapabilities;

    public function healthCheck(): HealthResult;

    /** @param list<string> $texts @return list<list<float>> */
    public function embed(array $texts): array;
}

final class ChatCapabilities
{
    /** @param array<string, bool|null> $features */
    public function __construct(
        public readonly array $features,
        public readonly ?int $maxContextTokens = null
    ) {
    }
}

final class ChatRequest
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        public readonly string $systemPrompt,
        public readonly string $question,
        public readonly EvidenceResponse $evidence,
        public readonly array $settings = []
    ) {
        if (trim($systemPrompt) === '' || trim($question) === '') {
            throw new InvalidArgumentException('Chat request prompts are required.');
        }
    }
}

final class ChatResponse
{
    /**
     * @param list<array{chunk_id: string, document_id: string}> $citations
     * @param array{input_tokens: int|null, output_tokens: int|null} $usage
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $citations,
        public readonly string $providerId,
        public readonly string $modelId,
        public readonly array $usage = ['input_tokens' => null, 'output_tokens' => null],
        public readonly string $finishReason = 'stop',
        public readonly array $warnings = []
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'citations' => array_values($this->citations),
            'model' => ['provider' => $this->providerId, 'model' => $this->modelId],
            'usage' => [
                'input_tokens' => isset($this->usage['input_tokens']) ? (int) $this->usage['input_tokens'] : null,
                'output_tokens' => isset($this->usage['output_tokens']) ? (int) $this->usage['output_tokens'] : null,
            ],
            'finish_reason' => $this->finishReason,
            'warnings' => array_values($this->warnings),
        ];
    }
}

interface ChatProviderInterface
{
    public function capabilities(): ChatCapabilities;

    public function healthCheck(): HealthResult;

    public function generate(ChatRequest $request): ChatResponse;

    /** @return iterable<string> */
    public function stream(ChatRequest $request): iterable;
}
