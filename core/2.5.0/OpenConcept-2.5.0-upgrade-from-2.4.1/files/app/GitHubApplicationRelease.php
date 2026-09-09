<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeOutboundHttpClient.php';

/** Reads published application packages from the existing official core catalog. */
final class GitHubApplicationRelease
{
    public const REPOSITORY = 'BloomingBlooming/openconcept_official';
    public const CATALOG_URL = 'https://api.github.com/repos/' . self::REPOSITORY . '/contents/core?ref=main';
    private const RAW_ROOT = 'https://raw.githubusercontent.com/' . self::REPOSITORY . '/main/core/';
    private const WEB_ROOT = 'https://github.com/' . self::REPOSITORY . '/tree/main/core/';

    public function __construct(private readonly SafeOutboundHttpClient $http = new SafeOutboundHttpClient())
    {
    }

    public static function stableVersion(string $value): ?string
    {
        $value = trim($value);
        if (str_starts_with($value, 'v')) {
            $value = substr($value, 1);
        }
        return preg_match('/^(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})\.(?:0|[1-9][0-9]{0,8})$/D', $value) === 1
            ? $value : null;
    }

    /** Keep cached links as constrained as freshly received catalog links. */
    public static function validatedRelease(mixed $release): ?array
    {
        if (!is_array($release)) {
            return null;
        }
        $version = self::stableVersion((string) ($release['version'] ?? ''));
        if ($version === null || ($release['version'] ?? '') !== $version
            || ($release['id'] ?? '') !== 'official-core-' . $version
            || ($release['url'] ?? '') !== self::WEB_ROOT . $version
            || ($release['instructions_url'] ?? '') !== self::WEB_ROOT . $version . '#readme'
            || !is_string($release['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $release['sha256']) !== 1) {
            return null;
        }
        $safe = array_intersect_key($release, array_fill_keys(['version', 'id', 'url', 'instructions_url', 'sha256'], true));
        $date = $release['published_at'] ?? null;
        $safe['published_at'] = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T[0-9:Z+\-]+)?$/D', $date) === 1 ? $date : null;
        $safe['notes'] = is_string($release['notes'] ?? null) ? mb_substr($release['notes'], 0, 12000) : '';
        return $safe;
    }

    /** @return array<string, mixed> */
    public function check(string $installedVersion, array $previous = [], ?int $now = null): array
    {
        $now ??= time();
        $installed = self::stableVersion($installedVersion);
        if ($installed === null) {
            throw new InvalidArgumentException('The installed application version is invalid.');
        }
        $state = [
            'installed_version' => $installed,
            'checked_at' => $now,
            'last_success_at' => (int) ($previous['last_success_at'] ?? 0),
            'latest' => self::validatedRelease($previous['latest'] ?? null),
            'etag' => $this->safeEtag($previous['etag'] ?? ''),
            'failure_count' => (int) ($previous['failure_count'] ?? 0),
            'next_attempt_at' => 0,
            'error_code' => null,
            'status' => 'checking',
        ];
        if ((int) ($previous['next_attempt_at'] ?? 0) > $now) {
            return array_replace($previous, ['installed_version' => $installed, 'latest' => $state['latest'], 'etag' => $state['etag'], 'status' => 'deferred']);
        }
        try {
            $headers = ['Accept: application/vnd.github+json'];
            if ($state['etag'] !== '' && is_array($state['latest'])) {
                $headers[] = 'If-None-Match: ' . $state['etag'];
            }
            $response = $this->http->request('GET', self::CATALOG_URL, $headers, null, 3, 6, 262144);
            if ($response['status'] === 304 && is_array($state['latest'])) {
                return $this->success($state, $now);
            }
            $this->assertResponse($response, $now);
            $catalog = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($catalog) || !array_is_list($catalog)) {
                throw new RuntimeException('release_catalog_invalid');
            }
            $versions = [];
            foreach ($catalog as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'dir') {
                    continue;
                }
                $name = (string) ($entry['name'] ?? '');
                $version = self::stableVersion($name);
                if ($version !== null && $name === $version && ($entry['path'] ?? '') === 'core/' . $version) {
                    $versions[] = $version;
                }
            }
            usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
            if ($versions === []) {
                throw new RuntimeException('release_not_published');
            }
            // A version directory alone is not evidence that a release is available.
            $version = $versions[0];
            $packageName = 'OpenConcept-' . $version . '-public';
            $detailsUrl = 'https://api.github.com/repos/' . self::REPOSITORY
                . '/contents/core/' . $version . '?ref=main';
            $detailsResponse = $this->http->request('GET', $detailsUrl, ['Accept: application/vnd.github+json'], null, 3, 6, 262144);
            $this->assertResponse($detailsResponse, $now);
            $details = json_decode($detailsResponse['body'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($details) || !array_is_list($details)) {
                throw new RuntimeException('release_package_incomplete');
            }
            $available = [];
            foreach ($details as $entry) {
                if (is_array($entry)) {
                    $available[(string) ($entry['name'] ?? '')] = $entry;
                }
            }
            foreach ([$packageName . '.zip', $packageName . '.zip.sha256'] as $name) {
                $entry = $available[$name] ?? null;
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'file' || (int) ($entry['size'] ?? 0) < 1
                    || ($entry['download_url'] ?? '') !== self::RAW_ROOT . $version . '/' . $name) {
                    throw new RuntimeException('release_package_incomplete');
                }
            }
            if (($available[$packageName]['type'] ?? '') !== 'dir') {
                throw new RuntimeException('release_package_incomplete');
            }
            $checksumResponse = $this->http->request('GET', self::RAW_ROOT . $version . '/' . $packageName . '.zip.sha256', [], null, 3, 6, 1024);
            $this->assertResponse($checksumResponse, $now);
            if (preg_match('/^([a-fA-F0-9]{64})[ \t]+\*?' . preg_quote($packageName . '.zip', '/') . '\s*$/D', trim($checksumResponse['body']), $checksum) !== 1) {
                throw new RuntimeException('release_checksum_invalid');
            }
            $manifestResponse = $this->http->request('GET', self::RAW_ROOT . $version . '/' . $packageName . '/DISTRIBUTION-MANIFEST.json', [], null, 3, 6, 2097152);
            $this->assertResponse($manifestResponse, $now);
            $manifest = json_decode($manifestResponse['body'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['product'] ?? '') !== 'OpenConcept'
                || ($manifest['product_version'] ?? '') !== $version
                || ($manifest['package_type'] ?? '') !== 'public-distribution'
                || ($manifest['instance_configuration_included'] ?? null) !== false
                || ($manifest['runtime_data_included'] ?? null) !== false
                || (int) ($manifest['file_count'] ?? 0) < 1) {
                throw new RuntimeException('release_manifest_invalid');
            }
            $state['latest'] = [
                'version' => $version,
                'id' => 'official-core-' . $version,
                'url' => self::WEB_ROOT . $version,
                'instructions_url' => self::WEB_ROOT . $version . '#readme',
                'published_at' => null,
                'notes' => '',
                'sha256' => strtolower($checksum[1]),
            ];
            $releaseNotes = $manifest['release_notes'] ?? null;
            if (is_array($releaseNotes)) {
                $state['latest']['notes'] = mb_substr((string) ($releaseNotes['summary'] ?? ''), 0, 12000);
                $date = $releaseNotes['published_at'] ?? null;
                if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T[0-9:Z+\-]+)?$/D', $date) === 1) {
                    $state['latest']['published_at'] = $date;
                }
            }
            $state['etag'] = $this->safeEtag($response['headers']['etag'] ?? '');
            return $this->success($state, $now);
        } catch (Throwable $exception) {
            $known = ['release_catalog_invalid', 'release_not_published', 'release_package_incomplete',
                'release_checksum_invalid', 'release_manifest_invalid', 'release_unavailable', 'release_rate_limited'];
            $state['error_code'] = in_array($exception->getMessage(), $known, true)
                ? $exception->getMessage() : 'release_check_failed';
            $state['failure_count']++;
            $delay = min(3600, 60 * (2 ** min(6, $state['failure_count'] - 1)));
            $state['next_attempt_at'] = max($now + $delay, $exception instanceof ApplicationReleaseRateLimit ? $exception->retryAt : 0);
            $state['status'] = 'failed';
            return $state;
        }
    }

    private function success(array $state, int $now): array
    {
        $state['latest'] = self::validatedRelease($state['latest']);
        $latestVersion = self::stableVersion((string) ($state['latest']['version'] ?? ''));
        if ($latestVersion === null) {
            throw new RuntimeException('release_manifest_invalid');
        }
        $state['status'] = version_compare($latestVersion, $state['installed_version'], '>') ? 'update_available' : 'current';
        $state['last_success_at'] = $now;
        $state['failure_count'] = 0;
        $state['error_code'] = null;
        $state['next_attempt_at'] = 0;
        return $state;
    }

    private function safeEtag(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 200 && preg_match('/^(?:W\/)?"[\x21\x23-\x7e]+"$/D', $value) === 1
            ? $value : '';
    }

    private function assertResponse(array $response, int $now): void
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status === 429 || ($status === 403 && (($response['headers']['x-ratelimit-remaining'] ?? '') === '0'
                || isset($response['headers']['retry-after'])))) {
            $retry = (string) ($response['headers']['retry-after'] ?? '');
            $retryAt = ctype_digit($retry) ? $now + min(86400, (int) $retry) : (int) strtotime($retry);
            $retryAt = max($retryAt, (int) ($response['headers']['x-ratelimit-reset'] ?? 0), $now + 60);
            throw new ApplicationReleaseRateLimit(min($now + 86400, $retryAt));
        }
        if ($status !== 200) {
            throw new RuntimeException('release_unavailable');
        }
    }
}

final class ApplicationReleaseRateLimit extends RuntimeException
{
    public function __construct(public readonly int $retryAt)
    {
        parent::__construct('release_rate_limited');
    }
}
