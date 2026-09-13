<?php

declare(strict_types=1);

final class DatabaseCapabilities implements DatabaseCapabilitiesInterface
{
    /** @var array<string, string> */
    private array $extensions;

    /**
     * @param array<string, string> $extensions Installed extension => version.
     */
    public function __construct(
        private readonly string $driver,
        private readonly bool $transactions,
        private readonly bool $vectorSearch = false,
        array $extensions = []
    ) {
        $normalized = [];
        foreach ($extensions as $name => $version) {
            $normalized[strtolower(trim((string) $name))] = trim((string) $version);
        }
        $this->extensions = $normalized;
    }

    public function driverName(): string
    {
        return $this->driver;
    }

    public function supportsExtension(string $name): bool
    {
        return $this->extensionVersion($name) !== null;
    }

    public function extensionVersion(string $name): ?string
    {
        $name = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $name) !== 1) {
            return null;
        }
        return array_key_exists($name, $this->extensions) ? $this->extensions[$name] : null;
    }

    public function supportsTransactions(): bool
    {
        return $this->transactions;
    }

    public function supportsVectorSearch(): bool
    {
        return $this->vectorSearch;
    }
}
