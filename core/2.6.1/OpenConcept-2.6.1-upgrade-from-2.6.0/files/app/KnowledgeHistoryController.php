<?php

declare(strict_types=1);

require_once __DIR__ . '/KnowledgePortableArchive.php';
require_once __DIR__ . '/KnowledgeSearchBridge.php';

final class KnowledgeHistoryController
{
    public function __construct(private readonly Database $database, private readonly string $root) {}

    public function handles(string $action): bool { return str_starts_with($action, 'knowledge-'); }

    public function dispatch(string $action, string $method, array $actor, string $fingerprint): never
    {
        $pdo = $this->database->pdo();
        try {
            if ($method === 'POST') { requireCsrf(); }
            if (in_array($action, ['knowledge-import', 'knowledge-annotate'], true) && $method === 'POST') {
                $body = bodyJson(); beginPageWriteTransaction($pdo);
                $user = lockedAuthenticatedApplicationUserForWrite($pdo, $actor, $fingerprint);
                if ($user === null || !empty($user['must_change_password'])) { throw new RuntimeException('Authentication changed.', 401); }
                $records = new KnowledgeRecords($pdo);
                $result = $action === 'knowledge-import'
                    ? $records->import(is_string($body['archive'] ?? null) ? $body['archive'] : '', $user)
                    : $records->annotate((string) ($body['target_id'] ?? ''), (string) ($body['kind'] ?? ''), (string) ($body['text'] ?? ''), $user);
                audit($pdo, (int) $user['id'], $action, 'knowledge', 0, ['record_id' => $result['id'] ?? $result['import_id']]);
                $pdo->commit(); jsonResponse($result);
            }
            if ($action === 'knowledge-export' && $method === 'POST') {
                $user = currentUser($pdo);
                if ($user === null || $user['role'] !== 'admin' || !empty($user['must_change_password'])
                    || !hash_equals(sessionPasswordFingerprint() ?? '', $fingerprint)) { throw new RuntimeException('Administrator authentication is required.', 403); }
                $user['password_fingerprint'] = $fingerprint;
                $directory = $this->database->storagePath() . '/knowledge-exports';
                if (!is_dir($directory) && !mkdir($directory, 0700)) { throw new RuntimeException('Private export storage unavailable.'); }
                $token = bin2hex(random_bytes(32)); $path = $directory . '/' . $token . '.oc.jsonl';
                $service = CanonicalReadSnapshotService::forDatabase($this->database, $this->root,
                    static fn(string $issuer): bool => $issuer === 'knowledge-archive');
                $result = (new KnowledgePortableArchive($service))->create($user, $path);
                unset($result['path']);
                $_SESSION['knowledge_exports'][$token] = ['user_id' => (int) $user['id'], 'expires' => time() + 3600, 'fingerprint' => $fingerprint];
                jsonResponse($result + ['download_url' => 'api.php?action=knowledge-export-download&token=' . $token]);
            }
            if ($action === 'knowledge-ai' && $method === 'POST') {
                $attempts = array_values(array_filter((array) ($_SESSION['ai_search_attempts'] ?? []), static fn($at): bool => is_int($at) && $at > time() - 60));
                if (count($attempts) >= max(2, min(30, (int) (getenv('OPENCONCEPT_AI_SEARCH_RATE_LIMIT') ?: 8)))) {
                    header('Retry-After: 60');
                    global $i18n;
                    jsonResponse(['error' => $i18n->translate('ai.rateLimited', [], uiLocale($actor))], 429);
                }
                $attempts[] = time(); $_SESSION['ai_search_attempts'] = $attempts;
                $body = bodyJson();
                $result = (new AiSearchService($pdo, null, baseUrl()))->searchHistory(
                    (string) ($body['question'] ?? ''), $actor, $fingerprint, (array) ($body['filters'] ?? []));
                $repository = new AiChatRepository($pdo);
                $conversation = $repository->createForUser((int) $actor['id'], $fingerprint);
                $saved = $repository->appendTurn((int) $conversation['id'], (int) $actor['id'], $result, $fingerprint);
                jsonResponse(['result' => $saved['turn']]);
            }
            if ($method !== 'GET') { throw new RuntimeException('Unknown knowledge operation.', 404); }
            $owns = beginConsistentPageRead($pdo);
            $user = authenticatedApplicationUserForReadSnapshot($pdo, $actor, $fingerprint);
            if ($user === null || !empty($user['must_change_password'])) { throw new RuntimeException('Authentication changed.', 401); }
            $service = new KnowledgeHistoryService($pdo, uploadStoragePath());
            if ($action === 'knowledge-search') { $result = $service->search($user, $_GET); }
            elseif ($action === 'knowledge-record') { $result = $service->record((string) ($_GET['record'] ?? ''), $user, true); }
            elseif ($action === 'knowledge-file') {
                $file = $service->file((string) ($_GET['record'] ?? ''), $user);
                if ($owns) { $pdo->commit(); }
                $this->download($file['path'], $file['name']);
            } elseif ($action === 'knowledge-export-download') {
                $token = (string) ($_GET['token'] ?? '');
                $export = $_SESSION['knowledge_exports'][$token] ?? null;
                if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !is_array($export) || $user['role'] !== 'admin'
                    || $export['user_id'] !== (int) $user['id'] || $export['expires'] < time() || !hash_equals($export['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Export unavailable.', 404);
                }
                $file = $this->database->storagePath() . '/knowledge-exports/' . $token . '.oc.jsonl';
                if ($owns) { $pdo->commit(); }
                $this->download($file, 'OpenConcept-knowledge-' . gmdate('Y-m-d') . '.oc.jsonl');
            } else { throw new RuntimeException('Unknown knowledge operation.', 404); }
            if ($owns) { $pdo->commit(); }
            jsonResponse($result);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $status = $error instanceof PluginApiConflict ? 409 : (in_array((int) $error->getCode(), [401, 403, 404, 409, 413, 422], true) ? (int) $error->getCode() : 500);
            if ($status === 500) { error_log('Knowledge history operation failed: ' . $error->getMessage()); }
            jsonResponse(['error' => $status === 500 ? 'Knowledge history could not be processed.' : $error->getMessage(), 'code' => 'knowledge_operation_failed'], $status);
        }
    }

    private function download(string $path, string $name): never
    {
        if (!is_file($path) || is_link($path)) { throw new RuntimeException('Original unavailable.', 404); }
        header('Content-Type: application/octet-stream'); header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Disposition: attachment; filename=\"OpenConcept-original\"; filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . filesize($path));
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        readfile($path); exit;
    }
}
