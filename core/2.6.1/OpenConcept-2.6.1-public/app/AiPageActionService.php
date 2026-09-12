<?php

declare(strict_types=1);

require_once __DIR__ . '/KnowledgeFileVersions.php';

/**
 * Plans and executes a deliberately small, server-authorized set of AI page actions.
 * Model output is treated as untrusted input and never becomes executable code.
 */
final class AiPageActionService
{
    private PDO $pdo;
    private OpenAIClient $client;
    private string $applicationUrl;

    public function __construct(PDO $pdo, ?OpenAIClient $client = null, ?string $applicationUrl = null)
    {
        $this->pdo = $pdo;
        $this->client = $client ?? new OpenAIClient();
        $this->applicationUrl = rtrim($applicationUrl ?? (function_exists('baseUrl') ? baseUrl() : ''), '/');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $editorContext
     * @param array<int, array<string, string>> $conversationHistory
     * @return array<string, mixed>|null Null means that the request should continue through ordinary AI search.
     */
    public function handle(
        string $question,
        array $user,
        int $currentPageId,
        string $expectedPasswordFingerprint,
        array $editorContext = [],
        array $conversationHistory = [],
        string $expectedUpdatedAt = ''
    ): ?array {
        $question = cleanText($question, 4000);
        if ($question === '') {
            return null;
        }

        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        $authorizedUser = null;
        $page = $currentPageId > 0
            ? $this->accessiblePage(
                $currentPageId,
                $user,
                $expectedPasswordFingerprint,
                $authorizedUser
            )
            : null;
        if ($authorizedUser !== null) {
            $user = $authorizedUser;
        }
        $editorContext = $this->normalizeEditorContext($editorContext, $page);
        $safetyIdentifier = 'ocu_' . substr(hash('sha256', $this->applicationUrl . ':' . (string) ($user['id'] ?? 0)), 0, 32);
        $user = $this->authenticatedUserForModelRequest($user, $expectedPasswordFingerprint);
        $response = $this->client->planPageAction(
            $question,
            $this->modelPageContext($page),
            $editorContext,
            array_slice($conversationHistory, -8),
            $safetyIdentifier
        );
        $plan = is_array($response['data'] ?? null) ? $response['data'] : [];
        $operation = (string) ($plan['operation'] ?? 'none');
        if (empty($plan['is_action_request'])) {
            return null;
        }
        if ($operation === 'none') {
            $answer = cleanText((string) ($plan['response'] ?? ''), 4000);
            return $this->result($question, $answer ?: 'その操作はまだ安全に実行できません。', $page, $response, []);
        }
        if ($page === null) {
            return $this->result(
                $question,
                '操作するページを開いてから、対象や変更内容をもう一度指定してください。',
                null,
                $response,
                []
            );
        }

        $actions = [];
        $answer = cleanText((string) ($plan['response'] ?? ''), 4000);
        if (in_array($operation, ['rename_page', 'create_page', 'update_focused_block', 'insert_after_focus'], true)) {
            $this->assertEditable($page, $user);
            $this->assertCurrentVersion($page, $expectedUpdatedAt);
            if ($operation === 'create_page' && !canCreatePage($user)) {
                throw new AiValidationException('ai.createPageForbidden');
            }
            $normalizedPlan = $this->normalizePendingPlan($plan);
            $preview = [
                'heading' => 'AIがページを変更しようとしています',
                'summary' => '',
                'before' => '',
                'after' => '',
            ];

            if ($operation === 'rename_page') {
                if ($normalizedPlan['title'] === '') {
                    return $this->result($question, '変更後のページタイトルを指定してください。', $page, $response, []);
                }
                $preview['summary'] = '現在のページのタイトルを変更します。';
                $preview['before'] = (string) $page['title'];
                $preview['after'] = $normalizedPlan['title'];
            } elseif ($operation === 'create_page') {
                $plannedBlocks = $this->plannedBlocks($normalizedPlan['blocks'], (string) ($page['plain_text'] ?? ''));
                if ($normalizedPlan['title'] === '' || $plannedBlocks === []) {
                    return $this->result($question, '新しいページのタイトルまたは本文が明確ではありません。作成したい内容をもう少し具体的に指定してください。', $page, $response, []);
                }
                $preview['heading'] = 'AIが新しいページを作成しようとしています';
                $preview['summary'] = '現在のページの子ページとして作成します。';
                $preview['before'] = '作成先：' . (string) $page['title'];
                $preview['after'] = $normalizedPlan['title'] . "\n" . blocksPlainText($plannedBlocks);
            } else {
                $blockId = (string) ($editorContext['block_id'] ?? '');
                if ($blockId === '') {
                    return $this->result($question, '編集する場所が明確ではありません。対象の本文ブロックへカーソルを置いて、もう一度指示してください。', $page, $response, []);
                }
                $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
                $blocks = is_array($blocks) ? $blocks : [];
                $index = $this->blockIndex($blocks, $blockId);
                if ($index < 0) {
                    return $this->result($question, '編集対象のブロックが更新されています。もう一度対象へカーソルを置いてください。', $page, $response, []);
                }
                $preview['before'] = $this->blockPlainText((array) $blocks[$index]);
                if ($operation === 'update_focused_block') {
                    $type = (string) ($blocks[$index]['type'] ?? 'paragraph');
                    if (!in_array($type, ['paragraph', 'lead', 'heading1', 'heading2', 'heading3', 'bullet', 'number', 'todo', 'quote', 'code', 'callout'], true)) {
                        return $this->result($question, 'この種類のブロックはAIから直接書き換えられません。', $page, $response, []);
                    }
                    $preview['summary'] = 'カーソルを置いていた本文ブロック全体を置き換えます。';
                    $preview['after'] = $normalizedPlan['content'] === '' ? '（空の内容にします）' : $normalizedPlan['content'];
                } else {
                    $plannedBlocks = $this->plannedBlocks($normalizedPlan['blocks'], (string) ($page['plain_text'] ?? ''));
                    if ($plannedBlocks === []) {
                        return $this->result($question, '追加する内容が明確ではありません。挿入したい内容をもう少し具体的に指定してください。', $page, $response, []);
                    }
                    $preview['summary'] = 'カーソルを置いていた本文ブロックの後に内容を追加します。';
                    $preview['after'] = blocksPlainText($plannedBlocks);
                }
            }

            $result = $this->result(
                $question,
                $operation === 'create_page'
                    ? '新しいページの作成案を準備しました。確認ダイアログで実行するか選択してください。'
                    : 'ページの変更案を準備しました。確認ダイアログで実行するか選択してください。',
                $page,
                $response,
                []
            );
            $result['pending_action'] = [
                'user_id' => (int) ($user['id'] ?? 0),
                'question' => $question,
                'operation' => $operation,
                'page_id' => (int) $page['id'],
                'expected_updated_at' => (string) ($page['updated_at'] ?? ''),
                'expected_page_fingerprint' => $this->pageSnapshotFingerprint($page),
                'editor_context' => $editorContext,
                'plan' => $normalizedPlan,
                'preview' => array_map(fn(string $value): string => $this->previewText($value), $preview),
                'response' => $this->responseMeta($response),
                'created_at' => time(),
            ];
            return $result;
        } elseif ($operation === 'report_focus') {
            $answer = $this->focusDescription($editorContext);
        } elseif ($operation === 'focus_title') {
            $actions[] = ['type' => 'focus', 'page_id' => (int) $page['id'], 'target' => 'title', 'block_id' => '', 'row' => 0, 'column' => 0];
            $answer = $answer ?: '現在のページの題名へフォーカスを移動しました。';
        } elseif ($operation === 'focus_current_block') {
            $blockId = (string) ($editorContext['block_id'] ?? '');
            if ($blockId === '') {
                throw new AiValidationException('ai.cursorBlockMissing');
            }
            $actions[] = [
                'type' => 'focus',
                'page_id' => (int) $page['id'],
                'target' => (string) ($editorContext['target_type'] ?? '') === 'table_cell' ? 'table_cell' : 'block',
                'block_id' => $blockId,
                'row' => (int) ($editorContext['row'] ?? 0),
                'column' => (int) ($editorContext['column'] ?? 0),
            ];
            $answer = $answer ?: '直前に編集していた位置へフォーカスを戻しました。';
        } else {
            return null;
        }

        return $this->result($question, $answer, $page, $response, $actions);
    }

    /**
     * Execute a server-stored action only after the browser has confirmed it.
     *
     * @param array<string, mixed> $pending
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function executePending(array $pending, array $user, string $expectedPasswordFingerprint): array
    {
        if ((int) ($pending['user_id'] ?? 0) !== (int) ($user['id'] ?? 0)) {
            throw new AiValidationException('ai.cannotApplyAction');
        }
        $pageId = (int) ($pending['page_id'] ?? 0);
        $authorizedUser = null;
        $page = $pageId > 0
            ? $this->accessiblePage(
                $pageId,
                $user,
                $expectedPasswordFingerprint,
                $authorizedUser
            )
            : null;
        if ($authorizedUser !== null) {
            $user = $authorizedUser;
        }
        if ($page === null) {
            throw new AiValidationException('ai.actionPageMissing');
        }
        $expectedFingerprint = strtolower(trim((string) ($pending['expected_page_fingerprint'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedFingerprint) !== 1
            || !hash_equals($expectedFingerprint, $this->pageSnapshotFingerprint($page))) {
            throw new AiValidationException('ai.pageChanged');
        }
        $this->assertEditable($page, $user);
        $this->assertCurrentVersion($page, (string) ($pending['expected_updated_at'] ?? ''));

        $question = cleanText((string) ($pending['question'] ?? ''), 4000);
        $operation = (string) ($pending['operation'] ?? 'none');
        $plan = $this->normalizePendingPlan(is_array($pending['plan'] ?? null) ? $pending['plan'] : []);
        $editorContext = $this->normalizeEditorContext(
            is_array($pending['editor_context'] ?? null) ? $pending['editor_context'] : [],
            $page
        );
        $response = is_array($pending['response'] ?? null) ? $pending['response'] : [];
        $actions = [];
        $answer = '';

        if ($operation === 'rename_page') {
            if ($plan['title'] === '') {
                throw new AiValidationException('ai.actionTitleMissing');
            }
            $updatedAt = $this->renamePage($page, $plan['title'], $user, $expectedPasswordFingerprint);
            $page['title'] = $plan['title'];
            $page['updated_at'] = $updatedAt;
            $actions[] = ['type' => 'page_updated', 'page_id' => (int) $page['id'], 'title' => $plan['title'], 'updated_at' => $updatedAt];
            $answer = "確認後、現在のページの題名を「{$plan['title']}」に変更しました。";
        } elseif ($operation === 'create_page') {
            if (!canCreatePage($user)) {
                throw new AiValidationException('ai.createPageForbidden');
            }
            $created = $this->createPageFromSource($page, $plan, $user, $expectedPasswordFingerprint);
            $actions[] = [
                'type' => 'page_created',
                'page_id' => $created['id'],
                'parent_id' => (int) $page['id'],
                'title' => $created['title'],
                'updated_at' => $created['updated_at'],
            ];
            $answer = "確認後、現在のページを根拠に、新しいページ「{$created['title']}」を作成しました。";
        } elseif (in_array($operation, ['update_focused_block', 'insert_after_focus'], true)) {
            $blockId = (string) ($editorContext['block_id'] ?? '');
            if ($blockId === '') {
                throw new AiValidationException('ai.editBlockMissing');
            }
            $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
            $blocks = is_array($blocks) ? $blocks : [];
            $index = $this->blockIndex($blocks, $blockId);
            if ($index < 0) {
                throw new AiValidationException('ai.blockChanged');
            }
            if ($operation === 'update_focused_block') {
                $type = (string) ($blocks[$index]['type'] ?? 'paragraph');
                if (!in_array($type, ['paragraph', 'lead', 'heading1', 'heading2', 'heading3', 'bullet', 'number', 'todo', 'quote', 'code', 'callout'], true)) {
                    throw new AiValidationException('ai.blockTypeUnsupported');
                }
                $blocks[$index]['content'] = $this->plainTextToBlockHtml($plan['content'], $type);
                $answer = '確認後、カーソルを置いていたブロックを書き換えました。';
            } else {
                $inserted = $this->plannedBlocks($plan['blocks'], (string) ($page['plain_text'] ?? ''));
                if ($inserted === []) {
                    throw new AiValidationException('ai.insertContentMissing');
                }
                array_splice($blocks, $index + 1, 0, $inserted);
                $answer = '確認後、カーソルを置いていたブロックの後に内容を追加しました。';
            }
            $updatedAt = $this->replacePageBlocks($page, $blocks, $user, $operation, $expectedPasswordFingerprint);
            $page['blocks_json'] = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $page['plain_text'] = blocksPlainText($blocks);
            $page['updated_at'] = $updatedAt;
            $actions[] = ['type' => 'page_updated', 'page_id' => (int) $page['id'], 'title' => (string) $page['title'], 'updated_at' => $updatedAt];
        } else {
            throw new AiValidationException('ai.cannotApplyAction');
        }

        return $this->result($question, $answer, $page, $response, $actions);
    }

    /** @param array<string, mixed>|null $page @return array<string, mixed> */
    private function modelPageContext(?array $page): array
    {
        if ($page === null) {
            return ['available' => false, 'id' => 0, 'title' => '', 'url' => '', 'updated_at' => '', 'source_text' => '', 'blocks' => []];
        }
        $rawBlocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true);
        $rawBlocks = is_array($rawBlocks) ? $rawBlocks : [];
        $modelBlocks = [];
        $used = 0;
        foreach (array_slice($rawBlocks, 0, 300) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $text = $this->blockPlainText($block);
            if ($text === '' && ($block['type'] ?? '') !== 'divider') {
                continue;
            }
            $remaining = 50000 - $used;
            if ($remaining <= 0) {
                break;
            }
            $text = utf8Slice($text, $remaining);
            $modelBlocks[] = [
                'id' => cleanText((string) ($block['id'] ?? ''), 120),
                'type' => cleanText((string) ($block['type'] ?? 'paragraph'), 40),
                'text' => $text,
            ];
            $used += $this->textLength($text);
        }
        $sourceText = trim((string) ($page['plain_text'] ?? ''));
        if ($sourceText === '') {
            $sourceText = implode("\n", array_column($modelBlocks, 'text'));
        }
        return [
            'available' => true,
            'id' => (int) $page['id'],
            'title' => (string) $page['title'],
            'url' => $this->applicationUrl . '/#page-' . (int) $page['id'],
            'updated_at' => (string) $page['updated_at'],
            'source_text' => utf8Slice($sourceText, 50000),
            'blocks' => $modelBlocks,
        ];
    }

    /** @param array<string, mixed>|null $page @return array<string, mixed> */
    private function normalizeEditorContext(array $context, ?array $page): array
    {
        $pageId = max(0, (int) ($context['page_id'] ?? 0));
        if ($page === null || $pageId !== (int) $page['id']) {
            return ['page_id' => $page === null ? 0 : (int) $page['id'], 'target_type' => 'none', 'block_id' => '', 'block_type' => '', 'selected_text' => '', 'text' => '', 'row' => 0, 'column' => 0];
        }
        $targetType = in_array((string) ($context['target_type'] ?? ''), ['none', 'title', 'block', 'table_cell'], true)
            ? (string) $context['target_type']
            : 'none';
        $blockId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($context['block_id'] ?? '')) ?? '';
        if ($targetType === 'title') {
            $blockId = '';
        }
        return [
            'page_id' => (int) $page['id'],
            'target_type' => $targetType,
            'block_id' => utf8Slice($blockId, 120),
            'block_type' => cleanText((string) ($context['block_type'] ?? ''), 40),
            'selected_text' => cleanText((string) ($context['selected_text'] ?? ''), 2000),
            'text' => cleanText((string) ($context['text'] ?? ''), 6000),
            'row' => max(0, min(49, (int) ($context['row'] ?? 0))),
            'column' => max(0, min(11, (int) ($context['column'] ?? 0))),
        ];
    }

    /** @param array<string, mixed> $user @return array<string, mixed>|null */
    private function accessiblePage(
        int $pageId,
        array $user,
        string $expectedPasswordFingerprint,
        ?array &$authorizedUser = null
    ): ?array
    {
        $authorizedUser = null;
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $snapshotUser = $this->snapshotUser($user, $expectedPasswordFingerprint);
            if ($snapshotUser === null) {
                if ($ownsSnapshot) {
                    $this->pdo->commit();
                }
                return null;
            }
            $authorizedUser = $snapshotUser;
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.*, u.department AS author_department
FROM pages p
JOIN users u ON u.id = p.author_id
WHERE p.id = ? AND p.archived_at IS NULL
LIMIT 1
SQL);
            $statement->execute([$pageId]);
            $page = $statement->fetch();
            if (is_array($page)) {
                $page['access_departments'] = pageAccessDepartments($this->pdo, $pageId, $page);
                $page['access_member_ids'] = pageAccessMemberIds($this->pdo, $pageId);
            }
            $result = is_array($page) && canViewPage($this->pdo, $snapshotUser, $page) ? $page : null;
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

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function comparablePageSnapshot(array $page): array
    {
        $pageId = (int) ($page['id'] ?? 0);
        $parentId = $page['parent_id'] ?? null;
        $archivedAt = $page['archived_at'] ?? null;
        $departments = array_key_exists('access_departments', $page)
            ? normalizePageAccessDepartments((array) $page['access_departments'])
            : pageAccessDepartments($this->pdo, $pageId, $page);
        $memberIds = array_key_exists('access_member_ids', $page)
            ? array_values(array_unique(array_map('intval', (array) $page['access_member_ids'])))
            : pageAccessMemberIds($this->pdo, $pageId);
        sort($memberIds, SORT_NUMERIC);
        return [
            'id' => $pageId,
            'parent_id' => $parentId === null ? null : (int) $parentId,
            'title' => (string) ($page['title'] ?? ''),
            'icon' => (string) ($page['icon'] ?? ''),
            'cover' => (string) ($page['cover'] ?? ''),
            'status' => (string) ($page['status'] ?? ''),
            'category' => (string) ($page['category'] ?? ''),
            'manual_tags_json' => (string) ($page['manual_tags_json'] ?? ''),
            'blocks_json' => (string) ($page['blocks_json'] ?? ''),
            'plain_text' => (string) ($page['plain_text'] ?? ''),
            'visibility' => (string) ($page['visibility'] ?? ''),
            'access_department' => (string) ($page['access_department'] ?? ''),
            'access_departments' => $departments,
            'access_member_ids' => $memberIds,
            'comments_enabled' => (int) ($page['comments_enabled'] ?? 0),
            'author_id' => (int) ($page['author_id'] ?? 0),
            'archived_at' => $archivedAt === null ? null : (string) $archivedAt,
            'updated_at' => (string) ($page['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $page */
    private function pageSnapshotFingerprint(array $page): string
    {
        return hash('sha256', json_encode(
            $this->comparablePageSnapshot($page),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @param array<string, mixed> $expected @param array<string, mixed> $live */
    private function assertSamePageSnapshot(array $expected, array $live): void
    {
        if ($this->comparablePageSnapshot($expected) !== $this->comparablePageSnapshot($live)) {
            throw new AiValidationException('ai.pageChanged');
        }
    }

    /**
     * @param array<string, mixed> $expectedPage
     * @param array<string, mixed> $user
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function lockedEditablePageForWrite(
        array $expectedPage,
        array $user,
        string $expectedPasswordFingerprint
    ): array
    {
        $liveUser = preg_match('/^[a-f0-9]{64}$/D', (string) $expectedPasswordFingerprint) === 1
            ? lockedAuthenticatedApplicationUserForWrite($this->pdo, $user, $expectedPasswordFingerprint)
            : null;
        if ($liveUser === null) {
            throw new AiValidationException('ai.editPageForbidden');
        }
        $pageId = (int) ($expectedPage['id'] ?? 0);
        $lockedHierarchy = lockedPageHierarchiesForUpdate($this->pdo, [$pageId]);
        $livePage = $lockedHierarchy['rows'][$pageId] ?? null;
        if ($lockedHierarchy['reason'] !== null || !is_array($livePage)) {
            throw new AiValidationException('ai.pageHierarchyChanged');
        }
        if (!canEditPage($this->pdo, $liveUser, $livePage)) {
            throw new AiValidationException('ai.editPageForbidden');
        }
        $this->assertSamePageSnapshot($expectedPage, $livePage);
        $livePage['access_departments'] = pageAccessDepartments($this->pdo, $pageId, $livePage);
        $livePage['access_member_ids'] = pageAccessMemberIds($this->pdo, $pageId);
        return [$livePage, $liveUser];
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $user */
    private function assertEditable(array $page, array $user): void
    {
        if (!canEditPage($this->pdo, $user, $page)) {
            throw new AiValidationException('ai.aiEditForbidden');
        }
    }

    /** @param array<string, mixed> $page */
    private function assertCurrentVersion(array $page, string $expectedUpdatedAt): void
    {
        $expectedUpdatedAt = trim($expectedUpdatedAt);
        if ($expectedUpdatedAt !== '' && !hash_equals((string) ($page['updated_at'] ?? ''), $expectedUpdatedAt)) {
            throw new AiValidationException('ai.pageChangedRetry');
        }
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $user */
    private function renamePage(
        array $page,
        string $title,
        array $user,
        string $expectedPasswordFingerprint
    ): string
    {
        beginPageWriteTransaction($this->pdo);
        try {
            [$livePage, $liveUser] = $this->lockedEditablePageForWrite($page, $user, $expectedPasswordFingerprint);
            $page = $livePage;
            $user = $liveUser;
            $this->insertRevision($page, $user);
            $update = $this->pdo->prepare("UPDATE pages SET title = ?, content_revision = content_revision + 1, translation_status = CASE WHEN source_page_id IS NULL THEN translation_status ELSE 'human_edited' END, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$title, (int) $user['id'], (int) $page['id']]);
            invalidateRagPageGenerations($this->pdo, [(int) $page['id']]);
            audit($this->pdo, (int) $user['id'], 'ai_updated', 'page', (int) $page['id'], ['field' => 'title', 'title' => $title]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Hooks::action('article_updated', ['id' => (int) $page['id']], $page);
        queueRagPage($this->pdo, (int) $page['id']);
        return $this->updatedAt((int) $page['id']);
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $plan @param array<string, mixed> $user @return array{id: int, title: string, updated_at: string} */
    private function createPageFromSource(
        array $source,
        array $plan,
        array $user,
        string $expectedPasswordFingerprint
    ): array
    {
        $title = cleanText((string) ($plan['title'] ?? ''), 240);
        if ($title === '') {
            throw new AiValidationException('ai.generateTitleFailed');
        }
        $blocks = $this->plannedBlocks((array) ($plan['blocks'] ?? []), (string) ($source['plain_text'] ?? ''));
        if ($blocks === []) {
            throw new AiValidationException('ai.generateContentFailed');
        }
        $sourceUrl = $this->applicationUrl . '/#page-' . (int) $source['id'];
        $blocks[] = ['id' => $this->blockId(), 'type' => 'divider', 'content' => ''];
        $blocks[] = ['id' => $this->blockId(), 'type' => 'heading2', 'content' => '出典'];
        $blocks[] = [
            'id' => $this->blockId(),
            'type' => 'paragraph',
            'content' => '<a href="' . htmlspecialchars($sourceUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars((string) $source['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>（作成元ページ）',
        ];
        $blocks = sanitizeBlocks($blocks);
        $plain = utf8Slice(blocksPlainText($blocks), 100000);

        beginPageWriteTransaction($this->pdo);
        try {
            [$liveSource, $liveUser] = $this->lockedEditablePageForWrite($source, $user, $expectedPasswordFingerprint);
            $source = $liveSource;
            $user = $liveUser;

            $sort = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id = ? AND archived_at IS NULL');
            $sort->execute([(int) $source['id']]);
            $sortOrder = (int) $sort->fetchColumn();
            $isPrivate = ($source['status'] ?? '') === 'private'
                || ($source['visibility'] ?? '') === 'private'
                || pageHierarchyForcesPrivate($this->pdo, (int) $source['id']);
            $status = $isPrivate ? 'private' : 'draft';
            $visibility = $isPrivate ? 'private' : (string) ($source['visibility'] ?? 'company');
            $accessDepartments = pageAccessDepartments($this->pdo, (int) $source['id'], $source);
            if ($accessDepartments === []) {
                $accessDepartments = normalizePageAccessDepartments([(string) ($user['department'] ?? '')]);
            }
            $accessDepartment = legacyPageAccessDepartment($accessDepartments, (string) ($user['department'] ?? ''));
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO pages
    (parent_id, sort_order, title, icon, cover, status, category, tags_json, manual_tags_json, blocks_json, plain_text,
     visibility, access_department, language_code, author_id, updated_by)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
            $inheritedTags = (string) ($source['manual_tags_json'] ?? $source['tags_json'] ?? '[]');
            $insert->execute([
                (int) $source['id'],
                $sortOrder,
                $title,
                '✨',
                'none',
                $status,
                cleanText((string) ($source['category'] ?? 'ナレッジ'), 80) ?: 'ナレッジ',
                $inheritedTags,
                $inheritedTags,
                json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                $plain,
                $visibility,
                $accessDepartment,
                (string) ($source['language_code'] ?? 'und'),
                (int) $user['id'],
                (int) $user['id'],
            ]);
            $id = (int) $this->pdo->lastInsertId();
            replacePageAccessDepartments($this->pdo, $id, $accessDepartments, (int) $user['id']);
            if ($visibility === 'group') {
                $copy = $this->pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) SELECT ?, user_id, ? FROM page_access_members WHERE page_id = ?');
                $copy->execute([$id, (int) $user['id'], (int) $source['id']]);
            }
            audit($this->pdo, (int) $user['id'], 'ai_created', 'page', $id, ['source_page_id' => (int) $source['id'], 'title' => $title]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Hooks::action('article_created', ['id' => $id, 'parent_id' => (int) $source['id'], 'source' => 'ai']);
        queueRagPage($this->pdo, $id);
        return ['id' => $id, 'title' => $title, 'updated_at' => $this->updatedAt($id)];
    }

    /** @param array<string, mixed> $page @param array<int, array<string, mixed>> $blocks @param array<string, mixed> $user */
    private function replacePageBlocks(
        array $page,
        array $blocks,
        array $user,
        string $operation,
        string $expectedPasswordFingerprint
    ): string
    {
        $blocks = sanitizeBlocks($blocks);
        beginPageWriteTransaction($this->pdo);
        try {
            [$livePage, $liveUser] = $this->lockedEditablePageForWrite($page, $user, $expectedPasswordFingerprint);
            $page = $livePage;
            $user = $liveUser;
            $this->insertRevision($page, $user);
            $update = $this->pdo->prepare("UPDATE pages SET blocks_json = ?, plain_text = ?, content_revision = content_revision + 1, translation_status = CASE WHEN source_page_id IS NULL THEN translation_status ELSE 'human_edited' END, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([
                json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                utf8Slice(blocksPlainText($blocks), 100000),
                (int) $user['id'],
                (int) $page['id'],
            ]);
            invalidateRagPageGenerations($this->pdo, [(int) $page['id']]);
            audit($this->pdo, (int) $user['id'], 'ai_updated', 'page', (int) $page['id'], ['operation' => $operation]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        Hooks::action('article_updated', ['id' => (int) $page['id']], $page);
        queueRagPage($this->pdo, (int) $page['id']);
        return $this->updatedAt((int) $page['id']);
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $user */
    private function insertRevision(array $page, array $user): void
    {
        $meta = [
            'status' => (string) ($page['status'] ?? 'draft'),
            'category' => (string) ($page['category'] ?? 'ナレッジ'),
            'tags' => json_decode((string) ($page['tags_json'] ?? '[]'), true) ?: [],
            'manual_tags' => json_decode((string) ($page['manual_tags_json'] ?? '[]'), true) ?: [],
            'visibility' => (string) ($page['visibility'] ?? 'company'),
            'access_department' => (string) ($page['access_department'] ?? ''),
            'access_departments' => pageAccessDepartments($this->pdo, (int) $page['id'], $page),
            'access_member_ids' => pageAccessMemberIds($this->pdo, (int) $page['id']),
            'language_code' => (string) ($page['language_code'] ?? 'und'),
            'content_revision' => (int) ($page['content_revision'] ?? 1),
            'translation_status' => (string) ($page['translation_status'] ?? 'original'),
            'source_revision' => $page['source_revision'] === null ? null : (int) $page['source_revision'],
        ];
        $meta = KnowledgeFileVersions::revisionMetadata($this->pdo, $page, $meta);
        $revision = $this->pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $revision->execute([
            (int) $page['id'],
            (string) $page['title'],
            (string) ($page['icon'] ?? '📄'),
            (string) ($page['blocks_json'] ?? '[]'),
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int) $user['id'],
        ]);
    }

    /** @param array<string, mixed> $plan @return array{title: string, content: string, blocks: array<int, array<string, string>>} */
    private function normalizePendingPlan(array $plan): array
    {
        $blocks = [];
        foreach (array_slice(is_array($plan['blocks'] ?? null) ? $plan['blocks'] : [], 0, 60) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = in_array((string) ($block['type'] ?? ''), ['paragraph', 'heading1', 'heading2', 'heading3', 'bullet', 'number', 'quote', 'callout', 'divider'], true)
                ? (string) $block['type']
                : 'paragraph';
            $blocks[] = [
                'type' => $type,
                'content' => cleanText((string) ($block['content'] ?? ''), 12000),
                'emoji' => cleanText((string) ($block['emoji'] ?? ''), 4),
            ];
        }
        return [
            'title' => cleanText((string) ($plan['title'] ?? ''), 240),
            'content' => cleanText((string) ($plan['content'] ?? ''), 20000),
            'blocks' => $blocks,
        ];
    }

    /** @param array<string, mixed> $response @return array<string, int|string> */
    private function responseMeta(array $response): array
    {
        return [
            'model' => cleanText((string) ($response['model'] ?? $this->client->model()), 120),
            'response_id' => cleanText((string) ($response['response_id'] ?? ''), 190),
            'input_tokens' => max(0, (int) ($response['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($response['output_tokens'] ?? 0)),
            'latency_ms' => max(0, (int) ($response['latency_ms'] ?? 0)),
        ];
    }

    private function previewText(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return utf8Slice($value, 700);
    }

    /** @param array<int, mixed> $planned @return array<int, array<string, mixed>> */
    private function plannedBlocks(array $planned, string $sourceText): array
    {
        $blocks = [];
        $normalizedSource = $this->normalizeComparableText($sourceText);
        foreach (array_slice($planned, 0, 60) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $type = in_array((string) ($raw['type'] ?? ''), ['paragraph', 'heading1', 'heading2', 'heading3', 'bullet', 'number', 'quote', 'callout', 'divider'], true)
                ? (string) $raw['type']
                : 'paragraph';
            $text = cleanText((string) ($raw['content'] ?? ''), 12000);
            if ($type !== 'divider' && $text === '') {
                continue;
            }
            if ($type === 'quote') {
                $normalizedQuote = $this->normalizeComparableText($text);
                if ($normalizedQuote === '' || $normalizedSource === '' || !str_contains($normalizedSource, $normalizedQuote)) {
                    $type = 'paragraph';
                    $text = '要約：' . $text;
                }
            }
            $blocks[] = [
                'id' => $this->blockId(),
                'type' => $type,
                'content' => $type === 'divider' ? '' : $this->plainTextToBlockHtml($text, $type),
                ...($type === 'callout' ? ['emoji' => cleanText((string) ($raw['emoji'] ?? '💡'), 4) ?: '💡'] : []),
            ];
        }
        return sanitizeBlocks($blocks);
    }

    private function plainTextToBlockHtml(string $text, string $type): string
    {
        $text = utf8Slice(str_replace(["\r\n", "\r"], "\n", $text), 20000);
        if ($type === 'code') {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /** @param array<string, mixed> $block */
    private function blockPlainText(array $block): string
    {
        if (($block['type'] ?? '') === 'table') {
            return implode("\n", array_map(static fn(array $row): string => implode("\t", array_map(
                static fn($cell): string => html_entity_decode(strip_tags((string) (is_array($cell) ? ($cell['content'] ?? '') : $cell)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $row
            )), (array) ($block['table_rows'] ?? [])));
        }
        if (in_array((string) ($block['type'] ?? ''), ['image', 'file'], true)) {
            return trim(html_entity_decode(strip_tags((string) ($block['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8') . ' ' . (string) ($block['file_name'] ?? ''));
        }
        return trim(html_entity_decode(str_replace(['<br>', '<br/>', '<br />'], "\n", strip_tags((string) ($block['content'] ?? ''), '<br>')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function blockIndex(array $blocks, string $blockId): int
    {
        foreach ($blocks as $index => $block) {
            if (is_array($block) && hash_equals((string) ($block['id'] ?? ''), $blockId)) {
                return (int) $index;
            }
        }
        return -1;
    }

    /** @param array<string, mixed> $context */
    private function focusDescription(array $context): string
    {
        return match ((string) ($context['target_type'] ?? 'none')) {
            'title' => 'AIを開く直前のフォーカスは、現在のページの題名にありました。',
            'block' => 'AIを開く直前のフォーカスは、本文の「' . utf8Slice((string) ($context['text'] ?? ''), 80) . '」のブロックにありました。',
            'table_cell' => 'AIを開く直前のフォーカスは、表の' . ((int) ($context['row'] ?? 0) + 1) . '行' . ((int) ($context['column'] ?? 0) + 1) . '列にありました。',
            default => 'AIを開く直前の編集フォーカスは記録されていません。',
        };
    }

    /** @param array<string, mixed>|null $page @param array<string, mixed> $response @param array<int, array<string, mixed>> $actions @return array<string, mixed> */
    private function result(string $question, string $answer, ?array $page, array $response, array $actions): array
    {
        $pageId = (int) ($page['id'] ?? 0);
        $pageTitle = cleanText((string) ($page['title'] ?? ''), 240);
        $sources = $pageId > 0 ? [[
            'document_id' => 'oc-page-' . $pageId,
            'chunk_id' => 'oc-action-' . substr(hash('sha256', $pageId . ':' . (string) ($page['updated_at'] ?? '')), 0, 32),
            'page_id' => $pageId,
            'file_id' => 0,
            'source_scope' => 'page_action',
            'page_fingerprint' => aiPageContentFingerprint($this->pdo, $page, false),
            'title' => $pageTitle,
            'summary' => 'AIページ操作の根拠にした現在ページです。',
            'url' => $this->applicationUrl . '/#page-' . $pageId,
        ]] : [];
        return [
            'mode' => 'page-action',
            'question' => $question,
            'answer' => cleanText($answer, 10000),
            'answer_basis' => $pageId > 0 ? 'current_page' : 'unavailable',
            'general_knowledge_fallback' => false,
            'sufficient' => $pageId > 0,
            'page_id' => $pageId ?: null,
            'page_title' => $pageTitle,
            'sources' => $sources,
            'candidate_count' => $pageId > 0 ? 1 : 0,
            'model' => (string) ($response['model'] ?? $this->client->model()),
            'reasoning_effort' => 'medium',
            'response_id' => (string) ($response['response_id'] ?? ''),
            'input_tokens' => (int) ($response['input_tokens'] ?? 0),
            'output_tokens' => (int) ($response['output_tokens'] ?? 0),
            'latency_ms' => (int) ($response['latency_ms'] ?? 0),
            'degraded' => false,
            'actions' => $actions,
        ];
    }

    private function updatedAt(int $pageId): string
    {
        $statement = $this->pdo->prepare('SELECT updated_at FROM pages WHERE id = ?');
        $statement->execute([$pageId]);
        return (string) ($statement->fetchColumn() ?: '');
    }

    private function normalizeComparableText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function blockId(): string
    {
        return 'b-' . bin2hex(random_bytes(8));
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
