<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRepository.php';
require_once __DIR__ . '/PluginApiClient.php';

/** Versioned in-process extension contract. Trusted PHP remains outside a sandbox. */
final class PluginApiRegistry
{
    public const VERSION = '1.0.0';
    public const CAPABILITIES = ['storage', 'settings', 'secrets', 'jobs', 'events', 'sources', 'search-content', 'notifications', 'actions', 'file-state', 'app-info', 'canonical-read', 'canonical-providers'];
    private PluginApiRepository $repository;
    private array $clients = [];
    private array $jobs = [];
    private array $actions = [];
    private array $subscribers = [];
    private array $triggers = [];
    private array $leases = [];
    private array $sourceProviders = [];
    private array $fileActions = [];
    private array $actors = [];

    public function __construct(PDO $pdo, private readonly array $services = [])
    {
        $this->repository = new PluginApiRepository($pdo, $services['clock'] ?? null);
    }

    public function forPlugin(string $id): PluginApiClient
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $id) !== 1) { throw new InvalidArgumentException('Invalid plugin id.'); }
        return $this->clients[$id] ??= new PluginApiClient($this, $id);
    }

    public function forCore(string $id): PluginApiClient
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,57}$/D', $id) !== 1) { throw new InvalidArgumentException('Invalid core service id.'); }
        $scope = 'core:' . $id;
        return $this->clients[$scope] ??= new PluginApiClient($this, $scope);
    }

    public function service(string $name, mixed ...$args): mixed
    {
        if (!is_callable($this->services[$name] ?? null)) { throw new RuntimeException('Plugin API capability unavailable: ' . $name); }
        return ($this->services[$name])(...$args);
    }

    public function data(string $scope, string $collection, string $key): ?array { return $this->repository->get($scope, self::name($collection), self::key($key)); }
    public function listData(string $scope, string $collection, ?string $after, int $limit): array { return $this->repository->list($scope, self::name($collection), $after, $limit); }
    public function putData(string $scope, string $collection, string $key, array $value, ?int $revision): int { return $this->mutate($scope, fn (): int => $this->repository->put($scope, self::name($collection), self::key($key), $value, $revision)); }

    public function mutate(string $scope, callable $operation): mixed
    {
        return $this->repository->transaction(function () use ($scope, $operation): mixed {
            // Match Core administrative writers (including toggle-plugin): lock
            // the current actor before the plugin enabled setting, then the job.
            $this->assertActor($scope);
            if (is_callable($this->services['lockScope'] ?? null)) { $this->service('lockScope', $scope); }
            $this->assertScopeEnabled($scope);
            if (isset($this->leases[$scope])) { $this->repository->assertLease($this->leases[$scope]); }
            return $operation();
        });
    }

    public function registerJob(string $scope, string $name, callable $handler, array $options): void
    {
        $name = self::name($name);
        $this->jobs[$scope][$name] = ['handler' => $handler, 'triggers' => $options['triggers'] ?? [], 'lease_seconds' => max(30, min(900, (int) ($options['lease_seconds'] ?? 180)))];
    }

    public function enqueue(string $scope, string $name, array $payload, string $dedupeKey, int $delay): string
    {
        if (!isset($this->jobs[$scope][self::name($name)])) { throw new RuntimeException('Job handler is not registered.'); }
        if ($dedupeKey === '' || strlen($dedupeKey) > 2048) { throw new InvalidArgumentException('A bounded job deduplication key is required.'); }
        return $this->mutate($scope, fn (): string => $this->repository->enqueue($scope, $name, $payload, $dedupeKey, $delay));
    }

    public function jobs(string $scope, ?string $state, ?string $cursor, int $limit): array { return $this->repository->jobs($scope, $state, $cursor, $limit); }
    public function getJob(string $scope, string $id): ?array { return $this->repository->getJob($scope, $id); }

    public function runJobs(int $limit = 2, int $budgetSeconds = 15, array $triggerContext = []): array
    {
        $allowed = [];
        $trigger = (string) ($triggerContext['trigger'] ?? 'manual');
        foreach ($this->jobs as $scope => $jobs) {
            if (!$this->scopeEnabled($scope)) { continue; }
            if (isset($triggerContext['scopes']) && !in_array($scope, (array) $triggerContext['scopes'], true)) { continue; }
            foreach ($jobs as $name => $job) {
                if ($job['triggers'] === [] || in_array($trigger, $job['triggers'], true)) { $allowed[] = [$scope, $name]; }
            }
        }
        $deadline = microtime(true) + max(1, min(120, $budgetSeconds)); $results = [];
        for ($i = 0; $i < max(1, min(25, $limit)) && microtime(true) < $deadline; $i++) {
            $lease = $this->repository->claim($allowed);
            if ($lease === null) { break; }
            $scope = $lease['scope']; $job = $this->jobs[$scope][$lease['job_name']];
            $this->leases[$scope] = $lease;
            try {
                $this->repository->heartbeat($lease, $job['lease_seconds']);
                ($job['handler'])($lease['payload'], $triggerContext + [
                    'id' => $lease['id'], 'attempt' => $lease['attempts'],
                    'heartbeat' => fn (): mixed => $this->repository->heartbeat($lease, $job['lease_seconds']),
                    'assertLease' => fn (): mixed => $this->repository->assertLease($lease),
                ]);
                $this->assertScopeEnabled($scope);
                $completed = $this->repository->finish($lease);
                $results[] = ['id' => $lease['id'], 'state' => $completed ? 'completed' : 'lease-lost'];
            } catch (Throwable $error) {
                // Operational error codes only; provider response bodies and credentials must not enter Core job logs.
                $code = $error instanceof PluginApiConflict ? 'lease-or-state-conflict' : 'handler-failed';
                $owned = $this->repository->finish($lease, $code);
                $results[] = ['id' => $lease['id'], 'state' => $owned ? 'retry-or-failed' : 'lease-lost', 'error' => $code];
            } finally { unset($this->leases[$scope]); }
        }
        return $results;
    }

    public function registerTrigger(string $scope, string $name, callable $handler): void { $this->triggers[self::name($name)][$scope][] = $handler; }
    public function trigger(string $name, array $context = []): void
    {
        foreach ($this->triggers[$name] ?? [] as $handlers) {
            foreach ($handlers as $handler) {
                try { $handler($context + ['trigger' => $name]); } catch (Throwable) { /* A failed plugin cannot fail login or page viewing. */ }
            }
        }
    }

    public function subscribe(string $scope, string $name, callable $handler): void { $this->subscribers[self::name($name)][$scope][] = $handler; }
    public function events(string $name, ?string $after = null, int $limit = 100): array { return $this->repository->events(self::name($name), $after, $limit); }
    /** Owner adapters stay readable after plugin disable when Core has a durable accepted record. */
    public function registerSourceProvider(string $scope, string $type, array $provider): void
    {
        $this->assertScopeEnabled($scope);
        $type = self::name($type);
        if (isset($this->sourceProviders[$type]) || $type === 'file') { throw new LogicException('The source type already has an owner.'); }
        foreach (['resolve', 'list', 'read'] as $method) {
            if (!is_callable($provider[$method] ?? null)) { throw new InvalidArgumentException('A source provider requires resolve, list and read callbacks.'); }
        }
        $this->sourceProviders[$type] = ['scope' => $scope] + $provider;
    }
    public function sourceProvider(string $type): ?array { return $this->sourceProviders[$type] ?? null; }
    public function sourceProviders(): array { return $this->sourceProviders; }
    public function publishEvent(string $name, array $payload, ?string $dedupeKey = null): void
    {
        $name = self::name($name);
        $key = hash('sha256', $dedupeKey ?? PluginApiRepository::encode($payload));
        if (!$this->repository->event($name, $key, $payload)) { return; }
        $this->dispatchEvent($name, $payload);
    }
    /** Dispatch an event already committed by a Core atomic source operation. */
    public function dispatchEvent(string $name, array $payload): void
    {
        foreach ($this->subscribers[$name] ?? [] as $scope => $handlers) {
            if (!$this->scopeEnabled($scope)) { continue; }
            foreach ($handlers as $handler) {
                try { $handler($payload); } catch (Throwable) { /* Durable publication remains available for recovery scans. */ }
            }
        }
    }

    public function recordPublication(array $source): void
    {
        self::validateSource($source);
        $key = PluginApiRepository::sourceKey($source);
        $this->repository->transaction(function () use ($source, $key): void {
            if ($this->repository->get('core:sources', 'publication', $key) === null) {
                $this->repository->put('core:sources', 'publication', $key, $source + ['published_at' => $this->repository->now()], 0);
            }
        });
        $this->publishEvent('source.published', $source, $key);
    }

    public function source(array $ref, ?array $actor = null): ?array
    {
        self::validateSource($ref);
        $source = $this->service('resolveSource', $ref, $actor);
        if (!is_array($source)) { return null; }
        self::validateSource($source);
        if (!hash_equals(PluginApiRepository::sourceKey($ref), PluginApiRepository::sourceKey($source))) { return null; }
        $source['ever_published'] = (bool) ($source['ever_published'] ?? false)
            || $this->repository->get('core:sources', 'publication', PluginApiRepository::sourceKey($ref)) !== null;
        return $source;
    }

    public function listPublishedSources(?string $cursor, int $limit, array $extensions): array
    {
        $result = $this->service('listPublishedSources', $cursor, max(1, min(100, $limit)), $extensions);
        foreach ($result['items'] ?? [] as $item) {
            // Core resolvers must never infer that an unpublished replacement was published.
            if (($item['ever_published'] ?? false) || ($item['current_published'] ?? false)) { $this->recordPublication($item); }
        }
        return $result;
    }

    public function readSource(array $ref): string
    {
        $current = $this->source($ref);
        if ($current === null || !($current['ever_published'] ?? false)) { throw new PluginApiConflict('The source version is unavailable or was never published.'); }
        $bytes = $this->service('readSource', $ref);
        if (!is_string($bytes) || !hash_equals(strtolower($ref['content_hash']), hash('sha256', $bytes))) { throw new PluginApiConflict('The original bytes changed.'); }
        return $bytes;
    }

    public function acceptSearchData(string $scope, array $ref, string $text, array $provenance, ?array $actor): array
    {
        if (trim($text) === '' || strlen($text) > 8388608 || !mb_check_encoding($text, 'UTF-8')) { throw new InvalidArgumentException('Accepted text must be nonempty UTF-8 of at most 8 MiB.'); }
        return $this->mutate($scope, function () use ($scope, $ref, $text, $provenance, $actor): array {
            $source = $this->source($ref, $actor);
            if ($source === null || !($source['ever_published'] ?? false) || ($actor !== null && !($source['readable'] ?? false))) { throw new PluginApiConflict('The source version or access changed before registration.'); }
            return $this->repository->accept($scope, $source, $text, $provenance);
        });
    }

    public function searchData(string $scope, array $ref, ?array $actor): ?array
    {
        $source = $this->source($ref, $actor);
        if ($source === null || ($actor !== null && !($source['readable'] ?? false))) { throw new PluginApiConflict('The source version or access changed.'); }
        return $this->repository->searchData($scope, $ref);
    }

    public function invalidateSearchData(string $scope, array $ref, int $revision, ?array $actor): array
    {
        return $this->mutate($scope, function () use ($scope, $ref, $revision, $actor): array {
            $this->searchData($scope, $ref, $actor);
            return $this->repository->invalidateSearchData($scope, $ref, $revision);
        });
    }

    public function registerFileAction(string $scope, string $name, callable $provider): void { $this->fileActions[$scope][self::name($name)] = $provider; }
    public function fileActions(array $ref, array $actor): array
    {
        $source = $this->source($ref, $actor);
        if ($source === null || !($source['readable'] ?? false)) { return []; }
        $actions = [];
        foreach ($this->fileActions as $scope => $providers) {
            if (!$this->scopeEnabled($scope)) { continue; }
            foreach ($providers as $name => $provider) {
                try {
                    $item = $provider($source, $actor);
                    if (!is_array($item) || !is_string($item['label'] ?? null) || strlen($item['label']) > 256) { continue; }
                    $action = (string) ($item['action'] ?? $name);
                    if (!isset($this->actions[$scope][$action])) { continue; }
                    $actions[] = ['scope' => $scope, 'action' => $action, 'label' => $item['label'], 'payload' => (array) ($item['payload'] ?? $ref)];
                } catch (Throwable) { /* Optional row actions cannot fail the file list. */ }
            }
        }
        return $actions;
    }

    /** Current source, publication and ACL are rechecked on every search, including after plugin disable. */
    public function searchCandidates(string $query, array $actor, int $limit = 20): array
    {
        $result = [];
        foreach ($this->repository->searchRows($query, min(500, max(1, $limit) * 8)) as $row) {
            $source = $this->source($row, $actor);
            if ($source === null || !($source['current_published'] ?? false) || !($source['readable'] ?? false)) { continue; }
            $row['source'] = $source; $row['provenance'] = PluginApiRepository::decode($row['provenance_json']);
            $result[] = $row;
            if (count($result) >= max(1, $limit)) { break; }
        }
        return $result;
    }

    public function setFileState(string $scope, array $ref, string $state, string $label): void
    {
        if (strlen($label) > 256) { throw new InvalidArgumentException('The file label is too long.'); }
        self::name($state); self::validateSource($ref);
        $this->putData($scope, 'file-state', PluginApiRepository::sourceKey($ref), ['source' => $ref, 'state' => $state, 'label' => $label], null);
    }

    public function fileStates(array $ref, array $actor): array
    {
        $source = $this->source($ref, $actor);
        if ($source === null || !($source['readable'] ?? false)) { return []; }
        return $this->repository->fileStates(PluginApiRepository::sourceKey($ref));
    }

    public function notify(string $scope, array $users, string $type, string $message, array $target, ?string $action, string $dedupeKey): array
    {
        self::name($type); if ($action !== null) { self::name($action); }
        if ($message === '' || strlen($message) > 4000 || $dedupeKey === '' || strlen($dedupeKey) > 2048) { throw new InvalidArgumentException('A bounded notification and deduplication key are required.'); }
        $ids = [];
        foreach (array_unique(array_map('intval', $users)) as $userId) {
            if ($userId < 1) { continue; }
            $ids[$userId] = $this->mutate($scope, function () use ($scope, $userId, $type, $message, $target, $action, $dedupeKey): int {
                $key = hash('sha256', $userId . "\0" . $dedupeKey);
                $existing = $this->repository->get($scope, 'notification-dedup', $key);
                if ($existing !== null) { return (int) $existing['value']['id']; }
                $created = $this->service('notify', [$userId], $type, $message, isset($target['page_id']) ? (int) $target['page_id'] : null);
                $id = (int) ($created[$userId] ?? 0);
                if ($id < 1) { throw new RuntimeException('Notification creation failed.'); }
                $this->repository->put($scope, 'notification-dedup', $key, ['id' => $id], 0);
                $this->repository->put('core:notifications', 'metadata', (string) $id, ['id' => $id, 'user_id' => $userId, 'scope' => $scope, 'action' => $action, 'target' => $target], 0);
                return $id;
            });
        }
        return $ids;
    }

    public function notificationMetadata(int $id, array $actor): ?array
    {
        $row = $this->repository->get('core:notifications', 'metadata', (string) $id);
        if ($row === null || (int) $row['value']['user_id'] !== (int) ($actor['id'] ?? 0)) { return null; }
        return $row['value'];
    }

    public function registerAction(string $scope, string $name, callable $handler): void { $this->actions[$scope][self::name($name)] = $handler; }
    public function dispatchAction(string $scope, string $name, string $mode, array $payload, array $actor): array
    {
        if ((int) ($actor['id'] ?? 0) < 1 || !in_array($mode, ['view', 'invoke'], true)) { throw new RuntimeException('Invalid action context.'); }
        $handler = $this->actions[$scope][self::name($name)] ?? null;
        if (!is_callable($handler)) { throw new RuntimeException('This extension action is unavailable.'); }
        $this->assertScopeEnabled($scope);
        $previous = $this->actors[$scope] ?? null;
        $this->actors[$scope] = $actor;
        try {
            $this->assertActor($scope);
            $result = $handler($mode, $payload, $actor);
            $this->assertActor($scope);
            if ($mode === 'invoke' && is_callable($this->services['audit'] ?? null)) { $this->service('audit', 'plugin-action', ['scope' => $scope, 'action' => $name, 'actor_user_id' => (int) $actor['id']]); }
            return $result;
        } finally {
            if ($previous === null) { unset($this->actors[$scope]); } else { $this->actors[$scope] = $previous; }
        }
    }

    public function read(string $scope, callable $operation): mixed { $this->assertActor($scope); return $operation(); }
    private function assertActor(string $scope): void
    {
        if (isset($this->actors[$scope]) && is_callable($this->services['authorizeActor'] ?? null)
            && !$this->service('authorizeActor', $scope, $this->actors[$scope])) {
            throw new RuntimeException('The acting user permissions or authentication changed.', 403);
        }
    }

    private function scopeEnabled(string $scope): bool
    {
        return str_starts_with($scope, 'core:') || !is_callable($this->services['scopeEnabled'] ?? null) || (bool) $this->service('scopeEnabled', $scope);
    }
    private function assertScopeEnabled(string $scope): void
    {
        if (!$this->scopeEnabled($scope)) { throw new PluginApiConflict('The plugin is currently disabled.'); }
    }

    private static function name(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1) { throw new InvalidArgumentException('Invalid extension name.'); }
        return $name;
    }
    private static function key(string $key): string
    {
        if ($key === '' || strlen($key) > 190 || preg_match('/[\x00-\x1f]/', $key)) { throw new InvalidArgumentException('Invalid extension record key.'); }
        return $key;
    }
    private static function validateSource(array $source): void
    {
        foreach (['source_type' => 64, 'source_id' => 190, 'source_version' => 128, 'content_hash' => 64] as $field => $max) {
            if (!isset($source[$field]) || !is_scalar($source[$field]) || (string) $source[$field] === '' || strlen((string) $source[$field]) > $max) { throw new InvalidArgumentException('Invalid source reference: ' . $field); }
        }
        if (preg_match('/^[a-f0-9]{64}$/D', (string) $source['content_hash']) !== 1) { throw new InvalidArgumentException('Source SHA-256 is required.'); }
    }
}
