<?php

declare(strict_types=1);

/**
 * Built-in connector that maps the product-neutral OpenConcept RAG API v1 to
 * the internal retriever contract without granting the remote service access
 * to canonical tables.
 */
final class CustomRagHttpConnector implements RagRetrieverInterface
{
    public const PROVIDER_ID = 'openconcept-rag-custom';

    /** @var array<string, mixed> */
    private array $configuration;
    private SafeOutboundHttpClient $http;

    /** @param array<string, mixed> $configuration */
    public function __construct(array $configuration, ?SafeOutboundHttpClient $http = null)
    {
        $baseUrl = rtrim(trim((string) ($configuration['base_url'] ?? '')), '/');
        if ($baseUrl === '') {
            throw new InvalidArgumentException('Custom RAG Base URL is required.');
        }
        $authMode = (string) ($configuration['auth_mode'] ?? 'bearer');
        if (!in_array($authMode, ['bearer', 'api_key', 'none'], true)) {
            throw new InvalidArgumentException('Custom RAG authentication mode is invalid.');
        }
        $secretEnv = trim((string) ($configuration['secret_env'] ?? ''));
        if ($authMode !== 'none' && preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $secretEnv) !== 1) {
            throw new InvalidArgumentException('Custom RAG authentication must reference a server environment variable.');
        }
        $allowPrivate = (bool) ($configuration['allow_private_network'] ?? false);
        $verifySsl = !array_key_exists('verify_ssl', $configuration) || (bool) $configuration['verify_ssl'];
        $allowHttp = (bool) ($configuration['allow_http'] ?? false);
        if ($authMode === 'none' && !$allowPrivate) {
            throw new InvalidArgumentException('Unauthenticated Custom RAG is limited to explicitly allowed private environments.');
        }
        if ($authMode === 'none' && !$this->isLocalTarget($baseUrl)) {
            throw new InvalidArgumentException('Unauthenticated Custom RAG must use localhost or a literal private-network address.');
        }
        $configuration['base_url'] = $baseUrl;
        $configuration['auth_mode'] = $authMode;
        $configuration['secret_env'] = $secretEnv;
        $configuration['connect_timeout'] = max(1, min(120, (int) ($configuration['connect_timeout'] ?? 10)));
        $configuration['search_timeout'] = max(1, min(600, (int) ($configuration['search_timeout'] ?? 30)));
        $configuration['verify_ssl'] = $verifySsl;
        $configuration['allow_private_network'] = $allowPrivate;
        $configuration['allow_http'] = $allowHttp;
        $this->configuration = $configuration;
        $this->http = $http ?? new SafeOutboundHttpClient($allowPrivate, $verifySsl, $allowHttp);
    }

    private function isLocalTarget(string $url): bool
    {
        $host = strtolower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    public function capabilities(): RagCapabilities
    {
        $payload = $this->jsonRequest('GET', '/v1/capabilities');
        if ((string) ($payload['api_version'] ?? '') !== '1.0') {
            throw new RuntimeException('Custom RAG does not support OpenConcept RAG API v1.');
        }
        $features = [];
        foreach ((array) ($payload['features'] ?? []) as $feature => $enabled) {
            if (is_string($feature) && $enabled === true) {
                $features[] = $feature;
            }
        }
        foreach (['document_upsert', 'document_delete', 'permission_filter'] as $required) {
            if (!in_array($required, $features, true)) {
                throw new RuntimeException('Custom RAG is missing a required capability: ' . $required);
            }
        }
        $limits = [];
        foreach ((array) ($payload['limits'] ?? []) as $name => $value) {
            if (is_string($name) && (is_scalar($value) || $value === null)) {
                $limits[$name] = $value;
            }
        }
        return new RagCapabilities('1.0', $features, $limits);
    }

    public function healthCheck(): HealthResult
    {
        try {
            $payload = $this->jsonRequest('GET', '/v1/health');
            $healthy = in_array((string) ($payload['status'] ?? ''), ['ok', 'ready', 'healthy'], true)
                || ($payload['healthy'] ?? null) === true;
            return new HealthResult($healthy, $healthy ? 'ready' : 'unhealthy', [
                'api_version' => (string) ($payload['api_version'] ?? ''),
            ]);
        } catch (Throwable) {
            return new HealthResult(false, 'unreachable');
        }
    }

    public function createIndex(IndexConfiguration $configuration): IndexGeneration
    {
        $this->assertUsable();
        $request = ['configuration' => $configuration->toArray()];
        // Every rebuild must receive a distinct remote generation. The
        // administrator's index/namespace remains inside configuration
        // options for the remote engine to scope, not reuse, the generation.
        $operationId = bin2hex(random_bytes(12));
        $payload = $this->awaitAsyncResult($this->jsonRequest(
            'POST',
            '/v1/indexes',
            $request,
            'index-' . $operationId
        ));
        $remoteIndexId = trim((string) ($payload['index_id'] ?? $payload['id'] ?? ''));
        if ($remoteIndexId === '' || strlen($remoteIndexId) > 190 || preg_match('/[\x00-\x1F]/', $remoteIndexId) === 1) {
            throw new RuntimeException('Custom RAG returned an invalid index ID.');
        }
        $options = $configuration->options;
        $options['remote_index_id'] = $remoteIndexId;
        $resolved = new IndexConfiguration(
            $configuration->enginePluginId,
            $configuration->enginePluginVersion,
            $configuration->chunkerId,
            $configuration->chunkerVersion,
            $configuration->embeddingProviderId,
            $configuration->embeddingModelId,
            $configuration->embeddingModelRevision,
            $configuration->embeddingDimensions,
            $configuration->distanceMetric,
            $options
        );
        return IndexGeneration::create($resolved, ['remote_status' => (string) ($payload['status'] ?? 'building')]);
    }

    public function upsertDocument(DocumentSnapshot $document, IndexGeneration $generation): IndexResult
    {
        $index = $this->remoteIndexId($generation);
        $payload = $this->awaitAsyncResult($this->jsonRequest(
            'POST',
            '/v1/indexes/' . rawurlencode($index) . '/documents:upsert',
            ['document' => $document->toArray()],
            'document-' . substr(hash('sha256', $index . ':' . $document->documentId . ':' . $document->revisionId), 0, 40)
        ));
        $status = (string) ($payload['status'] ?? 'completed');
        if (!in_array($status, ['completed', 'skipped'], true)) {
            throw new RuntimeException('Custom RAG returned an invalid document indexing status.');
        }
        return new IndexResult(
            $status,
            max(0, (int) ($payload['indexed_items'] ?? count($document->blocks))),
            ['remote_job_id' => (string) ($payload['job_id'] ?? '')]
        );
    }

    public function deleteDocument(string $documentId, IndexGeneration $generation): void
    {
        $payload = $this->jsonRequest(
            'DELETE',
            '/v1/indexes/' . rawurlencode($this->remoteIndexId($generation)) . '/documents/' . rawurlencode($documentId),
            null,
            'delete-' . substr(hash('sha256', $generation->id . ':' . $documentId), 0, 40),
            [200, 202, 204, 404]
        );
        $this->awaitAsyncResult($payload);
    }

    public function search(RagSearchRequest $request, AccessScope $scope): EvidenceResponse
    {
        $queryId = $request->resolvedQueryId();
        // Send only the current access principals. RAG Core still performs a
        // second live ACL and revision check before returning any evidence.
        $payload = $this->jsonRequest(
            'POST',
            '/v1/indexes/' . rawurlencode($this->remoteIndexId($request->generation)) . '/search',
            [
                'query_id' => $queryId,
                'query' => $request->query,
                'limit' => $request->limit,
                'filters' => $request->filters,
                'access_scope' => [
                    'workspace_id' => $scope->workspaceId,
                    'allowed_principals' => $this->principals($scope),
                ],
            ]
        );
        return $this->evidenceResponse($payload, $request, $queryId);
    }

    public function validateConnection(): array
    {
        $health = $this->healthCheck();
        if (!$health->healthy) {
            throw new RuntimeException('Custom RAG health check failed.');
        }
        $capabilities = $this->capabilities();
        return ['health' => $health->status, 'api_version' => $capabilities->apiVersion, 'features' => $capabilities->features];
    }

    private function assertUsable(): void
    {
        $this->validateConnection();
    }

    private function remoteIndexId(IndexGeneration $generation): string
    {
        $id = trim((string) ($generation->configuration->options['remote_index_id'] ?? $this->configuration['index_id'] ?? ''));
        if ($id === '') {
            throw new RuntimeException('Custom RAG index ID is unavailable.');
        }
        return $id;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function awaitAsyncResult(array $payload): array
    {
        $status = (string) ($payload['status'] ?? '');
        $jobId = trim((string) ($payload['job_id'] ?? ''));
        if (!in_array($status, ['queued', 'processing'], true) || $jobId === '') {
            return $payload;
        }
        if (strlen($jobId) > 190 || preg_match('/[\x00-\x1F]/', $jobId) === 1) {
            throw new RuntimeException('Custom RAG returned an invalid asynchronous job ID.');
        }
        $deadline = microtime(true) + (int) $this->configuration['search_timeout'];
        do {
            usleep(250000);
            $job = $this->jsonRequest('GET', '/v1/jobs/' . rawurlencode($jobId));
            $jobStatus = (string) ($job['status'] ?? '');
            if ($jobStatus === 'completed') {
                $result = is_array($job['result'] ?? null) ? $job['result'] : [];
                return [...$payload, ...$result, 'status' => (string) ($result['status'] ?? 'completed'), 'job_id' => $jobId];
            }
            if ($jobStatus === 'partial') {
                throw new RuntimeException('Custom RAG asynchronous job completed partially.');
            }
            if (in_array($jobStatus, ['failed', 'cancelled'], true)) {
                throw new RuntimeException('Custom RAG asynchronous job failed.');
            }
            if (!in_array($jobStatus, ['queued', 'processing'], true)) {
                throw new RuntimeException('Custom RAG returned an invalid asynchronous job status.');
            }
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Custom RAG asynchronous job timed out.');
    }

    /** @return list<string> */
    private function principals(AccessScope $scope): array
    {
        $principals = ['user:' . $scope->userId];
        $department = trim((string) ($scope->attributes['department'] ?? ''));
        if ($department !== '') {
            $principals[] = 'group:' . $department;
        }
        $role = trim((string) ($scope->attributes['role'] ?? ''));
        if ($role !== '') {
            $principals[] = 'role:' . $role;
        }
        return $principals;
    }

    /** @param array<string, mixed> $payload */
    private function evidenceResponse(array $payload, RagSearchRequest $request, string $queryId): EvidenceResponse
    {
        // Normalize the external fixed schema into the internal immutable
        // Evidence objects used by every retriever implementation.
        $responseQueryId = (string) ($payload['query_id'] ?? '');
        $responseQuery = (string) ($payload['query'] ?? '');
        $engine = is_array($payload['engine'] ?? null) ? $payload['engine'] : [];
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        if ((string) ($payload['schema_version'] ?? '') !== '1.0'
            || !hash_equals($queryId, $responseQueryId)
            || !hash_equals($request->query, $responseQuery)
            || trim((string) ($engine['plugin_id'] ?? '')) === ''
            || trim((string) ($engine['plugin_version'] ?? '')) === ''
            || trim((string) ($engine['index_generation'] ?? '')) === ''
            || !is_array($payload['evidence'] ?? null)
            || !is_array($payload['warnings'] ?? null)) {
            throw new RuntimeException('Custom RAG returned an invalid Evidence JSON schema.');
        }
        $items = [];
        foreach ($payload['evidence'] as $offset => $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Custom RAG returned incomplete evidence.');
            }
            $scoreValue = is_array($row['score'] ?? null) ? ($row['score']['raw'] ?? null) : null;
            $normalized = is_array($row['score'] ?? null) ? ($row['score']['normalized'] ?? null) : null;
            $methods = is_array($row['retrieval_method'] ?? null)
                ? array_values(array_filter($row['retrieval_method'], 'is_string'))
                : [];
            $requiredFields = [
                'rank', 'document_id', 'revision_id', 'chunk_id', 'block_ids', 'title', 'text',
                'language', 'tags', 'retrieval_method', 'score', 'source_url', 'content_hash', 'updated_at',
            ];
            if ((int) ($row['rank'] ?? 0) !== $offset + 1
                || array_diff($requiredFields, array_keys($row)) !== []
                || trim((string) ($row['document_id'] ?? '')) === ''
                || trim((string) ($row['revision_id'] ?? '')) === ''
                || trim((string) ($row['chunk_id'] ?? '')) === ''
                || !is_array($row['block_ids'] ?? null)
                || $row['block_ids'] === []
                || count(array_filter($row['block_ids'], 'is_string')) !== count($row['block_ids'])
                || count(array_unique($row['block_ids'])) !== count($row['block_ids'])
                || $methods === []
                || !is_string($row['title'])
                || !is_string($row['text'])
                || !is_string($row['language'])
                || trim($row['language']) === ''
                || !is_array($row['tags'])
                || count(array_filter($row['tags'], 'is_string')) !== count($row['tags'])
                || !is_string($row['source_url'])
                || !is_string($row['content_hash'])
                || !is_string($row['updated_at'])
                || preg_match('/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $row['updated_at']) !== 1
                || !is_numeric($scoreValue)
                || !is_finite((float) $scoreValue)
                || ($normalized !== null && (!is_numeric($normalized) || !is_finite((float) $normalized)))) {
                throw new RuntimeException('Custom RAG returned incomplete evidence.');
            }
            $metadata = [
                'title' => (string) ($row['title'] ?? ''),
                'language' => (string) ($row['language'] ?? 'und'),
                'tags' => is_array($row['tags'] ?? null) ? array_values(array_filter($row['tags'], 'is_string')) : [],
                'retrieval_methods' => $methods,
                'normalized_score' => $normalized,
                'source_url' => (string) ($row['source_url'] ?? ''),
                'content_hash' => (string) ($row['content_hash'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
                'extensions' => is_array($row['extensions'] ?? null) ? $row['extensions'] : [],
            ];
            $items[] = new Evidence(
                (string) ($row['document_id'] ?? ''),
                (string) ($row['revision_id'] ?? ''),
                array_values(array_filter($row['block_ids'], 'is_string')),
                (string) ($row['text'] ?? ''),
                (float) $scoreValue,
                $methods[0] ?? 'custom',
                $metadata,
                (string) ($row['chunk_id'] ?? '')
            );
        }
        $resultStatus = (string) ($result['status'] ?? '');
        if ((int) ($result['count'] ?? -1) !== count($items)
            || !in_array($resultStatus, ['found', 'not_found', 'partial'], true)
            || ($resultStatus === 'found' && $items === [])
            || ($resultStatus === 'not_found' && $items !== [])
            || count(array_filter($payload['warnings'], 'is_string')) !== count($payload['warnings'])) {
            throw new RuntimeException('Custom RAG returned inconsistent Evidence result metadata.');
        }
        return new EvidenceResponse(
            $responseQueryId,
            $responseQuery,
            (string) $engine['plugin_id'],
            (string) $engine['plugin_version'],
            $request->generation->id,
            $items,
            (bool) ($result['truncated'] ?? false),
            is_array($payload['warnings'] ?? null) ? array_values(array_filter($payload['warnings'], 'is_string')) : [],
            is_array($payload['extensions'] ?? null) ? $payload['extensions'] : []
        );
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param list<int> $acceptedStatuses
     * @return array<string, mixed>
     */
    private function jsonRequest(
        string $method,
        string $path,
        ?array $payload = null,
        ?string $idempotencyKey = null,
        array $acceptedStatuses = [200, 201, 202]
    ): array {
        // Authentication values are resolved at request time and never stored
        // in the provider configuration or included in raised error messages.
        $headers = ['Accept: application/json'];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $authMode = (string) $this->configuration['auth_mode'];
        if ($authMode !== 'none') {
            $secret = (string) getenv((string) $this->configuration['secret_env']);
            if ($secret === '') {
                throw new RuntimeException('Custom RAG authentication environment variable is unavailable.');
            }
            $headers[] = $authMode === 'bearer'
                ? 'Authorization: Bearer ' . $secret
                : 'X-API-Key: ' . $secret;
        }
        $body = $payload === null ? null : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $response = $this->http->request(
            $method,
            (string) $this->configuration['base_url'] . $path,
            $headers,
            $body,
            (int) $this->configuration['connect_timeout'],
            (int) $this->configuration['search_timeout'],
            max(1048576, (int) ($this->configuration['max_response_bytes'] ?? 4194304))
        );
        if (!in_array($response['status'], $acceptedStatuses, true)) {
            throw new RuntimeException('Custom RAG request failed with HTTP status ' . $response['status'] . '.');
        }
        if ($response['status'] === 204 || trim($response['body']) === '') {
            return [];
        }
        $decoded = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('Custom RAG response is not a JSON object.');
        }
        return $decoded;
    }
}
