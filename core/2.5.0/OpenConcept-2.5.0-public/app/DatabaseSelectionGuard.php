<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseAdapterException.php';

/**
 * Binds one OpenConcept storage workspace to one physical database selection.
 *
 * The binding survives ordinary request and process shutdown so sequentially
 * alternating web/cron environments cannot evade split-brain detection. A
 * reviewed adapter cutover clears the binding while the application-wide
 * writer drain is exclusive; the next canonical process then seals the new
 * selection.
 */
final class DatabaseSelectionGuard
{
    private const FORMAT_VERSION = 1;

    /** @var array<int, array{handle: resource, path: string, fingerprint: string}> */
    private static array $activeHandles = [];

    /** @var array<string, string> Process-local bindings for explicit CLI test databases. */
    private static array $memoryBindings = [];

    public function __construct(
        private readonly string $storagePath,
        private readonly bool $persistent = true
    ) {
    }

    /**
     * @param array<string, scalar|null> $identity Credential-free physical database identity.
     * @return resource
     */
    public function acquire(string $backend, array $identity)
    {
        $backend = strtolower(trim($backend));
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $backend) !== 1) {
            throw new DatabaseAdapterException(
                'The selected database backend identifier is invalid.',
                'database_selection_guard_invalid'
            );
        }

        ksort($identity, SORT_STRING);
        try {
            $fingerprint = hash('sha256', json_encode([
                'backend' => $backend,
                'identity' => $identity,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            throw $this->unavailable($exception);
        }
        $path = $this->persistent
            ? $this->lockPath()
            : 'memory:' . hash('sha256', str_replace('\\', '/', $this->storagePath));

        foreach (self::$activeHandles as $record) {
            if (($record['path'] ?? null) === $path
                && is_resource($record['handle'] ?? null)
                && !hash_equals((string) $record['fingerprint'], $fingerprint)) {
                throw $this->conflict();
            }
        }

        if (!$this->persistent) {
            $boundFingerprint = self::$memoryBindings[$path] ?? null;
            if (is_string($boundFingerprint) && !hash_equals($boundFingerprint, $fingerprint)) {
                throw $this->conflict();
            }
            self::$memoryBindings[$path] = $fingerprint;
            $handle = fopen('php://temp', 'w+b');
            if (!is_resource($handle)) {
                throw $this->unavailable();
            }
            self::$activeHandles[get_resource_id($handle)] = [
                'handle' => $handle,
                'path' => $path,
                'fingerprint' => $fingerprint,
            ];
            return $handle;
        }

        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle)) {
            throw $this->unavailable();
        }
        @chmod($path, 0600);

        try {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $record = $this->readRecord($handle);
                if ($record === null) {
                    $this->writeRecord($handle, $backend, $fingerprint);
                } else {
                    $this->assertRecordMatches($record, $fingerprint);
                }
                if (!flock($handle, LOCK_SH)) {
                    throw $this->unavailable();
                }
            } else {
                if (!flock($handle, LOCK_SH | LOCK_NB)) {
                    throw $this->unavailable();
                }
                $record = $this->readRecord($handle);
                if ($record === null) {
                    // Another process can expose an empty binding only while a
                    // reviewed cutover is clearing it. Stop instead of guessing.
                    throw $this->unavailable();
                }
                $this->assertRecordMatches($record, $fingerprint);
            }
        } catch (Throwable $exception) {
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($exception instanceof DatabaseAdapterException) {
                throw $exception;
            }
            throw $this->unavailable($exception);
        }

        self::$activeHandles[get_resource_id($handle)] = [
            'handle' => $handle,
            'path' => $path,
            'fingerprint' => $fingerprint,
        ];
        return $handle;
    }

    /** @param resource $handle */
    public function release($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        unset(self::$activeHandles[get_resource_id($handle)]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Clear the persistent binding only after a validated canonical cutover.
     * DatabaseMaintenanceMode must already have drained every other process.
     *
     * @param resource $handle
     */
    public function releaseForCanonicalCutover($handle): void
    {
        if (!is_resource($handle)) {
            throw new LogicException('The database selection lease is unavailable.');
        }
        $id = get_resource_id($handle);
        $record = self::$activeHandles[$id] ?? null;
        if (!is_array($record) || ($record['handle'] ?? null) !== $handle) {
            throw new LogicException('The database selection lease is not owned by this process.');
        }
        if (str_starts_with((string) $record['path'], 'memory:')) {
            unset(self::$memoryBindings[(string) $record['path']], self::$activeHandles[$id]);
            fclose($handle);
            return;
        }
        if (!flock($handle, LOCK_EX)) {
            throw $this->unavailable();
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0 || !fflush($handle)) {
            throw $this->unavailable();
        }
        unset(self::$activeHandles[$id]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function lockPath(): string
    {
        $directory = rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . '.database-adapters';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw $this->unavailable();
        }
        @chmod($directory, 0700);
        return $directory . DIRECTORY_SEPARATOR . 'runtime-selection.lock';
    }

    /** @param resource $handle @return array<string, mixed>|null */
    private function readRecord($handle): ?array
    {
        if (fseek($handle, 0) !== 0) {
            throw $this->unavailable();
        }
        $payload = stream_get_contents($handle);
        if (!is_string($payload)) {
            throw $this->unavailable();
        }
        if (trim($payload) === '') {
            return null;
        }
        try {
            $record = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseAdapterException(
                'The database selection guard record is invalid. OpenConcept stopped to protect canonical data.',
                'database_selection_guard_invalid',
                $exception
            );
        }
        if (!is_array($record)
            || ($record['format_version'] ?? null) !== self::FORMAT_VERSION
            || preg_match('/^[a-z][a-z0-9_]{0,31}$/D', (string) ($record['backend'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($record['fingerprint'] ?? '')) !== 1) {
            throw new DatabaseAdapterException(
                'The database selection guard record is invalid. OpenConcept stopped to protect canonical data.',
                'database_selection_guard_invalid'
            );
        }
        return $record;
    }

    /** @param resource $handle */
    private function writeRecord($handle, string $backend, string $fingerprint): void
    {
        $payload = json_encode([
            'format_version' => self::FORMAT_VERSION,
            'backend' => $backend,
            'fingerprint' => $fingerprint,
            'bound_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (ftruncate($handle, 0) === false
            || fseek($handle, 0) !== 0
            || fwrite($handle, $payload) !== strlen($payload)
            || !fflush($handle)) {
            throw $this->unavailable();
        }
    }

    /** @param array<string, mixed> $record */
    private function assertRecordMatches(array $record, string $fingerprint): void
    {
        if (!hash_equals((string) $record['fingerprint'], $fingerprint)) {
            throw $this->conflict();
        }
    }

    private function conflict(): DatabaseAdapterException
    {
        return new DatabaseAdapterException(
            'A different or multiple database selection was detected. OpenConcept stopped to protect canonical data.',
            'database_selection_conflict'
        );
    }

    private function unavailable(?Throwable $previous = null): DatabaseAdapterException
    {
        return new DatabaseAdapterException(
            'The database selection guard could not prove a single canonical database. OpenConcept stopped to protect canonical data.',
            'database_selection_guard_unavailable',
            $previous
        );
    }
}
