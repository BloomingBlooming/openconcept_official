<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRegistry.php';

/** Release-owned minimum versions. Known plugins may publish backward-compatible updates. */
final class PluginCompatibility
{
    private const MINIMUMS = [
        '2.5.0' => [
            'ai-file-reader' => ['0.1.0'],
            'ai-search-voice' => ['0.3.1'],
            'database-mysql-adapter' => ['1.1.8'],
            'database-postgresql-adapter' => ['1.5.1'],
            'drawing-manager' => ['0.9.2'],
            'translation-openai' => ['1.0.0'],
            'voice-conversation' => ['0.15.1'],
        ],
    ];
    private array $versions;
    private string $apiVersion;
    private array $capabilities;
    private bool $knownApplication;

    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /** Preserve explicit version-list injection for fixtures; its lowest version is the minimum. */
    public function __construct(
        private readonly string $appVersion = '2.5.0',
        ?array $verifiedVersions = null,
        ?string $apiVersion = null,
        ?array $capabilities = null
    ) {
        $this->knownApplication = $verifiedVersions !== null || isset(self::MINIMUMS[$appVersion]);
        $this->versions = $verifiedVersions ?? self::MINIMUMS[$appVersion] ?? [];
        foreach ($this->versions as $id => $versions) {
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $id) !== 1
                || !is_array($versions) || !array_is_list($versions) || $versions === []) {
                throw new InvalidArgumentException('Compatibility requires exact plugin IDs and version lists.');
            }
            foreach ($versions as $version) {
                if (!is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
                    throw new InvalidArgumentException('Compatibility versions must be exact semantic versions.');
                }
            }
        }
        $this->apiVersion = $apiVersion ?? PluginApiRegistry::VERSION;
        $this->capabilities = $capabilities ?? PluginApiRegistry::CAPABILITIES;
    }

    public function appVersion(): string { return $this->appVersion; }

    public function check(array $plugin, bool $apiAvailable = true): array
    {
        $id = (string) ($plugin['id'] ?? '');
        $version = (string) ($plugin['version'] ?? '');
        $supported = $this->versions[$id] ?? [];
        $ordered = $supported;
        usort($ordered, self::compareVersions(...));
        $minimum = $ordered[0] ?? null;
        $status = ['compatible' => false, 'code' => 'compatible', 'app_version' => $this->appVersion,
            'plugin_version' => $version, 'supported_versions' => $supported, 'minimum_supported_version' => $minimum];
        $requirements = $plugin['requires'] ?? [];
        if (!is_array($requirements) || preg_match(self::VERSION_PATTERN, $version) !== 1) { $status['code'] = 'invalid_manifest'; return $status; }
        $requiredApi = $requirements['plugin_api'] ?? null;
        $requiredCapabilities = $requirements['capabilities'] ?? [];
        if (($requiredApi !== null && (!is_string($requiredApi) || preg_match(self::VERSION_PATTERN, $requiredApi) !== 1)) || !is_array($requiredCapabilities)) {
            $status['code'] = 'invalid_manifest'; return $status;
        }
        $missing = array_values(array_diff($requiredCapabilities, $this->capabilities));
        if ($requiredApi !== null || $requiredCapabilities !== []) {
            if ($requiredApi !== null) { $status['required_api'] = $requiredApi; }
            $status['available_api'] = $apiAvailable ? $this->apiVersion : null;
            $status['missing_capabilities'] = $missing;
        }
        $status['code'] = match (true) {
            !$this->knownApplication => 'unsupported_application',
            $supported === [] => 'unknown_plugin',
            self::compareVersions($version, $minimum) < 0 => 'unsupported_plugin_version',
            ($requiredApi !== null || $requiredCapabilities !== []) && !$apiAvailable => 'plugin_api_unavailable',
            $requiredApi !== null && explode('.', $requiredApi)[0] !== explode('.', $this->apiVersion)[0] => 'plugin_api_major_mismatch',
            $requiredApi !== null && self::compareVersions($this->apiVersion, $requiredApi) < 0 => 'plugin_api_version_too_old',
            $missing !== [] => 'plugin_api_missing_capabilities',
            default => 'compatible',
        };
        $status['compatible'] = $status['code'] === 'compatible';
        return $status;
    }

    /** SemVer precedence: numeric core, prerelease identifiers, then stable; build metadata is ignored. */
    public static function compareVersions(string $left, string $right): int
    {
        foreach ([$left, $right] as $version) {
            if (preg_match(self::VERSION_PATTERN, $version) !== 1) { throw new InvalidArgumentException('Invalid semantic version.'); }
        }
        $leftParts = explode('-', explode('+', $left, 2)[0], 2);
        $rightParts = explode('-', explode('+', $right, 2)[0], 2);
        $leftCore = explode('.', $leftParts[0]); $rightCore = explode('.', $rightParts[0]);
        for ($index = 0; $index < 3; $index++) {
            $comparison = self::compareNumericIdentifier($leftCore[$index], $rightCore[$index]);
            if ($comparison !== 0) { return $comparison; }
        }
        if (!isset($leftParts[1]) || !isset($rightParts[1])) { return isset($leftParts[1]) ? -1 : (isset($rightParts[1]) ? 1 : 0); }
        $leftPre = explode('.', $leftParts[1]); $rightPre = explode('.', $rightParts[1]);
        for ($index = 0; $index < min(count($leftPre), count($rightPre)); $index++) {
            $leftNumeric = ctype_digit($leftPre[$index]); $rightNumeric = ctype_digit($rightPre[$index]);
            $comparison = $leftNumeric && $rightNumeric
                ? self::compareNumericIdentifier($leftPre[$index], $rightPre[$index])
                : ($leftNumeric !== $rightNumeric ? ($leftNumeric ? -1 : 1) : (strcmp($leftPre[$index], $rightPre[$index]) <=> 0));
            if ($comparison !== 0) { return $comparison; }
        }
        return count($leftPre) <=> count($rightPre);
    }

    private static function compareNumericIdentifier(string $left, string $right): int
    {
        // Keep arbitrarily large version numbers exact. Leading zero prerelease
        // identifiers remain accepted, matching PluginManager's existing syntax.
        $left = ltrim($left, '0') ?: '0'; $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: (strcmp($left, $right) <=> 0);
    }

    /** Only fixed messages reach clients. Exception details remain server-side. */
    public static function error(string $code): array
    {
        return ['code' => $code, 'message' => match ($code) {
            'unsupported_application' => 'No plugin minimum-version policy is available for this application version.',
            'unknown_plugin' => 'This plugin is not on the application compatibility list.',
            'unsupported_plugin_version' => 'This plugin version is older than the minimum supported by this application.',
            'plugin_api_unavailable' => 'The required Plugin API is unavailable.',
            'plugin_api_major_mismatch' => 'The plugin requires a different Plugin API major version.',
            'plugin_api_version_too_old' => 'The plugin requires a newer Plugin API version.',
            'plugin_api_missing_capabilities' => 'The Plugin API does not provide the required capabilities.',
            'invalid_manifest' => 'The installed plugin manifest or its declared files are invalid.',
            default => 'The plugin could not start. Reinstall a supported version or review the server log.',
        }];
    }
}
