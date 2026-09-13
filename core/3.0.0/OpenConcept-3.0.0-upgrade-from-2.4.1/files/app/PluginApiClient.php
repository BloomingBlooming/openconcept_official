<?php

declare(strict_types=1);

/** Scoped facade given to plugin.php. Raw PDO/credentials are not exposed by this API. */
final class PluginApiClient
{
    public function __construct(private readonly PluginApiRegistry $registry, private readonly string $scope) {}
    public function version(): string { return PluginApiRegistry::VERSION; }
    public function capabilities(): array { return PluginApiRegistry::CAPABILITIES; }
    public function scope(): string { return $this->scope; }
    /** Short database-only operation; never hold this across external API calls. */
    public function transaction(callable $operation): mixed { return $this->registry->mutate($this->scope, $operation); }
    public function getData(string $collection, string $key): ?array { return $this->registry->data($this->scope, $collection, $key); }
    public function putData(string $collection, string $key, array $value, ?int $expectedRevision = null): int { return $this->registry->putData($this->scope, $collection, $key, $value, $expectedRevision); }
    public function listData(string $collection, ?string $after = null, int $limit = 100): array { return $this->registry->listData($this->scope, $collection, $after, $limit); }
    public function getSetting(string $key, mixed $default = null): mixed { return $this->getData('settings', $key)['value']['value'] ?? $default; }
    public function setSetting(string $key, mixed $value): void { $this->putData('settings', $key, ['value' => $value]); }
    public function getSecret(string $key): ?string { return $this->registry->read($this->scope, fn (): mixed => $this->registry->service('secretGet', $this->scope, $key)); }
    public function setSecret(string $key, ?string $value): void { $this->registry->mutate($this->scope, fn (): mixed => $this->registry->service('secretSet', $this->scope, $key, $value)); }
    public function appInfo(): array { return $this->registry->service('appInfo'); }
    public function canonicalRead(string $operation, array $actor, array $arguments = []): array { return $this->registry->service('canonicalRead', $this->scope, $operation, $actor, $arguments); }
    public function registerCanonicalProvider(CanonicalSnapshotProviderInterface $provider): void { $this->registry->service('registerCanonicalProvider', $this->scope, $provider); }
    public function registerJob(string $name, callable $handler, array $options = []): void { $this->registry->registerJob($this->scope, $name, $handler, $options); }
    public function enqueue(string $name, array $payload, string $dedupeKey, int $delaySeconds = 0): string { return $this->registry->enqueue($this->scope, $name, $payload, $dedupeKey, $delaySeconds); }
    public function jobs(?string $state = null, ?string $cursor = null, int $limit = 100): array { return $this->registry->jobs($this->scope, $state, $cursor, $limit); }
    public function getJob(string $id): ?array { return $this->registry->getJob($this->scope, $id); }
    public function registerTrigger(string $name, callable $handler): void { $this->registry->registerTrigger($this->scope, $name, $handler); }
    public function subscribe(string $name, callable $handler): void { $this->registry->subscribe($this->scope, $name, $handler); }
    public function events(string $name, ?string $after = null, int $limit = 100): array { return $this->registry->events($name, $after, $limit); }
    public function registerSourceProvider(string $type, array $provider): void { $this->registry->registerSourceProvider($this->scope, $type, $provider); }
    public function publishEvent(string $name, array $payload, ?string $dedupeKey = null): void { $this->registry->publishEvent('plugin.' . $this->scope . '.' . $name, $payload, $dedupeKey); }
    public function listPublishedSources(?string $cursor = null, int $limit = 25, array $extensions = []): array { return $this->registry->listPublishedSources($cursor, $limit, $extensions); }
    public function source(array $ref, ?array $actor = null): ?array { return $this->registry->source($ref, $actor); }
    public function readSource(array $ref): string { return $this->registry->readSource($ref); }
    public function acceptSearchData(array $ref, string $text, array $provenance = [], ?array $actor = null): array { return $this->registry->acceptSearchData($this->scope, $ref, $text, $provenance, $actor); }
    public function searchData(array $ref, ?array $actor = null): ?array { return $this->registry->searchData($this->scope, $ref, $actor); }
    public function invalidateSearchData(array $ref, int $expectedRevision, ?array $actor = null): array { return $this->registry->invalidateSearchData($this->scope, $ref, $expectedRevision, $actor); }
    public function registerFileAction(string $name, callable $provider): void { $this->registry->registerFileAction($this->scope, $name, $provider); }
    public function setFileState(array $ref, string $state, string $label): void { $this->registry->setFileState($this->scope, $ref, $state, $label); }
    public function notify(array $userIds, string $type, string $message, array $target, ?string $action = null, string $dedupeKey = ''): array { return $this->registry->notify($this->scope, $userIds, $type, $message, $target, $action, $dedupeKey); }
    public function notifyAdmins(string $type, string $message, array $target, ?string $action = null, string $dedupeKey = ''): array { return $this->notify($this->registry->service('adminIds'), $type, $message, $target, $action, $dedupeKey); }
    /** Added API: preserves the legacy notify methods and generates each message for its recipient. */
    public function notifyAdminsLocalized(string $type, string $key, array $parameters, array $target, ?string $action = null, string $dedupeKey = ''): array
    {
        $ids = [];
        foreach ($this->registry->service('adminIds') as $userId) {
            $message = $this->registry->service('notificationMessage', (int) $userId, $this->scope, $key, $parameters);
            $created = $this->notify([(int) $userId], $type, $message, $target, $action, $dedupeKey);
            foreach ($created as $recipientId => $id) { $this->registry->localizeNotification($this->scope, (int) $id, (int) $recipientId, $message, $key, $parameters); }
            $ids += $created;
        }
        return $ids;
    }
    public function registerAction(string $name, callable $handler): void { $this->registry->registerAction($this->scope, $name, $handler); }
}
