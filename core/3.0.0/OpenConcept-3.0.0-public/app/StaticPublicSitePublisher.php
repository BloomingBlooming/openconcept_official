<?php

declare(strict_types=1);

final class StaticPublicationException extends RuntimeException
{
    public function __construct(
        string $message,
        int $status,
        public readonly string $publicCode,
        public readonly ?string $adminMessage = null
    ) {
        parent::__construct($message, $status);
    }
}

/**
 * Writes and verifies self-contained static public sites.
 *
 * The database manifest is authoritative. Before an existing site is replaced
 * or removed, its signed marker, complete file set, sizes, and hashes must all
 * still match. This deliberately refuses to overwrite hand-edited artifacts.
 */
final class StaticPublicSitePublisher
{
    public const MANIFEST_SCHEMA = 'openconcept-static-publication/v1';
    public const JOURNAL_SCHEMA = 'openconcept-static-publication-journal/v1';
    public const MARKER_FILE = '.openconcept-publication.json';
    public const MAX_PAGES = 250;

    private string $publishRoot;
    private string $storageRoot;
    private string $applicationRoot;

    /** @var array<string, string> */
    private array $securedPrivatePaths = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly I18n $i18n,
        ?string $publishRoot = null,
        ?string $storageRoot = null
    ) {
        $this->applicationRoot = dirname(__DIR__);
        $configuredRoot = trim((string) getenv('OPENCONCEPT_PUBLIC_SITE_DIR'));
        $this->publishRoot = $this->resolveExistingDirectory(
            $publishRoot ?? ($configuredRoot !== '' ? $configuredRoot : $this->applicationRoot . '/published'),
            'public site root'
        );
        $this->storageRoot = $this->resolveExistingDirectory(
            $storageRoot ?? $this->applicationRoot . '/storage',
            'storage root'
        );
    }

    public function validateSlug(string $slug): string
    {
        $slug = trim($slug);
        if (strlen($slug) < 3 || strlen($slug) > 80
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
            || in_array($slug, ['assets', 'api', 'app', 'public', 'storage', 'admin', 'index'], true)) {
            throw new RuntimeException('公開URL名は3〜80文字の半角小文字・数字・ハイフンで指定してください。', 422);
        }
        return $slug;
    }

    public function siteBaseUrl(string $slug, string $applicationBaseUrl): string
    {
        $slug = $this->validateSlug($slug);
        $configured = rtrim(trim((string) getenv('OPENCONCEPT_PUBLIC_SITE_URL')), '/');
        $base = $configured !== '' ? $configured : rtrim($applicationBaseUrl, '/') . '/published';
        $parts = parse_url($base);
        if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('静的公開URLの設定が正しくありません。', 500);
        }
        return $base . '/' . $slug;
    }

    /** @template T @param callable(): T $callback @return T */
    public function withExclusiveLock(string $slug, callable $callback): mixed
    {
        $slug = $this->validateSlug($slug);
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'publication-locks';
        $this->ensurePrivateDirectory($directory);
        $path = $directory . DIRECTORY_SEPARATOR . hash('sha256', $slug) . '.lock';
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('The publication lock path is unsafe.', 500);
        }
        if (is_file($path)) {
            $this->securePrivateFile($path, 'publication lock', true);
        }
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            $this->permissionFailure($path, 'publication lock', 'Could not open the private lock file for reading and writing.');
        }
        try {
            if (is_link($path)) {
                throw new RuntimeException('The publication lock path is unsafe.', 500);
            }
            $this->securePrivateFile($path, 'publication lock', true);
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('静的サイトの発行ロックを取得できませんでした。', 500);
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Recover the one durable publication operation for a page, if present.
     * The optional slug hint is retained for caller/API compatibility only.
     * Discovery authenticates every well-known journal before routing by its
     * signed root_page_id, including when the database row did not survive.
     */
    public function recoverForRoot(int $rootPageId, ?string $slugHint = null): void
    {
        if ($rootPageId < 1 || $this->pdo->inTransaction()) {
            throw new RuntimeException('Publication recovery must run before a database transaction.', 500);
        }
        $paths = $this->journalCandidates($rootPageId);
        if ($paths === []) {
            return;
        }
        if (count($paths) !== 1) {
            $this->recoveryRequired();
        }
        $path = $paths[0];
        $loaded = $this->loadJournal($path, $rootPageId, null);
        $slug = (string) $loaded['journal']['public_slug'];
        $this->withExclusiveLock($slug, function () use ($rootPageId, $slug): void {
            $this->recoverForRootUnderLock($rootPageId, $slug);
        });
    }

    /** Caller must hold the publication lock for $slug. */
    public function recoverForRootUnderLock(int $rootPageId, string $slug): void
    {
        if ($rootPageId < 1 || $this->pdo->inTransaction()) {
            throw new RuntimeException('Publication recovery must run before a database transaction.', 500);
        }
        $slug = $this->validateSlug($slug);
        $path = $this->journalPath($slug, false);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $loaded = $this->loadJournal($path, $rootPageId, $slug);
        $this->recoverLoadedJournal($path, $loaded['journal'], $loaded['raw_sha256']);
    }

    /**
     * Persist the recovery fence after staging and before the first rename.
     *
     * @param array<string, mixed>|null $oldShare
     * @param array<int, mixed> $oldSelectedPageIds
     * @param array<string, mixed> $newShare final committed row values
     * @param array<int, mixed> $newSelectedPageIds
     */
    public function prepareActivationJournal(
        string $slug,
        string $operationId,
        string $stagePath,
        ?array $oldShare,
        array $oldSelectedPageIds,
        array $newShare,
        array $newSelectedPageIds,
        string $newManifestJson
    ): void {
        $slug = $this->validateSlug($slug);
        $this->assertOperationId($operationId);
        $stage = $this->assertManagedPath($stagePath, '.openconcept-stage-');
        if (preg_match(
            '/^\.openconcept-stage-' . preg_quote($slug, '/') . '-[a-f0-9]{24}$/D',
            basename($stage)
        ) !== 1) {
            throw new RuntimeException('The publication stage identity is invalid.', 500);
        }
        $oldDatabase = $this->databaseDescriptor($oldShare, $oldSelectedPageIds);
        $newDatabase = $this->databaseDescriptor($newShare, $newSelectedPageIds);
        $oldManifestJson = $this->enabledManifest($oldShare);
        $oldIdentity = $oldManifestJson === null ? null : $this->artifactIdentity($oldDatabase);
        $newIdentity = $this->artifactIdentity($newDatabase);
        if (!$newDatabase['row_exists'] || !$newDatabase['enabled'] || $newManifestJson === ''
            || !hash_equals((string) $newDatabase['manifest_sha256'], hash('sha256', $newManifestJson))
            || $this->verifyDirectoryForIdentity($stage, $newIdentity, $newManifestJson) !== 'verified') {
            throw new RuntimeException('The staged publication cannot be recovery-journaled.', 500);
        }
        if ($oldManifestJson !== null
            && $this->verifyTargetForIdentity($slug, $oldIdentity, $oldManifestJson) !== 'verified') {
            throw new StaticPublicationException(
                'The existing public artifact changed before recovery preparation.',
                409,
                'artifact_modified'
            );
        }
        $this->writePreparedJournal($this->journalPayload(
            'activate',
            $slug,
            $operationId,
            (int) $newDatabase['root_page_id'],
            $oldDatabase,
            $newDatabase,
            $oldManifestJson,
            $newManifestJson,
            basename($stage)
        ));
    }

    /**
     * @param array<string, mixed> $oldShare
     * @param array<int, mixed> $selectedPageIds
     * @param array<string, mixed> $newShare final committed row values
     */
    public function prepareRetirementJournal(
        string $slug,
        string $operationId,
        array $oldShare,
        array $selectedPageIds,
        array $newShare
    ): void {
        $slug = $this->validateSlug($slug);
        $this->assertOperationId($operationId);
        $oldDatabase = $this->databaseDescriptor($oldShare, $selectedPageIds);
        $newDatabase = $this->databaseDescriptor($newShare, $selectedPageIds);
        $oldManifestJson = $this->enabledManifest($oldShare);
        if ($oldManifestJson === null || !$oldDatabase['enabled'] || $newDatabase['enabled']
            || $this->verifyTargetForIdentity(
                $slug,
                $this->artifactIdentity($oldDatabase),
                $oldManifestJson
            ) !== 'verified') {
            throw new StaticPublicationException(
                'The existing public artifact cannot be recovery-journaled.',
                409,
                'artifact_modified'
            );
        }
        $this->writePreparedJournal($this->journalPayload(
            'retire',
            $slug,
            $operationId,
            (int) $oldDatabase['root_page_id'],
            $oldDatabase,
            $newDatabase,
            $oldManifestJson,
            null,
            null
        ));
    }

    /**
     * @param array<string, mixed> $share
     * @return array{status: string, integrity: string}
     */
    public function inspect(array $share): array
    {
        $slug = (string) ($share['public_slug'] ?? '');
        if ($slug === '' || empty($share['enabled'])) {
            return ['status' => 'unpublished', 'integrity' => 'not_published'];
        }
        $verification = $this->verifyTarget($share, (string) ($share['manifest_json'] ?? ''));
        if ($verification === 'verified') {
            return ['status' => 'current', 'integrity' => 'verified'];
        }
        return [
            'status' => $verification === 'missing' ? 'missing' : 'modified',
            'integrity' => $verification,
        ];
    }

    /**
     * Inventory runtime state that cannot be proven solely from enabled DB
     * rows. Deployment must stop while a recovery fence, transient generation,
     * unregistered site, or unsafe entry exists.
     *
     * @param array<int, mixed> $enabledSlugs
     * @return array{pending_recovery_journals:int,unsafe_journal_entries:int,transient_public_artifacts:int,orphaned_public_sites:int,unsafe_public_entries:int}
     */
    public function deploymentRuntimeState(array $enabledSlugs): array
    {
        $enabled = [];
        foreach ($enabledSlugs as $slug) {
            if (!is_string($slug)) {
                throw new RuntimeException('An enabled public-site slug is malformed.', 500);
            }
            $slug = $this->validateSlug($slug);
            if (isset($enabled[$slug])) {
                throw new RuntimeException('Enabled public-site slugs are not unique.', 500);
            }
            $enabled[$slug] = true;
        }

        $state = [
            'pending_recovery_journals' => 0,
            'unsafe_journal_entries' => 0,
            'transient_public_artifacts' => 0,
            'orphaned_public_sites' => 0,
            'unsafe_public_entries' => 0,
        ];
        $journalDirectory = $this->journalDirectory(false);
        if ($journalDirectory !== null) {
            $entries = @scandir($journalDirectory);
            if (!is_array($entries)) {
                throw new RuntimeException('Could not inspect publication recovery journals.', 500);
            }
            foreach ($entries as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $journalDirectory . DIRECTORY_SEPARATOR . $name;
                if (preg_match('/^[a-f0-9]{64}\.json$/D', $name) === 1
                    && !is_link($path) && is_file($path)) {
                    $state['pending_recovery_journals']++;
                } else {
                    $state['unsafe_journal_entries']++;
                }
            }
        }

        $entries = @scandir($this->publishRoot);
        if (!is_array($entries)) {
            throw new RuntimeException('Could not inspect the public-site root.', 500);
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->publishRoot . DIRECTORY_SEPARATOR . $name;
            if ($name === '.htaccess') {
                if (is_link($path) || (!is_file($path) && file_exists($path))) {
                    $state['unsafe_public_entries']++;
                }
                continue;
            }
            if (preg_match('/^\.openconcept-(?:stage|backup|failed)-/', $name) === 1) {
                $state['transient_public_artifacts']++;
                continue;
            }
            try {
                $slug = $this->validateSlug($name);
            } catch (Throwable) {
                $state['unsafe_public_entries']++;
                continue;
            }
            if (is_link($path) || !is_dir($path)) {
                $state['unsafe_public_entries']++;
            } elseif (!isset($enabled[$slug])) {
                $state['orphaned_public_sites']++;
            }
        }
        return $state;
    }

    /** @param array<string, mixed> $snapshot */
    public function sourceFingerprint(array $snapshot): string
    {
        $images = $this->collectImages($snapshot, null);
        return $this->fingerprint($snapshot, $images['descriptors']);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $share
     * @return array{stage_path: string, manifest_json: string, source_hash: string, page_count: int}
     */
    public function buildStage(
        array $snapshot,
        array $share,
        string $applicationBaseUrl,
        string $siteBaseUrl
    ): array {
        $slug = $this->validateSlug((string) ($share['public_slug'] ?? ''));
        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        if ($pages === [] || count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('一度に静的公開できるページは' . self::MAX_PAGES . '件までです。', 422);
        }
        $stage = $this->publishRoot . DIRECTORY_SEPARATOR . '.openconcept-stage-'
            . $slug . '-' . bin2hex(random_bytes(12));
        if (!mkdir($stage, 0755) || !is_dir($stage)) {
            throw new RuntimeException('静的サイトの一時領域を作成できませんでした。', 500);
        }
        @chmod($stage, 0755);
        try {
            $this->ensureChildDirectory($stage, 'assets');
            $this->ensureChildDirectory($stage, 'pages');
            $images = $this->collectImages($snapshot, $stage);
            $snapshot['image_paths'] = $images['paths'];

            $cssSource = $this->applicationRoot . '/public/assets/public-share.css';
            $css = file_get_contents($cssSource);
            if (!is_string($css)) {
                throw new RuntimeException('静的公開用スタイルを読み込めませんでした。', 500);
            }
            $this->writeFile($stage, 'assets/public-share.css', $css);

            $renderer = new PublicPageRenderer($this->i18n);
            $pagePaths = is_array($snapshot['page_paths'] ?? null) ? $snapshot['page_paths'] : [];
            foreach ($pages as $pageId => $page) {
                if (!is_array($page)) {
                    continue;
                }
                $pageId = (int) $pageId;
                $relativePath = (string) ($pagePaths[$pageId] ?? '');
                if ($relativePath === '') {
                    throw new RuntimeException('静的ページの出力先が不正です。', 500);
                }
                $context = $snapshot;
                $context['page'] = $page;
                $html = $renderer->html($context, $applicationBaseUrl, $siteBaseUrl, $relativePath);
                $this->writeFile($stage, $relativePath, $html);
            }
            $this->writeFile($stage, 'sitemap.xml', $this->sitemap($snapshot, $siteBaseUrl));
            $this->writeFile($stage, '.htaccess', $this->siteHtaccess());

            $sourceHash = $this->fingerprint($snapshot, $images['descriptors']);
            $files = $this->artifactFiles($stage, false);
            $key = $this->signingKey(true);
            $token = (string) ($share['token'] ?? '');
            $manifest = [
                'schema' => self::MANIFEST_SCHEMA,
                'signing_key_id' => substr(hash('sha256', $key), 0, 16),
                'publication_id' => substr(hash('sha256', $token), 0, 32),
                'share_id' => (int) ($share['id'] ?? 0),
                'root_page_id' => (int) ($share['root_page_id'] ?? 0),
                'public_slug' => $slug,
                'revision' => (int) ($share['revision'] ?? 0),
                'source_sha256' => $sourceHash,
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'files' => $files,
            ];
            $payload = $this->encodeJson($manifest);
            $manifest['signature'] = hash_hmac('sha256', $payload, $key);
            $manifestJson = $this->encodeJson($manifest);
            $this->writeFile($stage, self::MARKER_FILE, $manifestJson);
            if ($this->verifyDirectory($stage, $share, $manifestJson) !== 'verified') {
                throw new RuntimeException('生成した静的サイトの完全性を確認できませんでした。', 500);
            }
            $this->syncTreeDirectories($stage);
            $this->syncDirectory($this->publishRoot);
            return [
                'stage_path' => $stage,
                'manifest_json' => $manifestJson,
                'source_hash' => $sourceHash,
                'page_count' => count($pages),
            ];
        } catch (Throwable $exception) {
            $this->removeTree($stage);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $share */
    public function assertReplaceable(array $share): void
    {
        $slug = $this->validateSlug((string) ($share['public_slug'] ?? ''));
        $target = $this->targetPath($slug);
        if (!empty($share['enabled'])) {
            $status = $this->verifyTarget($share, (string) ($share['manifest_json'] ?? ''));
            if ($status !== 'verified') {
                throw new StaticPublicationException(
                    '公開ファイルが手動変更または欠落しているため、OpenConceptから更新できません。',
                    409,
                    $status === 'missing' ? 'artifact_missing' : 'artifact_modified'
                );
            }
            return;
        }
        if (file_exists($target) || is_link($target)) {
            throw new StaticPublicationException(
                '固定URLの出力先にはOpenConceptが管理していないファイルがあります。',
                409,
                'publication_conflict'
            );
        }
    }

    /**
     * Swap a completed stage into the stable path. The returned backup must be
     * finalized or restored by the caller after the database fence is settled.
     * @return array{target: string, backup: ?string}
     */
    public function activate(string $stagePath, string $slug, string $operationId): array
    {
        $slug = $this->validateSlug($slug);
        $this->assertOperationId($operationId);
        $stage = $this->assertManagedPath($stagePath, '.openconcept-stage-');
        $target = $this->targetPath($slug);
        $backup = null;
        if (file_exists($target) || is_link($target)) {
            if (is_link($target) || !is_dir($target)) {
                throw new RuntimeException('固定URLの出力先が安全なディレクトリではありません。', 409);
            }
            $backup = $this->publishRoot . DIRECTORY_SEPARATOR . '.openconcept-backup-' . $slug . '-' . $operationId;
            if (file_exists($backup) || !rename($target, $backup)) {
                throw new RuntimeException('現在の静的サイトを更新用に退避できませんでした。', 500);
            }
        }
        if (!rename($stage, $target)) {
            if ($backup !== null) {
                @rename($backup, $target);
            }
            $this->syncDirectory($this->publishRoot);
            throw new RuntimeException('静的サイトを固定URLへ切り替えられませんでした。', 500);
        }
        $this->syncDirectory($this->publishRoot);
        return ['target' => $target, 'backup' => $backup];
    }

    /**
     * Verify both sides after rename and before the caller commits metadata.
     * This closes the long build-time check/use window: a live tree changed by
     * an operator is restored by the caller instead of being adopted or lost.
     * @param array{target: string, backup: ?string} $swap
     * @param array<string, mixed>|null $oldShare
     * @param array<string, mixed> $newShare
     */
    public function assertActivated(
        array $swap,
        ?array $oldShare,
        array $newShare,
        string $newManifestJson
    ): void {
        $target = $this->assertManagedPath((string) $swap['target']);
        if ($this->verifyDirectory($target, $newShare, $newManifestJson) !== 'verified') {
            throw new StaticPublicationException(
                '切り替え後の静的サイトを検証できませんでした。',
                409,
                'artifact_modified'
            );
        }

        $backup = $swap['backup'] ?? null;
        if ($oldShare === null || empty($oldShare['enabled'])) {
            if (is_string($backup) && $backup !== '') {
                throw new StaticPublicationException(
                    '検証後に固定URLの出力先が変更されました。',
                    409,
                    'publication_conflict'
                );
            }
            return;
        }
        if (!is_string($backup) || $backup === ''
            || $this->verifyDirectory(
                $this->assertManagedPath($backup, '.openconcept-backup-'),
                $oldShare,
                (string) ($oldShare['manifest_json'] ?? '')
            ) !== 'verified') {
            throw new StaticPublicationException(
                '公開ファイルが検証後に手動変更されたため更新を中止しました。',
                409,
                'artifact_modified'
            );
        }
    }

    /** @param array{target: string, backup: ?string} $swap */
    public function restoreSwap(array $swap): void
    {
        $target = $this->assertManagedPath((string) $swap['target']);
        $backup = $swap['backup'] === null ? null : $this->assertManagedPath((string) $swap['backup'], '.openconcept-backup-');
        $failed = $this->publishRoot . DIRECTORY_SEPARATOR . '.openconcept-failed-' . bin2hex(random_bytes(10));
        if (file_exists($failed) || is_link($failed)) {
            throw new RuntimeException('The failed-publication recovery path is already occupied.', 409);
        }
        if ((file_exists($target) || is_link($target)) && !rename($target, $failed)) {
            throw new RuntimeException('失敗した静的サイトを退避できませんでした。', 500);
        }
        if ($backup !== null && is_dir($backup) && !rename($backup, $target)) {
            $this->syncDirectory($this->publishRoot);
            throw new RuntimeException('以前の静的サイトを復元できませんでした。', 500);
        }
        if (is_dir($failed)) {
            $this->removeTree($failed);
            return;
        }
        $this->syncDirectory($this->publishRoot);
    }

    /** @param array{target: string, backup: ?string} $swap @param array<string, mixed>|null $oldShare */
    public function finalizeSwap(array $swap, ?array $oldShare = null): void
    {
        $backup = $swap['backup'] ?? null;
        if (!is_string($backup) || $backup === '') {
            return;
        }
        $backup = $this->assertManagedPath($backup, '.openconcept-backup-');
        if ($oldShare !== null
            && $this->verifyDirectory($backup, $oldShare, (string) ($oldShare['manifest_json'] ?? '')) !== 'verified') {
            return;
        }
        $this->removeTree($backup);
    }

    /** @param array<string, mixed> $share @return array{target: string, backup: string} */
    public function retire(array $share, string $operationId): array
    {
        $this->assertOperationId($operationId);
        $status = $this->verifyTarget($share, (string) ($share['manifest_json'] ?? ''));
        if ($status !== 'verified') {
            throw new StaticPublicationException(
                '公開ファイルが手動変更または欠落しているため、OpenConceptから削除できません。',
                409,
                $status === 'missing' ? 'artifact_missing' : 'artifact_modified'
            );
        }
        $slug = $this->validateSlug((string) $share['public_slug']);
        $target = $this->targetPath($slug);
        $backup = $this->publishRoot . DIRECTORY_SEPARATOR . '.openconcept-backup-' . $slug . '-' . $operationId;
        if (!rename($target, $backup)) {
            throw new RuntimeException('静的サイトを公開URLから取り外せませんでした。', 500);
        }
        $this->syncDirectory($this->publishRoot);
        return ['target' => $target, 'backup' => $backup];
    }

    /** @param array{target: string, backup: string} $retired @param array<string, mixed> $share */
    public function assertRetired(array $retired, array $share): void
    {
        $backup = $this->assertManagedPath((string) $retired['backup'], '.openconcept-backup-');
        if ($this->verifyDirectory($backup, $share, (string) ($share['manifest_json'] ?? '')) !== 'verified') {
            throw new StaticPublicationException(
                '公開ファイルが検証後に手動変更されたため公開停止を中止しました。',
                409,
                'artifact_modified'
            );
        }
    }

    /** @param array{target: string, backup: string} $retired */
    public function restoreRetired(array $retired): void
    {
        $target = $this->assertManagedPath((string) $retired['target']);
        $backup = $this->assertManagedPath((string) $retired['backup'], '.openconcept-backup-');
        if (is_dir($backup) && !rename($backup, $target)) {
            throw new RuntimeException('公開停止前の静的サイトを復元できませんでした。', 500);
        }
        $this->syncDirectory($this->publishRoot);
    }

    /** @param array{target: string, backup: string} $retired @param array<string, mixed> $share */
    public function finalizeRetired(array $retired, array $share): void
    {
        $backup = $this->assertManagedPath((string) $retired['backup'], '.openconcept-backup-');
        if ($this->verifyDirectory($backup, $share, (string) ($share['manifest_json'] ?? '')) !== 'verified') {
            return;
        }
        $this->removeTree($backup);
    }

    public function cleanupStage(string $stagePath): void
    {
        if ($stagePath === '') {
            return;
        }
        $stage = $this->assertManagedPath($stagePath, '.openconcept-stage-');
        if (is_dir($stage)) {
            $this->removeTree($stage);
        }
    }

    /** @param array<string, mixed> $share */
    private function verifyTarget(array $share, string $manifestJson): string
    {
        $slug = (string) ($share['public_slug'] ?? '');
        if ($slug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            return 'unowned';
        }
        $target = $this->targetPath($slug);
        if (!file_exists($target) && !is_link($target)) {
            return 'missing';
        }
        if (is_link($target) || !is_dir($target)) {
            return 'modified';
        }
        return $this->verifyDirectoryForIdentity(
            $target,
            $this->artifactIdentity($this->databaseDescriptor($share, [])),
            $manifestJson
        );
    }

    /** @param array<string, mixed> $share */
    private function verifyDirectory(string $directory, array $share, string $manifestJson): string
    {
        try {
            $manifest = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $manifest = null;
        }
        $descriptor = $this->databaseDescriptor($share, []);
        if (is_array($manifest)) {
            $descriptor['source_hash'] = (string) ($manifest['source_sha256'] ?? '');
        }
        return $this->verifyDirectoryForIdentity(
            $directory,
            $this->artifactIdentity($descriptor),
            $manifestJson
        );
    }

    /** @param array<string, mixed> $identity */
    private function verifyDirectoryForIdentity(string $directory, array $identity, string $manifestJson): string
    {
        $directoryStat = @lstat($directory);
        if ($directoryStat === false || is_link($directory)
            || (((int) ($directoryStat['mode'] ?? 0) & 0170000) === 0120000)) {
            return 'modified';
        }
        if ($manifestJson === '') {
            return 'unowned';
        }
        try {
            $manifest = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'unowned';
        }
        if (!is_array($manifest) || (string) ($manifest['schema'] ?? '') !== self::MANIFEST_SCHEMA
            || (int) ($manifest['share_id'] ?? 0) !== (int) ($identity['share_id'] ?? 0)
            || (int) ($manifest['root_page_id'] ?? 0) !== (int) ($identity['root_page_id'] ?? 0)
            || (string) ($manifest['public_slug'] ?? '') !== (string) ($identity['public_slug'] ?? '')
            || (string) ($manifest['publication_id'] ?? '') !== (string) ($identity['publication_id'] ?? '')
            || (int) ($manifest['revision'] ?? 0) !== (int) ($identity['revision'] ?? 0)
            || (string) ($manifest['source_sha256'] ?? '') !== (string) ($identity['source_sha256'] ?? '')
            || !is_array($manifest['files'] ?? null)) {
            return 'unowned';
        }
        $signature = (string) ($manifest['signature'] ?? '');
        unset($manifest['signature']);
        $key = $this->signingKey(false);
        if ($key === null || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || (string) ($manifest['signing_key_id'] ?? '') !== substr(hash('sha256', $key), 0, 16)
            || !hash_equals(hash_hmac('sha256', $this->encodeJson($manifest), $key), $signature)) {
            return 'unowned';
        }
        $markerPath = $directory . DIRECTORY_SEPARATOR . self::MARKER_FILE;
        if (is_link($markerPath) || !is_file($markerPath)) {
            return 'modified';
        }
        $marker = file_get_contents($markerPath);
        if (!is_string($marker) || !hash_equals($manifestJson, $marker)) {
            return 'modified';
        }
        $expected = [self::MARKER_FILE => true];
        foreach ($manifest['files'] as $file) {
            if (!is_array($file)) {
                return 'unowned';
            }
            $path = (string) ($file['path'] ?? '');
            if (!$this->safeRelativePath($path) || isset($expected[$path])) {
                return 'unowned';
            }
            $expected[$path] = true;
            $absolute = $directory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (is_link($absolute) || !is_file($absolute)
                || filesize($absolute) !== (int) ($file['bytes'] ?? -1)
                || !hash_equals((string) ($file['sha256'] ?? ''), (string) hash_file('sha256', $absolute))) {
                return 'modified';
            }
        }
        try {
            $actual = $this->artifactFiles($directory, true);
        } catch (Throwable) {
            return 'modified';
        }
        $actualPaths = array_column($actual, 'path');
        sort($actualPaths, SORT_STRING);
        $expectedPaths = array_keys($expected);
        sort($expectedPaths, SORT_STRING);
        return $actualPaths === $expectedPaths ? 'verified' : 'modified';
    }

    /** @param array<string, mixed> $identity */
    private function verifyTargetForIdentity(string $slug, array $identity, string $manifestJson): string
    {
        $target = $this->targetPath($slug);
        if (!file_exists($target) && !is_link($target)) {
            return 'missing';
        }
        if (is_link($target) || !is_dir($target)) {
            return 'modified';
        }
        return $this->verifyDirectoryForIdentity($target, $identity, $manifestJson);
    }

    /** @param array<string, mixed>|null $share */
    private function enabledManifest(?array $share): ?string
    {
        if (!is_array($share) || empty($share['enabled'])) {
            return null;
        }
        $manifest = $share['manifest_json'] ?? null;
        return is_string($manifest) && $manifest !== '' ? $manifest : null;
    }

    /**
     * @param array<string, mixed>|null $share
     * @param array<int, mixed> $selectedPageIds
     * @return array<string, mixed>
     */
    private function databaseDescriptor(?array $share, array $selectedPageIds): array
    {
        $selected = [];
        foreach ($selectedPageIds as $pageId) {
            if ((is_int($pageId) || (is_string($pageId) && preg_match('/^[1-9][0-9]*$/D', $pageId) === 1))
                && (int) $pageId > 0) {
                $selected[(int) $pageId] = (int) $pageId;
            }
        }
        $selected = array_values($selected);
        sort($selected, SORT_NUMERIC);
        if (!is_array($share)) {
            return [
                'row_exists' => false,
                'share_id' => null,
                'root_page_id' => null,
                'public_slug' => null,
                'publication_id' => null,
                'enabled' => false,
                'revision' => null,
                'published_revision' => null,
                'manifest_sha256' => null,
                'source_hash' => null,
                'publication_locale' => null,
                'selected_page_ids' => [],
            ];
        }
        $manifest = $share['manifest_json'] ?? null;
        $sourceHash = $share['source_hash'] ?? null;
        $locale = $share['publication_locale'] ?? null;
        $publishedRevision = $share['published_revision'] ?? null;
        return [
            'row_exists' => true,
            'share_id' => (int) ($share['id'] ?? 0),
            'root_page_id' => (int) ($share['root_page_id'] ?? 0),
            'public_slug' => (string) ($share['public_slug'] ?? ''),
            'publication_id' => substr(hash('sha256', (string) ($share['token'] ?? '')), 0, 32),
            'enabled' => !empty($share['enabled']),
            'revision' => (int) ($share['revision'] ?? 0),
            'published_revision' => $publishedRevision === null ? null : (int) $publishedRevision,
            'manifest_sha256' => is_string($manifest) && $manifest !== '' ? hash('sha256', $manifest) : null,
            'source_hash' => is_string($sourceHash) && $sourceHash !== '' ? $sourceHash : null,
            'publication_locale' => is_string($locale) && $locale !== '' ? $locale : null,
            'selected_page_ids' => $selected,
        ];
    }

    /** @param array<string, mixed> $database @return array<string, mixed> */
    private function artifactIdentity(array $database): array
    {
        return [
            'share_id' => (int) ($database['share_id'] ?? 0),
            'root_page_id' => (int) ($database['root_page_id'] ?? 0),
            'public_slug' => (string) ($database['public_slug'] ?? ''),
            'publication_id' => (string) ($database['publication_id'] ?? ''),
            'revision' => (int) ($database['revision'] ?? 0),
            'source_sha256' => (string) ($database['source_hash'] ?? ''),
            'manifest_sha256' => (string) ($database['manifest_sha256'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $oldDatabase
     * @param array<string, mixed> $newDatabase
     * @return array<string, mixed>
     */
    private function journalPayload(
        string $operation,
        string $slug,
        string $operationId,
        int $rootPageId,
        array $oldDatabase,
        array $newDatabase,
        ?string $oldManifestJson,
        ?string $newManifestJson,
        ?string $stageName
    ): array {
        $backupName = '.openconcept-backup-' . $slug . '-' . $operationId;
        return [
            'schema' => self::JOURNAL_SCHEMA,
            'root_page_id' => $rootPageId,
            'public_slug' => $slug,
            'operation_id' => $operationId,
            'operation' => $operation,
            'prepared_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'old_database' => $oldDatabase,
            'new_database' => $newDatabase,
            'old_manifest_json' => $oldManifestJson,
            'new_manifest_json' => $newManifestJson,
            'artifacts' => [
                'target_name' => $slug,
                'stage_name' => $stageName,
                'backup_name' => $backupName,
                'failed_name' => $operation === 'activate'
                    ? '.openconcept-failed-' . $slug . '-' . $operationId
                    : null,
                'old_identity' => $oldManifestJson === null ? null : $this->artifactIdentity($oldDatabase),
                'new_identity' => $newManifestJson === null ? null : $this->artifactIdentity($newDatabase),
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function writePreparedJournal(array $payload): void
    {
        $slug = (string) ($payload['public_slug'] ?? '');
        $path = $this->journalPath($slug, true);
        if (file_exists($path) || is_link($path)) {
            $this->recoveryRequired();
        }
        $key = $this->signingKey(true);
        if (!is_string($key)) {
            throw new RuntimeException('The publication signing key is unavailable.', 500);
        }
        $payload['signing_key_id'] = substr(hash('sha256', $key), 0, 16);
        $payload['signature'] = hash_hmac('sha256', $this->encodeJson($payload), $key);
        $contents = $this->encodeJson($payload);
        if (strlen($contents) > 16 * 1024 * 1024) {
            throw new RuntimeException('The private publication journal is too large.', 422);
        }
        $directory = dirname($path);
        $temporary = $directory . DIRECTORY_SEPARATOR . basename($path) . '.tmp-' . bin2hex(random_bytes(12));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            $this->permissionFailure(
                $temporary,
                'publication recovery journal',
                'Could not create the private journal file.'
            );
        }
        $renamed = false;
        try {
            $this->securePrivateFile($temporary, 'publication recovery journal', true);
            $this->writeAll($handle, $contents);
            $this->flushAndSyncFile($handle, 'private publication journal');
            fclose($handle);
            $handle = null;
            clearstatcache(true, $path);
            if (file_exists($path) || is_link($path) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not atomically install the private publication journal.', 500);
            }
            $renamed = true;
            $this->securePrivateFile($path, 'publication recovery journal', true);
            $this->syncDirectory($directory);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$renamed && (is_file($temporary) || is_link($temporary))) {
                @unlink($temporary);
            }
        }
    }

    /** @return array<int, string> */
    private function journalCandidates(int $rootPageId): array
    {
        $directory = $this->journalDirectory(false);
        if ($directory === null) {
            return [];
        }
        $candidates = [];
        foreach (scandir($directory) ?: [] as $name) {
            if (preg_match('/^[a-f0-9]{64}\.json$/D', $name) !== 1) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_link($path) || !is_file($path)) {
                $this->recoveryRequired();
            }
            // Authenticate the complete journal before trusting root_page_id,
            // public_slug, or any artifact identity used for routing.
            $loaded = $this->loadJournal($path, null, null);
            if ((int) $loaded['journal']['root_page_id'] === $rootPageId) {
                $candidates[$path] = $path;
            }
        }
        return array_values($candidates);
    }

    /** @return array{journal: array<string, mixed>, raw_sha256: string} */
    private function loadJournal(string $path, ?int $expectedRootPageId, ?string $expectedSlug): array
    {
        $directory = $this->journalDirectory(false);
        if ($directory === null || dirname($path) !== $directory
            || preg_match('/^[a-f0-9]{64}\.json$/D', basename($path)) !== 1
            || is_link($path) || !is_file($path)) {
            $this->recoveryRequired();
        }
        $this->securePrivateFile($path, 'publication recovery journal', true);
        $size = @filesize($path);
        if (!is_int($size) || $size < 2 || $size > 16 * 1024 * 1024) {
            $this->recoveryRequired();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || strlen($raw) !== $size) {
            $this->recoveryRequired();
        }
        try {
            $journal = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->recoveryRequired();
        }
        if (!is_array($journal)) {
            $this->recoveryRequired();
        }
        $signature = $journal['signature'] ?? null;
        unset($journal['signature']);
        try {
            $key = $this->signingKey(false);
        } catch (StaticPublicationException $exception) {
            if ($exception->publicCode === 'publication_permissions_unsafe') {
                throw $exception;
            }
            $this->recoveryRequired();
        } catch (Throwable) {
            $this->recoveryRequired();
        }
        if (!is_string($key) || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1
            || !hash_equals(hash_hmac('sha256', $this->encodeJson($journal), $key), $signature)
            || (string) ($journal['signing_key_id'] ?? '') !== substr(hash('sha256', $key), 0, 16)) {
            $this->recoveryRequired();
        }
        $journal['signature'] = $signature;
        $trustedRootPageId = $journal['root_page_id'] ?? null;
        $trustedSlug = $journal['public_slug'] ?? null;
        if (!is_int($trustedRootPageId) || $trustedRootPageId < 1 || !is_string($trustedSlug)) {
            $this->recoveryRequired();
        }
        try {
            $trustedSlug = $this->validateSlug($trustedSlug);
        } catch (Throwable) {
            $this->recoveryRequired();
        }
        if (basename($path) !== hash('sha256', $trustedSlug) . '.json'
            || ($expectedRootPageId !== null && $trustedRootPageId !== $expectedRootPageId)
            || ($expectedSlug !== null && $trustedSlug !== $expectedSlug)
            || !$this->validJournal($journal, $trustedRootPageId, $trustedSlug)) {
            $this->recoveryRequired();
        }
        return ['journal' => $journal, 'raw_sha256' => hash('sha256', $raw)];
    }

    /** @param array<string, mixed> $journal */
    private function validJournal(array $journal, int $rootPageId, string $slug): bool
    {
        $operation = $journal['operation'] ?? null;
        $operationId = $journal['operation_id'] ?? null;
        $old = $journal['old_database'] ?? null;
        $new = $journal['new_database'] ?? null;
        $artifacts = $journal['artifacts'] ?? null;
        if (array_keys($journal) !== [
            'schema', 'root_page_id', 'public_slug', 'operation_id', 'operation', 'prepared_at',
            'old_database', 'new_database', 'old_manifest_json', 'new_manifest_json', 'artifacts',
            'signing_key_id', 'signature',
        ]
            || array_keys($artifacts) !== [
                'target_name', 'stage_name', 'backup_name', 'failed_name', 'old_identity', 'new_identity',
            ]
            || ($journal['schema'] ?? null) !== self::JOURNAL_SCHEMA
            || ($journal['root_page_id'] ?? null) !== $rootPageId
            || ($journal['public_slug'] ?? null) !== $slug
            || !is_string($journal['prepared_at'] ?? null)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', (string) $journal['prepared_at']) !== 1
            || !is_string($operationId) || preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1
            || !in_array($operation, ['activate', 'retire'], true)
            || !is_array($old) || !is_array($new) || !is_array($artifacts)
            || !$this->validDatabaseDescriptor($old) || !$this->validDatabaseDescriptor($new)
            || ($new['row_exists'] ?? false) !== true
            || (int) ($new['root_page_id'] ?? 0) !== $rootPageId
            || (string) ($new['public_slug'] ?? '') !== $slug
            || ($artifacts['target_name'] ?? null) !== $slug
            || ($artifacts['backup_name'] ?? null) !== '.openconcept-backup-' . $slug . '-' . $operationId) {
            return false;
        }
        if (!empty($old['row_exists'])) {
            if ((int) $old['root_page_id'] !== $rootPageId
                || ((string) $old['public_slug'] !== '' && (string) $old['public_slug'] !== $slug)
                || (int) $old['share_id'] !== (int) $new['share_id']
                || (string) $old['publication_id'] !== (string) $new['publication_id']
                || ($old['publication_locale'] !== null
                    && $old['publication_locale'] !== $new['publication_locale'])
                || (int) $new['revision'] !== (int) $old['revision'] + 1) {
                return false;
            }
        } elseif ((int) $new['revision'] !== 1) {
            return false;
        }
        $oldManifest = $journal['old_manifest_json'] ?? null;
        $newManifest = $journal['new_manifest_json'] ?? null;
        $oldIdentity = $artifacts['old_identity'] ?? null;
        $newIdentity = $artifacts['new_identity'] ?? null;
        $expectsOldArtifact = !empty($old['row_exists']) && !empty($old['enabled']);
        if ($expectsOldArtifact !== is_string($oldManifest) || $expectsOldArtifact !== is_array($oldIdentity)) {
            return false;
        }
        if ($expectsOldArtifact && (!$this->manifestMatchesDescriptor($oldManifest, $old, $oldIdentity)
            || !hash_equals((string) $old['manifest_sha256'], hash('sha256', $oldManifest)))) {
            return false;
        }
        if ($operation === 'activate') {
            if (empty($new['enabled']) || (int) $new['published_revision'] !== (int) $new['revision']
                || !is_string($newManifest) || !is_array($newIdentity)
                || !$this->manifestMatchesDescriptor($newManifest, $new, $newIdentity)
                || !hash_equals((string) $new['manifest_sha256'], hash('sha256', $newManifest))
                || !is_string($artifacts['stage_name'] ?? null)
                || preg_match('/^\.openconcept-stage-' . preg_quote($slug, '/') . '-[a-f0-9]{24}$/D', (string) $artifacts['stage_name']) !== 1
                || ($artifacts['failed_name'] ?? null) !== '.openconcept-failed-' . $slug . '-' . $operationId) {
                return false;
            }
        } else {
            if (empty($old['enabled']) || !empty($new['enabled']) || $new['published_revision'] !== null
                || $new['manifest_sha256'] !== null || $new['source_hash'] !== null || $newManifest !== null
                || $newIdentity !== null || ($artifacts['stage_name'] ?? null) !== null
                || ($artifacts['failed_name'] ?? null) !== null
                || $old['selected_page_ids'] !== $new['selected_page_ids']) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $descriptor */
    private function validDatabaseDescriptor(array $descriptor): bool
    {
        $expectedKeys = [
            'row_exists', 'share_id', 'root_page_id', 'public_slug', 'publication_id', 'enabled', 'revision',
            'published_revision', 'manifest_sha256', 'source_hash', 'publication_locale', 'selected_page_ids',
        ];
        if (array_keys($descriptor) !== $expectedKeys || !is_bool($descriptor['row_exists'])
            || !is_bool($descriptor['enabled']) || !is_array($descriptor['selected_page_ids'])) {
            return false;
        }
        if (!$descriptor['row_exists']) {
            return $descriptor === $this->databaseDescriptor(null, []);
        }
        if (!is_int($descriptor['share_id']) || $descriptor['share_id'] < 1
            || !is_int($descriptor['root_page_id']) || $descriptor['root_page_id'] < 1
            || !is_string($descriptor['public_slug'])
            || !is_string($descriptor['publication_id'])
            || preg_match('/^[a-f0-9]{32}$/D', $descriptor['publication_id']) !== 1
            || !is_int($descriptor['revision']) || $descriptor['revision'] < 1
            || !($descriptor['published_revision'] === null || (is_int($descriptor['published_revision']) && $descriptor['published_revision'] > 0))
            || !($descriptor['manifest_sha256'] === null || (is_string($descriptor['manifest_sha256']) && preg_match('/^[a-f0-9]{64}$/D', $descriptor['manifest_sha256']) === 1))
            || !($descriptor['source_hash'] === null || (is_string($descriptor['source_hash']) && preg_match('/^[a-f0-9]{64}$/D', $descriptor['source_hash']) === 1))
            || !($descriptor['publication_locale'] === null || is_string($descriptor['publication_locale']))) {
            return false;
        }
        $selected = $descriptor['selected_page_ids'];
        foreach ($selected as $pageId) {
            if (!is_int($pageId) || $pageId < 1) {
                return false;
            }
        }
        $sorted = array_values(array_unique($selected));
        sort($sorted, SORT_NUMERIC);
        return $selected === $sorted;
    }

    /** @param array<string, mixed> $descriptor @param array<string, mixed> $identity */
    private function manifestMatchesDescriptor(string $manifestJson, array $descriptor, array $identity): bool
    {
        if ($identity !== $this->artifactIdentity($descriptor)) {
            return false;
        }
        try {
            $manifest = json_decode($manifestJson, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($manifest) || (string) ($manifest['schema'] ?? '') !== self::MANIFEST_SCHEMA
            || (int) ($manifest['share_id'] ?? 0) !== (int) $identity['share_id']
            || (int) ($manifest['root_page_id'] ?? 0) !== (int) $identity['root_page_id']
            || (string) ($manifest['public_slug'] ?? '') !== (string) $identity['public_slug']
            || (string) ($manifest['publication_id'] ?? '') !== (string) $identity['publication_id']
            || (int) ($manifest['revision'] ?? 0) !== (int) $identity['revision']
            || (string) ($manifest['source_sha256'] ?? '') !== (string) $identity['source_sha256']
            || !is_array($manifest['files'] ?? null)) {
            return false;
        }
        $signature = $manifest['signature'] ?? null;
        unset($manifest['signature']);
        try {
            $key = $this->signingKey(false);
        } catch (StaticPublicationException $exception) {
            if ($exception->publicCode === 'publication_permissions_unsafe') {
                throw $exception;
            }
            return false;
        } catch (Throwable) {
            return false;
        }
        return is_string($key) && is_string($signature) && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && (string) ($manifest['signing_key_id'] ?? '') === substr(hash('sha256', $key), 0, 16)
            && hash_equals(hash_hmac('sha256', $this->encodeJson($manifest), $key), $signature);
    }

    /** @param array<string, mixed> $journal */
    private function recoverLoadedJournal(string $path, array $journal, string $rawHash): void
    {
        $rootPageId = (int) $journal['root_page_id'];
        $current = $this->currentDatabaseDescriptor($rootPageId);
        $old = $journal['old_database'];
        $new = $journal['new_database'];
        $matchesOld = $current === $old;
        $matchesNew = $current === $new;
        if ($matchesOld === $matchesNew) {
            $this->recoveryRequired();
        }
        $paths = $this->recoveryArtifactPaths($journal);
        $statuses = $this->recoveryArtifactStatuses($journal, $paths);
        $operation = (string) $journal['operation'];
        if ($operation === 'activate') {
            $this->validateActivationRecoveryState($journal, $statuses, $matchesOld);
        } else {
            $this->validateRetirementRecoveryState($journal, $statuses, $matchesOld);
        }

        // Close the manual-edit window immediately before the first move or
        // delete. A mismatch is rejected without changing either side.
        $this->assertJournalUnchanged($path, $rawHash);
        if ($this->recoveryArtifactStatuses($journal, $paths) !== $statuses) {
            $this->recoveryRequired();
        }

        if ($operation === 'activate' && $matchesOld) {
            $this->rollbackActivationArtifacts($journal, $paths, $statuses);
        } elseif ($operation === 'activate') {
            $this->forwardActivationArtifacts($journal, $paths, $statuses);
        } elseif ($matchesOld) {
            $this->rollbackRetirementArtifacts($journal, $paths, $statuses);
        } else {
            $this->forwardRetirementArtifacts($journal, $paths, $statuses);
        }

        if ($this->currentDatabaseDescriptor($rootPageId) !== ($matchesOld ? $old : $new)) {
            $this->recoveryRequired();
        }
        $this->assertRecoveredFilesystem($journal, $paths, $matchesOld);
        $this->assertJournalUnchanged($path, $rawHash);
        $this->syncDirectory($this->publishRoot);
        $this->deleteJournal($path);
    }

    /** @return array<string, mixed> */
    private function currentDatabaseDescriptor(int $rootPageId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM page_public_shares WHERE root_page_id = ?');
        $statement->execute([$rootPageId]);
        $share = $statement->fetch();
        if (!is_array($share)) {
            return $this->databaseDescriptor(null, []);
        }
        $selectedStatement = $this->pdo->prepare(
            'SELECT page_id FROM page_public_share_pages WHERE share_id = ? ORDER BY page_id'
        );
        $selectedStatement->execute([(int) $share['id']]);
        return $this->databaseDescriptor($share, array_map('intval', $selectedStatement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $journal @return array<string, ?string> */
    private function recoveryArtifactPaths(array $journal): array
    {
        $artifacts = $journal['artifacts'];
        $paths = [];
        foreach (['target', 'stage', 'backup', 'failed'] as $kind) {
            $name = $artifacts[$kind . '_name'] ?? null;
            $paths[$kind] = is_string($name)
                ? $this->assertManagedPath($this->publishRoot . DIRECTORY_SEPARATOR . $name)
                : null;
        }
        return $paths;
    }

    /**
     * @param array<string, mixed> $journal
     * @param array<string, ?string> $paths
     * @return array<string, string>
     */
    private function recoveryArtifactStatuses(array $journal, array $paths): array
    {
        $artifacts = $journal['artifacts'];
        $oldManifest = is_string($journal['old_manifest_json'] ?? null) ? $journal['old_manifest_json'] : null;
        $newManifest = is_string($journal['new_manifest_json'] ?? null) ? $journal['new_manifest_json'] : null;
        $oldIdentity = is_array($artifacts['old_identity'] ?? null) ? $artifacts['old_identity'] : null;
        $newIdentity = is_array($artifacts['new_identity'] ?? null) ? $artifacts['new_identity'] : null;
        $statuses = [];
        foreach ($paths as $kind => $path) {
            $statuses[$kind] = $path === null ? 'absent' : $this->artifactStatus(
                $path,
                $oldIdentity,
                $oldManifest,
                $newIdentity,
                $newManifest
            );
        }
        return $statuses;
    }

    /** @param array<string, mixed>|null $oldIdentity @param array<string, mixed>|null $newIdentity */
    private function artifactStatus(
        string $path,
        ?array $oldIdentity,
        ?string $oldManifest,
        ?array $newIdentity,
        ?string $newManifest
    ): string {
        if (!file_exists($path) && !is_link($path)) {
            return 'absent';
        }
        if (is_link($path) || !is_dir($path)) {
            return 'invalid';
        }
        $old = $oldIdentity !== null && $oldManifest !== null
            && $this->verifyDirectoryForIdentity($path, $oldIdentity, $oldManifest) === 'verified';
        $new = $newIdentity !== null && $newManifest !== null
            && $this->verifyDirectoryForIdentity($path, $newIdentity, $newManifest) === 'verified';
        if ($old && $new) {
            return 'ambiguous';
        }
        return $old ? 'old' : ($new ? 'new' : 'invalid');
    }

    /** @param array<string, mixed> $journal @param array<string, string> $statuses */
    private function validateActivationRecoveryState(array $journal, array $statuses, bool $databaseIsOld): void
    {
        if (!in_array($statuses['target'], ['absent', 'old', 'new'], true)
            || !in_array($statuses['stage'], ['absent', 'new'], true)
            || !in_array($statuses['backup'], ['absent', 'old'], true)
            || !in_array($statuses['failed'], ['absent', 'new'], true)) {
            $this->recoveryRequired();
        }
        $oldExists = is_string($journal['old_manifest_json'] ?? null);
        $oldCount = ($statuses['target'] === 'old' ? 1 : 0) + ($statuses['backup'] === 'old' ? 1 : 0);
        $newCount = ($statuses['target'] === 'new' ? 1 : 0)
            + ($statuses['stage'] === 'new' ? 1 : 0)
            + ($statuses['failed'] === 'new' ? 1 : 0);
        if ($databaseIsOld) {
            if ($oldCount !== ($oldExists ? 1 : 0) || $newCount > 1) {
                $this->recoveryRequired();
            }
            return;
        }
        if ($statuses['target'] !== 'new' || $statuses['stage'] !== 'absent'
            || $statuses['failed'] !== 'absent' || $newCount !== 1
            || $oldCount > ($oldExists ? 1 : 0)
            || (!$oldExists && $statuses['backup'] !== 'absent')) {
            $this->recoveryRequired();
        }
    }

    /** @param array<string, mixed> $journal @param array<string, string> $statuses */
    private function validateRetirementRecoveryState(array $journal, array $statuses, bool $databaseIsOld): void
    {
        if ($statuses['stage'] !== 'absent' || $statuses['failed'] !== 'absent'
            || !in_array($statuses['target'], ['absent', 'old'], true)
            || !in_array($statuses['backup'], ['absent', 'old'], true)) {
            $this->recoveryRequired();
        }
        $oldCount = ($statuses['target'] === 'old' ? 1 : 0) + ($statuses['backup'] === 'old' ? 1 : 0);
        if (($databaseIsOld && $oldCount !== 1)
            || (!$databaseIsOld && ($statuses['target'] !== 'absent' || $oldCount > 1))) {
            $this->recoveryRequired();
        }
    }

    /** @param array<string, mixed> $journal @param array<string, ?string> $paths @param array<string, string> $statuses */
    private function rollbackActivationArtifacts(array $journal, array $paths, array $statuses): void
    {
        if ($statuses['target'] === 'new') {
            $this->renameArtifact((string) $paths['target'], (string) $paths['failed']);
            $statuses['target'] = 'absent';
            $statuses['failed'] = 'new';
        }
        $oldExists = is_string($journal['old_manifest_json'] ?? null);
        if ($oldExists && $statuses['target'] === 'absent') {
            $this->renameArtifact((string) $paths['backup'], (string) $paths['target']);
            $statuses['backup'] = 'absent';
            $statuses['target'] = 'old';
        }
        if ($statuses['stage'] === 'new') {
            $this->removeJournalArtifact($journal, (string) $paths['stage'], 'new');
        }
        if ($statuses['failed'] === 'new') {
            $this->removeJournalArtifact($journal, (string) $paths['failed'], 'new');
        }
    }

    /** @param array<string, mixed> $journal @param array<string, ?string> $paths @param array<string, string> $statuses */
    private function forwardActivationArtifacts(array $journal, array $paths, array $statuses): void
    {
        if ($statuses['backup'] === 'old') {
            $this->removeJournalArtifact($journal, (string) $paths['backup'], 'old');
        }
    }

    /** @param array<string, mixed> $journal @param array<string, ?string> $paths @param array<string, string> $statuses */
    private function rollbackRetirementArtifacts(array $journal, array $paths, array $statuses): void
    {
        if ($statuses['target'] === 'absent') {
            $this->renameArtifact((string) $paths['backup'], (string) $paths['target']);
        }
    }

    /** @param array<string, mixed> $journal @param array<string, ?string> $paths @param array<string, string> $statuses */
    private function forwardRetirementArtifacts(array $journal, array $paths, array $statuses): void
    {
        if ($statuses['backup'] === 'old') {
            $this->removeJournalArtifact($journal, (string) $paths['backup'], 'old');
        }
    }

    /** @param array<string, mixed> $journal */
    private function removeJournalArtifact(array $journal, string $path, string $generation): void
    {
        $identity = $journal['artifacts'][$generation . '_identity'] ?? null;
        $manifest = $journal[$generation . '_manifest_json'] ?? null;
        if (!is_array($identity) || !is_string($manifest)
            || $this->verifyDirectoryForIdentity($path, $identity, $manifest) !== 'verified') {
            $this->recoveryRequired();
        }
        $this->removeTree($path);
    }

    private function renameArtifact(string $from, string $to): void
    {
        $from = $this->assertManagedPath($from);
        $to = $this->assertManagedPath($to);
        if (is_link($from) || !is_dir($from) || file_exists($to) || is_link($to) || !rename($from, $to)) {
            throw new RuntimeException('Could not restore the static publication artifact.', 500);
        }
        $this->syncDirectory($this->publishRoot);
    }

    /** @param array<string, mixed> $journal @param array<string, ?string> $paths */
    private function assertRecoveredFilesystem(array $journal, array $paths, bool $databaseIsOld): void
    {
        $statuses = $this->recoveryArtifactStatuses($journal, $paths);
        foreach (['stage', 'backup', 'failed'] as $kind) {
            if ($statuses[$kind] !== 'absent') {
                $this->recoveryRequired();
            }
        }
        if ((string) $journal['operation'] === 'activate') {
            $expected = $databaseIsOld
                ? (is_string($journal['old_manifest_json'] ?? null) ? 'old' : 'absent')
                : 'new';
        } else {
            $expected = $databaseIsOld ? 'old' : 'absent';
        }
        if ($statuses['target'] !== $expected) {
            $this->recoveryRequired();
        }
    }

    private function assertJournalUnchanged(string $path, string $rawHash): void
    {
        if (is_link($path) || !is_file($path)) {
            $this->recoveryRequired();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || !hash_equals($rawHash, hash('sha256', $raw))) {
            $this->recoveryRequired();
        }
    }

    private function deleteJournal(string $path): void
    {
        if (is_link($path) || !is_file($path) || !unlink($path)) {
            throw new RuntimeException('Could not remove the completed publication journal.', 500);
        }
        $this->syncDirectory(dirname($path));
    }

    private function recoveryRequired(): never
    {
        throw new StaticPublicationException(
            'The interrupted static publication cannot be recovered automatically.',
            409,
            'publication_recovery_required'
        );
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{paths: array<string, string>, descriptors: array<int, array<string, mixed>>}
     */
    private function collectImages(array $snapshot, ?string $stage): array
    {
        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        $requested = [];
        foreach ($pages as $pageId => $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach (is_array($page['blocks'] ?? null) ? $page['blocks'] : [] as $block) {
                if (!is_array($block) || (string) ($block['type'] ?? '') !== 'image'
                    || (string) ($block['file_category'] ?? '') !== 'image') {
                    continue;
                }
                $fileId = (int) ($block['file_id'] ?? 0);
                if ($fileId > 0) {
                    $requested[(int) $pageId . ':' . $fileId] = [(int) $pageId, $fileId];
                }
            }
        }
        if ($requested === []) {
            return ['paths' => [], 'descriptors' => []];
        }
        $paths = [];
        $descriptors = [];
        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        $uploadRoot = $this->uploadRoot();
        foreach ($requested as $key => [$pageId, $fileId]) {
            $statement = $this->pdo->prepare('SELECT id, page_id, stored_name, mime_type, category, size_bytes FROM files WHERE id = ?');
            $statement->execute([$fileId]);
            $file = $statement->fetch();
            $mime = is_array($file) ? (string) ($file['mime_type'] ?? '') : '';
            $storedName = is_array($file) ? (string) ($file['stored_name'] ?? '') : '';
            if (!is_array($file) || (int) ($file['page_id'] ?? 0) !== $pageId
                || (string) ($file['category'] ?? '') !== 'image' || !isset($allowed[$mime])
                || $storedName === '' || basename($storedName) !== $storedName) {
                throw new RuntimeException('公開対象の画像を安全に確認できませんでした。', 422);
            }
            $source = realpath($uploadRoot . DIRECTORY_SEPARATOR . $storedName);
            if ($source === false || !$this->isContained($uploadRoot, $source) || is_link($source) || !is_file($source)) {
                throw new RuntimeException('公開対象の画像ファイルが見つかりません。', 422);
            }
            $detected = (new finfo(FILEINFO_MIME_TYPE))->file($source);
            if (!is_string($detected) || $detected !== $mime) {
                throw new RuntimeException('公開対象の画像形式が登録内容と一致しません。', 422);
            }
            $sha = (string) hash_file('sha256', $source);
            $bytes = (int) filesize($source);
            if ($bytes !== (int) ($file['size_bytes'] ?? -1)) {
                throw new RuntimeException('公開対象の画像サイズが登録内容と一致しません。', 422);
            }
            $relative = 'assets/image-' . $fileId . '.' . $allowed[$mime];
            if ($stage !== null) {
                $this->copyFile($stage, $relative, $source, $sha, $bytes);
            }
            $paths[(string) $key] = $relative;
            $descriptors[] = [
                'page_id' => $pageId,
                'file_id' => $fileId,
                'path' => $relative,
                'sha256' => $sha,
                'bytes' => $bytes,
                'mime' => $mime,
            ];
        }
        usort($descriptors, static fn(array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        return ['paths' => $paths, 'descriptors' => $descriptors];
    }

    /** @param array<string, mixed> $snapshot @param array<int, array<string, mixed>> $images */
    private function fingerprint(array $snapshot, array $images): string
    {
        $localeHashes = [];
        foreach (glob($this->applicationRoot . '/locales/*.json') ?: [] as $path) {
            $localeHashes[basename($path)] = hash_file('sha256', $path);
        }
        foreach (glob($this->applicationRoot . '/locales/page-export/*.json') ?: [] as $path) {
            $localeHashes['page-export/' . basename($path)] = hash_file('sha256', $path);
        }
        ksort($localeHashes, SORT_STRING);
        $material = [
            'format' => PublicPageRenderer::FORMAT_VERSION,
            'renderer' => hash_file('sha256', __DIR__ . '/PublicPageRenderer.php'),
            'date_localization' => hash_file('sha256', __DIR__ . '/PageExportLocalization.php'),
            'safe_html' => hash_file('sha256', __DIR__ . '/SafeHtml.php'),
            'css' => hash_file('sha256', $this->applicationRoot . '/public/assets/public-share.css'),
            'locales' => $localeHashes,
            'publication_locale' => (string) ($snapshot['publication_locale'] ?? ''),
            'application_base_url' => rtrim((string) ($snapshot['application_base_url'] ?? ''), '/'),
            'site_base_url' => rtrim((string) ($snapshot['site_base_url'] ?? ''), '/'),
            'workspace_name' => (string) ($snapshot['workspace_name'] ?? ''),
            'organization_name' => (string) ($snapshot['organization_name'] ?? ''),
            'root_page_id' => (int) ($snapshot['root_page_id'] ?? 0),
            'navigation' => $snapshot['navigation'] ?? [],
            'pages' => $snapshot['pages'] ?? [],
            'images' => $images,
        ];
        return hash('sha256', $this->encodeJson($material));
    }

    /** @param array<string, mixed> $snapshot */
    private function sitemap(array $snapshot, string $siteBaseUrl): string
    {
        $paths = is_array($snapshot['page_paths'] ?? null) ? $snapshot['page_paths'] : [];
        $pages = is_array($snapshot['pages'] ?? null) ? $snapshot['pages'] : [];
        $urls = '';
        foreach ($paths as $pageId => $path) {
            $page = is_array($pages[(int) $pageId] ?? null) ? $pages[(int) $pageId] : [];
            $relativePath = ltrim(str_replace('\\', '/', (string) $path), '/');
            $location = $relativePath === 'index.html'
                ? rtrim($siteBaseUrl, '/') . '/'
                : rtrim($siteBaseUrl, '/') . '/' . implode('/', array_map(
                    static fn(string $segment): string => rawurlencode($segment),
                    explode('/', $relativePath)
                ));
            $lastModified = substr((string) ($page['updated_at'] ?? ''), 0, 10);
            $urls .= '<url><loc>' . htmlspecialchars($location, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>'
                . ($lastModified !== '' ? '<lastmod>' . htmlspecialchars($lastModified, ENT_XML1, 'UTF-8') . '</lastmod>' : '')
                . '</url>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
    }

    private function siteHtaccess(): string
    {
        return <<<'HTACCESS'
DirectoryIndex index.html
Options -Indexes -MultiViews -ExecCGI
<FilesMatch "^\.|\.(?:php|phtml|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>
<IfModule mod_headers.c>
    Header always set Content-Security-Policy "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
</IfModule>
HTACCESS;
    }

    /** @return array<int, array{path: string, sha256: string, bytes: int, mime: string}> */
    private function artifactFiles(string $directory, bool $includeMarker): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isLink() || !$item->isFile()) {
                throw new RuntimeException('静的サイト内に管理対象外の特殊ファイルがあります。', 409);
            }
            $relative = str_replace('\\', '/', substr($path, strlen($directory) + 1));
            if (!$this->safeRelativePath($relative) || (!$includeMarker && $relative === self::MARKER_FILE)) {
                if (!$includeMarker && $relative === self::MARKER_FILE) {
                    continue;
                }
                throw new RuntimeException('静的サイト内のパスが不正です。', 409);
            }
            $files[] = [
                'path' => $relative,
                'sha256' => (string) hash_file('sha256', $path),
                'bytes' => (int) filesize($path),
                'mime' => $this->artifactMime($relative),
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $files;
    }

    private function artifactMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'html' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'text/plain; charset=utf-8',
        };
    }

    private function writeFile(string $root, string $relative, string $contents): void
    {
        if (!$this->safeRelativePath($relative)) {
            throw new RuntimeException('静的公開ファイルのパスが不正です。', 500);
        }
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $parent = dirname($path);
        if (!is_dir($parent)) {
            throw new RuntimeException('静的公開ファイルの出力先がありません。', 500);
        }
        $handle = fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('静的公開ファイルを作成できませんでした。', 500);
        }
        try {
            $offset = 0;
            $length = strlen($contents);
            while ($offset < $length) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('静的公開ファイルを書き込めませんでした。', 500);
                }
                $offset += $written;
            }
            @chmod($path, 0644);
            $this->flushAndSyncFile($handle, 'static publication file');
        } finally {
            fclose($handle);
        }
    }

    private function copyFile(string $root, string $relative, string $source, string $expectedHash, int $expectedBytes): void
    {
        $destination = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $input = fopen($source, 'rb');
        $output = fopen($destination, 'xb');
        if (!is_resource($input) || !is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('公開画像をコピーできませんでした。', 500);
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('公開画像を読み込めませんでした。', 500);
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $bytes += strlen($chunk);
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('公開画像を書き込めませんでした。', 500);
                }
            }
            @chmod($destination, 0644);
            $this->flushAndSyncFile($output, 'static publication image');
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($bytes !== $expectedBytes || !hash_equals($expectedHash, hash_final($hash))) {
            throw new StaticPublicationException(
                '公開画像がコピー中に変更されました。',
                409,
                'source_changed_during_build'
            );
        }
    }

    private function signingKey(bool $create): ?string
    {
        $configured = trim((string) getenv('OPENCONCEPT_PUBLICATION_SIGNING_KEY_FILE'));
        $path = $configured !== '' ? $configured : $this->storageRoot . DIRECTORY_SEPARATOR . 'publication-signing.key';
        if (!$this->isAbsolutePath($path)) {
            throw new RuntimeException('公開署名鍵のパスは絶対パスで指定してください。', 500);
        }
        if (!is_file($path) && $create) {
            if ($configured !== '') {
                throw new RuntimeException('設定された公開署名鍵が見つかりません。', 500);
            }
            $lockDirectory = $this->storageRoot . DIRECTORY_SEPARATOR . 'publication-locks';
            $this->ensurePrivateDirectory($lockDirectory);
            $keyLockPath = $lockDirectory . DIRECTORY_SEPARATOR . 'signing-key.lock';
            if (is_file($keyLockPath)) {
                $this->securePrivateFile($keyLockPath, 'publication signing-key lock', true);
            }
            $lock = @fopen($keyLockPath, 'c+b');
            if (!is_resource($lock)) {
                $this->permissionFailure(
                    $keyLockPath,
                    'publication signing-key lock',
                    'Could not open the private signing-key lock for reading and writing.'
                );
            }
            $this->securePrivateFile($keyLockPath, 'publication signing-key lock', true);
            if (!flock($lock, LOCK_EX)) {
                fclose($lock);
                throw new RuntimeException('公開署名鍵の作成ロックを取得できませんでした。', 500);
            }
            try {
                clearstatcache(true, $path);
                if (!is_file($path)) {
                    $encoded = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $handle = @fopen($path, 'xb');
                    if (!is_resource($handle)) {
                        $this->permissionFailure(
                            $path,
                            'publication signing key',
                            'Could not create the private signing-key file.'
                        );
                    }
                    try {
                        // Protect the empty file before any secret bytes are written.
                        $this->securePrivateFile($path, 'publication signing key', true);
                        $contents = $encoded . "\n";
                        if (fwrite($handle, $contents) !== strlen($contents)) {
                            throw new RuntimeException('公開署名鍵を書き込めませんでした。', 500);
                        }
                        $this->flushAndSyncFile($handle, 'publication signing key');
                    } finally {
                        fclose($handle);
                    }
                    $this->securePrivateFile($path, 'publication signing key', true);
                    $this->syncDirectory(dirname($path));
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $this->securePrivateFile($path, 'publication signing key', false);
        $encoded = trim((string) file_get_contents($path));
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $encoded) !== 1) {
            throw new RuntimeException('公開署名鍵の形式が正しくありません。', 500);
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
        if (!is_string($decoded) || strlen($decoded) !== 32) {
            throw new RuntimeException('公開署名鍵の形式が正しくありません。', 500);
        }
        return $decoded;
    }

    private function uploadRoot(): string
    {
        $configured = trim((string) getenv('OPENCONCEPT_UPLOAD_DIR'));
        return $this->resolveExistingDirectory(
            $configured !== '' ? $configured : $this->storageRoot . DIRECTORY_SEPARATOR . 'uploads',
            'upload root'
        );
    }

    private function targetPath(string $slug): string
    {
        return $this->publishRoot . DIRECTORY_SEPARATOR . $this->validateSlug($slug);
    }

    private function ensureChildDirectory(string $root, string $relative): void
    {
        $path = $root . DIRECTORY_SEPARATOR . $relative;
        if (!mkdir($path, 0755) || !is_dir($path)) {
            throw new RuntimeException('静的サイトの出力フォルダーを作成できませんでした。', 500);
        }
        @chmod($path, 0755);
    }

    private function ensurePrivateDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('A private publication directory cannot be a symbolic link.', 500);
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            $this->permissionFailure(
                $path,
                'private publication directory',
                'Could not create the private directory.'
            );
        }
        if (is_link($path)) {
            throw new RuntimeException('公開処理用の保護フォルダーにシンボリックリンクは使用できません。', 500);
        }
        $this->securePrivateDirectory($path, 'private publication directory');
    }

    private function journalPath(string $slug, bool $createDirectory): string
    {
        $slug = $this->validateSlug($slug);
        $directory = $this->journalDirectory($createDirectory);
        if ($directory === null) {
            return $this->storageRoot . DIRECTORY_SEPARATOR . 'publication-journals'
                . DIRECTORY_SEPARATOR . hash('sha256', $slug) . '.json';
        }
        return $directory . DIRECTORY_SEPARATOR . hash('sha256', $slug) . '.json';
    }

    private function journalDirectory(bool $create): ?string
    {
        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'publication-journals';
        if (is_link($directory)) {
            $this->recoveryRequired();
        }
        if (!is_dir($directory)) {
            if (!$create) {
                return null;
            }
            $this->ensurePrivateDirectory($directory);
            $this->syncDirectory($this->storageRoot);
        }
        if (!is_dir($directory) || is_link($directory)) {
            $this->recoveryRequired();
        }
        $this->securePrivateDirectory($directory, 'publication recovery journal directory');
        return $directory;
    }

    private function securePrivateDirectory(string $path, string $label): void
    {
        if (is_link($path) || !is_dir($path)) {
            $this->permissionFailure($path, $label, 'The path is not a regular directory.');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->secureWindowsPrivatePath($path, true, $label);
            return;
        }
        $this->repairPosixOwner($path, $label);
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (is_int($mode) && ($mode & 0777) === 0700
            && is_readable($path) && is_writable($path) && is_executable($path)) {
            return;
        }
        if (!@chmod($path, 0700)) {
            $this->permissionFailure($path, $label, 'Could not change the directory mode to 0700.');
        }
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0700
            || !is_readable($path) || !is_writable($path) || !is_executable($path)) {
            $this->permissionFailure($path, $label, 'The directory mode is not 0700 after repair.');
        }
    }

    private function securePrivateFile(string $path, string $label, bool $requireWrite): void
    {
        if (is_link($path) || !is_file($path)) {
            $this->permissionFailure($path, $label, 'The path is not a regular file.');
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->secureWindowsPrivatePath($path, false, $label);
            return;
        }
        $this->repairPosixOwner($path, $label);
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        $privateMode = is_int($mode) ? ($mode & 0777) : -1;
        $safe = ($requireWrite ? $privateMode === 0600 : in_array($privateMode, [0400, 0600], true))
            && is_readable($path) && (!$requireWrite || is_writable($path));
        if ($safe) {
            return;
        }
        if (!@chmod($path, 0600)) {
            $this->permissionFailure($path, $label, 'Could not change the file mode to 0600.');
        }
        clearstatcache(true, $path);
        $mode = @fileperms($path);
        if (!is_int($mode) || ($mode & 0777) !== 0600
            || !is_readable($path) || ($requireWrite && !is_writable($path))) {
            $this->permissionFailure($path, $label, 'The file mode is not 0600 after repair.');
        }
    }

    private function repairPosixOwner(string $path, string $label): void
    {
        if (!function_exists('posix_geteuid')) {
            return;
        }
        $effectiveUserId = posix_geteuid();
        $ownerId = @fileowner($path);
        if (is_int($ownerId) && $ownerId === $effectiveUserId) {
            return;
        }
        if (!function_exists('chown') || !@chown($path, $effectiveUserId)) {
            $this->permissionFailure(
                $path,
                $label,
                'The POSIX object is not owned by the PHP process identity and ownership could not be repaired.'
            );
        }
        clearstatcache(true, $path);
        if (@fileowner($path) !== $effectiveUserId) {
            $this->permissionFailure($path, $label, 'The POSIX owner did not pass verification after repair.');
        }
    }

    /**
     * NTFS has no useful PHP permission-bit projection. Inspect and repair the
     * DACL with Windows' managed ACL API, using SIDs so localized account names
     * cannot change the policy. Only the PHP identity, SYSTEM, and the built-in
     * Administrators group retain access to private publication material.
     */
    private function secureWindowsPrivatePath(string $path, bool $directory, string $label): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat)) {
            $this->permissionFailure($path, $label, 'Could not inspect the NTFS path.');
        }
        $cacheKey = ($directory ? 'd:' : 'f:') . strtolower(str_replace('/', '\\', $path));
        $fingerprint = implode(':', [
            (string) ($stat['dev'] ?? ''),
            (string) ($stat['ino'] ?? ''),
            (string) ($stat['mode'] ?? ''),
            (string) ($stat['ctime'] ?? ''),
            (string) ($stat['mtime'] ?? ''),
            (string) ($stat['size'] ?? ''),
        ]);
        if (($this->securedPrivatePaths[$cacheKey] ?? null) === $fingerprint) {
            return;
        }
        if (!function_exists('proc_open')) {
            $this->permissionFailure($path, $label, 'PHP proc_open is unavailable, so the NTFS ACL cannot be verified.');
        }
        $systemRoot = trim((string) getenv('SystemRoot'));
        $powerShell = $systemRoot === ''
            ? ''
            : rtrim($systemRoot, "\\/") . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if ($powerShell === '' || !is_file($powerShell)) {
            $this->permissionFailure($path, $label, 'Windows PowerShell is unavailable, so the NTFS ACL cannot be verified.');
        }

        $script = <<<'POWERSHELL'
$ErrorActionPreference = 'Stop'
$target = [Environment]::GetEnvironmentVariable('OPENCONCEPT_ACL_TARGET', 'Process')
$expectDirectory = [Environment]::GetEnvironmentVariable('OPENCONCEPT_ACL_DIRECTORY', 'Process') -eq '1'
$item = Get-Item -LiteralPath $target -Force
if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse points are not allowed.' }
if ($expectDirectory -ne [bool]$item.PSIsContainer) { throw 'The NTFS object type changed.' }
$current = [Security.Principal.WindowsIdentity]::GetCurrent().User
$trusted = @($current.Value, 'S-1-5-18', 'S-1-5-32-544')
$fullControl = [int][Security.AccessControl.FileSystemRights]::FullControl

function Test-OpenConceptPrivateAcl([Security.AccessControl.FileSystemSecurity]$acl) {
    if (-not $acl.AreAccessRulesProtected) { return $false }
    if ($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ne $current.Value) { return $false }
    $rightsBySid = @{}
    foreach ($trustedSid in $trusted) { $rightsBySid[$trustedSid] = 0 }
    foreach ($rule in $acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier])) {
        $sid = $rule.IdentityReference.Value
        if ($rule.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow) { return $false }
        if ($trusted -notcontains $sid) { return $false }
        if (($rule.PropagationFlags -band [Security.AccessControl.PropagationFlags]::InheritOnly) -eq 0) {
            $rightsBySid[$sid] = $rightsBySid[$sid] -bor [int]$rule.FileSystemRights
        }
    }
    foreach ($trustedSid in $trusted) {
        if (($rightsBySid[$trustedSid] -band $fullControl) -ne $fullControl) { return $false }
    }
    return $true
}

$acl = Get-Acl -LiteralPath $target
$repaired = $false
if (-not (Test-OpenConceptPrivateAcl $acl)) {
    $acl.SetAccessRuleProtection($true, $false)
    foreach ($rule in @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))) {
        [void]$acl.RemoveAccessRuleSpecific($rule)
    }
    $acl.SetOwner($current)
    $inheritance = if ($expectDirectory) {
        [Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit'
    } else {
        [Security.AccessControl.InheritanceFlags]::None
    }
    foreach ($sidValue in $trusted) {
        $sid = [Security.Principal.SecurityIdentifier]::new($sidValue)
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $target -AclObject $acl
    $repaired = $true
}
$verifiedItem = Get-Item -LiteralPath $target -Force
if (($verifiedItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'The repaired path became a reparse point.' }
if ($expectDirectory -ne [bool]$verifiedItem.PSIsContainer) { throw 'The repaired NTFS object type changed.' }
$verified = Get-Acl -LiteralPath $target
if (-not (Test-OpenConceptPrivateAcl $verified)) { throw 'The repaired NTFS ACL did not pass verification.' }
[pscustomobject]@{
    ok = $true
    repaired = $repaired
    identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
} | ConvertTo-Json -Compress
POWERSHELL;

        $utf16 = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($script, 'UTF-16LE', 'UTF-8')
            : (function_exists('iconv') ? iconv('UTF-8', 'UTF-16LE', $script) : false);
        if (!is_string($utf16)) {
            $this->permissionFailure($path, $label, 'The NTFS ACL helper command could not be encoded.');
        }
        $environment = getenv();
        if (!is_array($environment)) {
            $environment = [];
        }
        $environment['OPENCONCEPT_ACL_TARGET'] = $path;
        $environment['OPENCONCEPT_ACL_DIRECTORY'] = $directory ? '1' : '0';
        $command = [
            $powerShell,
            '-NoLogo',
            '-NoProfile',
            '-NonInteractive',
            '-ExecutionPolicy',
            'Bypass',
            '-EncodedCommand',
            base64_encode($utf16),
        ];
        $pipes = [];
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            $this->permissionFailure($path, $label, 'The NTFS ACL helper could not be started.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $timedOut = false;
        $deadline = microtime(true) + 15.0;
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!is_array($status) || empty($status['running'])) {
                $exitCode = is_array($status) ? (int) ($status['exitcode'] ?? -1) : -1;
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
            usleep(10000);
        } while (true);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === null || $exitCode < 0) {
            $exitCode = $closeCode;
        }
        try {
            $result = is_string($stdout) ? json_decode(trim($stdout), true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $result = null;
        }
        if ($timedOut || $exitCode !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true) {
            $detail = $timedOut
                ? 'The NTFS ACL helper exceeded its 15-second safety timeout.'
                : (trim((string) $stderr) !== ''
                ? 'The NTFS ACL helper was denied or failed.'
                : 'The NTFS ACL helper did not return a verified result.');
            $this->permissionFailure($path, $label, $detail);
        }
        clearstatcache(true, $path);
        $verifiedStat = @lstat($path);
        if (!is_array($verifiedStat)) {
            $this->permissionFailure($path, $label, 'The protected NTFS path disappeared after verification.');
        }
        $this->securedPrivatePaths[$cacheKey] = implode(':', [
            (string) ($verifiedStat['dev'] ?? ''),
            (string) ($verifiedStat['ino'] ?? ''),
            (string) ($verifiedStat['mode'] ?? ''),
            (string) ($verifiedStat['ctime'] ?? ''),
            (string) ($verifiedStat['mtime'] ?? ''),
            (string) ($verifiedStat['size'] ?? ''),
        ]);
    }

    private function permissionFailure(string $path, string $label, string $detail): never
    {
        $os = PHP_OS_FAMILY;
        $resolution = $os === 'Windows'
            ? 'PHPサービス実行IDが所有者としてフルコントロールを持ち、SYSTEMとAdministrators以外から継承・許可されるACLを除去できるよう、サーバーまたはホスティング事業者でNTFS権限を設定してください。'
            : 'PHP実行ユーザーが対象を所有し、ディレクトリ0700・ファイル0600へ変更できるよう、サーバーまたはホスティング事業者で所有者と権限を設定してください。';
        $adminMessage = '静的Web公開を安全のため停止しました。' . $resolution
            . ' OS: ' . $os . '。問題: ' . $detail . ' 対象: ' . $path . '。区分: ' . $label . '。';
        error_log('OpenConcept static publication permission failure [' . $os . '/' . $label . ']: ' . $detail);
        throw new StaticPublicationException(
            '静的Web公開に必要な秘密鍵または内部保存領域の権限を安全に設定できませんでした。サーバー管理者に確認してください。',
            500,
            'publication_permissions_unsafe',
            $adminMessage
        );
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($handle, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write the private publication journal.', 500);
            }
            $offset += $written;
        }
    }

    /** @param resource $handle */
    private function flushAndSyncFile($handle, string $label): void
    {
        if (!fflush($handle)) {
            throw new RuntimeException('Could not flush ' . $label . '.', 500);
        }
        if (!function_exists('fsync')) {
            if (DIRECTORY_SEPARATOR !== '\\') {
                throw new RuntimeException('File synchronization is unavailable for ' . $label . '.', 500);
            }
            return;
        }
        $synced = @fsync($handle);
        if ($synced !== true && DIRECTORY_SEPARATOR !== '\\') {
            throw new RuntimeException('Could not durably sync ' . $label . '.', 500);
        }
    }

    private function syncTreeDirectories(string $root): void
    {
        if (is_link($root) || !is_dir($root)) {
            throw new RuntimeException('The static publication tree is unsafe to synchronize.', 500);
        }
        $directories = [$root];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('A symbolic link cannot be synchronized as a publication artifact.', 409);
            }
            if ($item->isDir()) {
                $directories[] = $item->getPathname();
            } elseif (!$item->isFile()) {
                throw new RuntimeException('A special file cannot be synchronized as a publication artifact.', 409);
            }
        }
        usort($directories, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($directories as $directory) {
            $this->syncDirectory($directory);
        }
    }

    private function syncDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }
        if (!function_exists('fsync')) {
            throw new RuntimeException('Directory synchronization is unavailable.', 500);
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new RuntimeException('The directory to synchronize is unsafe.', 500);
        }
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not open a directory for durable synchronization.', 500);
        }
        try {
            if (@fsync($handle) !== true) {
                throw new RuntimeException('Could not durably synchronize a directory.', 500);
            }
        } finally {
            fclose($handle);
        }
    }

    private function resolveExistingDirectory(string $path, string $label): string
    {
        if (!$this->isAbsolutePath($path)) {
            throw new RuntimeException($label . ' must be an absolute path.', 500);
        }
        $resolved = realpath($path);
        if ($resolved === false || !is_dir($resolved) || is_link($path)) {
            throw new RuntimeException($label . ' does not exist or is unsafe.', 500);
        }
        return rtrim($resolved, "/\\");
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private function safeRelativePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 240 && !str_contains($path, "\0")
            && !str_contains($path, '\\') && !str_starts_with($path, '/')
            && preg_match('~(?:^|/)\.\.?(/|$)~', $path) !== 1
            && preg_match('~^[A-Za-z0-9._/-]+$~D', $path) === 1;
    }

    private function isContained(string $root, string $path): bool
    {
        $root = rtrim($root, "/\\") . DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') {
            return str_starts_with(strtolower($path), strtolower($root));
        }
        return str_starts_with($path, $root);
    }

    private function assertManagedPath(string $path, string $requiredPrefix = ''): string
    {
        $parent = realpath(dirname($path));
        if ($parent === false || rtrim($parent, "/\\") !== $this->publishRoot
            || ($requiredPrefix !== '' && !str_starts_with(basename($path), $requiredPrefix))) {
            throw new RuntimeException('管理対象外の公開パスを操作しようとしました。', 500);
        }
        return $path;
    }

    private function assertOperationId(string $operationId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
            throw new RuntimeException('公開操作IDが不正です。', 500);
        }
    }

    private function removeTree(string $path): void
    {
        $path = $this->assertManagedPath($path);
        if (is_link($path) || !is_dir($path)) {
            throw new RuntimeException('安全に削除できない公開フォルダーです。', 409);
        }
        $resolvedRoot = realpath($path);
        if ($resolvedRoot === false || !$this->isContained($this->publishRoot, $resolvedRoot)) {
            throw new RuntimeException('安全に削除できない公開フォルダーです。', 409);
        }
        $entries = [];
        // scandir closes its enumeration handle before deletion, including on Windows.
        $collect = function (string $directory) use (&$collect, &$entries, $resolvedRoot): void {
            $names = scandir($directory);
            if ($names === false) {
                throw new RuntimeException('安全に削除できない公開フォルダーです。', 409);
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $itemPath = $directory . DIRECTORY_SEPARATOR . $name;
                $resolvedItem = realpath($itemPath);
                if (is_link($itemPath) || $resolvedItem === false || !$this->isContained($resolvedRoot, $resolvedItem)) {
                    throw new RuntimeException('シンボリックリンクを含む公開フォルダーは削除できません。', 409);
                }
                if (is_dir($itemPath)) {
                    $collect($itemPath);
                }
                $entries[] = $itemPath;
            }
        };
        $collect($path);
        unset($collect);
        foreach ($entries as $itemPath) {
            clearstatcache(true, $itemPath);
            $resolvedItem = realpath($itemPath);
            if (is_link($itemPath) || $resolvedItem === false || !$this->isContained($resolvedRoot, $resolvedItem)) {
                throw new RuntimeException('シンボリックリンクを含む公開フォルダーは削除できません。', 409);
            }
            if (is_dir($itemPath)) {
                if (!rmdir($itemPath)) {
                    throw new RuntimeException('公開フォルダーを削除できませんでした。', 500);
                }
            } elseif (is_file($itemPath)) {
                if (!unlink($itemPath)) {
                    throw new RuntimeException('公開ファイルを削除できませんでした。', 500);
                }
            } else {
                throw new RuntimeException('特殊ファイルを含む公開フォルダーは削除できません。', 409);
            }
        }
        clearstatcache(true, $path);
        if (is_link($path) || realpath($path) !== $resolvedRoot) {
            throw new RuntimeException('安全に削除できない公開フォルダーです。', 409);
        }
        if (!rmdir($path)) {
            throw new RuntimeException('公開フォルダーを削除できませんでした。', 500);
        }
        $this->syncDirectory($this->publishRoot);
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
