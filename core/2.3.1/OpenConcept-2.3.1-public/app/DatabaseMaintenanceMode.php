<?php

declare(strict_types=1);

final class DatabaseMaintenanceMode
{
    private const INTENT_FORMAT_VERSION = 1;
    private const DEFAULT_DRAIN_TIMEOUT_SECONDS = 15.0;
    private const DRAIN_POLL_MICROSECONDS = 100_000;

    /** @var array<int, array{handle:resource,path:string,exclusive:bool,intent_path?:string,intent_token?:string}> */
    private static array $sharedHandles = [];

    private readonly float $drainTimeoutSeconds;

    public function __construct(
        private readonly string $storagePath,
        ?float $drainTimeoutSeconds = null
    ) {
        if ($drainTimeoutSeconds !== null) {
            $this->drainTimeoutSeconds = max(0.05, min(120.0, $drainTimeoutSeconds));
            return;
        }
        $configured = trim((string) getenv('OPENCONCEPT_DATABASE_DRAIN_TIMEOUT_SECONDS'));
        $this->drainTimeoutSeconds = $configured !== '' && is_numeric($configured)
            ? max(3.0, min(120.0, (float) $configured))
            : self::DEFAULT_DRAIN_TIMEOUT_SECONDS;
    }

    /** @return resource */
    public function acquireShared(bool $nonBlocking = true)
    {
        $handle = fopen($this->lockPath(), 'c+b');
        if (!is_resource($handle)) {
            throw new DatabaseAdapterException('Database migration maintenance is active.', 'maintenance');
        }
        if (is_file($this->intentPath())) {
            // An intent can survive a web-server/PHP crash after every OS lock has
            // been released. Exclusive non-blocking ownership proves that no
            // live source connection or cutover owns the workspace before the
            // stale marker is removed.
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                throw new DatabaseAdapterException('Database migration maintenance is active.', 'maintenance');
            }
            $this->removeStaleIntentWhileExclusive();
        }
        $operation = LOCK_SH | ($nonBlocking ? LOCK_NB : 0);
        if (!flock($handle, $operation)) {
            fclose($handle);
            throw new DatabaseAdapterException('Database migration maintenance is active.', 'maintenance');
        }
        // Close the check/acquire race: an upgrader publishes the intent first,
        // then converts its already-held shared lock. A newcomer that slipped
        // between the first check and flock must surrender immediately.
        if (is_file($this->intentPath())) {
            flock($handle, LOCK_UN);
            fclose($handle);
            throw new DatabaseAdapterException('Database migration maintenance is active.', 'maintenance');
        }
        self::$sharedHandles[get_resource_id($handle)] = [
            'handle' => $handle,
            'path' => $this->lockPath(),
            'exclusive' => false,
        ];
        return $handle;
    }

    /**
     * Convert the Database lifetime lease itself from shared to exclusive.
     * Writing the intent before the blocking conversion prevents later shared
     * acquisitions from extending the drain indefinitely or reading old
     * adapter state while cutover is waiting for an existing request.
     *
     * @param resource $handle
     * @return resource
     */
    public function upgradeSharedToExclusive($handle, array $context = [])
    {
        if (!is_resource($handle)) {
            throw new LogicException('The database maintenance lease is unavailable.');
        }
        $id = get_resource_id($handle);
        $record = self::$sharedHandles[$id] ?? null;
        if (!is_array($record)
            || ($record['handle'] ?? null) !== $handle
            || ($record['path'] ?? null) !== $this->lockPath()
            || ($record['exclusive'] ?? null) !== false) {
            throw new LogicException('The database maintenance lease cannot be upgraded.');
        }
        foreach (self::$sharedHandles as $otherId => $other) {
            if ($otherId !== $id
                && ($other['path'] ?? null) === $this->lockPath()
                && is_resource($other['handle'] ?? null)) {
                throw new DatabaseAdapterException(
                    'Another database connection in this process still owns the source lease.',
                    'maintenance_barrier_unavailable'
                );
            }
        }
        $intentToken = bin2hex(random_bytes(16));
        $intentPath = $this->intentPath();
        $intent = @fopen($intentPath, 'x+b');
        if (!is_resource($intent)) {
            throw new DatabaseAdapterException('Database migration maintenance is active.', 'maintenance');
        }
        $now = gmdate('c');
        $intentRecord = [
            'format_version' => self::INTENT_FORMAT_VERSION,
            'mode' => 'adapter_cutover',
            'token' => $intentToken,
            'stage' => 'waiting_for_database',
            'cancellable' => true,
            'started_at' => $now,
            'updated_at' => $now,
            'pid' => getmypid(),
            'drain_timeout_seconds' => $this->drainTimeoutSeconds,
        ];
        foreach (['adapter', 'operation', 'owner_session_hash', 'owner_user_id'] as $key) {
            if (array_key_exists($key, $context)) {
                $intentRecord[$key] = $context[$key];
            }
        }
        $intentPayload = json_encode($intentRecord, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (fwrite($intent, $intentPayload) === false || !fflush($intent)) {
            fclose($intent);
            @unlink($intentPath);
            throw new RuntimeException('Could not publish database migration maintenance intent.');
        }
        fclose($intent);
        self::$sharedHandles[$id]['intent_path'] = $intentPath;
        self::$sharedHandles[$id]['intent_token'] = $intentToken;

        $deadline = microtime(true) + $this->drainTimeoutSeconds;
        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if ($this->isCancellationRequested($intentToken)) {
                $this->abortPendingUpgrade(
                    $handle,
                    $id,
                    'Database migration was cancelled. SQLite remains canonical.',
                    'migration_cancelled'
                );
            }
            if (microtime(true) >= $deadline) {
                $this->abortPendingUpgrade(
                    $handle,
                    $id,
                    'Other database activity did not finish before the migration wait limit. SQLite remains canonical.',
                    'database_drain_timeout'
                );
            }
            usleep(self::DRAIN_POLL_MICROSECONDS);
        }
        self::$sharedHandles[$id]['exclusive'] = true;
        $this->updateExclusiveStage($handle, 'preparing', [], true);
        return $handle;
    }

    /** @param resource $handle @param array<string, scalar|null> $details */
    public function updateExclusiveStage(
        $handle,
        string $stage,
        array $details = [],
        bool $cancellable = true
    ): void {
        $record = $this->exclusiveRecord($handle);
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $stage) !== 1) {
            throw new InvalidArgumentException('The database migration stage is invalid.');
        }
        $payload = $this->readIntent();
        if (!is_array($payload)
            || !hash_equals((string) $record['intent_token'], (string) ($payload['token'] ?? ''))) {
            throw new RuntimeException('The database migration intent is unavailable.');
        }
        $payload['stage'] = $stage;
        $payload['cancellable'] = $cancellable;
        $payload['updated_at'] = gmdate('c');
        $payload['details'] = $details;
        $this->writeIntent($payload);
    }

    /** @param resource $handle */
    public function throwIfCancellationRequested($handle): void
    {
        $record = $this->exclusiveRecord($handle);
        if ($this->isCancellationRequested((string) $record['intent_token'])) {
            throw new DatabaseAdapterException(
                'Database migration was cancelled. SQLite remains canonical.',
                'migration_cancelled'
            );
        }
    }

    /** @return array<string, mixed> */
    public function operationStatus(?string $sessionHash = null, ?int $userId = null): array
    {
        $payload = $this->readIntent();
        if (!is_array($payload)) {
            return [
                'active' => is_file($this->intentPath()),
                'stage' => is_file($this->intentPath()) ? 'unknown' : null,
                'cancellable' => false,
                'cancel_requested' => false,
                'owned_by_session' => false,
            ];
        }
        $started = strtotime((string) ($payload['started_at'] ?? ''));
        $token = (string) ($payload['token'] ?? '');
        $owned = is_string($sessionHash)
            && preg_match('/^[a-f0-9]{64}$/D', $sessionHash) === 1
            && is_int($userId)
            && $userId > 0
            && hash_equals((string) ($payload['owner_session_hash'] ?? ''), $sessionHash)
            && (int) ($payload['owner_user_id'] ?? 0) === $userId;
        return [
            'active' => true,
            'stage' => (string) ($payload['stage'] ?? 'unknown'),
            'adapter' => (string) ($payload['adapter'] ?? ''),
            'operation' => (string) ($payload['operation'] ?? 'adapter_cutover'),
            'started_at' => (string) ($payload['started_at'] ?? ''),
            'updated_at' => (string) ($payload['updated_at'] ?? ''),
            'elapsed_seconds' => $started === false ? null : max(0, time() - $started),
            'drain_timeout_seconds' => (float) ($payload['drain_timeout_seconds'] ?? $this->drainTimeoutSeconds),
            'details' => is_array($payload['details'] ?? null) ? $payload['details'] : [],
            'cancellable' => ($payload['cancellable'] ?? false) === true,
            'cancel_requested' => $token !== '' && $this->isCancellationRequested($token),
            'owned_by_session' => $owned,
        ];
    }

    /** @return array<string, mixed> */
    public function requestCancellation(string $sessionHash, int $userId): array
    {
        $intentHandle = @fopen($this->intentPath(), 'r+b');
        if (!is_resource($intentHandle)) {
            throw new DatabaseAdapterException('No database migration is active.', 'migration_not_active');
        }
        if (!flock($intentHandle, LOCK_EX)) {
            fclose($intentHandle);
            throw new DatabaseAdapterException(
                'The migration cancellation request could not be synchronized.',
                'migration_cancel_failed'
            );
        }
        try {
            if (!is_file($this->intentPath())
                || fseek($intentHandle, 0) !== 0
                || !is_string($raw = stream_get_contents($intentHandle))) {
                throw new DatabaseAdapterException('No database migration is active.', 'migration_not_active');
            }
            try {
                $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new DatabaseAdapterException(
                    'The database migration state is invalid.',
                    'maintenance',
                    $exception
                );
            }
            if (!is_array($payload)) {
                throw new DatabaseAdapterException('The database migration state is invalid.', 'maintenance');
            }
            if (!hash_equals((string) ($payload['owner_session_hash'] ?? ''), $sessionHash)
                || (int) ($payload['owner_user_id'] ?? 0) !== $userId) {
                throw new DatabaseAdapterException(
                    'Only the administrator session that started this migration can cancel it.',
                    'migration_cancel_forbidden'
                );
            }
            if (($payload['cancellable'] ?? false) !== true) {
                throw new DatabaseAdapterException(
                    'The migration has reached its activation commit point and can no longer be cancelled.',
                    'migration_cannot_cancel'
                );
            }
            $token = (string) ($payload['token'] ?? '');
            if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
                throw new DatabaseAdapterException('The database migration state is invalid.', 'maintenance');
            }
            $path = $this->cancelPath($token);
            if (!is_file($path)) {
                $handle = @fopen($path, 'x+b');
                if (!is_resource($handle)) {
                    throw new DatabaseAdapterException(
                        'The migration cancellation request could not be recorded.',
                        'migration_cancel_failed'
                    );
                }
                $body = json_encode([
                    'token' => $token,
                    'requested_at' => gmdate('c'),
                    'requested_by' => $userId,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $written = fwrite($handle, $body);
                $flushed = fflush($handle);
                fclose($handle);
                if ($written !== strlen($body) || !$flushed) {
                    @unlink($path);
                    throw new DatabaseAdapterException(
                        'The migration cancellation request could not be recorded.',
                        'migration_cancel_failed'
                    );
                }
            }
        } finally {
            flock($intentHandle, LOCK_UN);
            fclose($intentHandle);
        }
        return $this->operationStatus($sessionHash, $userId);
    }

    /**
     * On failure the same connection remains canonical and regains its shared
     * lifetime lease. On successful activation the old Database object is
     * retired and its lease is closed, so every later process must load the
     * newly activated adapter state before opening a connection.
     *
     * @param resource $handle
     */
    public function finishExclusiveUpgrade($handle, bool $activated): void
    {
        if (!is_resource($handle)) {
            return;
        }
        $id = get_resource_id($handle);
        $record = self::$sharedHandles[$id] ?? null;
        if (!is_array($record) || ($record['exclusive'] ?? null) !== true) {
            throw new LogicException('The database maintenance lease is not exclusive.');
        }
        if ($activated) {
            $this->removeOwnedIntent($record);
            unset(self::$sharedHandles[$id]);
            flock($handle, LOCK_UN);
            fclose($handle);
            return;
        }
        if (!flock($handle, LOCK_SH)) {
            $this->removeOwnedIntent($record);
            unset(self::$sharedHandles[$id]);
            fclose($handle);
            throw new RuntimeException('Could not leave database migration maintenance mode safely.');
        }
        self::$sharedHandles[$id]['exclusive'] = false;
        $this->removeOwnedIntent($record);
        unset(self::$sharedHandles[$id]['intent_path'], self::$sharedHandles[$id]['intent_token']);
    }

    /** @param resource $handle */
    public function releaseShared($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        $id = get_resource_id($handle);
        $record = self::$sharedHandles[$id] ?? null;
        if (is_array($record) && ($record['exclusive'] ?? false) === true) {
            $this->removeOwnedIntent($record);
        }
        unset(self::$sharedHandles[$id]);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function isExclusiveInThisProcess(): bool
    {
        foreach (self::$sharedHandles as $record) {
            if (($record['exclusive'] ?? false) === true
                && is_resource($record['handle'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function lockPath(): string
    {
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('Could not create OpenConcept storage directory.');
        }
        return rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . '.database-migration.lock';
    }

    private function intentPath(): string
    {
        return rtrim($this->storagePath, "/\\") . DIRECTORY_SEPARATOR . '.database-migration.intent';
    }

    /** @param array<string, mixed> $record */
    private function removeOwnedIntent(array $record): void
    {
        $path = $record['intent_path'] ?? null;
        $token = $record['intent_token'] ?? null;
        if (!is_string($path) || !is_string($token) || $path !== $this->intentPath()) {
            return;
        }
        $payload = @file_get_contents($path);
        if (!is_string($payload)) {
            return;
        }
        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return;
        }
        if (is_array($decoded) && hash_equals($token, (string) ($decoded['token'] ?? ''))) {
            @unlink($path);
            @unlink($this->cancelPath($token));
        }
    }

    /** @param resource $handle */
    private function abortPendingUpgrade(
        $handle,
        int $id,
        string $message,
        string $failureCode
    ): never {
        if (!flock($handle, LOCK_SH)) {
            $this->removeOwnedIntent(self::$sharedHandles[$id] ?? []);
            unset(self::$sharedHandles[$id]);
            fclose($handle);
            throw new RuntimeException('Could not restore the source database lease after a migration wait failure.');
        }
        $this->removeOwnedIntent(self::$sharedHandles[$id]);
        unset(self::$sharedHandles[$id]['intent_path'], self::$sharedHandles[$id]['intent_token']);
        throw new DatabaseAdapterException($message, $failureCode);
    }

    /** @param resource $handle @return array<string, mixed> */
    private function exclusiveRecord($handle): array
    {
        if (!is_resource($handle)) {
            throw new LogicException('The database maintenance lease is unavailable.');
        }
        $record = self::$sharedHandles[get_resource_id($handle)] ?? null;
        if (!is_array($record)
            || ($record['handle'] ?? null) !== $handle
            || ($record['exclusive'] ?? false) !== true
            || !is_string($record['intent_token'] ?? null)) {
            throw new LogicException('The database maintenance lease is not exclusive.');
        }
        return $record;
    }

    /** @return array<string, mixed>|null */
    private function readIntent(): ?array
    {
        $path = $this->intentPath();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = @file_get_contents($path);
            if (!is_string($raw)) {
                return null;
            }
            try {
                $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                return is_array($payload) ? $payload : null;
            } catch (Throwable) {
                usleep(10_000);
            }
        }
        return null;
    }

    /** @param array<string, mixed> $payload */
    private function writeIntent(array $payload): void
    {
        $path = $this->intentPath();
        $handle = @fopen($path, 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Could not update database migration status.');
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ok = ftruncate($handle, 0)
            && fseek($handle, 0) === 0
            && fwrite($handle, $body) === strlen($body)
            && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$ok) {
            throw new RuntimeException('Could not update database migration status.');
        }
    }

    private function isCancellationRequested(string $token): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return false;
        }
        $raw = @file_get_contents($this->cancelPath($token));
        if (!is_string($raw)) {
            return false;
        }
        try {
            $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }
        return is_array($payload) && hash_equals($token, (string) ($payload['token'] ?? ''));
    }

    private function cancelPath(string $token): string
    {
        return rtrim($this->storagePath, "/\\")
            . DIRECTORY_SEPARATOR . '.database-migration.cancel-' . $token;
    }

    private function removeStaleIntentWhileExclusive(): void
    {
        $payload = $this->readIntent();
        $token = is_array($payload) ? (string) ($payload['token'] ?? '') : '';
        @unlink($this->intentPath());
        if (preg_match('/^[a-f0-9]{32}$/D', $token) === 1) {
            @unlink($this->cancelPath($token));
        }
    }
}
