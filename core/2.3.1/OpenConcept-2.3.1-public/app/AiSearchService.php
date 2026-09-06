<?php

declare(strict_types=1);

final class AiSearchService
{
    public const INSUFFICIENT_ANSWER = '登録データだけでは回答できません。';
    /** @deprecated 過去に保存した回答の判別と表示互換性のためにのみ使用します。 */
    public const GENERAL_KNOWLEDGE_PREFIX = '登録データだけでは回答できませんが、私の知識から回答すると、';
    public const GENERAL_KNOWLEDGE_SOURCE_EXPLANATION = '私の知っている一般的な知識から回答しました。';
    public const PAGE_INSUFFICIENT_ANSWER = 'このページの内容だけでは回答できません。';

    private PDO $pdo;
    private OpenAIClient $client;
    private string $applicationUrl;
    private int $candidateLimit;
    private int $questionLimit;

    public function __construct(PDO $pdo, ?OpenAIClient $client = null, ?string $applicationUrl = null)
    {
        $this->pdo = $pdo;
        $this->client = $client ?? new OpenAIClient();
        $this->applicationUrl = rtrim($applicationUrl ?? (function_exists('baseUrl') ? baseUrl() : ''), '/');
        $this->candidateLimit = max(4, min(20, (int) (getenv('OPENCONCEPT_AI_SEARCH_CANDIDATE_LIMIT') ?: 12)));
        $this->questionLimit = max(200, min(4000, (int) (getenv('OPENCONCEPT_AI_SEARCH_MAX_QUESTION_CHARS') ?: 1000)));
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function search(
        string $question,
        array $user,
        string $expectedPasswordFingerprint,
        array $conversationHistory = [],
        int $currentPageId = 0
    ): array
    {
        $question = $this->cleanQuestion($question);
        $conversationHistory = $this->normalizeConversationHistory($conversationHistory);
        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        $safetyIdentifier = 'ocu_' . substr(hash('sha256', $this->applicationUrl . ':' . (string) ($user['id'] ?? 0)), 0, 32);
        $basisExplanation = $this->answerBasisExplanation($question, $conversationHistory);
        if ($basisExplanation !== null) {
            return $basisExplanation;
        }
        $currentPage = $currentPageId > 0
            ? $this->accessiblePage($currentPageId, $user, $expectedPasswordFingerprint)
            : null;
        $currentPageCandidates = [];
        $currentPageImages = [];
        if ($currentPage !== null) {
            $currentPageCandidates = $this->canonicalPageCandidates($currentPage);
            $imageContext = $this->canonicalPageImageContext($currentPage);
            $currentPageCandidates = array_merge($currentPageCandidates, $imageContext['candidates']);
            $currentPageImages = $imageContext['images'];
        }

        if ($this->isCurrentPageOnlyRequest($question, $currentPage)) {
            if ($currentPage === null || $currentPageCandidates === []) {
                return [
                    'mode' => 'page-context',
                    'question' => $question,
                    'answer' => self::PAGE_INSUFFICIENT_ANSWER,
                    'answer_basis' => 'unavailable',
                    'general_knowledge_fallback' => false,
                    'sufficient' => false,
                    'sources' => [],
                    'candidate_count' => 0,
                    'model' => $this->client->model(),
                    'reasoning_effort' => 'low',
                    'response_id' => '',
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'latency_ms' => 0,
                    'degraded' => false,
                ];
            }
            $pageResult = $this->answerCandidates(
                $question,
                $currentPageCandidates,
                $safetyIdentifier,
                $user,
                $expectedPasswordFingerprint,
                0,
                false,
                null,
                $conversationHistory,
                $currentPageImages,
                true,
                [],
                'current_page_only'
            );
            $pageResult['mode'] = 'page-context';
            $pageResult['page_id'] = (int) $currentPage['page_id'];
            $pageResult['page_title'] = $this->cleanOutput((string) $currentPage['page_title'], 240);
            $pageResult['current_page_context'] = true;
            $pageResult['answer_basis'] = $pageResult['sufficient'] ? 'current_page' : 'unavailable';
            $pageResult['general_knowledge_fallback'] = false;
            if (!$pageResult['sufficient']) {
                $pageResult['answer'] = self::PAGE_INSUFFICIENT_ANSWER;
            }
            return $pageResult;
        }

        if (class_exists(Hooks::class, false)) {
            $ragResult = Hooks::filter(
                'ai_search_rag_result',
                null,
                $question,
                $user,
                $this->applicationUrl,
                $this->candidateLimit
            );
            if ($ragResult !== null) {
                if (!is_array($ragResult)) {
                    throw new RuntimeException('The RAG AI search integration returned an invalid result.');
                }
                return $ragResult;
            }
        }

        $linkedFileContext = $this->linkedFileContext($question, $user, $expectedPasswordFingerprint);
        $expandedTerms = [];
        $expansionFailed = false;
        $expansionMetadata = [
            'model' => $this->client->model(),
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => 0,
        ];

        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        try {
            $expansion = $this->client->expandSearch($question, $safetyIdentifier, $conversationHistory);
            $expandedTerms = (array) ($expansion['data']['terms'] ?? []);
            $expansionMetadata = $expansion;
        } catch (Throwable) {
            // The original question still provides a deterministic, safe search fallback.
            $expansionFailed = true;
        }

        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        $terms = $this->normalizeTerms($question, $expandedTerms);
        $retrievedCandidates = $this->findCandidates($terms, $user, $expectedPasswordFingerprint);
        if ($currentPage !== null) {
            $retrievedCandidates = array_values(array_filter(
                $retrievedCandidates,
                static fn(array $candidate): bool => (int) ($candidate['page_id'] ?? 0) !== (int) $currentPage['page_id']
            ));
        }
        $candidates = $this->mergeCandidates($linkedFileContext['candidates'], $currentPageCandidates, $retrievedCandidates);
        if ($candidates === []) {
            $emptyResult = [
                'question' => $question,
                'answer' => self::INSUFFICIENT_ANSWER,
                'answer_basis' => 'unavailable',
                'general_knowledge_fallback' => false,
                'sufficient' => false,
                'sources' => [],
                'candidate_count' => 0,
                'model' => (string) ($expansionMetadata['model'] ?? $this->client->model()),
                'reasoning_effort' => 'low',
                'response_id' => (string) ($expansionMetadata['response_id'] ?? ''),
                'input_tokens' => (int) ($expansionMetadata['input_tokens'] ?? 0),
                'output_tokens' => (int) ($expansionMetadata['output_tokens'] ?? 0),
                'latency_ms' => (int) ($expansionMetadata['latency_ms'] ?? 0),
                'degraded' => $expansionFailed,
            ];
            return $this->allowsGeneralKnowledgeFallback($question)
                ? $this->answerFromGeneralKnowledge(
                    $question,
                    $safetyIdentifier,
                    $conversationHistory,
                    $emptyResult,
                    $user,
                    $expectedPasswordFingerprint
                )
                : $emptyResult;
        }

        $result = $this->answerCandidates(
            $question,
            $candidates,
            $safetyIdentifier,
            $user,
            $expectedPasswordFingerprint,
            (int) ($expansionMetadata['latency_ms'] ?? 0),
            $expansionFailed,
            null,
            $conversationHistory,
            $currentPageImages,
            $currentPage !== null,
            $linkedFileContext['files']
        );
        if ($currentPage !== null) {
            $result['page_id'] = (int) $currentPage['page_id'];
            $result['page_title'] = $this->cleanOutput((string) $currentPage['page_title'], 240);
            $result['current_page_context'] = true;
        }
        if (!$result['sufficient'] && $this->allowsGeneralKnowledgeFallback($question)) {
            $result = $this->answerFromGeneralKnowledge(
                $question,
                $safetyIdentifier,
                $conversationHistory,
                $result,
                $user,
                $expectedPasswordFingerprint
            );
            if ($currentPage !== null) {
                $result['page_id'] = (int) $currentPage['page_id'];
                $result['page_title'] = $this->cleanOutput((string) $currentPage['page_title'], 240);
                $result['current_page_context'] = true;
            }
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function summarizePage(
        int $pageId,
        array $user,
        string $expectedPasswordFingerprint,
        array $conversationHistory = []
    ): array
    {
        if ($pageId < 1) {
            throw new InvalidArgumentException('要約するページを選択してください。');
        }

        $conversationHistory = $this->normalizeConversationHistory($conversationHistory);
        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        $page = $this->accessiblePage($pageId, $user, $expectedPasswordFingerprint);
        if ($page === null) {
            // Do not reveal whether an inaccessible page ID exists.
            throw new InvalidArgumentException('このページは要約できません。');
        }

        $pageTitle = $this->cleanOutput((string) $page['page_title'], 240);
        $question = "「{$pageTitle}」の内容を簡潔に要約してください。重要な目的、決定事項、手順、期限、注意点があれば整理して示してください。";
        $candidates = $this->canonicalPageCandidates($page);
        $imageContext = $this->canonicalPageImageContext($page);
        $candidates = array_merge($candidates, $imageContext['candidates']);
        $metadata = [
            'mode' => 'page-summary',
            'page_id' => $pageId,
            'page_title' => $pageTitle,
        ];
        if ($candidates === []) {
            return array_merge([
                'question' => 'このページを要約する',
                'answer' => self::PAGE_INSUFFICIENT_ANSWER,
                'answer_basis' => 'unavailable',
                'general_knowledge_fallback' => false,
                'sufficient' => false,
                'sources' => [],
                'candidate_count' => 0,
                'model' => $this->client->model(),
                'reasoning_effort' => 'low',
                'response_id' => '',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'latency_ms' => 0,
                'degraded' => false,
            ], $metadata);
        }

        $contentLength = $this->textLength((string) ($page['plain_text'] ?? ''));
        $effort = ($contentLength > 12000 || count($candidates) >= 6)
            ? 'high'
            : (($contentLength > 2500 || count($candidates) >= 2) ? 'medium' : 'low');
        $safetyIdentifier = 'ocu_' . substr(hash('sha256', $this->applicationUrl . ':' . (string) ($user['id'] ?? 0)), 0, 32);
        $result = $this->answerCandidates(
            $question,
            $candidates,
            $safetyIdentifier,
            $user,
            $expectedPasswordFingerprint,
            0,
            false,
            $effort,
            $conversationHistory,
            $imageContext['images'],
            true,
            [],
            'current_page_only'
        );
        $result['question'] = 'このページを要約する';
        $result['answer_basis'] = $result['sufficient'] ? 'current_page' : 'unavailable';
        $result['general_knowledge_fallback'] = false;
        if (!$result['sufficient']) {
            $result['answer'] = self::PAGE_INSUFFICIENT_ANSWER;
        }
        return array_merge($result, $metadata);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function answerCandidates(
        string $question,
        array $candidates,
        string $safetyIdentifier,
        array $requestUser,
        string $expectedPasswordFingerprint,
        int $priorLatencyMs = 0,
        bool $degraded = false,
        ?string $forcedEffort = null,
        array $conversationHistory = [],
        array $currentPageImages = [],
        bool $hasCurrentPageContext = false,
        array $linkedFiles = [],
        string $requestScope = 'registered_data'
    ): array {
        $context = $this->buildModelContext($candidates, $hasCurrentPageContext);
        $effort = $forcedEffort ?? ($linkedFiles !== [] ? 'medium' : $this->routeAnswerEffort($question, count($context)));
        try {
            $this->authenticatedUserForModelRequest($requestUser, $expectedPasswordFingerprint);
            $response = $this->client->answerSearch(
                $question,
                $context,
                $effort,
                $safetyIdentifier,
                $conversationHistory,
                $currentPageImages,
                $linkedFiles,
                $requestScope,
                $this->currentPageMetadata($candidates)
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('AI回答を生成できませんでした。時間をおいてもう一度お試しください。', 0, $exception);
        }

        $byPublicId = [];
        foreach ($candidates as $candidate) {
            $byPublicId[(string) $candidate['chunk_id']] = $candidate;
        }
        $selected = [];
        $seen = [];
        foreach (array_slice((array) ($response['data']['selected_chunk_ids'] ?? []), 0, 8) as $chunkId) {
            $chunkId = (string) $chunkId;
            if ($chunkId !== '' && isset($byPublicId[$chunkId]) && !isset($seen[$chunkId])) {
                $selected[] = $byPublicId[$chunkId];
                $seen[$chunkId] = true;
            }
        }

        $answer = $this->cleanOutput((string) ($response['data']['answer'] ?? ''), 6000);
        $sufficient = !empty($response['data']['sufficient']) && $answer !== '' && $selected !== [];
        $sources = $sufficient ? array_map(fn(array $candidate): array => [
            'document_id' => $candidate['document_id'],
            'chunk_id' => $candidate['chunk_id'],
            'page_id' => $candidate['page_id'],
            'file_id' => (int) ($candidate['file_id'] ?? 0),
            'source_scope' => (string) ($candidate['source_scope'] ?? ''),
            'source_hash' => (string) ($candidate['source_hash'] ?? ''),
            'page_fingerprint' => (string) ($candidate['page_fingerprint'] ?? ''),
            'chunk_key' => (string) ($candidate['chunk_key'] ?? ''),
            'prompt_version' => (string) ($candidate['prompt_version'] ?? ''),
            'title' => $candidate['page_title'],
            'summary' => $candidate['chunk_summary'],
            'url' => $candidate['url'],
        ], $selected) : [];

        return [
            'question' => $question,
            'answer' => $sufficient ? $answer : self::INSUFFICIENT_ANSWER,
            'answer_basis' => $sufficient ? ($requestScope === 'current_page_only' ? 'current_page' : 'registered_data') : 'unavailable',
            'general_knowledge_fallback' => false,
            'sufficient' => $sufficient,
            'sources' => $sources,
            'candidate_count' => count($candidates),
            'model' => (string) ($response['model'] ?? $this->client->model()),
            'reasoning_effort' => $effort,
            'response_id' => (string) ($response['response_id'] ?? ''),
            'input_tokens' => (int) ($response['input_tokens'] ?? 0),
            'output_tokens' => (int) ($response['output_tokens'] ?? 0),
            'latency_ms' => $priorLatencyMs + (int) ($response['latency_ms'] ?? 0),
            'degraded' => $degraded,
        ];
    }

    /** @param array<string, mixed> $baseResult @return array<string, mixed> */
    private function answerFromGeneralKnowledge(
        string $question,
        string $safetyIdentifier,
        array $conversationHistory,
        array $baseResult,
        array $requestUser,
        string $expectedPasswordFingerprint
    ): array {
        $effort = $this->routeAnswerEffort($question, 0);
        try {
            $this->authenticatedUserForModelRequest($requestUser, $expectedPasswordFingerprint);
            $response = $this->client->answerGeneralKnowledge($question, $effort, $safetyIdentifier, $conversationHistory);
        } catch (Throwable $exception) {
            throw new RuntimeException('一般知識からAI回答を生成できませんでした。時間をおいてもう一度お試しください。', 0, $exception);
        }
        $answer = $this->cleanOutput((string) ($response['data']['answer'] ?? ''), 6000);
        if ($answer === '') {
            return $baseResult;
        }
        $answer = $this->withoutLegacyGeneralKnowledgePrefix($answer);
        return array_merge($baseResult, [
            'mode' => 'general-knowledge',
            'answer' => $answer,
            'answer_basis' => 'general_knowledge',
            'general_knowledge_fallback' => true,
            'sufficient' => false,
            'sources' => [],
            'model' => (string) ($response['model'] ?? $this->client->model()),
            'reasoning_effort' => $effort,
            'response_id' => (string) ($response['response_id'] ?? ''),
            'input_tokens' => (int) ($baseResult['input_tokens'] ?? 0) + (int) ($response['input_tokens'] ?? 0),
            'output_tokens' => (int) ($baseResult['output_tokens'] ?? 0) + (int) ($response['output_tokens'] ?? 0),
            'latency_ms' => (int) ($baseResult['latency_ms'] ?? 0) + (int) ($response['latency_ms'] ?? 0),
        ]);
    }

    /** @param array<int, array<string, string>> $conversationHistory @return array<string, mixed>|null */
    private function answerBasisExplanation(string $question, array $conversationHistory): ?array
    {
        if (!$this->asksAboutPreviousAnswerBasis($question) || $conversationHistory === []) {
            return null;
        }
        $lastTurn = $conversationHistory[array_key_last($conversationHistory)];
        $basis = (string) ($lastTurn['answer_basis'] ?? '');
        $answers = [
            'general_knowledge' => self::GENERAL_KNOWLEDGE_SOURCE_EXPLANATION . 'アプリの登録データや表示中のページを根拠にした回答ではありません。',
            'registered_data' => 'アプリに登録されているデータを根拠に回答しました。回答欄の「参照元」から確認できます。',
            'current_page' => '回答時に表示されていたページの内容だけを根拠に回答しました。一般知識は混ぜていません。',
            'unavailable' => '直前の回答には、回答を支えられる十分な根拠がありませんでした。',
        ];
        if (!isset($answers[$basis])) {
            return null;
        }
        $sufficient = in_array($basis, ['registered_data', 'current_page'], true);
        return [
            'mode' => $basis === 'general_knowledge' ? 'general-knowledge' : ($basis === 'current_page' ? 'page-context' : 'search'),
            'question' => $question,
            'answer' => $answers[$basis],
            'answer_basis' => $basis,
            'general_knowledge_fallback' => $basis === 'general_knowledge',
            'sufficient' => $sufficient,
            'sources' => [],
            'candidate_count' => 0,
            'model' => $this->client->model(),
            'reasoning_effort' => 'low',
            'response_id' => '',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'latency_ms' => 0,
            'degraded' => false,
        ];
    }

    private function asksAboutPreviousAnswerBasis(string $question): bool
    {
        if (preg_match('/(?:根拠|情報源|出典|ソース|どこからの情報|何をもとに|何に基づ)/u', $question) === 1) {
            return preg_match('/(?:は|何|どこ|どの|教えて|示して|説明|確認|知りたい|ありますか|ですか|なの)/u', $question) === 1;
        }
        return preg_match('/(?:登録データ|一般知識|あなたの知識|AIの知識).*(?:ですか|なの|から回答)/u', $question) === 1;
    }

    private function withoutLegacyGeneralKnowledgePrefix(string $answer): string
    {
        $pattern = '/^' . preg_quote(self::GENERAL_KNOWLEDGE_PREFIX, '/') . '\s*/u';
        return trim(preg_replace($pattern, '', $answer) ?? $answer);
    }

    /** @param array<string, mixed>|null $currentPage */
    private function isCurrentPageOnlyRequest(string $question, ?array $currentPage): bool
    {
        $pageReference = '(?:このページ|現在のページ|現在[、,\s]*(?:表示されている|表示中の)ページ|今[、,\s]*(?:表示している|開いている|見ている)ページ|表示中のページ|開いているページ|見ているページ|(?:この|現在の|表示中の)画面|ページ内|この本文|表示されている内容|(?:この|現在の|表示中の)(?:URL|ＵＲＬ|アドレス))';
        if (preg_match('/' . $pageReference . '(?:に関係なく|を使わず|以外)/u', $question) === 1) {
            return false;
        }
        if (preg_match('/' . $pageReference . '/u', $question) === 1) {
            return true;
        }
        $pageTitle = trim((string) ($currentPage['page_title'] ?? ''));
        return $pageTitle !== ''
            && str_contains($question, $pageTitle)
            && preg_match('/ページ|内容|本文|要約|まとめ/u', $question) === 1;
    }

    private function allowsGeneralKnowledgeFallback(string $question): bool
    {
        if (preg_match('/(?:--|\/\*|\*\/|\bDROP\s+TABLE\b|\bUNION\s+SELECT\b|\bOR\s+1\s*=\s*1\b)/iu', $question) === 1) {
            return false;
        }
        return preg_match('#https?://\S+#iu', $question) !== 1
            || preg_match('/内容|要約|まとめ|読ん|ファイル|資料/u', $question) !== 1;
    }

    /** @param array<string, mixed> $user @return array<string, mixed>|null */
    private function accessiblePage(
        int $pageId,
        array $user,
        string $expectedPasswordFingerprint
    ): ?array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $snapshotUser = $this->snapshotUser($user, $expectedPasswordFingerprint);
            if ($snapshotUser === null) {
                if ($ownsSnapshot) {
                    $this->pdo->commit();
                }
                return null;
            }
            [$aclSql, $aclParams] = $this->aclSql($snapshotUser);
            $sql = <<<SQL
SELECT p.id AS page_id, p.title AS page_title, p.category, p.tags_json, p.manual_tags_json, p.blocks_json, p.plain_text, p.visibility,
       p.access_department, p.author_id, p.updated_at AS page_updated_at,
       author.department AS author_department
FROM pages p
JOIN users author ON author.id = p.author_id
WHERE p.id = ? AND p.archived_at IS NULL AND {$aclSql}
LIMIT 1
SQL;
            $statement = $this->pdo->prepare($sql);
            $statement->execute(array_merge([$pageId], $aclParams));
            $page = $statement->fetch();
            $result = null;
            if (is_array($page) && $this->canViewCandidate($page, $snapshotUser)) {
                // Materialize every database/file-derived prompt input in the
                // same snapshot as the body and ACL. Later prompt construction
                // must not mix this page with a newer RAG generation or image.
                $page['visible_tags'] = $this->visibleTags($page);
                $page['page_fingerprint'] = aiPageContentFingerprint($this->pdo, $page);
                $page['materialized_image_context'] = $this->materializeCanonicalPageImageContext($page);
                $result = $page;
            }
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $user @return array<string, mixed>|null */
    private function snapshotUser(array $user, string $expectedPasswordFingerprint): ?array
    {
        return authenticatedApplicationUserForReadSnapshot(
            $this->pdo,
            $user,
            $expectedPasswordFingerprint
        );
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    private function authenticatedUserForModelRequest(
        array $user,
        string $expectedPasswordFingerprint
    ): array {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $authenticated = $this->snapshotUser($user, $expectedPasswordFingerprint);
            if ($authenticated === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $authenticated;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $page @return array<int, string> */
    private function visibleTags(array $page): array
    {
        if (isset($page['visible_tags']) && is_array($page['visible_tags'])) {
            return array_values(array_map('strval', $page['visible_tags']));
        }
        $pageId = (int) ($page['page_id'] ?? $page['id'] ?? 0);
        if ($pageId > 0 && function_exists('visiblePageTags')) {
            return visiblePageTags($this->pdo, $pageId, $page);
        }
        $manual = json_decode((string) ($page['manual_tags_json'] ?? ''), true);
        if (!is_array($manual)) {
            $manual = json_decode((string) ($page['tags_json'] ?? '[]'), true);
        }
        return array_values(array_map('strval', is_array($manual) ? $manual : []));
    }

    /** @param array<string, mixed> $page @return array<int, array<string, mixed>> */
    private function canonicalPageCandidates(array $page): array
    {
        $content = trim((string) ($page['plain_text'] ?? ''));
        if ($content === '') {
            $content = '（このページの本文は空です）';
        }
        $pageId = (int) $page['page_id'];
        $pageTitle = (string) $page['page_title'];
        $tags = $this->visibleTags($page);
        $length = $this->textLength($content);
        $candidates = [];
        for ($offset = 0, $index = 0; $offset < $length && $index < 30; $offset += 3500, $index++) {
            $segment = trim($this->sliceTextRange($content, $offset, 3500));
            if ($segment === '') {
                continue;
            }
            $chunkKey = hash('sha256', implode(':', ['canonical', $pageId, (string) ($page['page_updated_at'] ?? ''), $index]));
            $candidates[] = [
                'internal_id' => $index + 1,
                'document_id' => 'oc-page-' . $pageId,
                'chunk_id' => 'oc-chunk-' . substr(hash('sha256', $pageId . ':' . $chunkKey), 0, 32),
                'page_id' => $pageId,
                'page_title' => $pageTitle,
                'chunk_title' => '正本本文 ' . ($index + 1),
                'chunk_summary' => '現在の正本ページ本文から作成した要約用チャンクです。',
                'content' => $segment,
                'tags' => $tags,
                'source_scope' => 'current_page_full_text',
                'page_fingerprint' => (string) ($page['page_fingerprint'] ?? ''),
                'score' => 1000 - $index,
                'url' => $this->applicationUrl . '/#page-' . $pageId,
            ];
        }
        return $candidates;
    }

    /**
     * @param array<string, mixed> $page
     * @return array{candidates: array<int, array<string, mixed>>, images: array<int, array<string, string>>}
     */
    private function canonicalPageImageContext(array $page): array
    {
        $context = $page['materialized_image_context'] ?? null;
        if (!is_array($context)
            || !isset($context['candidates'], $context['images'])
            || !is_array($context['candidates'])
            || !is_array($context['images'])) {
            // Current-page image context is security-sensitive. It must have
            // been materialized by accessiblePage() before its read snapshot
            // was committed; a later database read could mix generations.
            return ['candidates' => [], 'images' => []];
        }
        return [
            'candidates' => array_values($context['candidates']),
            'images' => array_values($context['images']),
        ];
    }

    /**
     * @param array<string, mixed> $page
     * @return array{candidates: array<int, array<string, mixed>>, images: array<int, array<string, string>>}
     */
    private function materializeCanonicalPageImageContext(array $page): array
    {
        $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
        if (!is_array($blocks)) {
            return ['candidates' => [], 'images' => []];
        }
        $imageBlocks = [];
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                continue;
            }
            $fileId = (int) ($block['file_id'] ?? 0);
            if ($fileId > 0 && !isset($imageBlocks[$fileId])) {
                $imageBlocks[$fileId] = $this->cleanOutput(strip_tags((string) ($block['content'] ?? '')), 500);
            }
        }
        $maxImages = max(0, min(6, (int) (getenv('OPENCONCEPT_AI_CURRENT_PAGE_MAX_IMAGES') ?: 4)));
        if ($imageBlocks === [] || $maxImages === 0 || !function_exists('uploadStoragePath')) {
            return ['candidates' => [], 'images' => []];
        }

        $fileIds = array_slice(array_keys($imageBlocks), 0, $maxImages * 2);
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $statement = $this->pdo->prepare("SELECT id, original_name, stored_name, mime_type, size_bytes FROM files WHERE page_id = ? AND category = 'image' AND id IN ({$placeholders}) ORDER BY id");
        $statement->execute(array_merge([(int) $page['page_id']], $fileIds));
        $rows = $statement->fetchAll();
        $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
        $perImageLimit = max(262144, min(8388608, (int) (getenv('OPENCONCEPT_AI_CURRENT_PAGE_MAX_IMAGE_BYTES') ?: 5242880)));
        $totalLimit = max($perImageLimit, min(20971520, (int) (getenv('OPENCONCEPT_AI_CURRENT_PAGE_MAX_IMAGE_TOTAL_BYTES') ?: 10485760)));
        $totalBytes = 0;
        $candidates = [];
        $images = [];
        foreach ($rows as $row) {
            if (count($images) >= $maxImages) {
                break;
            }
            $mime = strtolower((string) ($row['mime_type'] ?? ''));
            $storedName = (string) ($row['stored_name'] ?? '');
            $declaredSize = (int) ($row['size_bytes'] ?? 0);
            if (!in_array($mime, $allowedMimes, true) || $storedName === '' || basename($storedName) !== $storedName || $declaredSize < 1 || $declaredSize > $perImageLimit) {
                continue;
            }
            $path = uploadStoragePath() . DIRECTORY_SEPARATOR . $storedName;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $data = file_get_contents($path);
            if ($data === false || $data === '' || strlen($data) > $perImageLimit || $totalBytes + strlen($data) > $totalLimit) {
                continue;
            }
            if (function_exists('getimagesizefromstring') && @getimagesizefromstring($data) === false) {
                continue;
            }
            $fileId = (int) $row['id'];
            $reference = 'current-page-image-' . (count($images) + 1);
            $chunkId = 'oc-image-' . substr(hash('sha256', (string) $page['page_id'] . ':' . $fileId . ':' . $storedName), 0, 32);
            $caption = (string) ($imageBlocks[$fileId] ?? '');
            $fileName = $this->cleanOutput((string) ($row['original_name'] ?? 'ページ画像'), 240);
            $images[] = [
                'reference' => $reference,
                'chunk_id' => $chunkId,
                'filename' => $fileName,
                'data_url' => 'data:' . $mime . ';base64,' . base64_encode($data),
            ];
            $candidates[] = [
                'internal_id' => 1000000 + $fileId,
                'document_id' => 'oc-page-' . (int) $page['page_id'],
                'chunk_id' => $chunkId,
                'page_id' => (int) $page['page_id'],
                'page_title' => (string) $page['page_title'],
                'chunk_title' => 'ページ画像 ' . (count($images)),
                'chunk_summary' => '現在表示中の正本ページに配置された画像です。',
                'content' => "画像参照: {$reference}\nファイル名: {$fileName}" . ($caption !== '' ? "\n説明: {$caption}" : ''),
                'tags' => [],
                'source_scope' => 'current_page_image',
                'page_fingerprint' => (string) ($page['page_fingerprint'] ?? ''),
                'file_id' => $fileId,
                'image_reference' => $reference,
                'score' => 900 - count($images),
                'url' => $this->applicationUrl . '/#page-' . (int) $page['page_id'],
            ];
            $totalBytes += strlen($data);
        }
        return ['candidates' => $candidates, 'images' => $images];
    }

    /**
     * Resolve only OpenConcept file-content links found in the question. The server-side
     * file is read after applying the same page ACL used by the download endpoint.
     *
     * @param array<string, mixed> $user
     * @return array{candidates: array<int, array<string, mixed>>, files: array<int, array<string, string>>}
     */
    private function linkedFileContext(
        string $question,
        array $user,
        string $expectedPasswordFingerprint
    ): array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $snapshotUser = $this->snapshotUser($user, $expectedPasswordFingerprint);
            $result = $snapshotUser === null
                ? ['candidates' => [], 'files' => []]
                : $this->linkedFileContextInSnapshot($question, $snapshotUser);
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $user
     * @return array{candidates: array<int, array<string, mixed>>, files: array<int, array<string, string>>}
     */
    private function linkedFileContextInSnapshot(string $question, array $user): array
    {
        $fileIds = $this->linkedFileIds($question);
        $maxFiles = max(1, min(3, (int) (getenv('OPENCONCEPT_AI_LINKED_FILE_LIMIT') ?: 2)));
        $openAiFileLimit = 50 * 1024 * 1024 - 1;
        $perFileLimit = max(1048576, min($openAiFileLimit, (int) (getenv('OPENCONCEPT_AI_LINKED_FILE_MAX_BYTES') ?: 15728640)));
        $totalLimit = max($perFileLimit, min($openAiFileLimit, (int) (getenv('OPENCONCEPT_AI_LINKED_FILE_MAX_TOTAL_BYTES') ?: 25165824)));
        $pdfDetail = strtolower(trim((string) (getenv('OPENCONCEPT_AI_LINKED_PDF_DETAIL') ?: 'low')));
        if (!in_array($pdfDetail, ['auto', 'low', 'high'], true)) {
            $pdfDetail = 'low';
        }
        if ($fileIds === [] || !function_exists('uploadStoragePath')) {
            return ['candidates' => [], 'files' => []];
        }

        $accepted = [
            'pdf' => ['application/pdf' => ['pdf']],
            'word' => [
                'application/msword' => ['doc'],
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            ],
            'excel' => [
                'application/vnd.ms-excel' => ['xls'],
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
            ],
            'markdown' => [
                'text/plain' => ['md', 'markdown'],
                'text/markdown' => ['md', 'markdown'],
                'text/x-markdown' => ['md', 'markdown'],
            ],
            'text' => ['text/plain' => ['txt']],
        ];
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT f.id, f.original_name, f.stored_name, f.mime_type, f.category, f.size_bytes, f.uploaded_by, f.page_id,
       p.title AS page_title, p.archived_at AS page_archived_at
FROM files f
LEFT JOIN pages p ON p.id = f.page_id
WHERE f.id = ?
LIMIT 1
SQL);

        $candidates = [];
        $files = [];
        $totalBytes = 0;
        foreach (array_slice($fileIds, 0, $maxFiles * 3) as $fileId) {
            if (count($files) >= $maxFiles) {
                break;
            }
            $statement->execute([$fileId]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                continue;
            }
            $pageId = $row['page_id'] === null ? 0 : (int) $row['page_id'];
            if (!canViewFile($this->pdo, $user, $row)) {
                continue;
            }

            $category = strtolower((string) ($row['category'] ?? ''));
            $mimeType = strtolower((string) ($row['mime_type'] ?? ''));
            $originalName = $this->cleanOutput((string) ($row['original_name'] ?? ''), 240);
            $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if (!isset($accepted[$category][$mimeType]) || !in_array($extension, $accepted[$category][$mimeType], true)) {
                continue;
            }

            $storedName = (string) ($row['stored_name'] ?? '');
            $declaredSize = (int) ($row['size_bytes'] ?? 0);
            if ($storedName === '' || basename($storedName) !== $storedName || $declaredSize < 1 || $declaredSize > $perFileLimit) {
                continue;
            }
            $path = uploadStoragePath() . DIRECTORY_SEPARATOR . $storedName;
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $actualSize = filesize($path);
            if ($actualSize === false || $actualSize < 1 || $actualSize > $perFileLimit || $totalBytes + $actualSize > $totalLimit) {
                continue;
            }
            $data = file_get_contents($path);
            if ($data === false || strlen($data) !== $actualSize) {
                continue;
            }

            $reference = 'linked-file-' . (count($files) + 1);
            $chunkId = 'oc-file-' . substr(hash('sha256', $fileId . ':' . $storedName . ':' . $actualSize), 0, 32);
            $pageTitle = $originalName !== '' ? $originalName : ('ファイル #' . $fileId);
            $files[] = [
                'reference' => $reference,
                'chunk_id' => $chunkId,
                'filename' => $pageTitle,
                'mime_type' => $mimeType,
                'data_url' => 'data:' . $mimeType . ';base64,' . base64_encode($data),
                'detail' => $mimeType === 'application/pdf' ? $pdfDetail : '',
            ];
            $candidates[] = [
                'internal_id' => 2000000 + $fileId,
                'document_id' => 'oc-file-' . $fileId,
                'chunk_id' => $chunkId,
                'page_id' => $pageId,
                'file_id' => $fileId,
                'page_title' => $pageTitle,
                'chunk_title' => 'リンクされたファイル',
                'chunk_summary' => '質問内のリンクから権限を確認して添付したファイルです。',
                'content' => "添付ファイル参照: {$reference}\nファイル名: {$pageTitle}",
                'tags' => [],
                'source_scope' => 'linked_file',
                'file_reference' => $reference,
                'score' => 2000 - count($files),
                'url' => $this->applicationUrl . '/api.php?action=file-content&id=' . $fileId,
            ];
            $totalBytes += $actualSize;
        }
        return ['candidates' => $candidates, 'files' => $files];
    }

    /** @return array<int, int> */
    private function linkedFileIds(string $question): array
    {
        $question = html_entity_decode($question, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $matchCount = preg_match_all('~(?:https?://[^\s<>"\']+)?/?api\.php\?[^\s<>"\']+~iu', $question, $matches);
        if ($matchCount === false || $matchCount < 1) {
            return [];
        }
        $ids = [];
        foreach ((array) ($matches[0] ?? []) as $matchedUrl) {
            $matchedUrl = str_replace('&amp;', '&', (string) $matchedUrl);
            $linkedHost = strtolower((string) (parse_url($matchedUrl, PHP_URL_HOST) ?? ''));
            $applicationHost = strtolower((string) (parse_url($this->applicationUrl, PHP_URL_HOST) ?? ''));
            if ($linkedHost !== '' && ($applicationHost === '' || $linkedHost !== $applicationHost)) {
                continue;
            }
            $query = parse_url($matchedUrl, PHP_URL_QUERY);
            if (!is_string($query) || $query === '') {
                continue;
            }
            parse_str($query, $parameters);
            if (($parameters['action'] ?? '') !== 'file-content') {
                continue;
            }
            $fileId = (int) ($parameters['id'] ?? 0);
            if ($fileId > 0 && !in_array($fileId, $ids, true)) {
                $ids[] = $fileId;
            }
        }
        return $ids;
    }

    /** @param array<int, array<string, mixed>> ...$groups @return array<int, array<string, mixed>> */
    private function mergeCandidates(array ...$groups): array
    {
        $merged = [];
        $seen = [];
        foreach ($groups as $group) {
            foreach ($group as $candidate) {
                $chunkId = (string) ($candidate['chunk_id'] ?? '');
                if ($chunkId === '' || isset($seen[$chunkId])) {
                    continue;
                }
                $seen[$chunkId] = true;
                $merged[] = $candidate;
            }
        }
        return $merged;
    }

    private function cleanQuestion(string $question): string
    {
        $question = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question) ?? '');
        $length = $this->textLength($question);
        if ($length < 2) {
            throw new InvalidArgumentException('AI検索には2文字以上の質問を入力してください。');
        }
        if ($length > $this->questionLimit) {
            throw new InvalidArgumentException("質問は{$this->questionLimit}文字以内で入力してください。");
        }
        return $question;
    }

    /** @param array<int, mixed> $expanded @return array<int, string> */
    private function normalizeTerms(string $question, array $expanded): array
    {
        $raw = array_merge([$question], $expanded);
        $terms = [];
        $seen = [];
        foreach ($raw as $value) {
            $value = trim((string) $value);
            $value = preg_replace('/[^\p{L}\p{N}\p{M}\s\-]+/u', ' ', $value) ?? '';
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
            if ($value === '' || $this->textLength($value) < 2) {
                continue;
            }
            $value = $this->sliceText($value, 80);
            $normalized = $this->lowerText($value);
            if (!isset($seen[$normalized])) {
                $terms[] = $value;
                $seen[$normalized] = true;
            }
            if (count($terms) >= 16) {
                break;
            }
        }
        if ($terms === []) {
            $terms[] = $this->sliceText($question, 80);
        }
        return $terms;
    }

    /**
     * @param array<int, string> $terms
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function findCandidates(
        array $terms,
        array $user,
        string $expectedPasswordFingerprint
    ): array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $snapshotUser = $this->snapshotUser($user, $expectedPasswordFingerprint);
            $result = $snapshotUser === null
                ? []
                : $this->findCandidatesInSnapshot($terms, $snapshotUser);
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<int, string> $terms @param array<string, mixed> $user @return array<int, array<string, mixed>> */
    private function findCandidatesInSnapshot(array $terms, array $user): array
    {
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $rows = [];
        if ($driver === 'mysql') {
            $rows = $this->fullTextCandidates($terms, $user);
        }
        if (count($rows) < $this->candidateLimit) {
            $rows = array_merge($rows, $this->fallbackCandidates($terms, $user));
        }

        $candidates = [];
        $seen = [];
        foreach ($rows as $row) {
            $internalId = (int) ($row['id'] ?? 0);
            if ($internalId < 1 || isset($seen[$internalId]) || !$this->canViewCandidate($row, $user)) {
                continue;
            }
            $seen[$internalId] = true;
            $pageId = (int) $row['page_id'];
            $chunkKey = (string) $row['chunk_key'];
            $tags = $this->visibleTags($row);
            $score = max(0.0, (float) ($row['fulltext_score'] ?? 0));
            $titleText = $this->lowerText((string) $row['page_title'] . ' ' . (string) $row['chunk_title']);
            $summaryText = $this->lowerText((string) $row['chunk_summary']);
            $tagText = $this->lowerText(implode(' ', $tags));
            foreach ($terms as $term) {
                $needle = $this->lowerText($term);
                if ($needle !== '' && str_contains($titleText, $needle)) {
                    $score += 2.0;
                }
                if ($needle !== '' && str_contains($tagText, $needle)) {
                    $score += 1.2;
                }
                if ($needle !== '' && str_contains($summaryText, $needle)) {
                    $score += 0.5;
                }
            }
            $updatedAt = strtotime((string) ($row['page_updated_at'] ?? '')) ?: 0;
            if ($updatedAt > 0 && $updatedAt >= time() - 90 * 86400) {
                $score += 0.2;
            }
            $candidates[] = [
                'internal_id' => $internalId,
                'document_id' => 'oc-page-' . $pageId,
                'chunk_id' => 'oc-chunk-' . substr(hash('sha256', $pageId . ':' . $chunkKey), 0, 32),
                'page_id' => $pageId,
                'page_title' => (string) $row['page_title'],
                'chunk_title' => (string) $row['chunk_title'],
                'chunk_summary' => $this->cleanOutput((string) $row['chunk_summary'], 1000),
                'content' => (string) $row['content'],
                'tags' => $tags,
                'source_scope' => 'retrieved_chunk',
                'source_hash' => (string) ($row['source_hash'] ?? ''),
                'chunk_key' => $chunkKey,
                'prompt_version' => (string) ($row['prompt_version'] ?? ''),
                'score' => $score,
                'url' => $this->applicationUrl . '/#page-' . $pageId,
            ];
        }

        usort($candidates, static fn(array $left, array $right): int => $right['score'] <=> $left['score'] ?: $left['internal_id'] <=> $right['internal_id']);
        return array_slice($candidates, 0, $this->candidateLimit);
    }

    /** @param array<int, string> $terms @param array<string, mixed> $user @return array<int, array<string, mixed>> */
    private function fullTextCandidates(array $terms, array $user): array
    {
        $query = $this->sliceText(implode(' ', $terms), 800);
        if ($query === '') {
            return [];
        }
        [$aclSql, $aclParams] = $this->aclSql($user);
        $limit = $this->candidateLimit * 3;
        $sql = <<<SQL
SELECT c.id, c.page_id, c.chunk_key, c.title AS chunk_title, c.content, c.chunk_summary,
       c.source_hash, c.prompt_version, p.title AS page_title, p.tags_json, p.manual_tags_json, p.visibility, p.access_department, p.author_id,
       p.updated_at AS page_updated_at, author.department AS author_department,
       MATCH(c.title, c.chunk_summary, c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE) AS fulltext_score
FROM rag_chunks c
JOIN pages p ON p.id = c.page_id
JOIN users author ON author.id = p.author_id
JOIN rag_source_documents d ON d.page_id = c.page_id AND d.source_hash = c.source_hash AND d.is_current = 1 AND d.source_schema_version = ?
WHERE c.is_active = 1 AND c.prompt_version = ? AND p.status = 'published' AND p.archived_at IS NULL
  AND {$aclSql}
  AND MATCH(c.title, c.chunk_summary, c.search_text) AGAINST (? IN NATURAL LANGUAGE MODE)
ORDER BY fulltext_score DESC, p.updated_at DESC, c.id ASC
LIMIT {$limit}
SQL;
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(array_merge(
                [$query, RagPipeline::SOURCE_SCHEMA_VERSION, RagPipeline::PROMPT_VERSION],
                $aclParams,
                [$query]
            ));
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'search_text') || str_contains($exception->getMessage(), 'FULLTEXT')) {
                throw new RuntimeException('AI検索用のMySQL索引が未適用です。', 0, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<int, string> $terms @param array<string, mixed> $user @return array<int, array<string, mixed>> */
    private function fallbackCandidates(array $terms, array $user): array
    {
        $terms = array_slice($terms, 0, 6);
        [$aclSql, $aclParams] = $this->aclSql($user);
        $conditions = [];
        $termParams = [];
        foreach ($terms as $term) {
            $pattern = '%' . $term . '%';
            $conditions[] = '(c.title LIKE ? OR c.chunk_summary LIKE ? OR c.search_text LIKE ? OR p.title LIKE ?)';
            array_push($termParams, $pattern, $pattern, $pattern, $pattern);
        }
        if ($conditions === []) {
            return [];
        }
        $limit = $this->candidateLimit * 3;
        $termSql = implode(' OR ', $conditions);
        $sql = <<<SQL
SELECT c.id, c.page_id, c.chunk_key, c.title AS chunk_title, c.content, c.chunk_summary,
       c.source_hash, c.prompt_version, p.title AS page_title, p.tags_json, p.manual_tags_json, p.visibility, p.access_department, p.author_id,
       p.updated_at AS page_updated_at, author.department AS author_department, 0 AS fulltext_score
FROM rag_chunks c
JOIN pages p ON p.id = c.page_id
JOIN users author ON author.id = p.author_id
JOIN rag_source_documents d ON d.page_id = c.page_id AND d.source_hash = c.source_hash AND d.is_current = 1 AND d.source_schema_version = ?
WHERE c.is_active = 1 AND c.prompt_version = ? AND p.status = 'published' AND p.archived_at IS NULL
  AND {$aclSql}
  AND ({$termSql})
ORDER BY p.updated_at DESC, c.id ASC
LIMIT {$limit}
SQL;
        try {
            $statement = $this->pdo->prepare($sql);
            $statement->execute(array_merge(
                [RagPipeline::SOURCE_SCHEMA_VERSION, RagPipeline::PROMPT_VERSION],
                $aclParams,
                $termParams
            ));
            return $statement->fetchAll();
        } catch (PDOException $exception) {
            if (str_contains($exception->getMessage(), 'search_text')) {
                throw new RuntimeException('AI検索用のDB移行が未適用です。', 0, $exception);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $user @return array{0: string, 1: array<int, mixed>} */
    private function aclSql(array $user): array
    {
        $administrator = in_array((string) ($user['role'] ?? ''), ['admin', 'content_admin'], true) ? 1 : 0;
        $userId = (int) ($user['id'] ?? 0);
        $department = (string) ($user['department'] ?? '');
        return [
            "(? = 1 OR p.author_id = ? OR p.visibility = 'company'
              OR (p.visibility = 'department' AND (
                    EXISTS (
                        SELECT 1 FROM page_access_departments pad
                        WHERE pad.page_id = p.id AND pad.department = ?
                    )
                    OR (
                        NOT EXISTS (SELECT 1 FROM page_access_departments pad_any WHERE pad_any.page_id = p.id)
                        AND COALESCE(NULLIF(p.access_department, ''), author.department) = ?
                    )
              ))
              OR (p.visibility = 'group' AND EXISTS (
                    SELECT 1 FROM page_access_members pam WHERE pam.page_id = p.id AND pam.user_id = ?
              )))",
            [$administrator, $userId, $department, $department, $userId],
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $user */
    private function canViewCandidate(array $row, array $user): bool
    {
        $pageId = (int) ($row['page_id'] ?? $row['id'] ?? 0);
        return $pageId > 0 && canViewPage($this->pdo, $user, ['id' => $pageId]);
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<int, array<string, mixed>> */
    private function buildModelContext(array $candidates, bool $hasCurrentPageContext = false): array
    {
        $budget = $hasCurrentPageContext
            ? max(24000, min(180000, (int) (getenv('OPENCONCEPT_AI_CURRENT_PAGE_MAX_CONTEXT_CHARS') ?: 140000)))
            : max(8000, min(60000, (int) (getenv('OPENCONCEPT_AI_SEARCH_MAX_CONTEXT_CHARS') ?: 24000)));
        $context = [];
        foreach ($candidates as $candidate) {
            if ($budget < 400) {
                break;
            }
            $content = $this->sliceText((string) $candidate['content'], min(4000, $budget));
            $budget -= $this->textLength($content);
            $context[] = [
                'document_id' => $candidate['document_id'],
                'chunk_id' => $candidate['chunk_id'],
                'title' => $candidate['page_title'],
                'section' => $candidate['chunk_title'],
                'summary' => $candidate['chunk_summary'],
                'tags' => $candidate['tags'],
                'source_scope' => (string) ($candidate['source_scope'] ?? 'retrieved_chunk'),
                'image_reference' => (string) ($candidate['image_reference'] ?? ''),
                'file_reference' => (string) ($candidate['file_reference'] ?? ''),
                'file_id' => (int) ($candidate['file_id'] ?? 0),
                'url' => (string) ($candidate['url'] ?? ''),
                'content' => $content,
            ];
        }
        return $context;
    }

    /** @param array<int, array<string, mixed>> $candidates @return array<string, mixed> */
    private function currentPageMetadata(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (!in_array((string) ($candidate['source_scope'] ?? ''), ['current_page_full_text', 'current_page_image'], true)) {
                continue;
            }
            return [
                'id' => (int) ($candidate['page_id'] ?? 0),
                'title' => $this->cleanOutput((string) ($candidate['page_title'] ?? ''), 240),
                'url' => $this->cleanOutput((string) ($candidate['url'] ?? ''), 1000),
            ];
        }
        return [];
    }

    private function routeAnswerEffort(string $question, int $candidateCount): string
    {
        $length = $this->textLength($question);
        $complex = preg_match('/比較|違い|理由|影響|手順|横断|まとめ|compare|difference|why|impact|procedure/iu', $question) === 1;
        if (($length > 320 && $candidateCount >= 6) || ($complex && $candidateCount >= 9)) {
            return 'high';
        }
        if ($length > 60 || $candidateCount >= 4 || $complex) {
            return 'medium';
        }
        return 'low';
    }

    /** @param array<int, mixed> $history @return array<int, array{question: string, answer: string, answer_basis: string}> */
    private function normalizeConversationHistory(array $history): array
    {
        $normalized = [];
        $budget = 16000;
        foreach (array_reverse(array_slice($history, -12)) as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $question = $this->cleanOutput((string) ($turn['question'] ?? ''), 4000);
            $answer = $this->cleanOutput((string) ($turn['answer'] ?? ''), 6000);
            if ($question === '' || $answer === '') {
                continue;
            }
            $length = $this->textLength($question) + $this->textLength($answer);
            if ($normalized !== [] && $length > $budget) {
                break;
            }
            if ($length > $budget) {
                $answer = $this->sliceText($answer, max(1, $budget - $this->textLength($question)));
            }
            $answerBasis = (string) ($turn['answer_basis'] ?? '');
            if (!in_array($answerBasis, ['general_knowledge', 'registered_data', 'current_page', 'unavailable'], true)) {
                $answerBasis = str_starts_with($answer, self::GENERAL_KNOWLEDGE_PREFIX) ? 'general_knowledge' : '';
            }
            $normalized[] = [
                'question' => $question,
                'answer' => $this->withoutLegacyGeneralKnowledgePrefix($answer),
                'answer_basis' => $answerBasis,
            ];
            $budget -= min($length, $budget);
            if ($budget <= 0) {
                break;
            }
        }
        return array_reverse($normalized);
    }

    private function cleanOutput(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return $this->sliceText($value, $max);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function sliceText(string $value, int $length): string
    {
        return $this->sliceTextRange($value, 0, $length);
    }

    private function sliceTextRange(string $value, int $offset, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, $offset, $length);
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($value, $offset, $length, 'UTF-8');
            return $result === false ? '' : $result;
        }
        return substr($value, $offset, $length);
    }

    private function lowerText(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
