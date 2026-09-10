<?php

declare(strict_types=1);

/** Coordinates authenticated share settings with signed static-site output. */
final class PublicPageSharing
{
    private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/D';
    private const MAX_SELECTED_PAGES = StaticPublicSitePublisher::MAX_PAGES - 1;

    private StaticPublicSitePublisher $publisher;

    public function __construct(private readonly PDO $pdo, ?StaticPublicSitePublisher $publisher = null)
    {
        if ($publisher !== null) {
            $this->publisher = $publisher;
            return;
        }
        $i18n = new I18n('en-US');
        $i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $this->publisher = new StaticPublicSitePublisher($pdo, $i18n);
    }

    /** @return array<string, mixed> */
    public function stateForUser(
        int $rootPageId,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null
    ): array {
        $this->assertCanManagePreflight($rootPageId, $requestUser, $expectedPasswordFingerprint);
        $this->publisher->recoverForRoot($rootPageId);
        return $this->stateForUserAfterRecovery(
            $rootPageId,
            $requestUser,
            $expectedPasswordFingerprint,
            $applicationBaseUrl
        );
    }

    /** @return array<string, mixed> */
    private function stateForUserAfterRecovery(
        int $rootPageId,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null
    ): array {
        $ownsSnapshot = $this->beginReadSnapshot();
        try {
            $liveUser = authenticatedApplicationUserForReadSnapshot(
                $this->pdo,
                $requestUser,
                $expectedPasswordFingerprint
            );
            if ($liveUser === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }
            $root = $this->pageRow($rootPageId);
            if ($root === null) {
                throw new RuntimeException('ページが見つかりません。', 404);
            }
            if (!canEditPage($this->pdo, $liveUser, $root)) {
                throw new RuntimeException('このページのWeb公開を管理する権限がありません。', 403);
            }
            $state = $this->managementState(
                $root,
                $liveUser,
                $applicationBaseUrl ?? baseUrl()
            );
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $state;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @param array<int, mixed> $requestedPageIds
     * @return array<string, mixed>
     */
    public function updateForUser(
        int $rootPageId,
        bool $enabled,
        array $requestedPageIds,
        ?int $expectedRevision,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null,
        ?string $publicSlug = null
    ): array {
        $this->assertCanManagePreflight($rootPageId, $requestUser, $expectedPasswordFingerprint);
        $recoveryHint = trim((string) $publicSlug);
        $this->publisher->recoverForRoot(
            $rootPageId,
            $recoveryHint !== '' ? $recoveryHint : 'page-' . $rootPageId
        );
        $requestedPageIds = $this->normalizeRequestedPageIds($requestedPageIds, $rootPageId);
        $existingProbe = $this->shareRow($rootPageId);
        $slug = trim((string) ($existingProbe['public_slug'] ?? ''));
        if ($slug === '') {
            $slug = trim((string) $publicSlug);
            if ($slug === '') {
                $slug = 'page-' . $rootPageId;
            }
        }
        $slug = $this->publisher->validateSlug($slug);
        if (is_array($existingProbe) && trim((string) ($existingProbe['public_slug'] ?? '')) !== ''
            && $publicSlug !== null && trim($publicSlug) !== '' && trim($publicSlug) !== $slug) {
            throw new StaticPublicationException(
                '固定URL名は初回公開後に変更できません。',
                409,
                'publication_conflict'
            );
        }
        if ($applicationBaseUrl === null
            && trim((string) getenv('OPENCONCEPT_APP_URL')) === '') {
            throw new RuntimeException(
                '固定URLを安全に発行するため、信頼済みの OPENCONCEPT_APP_URL を設定してください。',
                500
            );
        }
        $baseUrl = $applicationBaseUrl ?? baseUrl();

        return $this->publisher->withExclusiveLock($slug, function () use (
            $rootPageId,
            $enabled,
            $requestedPageIds,
            $expectedRevision,
            $requestUser,
            $expectedPasswordFingerprint,
            $baseUrl,
            $slug
        ): array {
            $this->publisher->recoverForRootUnderLock($rootPageId, $slug);
            beginPageWriteTransaction($this->pdo);
            $stagePath = '';
            $swap = null;
            $retired = null;
            $oldShare = null;
            $committed = false;
            $journalPrepared = false;
            try {
                $liveUser = lockedAuthenticatedApplicationUserForWrite(
                    $this->pdo,
                    $requestUser,
                    $expectedPasswordFingerprint
                );
                if ($liveUser === null) {
                    throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
                }
                $lockedSubtree = lockedPageSubtreeForUpdate($this->pdo, $rootPageId);
                $root = $lockedSubtree['rows'][$rootPageId] ?? null;
                if ($lockedSubtree['reason'] !== null || !is_array($root)) {
                    throw new RuntimeException('ページ階層が変更されました。もう一度お試しください。', 409);
                }
                if (!canEditPage($this->pdo, $liveUser, $root)) {
                    throw new RuntimeException('このページのWeb公開を管理する権限がありません。', 403);
                }

                $shareSql = 'SELECT * FROM page_public_shares WHERE root_page_id = ?';
                if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                    $shareSql .= ' FOR UPDATE';
                }
                $shareStatement = $this->pdo->prepare($shareSql);
                $shareStatement->execute([$rootPageId]);
                $share = $shareStatement->fetch() ?: null;
                $oldShare = is_array($share) ? $share : null;
                $oldSelectedPageIds = [];
                if (is_array($share)) {
                    $oldSelectionStatement = $this->pdo->prepare(
                        'SELECT page_id FROM page_public_share_pages WHERE share_id = ? ORDER BY page_id'
                    );
                    $oldSelectionStatement->execute([(int) $share['id']]);
                    $oldSelectedPageIds = array_map('intval', $oldSelectionStatement->fetchAll(PDO::FETCH_COLUMN));
                }
                $currentRevision = is_array($share) ? (int) $share['revision'] : null;
                if ($currentRevision !== $expectedRevision) {
                    throw new StaticPublicationException(
                        'Web公開設定が別の画面で更新されました。最新の設定を確認してください。',
                        409,
                        'publication_conflict'
                    );
                }
                if (is_array($share) && !in_array((string) ($share['operation_state'] ?? 'idle'), ['idle', 'failed'], true)) {
                    throw new StaticPublicationException(
                        'この静的サイトは別の公開処理を実行中です。',
                        409,
                        'publication_busy'
                    );
                }
                if (is_array($share) && trim((string) ($share['public_slug'] ?? '')) !== ''
                    && (string) $share['public_slug'] !== $slug) {
                    throw new StaticPublicationException(
                        '固定URL名は初回公開後に変更できません。',
                        409,
                        'publication_conflict'
                    );
                }

                if (!$enabled) {
                    if (!is_array($share) || empty($share['enabled'])) {
                        $this->pdo->commit();
                        $committed = true;
                        return $this->stateForUserAfterRecovery($rootPageId, $liveUser, $expectedPasswordFingerprint, $baseUrl);
                    }
                    $operationId = bin2hex(random_bytes(16));
                    $newRevision = (int) $share['revision'] + 1;
                    $disabledShare = array_replace($share, [
                        'enabled' => 0,
                        'revision' => $newRevision,
                        'published_revision' => null,
                        'manifest_json' => null,
                        'source_hash' => null,
                    ]);
                    $this->publisher->prepareRetirementJournal(
                        $slug,
                        $operationId,
                        $share,
                        $oldSelectedPageIds,
                        $disabledShare
                    );
                    $journalPrepared = true;
                    $retired = $this->publisher->retire($share, $operationId);
                    $this->publisher->assertRetired($retired, $share);
                    $this->pdo->prepare(<<<'SQL'
UPDATE page_public_shares
SET enabled = 0, revision = ?, published_revision = NULL, manifest_json = NULL,
    source_hash = NULL, published_at = NULL, operation_state = 'idle', operation_id = NULL,
    pending_manifest_json = NULL, operation_started_at = NULL, updated_by = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ?
SQL)->execute([$newRevision, (int) $liveUser['id'], (int) $share['id']]);
                    audit($this->pdo, (int) $liveUser['id'], 'static_publication_stopped', 'page', $rootPageId, [
                        'public_slug' => $slug,
                        'revision' => $newRevision,
                    ]);
                    $this->pdo->commit();
                    $committed = true;
                    $recoveryPending = false;
                    try {
                        $this->publisher->recoverForRootUnderLock($rootPageId, $slug);
                    } catch (Throwable $cleanupException) {
                        if ($cleanupException instanceof StaticPublicationException
                            && $cleanupException->publicCode === 'publication_permissions_unsafe') {
                            throw $cleanupException;
                        }
                        $recoveryPending = true;
                        error_log('OpenConcept static publication stop cleanup failed: ' . get_class($cleanupException));
                    }
                    $state = $this->stateForUserAfterRecovery(
                        $rootPageId,
                        $liveUser,
                        $expectedPasswordFingerprint,
                        $baseUrl
                    );
                    return $recoveryPending ? $this->recoveryPendingState($state) : $state;
                }

                if ((string) ($root['status'] ?? '') !== 'published') {
                    throw new RuntimeException('静的サイトを発行するには、親ページを公開状態にしてください。', 422);
                }
                $selection = $this->authorizedSelection(
                    $rootPageId,
                    $requestedPageIds,
                    $lockedSubtree,
                    $liveUser,
                    $share
                );
                $publicationLocale = is_array($share) && trim((string) ($share['publication_locale'] ?? '')) !== ''
                    ? (string) $share['publication_locale']
                    : $this->publicationLocale($root, $liveUser);
                $newRevision = is_array($share) ? (int) $share['revision'] + 1 : 1;
                $token = is_array($share) ? (string) $share['token'] : $this->newToken();

                $collision = $this->pdo->prepare('SELECT root_page_id FROM page_public_shares WHERE public_slug = ?');
                $collision->execute([$slug]);
                $collisionRootId = (int) ($collision->fetchColumn() ?: 0);
                if ($collisionRootId > 0 && $collisionRootId !== $rootPageId) {
                    throw new StaticPublicationException(
                        'この固定URL名は既に使用されています。',
                        409,
                        'publication_conflict'
                    );
                }

                if (!is_array($share)) {
                    $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO page_public_shares
    (root_page_id, token, public_slug, enabled, publication_locale, revision, operation_state, created_by, updated_by)
VALUES (?, ?, ?, 0, ?, ?, 'publishing', ?, ?)
SQL);
                    $insert->execute([
                        $rootPageId,
                        $token,
                        $slug,
                        $publicationLocale,
                        $newRevision,
                        (int) $liveUser['id'],
                        (int) $liveUser['id'],
                    ]);
                    $shareId = (int) $this->pdo->lastInsertId();
                } else {
                    $shareId = (int) $share['id'];
                    $this->pdo->prepare(<<<'SQL'
UPDATE page_public_shares
SET public_slug = ?, publication_locale = ?, revision = ?, operation_state = 'publishing',
    operation_id = NULL, pending_manifest_json = NULL, operation_started_at = CURRENT_TIMESTAMP,
    updated_by = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ?
SQL)->execute([$slug, $publicationLocale, $newRevision, (int) $liveUser['id'], $shareId]);
                    $this->pdo->prepare('DELETE FROM page_public_share_pages WHERE share_id = ?')->execute([$shareId]);
                }
                if ($selection !== []) {
                    $insertSelected = $this->pdo->prepare(
                        'INSERT INTO page_public_share_pages (share_id, page_id, added_by) VALUES (?, ?, ?)'
                    );
                    foreach ($selection as $selectedPageId) {
                        $insertSelected->execute([$shareId, $selectedPageId, (int) $liveUser['id']]);
                    }
                }

                $shareForBuild = array_replace(is_array($share) ? $share : [], [
                    'id' => $shareId,
                    'root_page_id' => $rootPageId,
                    'token' => $token,
                    'public_slug' => $slug,
                    'publication_locale' => $publicationLocale,
                    'revision' => $newRevision,
                ]);
                $replaceableShare = is_array($oldShare)
                    ? (empty($oldShare['enabled']) ? array_replace($oldShare, ['public_slug' => $slug]) : $oldShare)
                    : array_replace($shareForBuild, ['enabled' => 0]);
                $this->publisher->assertReplaceable($replaceableShare);
                $snapshot = $this->publicationSnapshot(
                    $lockedSubtree['rows'],
                    $rootPageId,
                    $selection,
                    $publicationLocale
                );
                $siteBaseUrl = $this->publisher->siteBaseUrl($slug, $baseUrl);
                $snapshot['application_base_url'] = $baseUrl;
                $snapshot['site_base_url'] = $siteBaseUrl;
                $built = $this->publisher->buildStage($snapshot, $shareForBuild, $baseUrl, $siteBaseUrl);
                $stagePath = $built['stage_path'];
                $operationId = bin2hex(random_bytes(16));
                $this->pdo->prepare(<<<'SQL'
UPDATE page_public_shares
SET operation_id = ?, pending_manifest_json = ?, operation_started_at = CURRENT_TIMESTAMP
WHERE id = ? AND revision = ?
SQL)->execute([$operationId, $built['manifest_json'], $shareId, $newRevision]);
                // Recheck immediately before the filesystem swap. Building can
                // take long enough for an operator to alter the live tree after
                // the initial check; such a tree must never be overwritten.
                $this->publisher->assertReplaceable(
                    $replaceableShare
                );
                $shareForCommit = array_replace($shareForBuild, [
                    'enabled' => 1,
                    'published_revision' => $newRevision,
                    'manifest_json' => $built['manifest_json'],
                    'source_hash' => $built['source_hash'],
                ]);
                $this->publisher->prepareActivationJournal(
                    $slug,
                    $operationId,
                    $stagePath,
                    $oldShare,
                    $oldSelectedPageIds,
                    $shareForCommit,
                    $selection,
                    $built['manifest_json']
                );
                $journalPrepared = true;
                $swap = $this->publisher->activate($stagePath, $slug, $operationId);
                $stagePath = '';
                $this->publisher->assertActivated(
                    $swap,
                    is_array($oldShare) && !empty($oldShare['enabled']) ? $oldShare : null,
                    $shareForCommit,
                    $built['manifest_json']
                );
                $this->pdo->prepare(<<<'SQL'
UPDATE page_public_shares
SET enabled = 1, published_revision = ?, manifest_json = ?, source_hash = ?, published_at = CURRENT_TIMESTAMP,
    operation_state = 'idle', operation_id = NULL, pending_manifest_json = NULL, operation_started_at = NULL,
    updated_by = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ? AND revision = ?
SQL)->execute([
                    $newRevision,
                    $built['manifest_json'],
                    $built['source_hash'],
                    (int) $liveUser['id'],
                    $shareId,
                    $newRevision,
                ]);
                audit($this->pdo, (int) $liveUser['id'], empty($oldShare['enabled'])
                    ? 'static_publication_started' : 'static_publication_updated', 'page', $rootPageId, [
                    'public_slug' => $slug,
                    'revision' => $newRevision,
                    'page_count' => $built['page_count'],
                    'source_hash' => $built['source_hash'],
                ]);
                $this->pdo->commit();
                $committed = true;
                $recoveryPending = false;
                try {
                    $this->publisher->recoverForRootUnderLock($rootPageId, $slug);
                } catch (Throwable $cleanupException) {
                    if ($cleanupException instanceof StaticPublicationException
                        && $cleanupException->publicCode === 'publication_permissions_unsafe') {
                        throw $cleanupException;
                    }
                    $recoveryPending = true;
                    error_log('OpenConcept static publication backup cleanup failed: ' . get_class($cleanupException));
                }
                $state = $this->stateForUserAfterRecovery(
                    $rootPageId,
                    $liveUser,
                    $expectedPasswordFingerprint,
                    $baseUrl
                );
                return $recoveryPending ? $this->recoveryPendingState($state) : $state;
            } catch (Throwable $exception) {
                if (!$committed && !$journalPrepared && $swap !== null) {
                    try {
                        $this->publisher->restoreSwap($swap);
                    } catch (Throwable $restoreException) {
                        error_log('OpenConcept static publication rollback failed: ' . get_class($restoreException));
                    }
                } elseif (!$committed && !$journalPrepared && $retired !== null) {
                    try {
                        $this->publisher->restoreRetired($retired);
                    } catch (Throwable $restoreException) {
                        error_log('OpenConcept static publication stop rollback failed: ' . get_class($restoreException));
                    }
                }
                if (!$committed && !$journalPrepared && $stagePath !== '') {
                    try {
                        $this->publisher->cleanupStage($stagePath);
                    } catch (Throwable $stageCleanupException) {
                    }
                }
                $databaseRollbackSucceeded = !$this->pdo->inTransaction();
                if (!$committed && $this->pdo->inTransaction()) {
                    try {
                        $this->pdo->rollBack();
                        $databaseRollbackSucceeded = true;
                    } catch (Throwable $rollbackException) {
                        $databaseRollbackSucceeded = false;
                        error_log('OpenConcept static publication database rollback failed: ' . get_class($rollbackException));
                    }
                }
                if (!$committed && $journalPrepared && $databaseRollbackSucceeded) {
                    try {
                        $this->publisher->recoverForRootUnderLock($rootPageId, $slug);
                    } catch (Throwable $recoveryException) {
                        error_log('OpenConcept static publication journal cleanup failed: ' . get_class($recoveryException));
                    }
                }
                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    public function refreshForUser(
        int $rootPageId,
        ?int $expectedRevision,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null
    ): array {
        $this->assertCanManagePreflight($rootPageId, $requestUser, $expectedPasswordFingerprint);
        $this->publisher->recoverForRoot($rootPageId);
        $share = $this->shareRow($rootPageId);
        if (!is_array($share) || empty($share['enabled'])) {
            throw new RuntimeException('更新する静的サイトがありません。', 404);
        }
        $statement = $this->pdo->prepare('SELECT page_id FROM page_public_share_pages WHERE share_id = ? ORDER BY page_id');
        $statement->execute([(int) $share['id']]);
        return $this->updateForUser(
            $rootPageId,
            true,
            array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)),
            $expectedRevision,
            $requestUser,
            $expectedPasswordFingerprint,
            $applicationBaseUrl,
            (string) $share['public_slug']
        );
    }

    /**
     * Returns only publications the current actor may manage. This is suitable
     * for mutation responses and never discloses another owner's site URL.
     * @return array<int, array<string, mixed>>
     */
    public function affectedPublicationsForUser(
        int $pageId,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null
    ): array {
        return $this->affectedPublicationsForPagesForUser(
            [$pageId],
            $requestUser,
            $expectedPasswordFingerprint,
            $applicationBaseUrl
        );
    }

    /**
     * @param array<int, mixed> $pageIds
     * @return array<int, array<string, mixed>>
     */
    public function affectedPublicationsForPagesForUser(
        array $pageIds,
        array $requestUser,
        string $expectedPasswordFingerprint,
        ?string $applicationBaseUrl = null
    ): array {
        $normalized = [];
        foreach ($pageIds as $pageId) {
            if (is_int($pageId)) {
                $id = $pageId;
            } elseif (is_string($pageId) && preg_match('/^[1-9][0-9]*$/D', $pageId) === 1) {
                $id = (int) $pageId;
            } else {
                continue;
            }
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }
        $pageIds = array_values($normalized);
        sort($pageIds, SORT_NUMERIC);
        if ($pageIds === []) {
            return [];
        }
        if (count($pageIds) > StaticPublicSitePublisher::MAX_PAGES) {
            throw new RuntimeException('Too many pages were supplied for publication impact lookup.', 422);
        }
        $ownsSnapshot = $this->beginReadSnapshot();
        try {
            $liveUser = authenticatedApplicationUserForReadSnapshot(
                $this->pdo,
                $requestUser,
                $expectedPasswordFingerprint
            );
            if ($liveUser === null) {
                if ($ownsSnapshot) {
                    $this->pdo->commit();
                }
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
            $statement = $this->pdo->prepare(<<<SQL
SELECT DISTINCT s.*, p.title, p.icon, p.status, p.visibility, p.author_id, p.access_department, p.archived_at
FROM page_public_shares s
JOIN pages p ON p.id = s.root_page_id
LEFT JOIN page_public_share_pages sp ON sp.share_id = s.id
WHERE s.enabled = 1
  AND (s.root_page_id IN ({$placeholders}) OR sp.page_id IN ({$placeholders}))
ORDER BY s.root_page_id
SQL);
            $statement->execute([...$pageIds, ...$pageIds]);
            $result = [];
            $baseUrl = $applicationBaseUrl ?? baseUrl();
            foreach ($statement->fetchAll() as $share) {
                // s.id is the share row id, not a page id. Authorization helpers
                // intentionally reload by page id, so shape this as the root page
                // before checking access or a coincidental page/share id could
                // authorize the wrong publication URL.
                $rootForAuthorization = $share;
                $rootForAuthorization['id'] = (int) $share['root_page_id'];
                if (!canEditPage($this->pdo, $liveUser, $rootForAuthorization)) {
                    continue;
                }
                $result[] = [
                    'root_page_id' => (int) $share['root_page_id'],
                    'title' => (string) $share['title'],
                    'public_url' => $this->publisher->siteBaseUrl((string) $share['public_slug'], $baseUrl) . '/',
                    'revision' => (int) $share['revision'],
                ];
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

    /** @param array<int, int> $pageIds @return array<int, int> */
    public function enabledPublicationRootIdsForPages(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn(int $id): bool => $id > 0)));
        if ($pageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $statement = $this->pdo->prepare(<<<SQL
SELECT DISTINCT s.root_page_id
FROM page_public_shares s
LEFT JOIN page_public_share_pages sp ON sp.share_id = s.id
WHERE s.enabled = 1 AND (s.root_page_id IN ({$placeholders}) OR sp.page_id IN ({$placeholders}))
ORDER BY s.root_page_id
SQL);
        $statement->execute([...$pageIds, ...$pageIds]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<int, int> $pageIds @return array<int, int> */
    public function reservedPublicationRootIdsForPages(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn(int $id): bool => $id > 0)));
        if ($pageIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT root_page_id FROM page_public_shares WHERE root_page_id IN ({$placeholders}) ORDER BY root_page_id"
        );
        $statement->execute($pageIds);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Legacy token routes are intentionally retired; public files are static. */
    public function pageForToken(string $token, ?int $requestedPageId = null): array
    {
        throw new RuntimeException('この公開URLは廃止されました。固定URLを使用してください。', 410);
    }

    /** Legacy token routes are intentionally retired; public images are static. */
    public function imageForToken(string $token, int $pageId, int $fileId): array
    {
        throw new RuntimeException('この公開URLは廃止されました。固定URLを使用してください。', 410);
    }

    private function assertCanManagePreflight(
        int $rootPageId,
        array $requestUser,
        string $expectedPasswordFingerprint
    ): void {
        if (!validPasswordFingerprint($expectedPasswordFingerprint)) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id, name, email, password_hash, avatar_color, avatar_kind, avatar_value, ui_locale,
       role, department, must_change_password, invited_at, last_login_at, active
FROM users
WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')
LIMIT 1
SQL);
        $statement->execute([(int) ($requestUser['id'] ?? 0)]);
        $databaseUser = $statement->fetch();
        if (!is_array($databaseUser) || !hash_equals(
            hash('sha256', (string) ($databaseUser['password_hash'] ?? '')),
            $expectedPasswordFingerprint
        )) {
            throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
        }
        $liveUser = array_merge($requestUser, $databaseUser);
        unset($liveUser['password_hash']);
        $liveUser['id'] = (int) $liveUser['id'];
        $root = $this->pageRow($rootPageId);
        if ($root === null) {
            throw new RuntimeException('ページが見つかりません。', 404);
        }
        if (!canEditPage($this->pdo, $liveUser, $root)) {
            throw new RuntimeException('このページのWeb公開を管理する権限がありません。', 403);
        }
    }

    /** @return array<string, mixed> */
    private function managementState(array $root, array $user, string $applicationBaseUrl): array
    {
        $rootPageId = (int) $root['id'];
        $share = $this->shareRow($rootPageId);
        $selectedIds = [];
        if (is_array($share)) {
            $statement = $this->pdo->prepare('SELECT page_id FROM page_public_share_pages WHERE share_id = ? ORDER BY page_id');
            $statement->execute([(int) $share['id']]);
            $selectedIds = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        }
        $selectedSet = array_fill_keys($selectedIds, true);
        $subtree = $this->subtreeRows($rootPageId);
        $descendants = [];
        $visibleSelectedCount = 0;
        foreach ($subtree as $entry) {
            $page = $entry['page'];
            $pageId = (int) $page['id'];
            if ($pageId === $rootPageId || !canViewPage($this->pdo, $user, $page)) {
                continue;
            }
            if (isset($selectedSet[$pageId])) {
                $visibleSelectedCount++;
            }
            $descendants[] = [
                'id' => $pageId,
                'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
                'depth' => max(1, (int) $entry['depth']),
                'title' => (string) $page['title'],
                'icon' => (string) $page['icon'],
                'publishable' => (string) ($page['status'] ?? '') === 'published',
                'can_edit' => canEditPage($this->pdo, $user, $page),
            ];
        }
        $visibleIds = array_fill_keys(array_column($descendants, 'id'), true);
        $visibleSelectedIds = array_values(array_filter($selectedIds, static fn(int $id): bool => isset($visibleIds[$id])));
        sort($visibleSelectedIds, SORT_NUMERIC);

        $enabled = is_array($share) && !empty($share['enabled']);
        $slug = is_array($share) && trim((string) ($share['public_slug'] ?? '')) !== ''
            ? (string) $share['public_slug'] : 'page-' . $rootPageId;
        $publicUrl = $enabled ? $this->publisher->siteBaseUrl($slug, $applicationBaseUrl) . '/' : null;
        $publicationState = 'unpublished';
        $integrity = 'not_published';
        $canUpdate = false;
        $canStop = false;
        if ($enabled) {
            $inspection = $this->publisher->inspect($share);
            $publicationState = $inspection['status'];
            $integrity = $inspection['integrity'];
            if ((string) ($share['operation_state'] ?? 'idle') !== 'idle') {
                $publicationState = 'failed';
            } elseif ($publicationState === 'current') {
                try {
                    $rows = [];
                    foreach ($subtree as $entry) {
                        $rows[(int) $entry['page']['id']] = $entry['page'];
                    }
                    $snapshot = $this->publicationSnapshot(
                        $rows,
                        $rootPageId,
                        $selectedIds,
                        (string) ($share['publication_locale'] ?? 'en-US')
                    );
                    $snapshot['application_base_url'] = $applicationBaseUrl;
                    $snapshot['site_base_url'] = $this->publisher->siteBaseUrl(
                        (string) $share['public_slug'],
                        $applicationBaseUrl
                    );
                    $currentHash = $this->publisher->sourceFingerprint($snapshot);
                    if (!hash_equals((string) ($share['source_hash'] ?? ''), $currentHash)
                        || (int) ($share['published_revision'] ?? 0) !== (int) $share['revision']) {
                        $publicationState = 'stale';
                    }
                } catch (Throwable) {
                    $publicationState = 'stale';
                }
            }
            $canUpdate = in_array($publicationState, ['current', 'stale', 'failed'], true)
                && $integrity === 'verified' && (string) ($root['status'] ?? '') === 'published';
            $canStop = $integrity === 'verified';
        }

        return [
            'enabled' => $enabled,
            'public_url' => $publicUrl,
            'public_slug' => $slug,
            'slug_locked' => is_array($share) && trim((string) ($share['public_slug'] ?? '')) !== '',
            'revision' => is_array($share) ? (int) $share['revision'] : null,
            'selected_page_ids' => $visibleSelectedIds,
            'descendants' => $descendants,
            'hidden_selected_count' => max(0, count($selectedIds) - $visibleSelectedCount),
            'root_publishable' => (string) ($root['status'] ?? '') === 'published',
            'publication_state' => $publicationState,
            'recovery_pending' => false,
            'integrity_status' => $integrity,
            'can_update' => $canUpdate,
            'can_stop' => $canStop,
            'published_at' => is_array($share) ? ($share['published_at'] ?? null) : null,
            'published_page_count' => is_array($share) ? $this->publishedPageCount((string) ($share['manifest_json'] ?? '')) : 0,
        ];
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function recoveryPendingState(array $state): array
    {
        $state['publication_state'] = 'failed';
        $state['recovery_pending'] = true;
        $state['can_update'] = false;
        $state['can_stop'] = false;
        return $state;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $selectedPageIds
     * @return array<string, mixed>
     */
    private function publicationSnapshot(array $rows, int $rootPageId, array $selectedPageIds, string $publicationLocale): array
    {
        $byId = [];
        $children = [];
        foreach ($rows as $row) {
            if (!is_array($row) || (int) ($row['id'] ?? 0) < 1 || ($row['archived_at'] ?? null) !== null) {
                continue;
            }
            $pageId = (int) $row['id'];
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $row['id'] = $pageId;
            $row['parent_id'] = $row['parent_id'] === null ? null : $parentId;
            $byId[$pageId] = $row;
            $children[$parentId][] = $pageId;
        }
        if (!isset($byId[$rootPageId]) || (string) ($byId[$rootPageId]['status'] ?? '') !== 'published') {
            throw new RuntimeException('公開親ページは現在の公開状態ではありません。', 422);
        }
        foreach ($children as &$childIds) {
            usort($childIds, static function (int $left, int $right) use (&$byId): int {
                return ((int) ($byId[$left]['sort_order'] ?? 0) <=> (int) ($byId[$right]['sort_order'] ?? 0))
                    ?: ($left <=> $right);
            });
        }
        unset($childIds);
        $selectedSet = array_fill_keys($selectedPageIds, true);
        $selectedSet[$rootPageId] = true;
        $navigation = [];
        $included = [];
        $visited = [];
        $walk = function (int $pageId, ?int $visibleParentId, int $depth) use (
            &$walk,
            &$navigation,
            &$included,
            &$visited,
            $byId,
            $children,
            $selectedSet
        ): void {
            if (isset($visited[$pageId]) || !isset($byId[$pageId])) {
                return;
            }
            $visited[$pageId] = true;
            $page = $byId[$pageId];
            $isIncluded = isset($selectedSet[$pageId]) && (string) ($page['status'] ?? '') === 'published';
            $nextParent = $visibleParentId;
            $nextDepth = $depth;
            if ($isIncluded) {
                $included[$pageId] = $page;
                $navigation[] = [
                    'id' => $pageId,
                    'parent_id' => $visibleParentId,
                    'depth' => $depth,
                    'title' => (string) $page['title'],
                    'icon' => (string) $page['icon'],
                ];
                $nextParent = $pageId;
                $nextDepth++;
            }
            foreach ($children[$pageId] ?? [] as $childId) {
                $walk($childId, $nextParent, $nextDepth);
            }
        };
        $walk($rootPageId, null, 0);
        if (count($included) !== count($selectedSet)) {
            throw new RuntimeException('公開対象に、非公開・アーカイブ済み・移動済みのページが含まれています。', 422);
        }
        if (count($included) > StaticPublicSitePublisher::MAX_PAGES) {
            throw new RuntimeException('一度に静的公開できるページ数を超えています。', 422);
        }
        $pages = [];
        $pagePaths = [];
        foreach ($included as $pageId => $page) {
            $page['blocks'] = json_decode((string) ($page['blocks_json'] ?? '[]'), true) ?: [];
            // RAG-generated tags are asynchronous derived data. Publishing only
            // editor-authored tags keeps the static generation deterministic and
            // ensures every visible publication change originates from a write
            // path that can ask the editor whether to update the fixed URL.
            $page['tags'] = json_decode((string) ($page['manual_tags_json'] ?? '[]'), true) ?: [];
            $pages[$pageId] = [
                'id' => (int) $pageId,
                'title' => (string) $page['title'],
                'icon' => (string) $page['icon'],
                'cover' => (string) $page['cover'],
                'category' => (string) $page['category'],
                'tags' => $page['tags'],
                'blocks' => $page['blocks'],
                'plain_text' => (string) ($page['plain_text'] ?? ''),
                'language_code' => (string) ($page['language_code'] ?? 'und'),
                'updated_at' => (string) ($page['updated_at'] ?? ''),
            ];
            $pagePaths[$pageId] = (int) $pageId === $rootPageId ? 'index.html' : 'pages/page-' . (int) $pageId . '.html';
        }
        return [
            'root_page_id' => $rootPageId,
            'pages' => $pages,
            'navigation' => $navigation,
            'included_page_ids' => array_map('intval', array_keys($included)),
            'page_paths' => $pagePaths,
            'image_paths' => [],
            'publication_locale' => $publicationLocale,
            'workspace_name' => $this->setting('workspace_name', 'OpenConcept'),
            'organization_name' => $this->setting('organization_name', ''),
        ];
    }

    /**
     * @param array<string, mixed> $lockedSubtree
     * @param array<string, mixed>|null $share
     * @return array<int, int>
     */
    private function authorizedSelection(
        int $rootPageId,
        array $requestedPageIds,
        array $lockedSubtree,
        array $user,
        ?array $share
    ): array {
        $subtreeSet = array_fill_keys(array_map('intval', $lockedSubtree['ids']), true);
        foreach ($requestedPageIds as $pageId) {
            $page = $lockedSubtree['rows'][$pageId] ?? null;
            if (!isset($subtreeSet[$pageId]) || !is_array($page) || !canViewPage($this->pdo, $user, $page)) {
                throw new RuntimeException('公開対象には閲覧できる子孫ページだけを指定してください。', 403);
            }
            if ((string) ($page['status'] ?? '') !== 'published' || !canEditPage($this->pdo, $user, $page)) {
                throw new RuntimeException('公開済みで編集権限のあるページだけを静的サイトへ追加できます。', 422);
            }
        }
        if (is_array($share)) {
            $statement = $this->pdo->prepare('SELECT page_id FROM page_public_share_pages WHERE share_id = ?');
            $statement->execute([(int) $share['id']]);
            foreach (array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)) as $existingId) {
                $page = $lockedSubtree['rows'][$existingId] ?? null;
                if (!is_array($page) || !canViewPage($this->pdo, $user, $page)) {
                    throw new RuntimeException('閲覧できない既存の公開対象があります。設定更新の前に管理者へ確認してください。', 403);
                }
            }
        }
        $selection = array_values(array_unique(array_map('intval', $requestedPageIds)));
        sort($selection, SORT_NUMERIC);
        return $selection;
    }

    /** @return array<int, array{page: array<string, mixed>, depth: int}> */
    private function subtreeRows(int $rootPageId): array
    {
        $rows = $this->pdo->query('SELECT * FROM pages WHERE archived_at IS NULL')->fetchAll();
        $byId = [];
        $children = [];
        foreach ($rows as $row) {
            $pageId = (int) $row['id'];
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $byId[$pageId] = $row;
            $children[$parentId][] = $pageId;
        }
        foreach ($children as &$childIds) {
            usort($childIds, static function (int $left, int $right) use (&$byId): int {
                return ((int) ($byId[$left]['sort_order'] ?? 0) <=> (int) ($byId[$right]['sort_order'] ?? 0))
                    ?: ($left <=> $right);
            });
        }
        unset($childIds);
        if (!isset($byId[$rootPageId])) {
            return [];
        }
        $result = [];
        $visited = [];
        $queue = [[$rootPageId, 0]];
        for ($index = 0; $index < count($queue); $index++) {
            [$pageId, $depth] = $queue[$index];
            if (isset($visited[$pageId]) || !isset($byId[$pageId])) {
                continue;
            }
            $visited[$pageId] = true;
            $result[] = ['page' => $byId[$pageId], 'depth' => $depth];
            foreach ($children[$pageId] ?? [] as $childId) {
                $queue[] = [$childId, $depth + 1];
            }
        }
        return $result;
    }

    /** @return array<string, mixed>|null */
    private function shareRow(int $rootPageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM page_public_shares WHERE root_page_id = ?');
        $statement->execute([$rootPageId]);
        $share = $statement->fetch();
        return is_array($share) ? $share : null;
    }

    /** @return array<string, mixed>|null */
    private function pageRow(int $pageId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pages WHERE id = ? AND archived_at IS NULL');
        $statement->execute([$pageId]);
        $page = $statement->fetch();
        return is_array($page) ? $page : null;
    }

    /** @param array<int, mixed> $pageIds @return array<int, int> */
    private function normalizeRequestedPageIds(array $pageIds, int $rootPageId): array
    {
        if (!array_is_list($pageIds) || count($pageIds) > self::MAX_SELECTED_PAGES) {
            throw new RuntimeException('公開対象ページの指定が正しくありません。', 422);
        }
        $result = [];
        foreach ($pageIds as $pageId) {
            if (is_int($pageId)) {
                $normalized = $pageId;
            } elseif (is_string($pageId) && preg_match('/^[1-9][0-9]*$/D', $pageId) === 1) {
                $normalized = (int) $pageId;
            } else {
                throw new RuntimeException('公開対象ページの指定が正しくありません。', 422);
            }
            if ($normalized > 0 && $normalized !== $rootPageId) {
                $result[$normalized] = $normalized;
            }
        }
        $result = array_values($result);
        sort($result, SORT_NUMERIC);
        return $result;
    }

    private function publicationLocale(array $root, array $user): string
    {
        $available = array_column($this->defaultI18n()->availableLocales(), 'code');
        $pageLocale = (string) ($root['language_code'] ?? 'und');
        if ($pageLocale !== 'und' && in_array($pageLocale, $available, true)) {
            return $pageLocale;
        }
        $uiLocale = (string) ($user['ui_locale'] ?? 'en-US');
        return in_array($uiLocale, $available, true) ? $uiLocale : 'en-US';
    }

    private function defaultI18n(): I18n
    {
        $i18n = new I18n('en-US');
        $i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        return $i18n;
    }

    private function newToken(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
                continue;
            }
            $statement = $this->pdo->prepare('SELECT 1 FROM page_public_shares WHERE token = ?');
            $statement->execute([$token]);
            if (!$statement->fetchColumn()) {
                return $token;
            }
        }
        throw new RuntimeException('公開サイトの発行IDを作成できませんでした。', 500);
    }

    private function beginReadSnapshot(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        if ((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->pdo->beginTransaction();
        return true;
    }

    private function setting(string $key, string $default): string
    {
        $statement = $this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : $default;
    }

    private function publishedPageCount(string $manifestJson): int
    {
        try {
            $manifest = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 0;
        }
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
            return 0;
        }
        $count = 0;
        foreach ($manifest['files'] as $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($path === 'index.html' || preg_match('~^pages/page-[1-9][0-9]*\.html$~D', $path) === 1) {
                $count++;
            }
        }
        return $count;
    }
}
