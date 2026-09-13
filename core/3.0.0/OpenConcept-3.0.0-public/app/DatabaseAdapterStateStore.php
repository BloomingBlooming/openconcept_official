<?php

declare(strict_types=1);

/**
 * Stores canonical-backend state outside any database so a failed external
 * backend can never make OpenConcept silently select an older copy.
 *
 * Every update creates an immutable generation. A torn write is ignored and
 * the previous valid generation remains recoverable.
 */
final class DatabaseAdapterStateStore
{
    private const FORMAT_VERSION = 1;

    private string $directory;

    public function __construct(string $storagePath)
    {
        $this->directory = rtrim($storagePath, "/\\") . DIRECTORY_SEPARATOR . '.database-adapters';
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        $default = [
            'format_version' => self::FORMAT_VERSION,
            'generation' => 0,
            'canonical_backend' => 'sqlite',
            'adapters' => [],
            'updated_at' => null,
        ];
        if (!is_dir($this->directory)) {
            return $default;
        }
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . 'state-*.json') ?: [];
        rsort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            try {
                $state = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($state)
                && ($state['format_version'] ?? null) === self::FORMAT_VERSION
                && is_int($state['generation'] ?? null)
                && is_string($state['canonical_backend'] ?? null)
                && is_array($state['adapters'] ?? null)) {
                return $state;
            }
        }
        return $default;
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutator @return array<string, mixed> */
    public function update(callable $mutator): array
    {
        $current = $this->load();
        $next = $mutator($current);
        if (!is_array($next)) {
            throw new LogicException('Database adapter state mutator must return an array.');
        }
        $next['format_version'] = self::FORMAT_VERSION;
        $next['generation'] = ((int) ($current['generation'] ?? 0)) + 1;
        $next['updated_at'] = gmdate('c');
        $this->writeGeneration($next);
        return $next;
    }

    /** @param array<string, mixed> $config @param array{username: string, password: string} $credentials */
    public function activate(string $adapterId, array $config, array $credentials, array $diagnostics, string $sourceBackend): array
    {
        $sealed = $this->seal(json_encode($credentials, JSON_THROW_ON_ERROR));
        return $this->update(static function (array $state) use ($adapterId, $config, $sealed, $diagnostics, $sourceBackend): array {
            if ($sourceBackend !== $adapterId) {
                $sourceState = is_array($state['adapters'][$sourceBackend] ?? null)
                    ? $state['adapters'][$sourceBackend]
                    : [];
                $state['adapters'][$sourceBackend] = array_merge($sourceState, [
                    'lifecycle' => 'DISABLED',
                    'replaced_by' => $adapterId,
                    'deactivated_at' => gmdate('c'),
                    'last_checked_at' => gmdate('c'),
                ]);
            }
            $state['canonical_backend'] = $adapterId;
            $activeAdapter = [
                'lifecycle' => 'ACTIVE',
                'source_backend' => $sourceBackend,
                'config' => $config,
                'credential' => $sealed,
                'diagnostics' => $diagnostics,
                'activated_at' => gmdate('c'),
                'last_checked_at' => gmdate('c'),
                'last_error_code' => null,
            ];
            $state['adapters'][$adapterId] = $activeAdapter;
            return $state;
        });
    }

    /** @param array<string, mixed> $details */
    public function setLifecycle(string $adapterId, string $lifecycle, array $details = []): array
    {
        $allowed = ['INSTALLED', 'CONFIGURING', 'CHECKING', 'MIGRATING', 'VALIDATING', 'ACTIVE', 'ERROR', 'DISABLED'];
        if (!in_array($lifecycle, $allowed, true)) {
            throw new InvalidArgumentException('Unknown database adapter lifecycle.');
        }
        return $this->update(static function (array $state) use ($adapterId, $lifecycle, $details): array {
            $existing = is_array($state['adapters'][$adapterId] ?? null) ? $state['adapters'][$adapterId] : [];
            unset($details['credential'], $details['credentials'], $details['password'], $details['username']);
            $state['adapters'][$adapterId] = array_merge($existing, $details, [
                'lifecycle' => $lifecycle,
                'last_checked_at' => gmdate('c'),
            ]);
            return $state;
        });
    }

    /**
     * Stages a replacement for an ACTIVE adapter without changing the
     * canonical endpoint used by the next request. This is required for a
     * PostgreSQL-to-PostgreSQL move: the source and destination share one
     * adapter ID, so writing candidate settings into the active adapter would
     * make a failed attempt replace the still-healthy source configuration.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function setReplacementLifecycle(string $adapterId, string $lifecycle, array $details = []): array
    {
        $allowed = ['CONFIGURING', 'CHECKING', 'MIGRATING', 'VALIDATING', 'ERROR'];
        if (!in_array($lifecycle, $allowed, true)) {
            throw new InvalidArgumentException('Unknown database adapter replacement lifecycle.');
        }
        return $this->update(static function (array $state) use ($adapterId, $lifecycle, $details): array {
            unset($details['credential'], $details['credentials'], $details['password'], $details['username']);
            $adapter = is_array($state['adapters'][$adapterId] ?? null) ? $state['adapters'][$adapterId] : [];
            $replacement = is_array($adapter['replacement'] ?? null) ? $adapter['replacement'] : [];
            $adapter['replacement'] = array_merge($replacement, $details, [
                'lifecycle' => $lifecycle,
                'last_checked_at' => gmdate('c'),
            ]);
            $state['adapters'][$adapterId] = $adapter;
            return $state;
        });
    }

    /**
     * Records a failed same-adapter replacement while leaving the ACTIVE
     * source lifecycle, configuration, and credential envelope untouched.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function recordReplacementFailure(
        string $adapterId,
        string $operation,
        string $failureCode,
        string $message,
        array $details = []
    ): array {
        $operation = $this->logToken($operation, 'database_operation');
        $failureCode = $this->logToken($failureCode, 'adapter_error');
        $message = $this->logMessage($message);
        return $this->update(static function (array $state) use (
            $adapterId,
            $operation,
            $failureCode,
            $message,
            $details
        ): array {
            unset($details['credential'], $details['credentials'], $details['password'], $details['username']);
            $adapter = is_array($state['adapters'][$adapterId] ?? null) ? $state['adapters'][$adapterId] : [];
            $replacement = is_array($adapter['replacement'] ?? null) ? $adapter['replacement'] : [];
            $entry = [
                'timestamp' => gmdate('c'),
                'operation' => $operation,
                'code' => $failureCode,
                'message' => $message,
            ];
            $log = is_array($replacement['error_log'] ?? null)
                ? array_values($replacement['error_log'])
                : [];
            $log[] = $entry;
            $replacement = array_merge($replacement, $details, [
                'lifecycle' => 'ERROR',
                'last_error_code' => $failureCode,
                'last_error' => $entry,
                'error_log' => array_slice($log, -20),
                'last_checked_at' => gmdate('c'),
            ]);
            $adapter['replacement'] = $replacement;
            $state['adapters'][$adapterId] = $adapter;
            return $state;
        });
    }

    /**
     * Persist a credential-free operational error for the administrator UI.
     * A failed endpoint candidate does not make the current ACTIVE database
     * unhealthy, so callers decide whether the lifecycle should enter ERROR.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public function recordFailure(
        string $adapterId,
        string $operation,
        string $failureCode,
        string $message,
        bool $markLifecycleError,
        array $details = []
    ): array {
        $operation = $this->logToken($operation, 'database_operation');
        $failureCode = $this->logToken($failureCode, 'adapter_error');
        $message = $this->logMessage($message);
        return $this->update(static function (array $state) use (
            $adapterId,
            $operation,
            $failureCode,
            $message,
            $markLifecycleError,
            $details
        ): array {
            unset($details['credential'], $details['credentials'], $details['password'], $details['username'], $details['config']);
            $existing = is_array($state['adapters'][$adapterId] ?? null) ? $state['adapters'][$adapterId] : [];
            $entry = [
                'timestamp' => gmdate('c'),
                'operation' => $operation,
                'code' => $failureCode,
                'message' => $message,
            ];
            $log = is_array($existing['error_log'] ?? null) ? array_values($existing['error_log']) : [];
            $log[] = $entry;
            $log = array_slice($log, -20);
            $state['adapters'][$adapterId] = array_merge($existing, $details, [
                'lifecycle' => $markLifecycleError ? 'ERROR' : (string) ($existing['lifecycle'] ?? 'INSTALLED'),
                'last_error_code' => $failureCode,
                'last_error' => $entry,
                'error_log' => $log,
                'last_checked_at' => gmdate('c'),
            ]);
            return $state;
        });
    }

    /** @return array{username: string, password: string} */
    public function credentials(string $adapterId): array
    {
        $state = $this->load();
        $sealed = $state['adapters'][$adapterId]['credential'] ?? null;
        if (!is_array($sealed)) {
            throw new DatabaseUnavailableException(
                $adapterId,
                'Database credentials are not available.',
                null,
                'credential_state_invalid'
            );
        }
        try {
            $value = json_decode($this->unseal($sealed), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new DatabaseUnavailableException(
                $adapterId,
                'Database credentials could not be opened.',
                $exception,
                'credential_state_invalid'
            );
        }
        if (!is_array($value) || !is_string($value['username'] ?? null) || !is_string($value['password'] ?? null)) {
            throw new DatabaseUnavailableException(
                $adapterId,
                'Database credentials are invalid.',
                null,
                'credential_state_invalid'
            );
        }
        return ['username' => $value['username'], 'password' => $value['password']];
    }

    /**
     * Return this installation's immutable workspace identity and the short,
     * application-assigned table prefix derived from it. Existing managed
     * adapters may supply their legacy prefix once during ownership adoption.
     *
     * @return array{workspace_id: string, table_prefix: string, created_at: string, legacy_prefix: bool}
     */
    public function workspaceIdentity(?string $existingPrefix = null): array
    {
        $normalizedExistingPrefix = $existingPrefix === null
            ? null
            : $this->validWorkspacePrefix($existingPrefix);
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'workspace-identity.json';
        if (is_file($path)) {
            $identity = $this->readWorkspaceIdentity($path);
            if ($normalizedExistingPrefix !== null && $identity['table_prefix'] !== $normalizedExistingPrefix) {
                throw new DatabaseAdapterException(
                    'The stored workspace identity does not match the active table prefix.',
                    'workspace_identity_conflict'
                );
            }
            return $identity;
        }

        $workspaceId = bin2hex(random_bytes(16));
        $identity = [
            'workspace_id' => $workspaceId,
            'table_prefix' => $normalizedExistingPrefix
                ?? ('oc_' . substr(hash('sha256', $workspaceId), 0, 20) . '_'),
            'created_at' => gmdate('c'),
            'legacy_prefix' => $normalizedExistingPrefix !== null,
        ];
        $json = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $handle = @fopen($path, 'xb');
        if (is_resource($handle)) {
            try {
                if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new RuntimeException('Could not persist the workspace identity.');
                }
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            @chmod($path, 0600);
            return $identity;
        }
        if (is_file($path)) {
            $identity = $this->readWorkspaceIdentity($path);
            if ($normalizedExistingPrefix !== null && $identity['table_prefix'] !== $normalizedExistingPrefix) {
                throw new DatabaseAdapterException(
                    'The concurrently created workspace identity has a different table prefix.',
                    'workspace_identity_conflict'
                );
            }
            return $identity;
        }
        throw new RuntimeException('Could not create the workspace identity.');
    }

    /**
     * Changes only the network endpoint of an ACTIVE adapter while preserving
     * its database name, table prefix, credentials, and canonical generation.
     *
     * @param array<string, mixed> $expectedConfig
     * @param array<string, mixed> $newConfig
     * @param array<string, mixed> $diagnostics
     * @param array<string, mixed> $verification
     * @return array<string, mixed>
     */
    public function relocateEndpoint(
        string $adapterId,
        int $expectedGeneration,
        array $expectedConfig,
        array $newConfig,
        array $diagnostics,
        array $verification
    ): array {
        return $this->update(static function (array $state) use (
            $adapterId,
            $expectedGeneration,
            $expectedConfig,
            $newConfig,
            $diagnostics,
            $verification
        ): array {
            $adapter = $state['adapters'][$adapterId] ?? null;
            if (($state['generation'] ?? null) !== $expectedGeneration
                || ($state['canonical_backend'] ?? null) !== $adapterId
                || !is_array($adapter)
                || ($adapter['lifecycle'] ?? null) !== 'ACTIVE'
                || ($adapter['config'] ?? null) !== $expectedConfig) {
                throw new DatabaseAdapterException(
                    'The canonical database configuration changed during endpoint verification.',
                    'canonical_backend_changed'
                );
            }
            foreach ($expectedConfig as $key => $value) {
                if (in_array($key, ['host', 'port'], true)) {
                    continue;
                }
                if (!array_key_exists($key, $newConfig) || $newConfig[$key] !== $value) {
                    throw new DatabaseAdapterException(
                        'Only the database host and port may be changed.',
                        'invalid_configuration'
                    );
                }
            }
            if (!isset($adapter['credential'])) {
                throw new DatabaseAdapterException(
                    'Stored database credentials are unavailable.',
                    'credentials_unavailable'
                );
            }
            $state['adapters'][$adapterId] = array_merge($adapter, [
                'lifecycle' => 'ACTIVE',
                'config' => $newConfig,
                'diagnostics' => $diagnostics,
                'last_error_code' => null,
                'last_error' => null,
                'last_checked_at' => gmdate('c'),
                'endpoint_relocated_at' => gmdate('c'),
                'relocation' => [
                    'status' => 'completed',
                    'from' => [
                        'host' => (string) ($expectedConfig['host'] ?? ''),
                        'port' => (int) ($expectedConfig['port'] ?? 0),
                    ],
                    'to' => [
                        'host' => (string) ($newConfig['host'] ?? ''),
                        'port' => (int) ($newConfig['port'] ?? 0),
                    ],
                    'verification' => $verification,
                    'completed_at' => gmdate('c'),
                ],
            ]);
            return $state;
        });
    }

    /** @return array<string, mixed> */
    public function publicStatus(): array
    {
        $state = $this->load();
        $canonicalBackend = (string) ($state['canonical_backend'] ?? 'sqlite');
        foreach ($state['adapters'] as $adapterId => &$adapter) {
            if (!is_array($adapter)) {
                $adapter = [];
                continue;
            }
            // This marker can only become true after activate() has committed
            // PostgreSQL as canonical and recorded MySQL's successor. Failed
            // diagnosis or migration attempts never write replaced_by.
            $adapter['retired_after_migration'] = $adapterId === 'mysql'
                && $canonicalBackend === 'postgresql'
                && ($adapter['replaced_by'] ?? null) === 'postgresql';
            unset(
                $adapter['credential'],
                $adapter['credentials'],
                $adapter['password'],
                $adapter['username'],
                $adapter['config'],
                $adapter['relocation'],
                $adapter['workspace_ownership'],
                $adapter['workspace_deletion'],
                $adapter['deployment_target'],
                $adapter['deployment_target_selected_at'],
                $adapter['replacement']
            );
        }
        unset($adapter);
        return $state;
    }

    /**
     * Returns the exact local artifacts required to reopen the current
     * adapter state. Secrets remain file-backed and are never returned as
     * values.
     *
     * @return array<string, string>
     */
    public function backupInventory(string $backend): array
    {
        $state = $this->load();
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . 'state-*.json') ?: [];
        if ($paths === [] && ($state['canonical_backend'] ?? 'sqlite') === 'sqlite') {
            $state = $this->update(static fn (array $current): array => $current);
            $paths = glob($this->directory . DIRECTORY_SEPARATOR . 'state-*.json') ?: [];
        }
        rsort($paths, SORT_STRING);
        $statePath = null;
        foreach ($paths as $path) {
            try {
                $candidate = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            if (is_array($candidate)
                && ($candidate['generation'] ?? null) === ($state['generation'] ?? null)
                && ($candidate['canonical_backend'] ?? null) === ($state['canonical_backend'] ?? null)) {
                $statePath = $path;
                break;
            }
        }
        if (!is_string($statePath)) {
            throw new RuntimeException('The active database adapter state generation is unavailable.');
        }
        $inventory = ['adapter_state' => $statePath];
        $identity = $this->directory . DIRECTORY_SEPARATOR . 'workspace-identity.json';
        if (is_file($identity)) {
            $inventory['workspace_identity'] = $identity;
        }
        if ($backend !== 'sqlite') {
            $key = $this->directory . DIRECTORY_SEPARATOR . 'credential.key';
            if (!is_file($key) || filesize($key) !== 32) {
                throw new RuntimeException('The database credential.key is unavailable or invalid.');
            }
            $inventory['credential_key'] = $key;
        }
        return $inventory;
    }

    private function logToken(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        return preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) === 1 ? $value : $fallback;
    }

    private function logMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($message)) ?? '';
        $message = preg_replace('/\b(?:mysql|pgsql|postgres(?:ql)?):[^\s]+/iu', '[database connection redacted]', $message) ?? $message;
        $message = preg_replace('/\b(password|passwd|pwd|secret|token)\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $message) ?? $message;
        if ($message === '') {
            $message = 'The database operation failed.';
        }
        return function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500);
    }

    /** @return array{workspace_id: string, table_prefix: string, created_at: string, legacy_prefix: bool} */
    private function readWorkspaceIdentity(string $path): array
    {
        $raw = file_get_contents($path);
        try {
            $identity = is_string($raw) ? json_decode($raw, true, 16, JSON_THROW_ON_ERROR) : null;
        } catch (Throwable $exception) {
            throw new RuntimeException('The workspace identity is invalid.', 0, $exception);
        }
        if (!is_array($identity)
            || preg_match('/^[a-f0-9]{32}$/D', (string) ($identity['workspace_id'] ?? '')) !== 1
            || !is_string($identity['created_at'] ?? null)
            || !is_bool($identity['legacy_prefix'] ?? null)) {
            throw new RuntimeException('The workspace identity is invalid.');
        }
        $identity['table_prefix'] = $this->validWorkspacePrefix((string) ($identity['table_prefix'] ?? ''));
        return [
            'workspace_id' => (string) $identity['workspace_id'],
            'table_prefix' => $identity['table_prefix'],
            'created_at' => (string) $identity['created_at'],
            'legacy_prefix' => (bool) $identity['legacy_prefix'],
        ];
    }

    private function validWorkspacePrefix(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        if (preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new DatabaseAdapterException('The workspace table prefix is invalid.', 'invalid_configuration');
        }
        return $prefix;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create the database adapter state directory.');
        }
        @chmod($this->directory, 0700);
    }

    /** @param array<string, mixed> $state */
    private function writeGeneration(array $state): void
    {
        $this->ensureDirectory();
        $generation = (int) $state['generation'];
        $path = $this->directory . DIRECTORY_SEPARATOR
            . sprintf('state-%020d-%s.json', $generation, bin2hex(random_bytes(6)));
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create a database adapter state generation.');
        }
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Could not persist database adapter state.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /** @return array{algorithm: string, nonce: string, tag: string, ciphertext: string} */
    private function seal(string $plaintext): array
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to store database credentials safely.');
        }
        $key = $this->key();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, 'OpenConcept database adapter v1');
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Could not encrypt database credentials.');
        }
        return [
            'algorithm' => 'aes-256-gcm',
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /** @param array<string, mixed> $sealed */
    private function unseal(array $sealed): string
    {
        if (($sealed['algorithm'] ?? '') !== 'aes-256-gcm') {
            throw new RuntimeException('Unsupported credential envelope.');
        }
        $nonce = base64_decode((string) ($sealed['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($sealed['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($sealed['ciphertext'] ?? ''), true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            throw new RuntimeException('Credential envelope is invalid.');
        }
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            'OpenConcept database adapter v1'
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('Credential authentication failed.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        $this->ensureDirectory();
        $path = $this->directory . DIRECTORY_SEPARATOR . 'credential.key';
        if (is_file($path)) {
            $key = file_get_contents($path);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
            throw new RuntimeException('Database credential key is invalid.');
        }
        $key = random_bytes(32);
        $handle = @fopen($path, 'xb');
        if (is_resource($handle)) {
            try {
                if (fwrite($handle, $key) !== 32 || !fflush($handle)) {
                    throw new RuntimeException('Could not persist database credential key.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($path, 0600);
            return $key;
        }
        $existing = file_get_contents($path);
        if (!is_string($existing) || strlen($existing) !== 32) {
            throw new RuntimeException('Could not create database credential key.');
        }
        return $existing;
    }
}
