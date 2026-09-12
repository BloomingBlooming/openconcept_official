<?php

declare(strict_types=1);

final class RagAiSearchBridge
{
    public function __construct(private readonly RagCoreService $service)
    {
    }

    /**
     * Return a complete Core AI-search result when RAG retrieval is enabled.
     * A prior plugin result always wins so multiple providers can compose
     * without silently replacing one another.
     *
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function result(
        mixed $current,
        string $question,
        array $user,
        string $applicationUrl,
        int $limit = 10
    ): ?array {
        if (is_array($current)) {
            return $current;
        }
        if ($current !== null) {
            throw new RuntimeException('The preceding RAG AI search integration returned an invalid result.');
        }

        $configuration = $this->service->repository()->configuration();
        $retrieval = is_array($configuration['retrieval'] ?? null)
            ? $configuration['retrieval']
            : [];
        $retrievalMode = (string) ($retrieval['mode'] ?? 'unconfigured');
        if (!in_array($retrievalMode, ['standard', 'custom'], true)) {
            return null;
        }
        $engine = $this->service->repository()->engineInstance('primary');
        $activeGeneration = $this->service->repository()->activeGeneration('primary');
        if (!is_array($engine) || ($engine['enabled'] ?? false) !== true || !is_array($activeGeneration)) {
            // A desired RAG route is not an effective route until its first
            // generation is explicitly activated. Preserve the established AI
            // search path while an index is building or awaiting activation.
            return null;
        }

        $status = $this->service->status();
        $scope = new AccessScope(
            (int) ($user['id'] ?? 0),
            (string) ($status['workspace_id'] ?? ''),
            [
                'role' => (string) ($user['role'] ?? ''),
                'department' => (string) ($user['department'] ?? ''),
            ]
        );
        $evidence = $this->service->search(
            'primary',
            $question,
            $scope,
            max(1, min(5, $limit))
        );
        $evidenceJson = $evidence->toArray();
        $sources = $this->sources($evidence, $applicationUrl);
        $answerConfiguration = is_array($configuration['answer'] ?? null)
            ? $configuration['answer']
            : [];
        $answerMode = (string) ($answerConfiguration['mode'] ?? 'none');

        if ($answerMode === 'none') {
            $answer = json_encode(
                $evidenceJson,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            $model = $evidence->enginePluginId;
            $inputTokens = 0;
            $outputTokens = 0;
            $latencyMs = 0;
        } else {
            $providerId = (string) ($answerConfiguration['provider_id'] ?? 'openai-compatible');
            $providerConfiguration = is_array($answerConfiguration['configuration'] ?? null)
                ? $answerConfiguration['configuration']
                : [];
            $chat = $this->service->generateAnswer(
                $evidence,
                $question,
                $providerId,
                $providerConfiguration,
                (string) ($answerConfiguration['system_prompt'] ?? ''),
                [
                    'temperature' => (float) ($answerConfiguration['temperature'] ?? 0.2),
                    'language' => (string) ($answerConfiguration['language'] ?? ''),
                ]
            );
            $answer = $chat->answer;
            $model = $chat->modelId !== '' ? $chat->modelId : $chat->providerId;
            $inputTokens = max(0, (int) ($chat->usage['input_tokens'] ?? 0));
            $outputTokens = max(0, (int) ($chat->usage['output_tokens'] ?? 0));
            $latencyMs = max(0, (int) ($chat->usage['latency_ms'] ?? 0));
        }

        return [
            'mode' => $answerMode === 'none' ? 'rag-evidence' : 'rag-answer',
            'question' => $question,
            'answer' => $answer,
            'answer_basis' => $sources === [] ? 'unavailable' : 'registered_data',
            'general_knowledge_fallback' => false,
            'sufficient' => $sources !== [],
            'sources' => $sources,
            'candidate_count' => count($sources),
            'model' => $model,
            'reasoning_effort' => 'low',
            'response_id' => $evidence->queryId,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'latency_ms' => $latencyMs,
            'degraded' => false,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function sources(EvidenceResponse $response, string $applicationUrl): array
    {
        $baseUrl = rtrim($applicationUrl, '/');
        $sources = [];
        foreach ($response->evidence as $item) {
            if (!$item instanceof Evidence || preg_match('/^[1-9][0-9]{0,18}$/D', $item->documentId) !== 1) {
                continue;
            }
            $pageId = (int) $item->documentId;
            $metadata = $item->metadata;
            $sources[] = [
                'document_id' => $item->documentId,
                'chunk_id' => $item->stableChunkId(),
                'page_id' => $pageId,
                'title' => (string) ($metadata['title'] ?? ('Page ' . $pageId)),
                'summary' => $this->sliceText($item->excerpt, 500),
                'content' => $item->excerpt,
                'tags' => is_array($metadata['tags'] ?? null) ? array_values($metadata['tags']) : [],
                'source_scope' => 'rag_evidence',
                'source_hash' => $item->revisionId,
                'rag_generation_id' => $response->indexGeneration,
                'block_ids' => array_values($item->blockIds),
                'score' => $item->score,
                'retrieval_method' => $item->retrievalMethod,
                'url' => $baseUrl . '/#page-' . $pageId,
            ];
        }
        return $sources;
    }

    private function sliceText(string $value, int $length): string
    {
        $value = trim($value);
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
