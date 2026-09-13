<?php

declare(strict_types=1);

final class DatabaseAdapterPluginController
{
    public function __construct(
        private readonly DatabaseAdapterCoordinator $coordinator,
        private readonly string $adapterId,
        private readonly PDO $pdo
    ) {
    }

    /** @param array<string, mixed> $user */
    public function handle(string $action, string $method, array $user): void
    {
        $base = 'plugin-database-' . ($this->adapterId === 'postgresql' ? 'postgresql' : 'mysql') . '-adapter';
        if (!str_starts_with($action, $base . '-')) {
            return;
        }
        requireRole($user, ['admin']);
        if ($action === $base . '-status' && $method === 'GET') {
            jsonResponse(['status' => $this->coordinator->status($this->adapterId)]);
        }
        if ($action === $base . '-setup' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $config = [
                'host' => trim((string) ($body['host'] ?? '')),
                'port' => $body['port'] ?? null,
                'database' => trim((string) ($body['database'] ?? '')),
            ];
            if ($this->adapterId === 'postgresql') {
                $config['sslmode'] = trim((string) ($body['sslmode'] ?? 'prefer'));
                if (array_key_exists('require_pgvector', $body)) {
                    $config['require_pgvector'] = $body['require_pgvector'];
                }
            }
            $copyCanonical = true;
            if ($this->adapterId === 'postgresql' && array_key_exists('copy_canonical', $body)) {
                if (!is_bool($body['copy_canonical'])) {
                    jsonResponse(['error' => 'The canonical copy selection must be a boolean.'], 422);
                }
                $copyCanonical = $body['copy_canonical'];
            }
            $username = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            if ($username === '' || $password === '' || strlen($username) > 128 || strlen($password) > 4096) {
                jsonResponse(['error' => 'Database user ID and password are required.'], 422);
            }
            $expectedFingerprint = sessionPasswordFingerprint();
            if ($expectedFingerprint === null) {
                jsonResponse(['error' => 'Administrator authentication must be refreshed.'], 401);
            }
            $operationContext = [
                'owner_user_id' => (int) ($user['id'] ?? 0),
                'owner_session_hash' => hash('sha256', session_id()),
            ];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $result = $this->coordinator->setup(
                    $this->adapterId,
                    $config,
                    $username,
                    $password,
                    function () use ($user, $expectedFingerprint): void {
                        beginPageWriteTransaction($this->pdo);
                        try {
                            $admin = requireLockedApplicationAdminForWrite($this->pdo, $user, $expectedFingerprint);
                            audit($this->pdo, (int) $admin['id'], 'database_adapter_migration_started', 'database_adapter', 0, [
                                'adapter' => $this->adapterId,
                                'source_backend' => (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                            ]);
                            $this->pdo->commit();
                        } catch (Throwable $exception) {
                            if ($this->pdo->inTransaction()) {
                                $this->pdo->rollBack();
                            }
                            throw $exception;
                        }
                    },
                    $operationContext,
                    $copyCanonical
                );
                jsonResponse(['ok' => true, 'result' => $result]);
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $failureCode = $exception instanceof DatabaseAdapterException
                    ? $exception->failureCode()
                    : 'adapter_error';
                $message = $exception instanceof DatabaseAdapterException
                    ? $exception->getMessage()
                    : 'Database adapter setup failed.';
                $status = match ($failureCode) {
                    'maintenance', 'workspace_lock_unavailable',
                    'database_drain_timeout' => 503,
                    'migration_cancelled', 'adapter_installation_changed' => 409,
                    'destination_not_empty', 'invalid_source_backend',
                    'endpoint_unchanged', 'canonical_backend_changed',
                    'workspace_prefix_conflict', 'workspace_identity_conflict',
                    'workspace_manifest_mismatch' => 409,
                    'invalid_configuration', 'driver_missing',
                    'authentication_failed',
                    'database_not_found', 'tls_negotiation_failed',
                    'pgvector_package_missing', 'pgvector_permission_denied',
                    'pgvector_activation_failed', 'rag_rebuild_failed' => 422,
                    'dns_resolution_failed', 'tcp_connection_failed', 'connection_failed' => 502,
                    default => 500,
                };
                $payload = ['error' => $message, 'code' => $failureCode];
                if ($exception instanceof DatabaseAdapterException) {
                    $details = $this->publicPgvectorFailureDetails($exception);
                    if ($details !== []) {
                        $payload['details'] = $details;
                    }
                }
                jsonResponse($payload, $status);
            }
        }
        if ($this->adapterId === 'mysql' && $action === $base . '-relocate' && $method === 'POST') {
            requireCsrf();
            $body = bodyJson();
            $host = trim((string) ($body['host'] ?? ''));
            $port = $body['port'] ?? null;
            $username = trim((string) ($body['username'] ?? ''));
            $password = (string) ($body['password'] ?? '');
            if ($host === '' || strlen($host) > 255 || $username === '' || $password === ''
                || strlen($username) > 128 || strlen($password) > 4096) {
                jsonResponse(['error' => 'Host, port, current database user ID, and password are required.'], 422);
            }
            $expectedFingerprint = sessionPasswordFingerprint();
            if ($expectedFingerprint === null) {
                jsonResponse(['error' => 'Administrator authentication must be refreshed.'], 401);
            }
            $operationContext = [
                'owner_user_id' => (int) ($user['id'] ?? 0),
                'owner_session_hash' => hash('sha256', session_id()),
            ];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            try {
                $result = $this->coordinator->relocateMySqlEndpoint(
                    $host,
                    $port,
                    $username,
                    $password,
                    function () use ($user, $expectedFingerprint): void {
                        beginPageWriteTransaction($this->pdo);
                        try {
                            requireLockedApplicationAdminForWrite($this->pdo, $user, $expectedFingerprint);
                            $this->pdo->commit();
                        } catch (Throwable $exception) {
                            if ($this->pdo->inTransaction()) {
                                $this->pdo->rollBack();
                            }
                            throw $exception;
                        }
                    },
                    $operationContext
                );
                jsonResponse(['ok' => true, 'result' => $result]);
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $failureCode = $exception instanceof DatabaseAdapterException
                    ? $exception->failureCode()
                    : 'adapter_error';
                $message = $exception instanceof DatabaseAdapterException
                    ? $exception->getMessage()
                    : 'Database endpoint change failed.';
                $status = match ($failureCode) {
                    'maintenance', 'workspace_lock_unavailable',
                    'database_drain_timeout' => 503,
                    'migration_cancelled', 'adapter_installation_changed' => 409,
                    'endpoint_validation_failed', 'canonical_backend_changed',
                    'workspace_prefix_conflict', 'workspace_identity_conflict',
                    'workspace_manifest_mismatch', 'workspace_identity_missing' => 409,
                    'connection_failed' => 502,
                    'invalid_configuration', 'invalid_source_backend', 'not_active',
                    'endpoint_unchanged', 'credentials_mismatch', 'credentials_unavailable',
                    'driver_missing' => 422,
                    default => 500,
                };
                jsonResponse(['error' => $message, 'code' => $failureCode], $status);
            }
        }
        if ($action === $base . '-health' && $method === 'POST') {
            requireCsrf();
            try {
                jsonResponse(['ok' => true, 'result' => $this->coordinator->healthCheck($this->adapterId)]);
            } catch (DatabaseAdapterException $exception) {
                jsonResponse(['error' => $exception->getMessage(), 'code' => $exception->failureCode()], 503);
            }
        }
        if ($this->adapterId === 'postgresql'
            && $action === $base . '-enable-pgvector'
            && $method === 'POST') {
            requireCsrf();
            try {
                jsonResponse(['ok' => true, 'result' => $this->coordinator->enablePostgreSqlPgvector()]);
            } catch (DatabaseAdapterException $exception) {
                $payload = [
                    'error' => $exception->getMessage(),
                    'code' => $exception->failureCode(),
                ];
                $details = $this->publicPgvectorFailureDetails($exception);
                if ($details !== []) {
                    $payload['details'] = $details;
                }
                $status = in_array($exception->failureCode(), [
                    'pgvector_package_missing',
                    'pgvector_permission_denied',
                    'pgvector_activation_failed',
                    'pgvector_activation_unsupported',
                    'not_active',
                ], true) ? 422 : 500;
                jsonResponse($payload, $status);
            }
        }
        jsonResponse(['error' => 'Database adapter action was not found.'], 404);
    }

    /** @return array<string, bool|string> */
    private function publicPgvectorFailureDetails(DatabaseAdapterException $exception): array
    {
        if (!in_array($exception->failureCode(), [
            'pgvector_package_missing',
            'pgvector_permission_denied',
            'pgvector_activation_failed',
        ], true)) {
            return [];
        }
        $details = $exception->details();
        if (($details['capability'] ?? null) !== 'pgvector') {
            return [];
        }
        $status = (string) ($details['status'] ?? '');
        if (!in_array($status, ['installed', 'available', 'missing'], true)) {
            return [];
        }
        return [
            'capability' => 'pgvector',
            'status' => $status,
            'required' => ($details['required'] ?? false) === true,
            'available' => ($details['available'] ?? false) === true,
            'installed' => ($details['installed'] ?? false) === true,
        ];
    }
}
