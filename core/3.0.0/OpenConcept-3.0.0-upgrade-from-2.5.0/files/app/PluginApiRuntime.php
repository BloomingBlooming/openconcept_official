<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRegistry.php';
require_once __DIR__ . '/PluginSecretStore.php';
require_once __DIR__ . '/CanonicalReadSnapshotService.php';
require_once __DIR__ . '/AcceptedContentSnapshotProvider.php';
require_once __DIR__ . '/PluginFileService.php';

/** Core adapters behind the versioned extension facade. */
final class PluginApiRuntime
{
    public readonly PluginApiRegistry $api;
    public readonly PluginFileService $files;
    private PDO $pdo;
    private PluginApiRepository $records;
    private ?CanonicalReadSnapshotService $snapshots = null;
    private array $canonicalProviders = [];

    public function __construct(private readonly Database $database, private readonly PluginManager $plugins, private readonly string $root)
    {
        $this->pdo = $database->pdo();
        $this->records = new PluginApiRepository($this->pdo);
        $secrets = new PluginSecretStore($database->storagePath());
        $this->api = new PluginApiRegistry($this->pdo, [
            'resolveSource' => $this->resolveSource(...),
            'readSource' => $this->readSource(...),
            'listPublishedSources' => $this->listPublishedSources(...),
            'scopeEnabled' => $this->scopeEnabled(...),
            'lockScope' => function (string $scope): void {
                if (str_starts_with($scope, 'core:')) { return; }
                $plugin = $this->plugins->plugin($scope);
                if ($plugin === null || !$this->plugins->canEnable($scope)) { throw new PluginApiConflict('The plugin is unavailable.'); }
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $key = 'plugin.' . $scope . '.enabled';
                $sql = 'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)';
                $sql .= $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE setting_key = setting_key' : ' ON CONFLICT (setting_key) DO NOTHING';
                $this->pdo->prepare($sql)->execute([$key, $plugin['enabled'] ? '1' : '0']);
                $query = $this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?' . ($driver === 'sqlite' ? '' : ' FOR UPDATE'));
                $query->execute([$key]);
                if ($query->fetchColumn() !== '1') { throw new PluginApiConflict('The plugin is currently disabled.'); }
            },
            'authorizeActor' => function (string $scope, array $actor): bool {
                $id = (int) ($actor['id'] ?? 0);
                $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $current = null;
                if ($this->pdo->inTransaction()) {
                    if ($driver === 'sqlite') {
                        $this->pdo->prepare('UPDATE users SET id = id WHERE id = ?')->execute([$id]);
                    } else {
                        $lock = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1 AND role NOT IN ('suspended', 'system') FOR UPDATE");
                        $lock->execute([$id]); $current = $lock->fetch() ?: null;
                        if ($current === null) { return false; }
                    }
                }
                $current ??= $this->user($id);
                $fingerprint = $actor['password_fingerprint'] ?? (isset($actor['password_hash']) ? hash('sha256', (string) $actor['password_hash']) : null);
                return $current !== null && empty($current['must_change_password'])
                    && $current['role'] === ($actor['role'] ?? '') && is_string($fingerprint)
                    && hash_equals(hash('sha256', $current['password_hash']), $fingerprint);
            },
            'secretGet' => $secrets->get(...),
            'secretSet' => function (string $scope, string $name, ?string $value) use ($secrets): void {
                if (!$this->scopeEnabled($scope)) { throw new PluginApiConflict('The plugin is disabled.'); }
                $secrets->set($scope, $name, $value);
            },
            'notify' => function (array $users, string $type, string $message, ?int $pageId): array {
                $ids = [];
                foreach ($users as $id) {
                    if ($this->user((int) $id) === null) { continue; }
                    $ids[(int) $id] = createNotification($this->pdo, (int) $id, $type, $message, $pageId);
                }
                return $ids;
            },
            'adminIds' => fn (): array => array_map('intval', $this->pdo->query("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll(PDO::FETCH_COLUMN)),
            'notificationMessage' => fn (int $userId, string $domain, string $key, array $parameters): string => localizedNotificationMessage($this->pdo, $userId, $key, $parameters, $domain),
            'audit' => function (string $event, array $payload): void {
                $id = (int) ($payload['actor_user_id'] ?? 0);
                if ($id > 0) { audit($this->pdo, $id, $event, 'plugin', 0, $payload); }
            },
            'appInfo' => fn (): array => ['app_version' => trim((string) file_get_contents($this->root . '/VERSION')), 'plugin_api_version' => PluginApiRegistry::VERSION, 'capabilities' => PluginApiRegistry::CAPABILITIES],
            'canonicalRead' => $this->canonicalRead(...),
            'registerCanonicalProvider' => function (string $scope, CanonicalSnapshotProviderInterface $provider): void {
                $plugin = $this->plugins->plugin($scope);
                if ($plugin === null || !$this->scopeEnabled($scope) || $provider->id() !== $scope
                    || !in_array('canonical.provide', $plugin['permissions'] ?? [], true)
                    || isset($this->canonicalProviders[$scope])) {
                    throw new RuntimeException('The canonical source provider is unavailable or unauthorized.');
                }
                $this->canonicalProviders[$scope] = $provider;
                $this->snapshots = null;
            },
        ]);
        $this->files = new PluginFileService($this->pdo, uploadStoragePath(), [
            'resolveSource' => $this->resolveSource(...),
            'canViewFile' => fn (array $actor, array $file): bool => canViewFile($this->pdo, $actor, $file),
            'inspectUpload' => 'inspectUploadedFile', 'maxBytes' => uploadLimitMegabytes() * 1048576,
            'dispatchEvent' => $this->api->dispatchEvent(...),
            'lockHierarchy' => fn (int $id): array => lockedPageHierarchiesForUpdate($this->pdo, [$id]),
        ]);
    }

    public function user(int $id): ?array
    {
        $statement = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1 AND role NOT IN ('suspended', 'system')");
        $statement->execute([$id]); $user = $statement->fetch();
        return is_array($user) ? $user : null;
    }
    public function scopeEnabled(string $scope): bool
    {
        if (str_starts_with($scope, 'core:')) { return true; }
        $plugin = $this->plugins->plugin($scope);
        if ($plugin === null || !$this->plugins->canEnable($scope)) { return false; }
        $statement = $this->pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $statement->execute(['plugin.' . $scope . '.enabled']); $state = $statement->fetchColumn();
        return $state === false ? (bool) $plugin['enabled'] : $state === '1';
    }
    public function registerHooks(): void
    {
        foreach (['article_created', 'article_updated', 'article_published'] as $event) {
            Hooks::addAction($event, function (array $page): void { $this->recordPagePublication((int) ($page['id'] ?? 0)); });
        }
    }
    public function recordPagePublication(int $id): void
    {
        if ($id < 1) { return; }
        $statement = $this->pdo->prepare('SELECT id FROM files WHERE page_id = ? ORDER BY id');
        $statement->execute([$id]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $fileId) {
            $ref = $this->fileSource((int) $fileId);
            if ($ref !== null && $ref['current_published']) { $this->api->recordPublication($ref); }
        }
    }

    /** An immutable upload name and byte hash identify a file version. */
    public function fileSource(int $id, ?array $actor = null): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM files WHERE id = ?');
        $statement->execute([$id]); $file = $statement->fetch();
        if (!is_array($file)) { return null; }
        $path = $this->filePath($file);
        if ($path === null) { return null; }
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) { return null; }
        $page = null; $pageId = (int) ($file['page_id'] ?? 0);
        if ($pageId > 0) {
            $statement = $this->pdo->prepare('SELECT p.*, u.department AS author_department FROM pages p JOIN users u ON u.id = p.author_id WHERE p.id = ?');
            $statement->execute([$pageId]); $page = $statement->fetch() ?: null;
        }
        $published = is_array($page) && $page['status'] === 'published' && $page['archived_at'] === null
            && pageReferencesFile($this->pdo, $pageId, $id, $page);
        $source = [
            'source_type' => 'file', 'source_id' => (string) $id,
            'source_version' => hash('sha256', (string) $file['stored_name'] . "\0" . $hash), 'content_hash' => $hash,
            'filename' => (string) $file['original_name'], 'mime_type' => (string) $file['mime_type'], 'size' => (int) $file['size_bytes'],
            'page_id' => $pageId, 'page_title' => (string) ($page['title'] ?? ''),
            'current_published' => $published, 'ever_published' => $published,
            'readable' => $actor === null || canViewFile($this->pdo, $actor, $file, $page),
            'download_url' => 'api.php?action=file-content&id=' . $id,
            'url' => 'api.php?action=file-content&id=' . $id,
        ];
        $source['ever_published'] = $published || $this->records->get('core:sources', 'publication', PluginApiRepository::sourceKey($source)) !== null;
        return $source;
    }

    public function resolveSource(array $ref, ?array $actor): ?array
    {
        if ($actor !== null) {
            $current = $this->user((int) ($actor['id'] ?? 0));
            $fingerprint = $actor['password_fingerprint'] ?? (isset($actor['password_hash']) ? hash('sha256', (string) $actor['password_hash']) : null);
            if ($current === null || (is_string($fingerprint) && !hash_equals($fingerprint, hash('sha256', $current['password_hash'])))) { return null; }
            $current['password_fingerprint'] = hash('sha256', $current['password_hash']);
            unset($current['password_hash']);
            $actor = $current;
        }
        if (($ref['source_type'] ?? '') === 'file') { return $this->fileSource((int) $ref['source_id'], $actor); }
        $provider = $this->api->sourceProvider((string) ($ref['source_type'] ?? ''));
        return $provider === null ? null : ($provider['resolve'])($ref, $actor);
    }
    private function filePath(array $file): ?string
    {
        $name = (string) ($file['stored_name'] ?? '');
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || str_contains($name, "\0")) { return null; }
        $base = realpath(uploadStoragePath());
        $path = realpath(uploadStoragePath() . '/' . $name);
        if (!is_string($base) || !is_string($path) || !is_file($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $base), '/') . '/')) { return null; }
        return $path;
    }
    public function readSource(array $ref): string
    {
        if (($ref['source_type'] ?? '') !== 'file') {
            $provider = $this->api->sourceProvider((string) ($ref['source_type'] ?? ''));
            if ($provider === null) { throw new PluginApiConflict('The source provider is unavailable.'); }
            return ($provider['read'])($ref);
        }
        $statement = $this->pdo->prepare('SELECT * FROM files WHERE id = ?');
        $statement->execute([(int) $ref['source_id']]); $file = $statement->fetch();
        $path = is_array($file) ? $this->filePath($file) : null;
        if ($path === null || filesize($path) > 52428800) { throw new PluginApiConflict('The original file is unavailable or exceeds the 50 MiB reading limit.'); }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) { throw new PluginApiConflict('The original file cannot be read.'); }
        return $bytes;
    }
    public function listPublishedSources(?string $cursor, int $limit, array $extensions): array
    {
        $providers = $this->api->sourceProviders();
        $types = ['file', ...array_keys($providers)];
        $type = 'file'; $position = $cursor;
        if ($cursor !== null && str_starts_with($cursor, 'source:')) {
            $decoded = base64_decode(substr($cursor, 7), true);
            $positionData = is_string($decoded) ? json_decode($decoded, true, 8) : null;
            if (!is_array($positionData) || !is_string($positionData['type'] ?? null)
                || (!is_null($positionData['cursor'] ?? null) && !is_string($positionData['cursor']))) {
                throw new InvalidArgumentException('Invalid source cursor.');
            }
            $type = $positionData['type']; $position = $positionData['cursor'] ?? null;
        }
        if (!in_array($type, $types, true)) { return ['items' => [], 'next_cursor' => null]; }
        $result = $type === 'file' ? $this->listCorePublishedSources($position, $limit, $extensions)
            : ($providers[$type]['list'])($position, $limit, $extensions);
        if (!is_array($result) || !is_array($result['items'] ?? null) || count($result['items']) > $limit) {
            throw new RuntimeException('The source provider returned an invalid page.');
        }
        foreach ($result['items'] as $item) {
            if (!is_array($item) || ($item['source_type'] ?? '') !== $type) { throw new RuntimeException('A source provider returned another owner\'s type.'); }
        }
        $next = $result['next_cursor'] ?? null;
        if ($next === null) {
            $index = array_search($type, $types, true);
            $type = $types[$index + 1] ?? '';
        }
        if ($type !== '') {
            $result['next_cursor'] = 'source:' . base64_encode(json_encode(['type' => $type, 'cursor' => $next], JSON_THROW_ON_ERROR));
        } else { $result['next_cursor'] = null; }
        return $result;
    }
    private function listCorePublishedSources(?string $cursor, int $limit, array $extensions): array
    {
        $after = $cursor === null ? 0 : (int) $cursor;
        if ($cursor !== null && preg_match('/^[0-9]+$/D', $cursor) !== 1) { throw new InvalidArgumentException('Invalid source cursor.'); }
        // Scan a bounded number, retaining a cursor even when no eligible source was found.
        $scan = max(1, min(100, $limit));
        $statement = $this->pdo->prepare('SELECT id, original_name FROM files WHERE id > ? ORDER BY id LIMIT ' . ($scan + 1));
        $statement->execute([$after]); $rows = $statement->fetchAll(); $more = count($rows) > $scan;
        $items = []; $last = $after;
        foreach (array_slice($rows, 0, $scan) as $row) {
            $last = (int) $row['id'];
            if ($extensions !== [] && !in_array(strtolower(pathinfo((string) $row['original_name'], PATHINFO_EXTENSION)), $extensions, true)) { continue; }
            $source = $this->fileSource($last);
            if ($source !== null && $source['ever_published']) { $items[] = $source; }
        }
        return ['items' => $items, 'next_cursor' => $more ? (string) $last : null];
    }

    public function decorateNotifications(array $rows, array $actor): array
    {
        foreach ($rows as &$row) {
            $metadata = $this->api->notificationMetadata((int) $row['id'], $actor);
            $row['extension_action'] = $metadata !== null;
            if (($metadata['scope'] ?? '') === 'ai-file-reader' && class_exists(SystemMessageLocalizer::class, false)) {
                $locale = (string) ($actor['ui_locale'] ?? notificationRecipientLocale($this->pdo, (int) $actor['id']));
                $row['message'] = SystemMessageLocalizer::notification((string) $row['message'], (string) $row['type'], $locale, 'ai-file-reader');
            }
        }
        unset($row); return $rows;
    }
    public function canonicalRead(string $issuer, string $operation, array $actor, array $arguments = []): array
    {
        $this->snapshots ??= CanonicalReadSnapshotService::forDatabase($this->database, $this->root, function (string $scope, string $operation, array $user): bool {
            $plugin = $this->plugins->plugin($scope);
            return $plugin !== null && $this->scopeEnabled($scope) && in_array('canonical.export', $plugin['permissions'] ?? [], true);
        }, [new AcceptedContentSnapshotProvider($this->pdo), ...array_values($this->canonicalProviders)], array_column($this->plugins->plugins(false), 'id'));
        $id = (string) ($arguments['snapshot_id'] ?? '');
        return match ($operation) {
            'capabilities' => $this->snapshots->capabilities($actor, $issuer),
            'begin' => $this->snapshots->begin($actor, $issuer, (array) ($arguments['options'] ?? [])),
            'records' => $this->snapshots->records($actor, $issuer, $id, $arguments['cursor'] ?? null, (int) ($arguments['limit'] ?? 100)),
            'attachment' => $this->snapshots->attachment($actor, $issuer, $id, (string) ($arguments['attachment_id'] ?? ''), (int) ($arguments['offset'] ?? 0), (int) ($arguments['length'] ?? 1048576)),
            'manifest' => $this->snapshots->manifest($actor, $issuer, $id),
            'finish' => $this->snapshots->finish($actor, $issuer, $id),
            'close' => $this->snapshots->close($actor, $issuer, $id),
            default => throw new InvalidArgumentException('Unknown canonical read operation.'),
        };
    }
}
