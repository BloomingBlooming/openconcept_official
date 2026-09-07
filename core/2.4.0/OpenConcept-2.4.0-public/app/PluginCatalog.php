<?php

declare(strict_types=1);

/**
 * Reads a trusted HTTPS plugin catalog and installs integrity-checked
 * OpenConcept JSON packages without requiring a ZIP extension.
 */
final class PluginCatalog
{
    public const OFFICIAL_CATALOG_URL = 'https://raw.githubusercontent.com/BloomingBlooming/openconcept_official/main/plugins/catalog.json';
    private const OFFICIAL_PACKAGES_URL = 'https://raw.githubusercontent.com/BloomingBlooming/openconcept_official/main/plugins/packages/';
    private const MAX_CATALOG_BYTES = 1048576;
    private const MAX_PACKAGE_BYTES = 15728640;
    private const MAX_DECODED_BYTES = 10485760;
    private const MAX_FILES = 200;

    private string $catalogUrl;
    private string $pluginsRoot;
    private ?Closure $fetcher;

    /** @var array<int, array<string, string>>|null */
    private ?array $entries = null;

    public function __construct(string $catalogUrl, string $pluginsRoot, ?callable $fetcher = null)
    {
        $this->catalogUrl = trim($catalogUrl);
        $this->pluginsRoot = rtrim($pluginsRoot, "/\\");
        $this->fetcher = $fetcher === null ? null : Closure::fromCallable($fetcher);
    }

    public function isConfigured(): bool
    {
        return $this->catalogUrl !== '';
    }

    public static function official(string $pluginsRoot, ?callable $fetcher = null): self
    {
        return new self(self::OFFICIAL_CATALOG_URL, $pluginsRoot, $fetcher);
    }

    public static function officialPackageUrl(string $id, string $version): string
    {
        if (!PluginManager::isValidId($id) || !PluginManager::isValidVersion($version)) {
            throw new InvalidArgumentException('The official package identity is invalid.');
        }
        return self::OFFICIAL_PACKAGES_URL . rawurlencode($id) . '/' . rawurlencode($version)
            . '/' . rawurlencode($id . '-' . $version . '.oc-plugin.json');
    }

    /** @return array<int, array<string, string>> */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }
        if (!$this->isConfigured()) {
            return $this->entries = [];
        }

        $this->assertHttpsUrl($this->catalogUrl, 'Plugin catalog');
        $catalog = $this->decodeJson(
            $this->fetch($this->catalogUrl, self::MAX_CATALOG_BYTES),
            'Plugin catalog'
        );
        if (($catalog['schema_version'] ?? null) !== 1 || !is_array($catalog['plugins'] ?? null) || !array_is_list($catalog['plugins'])) {
            throw new RuntimeException('Plugin catalog must use schema_version 1 and contain a plugins list.');
        }
        if (count($catalog['plugins']) > 200) {
            throw new RuntimeException('Plugin catalog contains too many entries.');
        }

        $entries = [];
        $seen = [];
        foreach ($catalog['plugins'] as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('Every plugin catalog entry must be an object.');
            }
            $id = $item['id'] ?? null;
            $name = $item['name'] ?? null;
            $version = $item['version'] ?? null;
            $description = $item['description'] ?? '';
            $icon = $item['icon'] ?? '🧩';
            $downloadUrl = $item['download_url'] ?? null;
            $sha256 = strtolower((string) ($item['sha256'] ?? ''));

            if (!is_string($id) || isset($seen[$id])) {
                throw new RuntimeException('Plugin catalog IDs must be unique lower-case hyphenated identifiers.');
            }
            $seen[$id] = true;
            // Core-owned and retired built-in IDs may remain in an older
            // catalog during an application rollout. Hide only those entries
            // while keeping the rest of the catalog usable; install() still
            // rejects them through PluginManager::isValidId().
            if (PluginManager::isReservedId($id)) {
                continue;
            }
            if (!PluginManager::isValidId($id)) {
                throw new RuntimeException('Plugin catalog IDs must be unique lower-case hyphenated identifiers.');
            }
            if (!is_string($name) || trim($name) === '' || $this->textLength($name) > 120) {
                throw new RuntimeException(sprintf('Plugin "%s" has an invalid name.', $id));
            }
            if (!is_string($version) || !PluginManager::isValidVersion($version)) {
                throw new RuntimeException(sprintf('Plugin "%s" has an invalid semantic version.', $id));
            }
            if (!is_string($description) || $this->textLength($description) > 500) {
                throw new RuntimeException(sprintf('Plugin "%s" has an invalid description.', $id));
            }
            if (!is_string($icon) || trim($icon) === '' || $this->textLength($icon) > 16) {
                throw new RuntimeException(sprintf('Plugin "%s" has an invalid icon.', $id));
            }
            if (!is_string($downloadUrl)) {
                throw new RuntimeException(sprintf('Plugin "%s" has no download URL.', $id));
            }
            $this->assertHttpsUrl($downloadUrl, sprintf('Download URL for plugin "%s"', $id));
            if (!$this->sameOrigin($this->catalogUrl, $downloadUrl)) {
                throw new RuntimeException(sprintf('Plugin "%s" must be downloaded from the catalog origin.', $id));
            }
            // raw.githubusercontent.com hosts unrelated publishers too. Pin the
            // owner, repository, branch and exact versioned package path.
            if ($this->catalogUrl === self::OFFICIAL_CATALOG_URL
                && $downloadUrl !== self::officialPackageUrl($id, $version)) {
                throw new RuntimeException(sprintf('Plugin "%s" is outside the official package location.', $id));
            }
            if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException(sprintf('Plugin "%s" has an invalid SHA-256 digest.', $id));
            }

            $entries[] = [
                'id' => $id,
                'name' => trim($name),
                'version' => $version,
                'description' => trim($description),
                'icon' => trim($icon),
                'download_url' => $downloadUrl,
                'sha256' => $sha256,
            ];
        }

        return $this->entries = $entries;
    }

    /** @return array<string, mixed> */
    public function install(
        string $pluginId,
        ?callable $beforeCommit = null,
        ?callable $afterInstall = null
    ): array
    {
        if (!PluginManager::isValidId($pluginId)) {
            throw new InvalidArgumentException('The plugin ID is invalid.');
        }

        $entry = null;
        foreach ($this->entries() as $candidate) {
            if ($candidate['id'] === $pluginId) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null) {
            throw new RuntimeException('The requested plugin is not present in the configured catalog.');
        }

        if (!is_dir($this->pluginsRoot) && !mkdir($this->pluginsRoot, 0775, true) && !is_dir($this->pluginsRoot)) {
            throw new RuntimeException('The plugins directory could not be created.');
        }
        $target = $this->pluginsRoot . DIRECTORY_SEPARATOR . $pluginId;
        if (file_exists($target)) {
            throw new RuntimeException('The plugin is already installed.');
        }

        $packageBytes = $this->fetch($entry['download_url'], self::MAX_PACKAGE_BYTES);
        if (!hash_equals($entry['sha256'], hash('sha256', $packageBytes))) {
            throw new RuntimeException('The downloaded plugin package failed SHA-256 verification.');
        }
        $package = $this->decodeJson($packageBytes, 'Plugin package');
        if (($package['schema_version'] ?? null) !== 1 || !is_array($package['manifest'] ?? null) || !is_array($package['files'] ?? null)) {
            throw new RuntimeException('Plugin package must contain schema_version 1, manifest, and files.');
        }

        $manifest = $package['manifest'];
        if (($manifest['id'] ?? null) !== $pluginId || ($manifest['version'] ?? null) !== $entry['version']) {
            throw new RuntimeException('Plugin package identity does not match its catalog entry.');
        }
        // Defense in depth: the installed manifest is disabled even if the
        // package author shipped it enabled or the subsequent DB write fails.
        $manifest['enabled'] = false;
        if (count($package['files']) > self::MAX_FILES) {
            throw new RuntimeException('Plugin package files must be an object with at most 200 entries.');
        }

        $stagingParent = $this->pluginsRoot . DIRECTORY_SEPARATOR . '.install-' . bin2hex(random_bytes(12));
        $stagingPlugin = $stagingParent . DIRECTORY_SEPARATOR . $pluginId;
        if (!mkdir($stagingPlugin, 0700, true)) {
            throw new RuntimeException('A temporary plugin installation folder could not be created.');
        }

        $installed = false;
        try {
            $decodedBytes = 0;
            foreach ($package['files'] as $relativePath => $file) {
                if (!is_string($relativePath) || !$this->isSafeRelativePath($relativePath) || $relativePath === 'plugin.json') {
                    throw new RuntimeException('Plugin package contains an unsafe file path.');
                }
                if (!is_array($file) || ($file['encoding'] ?? null) !== 'base64' || !is_string($file['content'] ?? null)) {
                    throw new RuntimeException(sprintf('Plugin package file "%s" is malformed.', $relativePath));
                }
                $expectedHash = strtolower((string) ($file['sha256'] ?? ''));
                if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
                    throw new RuntimeException(sprintf('Plugin package file "%s" has an invalid digest.', $relativePath));
                }
                $contents = base64_decode($file['content'], true);
                if ($contents === false || !hash_equals($expectedHash, hash('sha256', $contents))) {
                    throw new RuntimeException(sprintf('Plugin package file "%s" failed integrity verification.', $relativePath));
                }
                $decodedBytes += strlen($contents);
                if ($decodedBytes > self::MAX_DECODED_BYTES) {
                    throw new RuntimeException('Plugin package exceeds the 10 MB extracted-size limit.');
                }

                $path = $stagingPlugin . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
                $parent = dirname($path);
                if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                    throw new RuntimeException(sprintf('Could not create a folder for "%s".', $relativePath));
                }
                if (file_put_contents($path, $contents, LOCK_EX) === false) {
                    throw new RuntimeException(sprintf('Could not write plugin file "%s".', $relativePath));
                }
            }

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($stagingPlugin . DIRECTORY_SEPARATOR . 'plugin.json', $manifestJson, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the plugin manifest.');
            }

            $validator = new PluginManager($stagingParent, false);
            $validatedPlugin = $validator->plugin($pluginId);
            if ($validatedPlugin === null || $validator->errors() !== []) {
                $messages = array_column($validator->errors(), 'message');
                throw new RuntimeException('Downloaded plugin validation failed: ' . implode('; ', $messages));
            }
            if ($beforeCommit !== null) {
                $beforeCommit($validatedPlugin);
            }
            if (file_exists($target) || !rename($stagingPlugin, $target)) {
                throw new RuntimeException('The plugin could not be moved into the plugins directory.');
            }
            $installed = true;
            if ($afterInstall !== null) {
                $afterInstall($validatedPlugin);
            }
            @rmdir($stagingParent);

            return [
                'id' => $validatedPlugin['id'],
                'name' => $validatedPlugin['name'],
                'version' => $validatedPlugin['version'],
                'enabled' => false,
                'description' => $validatedPlugin['description'],
                'ui' => $validatedPlugin['ui'],
            ];
        } catch (Throwable $exception) {
            if ($installed && is_dir($target)) {
                if (!is_dir($stagingParent)) {
                    @mkdir($stagingParent, 0700, true);
                }
                if (!is_dir($stagingPlugin) && !@rename($target, $stagingPlugin)) {
                    $this->removeTree($target);
                }
            }
            $this->removeTree($stagingParent);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label . ' is not valid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' root must be an object.');
        }
        return $decoded;
    }

    private function fetch(string $url, int $maximumBytes): string
    {
        if ($this->fetcher !== null) {
            $contents = ($this->fetcher)($url, $maximumBytes);
            if (!is_string($contents)) {
                throw new RuntimeException('Plugin download transport returned an invalid response.');
            }
            if (strlen($contents) > $maximumBytes) {
                throw new RuntimeException('Plugin download exceeded the configured size limit.');
            }
            return $contents;
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required for plugin downloads.');
        }

        $buffer = '';
        $tooLarge = false;
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Plugin download could not be initialized.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'OpenConcept-Plugin-Installer/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$buffer, &$tooLarge, $maximumBytes): int {
                if (strlen($buffer) + strlen($chunk) > $maximumBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $buffer .= $chunk;
                return strlen($chunk);
            },
        ];
        $caBundle = trim((string) getenv('OPENCONCEPT_CA_BUNDLE'));
        if ($caBundle !== '' && is_file($caBundle)) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($curl, $options);
        $success = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($tooLarge) {
            throw new RuntimeException('Plugin download exceeded the configured size limit.');
        }
        if ($success === false || $status !== 200) {
            throw new RuntimeException(sprintf('Plugin download failed (HTTP %d%s).', $status, $error !== '' ? ': ' . $error : ''));
        }
        return $buffer;
    }

    private function assertHttpsUrl(string $url, string $label): void
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            throw new RuntimeException($label . ' must be an absolute HTTPS URL without credentials or a fragment.');
        }
    }

    private function sameOrigin(string $left, string $right): bool
    {
        $leftParts = parse_url($left);
        $rightParts = parse_url($right);
        if (!is_array($leftParts) || !is_array($rightParts)) {
            return false;
        }
        return strtolower((string) ($leftParts['scheme'] ?? '')) === strtolower((string) ($rightParts['scheme'] ?? ''))
            && strtolower((string) ($leftParts['host'] ?? '')) === strtolower((string) ($rightParts['host'] ?? ''))
            && (int) ($leftParts['port'] ?? 443) === (int) ($rightParts['port'] ?? 443);
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return false;
            }
        }
        return preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->removeTree($itemPath);
            } else {
                @unlink($itemPath);
            }
        }
        @rmdir($path);
    }
}
