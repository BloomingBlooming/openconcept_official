<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginManager.php';

final class DatabaseAdapterRegistry
{
    /** @var array<string, DatabaseAdapterInterface> */
    private array $adapters = [];

    /** @var array<int, array{path: string, message: string}> */
    private array $errors = [];

    private array $installations = [];
    private array $rejections = [];
    private PluginCompatibility $compatibilityPolicy;

    public function __construct(private readonly string $pluginRoot, ?PluginCompatibility $compatibility = null)
    {
        $this->compatibilityPolicy = $compatibility ?? new PluginCompatibility(trim((string) file_get_contents(dirname(__DIR__) . '/VERSION')));
        $this->register(new SqliteDatabaseAdapter());
        $this->discover();
    }

    public function register(DatabaseAdapterInterface $adapter): void
    {
        $id = $adapter->id();
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $id) !== 1) {
            throw new InvalidArgumentException('Database adapter ID is invalid.');
        }
        if (isset($this->adapters[$id])) {
            throw new LogicException('Database adapter is already registered: ' . $id);
        }
        $this->adapters[$id] = $adapter;
    }

    public function get(string $id): DatabaseAdapterInterface
    {
        $adapter = $this->adapters[$id] ?? null;
        if (!$adapter instanceof DatabaseAdapterInterface) {
            if (isset($this->rejections[$id])) {
                throw new DatabaseUnavailableException($id,
                    'The configured database adapter is incompatible or its installation is invalid.',
                    null, 'adapter_incompatible', $this->rejections[$id]);
            }
            throw new DatabaseUnavailableException(
                $id,
                'The configured database adapter is not installed.',
                null,
                'adapter_missing'
            );
        }
        return $adapter;
    }

    public function forDsn(string $dsn): DatabaseAdapterInterface
    {
        $driver = strtolower((string) strstr($dsn, ':', true));
        $id = match ($driver) {
            'sqlite' => 'sqlite',
            'mysql' => 'mysql',
            'pgsql' => 'postgresql',
            default => '',
        };
        if ($id === '') {
            throw new DatabaseUnavailableException(
                'unknown',
                'The configured database driver is unsupported.',
                null,
                'driver_unsupported'
            );
        }
        return $this->get($id);
    }

    /** @return array<string, DatabaseAdapterInterface> */
    public function all(): array
    {
        return $this->adapters;
    }

    /** @return array<int, array{path: string, message: string}> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Fingerprint of the installation whose adapter object is already loaded. */
    public function fingerprintFor(string $id): string
    {
        $this->get($id);
        if ($id === 'sqlite') { return hash('sha256', 'OpenConcept builtin SQLite adapter'); }
        $fingerprint = $this->installations[$id]['fingerprint'] ?? null;
        if (!is_string($fingerprint)) { throw new DatabaseAdapterException('The adapter installation cannot be verified.', 'adapter_installation_changed'); }
        return $fingerprint;
    }

    /** Recheck after acquiring the cutover barrier; never execute adapter PHP here. */
    public function assertCurrent(string $id, string $expectedFingerprint): void
    {
        try {
            if (!hash_equals($this->fingerprintFor($id), $expectedFingerprint)) {
                throw new RuntimeException('The adapter instance does not match the expected installation.');
            }
            if ($id === 'sqlite') { return; }
            $current = $this->inspectInstallation($this->installations[$id]['path']);
            if (!hash_equals($expectedFingerprint, $current['fingerprint'])) {
                throw new RuntimeException('The adapter installation changed while waiting.');
            }
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException('The database adapter installation changed. Reload the plugin settings before retrying.',
                'adapter_installation_changed', $exception);
        }
    }

    private function discover(): void
    {
        if (!is_dir($this->pluginRoot)) {
            return;
        }
        $paths = glob(rtrim($this->pluginRoot, "/\\") . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'database-adapter.php') ?: [];
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $folder = basename(dirname($path));
            $expectedId = preg_match('/^database-([a-z][a-z0-9-]{0,31})-adapter$/D', $folder, $match) === 1 ? $match[1] : null;
            try {
                $installation = $this->inspectInstallation($path);
                $adapter = (static fn (string $entry): mixed => require $entry)($installation['path']);
                if (!$adapter instanceof DatabaseAdapterInterface) {
                    throw new RuntimeException('Adapter entry must return DatabaseAdapterInterface.');
                }
                if ($adapter->id() !== $expectedId) { throw new RuntimeException('Adapter ID does not match its plugin installation.'); }
                $this->register($adapter);
                $this->installations[$adapter->id()] = $installation;
            } catch (Throwable $exception) {
                if (is_string($expectedId) && $expectedId !== 'sqlite') {
                    $this->rejections[$expectedId] = $exception instanceof DatabaseAdapterException ? $exception->details() : [];
                }
                $this->errors[] = ['path' => $path, 'message' => $exception->getMessage()];
            }
        }
    }

    private function inspectInstallation(string $path): array
    {
        $directory = dirname($path);
        $folder = basename($directory);
        foreach ([$directory, $path, $directory . DIRECTORY_SEPARATOR . 'plugin.json'] as $candidate) {
            clearstatcache(true, $candidate);
        }
        if (preg_match('/^database-([a-z][a-z0-9-]{0,31})-adapter$/D', $folder, $match) !== 1 || $match[1] === 'sqlite') {
            throw new RuntimeException('Only a database adapter plugin can supply this entry point.');
        }
        $pluginRoot = realpath($this->pluginRoot);
        $pluginDirectory = realpath($directory);
        $realPath = realpath($path);
        if ($pluginRoot === false || $pluginDirectory === false || $realPath === false
            || is_link($directory) || is_link($path)
            || !$this->samePath(dirname($pluginDirectory), $pluginRoot)
            || !$this->samePath($pluginDirectory, $directory)
            || !$this->samePath(dirname($realPath), $pluginDirectory)
            || basename($realPath) !== 'database-adapter.php') {
            throw new RuntimeException('Adapter entry resolves outside its plugin installation.');
        }
        $manager = new PluginManager($this->pluginRoot, false, $this->compatibilityPolicy);
        $plugin = $manager->plugin($folder);
        $status = $manager->compatibilityFor($folder);
        if ($plugin === null || ($status['compatible'] ?? false) !== true) {
            throw new DatabaseAdapterException('The database adapter manifest is invalid or incompatible.', 'adapter_incompatible', null,
                ['compatibility' => $status ?? ['compatible' => false, 'code' => 'invalid_manifest']]);
        }
        $manifest = $pluginDirectory . DIRECTORY_SEPARATOR . 'plugin.json';
        if (is_link($manifest) || !$this->samePath($manifest, (string) realpath($manifest))) {
            throw new RuntimeException('The adapter manifest installation is unsafe.');
        }
        $manifestHash = hash_file('sha256', $manifest);
        $entryHash = hash_file('sha256', $realPath);
        if (!is_string($manifestHash) || !is_string($entryHash)) { throw new RuntimeException('The adapter installation cannot be fingerprinted.'); }
        return ['path' => $realPath, 'fingerprint' => hash('sha256', json_encode(
            ['plugin' => $folder, 'manifest' => $manifestHash, 'entry_path' => str_replace('\\', '/', $realPath), 'entry' => $entryHash], JSON_THROW_ON_ERROR))];
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
