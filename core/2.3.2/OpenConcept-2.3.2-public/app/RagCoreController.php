<?php

declare(strict_types=1);

final class RagCoreController
{
    public function __construct(
        private readonly RagCoreService $service,
        private readonly DatabaseCapabilitiesInterface $databaseCapabilities,
        private readonly ?string $canonicalBackend = null,
        private readonly ?AiProviderSettings $aiProviderSettings = null,
        private readonly ?AiProviderSettings $ragAnswerProviderSettings = null,
        private readonly ?AiProviderSettings $ragEmbeddingProviderSettings = null
    ) {
    }

    /** @param array<string, mixed> $user */
    public function handle(string $action, string $method, array $user): void
    {
        if (!str_starts_with($action, 'rag-core-')) {
            return;
        }
        $isSearch = $action === 'rag-core-search' && $method === 'POST';
        if ($isSearch && (int) ($user['id'] ?? 0) < 1) {
            jsonResponse(['error' => 'RAG authentication is required.'], 401);
        }
        if (!$isSearch && (string) ($user['role'] ?? '') !== 'admin') {
            jsonResponse(['error' => 'RAG Core administrator access is required.'], 403);
        }
        if ($action === 'rag-core-status' && $method === 'GET') {
            $status = $this->service->status();
            $status['deployment_requirement'] = $this->deploymentRequirementStatus();
            jsonResponse($status);
        }
        if ($action === 'rag-core-settings' && $method === 'GET') {
            $configuration = $this->service->repository()->configuration();
            $configurationSaved = $configuration !== [];
            $retrieval = is_array($configuration['retrieval'] ?? null)
                ? $configuration['retrieval']
                : [];
            if (!in_array((string) ($retrieval['mode'] ?? ''), ['standard', 'custom'], true)) {
                $configuration['retrieval'] = [
                    ...$retrieval,
                    'mode' => 'unconfigured',
                    'configuration' => is_array($retrieval['configuration'] ?? null)
                        ? $retrieval['configuration']
                        : [],
                ];
            }
            jsonResponse([
                'settings' => $configuration,
                'configuration_saved' => $configurationSaved,
                'answer_secret' => $this->answerSecretState($configuration),
                'standard_embedding_secret' => $this->embeddingSecretState($configuration),
                'shared_ai_provider' => $this->aiProviderSettings?->publicState(),
                'status' => $this->service->status(),
                'default_system_prompt' => RagCoreService::defaultSystemPrompt(),
                'standard_embedding_default_base_url' => $this->standardEmbeddingDefaultBaseUrl(),
                'deployment_requirement' => $this->deploymentRequirementStatus(),
                'canonical_backend' => $this->canonicalBackend,
                'worker' => $this->workerStatus($this->service->status()),
                'embedding_server_guidance' => $this->embeddingServerGuidance(),
            ]);
        }
        if ($action === 'rag-core-process' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            jsonResponse([
                'ok' => true,
                'result' => $this->service->processQueued(
                    max(1, min(100, (int) ($body['limit'] ?? 10))),
                    'admin-' . (int) ($user['id'] ?? 0)
                ),
            ]);
        }
        if ($action === 'rag-core-document-retry' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $documentId = trim((string) ($body['document_id'] ?? ''));
            if ($documentId === '' || strlen($documentId) > 160) {
                throw new InvalidArgumentException('RAG retry document ID is invalid.');
            }
            $queued = $this->service->retryDocument($documentId);
            if ($queued === 0) {
                jsonResponse(['error' => 'No current document snapshot or eligible RAG generation was found.'], 404);
            }
            jsonResponse(['ok' => true, 'queued' => $queued, 'status' => $this->service->status()]);
        }
        if ($action === 'rag-core-engine' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $configuration = is_array($body['configuration'] ?? null) ? $body['configuration'] : [];
            $this->service->repository()->saveEngineInstance(
                trim((string) ($body['engine_instance_id'] ?? '')),
                trim((string) ($body['plugin_id'] ?? '')),
                $configuration,
                (bool) ($body['enabled'] ?? true)
            );
            jsonResponse(['ok' => true, 'status' => $this->service->status()]);
        }
        if ($action === 'rag-core-settings' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $previous = $this->service->repository()->configuration();
            $settings = $this->normalizeSettings($body, $previous);
            $this->service->repository()->validateConfiguration($settings);
            if ($settings['retrieval']['mode'] === 'standard') {
                $this->requireStandardRagDeployment();
            }
            $answerInput = is_array($body['answer'] ?? null) ? $body['answer'] : [];
            $this->validateAnswerSecret($answerInput);
            $generation = null;
            $activeGeneration = $this->service->repository()->activeGeneration('primary');
            $embeddingHealth = null;
            if ($settings['retrieval']['mode'] === 'standard') {
                $standardInput = is_array($body['standard'] ?? null) ? $body['standard'] : [];
                $this->saveEmbeddingSecret($standardInput);
                $embeddingHealth = $this->service->retrieverHealth(
                    'openconcept-rag-standard',
                    $settings['retrieval']['configuration']
                );
                $this->ragEmbeddingProviderSettings?->recordRuntimeStatus(
                    $embeddingHealth->status,
                    $embeddingHealth->details
                );
                if (!$embeddingHealth->healthy) {
                    $this->service->repository()->saveConfiguration($settings);
                    $this->saveAnswerSecret($answerInput);
                    $this->service->repository()->saveEngineInstance(
                        'primary',
                        'openconcept-rag-standard',
                        $settings['retrieval']['configuration'],
                        false
                    );
                    $this->service->repository()->setEngineStatus('primary', 'waiting_for_embedding', false);
                    jsonResponse([
                        'ok' => true,
                        'waiting_for_embedding' => true,
                        'embedding' => $this->healthPayload($embeddingHealth),
                        'status' => $this->service->status(),
                    ]);
                }
            }
            if ($settings['retrieval']['mode'] === 'unconfigured') {
                if ($this->service->repository()->engineInstance('primary') !== null) {
                    $this->service->repository()->setEngineEnabled('primary', false);
                }
            } elseif ($this->retrievalFingerprint($settings) !== $this->retrievalFingerprint($previous)
                || $activeGeneration === null) {
                $providerId = $settings['retrieval']['mode'] === 'standard'
                    ? 'openconcept-rag-standard'
                    : 'openconcept-rag-custom';
                $generation = $this->service->startRebuild(
                    'primary',
                    $providerId,
                    $settings['retrieval']['configuration']
                );
                $this->service->repository()->setEngineStatus('primary', 'building', $activeGeneration !== null);
            } elseif ($settings['retrieval']['mode'] === 'standard') {
                $this->service->repository()->setEngineStatus('primary', 'ready', true);
            }
            $this->service->repository()->saveConfiguration($settings);
            $this->saveAnswerSecret($answerInput);
            jsonResponse([
                'ok' => true,
                'generation' => $generation instanceof IndexGeneration ? [
                    'index_generation_id' => $generation->id,
                    'status' => $generation->status,
                ] : null,
                'status' => $this->service->status(),
                'embedding' => $embeddingHealth instanceof HealthResult ? $this->healthPayload($embeddingHealth) : null,
            ]);
        }
        if ($action === 'rag-core-standard-retry' && $method === 'POST') {
            requireCsrf();
            $settings = $this->service->repository()->configuration();
            $retrieval = is_array($settings['retrieval'] ?? null) ? $settings['retrieval'] : [];
            if ((string) ($retrieval['mode'] ?? '') !== 'standard') {
                throw new InvalidArgumentException('Standard RAG is not the saved retrieval mode.');
            }
            $this->requireStandardRagDeployment();
            $configuration = is_array($retrieval['configuration'] ?? null) ? $retrieval['configuration'] : [];
            $health = $this->service->retrieverHealth('openconcept-rag-standard', $configuration);
            $this->ragEmbeddingProviderSettings?->recordRuntimeStatus($health->status, $health->details);
            if (!$health->healthy) {
                $this->service->repository()->setEngineStatus('primary', 'waiting_for_embedding', false);
                jsonResponse([
                    'ok' => true,
                    'waiting_for_embedding' => true,
                    'embedding' => $this->healthPayload($health),
                    'status' => $this->service->status(),
                ]);
            }
            $generation = $this->service->startRebuild(
                'primary',
                'openconcept-rag-standard',
                $configuration
            );
            $this->service->repository()->setEngineStatus(
                'primary',
                'building',
                $this->service->repository()->activeGeneration('primary') !== null
            );
            jsonResponse([
                'ok' => true,
                'waiting_for_embedding' => false,
                'embedding' => $this->healthPayload($health),
                'generation' => [
                    'index_generation_id' => $generation->id,
                    'status' => $generation->status,
                ],
                'status' => $this->service->status(),
            ]);
        }
        if ($action === 'rag-core-connection-test' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $type = (string) ($body['type'] ?? '');
            $configuration = is_array($body['configuration'] ?? null) ? $body['configuration'] : [];
            if ($type === 'standard-rag') {
                $this->requireStandardRagDeployment();
                jsonResponse(['ok' => true, 'result' => $this->validateStandardRagConnection($configuration)]);
            }
            if ($type === 'custom-rag') {
                jsonResponse(['ok' => true, 'result' => $this->service->validateRetrieverConnection(
                    'openconcept-rag-custom',
                    $configuration
                )]);
            }
            if ($type === 'openai-compatible') {
                $configuration = $this->withResolvedAnswerSecret($configuration);
                $provider = new OpenAiCompatibleChatProvider($configuration);
                $health = $provider->healthCheck();
                if (!$health->healthy) {
                    $detail = trim((string) ($health->details['error'] ?? ''));
                    jsonResponse(['error' => 'OpenAI-compatible provider generation test failed.'
                        . ($detail === '' ? '' : ' ' . $detail)], 422);
                }
                jsonResponse(['ok' => true, 'result' => [
                    'status' => $health->status,
                    'capabilities' => $provider->capabilities()->features,
                ]]);
            }
            jsonResponse(['error' => 'Unknown connection test type.'], 422);
        }
        if ($action === 'rag-core-generation-activate' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $status = $this->service->status();
            $this->service->activateGeneration(
                'primary',
                trim((string) ($body['generation_id'] ?? '')),
                new AccessScope((int) ($user['id'] ?? 0), (string) $status['workspace_id'], [
                    'role' => (string) ($user['role'] ?? ''),
                    'department' => (string) ($user['department'] ?? ''),
                ])
            );
            jsonResponse(['ok' => true, 'status' => $this->service->status()]);
        }
        if ($action === 'rag-core-generation-rollback' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $this->service->rollbackGeneration('primary', trim((string) ($body['generation_id'] ?? '')));
            jsonResponse(['ok' => true, 'status' => $this->service->status()]);
        }
        if ($action === 'rag-core-search' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $question = trim((string) ($body['query'] ?? ''));
            $limit = max(1, min(100, (int) ($body['limit'] ?? 10)));
            $scope = new AccessScope((int) ($user['id'] ?? 0), $this->service->status()['workspace_id'], [
                'role' => (string) ($user['role'] ?? ''),
                'department' => (string) ($user['department'] ?? ''),
            ]);
            $evidence = $this->service->search('primary', $question, $scope, $limit);
            $configuration = $this->service->repository()->configuration();
            $answer = is_array($configuration['answer'] ?? null) ? $configuration['answer'] : [];
            if ((string) ($answer['mode'] ?? 'none') === 'none') {
                jsonResponse(['evidence' => $evidence->toArray(), 'answer' => null]);
            }
            $providerId = (string) ($answer['provider_id'] ?? 'openai-compatible');
            $providerConfiguration = is_array($answer['configuration'] ?? null) ? $answer['configuration'] : [];
            $chat = $this->service->generateAnswer(
                $evidence,
                $question,
                $providerId,
                $providerConfiguration,
                (string) ($answer['system_prompt'] ?? ''),
                ['temperature' => (float) ($answer['temperature'] ?? 0.2), 'language' => (string) ($answer['language'] ?? '')]
            );
            jsonResponse(['evidence' => $evidence->toArray(), 'answer' => $chat->toArray()]);
        }
        jsonResponse(['error' => 'Unknown RAG Core action.'], 404);
    }

    /** @return array<string, mixed> */
    private function deploymentRequirementStatus(): array
    {
        if ($this->canonicalBackend !== 'postgresql'
            || $this->databaseCapabilities->driverName() !== 'pgsql') {
            return [
                'ready' => false,
                'code' => 'postgresql_required',
                'canonical_backend' => $this->canonicalBackend,
                'database_driver' => $this->databaseCapabilities->driverName(),
            ];
        }
        if (!$this->databaseCapabilities->supportsExtension('vector')) {
            return [
                'ready' => false,
                'code' => 'pgvector_missing',
                'canonical_backend' => $this->canonicalBackend,
                'database_driver' => $this->databaseCapabilities->driverName(),
                'pgvector_version' => null,
            ];
        }
        return [
            'ready' => true,
            'code' => 'postgresql_ready',
            'canonical_backend' => $this->canonicalBackend,
            'database_driver' => $this->databaseCapabilities->driverName(),
            'pgvector_version' => $this->databaseCapabilities->extensionVersion('vector'),
        ];
    }

    private function requireStandardRagDeployment(): void
    {
        $status = $this->deploymentRequirementStatus();
        if (($status['ready'] ?? false) === true) {
            return;
        }
        $code = (string) ($status['code'] ?? 'postgresql_required');
        $message = $code === 'pgvector_missing'
            ? 'OpenConcept Standard RAG requires the pgvector extension in the canonical PostgreSQL database.'
            : 'OpenConcept Standard RAG requires PostgreSQL to be the canonical database.';
        throw new DeploymentRequirementException(
            $message,
            $code,
            [...$status, 'settings_target' => 'database']
        );
    }

    private function standardEmbeddingDefaultBaseUrl(): string
    {
        $configured = trim((string) getenv('OPENCONCEPT_BGE_M3_BASE_URL'));
        return $configured !== '' ? $configured : 'http://127.0.0.1:8001/v1';
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function validateStandardRagConnection(array $configuration): array
    {
        $settings = $this->normalizeSettings([
            'retrieval_mode' => 'standard',
            'standard' => $configuration,
            'answer' => ['mode' => 'none'],
        ]);
        $normalized = $settings['retrieval']['configuration'];
        $embedding = is_array($normalized['embedding'] ?? null) ? $normalized['embedding'] : [];
        $rawEmbedding = is_array($configuration['embedding'] ?? null) ? $configuration['embedding'] : $configuration;
        $apiKey = trim((string) ($rawEmbedding['api_key'] ?? ''));
        if ($apiKey !== '') {
            $embedding['api_key'] = $apiKey;
        } else {
            $stored = $this->ragEmbeddingProviderSettings?->storedApiKeyFor(
                (string) ($embedding['base_url'] ?? ''),
                'bearer'
            ) ?? '';
            if ($stored !== '') {
                $embedding['api_key'] = $stored;
            }
        }
        $normalized['embedding'] = $embedding;
        return $this->service->validateRetrieverConnection(
            'openconcept-rag-standard',
            $normalized
        );
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function embeddingSecretState(array $configuration): array
    {
        $profiles = is_array($configuration['retrieval_profiles'] ?? null) ? $configuration['retrieval_profiles'] : [];
        $retrieval = is_array($configuration['retrieval'] ?? null) ? $configuration['retrieval'] : [];
        $standard = is_array($profiles['standard'] ?? null)
            ? $profiles['standard']
            : ((string) ($retrieval['mode'] ?? '') === 'standard' && is_array($retrieval['configuration'] ?? null)
                ? $retrieval['configuration'] : []);
        $embedding = is_array($standard['embedding'] ?? null) ? $standard['embedding'] : [];
        $baseUrl = (string) ($embedding['base_url'] ?? '');
        $stored = $this->ragEmbeddingProviderSettings?->storedApiKeyFor($baseUrl, 'bearer') ?? '';
        $environmentName = trim((string) ($embedding['api_key_env'] ?? 'OPENCONCEPT_BGE_M3_API_KEY'));
        $environment = preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $environmentName) === 1
            ? trim((string) getenv($environmentName)) : '';
        $public = $this->ragEmbeddingProviderSettings?->publicState();
        return [
            'configured' => $stored !== '',
            'available' => $stored !== '' || $environment !== '',
            'source' => $stored !== '' ? 'stored' : ($environment !== '' ? 'environment' : 'none'),
            'hint' => $stored === '' ? '' : ('••••' . substr($stored, -4)),
            'environment_name' => $environmentName,
            'runtime' => is_array($public['runtime'] ?? null) ? $public['runtime'] : null,
        ];
    }

    /** @param array<string, mixed> $input */
    private function saveEmbeddingSecret(array $input): void
    {
        if (!$this->ragEmbeddingProviderSettings instanceof AiProviderSettings) {
            return;
        }
        $embedding = is_array($input['embedding'] ?? null) ? $input['embedding'] : $input;
        $baseUrl = trim((string) ($embedding['base_url'] ?? ''));
        $modelId = trim((string) ($embedding['model_id'] ?? 'BAAI/bge-m3')) ?: 'BAAI/bge-m3';
        $apiKey = trim((string) ($embedding['api_key'] ?? ''));
        $settings = [
            'provider_id' => 'openai-compatible',
            'display_name' => 'Standard RAG Embedding',
            'base_url' => $baseUrl,
            'endpoint_mode' => 'chat_completions',
            'structured_output_mode' => 'prompt',
            'auth_mode' => 'bearer',
            'text_model' => $modelId,
            'transcription_model' => $modelId,
            'translation_model' => $modelId,
            'vision_model' => $modelId,
            'timeout' => max(15, min(300, (int) ($embedding['timeout'] ?? 60))),
            'verify_tls' => !array_key_exists('verify_ssl', $embedding) || (bool) $embedding['verify_ssl'],
            'allow_private_network' => (bool) ($embedding['allow_private_network'] ?? false),
            'allow_http' => (bool) ($embedding['allow_http'] ?? false),
            'clear_api_key' => (bool) ($embedding['clear_api_key'] ?? false),
        ];
        if ($apiKey !== '') {
            $settings['api_key'] = $apiKey;
        }
        $resolved = $this->ragEmbeddingProviderSettings->resolved();
        if ($apiKey === '' && ($resolved['api_key_source'] ?? '') === 'stored'
            && $this->ragEmbeddingProviderSettings->storedApiKeyFor($baseUrl, 'bearer') === '') {
            $settings['clear_api_key'] = true;
        }
        $this->ragEmbeddingProviderSettings->save($settings);
    }

    /** @return array<string, mixed> */
    private function healthPayload(HealthResult $health): array
    {
        return ['healthy' => $health->healthy, 'status' => $health->status, 'details' => $health->details];
    }

    /** @param array<string, mixed> $status @return array<string, mixed> */
    private function workerStatus(array $status): array
    {
        $jobs = is_array($status['jobs'] ?? null) ? $status['jobs'] : [];
        $engine = null;
        foreach ((array) ($status['engines'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['engine_instance_id'] ?? '') === 'primary') {
                $engine = $candidate;
                break;
            }
        }
        $state = (string) ($engine['status'] ?? '') === 'waiting_for_embedding'
            ? 'waiting_for_embedding'
            : (((int) ($jobs['processing'] ?? 0)) > 0 ? 'processing'
                : (((int) ($jobs['queued'] ?? 0)) > 0 ? 'queued' : 'idle'));
        return [
            'state' => $state,
            'queued' => (int) ($jobs['queued'] ?? 0),
            'processing' => (int) ($jobs['processing'] ?? 0),
            'failed' => (int) ($jobs['failed'] ?? 0),
            'run_now_available' => $state !== 'waiting_for_embedding',
            'updated_at' => $engine['updated_at'] ?? null,
        ];
    }

    /** @return array<string, string> */
    private function embeddingServerGuidance(): array
    {
        return [
            'owner' => 'server_administrator',
            'message' => 'OpenConcept diagnoses the Embedding endpoint but does not start or modify Docker services.',
            'command' => 'powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\setup-rag-stack.ps1 -Topology hybrid',
        ];
    }

    /** @param array<string, mixed> $configuration @return array{available: bool, source: string, masked: string} */
    private function answerSecretState(array $configuration): array
    {
        $answer = is_array($configuration['answer'] ?? null) ? $configuration['answer'] : [];
        $providerConfiguration = is_array($answer['configuration'] ?? null) ? $answer['configuration'] : [];
        $connection = is_array($providerConfiguration['connection'] ?? null)
            ? $providerConfiguration['connection']
            : $providerConfiguration;
        $shared = ($connection['use_shared_ai_provider'] ?? false) === true;
        $settingsStore = $shared ? $this->aiProviderSettings : $this->ragAnswerProviderSettings;
        $available = (string) ($connection['auth_mode'] ?? 'bearer') === 'none';
        $source = 'none';
        if ($settingsStore instanceof AiProviderSettings) {
            $public = $settingsStore->publicState();
            $secret = is_array($public['secret'] ?? null) ? $public['secret'] : [];
            $stored = (bool) ($secret['configured'] ?? false)
                && $settingsStore->storedApiKeyFor(
                    (string) ($connection['base_url'] ?? ''),
                    (string) ($connection['auth_mode'] ?? 'bearer')
                ) !== '';
            if ($shared) {
                $available = (bool) ($secret['available'] ?? false);
                $source = $available ? (string) ($secret['source'] ?? 'stored') : 'none';
            } elseif ($stored) {
                $available = true;
                $source = 'stored';
            }
        }
        $environmentName = trim((string) ($connection['api_key_env'] ?? ''));
        if (!$available && preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $environmentName) === 1
            && trim((string) getenv($environmentName)) !== '') {
            $available = true;
            $source = 'environment';
        }
        return ['available' => $available, 'source' => $source, 'masked' => $available ? '＊＊＊＊' : ''];
    }

    /** @param array<string, mixed> $answer */
    private function validateAnswerSecret(array $answer): void
    {
        $settings = $this->answerSecretSettings($answer);
        if ($settings === null || !$this->ragAnswerProviderSettings instanceof AiProviderSettings) {
            return;
        }
        $apiKey = trim((string) ($answer['api_key'] ?? ''));
        $hasNewApiKey = $apiKey !== '' && $apiKey !== '＊＊＊＊';
        $authMode = (string) ($settings['auth_mode'] ?? 'bearer');
        $storedApiKey = $this->ragAnswerProviderSettings->storedApiKeyFor(
            (string) ($settings['base_url'] ?? ''),
            $authMode
        );
        $apiKeyEnvironment = trim((string) ($answer['api_key_env'] ?? ''));
        if ($apiKeyEnvironment !== ''
            && preg_match('/^[A-Z][A-Z0-9_]{1,127}$/D', $apiKeyEnvironment) !== 1) {
            throw new InvalidArgumentException('The separate RAG answer API key environment variable is invalid.');
        }
        if ($authMode !== 'none' && !$hasNewApiKey && $storedApiKey === ''
            && $apiKeyEnvironment === '') {
            throw new InvalidArgumentException(
                'Enter an API key again or configure its environment variable for the separate RAG answer connection.'
            );
        }
        $this->ragAnswerProviderSettings->validate($settings);
    }

    /** @param array<string, mixed> $answer */
    private function saveAnswerSecret(array $answer): void
    {
        $settings = $this->answerSecretSettings($answer);
        if ($settings === null || !$this->ragAnswerProviderSettings instanceof AiProviderSettings) {
            return;
        }
        $apiKey = trim((string) ($answer['api_key'] ?? ''));
        $hasNewApiKey = $apiKey !== '' && $apiKey !== '＊＊＊＊';
        $resolved = $this->ragAnswerProviderSettings->resolved();
        $hasStoredApiKey = ($resolved['api_key_source'] ?? '') === 'stored'
            && trim((string) ($resolved['api_key'] ?? '')) !== '';
        $matchesDestination = $this->ragAnswerProviderSettings->storedApiKeyFor(
            (string) ($settings['base_url'] ?? ''),
            (string) ($settings['auth_mode'] ?? 'bearer')
        ) !== '';
        if (!$hasNewApiKey && $hasStoredApiKey && !$matchesDestination) {
            // Never carry a stored credential to a different host or auth
            // scheme. A configured environment reference may take over.
            $settings['clear_api_key'] = true;
        }
        $this->ragAnswerProviderSettings->save($settings);
    }

    /** @param array<string, mixed> $answer @return array<string, mixed>|null */
    private function answerSecretSettings(array $answer): ?array
    {
        if ((string) ($answer['mode'] ?? 'none') !== 'openai_compatible') {
            return null;
        }
        $apiKey = trim((string) ($answer['api_key'] ?? ''));
        $modelId = trim((string) ($answer['model_id'] ?? ''));
        $settings = [
            'provider_id' => 'openai-compatible',
            'display_name' => 'RAG Answer OpenAI Compatible',
            'base_url' => trim((string) ($answer['base_url'] ?? '')),
            'endpoint_mode' => (string) ($answer['endpoint_mode'] ?? 'chat_completions'),
            'structured_output_mode' => 'prompt',
            'auth_mode' => (string) ($answer['auth_mode'] ?? 'bearer'),
            'text_model' => $modelId,
            'transcription_model' => $modelId,
            'translation_model' => $modelId,
            'vision_model' => $modelId,
            'timeout' => max(15, min(300, (int) ($answer['timeout'] ?? 60))),
            'verify_tls' => !array_key_exists('verify_ssl', $answer) || (bool) $answer['verify_ssl'],
            'allow_private_network' => (bool) ($answer['allow_private_network'] ?? false),
            'allow_http' => (bool) ($answer['allow_http'] ?? false),
        ];
        if ($apiKey !== '' && $apiKey !== '＊＊＊＊') {
            $settings['api_key'] = $apiKey;
        }
        return $settings;
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function withResolvedAnswerSecret(array $configuration): array
    {
        $apiKey = trim((string) ($configuration['api_key'] ?? ''));
        if ($apiKey !== '' && $apiKey !== '＊＊＊＊') {
            return $configuration;
        }
        unset($configuration['api_key']);
        if ($this->ragAnswerProviderSettings instanceof AiProviderSettings) {
            $storedApiKey = $this->ragAnswerProviderSettings->storedApiKeyFor(
                (string) ($configuration['base_url'] ?? ''),
                (string) ($configuration['auth_mode'] ?? 'bearer')
            );
            if ($storedApiKey !== '') {
                $configuration['api_key'] = $storedApiKey;
            }
        }
        return $configuration;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $previous
     * @return array<string, mixed>
     */
    private function normalizeSettings(array $input, array $previous = []): array
    {
        $mode = trim((string) ($input['retrieval_mode'] ?? 'unconfigured'));
        if ($mode === 'disabled') {
            $mode = 'unconfigured';
        }
        if (!in_array($mode, ['unconfigured', 'standard', 'custom'], true)) {
            throw new InvalidArgumentException('RAG retrieval mode is invalid.');
        }
        $previousRetrieval = is_array($previous['retrieval'] ?? null) ? $previous['retrieval'] : [];
        $retrievalProfiles = is_array($previous['retrieval_profiles'] ?? null)
            ? $previous['retrieval_profiles']
            : [];
        $previousMode = (string) ($previousRetrieval['mode'] ?? '');
        $previousConfiguration = is_array($previousRetrieval['configuration'] ?? null)
            ? $previousRetrieval['configuration']
            : [];
        if (in_array($previousMode, ['standard', 'custom'], true)) {
            // The effective legacy configuration is authoritative and is
            // promoted into the matching profile on the first new save.
            $retrievalProfiles[$previousMode] = $previousConfiguration;
        } elseif ($previousConfiguration !== []) {
            if (is_array($previousConfiguration['embedding'] ?? null)) {
                $retrievalProfiles['standard'] = $previousConfiguration;
            } elseif (is_array($previousConfiguration['connection'] ?? null)) {
                $retrievalProfiles['custom'] = $previousConfiguration;
            }
        }
        if ($mode === 'standard') {
            $standardInput = is_array($input['standard'] ?? null)
                ? $input['standard']
                : (is_array($retrievalProfiles['standard'] ?? null) ? $retrievalProfiles['standard'] : []);
            $retrievalProfiles['standard'] = $this->normalizeStandardRetrieval($standardInput);
        } elseif ($mode === 'custom') {
            $customInput = is_array($input['custom'] ?? null)
                ? $input['custom']
                : (is_array($retrievalProfiles['custom'] ?? null) ? $retrievalProfiles['custom'] : []);
            $retrievalProfiles['custom'] = $this->normalizeCustomRetrieval($customInput);
        }
        foreach (['standard', 'custom'] as $profileMode) {
            if (!is_array($retrievalProfiles[$profileMode] ?? null)) {
                unset($retrievalProfiles[$profileMode]);
            }
        }
        $retrievalConfiguration = in_array($mode, ['standard', 'custom'], true)
            && is_array($retrievalProfiles[$mode] ?? null)
            ? $retrievalProfiles[$mode]
            : [];
        $answerInput = is_array($input['answer'] ?? null) ? $input['answer'] : [];
        $answerMode = (string) ($answerInput['mode'] ?? 'none');
        if (!in_array($answerMode, ['none', 'shared', 'openai_compatible', 'native'], true)) {
            throw new InvalidArgumentException('RAG answer mode is invalid.');
        }
        $providerId = in_array($answerMode, ['shared', 'openai_compatible'], true)
            ? 'openai-compatible'
            : trim((string) ($answerInput['provider_id'] ?? ''));
        if ($answerMode === 'native' && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $providerId) !== 1) {
            throw new InvalidArgumentException('Native Chat provider ID is invalid.');
        }
        $answerConfiguration = [];
        if ($answerMode === 'shared') {
            $answerConfiguration = ['connection' => ['use_shared_ai_provider' => true]];
        } elseif ($answerMode === 'openai_compatible') {
            $answerBaseUrl = trim((string) ($answerInput['base_url'] ?? ''));
            $defaultEndpointMode = rtrim(strtolower($answerBaseUrl), '/') === 'https://api.openai.com/v1'
                ? 'responses'
                : 'chat_completions';
            $answerConnection = [
                'base_url' => $answerBaseUrl,
                'model_id' => trim((string) ($answerInput['model_id'] ?? '')),
                'endpoint_mode' => (string) ($answerInput['endpoint_mode'] ?? $defaultEndpointMode),
                'auth_mode' => (string) ($answerInput['auth_mode'] ?? 'bearer'),
                'timeout' => max(1, min(600, (int) ($answerInput['timeout'] ?? 60))),
                'streaming' => (bool) ($answerInput['streaming'] ?? false),
                'temperature' => max(0.0, min(2.0, (float) ($answerInput['temperature'] ?? 0.2))),
                'verify_ssl' => !array_key_exists('verify_ssl', $answerInput) || (bool) $answerInput['verify_ssl'],
                'allow_private_network' => (bool) ($answerInput['allow_private_network'] ?? false),
                'allow_http' => (bool) ($answerInput['allow_http'] ?? false),
                'capabilities' => is_array($answerInput['capabilities'] ?? null) ? $answerInput['capabilities'] : [],
                'max_context_tokens' => isset($answerInput['max_context_tokens']) ? max(1, (int) $answerInput['max_context_tokens']) : null,
            ];
            $answerApiKeyEnvironment = trim((string) ($answerInput['api_key_env'] ?? ''));
            if ($answerApiKeyEnvironment !== '') {
                $answerConnection['api_key_env'] = $answerApiKeyEnvironment;
            }
            $answerConfiguration = ['connection' => $answerConnection];
        } elseif ($answerMode === 'native') {
            $answerConfiguration = is_array($answerInput['configuration'] ?? null) ? $answerInput['configuration'] : [];
        }
        $systemPrompt = trim((string) ($answerInput['system_prompt'] ?? ''));
        if ((function_exists('mb_strlen') ? mb_strlen($systemPrompt, 'UTF-8') : strlen($systemPrompt)) > 20000) {
            throw new InvalidArgumentException('RAG answer system prompt is too long.');
        }
        return [
            'retrieval' => ['mode' => $mode, 'configuration' => $retrievalConfiguration],
            'retrieval_profiles' => $retrievalProfiles,
            'answer' => [
                'mode' => in_array($answerMode, ['shared', 'openai_compatible'], true) ? 'provider' : $answerMode,
                'provider_id' => $providerId,
                'configuration' => $answerConfiguration,
                'system_prompt' => $systemPrompt === '' ? RagCoreService::defaultSystemPrompt() : $systemPrompt,
                'temperature' => max(0.0, min(2.0, (float) ($answerInput['temperature'] ?? 0.2))),
                'language' => trim((string) ($answerInput['language'] ?? '')),
            ],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeStandardRetrieval(array $input): array
    {
        $embedding = is_array($input['embedding'] ?? null) ? $input['embedding'] : $input;
        $chunking = is_array($input['chunking'] ?? null) ? $input['chunking'] : $input;
        return [
            'embedding' => [
                'base_url' => trim((string) ($embedding['base_url'] ?? '')),
                'api_key_env' => trim((string) ($embedding['api_key_env'] ?? '')) ?: 'OPENCONCEPT_BGE_M3_API_KEY',
                'model_id' => trim((string) ($embedding['model_id'] ?? 'BAAI/bge-m3')),
                'model_revision' => trim((string) ($embedding['model_revision'] ?? '')),
                'timeout' => max(1, min(600, (int) ($embedding['timeout'] ?? 60))),
                'batch_size' => max(1, min(256, (int) ($embedding['batch_size'] ?? 32))),
                'verify_ssl' => !array_key_exists('verify_ssl', $embedding) || (bool) $embedding['verify_ssl'],
                'allow_private_network' => (bool) ($embedding['allow_private_network'] ?? false),
                'allow_http' => (bool) ($embedding['allow_http'] ?? false),
            ],
            'chunking' => [
                'target_characters' => max(256, min(20000, (int) ($chunking['target_characters'] ?? 2400))),
            ],
            'rrf_k' => max(1, min(1000, (int) ($input['rrf_k'] ?? 60))),
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeCustomRetrieval(array $input): array
    {
        $custom = is_array($input['connection'] ?? null) ? $input['connection'] : $input;
        return ['connection' => [
            'name' => trim((string) ($custom['name'] ?? 'Custom RAG')),
            'base_url' => trim((string) ($custom['base_url'] ?? '')),
            'auth_mode' => (string) ($custom['auth_mode'] ?? 'bearer'),
            'secret_env' => trim((string) ($custom['secret_env'] ?? '')) ?: 'OPENCONCEPT_CUSTOM_RAG_API_KEY',
            'index_id' => trim((string) ($custom['index_id'] ?? '')),
            'connect_timeout' => max(1, min(120, (int) ($custom['connect_timeout'] ?? 10))),
            'search_timeout' => max(1, min(600, (int) ($custom['search_timeout'] ?? 30))),
            'verify_ssl' => !array_key_exists('verify_ssl', $custom) || (bool) $custom['verify_ssl'],
            'allow_private_network' => (bool) ($custom['allow_private_network'] ?? false),
            'allow_http' => (bool) ($custom['allow_http'] ?? false),
        ]];
    }

    /** @param array<string, mixed> $settings */
    private function retrievalFingerprint(array $settings): string
    {
        $retrieval = is_array($settings['retrieval'] ?? null) ? $settings['retrieval'] : [];
        return hash('sha256', json_encode($retrieval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
