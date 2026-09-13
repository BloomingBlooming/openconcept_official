<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaException.php';

/**
 * Built-in RAG orchestration for OpenConcept.
 *
 * Retrieval and answer providers may be supplied by plugins, but document
 * snapshots, synchronization, ACL/evidence verification, settings, jobs,
 * index generations, API handling, and AI-search integration belong to Core.
 */
final class RagCoreRuntime
{
    public const SCHEMA_VERSION = '2';
    public const SCHEMA_SETTING = 'rag.core_schema_version';
    public const STANDARD_PROVIDER_ID = StandardRagRetriever::PROVIDER_ID;
    public const CUSTOM_PROVIDER_ID = CustomRagHttpConnector::PROVIDER_ID;

    private function __construct(
        private readonly RagCoreService $service,
        private readonly RagCoreController $controller
    ) {
    }

    public static function boot(
        PDO $pdo,
        RagDocumentGatewayInterface $documents,
        DatabaseCapabilitiesInterface $databaseCapabilities,
        ?string $canonicalBackend,
        AiProviderSettings $answerProviderSettings
    ): ?self {
        $schemaVersion = setting($pdo, self::SCHEMA_SETTING, '0');
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new CoreSchemaException([
                'reason' => 'canonical_rag_schema_marker_mismatch',
                'stored_rag_schema_version' => $schemaVersion,
                'target_rag_schema_version' => self::SCHEMA_VERSION,
            ]);
        }

        $repository = new RagCoreRepository($pdo);
        $repository->verifySchema();
        $ragAnswerProviderSettings = $answerProviderSettings->scoped(AiProviderSettings::RAG_ANSWER_PROFILE);
        $ragEmbeddingProviderSettings = $answerProviderSettings->scoped(AiProviderSettings::RAG_EMBEDDING_PROFILE);
        $ragCustomProviderSettings = $answerProviderSettings->scoped(AiProviderSettings::RAG_CUSTOM_PROFILE);

        $registry = new RagProviderRegistry();
        $registry->registerRagRetrieverFactory(
            self::STANDARD_PROVIDER_ID,
            static function (array $configuration) use (
                $pdo,
                $databaseCapabilities,
                $ragEmbeddingProviderSettings
            ): RagRetrieverInterface {
                $embedding = is_array($configuration['embedding'] ?? null)
                    ? $configuration['embedding']
                    : [];
                $embedding = self::resolveApiKeyConfiguration($embedding, $ragEmbeddingProviderSettings);
                $chunking = is_array($configuration['chunking'] ?? null)
                    ? $configuration['chunking']
                    : [];
                return new StandardRagRetriever(
                    new StandardRagRepository($pdo),
                    new BgeM3EmbeddingProvider($embedding),
                    new DeterministicBlockChunker((int) ($chunking['target_characters'] ?? 2400)),
                    $databaseCapabilities
                );
            }
        );
        $registry->registerRagRetrieverFactory(
            self::CUSTOM_PROVIDER_ID,
            static function (array $configuration) use ($ragCustomProviderSettings): RagRetrieverInterface {
                $connection = is_array($configuration['connection'] ?? null)
                    ? $configuration['connection']
                    : $configuration;
                return new CustomRagHttpConnector(self::resolveApiKeyConfiguration(
                    $connection,
                    $ragCustomProviderSettings,
                    'secret_env',
                    (string) ($connection['auth_mode'] ?? 'bearer')
                ));
            }
        );
        $registry->registerChatProviderFactory(
            'openai-compatible',
            static function (array $configuration) use (
                $answerProviderSettings,
                $ragAnswerProviderSettings
            ): ChatProviderInterface {
                $connection = is_array($configuration['connection'] ?? null)
                    ? $configuration['connection']
                    : $configuration;
                if (($connection['use_shared_ai_provider'] ?? false) === true) {
                    $resolved = $answerProviderSettings->resolved();
                    $connection = [
                        'display_name' => (string) ($resolved['display_name'] ?? 'OpenAI Compatible'),
                        'base_url' => (string) ($resolved['base_url'] ?? ''),
                        'model_id' => (string) ($resolved['text_model'] ?? ''),
                        'endpoint_mode' => (string) ($resolved['endpoint_mode'] ?? 'chat_completions'),
                        'auth_mode' => (string) ($resolved['auth_mode'] ?? 'bearer'),
                        'api_key_env' => 'OPENAI_API_KEY',
                        'api_key' => (string) ($resolved['api_key'] ?? ''),
                        'timeout' => (int) ($resolved['timeout'] ?? 120),
                        'verify_ssl' => !array_key_exists('verify_tls', $resolved) || (bool) $resolved['verify_tls'],
                        'allow_private_network' => (bool) ($resolved['allow_private_network'] ?? false),
                        'allow_http' => (bool) ($resolved['allow_http'] ?? false),
                    ];
                } else {
                    unset($connection['use_shared_ai_provider']);
                    $connection = self::resolveApiKeyConfiguration(
                        $connection,
                        $ragAnswerProviderSettings,
                        'api_key_env',
                        (string) ($connection['auth_mode'] ?? 'bearer')
                    );
                }
                return new OpenAiCompatibleChatProvider($connection);
            }
        );

        $service = new RagCoreService($repository, $registry, $documents, $databaseCapabilities);
        $runtime = new self(
            $service,
            new RagCoreController(
                $service,
                $databaseCapabilities,
                $canonicalBackend,
                $answerProviderSettings,
                $ragAnswerProviderSettings,
                $ragEmbeddingProviderSettings,
                $ragCustomProviderSettings
            )
        );
        $runtime->registerCoreIntegration();
        return $runtime;
    }

    public function service(): RagCoreService
    {
        return $this->service;
    }

    /** Resolve credentials for a request only; the returned secret is never persisted in RAG configuration. */
    public static function resolveApiKeyConfiguration(
        array $configuration,
        AiProviderSettings $store,
        string $environmentField = 'api_key_env',
        string $authMode = 'bearer'
    ): array {
        $shared = (bool) ($configuration['use_shared_api_key'] ?? false);
        $authMode = $authMode === 'api_key' ? 'x-api-key' : $authMode;
        if ($shared) {
            $configuration['api_key'] = $store->apiKeyFor((string) ($configuration['base_url'] ?? ''), $authMode, true);
            // An unavailable shared key must never fall back to a dedicated
            // environment key. Preserve the original reference only in DB settings.
            $configuration[$environmentField] = '';
        } elseif (trim((string) ($configuration['api_key'] ?? '')) === ''
            || (string) ($configuration['api_key'] ?? '') === '＊＊＊＊') {
            unset($configuration['api_key']);
            $key = $store->storedApiKeyFor((string) ($configuration['base_url'] ?? ''), $authMode);
            if ($key !== '') {
                $configuration['api_key'] = $key;
            }
        }
        return $configuration;
    }

    public function controller(): RagCoreController
    {
        return $this->controller;
    }

    /** @return array{processed: int, completed: int, skipped: int, failed: int} */
    public function processQueued(int $limit, string $workerId): array
    {
        return $this->service->processQueued($limit, $workerId . '-rag-core');
    }

    private function registerCoreIntegration(): void
    {
        Hooks::addAction(
            'rag_document_snapshot_updated',
            function (int $pageId, string $revisionId): void {
                try {
                    $this->service->queueSnapshot((string) $pageId, $revisionId);
                } catch (Throwable $exception) {
                    error_log('RAG Core snapshot queue failed: ' . $exception->getMessage());
                }
            }
        );
        Hooks::addAction(
            'rag_document_snapshot_deleted',
            function (int $pageId, string $revisionId): void {
                try {
                    $this->service->queueDelete((string) $pageId, $revisionId);
                } catch (Throwable $exception) {
                    error_log('RAG Core delete queue failed: ' . $exception->getMessage());
                }
            }
        );
        Hooks::addFilter(
            'rag_core_service',
            fn (mixed $current): mixed => $current instanceof RagCoreService ? $current : $this->service
        );
        $bridge = new RagAiSearchBridge($this->service);
        Hooks::addFilter(
            'ai_search_rag_result',
            static fn (
                mixed $current,
                string $question,
                array $user,
                string $applicationUrl,
                int $limit
            ): mixed => $bridge->result($current, $question, $user, $applicationUrl, $limit)
        );
    }
}
