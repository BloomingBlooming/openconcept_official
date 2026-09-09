<?php

declare(strict_types=1);

final class AiChatRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<int, array<string, mixed>> */
    public function listForUser(int $userId, string $expectedPasswordFingerprint, int $limit = 50): array
    {
        return $this->consistentRead(function () use ($userId, $expectedPasswordFingerprint, $limit): array {
            $limit = max(1, min(100, $limit));
            $user = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            if ($user === null) {
                return [];
            }
            $statement = $this->pdo->prepare(<<<SQL
SELECT id, title, turn_count, last_page_id, last_page_title, created_at, updated_at
FROM ai_conversations
WHERE user_id = ? AND archived_at IS NULL
ORDER BY updated_at DESC, id DESC
LIMIT {$limit}
SQL);
            $statement->execute([$userId]);
            return array_map(
                fn(array $row): array => $this->authorizedConversationResult($row, $user),
                $statement->fetchAll()
            );
        });
    }

    /** @return array<string, mixed> */
    public function createForUser(int $userId, string $expectedPasswordFingerprint): array
    {
        beginPageWriteTransaction($this->pdo);
        try {
            $actor = lockedAuthenticatedApplicationUserForWrite(
                $this->pdo,
                ['id' => $userId],
                $expectedPasswordFingerprint
            );
            if ($actor === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }
            $statement = $this->pdo->prepare("INSERT INTO ai_conversations (user_id, title) VALUES (?, '新しいチャット')");
            $statement->execute([$userId]);
            $conversationId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $this->conversationForUser($conversationId, $userId, $expectedPasswordFingerprint);
    }

    /** @return array<string, mixed> */
    public function conversationForUser(
        int $conversationId,
        int $userId,
        string $expectedPasswordFingerprint
    ): array
    {
        return $this->consistentRead(function () use ($conversationId, $userId, $expectedPasswordFingerprint): array {
            $row = $this->conversationRowForUser($conversationId, $userId);
            $user = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            if ($user === null) {
                throw new AiValidationException('ai.openChatError');
            }
            return $this->authorizedConversationResult($row, $user);
        });
    }

    /** @return array<string, mixed> */
    private function conversationRowForUser(int $conversationId, int $userId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, title, turn_count, last_page_id, last_page_title, created_at, updated_at
FROM ai_conversations
WHERE id = ? AND user_id = ? AND archived_at IS NULL
LIMIT 1
SQL);
        $statement->execute([$conversationId, $userId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new AiValidationException('ai.openChatError');
        }
        return $row;
    }

    /** @return array{conversation: array<string, mixed>, turns: array<int, array<string, mixed>>} */
    public function getForUser(int $conversationId, int $userId, string $expectedPasswordFingerprint): array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $conversationRow = $this->conversationRowForUser($conversationId, $userId);
            $user = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            if ($user === null) {
                throw new AiValidationException('ai.openChatError');
            }
            $turnRows = $this->turnRows($conversationId);
            $visibleTurnRows = array_values(array_filter(
                $turnRows,
                fn(array $row): bool => $this->turnIsCurrentlyAccessible($row, $user)
            ));
            $result = [
                'conversation' => $this->authorizedConversationResult($conversationRow, $user, $turnRows, $visibleTurnRows),
                'turns' => array_map(fn(array $row): array => $this->turnResult($row), $visibleTurnRows),
            ];
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

    /** @return array<int, array{question: string, answer: string, answer_basis: string}> */
    public function historyForModel(
        int $conversationId,
        int $userId,
        string $expectedPasswordFingerprint,
        int $limit = 8,
        int $characterLimit = 16000
    ): array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $this->conversationRowForUser($conversationId, $userId);
            $user = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            if ($user === null) {
                throw new AiValidationException('ai.openChatError');
            }
            $limit = max(1, min(20, $limit));
            $characterLimit = max(2000, min(40000, $characterLimit));
            $fetchLimit = min(200, $limit * 10);
            $statement = $this->pdo->prepare(<<<SQL
SELECT id, turn_index, mode, question, answer, page_id, page_title, sufficient, sources_json,
       candidate_count, model, reasoning_effort, response_id, input_tokens, output_tokens,
       latency_ms, degraded, review_status, created_at
FROM ai_chat_turns
WHERE conversation_id = ?
ORDER BY turn_index DESC, id DESC
LIMIT {$fetchLimit}
SQL);
            $statement->execute([$conversationId]);
            $history = [];
            $used = 0;
            foreach ($statement->fetchAll() as $row) {
                if (!$this->turnIsCurrentlyAccessible($row, $user)) {
                    continue;
                }
                $question = (string) $row['question'];
                if (in_array((string) ($row['mode'] ?? ''), ['page-summary', 'page-context', 'page-action'], true) && trim((string) ($row['page_title'] ?? '')) !== '') {
                    $question = '「' . trim((string) $row['page_title']) . '」: ' . $question;
                }
                $storedAnswer = (string) $row['answer'];
                $answerBasis = $this->answerBasis((string) $row['mode'], (bool) $row['sufficient'], $storedAnswer);
                $answer = $this->withoutLegacyGeneralKnowledgePrefix($storedAnswer);
                $length = $this->textLength($question) + $this->textLength($answer);
                if ($history !== [] && $used + $length > $characterLimit) {
                    break;
                }
                $history[] = [
                    'question' => $this->sliceText($question, 4000),
                    'answer' => $this->sliceText($answer, 6000),
                    'answer_basis' => $answerBasis,
                ];
                $used += $length;
                if (count($history) >= $limit) {
                    break;
                }
            }
            $result = array_reverse($history);
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
     * @param array<string, mixed> $result
     * @return array{conversation: array<string, mixed>, turn: array<string, mixed>}
     */
    public function appendTurn(
        int $conversationId,
        int $userId,
        array $result,
        string $expectedPasswordFingerprint
    ): array
    {
        beginPageWriteTransaction($this->pdo);
        try {
            $actor = lockedAuthenticatedApplicationUserForWrite(
                $this->pdo,
                ['id' => $userId],
                $expectedPasswordFingerprint
            );
            if ($actor === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }
            $lockSql = 'SELECT id, title, turn_count FROM ai_conversations WHERE id = ? AND user_id = ? AND archived_at IS NULL';
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $lockSql .= ' FOR UPDATE';
            }
            $lock = $this->pdo->prepare($lockSql);
            $lock->execute([$conversationId, $userId]);
            $conversation = $lock->fetch();
            if (!is_array($conversation)) {
                throw new AiValidationException('ai.openChatError');
            }

            $turnIndex = (int) $conversation['turn_count'] + 1;
            $mode = in_array((string) ($result['mode'] ?? 'search'), ['search', 'page-summary', 'page-context', 'page-action', 'general-knowledge'], true)
                ? (string) ($result['mode'] ?? 'search')
                : 'search';
            $question = $this->cleanText((string) ($result['question'] ?? ''), 4000);
            $answer = $this->cleanText((string) ($result['answer'] ?? ''), 10000);
            $pageId = (int) ($result['page_id'] ?? 0);
            $pageId = $pageId > 0 ? $pageId : null;
            $pageTitle = $this->cleanText((string) ($result['page_title'] ?? ''), 240);
            $sources = array_values(array_filter(
                is_array($result['sources'] ?? null) ? $result['sources'] : [],
                static fn(mixed $source): bool => is_array($source)
            ));
            $sourcesJson = json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO ai_chat_turns
    (conversation_id, turn_index, mode, question, answer, page_id, page_title, sufficient,
     sources_json, candidate_count, model, reasoning_effort, response_id, input_tokens,
     output_tokens, latency_ms, degraded)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $insert->execute([
                $conversationId,
                $turnIndex,
                $mode,
                $question,
                $answer,
                $pageId,
                $pageTitle,
                !empty($result['sufficient']) ? 1 : 0,
                $sourcesJson,
                max(0, (int) ($result['candidate_count'] ?? 0)),
                $this->cleanText((string) ($result['model'] ?? ''), 120),
                in_array((string) ($result['reasoning_effort'] ?? ''), ['low', 'medium', 'high'], true)
                    ? (string) $result['reasoning_effort']
                    : 'low',
                $this->cleanText((string) ($result['response_id'] ?? ''), 190),
                max(0, (int) ($result['input_tokens'] ?? 0)),
                max(0, (int) ($result['output_tokens'] ?? 0)),
                max(0, (int) ($result['latency_ms'] ?? 0)),
                !empty($result['degraded']) ? 1 : 0,
            ]);
            $turnId = (int) $this->pdo->lastInsertId();

            $title = (string) $conversation['title'];
            if ((int) $conversation['turn_count'] === 0 || $title === '新しいチャット') {
                $title = $mode === 'page-summary' && $pageTitle !== ''
                    ? '「' . $pageTitle . '」の要約'
                    : $question;
                $title = $this->sliceText($title, 120);
            }
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE ai_conversations
SET title = ?, turn_count = ?, last_page_id = ?, last_page_title = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ? AND user_id = ?
SQL);
            $update->execute([$title, $turnIndex, $pageId, $pageTitle, $conversationId, $userId]);
            $this->pdo->commit();

            $ownsPostReadSnapshot = beginConsistentPageRead($this->pdo);
            $turn = $this->pdo->prepare(<<<'SQL'
SELECT id, turn_index, mode, question, answer, page_id, page_title, sufficient, sources_json,
       candidate_count, model, reasoning_effort, response_id, input_tokens, output_tokens,
       latency_ms, degraded, review_status, created_at
FROM ai_chat_turns
WHERE id = ?
SQL);
            $turn->execute([$turnId]);
            $turnRow = $turn->fetch();
            if (!is_array($turnRow)) {
                throw new RuntimeException('保存したAIチャットを読み取れません。');
            }
            $authorizationUser = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            $turnAccessible = $authorizationUser !== null
                && $this->turnIsCurrentlyAccessible($turnRow, $authorizationUser);
            $turnResult = $turnAccessible
                ? $this->turnResult($turnRow)
                : $this->restrictedTurnResult($turnRow);
            $savedResult = [
                'conversation' => $this->conversationForUser(
                    $conversationId,
                    $userId,
                    $expectedPasswordFingerprint
                ),
                'turn' => $turnResult,
                // Internal response-materialization gate. API callers must not
                // attach pending previews/actions unless this final snapshot
                // authorized the exact stored source generation.
                'turn_accessible' => $turnAccessible,
            ];
            if ($ownsPostReadSnapshot) {
                $this->pdo->commit();
            }
            return $savedResult;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Replace the assistant answer on an existing turn after a pending AI page action is confirmed or cancelled.
     *
     * @return array{conversation: array<string, mixed>, turn: array<string, mixed>}
     */
    public function updateTurnAnswer(
        int $conversationId,
        int $turnId,
        int $userId,
        string $answer,
        string $expectedPasswordFingerprint,
        ?array $sourceResult = null
    ): array
    {
        $answer = $this->cleanText($answer, 10000);
        $sources = array_values(array_filter(
            is_array($sourceResult['sources'] ?? null) ? $sourceResult['sources'] : [],
            static fn(mixed $source): bool => is_array($source)
        ));
        $pageId = max(0, (int) ($sourceResult['page_id'] ?? 0));
        $pageTitle = $this->cleanText((string) ($sourceResult['page_title'] ?? ''), 240);

        beginPageWriteTransaction($this->pdo);
        try {
            $actor = lockedAuthenticatedApplicationUserForWrite(
                $this->pdo,
                ['id' => $userId],
                $expectedPasswordFingerprint
            );
            if ($actor === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }

            $conversationSql = 'SELECT id FROM ai_conversations WHERE id = ? AND user_id = ? AND archived_at IS NULL';
            $turnSql = 'SELECT id FROM ai_chat_turns WHERE id = ? AND conversation_id = ?';
            if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                $conversationSql .= ' FOR UPDATE';
                $turnSql .= ' FOR UPDATE';
            }
            $conversationLock = $this->pdo->prepare($conversationSql);
            $conversationLock->execute([$conversationId, $userId]);
            if ($conversationLock->fetchColumn() === false) {
                throw new AiValidationException('ai.openChatError');
            }
            $turnLock = $this->pdo->prepare($turnSql);
            $turnLock->execute([$turnId, $conversationId]);
            if ($turnLock->fetchColumn() === false) {
                throw new AiValidationException('ai.chatResponseMissing');
            }

            if ($sourceResult === null) {
                $update = $this->pdo->prepare('UPDATE ai_chat_turns SET answer = ? WHERE id = ? AND conversation_id = ?');
                $update->execute([$answer, $turnId, $conversationId]);
                $this->pdo->prepare('UPDATE ai_conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?')
                    ->execute([$conversationId, $userId]);
            } else {
                $update = $this->pdo->prepare(<<<'SQL'
UPDATE ai_chat_turns
SET answer = ?, page_id = ?, page_title = ?, sufficient = ?, sources_json = ?, candidate_count = ?
WHERE id = ? AND conversation_id = ?
SQL);
                $update->execute([
                    $answer,
                    $pageId > 0 ? $pageId : null,
                    $pageTitle,
                    !empty($sourceResult['sufficient']) ? 1 : 0,
                    json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    max(0, (int) ($sourceResult['candidate_count'] ?? count($sources))),
                    $turnId,
                    $conversationId,
                ]);
                $this->pdo->prepare(<<<'SQL'
UPDATE ai_conversations
SET last_page_id = ?, last_page_title = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ? AND user_id = ?
SQL)->execute([
                    $pageId > 0 ? $pageId : null,
                    $pageTitle,
                    $conversationId,
                    $userId,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $turn = $this->pdo->prepare(<<<'SQL'
SELECT id, turn_index, mode, question, answer, page_id, page_title, sufficient, sources_json,
       candidate_count, model, reasoning_effort, response_id, input_tokens, output_tokens,
       latency_ms, degraded, review_status, created_at
FROM ai_chat_turns
WHERE id = ? AND conversation_id = ?
LIMIT 1
SQL);
            $turn->execute([$turnId, $conversationId]);
            $row = $turn->fetch();
            if (!is_array($row)) {
                throw new AiValidationException('ai.chatResponseMissing');
            }
            $authorizationUser = $this->authorizationUser($userId, $expectedPasswordFingerprint);
            $turnAccessible = $authorizationUser !== null
                && $this->turnIsCurrentlyAccessible($row, $authorizationUser);
            $result = [
                'conversation' => $this->conversationForUser(
                    $conversationId,
                    $userId,
                    $expectedPasswordFingerprint
                ),
                'turn' => $turnAccessible
                    ? $this->turnResult($row)
                    : $this->restrictedTurnResult($row),
                'turn_accessible' => $turnAccessible,
            ];
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

    /** @return array<string, mixed>|null */
    private function authorizationUser(int $userId, string $expectedPasswordFingerprint): ?array
    {
        return authenticatedApplicationUserForReadSnapshot(
            $this->pdo,
            ['id' => $userId],
            $expectedPasswordFingerprint
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function turnRows(int $conversationId): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, turn_index, mode, question, answer, page_id, page_title, sufficient, sources_json,
       candidate_count, model, reasoning_effort, response_id, input_tokens, output_tokens,
       latency_ms, degraded, review_status, created_at
FROM ai_chat_turns
WHERE conversation_id = ?
ORDER BY turn_index ASC, id ASC
SQL);
        $statement->execute([$conversationId]);
        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $user */
    private function turnIsCurrentlyAccessible(array $row, array $user): bool
    {
        $pageIds = [];
        $pageRows = [];
        $hasExtensionEvidence = false;
        $pageId = (int) ($row['page_id'] ?? 0);
        if ($pageId > 0) {
            $pageIds[$pageId] = $pageId;
        }
        $sources = json_decode((string) ($row['sources_json'] ?? '[]'), true);
        $sources = is_array($sources) ? $sources : [];
        if (!empty($row['sufficient']) && $sources === []) {
            return false;
        }
        foreach ($sources as $source) {
            if (!is_array($source)) {
                return false;
            }
            $sourcePageId = (int) ($source['page_id'] ?? 0);
            if ($sourcePageId > 0) {
                $pageIds[$sourcePageId] = $sourcePageId;
            }

            $scope = (string) ($source['source_scope'] ?? '');
            if ($scope === 'extension_content') {
                if (!class_exists(Hooks::class, false)
                    || Hooks::filter('ai_search_extension_evidence_valid', null, $source, $user) !== true) {
                    return false;
                }
                $hasExtensionEvidence = true;
                continue;
            }

            $fileId = (int) ($source['file_id'] ?? 0);
            if ($fileId > 0) {
                $fileStatement = $this->pdo->prepare('SELECT * FROM files WHERE id = ?');
                $fileStatement->execute([$fileId]);
                $file = $fileStatement->fetch();
                if (!is_array($file) || !canViewFile($this->pdo, $user, $file)) {
                    return false;
                }
            }

            $scope = (string) ($source['source_scope'] ?? '');
            if ($scope === 'linked_file') {
                if ($fileId < 1) {
                    return false;
                }
                continue;
            }
            if ($scope === 'retrieved_chunk') {
                $sourceHash = strtolower(trim((string) ($source['source_hash'] ?? '')));
                $chunkKey = strtolower(trim((string) ($source['chunk_key'] ?? '')));
                $promptVersion = trim((string) ($source['prompt_version'] ?? ''));
                if ($sourcePageId < 1
                    || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
                    || preg_match('/^[a-f0-9]{64}$/D', $chunkKey) !== 1
                    || !hash_equals(RagPipeline::PROMPT_VERSION, $promptVersion)) {
                    return false;
                }
                $currentSource = $this->pdo->prepare(<<<'SQL'
SELECT 1
FROM rag_chunks c
JOIN rag_source_documents d
  ON d.page_id = c.page_id
 AND d.source_hash = c.source_hash
 AND d.is_current = 1
 AND d.source_schema_version = ?
WHERE c.page_id = ? AND c.source_hash = ? AND c.chunk_key = ?
  AND c.prompt_version = ? AND c.is_active = 1
LIMIT 1
SQL);
                $currentSource->execute([
                    RagPipeline::SOURCE_SCHEMA_VERSION,
                    $sourcePageId,
                    $sourceHash,
                    $chunkKey,
                    $promptVersion,
                ]);
                if (!$currentSource->fetchColumn()) {
                    return false;
                }
                continue;
            }
            if ($scope === 'rag_evidence') {
                $sourceHash = strtolower(trim((string) ($source['source_hash'] ?? '')));
                $generationId = trim((string) ($source['rag_generation_id'] ?? ''));
                $chunkId = trim((string) ($source['chunk_id'] ?? ''));
                $blockIds = is_array($source['block_ids'] ?? null)
                    ? array_values(array_unique(array_filter($source['block_ids'], 'is_string')))
                    : [];
                if ($sourcePageId < 1
                    || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
                    || preg_match('/^gen_[a-z0-9]{12,64}$/D', $generationId) !== 1
                    || preg_match('/^chunk_[a-f0-9]{24,64}$/D', $chunkId) !== 1
                    || $blockIds === []
                    || count($blockIds) > 100) {
                    return false;
                }
                $currentSource = $this->pdo->prepare(<<<'SQL'
SELECT d.id
FROM rag_source_documents d
JOIN pages p ON p.id = d.page_id
WHERE d.page_id = ? AND d.source_hash = ? AND d.is_current = 1
  AND d.source_schema_version = ? AND p.status = 'published' AND p.archived_at IS NULL
  AND d.content_revision = p.content_revision AND d.source_updated_at = p.updated_at
ORDER BY d.id DESC
LIMIT 1
SQL);
                $currentSource->execute([$sourcePageId, $sourceHash, RagPipeline::SOURCE_SCHEMA_VERSION]);
                $sourceDocumentId = (int) $currentSource->fetchColumn();
                if ($sourceDocumentId < 1) {
                    return false;
                }
                $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
                $blocks = $this->pdo->prepare(<<<SQL
SELECT COUNT(DISTINCT block_id)
FROM rag_source_unit_blocks
WHERE source_unit_id IN (
    SELECT id FROM rag_source_units WHERE source_document_id = ?
) AND block_id IN ({$placeholders})
SQL);
                $blocks->execute([$sourceDocumentId, ...$blockIds]);
                if ((int) $blocks->fetchColumn() !== count($blockIds)) {
                    return false;
                }
                continue;
            }
            if (in_array($scope, ['current_page_full_text', 'current_page_image', 'page_action'], true)) {
                $fingerprint = strtolower(trim((string) ($source['page_fingerprint'] ?? '')));
                if ($sourcePageId < 1 || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                    return false;
                }
                $pageRows[$sourcePageId] ??= $this->authorizationPage($sourcePageId);
                if (!is_array($pageRows[$sourcePageId])
                    || !hash_equals(
                        $fingerprint,
                        aiPageContentFingerprint($this->pdo, $pageRows[$sourcePageId], $scope !== 'page_action')
                    )) {
                    return false;
                }
                if ($scope === 'current_page_image' && $fileId < 1) {
                    return false;
                }
                continue;
            }
            // Sufficient legacy/unknown sources lack a generation identity and
            // cannot safely retain a derived answer after page content changes.
            if (!empty($row['sufficient'])) {
                return false;
            }
        }

        if ($pageIds === []) {
            // A successful registered-data answer without traceable page IDs
            // cannot be safely re-authorized after its original request.
            return $hasExtensionEvidence || empty($row['sufficient']) || (string) ($row['mode'] ?? '') === 'general-knowledge';
        }
        foreach ($pageIds as $authorizedPageId) {
            $pageRows[$authorizedPageId] ??= $this->authorizationPage($authorizedPageId);
            if (!is_array($pageRows[$authorizedPageId])
                || $pageRows[$authorizedPageId]['archived_at'] !== null
                || !canViewPage($this->pdo, $user, $pageRows[$authorizedPageId])) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    private function authorizationPage(int $pageId): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.*, author.department AS author_department
FROM pages p
JOIN users author ON author.id = p.author_id
WHERE p.id = ?
LIMIT 1
SQL);
        $statement->execute([$pageId]);
        $page = $statement->fetch();
        return is_array($page) ? $page : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>>|null $turnRows
     * @param array<int, array<string, mixed>>|null $visibleTurnRows
     * @return array<string, mixed>
     */
    private function authorizedConversationResult(
        array $row,
        array $user,
        ?array $turnRows = null,
        ?array $visibleTurnRows = null
    ): array {
        $result = $this->conversationResult($row);
        if ((int) ($row['turn_count'] ?? 0) < 1) {
            return $result;
        }
        $turnRows ??= $this->turnRows((int) $row['id']);
        $visibleTurnRows ??= array_values(array_filter(
            $turnRows,
            fn(array $turn): bool => $this->turnIsCurrentlyAccessible($turn, $user)
        ));
        if (count($visibleTurnRows) === count($turnRows)) {
            return $result;
        }

        $result['turn_count'] = count($visibleTurnRows);
        $result['last_page_id'] = null;
        $result['last_page_title'] = '';
        if ($visibleTurnRows === []) {
            $result['title'] = '閲覧できる履歴がありません';
            return $result;
        }

        $first = $visibleTurnRows[0];
        $firstPageTitle = $this->cleanText((string) ($first['page_title'] ?? ''), 240);
        $result['title'] = (string) ($first['mode'] ?? '') === 'page-summary' && $firstPageTitle !== ''
            ? $this->sliceText('「' . $firstPageTitle . '」の要約', 120)
            : ($this->sliceText($this->cleanText((string) ($first['question'] ?? ''), 4000), 120) ?: '新しいチャット');
        $last = $visibleTurnRows[array_key_last($visibleTurnRows)];
        $lastPageId = (int) ($last['page_id'] ?? 0);
        if ($lastPageId > 0) {
            $result['last_page_id'] = $lastPageId;
            $result['last_page_title'] = $this->cleanText((string) ($last['page_title'] ?? ''), 240);
        }
        return $result;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function conversationResult(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'turn_count' => (int) $row['turn_count'],
            'last_page_id' => $row['last_page_id'] === null ? null : (int) $row['last_page_id'],
            'last_page_title' => (string) ($row['last_page_title'] ?? ''),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function turnResult(array $row): array
    {
        $sources = json_decode((string) ($row['sources_json'] ?? '[]'), true);
        $mode = (string) $row['mode'];
        $storedAnswer = (string) $row['answer'];
        $answer = $this->withoutLegacyGeneralKnowledgePrefix($storedAnswer);
        $sufficient = (bool) $row['sufficient'];
        $answerBasis = $this->answerBasis($mode, $sufficient, $storedAnswer);
        $generalKnowledge = $answerBasis === 'general_knowledge';
        return [
            'id' => (int) $row['id'],
            'turn_index' => (int) $row['turn_index'],
            'mode' => $mode,
            'question' => (string) $row['question'],
            'answer' => $answer,
            'page_id' => $row['page_id'] === null ? null : (int) $row['page_id'],
            'page_title' => (string) ($row['page_title'] ?? ''),
            'sufficient' => $sufficient,
            'answer_basis' => $answerBasis,
            'general_knowledge_fallback' => $generalKnowledge,
            'sources' => is_array($sources) ? array_values($sources) : [],
            'candidate_count' => (int) $row['candidate_count'],
            'model' => (string) $row['model'],
            'reasoning_effort' => (string) $row['reasoning_effort'],
            'response_id' => (string) ($row['response_id'] ?? ''),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'latency_ms' => (int) $row['latency_ms'],
            'degraded' => (bool) $row['degraded'],
            'review_status' => (string) ($row['review_status'] ?? 'pending'),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function restrictedTurnResult(array $row): array
    {
        $result = $this->turnResult($row);
        $result['answer'] = '閲覧権限が変更されたため、この回答は表示できません。';
        $result['page_id'] = null;
        $result['page_title'] = '';
        $result['sufficient'] = false;
        $result['answer_basis'] = 'unavailable';
        $result['general_knowledge_fallback'] = false;
        $result['sources'] = [];
        $result['candidate_count'] = 0;
        return $result;
    }

    private function consistentRead(callable $read): mixed
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $result = $read();
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

    private function answerBasis(string $mode, bool $sufficient, string $answer): string
    {
        if ($mode === 'general-knowledge' || (!$sufficient && str_starts_with($answer, AiSearchService::GENERAL_KNOWLEDGE_PREFIX))) {
            return 'general_knowledge';
        }
        if ($sufficient) {
            return in_array($mode, ['page-summary', 'page-context', 'page-action'], true) ? 'current_page' : 'registered_data';
        }
        return 'unavailable';
    }

    private function withoutLegacyGeneralKnowledgePrefix(string $answer): string
    {
        $pattern = '/^' . preg_quote(AiSearchService::GENERAL_KNOWLEDGE_PREFIX, '/') . '\s*/u';
        return trim(preg_replace($pattern, '', $answer) ?? $answer);
    }

    private function cleanText(string $value, int $length): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return $this->sliceText($value, $length);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function sliceText(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($value, 0, $length, 'UTF-8');
            return $result === false ? '' : $result;
        }
        return substr($value, 0, $length);
    }
}
