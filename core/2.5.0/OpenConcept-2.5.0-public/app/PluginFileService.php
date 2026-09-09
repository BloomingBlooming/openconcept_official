<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRepository.php';

/** Core file replacement and source-event journal behind the common plugin API. */
final class PluginFileService
{
    private PluginApiRepository $records;

    /**
     * Services: resolveSource(ref,actor), canViewFile(actor,file), inspectUpload(path,name),
     * dispatchEvent(name,payload). Tests may inject isUploaded/moveUpload; HTTP uses PHP's
     * uploaded-file checks. maxBytes is supplied from the application's upload policy.
     */
    public function __construct(private readonly PDO $pdo, private readonly string $uploadDirectory, private readonly array $services)
    {
        $this->records = new PluginApiRepository($pdo);
    }

    public function replace(array $ref, array $actor, array $upload): array
    {
        if (($ref['source_type'] ?? '') !== 'file' || !ctype_digit((string) ($ref['source_id'] ?? '')) || (int) $ref['source_id'] < 1) {
            throw new InvalidArgumentException('A Core file reference is required.');
        }
        $id = (int) $ref['source_id'];
        $this->authorize($id, $ref, $actor, false);
        [$temporary, $name, $size, $inspected] = $this->validateUpload($upload);
        if (!is_dir($this->uploadDirectory) && !mkdir($this->uploadDirectory, 0770, true) && !is_dir($this->uploadDirectory)) {
            throw new RuntimeException('The original file storage is unavailable.');
        }
        $directory = realpath($this->uploadDirectory);
        if (!is_string($directory)) { throw new RuntimeException('The original file storage is unavailable.'); }
        $stored = bin2hex(random_bytes(24)) . '.' . $inspected['extension'];
        $destination = $directory . DIRECTORY_SEPARATOR . $stored;
        $move = $this->services['moveUpload'] ?? 'move_uploaded_file';
        if (!$move($temporary, $destination)) { throw new RuntimeException('The replacement file could not be stored.'); }
        try {
            $result = $this->records->transaction(function () use ($id, $ref, $actor, $name, $size, $inspected, $stored): array {
                [$user, $old, $source] = $this->authorize($id, $ref, $actor, true);
                // Preserve the original bytes and a durable locator for the superseded
                // version. Original-file retention is independent of extraction state.
                $oldKey = PluginApiRepository::sourceKey($source);
                if ($this->records->get('core:sources', 'file-versions', $oldKey) === null) {
                    $this->records->put('core:sources', 'file-versions', $oldKey, [
                        'source' => $source, 'file' => $old, 'replaced_by' => (int) $user['id'], 'replaced_at' => time(),
                    ], 0);
                }
                $update = $this->pdo->prepare('UPDATE files SET original_name = ?, stored_name = ?, mime_type = ?, category = ?, size_bytes = ? WHERE id = ? AND stored_name = ?');
                $update->execute([$name, $stored, $inspected['mime'], $inspected['category'], $size, $id, $old['stored_name']]);
                if ($update->rowCount() !== 1) { throw new PluginApiConflict('The original file changed before replacement.'); }
                // Re-resolve after the write. The same file ID can retain its page link,
                // but the immutable upload name and byte hash create a new source version.
                $next = ($this->services['resolveSource'])($ref, $user);
                if (!is_array($next) || PluginApiRepository::sourceKey($next) === $oldKey) { throw new RuntimeException('The new original version could not be verified.'); }
                $events = [$this->journalEvent('source.replaced', $next, ['previous_source' => $source, 'actor_user_id' => (int) $user['id']])];
                if (!empty($next['current_published'])) {
                    $key = PluginApiRepository::sourceKey($next);
                    if ($this->records->get('core:sources', 'publication', $key) === null) {
                        $this->records->put('core:sources', 'publication', $key, $next + ['published_at' => time()], 0);
                    }
                    $events[] = $this->journalEvent('source.published', $next, [], $key);
                }
                $this->audit($user, $id, $source, $next);
                return ['source' => $next, 'events' => $events];
            });
        } catch (Throwable $error) {
            // Keep the blob on an ambiguous commit failure. A harmless orphan is
            // preferable to deleting bytes which a committed canonical row references.
            try {
                $current = $this->pdo->prepare('SELECT stored_name FROM files WHERE id = ?'); $current->execute([$id]);
                if ($current->fetchColumn() !== $stored) { @unlink($destination); }
            } catch (Throwable) {}
            throw $error;
        }
        $this->dispatchEvents($result['events']);
        return $result['source'];
    }

    /** Call inside the owner's canonical write transaction for deletion/ACL changes. */
    public function journalEvent(string $name, array $ref, array $details = [], ?string $dedupeKey = null): array
    {
        if (!in_array($name, ['source.created', 'source.published', 'source.unpublished', 'source.replaced', 'source.deleted', 'source.access-changed'], true)) {
            throw new InvalidArgumentException('Unknown Core source lifecycle event.');
        }
        $payload = $ref + $details;
        $key = hash('sha256', $dedupeKey ?? PluginApiRepository::sourceKey($ref) . "\0" . PluginApiRepository::encode($details));
        $created = $this->records->event($name, $key, $payload);
        return ['name' => $name, 'payload' => $payload, 'created' => $created];
    }

    public function fileSource(int $id, ?array $actor = null): ?array
    {
        return ($this->services['resolveSource'])(['source_type' => 'file', 'source_id' => (string) $id], $actor);
    }

    /** Journal the final publication/access state in the page owner's write transaction. */
    public function journalPageSources(array $pageIds, array $details = [], bool $accessChanged = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) { return []; }
        $query = $this->pdo->prepare('SELECT id FROM files WHERE page_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY id');
        $query->execute($ids); $fileIds = $query->fetchAll(PDO::FETCH_COLUMN); $events = [];
        foreach ($fileIds as $fileId) {
            $source = $this->fileSource((int) $fileId);
            if ($source === null) { continue; }
            if ($accessChanged) { $events[] = $this->journalEvent('source.access-changed', $source, $details); }
            if (!empty($source['current_published'])) {
                $key = PluginApiRepository::sourceKey($source);
                if ($this->records->get('core:sources', 'publication', $key) === null) {
                    $this->records->put('core:sources', 'publication', $key, $source + ['published_at' => time()], 0);
                }
                $events[] = $this->journalEvent('source.published', $source, [], $key);
            } else {
                $events[] = $this->journalEvent('source.unpublished', $source, $details);
            }
        }
        return $events;
    }

    /** Run only after commit; subscribers must enqueue work rather than parse originals. */
    public function dispatchEvents(array $events): void
    {
        foreach ($events as $event) {
            if (empty($event['created']) || !is_callable($this->services['dispatchEvent'] ?? null)) { continue; }
            try { ($this->services['dispatchEvent'])($event['name'], $event['payload']); } catch (Throwable) { /* Durable journal supports subsequent recovery. */ }
        }
    }

    private function authorize(int $id, array $ref, array $actor, bool $lock): array
    {
        $suffix = $lock && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite' ? ' FOR UPDATE' : '';
        $userQuery = $this->pdo->prepare("SELECT * FROM users WHERE id = ? AND active = 1 AND role NOT IN ('system', 'suspended')" . $suffix);
        $userQuery->execute([(int) ($actor['id'] ?? 0)]); $user = $userQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user) || !empty($user['must_change_password'])) { throw new RuntimeException('Authentication is required.', 401); }
        $fingerprint = $actor['password_fingerprint'] ?? (isset($actor['password_hash']) ? hash('sha256', (string) $actor['password_hash']) : null);
        if (is_string($fingerprint) && !hash_equals(hash('sha256', $user['password_hash']), $fingerprint)) { throw new RuntimeException('Authentication changed.', 401); }
        // Match every existing file writer: actor -> page hierarchy -> file.
        $beforeQuery = $this->pdo->prepare('SELECT page_id FROM files WHERE id = ?'); $beforeQuery->execute([$id]); $before = $beforeQuery->fetch(PDO::FETCH_ASSOC);
        $pageId = is_array($before) ? (int) ($before['page_id'] ?? 0) : 0;
        if ($lock && $pageId > 0) {
            if (is_callable($this->services['lockHierarchy'] ?? null)) {
                $hierarchy = ($this->services['lockHierarchy'])($pageId);
                if (!is_array($hierarchy) || ($hierarchy['reason'] ?? null) !== null || !isset($hierarchy['rows'][$pageId])) { throw new PluginApiConflict('The source page hierarchy changed.'); }
            } else {
                $page = $this->pdo->prepare('SELECT id FROM pages WHERE id = ?' . $suffix); $page->execute([$pageId]); $page->fetchColumn();
            }
        }
        $query = $this->pdo->prepare('SELECT * FROM files WHERE id = ?' . $suffix); $query->execute([$id]); $file = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) { throw new RuntimeException('The original file is unavailable.', 404); }
        if ((int) ($file['page_id'] ?? 0) !== $pageId) { throw new PluginApiConflict('The source page changed.'); }
        if (($user['role'] !== 'admin' && (int) $file['uploaded_by'] !== (int) $user['id'])
            || !($this->services['canViewFile'])($user, $file)) {
            throw new RuntimeException('Only the administrator or original uploader with current access may replace this file.', 403);
        }
        $source = ($this->services['resolveSource'])($ref, $user);
        if (!is_array($source) || !hash_equals(PluginApiRepository::sourceKey($source), PluginApiRepository::sourceKey($ref))) {
            throw new PluginApiConflict('The original file version changed.');
        }
        return [$user, $file, $source];
    }

    private function validateUpload(array $upload): array
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('A successful multipart upload is required.'); }
        $temporary = (string) ($upload['tmp_name'] ?? '');
        $isUploaded = $this->services['isUploaded'] ?? 'is_uploaded_file';
        if (!$isUploaded($temporary)) { throw new InvalidArgumentException('A valid uploaded file is required.'); }
        $size = filesize($temporary);
        $limit = max(1, (int) ($this->services['maxBytes'] ?? 52428800));
        if (!is_int($size) || $size < 1 || $size > $limit || $size !== (int) ($upload['size'] ?? 0)) { throw new RuntimeException('The replacement exceeds the upload size limit or its size changed.', 413); }
        $name = basename(str_replace('\\', '/', (string) ($upload['name'] ?? '')));
        $name = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $name = mb_substr(trim($name), 0, 255);
        if ($name === '') { throw new InvalidArgumentException('A filename is required.'); }
        $inspected = ($this->services['inspectUpload'])($temporary, $name);
        if (!is_array($inspected) || preg_match('/^[a-z0-9]{1,10}$/D', (string) ($inspected['extension'] ?? '')) !== 1
            || !is_string($inspected['mime'] ?? null) || !is_string($inspected['category'] ?? null)) { throw new InvalidArgumentException('This replacement file format is unsupported.'); }
        return [$temporary, $name, $size, $inspected];
    }

    private function audit(array $actor, int $id, array $before, array $after): void
    {
        $statement = $this->pdo->prepare('INSERT INTO audit_logs (user_id, action, subject_type, subject_id, detail_json) VALUES (?, ?, ?, ?, ?)');
        $statement->execute([(int) $actor['id'], 'file_replaced', 'file', $id, PluginApiRepository::encode(['previous_version' => $before['source_version'], 'source_version' => $after['source_version']])]);
    }
}
