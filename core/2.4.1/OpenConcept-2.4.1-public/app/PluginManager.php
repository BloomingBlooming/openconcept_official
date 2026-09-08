<?php

declare(strict_types=1);

/**
 * Discovers and boots trusted, locally installed OpenConcept plugins.
 *
 * A plugin is isolated in plugins/<id>/ and owns its version through the
 * version field in that directory's plugin.json. Plugin versions are never
 * inferred from the OpenConcept application version.
 */
final class PluginManager
{
    private const ID_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';
    private const VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';
    private const RESERVED_IDS = [
        'rag-core',
        'rag-standard',
        'rag-ollama-adapter',
        'rag-custom-rag-adapter',
    ];

    private string $root;

    /** @var array<string, array<string, mixed>> */
    private array $plugins = [];

    /** @var array<int, array{plugin: string, path: string, message: string}> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $booted = [];

    private bool $logErrors;

    public function __construct(string $root, bool $logErrors = true)
    {
        $this->root = rtrim($root, "/\\");
        $this->logErrors = $logErrors;
        $this->discover();
    }

    /** @return array<int, array<string, mixed>> */
    public function plugins(bool $enabledOnly = true): array
    {
        $plugins = array_values($this->plugins);
        if ($enabledOnly) {
            $plugins = array_values(array_filter(
                $plugins,
                static fn (array $plugin): bool => $plugin['enabled'] === true
            ));
        }
        return $plugins;
    }

    /** @return array<string, mixed>|null */
    public function plugin(string $id): ?array
    {
        return $this->plugins[$id] ?? null;
    }

    /**
     * Applies administrator-controlled states stored outside plugin source.
     * Call this before booting so a disabled plugin's PHP is never executed.
     *
     * @param array<string, bool> $states
     */
    public function applyEnabledStates(array $states): void
    {
        foreach ($states as $id => $enabled) {
            if (isset($this->plugins[$id]) && is_bool($enabled)) {
                $this->plugins[$id]['enabled'] = $enabled;
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function summaries(): array
    {
        return array_map(
            static fn (array $plugin): array => [
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'version' => $plugin['version'],
                'enabled' => $plugin['enabled'],
                'description' => $plugin['description'],
                'ui' => $plugin['ui'],
            ],
            $this->plugins(false)
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function activePlugins(): array
    {
        return array_values(array_filter(
            $this->plugins(true),
            fn (array $plugin): bool => isset($this->booted[$plugin['id']])
        ));
    }

    /** @return array<string, string> */
    public function versions(bool $enabledOnly = false): array
    {
        $versions = [];
        foreach ($this->plugins($enabledOnly) as $plugin) {
            $versions[$plugin['id']] = $plugin['version'];
        }
        return $versions;
    }

    /** @return array<string, string> */
    public function activeVersions(): array
    {
        $versions = [];
        foreach ($this->activePlugins() as $plugin) {
            $versions[$plugin['id']] = $plugin['version'];
        }
        return $versions;
    }

    /** @return array<int, array{id: string, label: string, icon: string, placement: string, version: string}> */
    public function activeSidebarItems(): array
    {
        $items = [];
        foreach ($this->activePlugins() as $plugin) {
            $sidebar = $plugin['ui']['sidebar'] ?? null;
            if (!is_array($sidebar)) {
                continue;
            }
            $items[] = [
                'id' => $plugin['id'],
                'label' => $sidebar['label'],
                'icon' => $sidebar['icon'],
                'placement' => $sidebar['placement'],
                'version' => $plugin['version'],
            ];
        }
        return $items;
    }

    /** @return array<string, mixed> */
    public function clientState(): array
    {
        $sidebarItems = $this->activeSidebarItems();
        $aiSearchIds = array_fill_keys(array_column(array_filter(
            $sidebarItems,
            static fn (array $item): bool => $item['placement'] === 'ai-search'
        ), 'id'), true);
        $isAiSearchAsset = static fn (array $asset): bool => isset($aiSearchIds[$asset['plugin']]);

        return [
            'plugins' => $this->activeVersions(),
            'plugin_ui' => $sidebarItems,
            'ai_search_assets' => [
                'styles' => array_values(array_filter($this->styleAssets(), $isAiSearchAsset)),
                'scripts' => array_values(array_filter($this->scriptAssets(), $isAiSearchAsset)),
            ],
        ];
    }

    public static function isValidId(string $id): bool
    {
        return !self::isReservedId($id)
            && strlen($id) <= 64
            && preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function isReservedId(string $id): bool
    {
        return in_array($id, self::RESERVED_IDS, true);
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1;
    }

    /** @return array<int, array{plugin: string, path: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function boot(array $context = []): void
    {
        foreach ($this->plugins(true) as $plugin) {
            $id = $plugin['id'];
            if (isset($this->booted[$id])) {
                continue;
            }

            try {
                if ($plugin['entry'] !== null) {
                    $result = $this->requireEntry($plugin['entry']);
                    if (is_callable($result)) {
                        $result(array_merge($context, [
                            'plugin' => $plugin,
                            'plugin_root' => $plugin['directory'],
                        ]));
                    } elseif (!in_array($result, [null, true, 1], true)) {
                        throw new RuntimeException('The PHP entry point must return a callable or no value.');
                    }
                }
                $this->booted[$id] = true;
            } catch (Throwable $exception) {
                $this->recordError($id, $plugin['directory'], 'Boot failed: ' . $exception->getMessage());
            }
        }
    }

    /** @return array<int, array{plugin: string, version: string, file: string}> */
    public function styleAssets(): array
    {
        return $this->assetsFor('styles');
    }

    /** @return array<int, array{plugin: string, version: string, file: string}> */
    public function scriptAssets(): array
    {
        return $this->assetsFor('scripts');
    }

    /**
     * Resolves an asset only when it is declared by an enabled plugin and the
     * requested version matches that plugin's own manifest version.
     *
     * @return array{path: string, type: string}|null
     */
    public function resolveAsset(string $pluginId, string $version, string $file): ?array
    {
        $plugin = $this->plugins[$pluginId] ?? null;
        if ($plugin === null || $plugin['enabled'] !== true || $plugin['version'] !== $version) {
            return null;
        }

        $type = null;
        if (in_array($file, $plugin['assets']['styles'], true)) {
            $type = 'text/css; charset=UTF-8';
        } elseif (in_array($file, $plugin['assets']['scripts'], true)) {
            $type = 'application/javascript; charset=UTF-8';
        } elseif (in_array($file, $plugin['assets']['resources'], true)) {
            $type = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'mjs' => 'application/javascript; charset=UTF-8',
                'wasm' => 'application/wasm',
                'ttf' => 'font/ttf',
                'pfb' => 'application/octet-stream',
                default => null,
            };
        }
        if ($type === null) {
            return null;
        }

        $path = $this->resolveDeclaredFile($plugin['directory'], $file);
        return $path === null ? null : ['path' => $path, 'type' => $type];
    }

    private function discover(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $directories = glob($this->root . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [];
        sort($directories, SORT_STRING);

        foreach ($directories as $directory) {
            $folder = basename($directory);
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'plugin.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            try {
                if (!$this->isWithinDirectory($this->root, $directory)) {
                    throw new RuntimeException('The plugin folder resolves outside the plugins directory.');
                }
                $plugin = $this->readManifest($manifestPath, $directory);
                $this->plugins[$plugin['id']] = $plugin;
            } catch (Throwable $exception) {
                $this->recordError($folder, $manifestPath, $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function readManifest(string $manifestPath, string $directory): array
    {
        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new RuntimeException('The manifest could not be read.');
        }

        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON: ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($manifest)) {
            throw new RuntimeException('The manifest root must be an object.');
        }
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('schema_version must be 1.');
        }

        $id = $manifest['id'] ?? null;
        if (!is_string($id) || !self::isValidId($id)) {
            throw new RuntimeException('id must be a lower-case hyphenated identifier of at most 64 characters.');
        }
        if (basename($directory) !== $id) {
            throw new RuntimeException(sprintf('The plugin folder must have the same name as id "%s".', $id));
        }

        $name = $manifest['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('name must be a non-empty string.');
        }

        $version = $manifest['version'] ?? null;
        if (!is_string($version) || !self::isValidVersion($version)) {
            throw new RuntimeException('version must be a valid semantic version such as 1.2.3.');
        }

        $enabled = $manifest['enabled'] ?? true;
        if (!is_bool($enabled)) {
            throw new RuntimeException('enabled must be true or false.');
        }

        $entry = $manifest['entry'] ?? null;
        if ($entry !== null && (!is_string($entry) || strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== 'php')) {
            throw new RuntimeException('entry must be a relative PHP file path.');
        }
        $resolvedEntry = $entry === null ? null : $this->resolveDeclaredFile($directory, $entry);
        if ($entry !== null && $resolvedEntry === null) {
            throw new RuntimeException(sprintf('The entry file "%s" does not exist or is outside the plugin folder.', $entry));
        }

        $assets = $manifest['assets'] ?? [];
        if (!is_array($assets)) {
            throw new RuntimeException('assets must be an object.');
        }
        $styles = $this->validateAssetList($directory, $assets['styles'] ?? [], 'styles', 'css');
        $scripts = $this->validateAssetList($directory, $assets['scripts'] ?? [], 'scripts', 'js');
        $resources = $this->validateResourceList($directory, $assets['resources'] ?? []);
        $ui = $this->validateUi($manifest['ui'] ?? []);

        return [
            'id' => $id,
            'name' => trim($name),
            'version' => $version,
            'enabled' => $enabled,
            'description' => is_string($manifest['description'] ?? null) ? trim($manifest['description']) : '',
            'directory' => realpath($directory) ?: $directory,
            'manifest' => $manifestPath,
            'entry' => $resolvedEntry,
            'assets' => [
                'styles' => $styles,
                'scripts' => $scripts,
                'resources' => $resources,
            ],
            'ui' => $ui,
        ];
    }

    /** @return array{sidebar: array{label: string, icon: string, placement: string}|null} */
    private function validateUi(mixed $ui): array
    {
        if (!is_array($ui)) {
            throw new RuntimeException('ui must be an object.');
        }

        $sidebar = $ui['sidebar'] ?? null;
        if ($sidebar === null || $sidebar === false) {
            return ['sidebar' => null];
        }
        if (!is_array($sidebar)) {
            throw new RuntimeException('ui.sidebar must be an object or false.');
        }

        $label = $sidebar['label'] ?? null;
        $icon = $sidebar['icon'] ?? null;
        $placement = $sidebar['placement'] ?? 'default';
        if (!is_string($label) || trim($label) === '' || $this->textLength($label) > 80) {
            throw new RuntimeException('ui.sidebar.label must be a non-empty string of at most 80 characters.');
        }
        if (!is_string($icon) || trim($icon) === '' || $this->textLength($icon) > 16) {
            throw new RuntimeException('ui.sidebar.icon must be a non-empty string of at most 16 characters.');
        }
        if (!is_string($placement) || !in_array($placement, ['default', 'ai-search'], true)) {
            throw new RuntimeException('ui.sidebar.placement must be either "default" or "ai-search".');
        }

        return ['sidebar' => ['label' => trim($label), 'icon' => trim($icon), 'placement' => $placement]];
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** @return array<int, string> */
    private function validateAssetList(string $directory, mixed $files, string $field, string $extension): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new RuntimeException(sprintf('assets.%s must be a list.', $field));
        }

        $validated = [];
        foreach ($files as $file) {
            if (!is_string($file) || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== $extension) {
                throw new RuntimeException(sprintf('Every assets.%s entry must be a relative .%s file path.', $field, $extension));
            }
            if ($this->resolveDeclaredFile($directory, $file) === null) {
                throw new RuntimeException(sprintf('The asset "%s" does not exist or is outside the plugin folder.', $file));
            }
            $validated[] = str_replace('\\', '/', $file);
        }
        return array_values(array_unique($validated));
    }

    /** @return array<int, string> */
    private function validateResourceList(string $directory, mixed $files): array
    {
        if (!is_array($files) || !array_is_list($files)) {
            throw new RuntimeException('assets.resources must be a list.');
        }

        $validated = [];
        foreach ($files as $file) {
            $extension = is_string($file) ? strtolower(pathinfo($file, PATHINFO_EXTENSION)) : '';
            if (!is_string($file) || !in_array($extension, ['mjs', 'wasm', 'ttf', 'pfb'], true)) {
                throw new RuntimeException('Every assets.resources entry must be a relative .mjs, .wasm, .ttf, or .pfb file path.');
            }
            if ($this->resolveDeclaredFile($directory, $file) === null) {
                throw new RuntimeException(sprintf('The resource "%s" does not exist or is outside the plugin folder.', $file));
            }
            $validated[] = str_replace('\\', '/', $file);
        }
        return array_values(array_unique($validated));
    }

    private function resolveDeclaredFile(string $directory, string $relativePath): ?string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')) {
            return null;
        }
        if (str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:\//', $relativePath) === 1) {
            return null;
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $base = realpath($directory);
        $path = realpath($directory . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments));
        if ($base === false || $path === false || !is_file($path)) {
            return null;
        }

        return $this->isWithinDirectory($base, $path) ? $path : null;
    }

    private function isWithinDirectory(string $directory, string $path): bool
    {
        $base = realpath($directory);
        $resolvedPath = realpath($path);
        if ($base === false || $resolvedPath === false) {
            return false;
        }

        $normalizedBase = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $resolvedPath);
        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedBase = strtolower($normalizedBase);
            $normalizedPath = strtolower($normalizedPath);
        }
        return str_starts_with($normalizedPath, $normalizedBase);
    }

    private function requireEntry(string $path): mixed
    {
        return require $path;
    }

    /** @return array<int, array{plugin: string, version: string, file: string}> */
    private function assetsFor(string $kind): array
    {
        $assets = [];
        foreach ($this->activePlugins() as $plugin) {
            foreach ($plugin['assets'][$kind] as $file) {
                $assets[] = [
                    'plugin' => $plugin['id'],
                    'version' => $plugin['version'],
                    'file' => $file,
                ];
            }
        }
        return $assets;
    }

    private function recordError(string $plugin, string $path, string $message): void
    {
        $error = ['plugin' => $plugin, 'path' => $path, 'message' => $message];
        $this->errors[] = $error;
        if ($this->logErrors) {
            error_log(sprintf('[OpenConcept plugin:%s] %s (%s)', $plugin, $message, $path));
        }
    }
}
