<?php

declare(strict_types=1);

final class BgeM3EmbeddingProvider implements EmbeddingProviderInterface
{
    /** @var array<string, mixed> */
    private array $configuration;
    private ?int $resolvedDimensions;
    private SafeOutboundHttpClient $http;

    /** @param array<string, mixed> $configuration */
    public function __construct(array $configuration = [], ?SafeOutboundHttpClient $http = null)
    {
        $baseUrl = trim((string) ($configuration['base_url'] ?? ''));
        $modelId = trim((string) ($configuration['model_id'] ?? ''));
        $modelRevision = trim((string) ($configuration['model_revision'] ?? ''));
        $configuration['base_url'] = rtrim(
            $baseUrl !== '' ? $baseUrl : trim((string) getenv('OPENCONCEPT_BGE_M3_BASE_URL')),
            '/'
        );
        $configuration['model_id'] = $modelId !== ''
            ? $modelId
            : (trim((string) getenv('OPENCONCEPT_BGE_M3_MODEL')) ?: 'BAAI/bge-m3');
        $configuration['model_revision'] = $modelRevision !== ''
            ? $modelRevision
            : trim((string) getenv('OPENCONCEPT_BGE_M3_REVISION'));
        $configuration['api_key_env'] = trim((string) ($configuration['api_key_env'] ?? 'OPENCONCEPT_BGE_M3_API_KEY'));
        if (preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $configuration['api_key_env']) !== 1) {
            throw new InvalidArgumentException('BGE-M3 authentication must reference a server environment variable.');
        }
        $configuration['timeout'] = max(1, min(600, (int) ($configuration['timeout'] ?? 60)));
        if (array_key_exists('api_key', $configuration)) {
            $apiKey = trim((string) $configuration['api_key']);
            if (strlen($apiKey) > 4096 || preg_match('/[\r\n\x00]/', $apiKey) === 1) {
                throw new InvalidArgumentException('BGE-M3 API key is invalid.');
            }
            $configuration['api_key'] = $apiKey;
        }
        $configuration['batch_size'] = max(1, min(256, (int) ($configuration['batch_size'] ?? 32)));
        $configuration['verify_ssl'] = !array_key_exists('verify_ssl', $configuration) || (bool) $configuration['verify_ssl'];
        $configuration['allow_private_network'] = array_key_exists('allow_private_network', $configuration)
            ? (bool) $configuration['allow_private_network']
            : $this->environmentFlag('OPENCONCEPT_BGE_M3_ALLOW_PRIVATE_NETWORK');
        $configuration['allow_http'] = array_key_exists('allow_http', $configuration)
            ? (bool) $configuration['allow_http']
            : $this->environmentFlag('OPENCONCEPT_BGE_M3_ALLOW_HTTP');
        $dimensions = $configuration['dimensions'] ?? getenv('OPENCONCEPT_BGE_M3_DIMENSIONS') ?: null;
        $this->resolvedDimensions = is_numeric($dimensions) && (int) $dimensions > 0 ? (int) $dimensions : null;
        $this->configuration = $configuration;
        $this->http = $http ?? new SafeOutboundHttpClient(
            $configuration['allow_private_network'],
            $configuration['verify_ssl'],
            $configuration['allow_http']
        );
    }

    public function capabilities(): EmbeddingCapabilities
    {
        return new EmbeddingCapabilities(
            'bge-m3-dense',
            (string) $this->configuration['model_id'],
            $this->configuration['model_revision'] === '' ? null : (string) $this->configuration['model_revision'],
            $this->resolvedDimensions,
            'cosine'
        );
    }

    public function healthCheck(): HealthResult
    {
        if ($this->configuration['base_url'] === '') {
            return new HealthResult(false, 'embedding_not_configured');
        }
        try {
            $vectors = $this->embed(['OpenConcept BGE-M3 dimension probe']);
            return new HealthResult($vectors !== [], 'ready', ['dimensions' => $this->resolvedDimensions]);
        } catch (EmbeddingServiceException $exception) {
            return new HealthResult(false, $exception->failureCode(), $exception->details());
        } catch (OutboundHttpException $exception) {
            return new HealthResult(false, $this->outboundFailureCode($exception->failureCode()), $exception->details());
        } catch (Throwable) {
            return new HealthResult(false, 'embedding_unreachable');
        }
    }

    public function embed(array $texts): array
    {
        if ($texts === [] || count($texts) > 256 || $this->configuration['base_url'] === '') {
            throw new InvalidArgumentException('BGE-M3 embedding input or endpoint is invalid.');
        }
        foreach ($texts as $text) {
            if (!is_string($text) || trim($text) === '' || $this->length($text) > 100000) {
                throw new InvalidArgumentException('BGE-M3 embedding text is invalid.');
            }
        }
        $headers = ['Accept: application/json', 'Content-Type: application/json; charset=utf-8'];
        $secret = array_key_exists('api_key', $this->configuration)
            ? (string) $this->configuration['api_key']
            : (string) getenv((string) $this->configuration['api_key_env']);
        if ($secret !== '') {
            $headers[] = 'Authorization: Bearer ' . $secret;
        }
        $response = $this->http->request(
            'POST',
            $this->configuration['base_url'] . '/embeddings',
            $headers,
            json_encode([
                'model' => $this->configuration['model_id'],
                'input' => array_values($texts),
                'encoding_format' => 'float',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            15,
            (int) $this->configuration['timeout'],
            33554432
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $status = (int) $response['status'];
            $code = match (true) {
                in_array($status, [401, 403], true) => 'embedding_authentication_failed',
                $status === 404 => 'embedding_endpoint_not_found',
                in_array($status, [408, 504], true) => 'embedding_timeout',
                in_array($status, [400, 409, 422], true) => 'embedding_model_unavailable',
                $status === 429 || $status >= 500 => 'embedding_service_unavailable',
                default => 'embedding_request_rejected',
            };
            throw new EmbeddingServiceException($code, 'BGE-M3 endpoint rejected the request.', [
                'http_status' => $status,
                'endpoint' => $this->configuration['base_url'] . '/embeddings',
                'model_id' => (string) $this->configuration['model_id'],
            ]);
        }
        try {
            $payload = json_decode($response['body'], true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new EmbeddingServiceException('embedding_invalid_response', 'BGE-M3 endpoint returned invalid JSON.');
        }
        $rows = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : null;
        if ($rows === null || count($rows) !== count($texts)) {
            throw new EmbeddingServiceException('embedding_invalid_response', 'BGE-M3 endpoint returned an invalid embedding count.');
        }
        usort($rows, static fn (array $left, array $right): int => (int) ($left['index'] ?? 0) <=> (int) ($right['index'] ?? 0));
        $vectors = [];
        foreach ($rows as $row) {
            $embedding = is_array($row['embedding'] ?? null) ? $row['embedding'] : [];
            if ($embedding === []) {
                throw new EmbeddingServiceException('embedding_invalid_response', 'BGE-M3 endpoint returned an empty embedding.');
            }
            $vector = [];
            foreach ($embedding as $value) {
                $number = (float) $value;
                if (!is_finite($number)) {
                    throw new EmbeddingServiceException('embedding_invalid_response', 'BGE-M3 endpoint returned a non-finite embedding value.');
                }
                $vector[] = $number;
            }
            $dimensions = count($vector);
            if ($this->resolvedDimensions !== null && $dimensions !== $this->resolvedDimensions) {
                throw new EmbeddingServiceException('embedding_dimension_mismatch', 'BGE-M3 embedding dimensions changed unexpectedly.', [
                    'expected_dimensions' => $this->resolvedDimensions,
                    'actual_dimensions' => $dimensions,
                ]);
            }
            $this->resolvedDimensions = $dimensions;
            $vectors[] = $vector;
        }
        return $vectors;
    }

    public function batchSize(): int
    {
        return (int) $this->configuration['batch_size'];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function environmentFlag(string $name): bool
    {
        return in_array(strtolower(trim((string) getenv($name))), ['1', 'true', 'yes', 'on'], true);
    }

    private function outboundFailureCode(string $code): string
    {
        return match ($code) {
            'outbound_timeout' => 'embedding_timeout',
            'outbound_tls_failed' => 'embedding_tls_failed',
            'outbound_http_not_allowed' => 'embedding_http_not_allowed',
            'outbound_private_network_not_allowed' => 'embedding_private_network_not_allowed',
            'outbound_url_invalid' => 'embedding_url_invalid',
            'outbound_dns_failed' => 'embedding_dns_failed',
            'outbound_connection_refused' => 'embedding_connection_refused',
            default => 'embedding_unreachable',
        };
    }
}
