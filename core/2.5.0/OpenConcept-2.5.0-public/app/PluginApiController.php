<?php

declare(strict_types=1);

require_once __DIR__ . '/NotificationInbox.php';

/** Authenticated HTTP transport; plugins retain responsibility for action-specific roles. */
final class PluginApiController
{
    public function __construct(private readonly PluginApiRuntime $runtime, private readonly PDO $pdo, private readonly I18n $i18n) {}

    public function handles(string $action): bool
    {
        return in_array($action, ['extension-action', 'extension-notification', 'extension-info', 'extension-canonical-read', 'extension-tick', 'extension-file-replace'], true);
    }
    public function dispatch(string $action, string $method, array $actor): never
    {
        try {
            if (!in_array($method, ['GET', 'POST'], true)) { jsonResponse(['error' => 'Method not allowed.'], 405); }
            if ($method === 'POST') { requireCsrf(); }
            $body = $method === 'POST' ? ($action === 'extension-file-replace' ? $_POST : bodyJson()) : $_GET;
            $fingerprint = sessionPasswordFingerprint();
            $user = $this->runtime->user((int) $actor['id']);
            if ($user === null || !empty($user['must_change_password']) || !is_string($fingerprint)
                || !hash_equals($fingerprint, hash('sha256', $user['password_hash']))) {
                jsonResponse(['error' => $this->tr('unavailable', $actor)], 401);
            }
            $user['password_fingerprint'] = $fingerprint;
            unset($user['password_hash']);
            if ($action === 'extension-file-replace' && $method === 'POST') {
                $ref = json_decode((string) ($body['source'] ?? ''), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($ref)) { throw new InvalidArgumentException('A source reference is required.'); }
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                jsonResponse(['ok' => true, 'source' => $this->runtime->files->replace($ref, $user, (array) ($_FILES['file'] ?? []))]);
            }
            if ($action === 'extension-info' && $method === 'GET') { jsonResponse($this->runtime->api->service('appInfo')); }
            if ($action === 'extension-tick' && $method === 'POST') {
                // A login ticket is issued by successful authentication, never by a caller-supplied role.
                $login = $_SESSION['extension_login_ticket'] ?? null;
                unset($_SESSION['extension_login_ticket']);
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                $context = ['user' => $user];
                if (is_array($login) && (int) ($login['user_id'] ?? 0) === (int) $user['id'] && (int) ($login['expires'] ?? 0) >= time()) {
                    if ($user['role'] === 'admin') {
                        $this->runtime->api->trigger('admin.login', $context);
                        $this->runtime->api->runJobs(1, 25, $context + ['trigger' => 'admin.login', 'scopes' => ['core:app-update']]);
                    }
                    $this->runtime->api->trigger('login', $context);
                    $jobs = $this->runtime->api->runJobs(2, 15, $context + ['trigger' => 'login']);
                } else {
                    $this->runtime->api->trigger('page-view', $context);
                    $jobs = $this->runtime->api->runJobs(2, 15, $context + ['trigger' => 'page-view']);
                }
                jsonResponse(['ok' => true, 'jobs' => $jobs]);
            }
            if ($action === 'extension-notification' && $method === 'GET') {
                $id = (int) ($body['id'] ?? 0);
                $ownsSnapshot = beginConsistentPageRead($this->pdo);
                try {
                    $liveUser = authenticatedApplicationUserForReadSnapshot($this->pdo, $user, $fingerprint);
                    if ($liveUser === null || !empty($liveUser['must_change_password'])) { throw new RuntimeException($this->tr('unavailable', $user), 401); }
                    $notification = (new NotificationInbox($this->pdo))->find($id, $liveUser);
                    if ($notification === null) { throw new RuntimeException($this->tr('unavailable', $liveUser), 404); }
                    $metadata = $this->runtime->api->notificationMetadata($id, $liveUser);
                    $response = $metadata === null || empty($metadata['action']) || !$this->runtime->scopeEnabled($metadata['scope'])
                        ? ['modal' => ['title' => $this->tr('notice', $liveUser), 'description' => $this->tr('unavailable', $liveUser), 'blocks' => [['type' => 'text', 'text' => $notification['message']]], 'buttons' => [['id' => 'close', 'label' => $this->tr('close', $liveUser), 'close' => true]]]]
                        : ['action' => ['scope' => $metadata['scope'], 'action' => $metadata['action'], 'payload' => $metadata['target']]];
                    if ($ownsSnapshot) { $this->pdo->commit(); }
                } catch (Throwable $error) {
                    if ($ownsSnapshot && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                    throw $error;
                }
                jsonResponse($response);
            }
            if ($action === 'extension-canonical-read' && $method === 'POST') {
                if ($user['role'] !== 'admin') { jsonResponse(['error' => 'Administrator access is required.'], 403); }
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                jsonResponse($this->runtime->canonicalRead((string) ($body['scope'] ?? ''), (string) ($body['operation'] ?? ''), $user, (array) ($body['arguments'] ?? [])));
            }
            if ($action === 'extension-action') {
                $scope = (string) ($body['scope'] ?? '');
                if (preg_match('/^(?:core:)?[a-z][a-z0-9-]{0,63}$/D', $scope) !== 1) { throw new InvalidArgumentException('Invalid extension scope.'); }
                $payload = $body['payload'] ?? [];
                if (is_string($payload)) { $payload = json_decode($payload, true, 32, JSON_THROW_ON_ERROR); }
                if (!is_array($payload) || strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 1048576) { throw new InvalidArgumentException('Invalid extension action payload.'); }
                if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
                jsonResponse($this->runtime->api->dispatchAction($scope, (string) ($body['extension_action'] ?? ''), $method === 'GET' ? 'view' : 'invoke', $payload, $user));
            }
            jsonResponse(['error' => 'Method not allowed.'], 405);
        } catch (Throwable $error) {
            $status = $error instanceof PluginApiConflict ? 409 : ($error instanceof InvalidArgumentException || $error instanceof JsonException ? 422 : 500);
            if (in_array((int) $error->getCode(), [401, 403, 404, 409, 410, 413, 422, 503], true)) { $status = (int) $error->getCode(); }
            // Provider/credential exception details belong to the plugin's sanitized review data.
            $safe = $status === 500 ? $this->tr('operationFailed', $actor) : (in_array($status, [401, 403], true) ? $this->tr('unavailable', $actor) : $error->getMessage());
            jsonResponse(['error' => $safe, 'code' => $error instanceof CanonicalSnapshotException ? $error->failureCode() : 'extension_operation_failed'], $status);
        }
    }
    private function tr(string $key, array $actor): string { return $this->i18n->translate($key, [], (string) ($actor['ui_locale'] ?? 'ja-JP'), 'plugin-api'); }
}
