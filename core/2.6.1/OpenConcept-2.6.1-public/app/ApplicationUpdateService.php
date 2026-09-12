<?php

declare(strict_types=1);

require_once __DIR__ . '/GitHubApplicationRelease.php';
require_once __DIR__ . '/PluginApiClient.php';
require_once __DIR__ . '/I18n.php';

/** Core client of the common API. It announces releases; it never installs them. */
final class ApplicationUpdateService
{
    public const SUCCESS_INTERVAL_SECONDS = 3600;
    private Closure $resolveUser;
    private Closure $clock;
    private GitHubApplicationRelease $releases;
    private I18n $i18n;

    /** @param callable(int):?array $resolveUser Re-fetch active administrator identity from Core. */
    public function __construct(
        private readonly PluginApiClient $api,
        callable $resolveUser,
        ?GitHubApplicationRelease $releases = null,
        ?I18n $i18n = null,
        ?callable $clock = null
    ) {
        if ($api->scope() !== 'core:app-update') {
            throw new InvalidArgumentException('Application updates require the reserved Core scope.');
        }
        $this->resolveUser = Closure::fromCallable($resolveUser);
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
        $this->releases = $releases ?? new GitHubApplicationRelease();
        $this->i18n = $i18n ?? new I18n();
        $this->i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $this->i18n->registerPackage('app-update', dirname(__DIR__) . '/locales/app-update');
    }

    public function register(): void
    {
        $this->api->registerTrigger('admin.login', fn (array $context) => $this->onAdminLogin($context));
        $this->api->registerJob('check-release', fn (array $payload, array $context) => $this->runCheck($context),
            ['triggers' => ['admin.login'], 'lease_seconds' => 90]);
        $this->api->registerAction('details', fn (string $mode, array $payload, array $actor): array => $this->details($actor));
    }

    /** Called only after successful authenticated administrator login. */
    private function onAdminLogin(array $context): void
    {
        if (($context['trigger'] ?? '') !== 'admin.login') {
            return;
        }
        $actor = $this->admin($context['user'] ?? []);
        if ($actor === null) {
            return;
        }
        $state = $this->state();
        // A different administrator receives the known release even while
        // this installation is throttled or another login owns the job.
        $this->notifyCachedRelease($actor, $state);
        if ((int) ($state['next_attempt_at'] ?? 0) > ($this->clock)()) {
            return;
        }
        $this->api->enqueue('check-release', [], 'release-check:' . (int) ($state['checked_at'] ?? 0)
            . ':' . (int) ($state['next_attempt_at'] ?? 0));
    }

    private function runCheck(array $context): void
    {
        if (($context['trigger'] ?? '') !== 'admin.login') {
            return;
        }
        $record = $this->api->getData('release-check', 'state');
        $previous = $record['value'] ?? [];
        $actor = $this->admin($context['user'] ?? []);
        if ($actor === null) {
            // Do not strand the globally deduplicated job if its invoking
            // administrator was demoted after login but before execution.
            $interrupted = array_replace($previous, ['checked_at' => ($this->clock)(),
                'next_attempt_at' => ($this->clock)() + 60, 'status' => 'failed', 'error_code' => 'release_check_failed']);
            $this->api->putData('release-check', 'state', $interrupted, (int) ($record['revision'] ?? 0));
            return;
        }
        if ((int) ($previous['next_attempt_at'] ?? 0) > ($this->clock)()) {
            $this->notifyCachedRelease($actor, $previous);
            return;
        }
        $installed = $this->installedVersion();
        // No transaction is held while the bounded, credential-free GitHub
        // HTTP client performs its catalog/checksum/manifest requests.
        $state = $this->releases->check($installed, $previous, ($this->clock)());
        if (in_array($state['status'] ?? '', ['current', 'update_available'], true)) {
            $state['next_attempt_at'] = ($this->clock)() + self::SUCCESS_INTERVAL_SECONDS;
        }
        if (is_callable($context['assertLease'] ?? null)) {
            $context['assertLease']();
        }
        $this->api->putData('release-check', 'state', $state, (int) ($record['revision'] ?? 0));
        $actor = $this->admin($context['user'] ?? []);
        if ($actor !== null) {
            $this->notifyCachedRelease($actor, $state);
        }
    }

    /** Read-only action; opening or closing it never schedules HTTP or an update. */
    public function details(array $actor): array
    {
        $actor = $this->admin($actor);
        if ($actor === null) {
            throw new RuntimeException('Administrator access is required.', 403);
        }
        $state = $this->state();
        $latest = GitHubApplicationRelease::validatedRelease($state['latest'] ?? null);
        $installed = $this->installedVersion();
        $status = (string) ($state['status'] ?? 'not_checked');
        if (($state['error_code'] ?? null) !== null) {
            $status = 'failed';
        } elseif ($latest !== null) {
            $status = version_compare($latest['version'], $installed, '>') ? 'update_available' : 'current';
        }
        $items = [
            ['label' => $this->tr('installed', $actor), 'value' => $installed],
            ['label' => $this->tr('latest', $actor), 'value' => $latest['version'] ?? $this->tr('unknown', $actor)],
            ['label' => $this->tr('status', $actor), 'value' => $this->tr('status.' . $status, $actor)],
            ['label' => $this->tr('last_success', $actor), 'value' => $this->date((int) ($state['last_success_at'] ?? 0), $actor)],
            ['label' => $this->tr('last_attempt', $actor), 'value' => $this->date((int) ($state['checked_at'] ?? 0), $actor)],
        ];
        if (($state['error_code'] ?? null) !== null) {
            $items[] = ['label' => $this->tr('failure', $actor), 'value' => $this->tr('error.' . $state['error_code'], $actor)];
            $items[] = ['label' => $this->tr('retry_after', $actor), 'value' => $this->date((int) ($state['next_attempt_at'] ?? 0), $actor)];
        }
        if ($latest !== null) {
            $items[] = ['label' => $this->tr('release_date', $actor), 'value' => $latest['published_at'] ?: $this->tr('unknown', $actor)];
            $items[] = ['label' => $this->tr('official', $actor), 'value' => $this->tr('open_release', $actor), 'href' => $latest['url']];
            $items[] = ['label' => $this->tr('instructions', $actor), 'value' => $this->tr('open_instructions', $actor), 'href' => $latest['instructions_url']];
        }
        $blocks = [['type' => 'details', 'items' => $items]];
        if ($latest !== null && trim((string) ($latest['notes'] ?? '')) !== '') {
            $blocks[] = ['type' => 'text', 'title' => $this->tr('changes', $actor), 'text' => $latest['notes']];
        }
        $blocks[] = ['type' => 'text', 'text' => $this->tr('manual_update', $actor)];
        return ['title' => $this->tr('title', $actor), 'blocks' => $blocks,
            'buttons' => [['id' => 'close', 'label' => $this->tr('close', $actor), 'close' => true]]];
    }

    private function notifyCachedRelease(array $actor, array $state): void
    {
        $latest = GitHubApplicationRelease::validatedRelease($state['latest'] ?? null);
        if ($latest === null || (int) ($state['last_success_at'] ?? 0) < 1 || !version_compare($latest['version'], $this->installedVersion(), '>')) {
            return;
        }
        $this->api->notify([(int) $actor['id']], 'application-update',
            $this->tr('notification', $actor, ['version' => $latest['version']]),
            ['type' => 'application-release', 'id' => $latest['id'], 'version' => $latest['version']],
            'details', 'application-release:' . $latest['version']);
    }

    private function installedVersion(): string
    {
        $version = GitHubApplicationRelease::stableVersion((string) ($this->api->appInfo()['app_version'] ?? ''));
        if ($version === null) {
            throw new RuntimeException('The canonical application version is unavailable.');
        }
        return $version;
    }

    private function state(): array
    {
        return $this->api->getData('release-check', 'state')['value'] ?? [];
    }

    private function admin(mixed $actor): ?array
    {
        if (!is_array($actor) || (int) ($actor['id'] ?? 0) < 1) {
            return null;
        }
        $current = ($this->resolveUser)((int) $actor['id']);
        return is_array($current) && (int) ($current['id'] ?? 0) === (int) $actor['id']
            && ($current['role'] ?? '') === 'admin' && (int) ($current['active'] ?? 0) === 1
            && !(bool) ($current['must_change_password'] ?? false) ? $current : null;
    }

    private function tr(string $key, array $actor, array $values = []): string
    {
        return $this->i18n->translate($key, $values, (string) ($actor['ui_locale'] ?? 'en-US'), 'app-update');
    }

    private function date(int $timestamp, array $actor): string
    {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) . ' UTC' : $this->tr('unknown', $actor);
    }
}
