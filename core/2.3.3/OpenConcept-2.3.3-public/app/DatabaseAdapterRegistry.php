<?php

declare(strict_types=1);

final class DatabaseAdapterRegistry
{
    /** @var array<string, DatabaseAdapterInterface> */
    private array $adapters = [];

    /** @var array<int, array{path: string, message: string}> */
    private array $errors = [];

    public function __construct(private readonly string $pluginRoot)
    {
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

    private function discover(): void
    {
        if (!is_dir($this->pluginRoot)) {
            return;
        }
        $paths = glob(rtrim($this->pluginRoot, "/\\") . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'database-adapter.php') ?: [];
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            try {
                $pluginDirectory = realpath(dirname($path));
                $pluginRoot = realpath($this->pluginRoot);
                $realPath = realpath($path);
                if ($pluginDirectory === false || $pluginRoot === false || $realPath === false
                    || !str_starts_with(str_replace('\\', '/', $pluginDirectory) . '/', str_replace('\\', '/', $pluginRoot) . '/')) {
                    throw new RuntimeException('Adapter entry resolves outside the plugin directory.');
                }
                $adapter = (static fn (string $entry): mixed => require $entry)($realPath);
                if (!$adapter instanceof DatabaseAdapterInterface) {
                    throw new RuntimeException('Adapter entry must return DatabaseAdapterInterface.');
                }
                $this->register($adapter);
            } catch (Throwable $exception) {
                $this->errors[] = ['path' => $path, 'message' => $exception->getMessage()];
            }
        }
    }
}
