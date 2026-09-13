<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/**
 * Trusted original owners participate while the existing application writer
 * barrier and read-only DB snapshot are held. They must use that same barrier
 * for every original mutation and must not make network requests here.
 * No connection, SQL executor, credentials, or writable destination is passed.
 */
interface CanonicalSnapshotProviderInterface
{
    public function id(): string;

    /**
     * {version:string, consistency:'application_writer_barrier_v1',
     *  schema:array<string,array{columns:list<array{name:string}>,primary_key:list<string>}>}
     */
    public function describe(): array;

    /** @return iterable<array{type:string,data:array<string,mixed>}> */
    public function records(array $context): iterable;

    /** @return iterable<array{id:string,version:string,name:string,mime_type:string,size_bytes:int,sha256:string,reference:array}> */
    public function attachments(array $context): iterable;

    /** @return resource Readable original stream; the service closes it. */
    public function openAttachment(string $id, array $context): mixed;
}

final class CanonicalSnapshotException extends RuntimeException
{
    public function __construct(private readonly string $reason, int $status = 409)
    {
        parent::__construct('Canonical snapshot: ' . $reason, $status);
    }

    public function failureCode(): string
    {
        return $this->reason;
    }
}

/**
 * Canonical content export contract v2, deliberately distinct from DB transfer
 * and environment recovery backups. Only the core constructs this service;
 * plugins receive its bounded operations, never its private Database/PDO.
 */
final class CanonicalReadSnapshotService
{
    public const FORMAT = 'openconcept-canonical-read-snapshot';
    public const VERSION = 2;
    private const CORE_TABLES = [
        'users', 'pages', 'page_access_departments', 'page_access_members',
        'page_public_shares', 'page_public_share_pages', 'revisions', 'comments', 'files',
        'ai_conversations', 'ai_chat_turns',
    ];
    private const OMITTED_COLUMNS = [
        'users' => ['password_hash', 'must_change_password'],
        'pages' => ['plain_text', 'tags_json'],
    ];
    private const MAX_READ_BYTES = 1048576;
    private const MAX_RECORD_BYTES = 8388608;
    private const MAX_SNAPSHOT_BYTES = 2147483648;
    private const MAX_RECORDS = 1000000;
    private const MAX_CAPTURE_SECONDS = 120;

    private string $root;
    private string $uploadRoot;
    private Closure $issuerAuthorized;
    /** @var array<string,CanonicalSnapshotProviderInterface> */
    private array $providers = [];
    private Closure $clock;
    private int $captureBytes = 0;
    private float $captureDeadline = 0;

    /**
     * The callback must recheck the caller namespace's CURRENT enabled state
     * and explicit canonical_snapshot capability, including for core issuers.
     * @param callable(string,string,array):bool $issuerAuthorized
     * @param list<CanonicalSnapshotProviderInterface> $providers
     * @param list<string> $knownPluginOwners Installed plugins with retained data.
     */
    public static function forDatabase(
        Database $database,
        string $applicationRoot,
        callable $issuerAuthorized,
        array $providers = [],
        array $knownPluginOwners = []
    ): self {
        $uploadRoot = trim((string) getenv('OPENCONCEPT_UPLOAD_DIR'));
        return new self(
            $database,
            $applicationRoot,
            $uploadRoot !== '' ? $uploadRoot : $database->storagePath() . '/uploads',
            $issuerAuthorized,
            $providers,
            $knownPluginOwners
        );
    }

    /** The explicit clock is for deterministic isolated expiry tests. */
    public function __construct(
        private readonly Database $database,
        private readonly string $applicationRoot,
        string $uploadRoot,
        callable $issuerAuthorized,
        array $providers = [],
        private readonly array $knownPluginOwners = [],
        ?callable $clock = null
    ) {
        $storage = realpath($database->storagePath());
        if (!is_string($storage) || is_link($database->storagePath())) {
            throw new CanonicalSnapshotException('private_storage_unavailable', 503);
        }
        $this->root = $storage . DIRECTORY_SEPARATOR . '.canonical-read-snapshots';
        $this->uploadRoot = rtrim($uploadRoot, "/\\");
        $this->issuerAuthorized = Closure::fromCallable($issuerAuthorized);
        $this->clock = $clock === null ? static fn (): int => time() : Closure::fromCallable($clock);
        require_once __DIR__ . '/KnowledgeSnapshotProvider.php';
        $providers = [new KnowledgeSnapshotProvider($database->pdo(), $this->uploadRoot), ...$providers];
        foreach ($providers as $provider) {
            if (!$provider instanceof CanonicalSnapshotProviderInterface
                || !$this->validOwner($provider->id()) || $provider->id() === 'core'
                || isset($this->providers[$provider->id()])) {
                throw new InvalidArgumentException('Invalid canonical original provider registration.');
            }
            $this->providers[$provider->id()] = $provider;
        }
        foreach (['public', 'published'] as $publicDirectory) {
            $public = realpath($applicationRoot . DIRECTORY_SEPARATOR . $publicDirectory);
            if (is_string($public) && $this->within($storage, $public)) {
                throw new CanonicalSnapshotException('snapshot_storage_not_private', 503);
            }
        }
    }

    public function capabilities(array $actor, string $issuer): array
    {
        $this->authorize($actor, $issuer, 'capabilities');
        $catalog = CoreSchemaCatalog::load();
        $driver = (string) $this->database->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $providerDescriptions = [];
        foreach ($this->providers as $id => $provider) {
            $providerDescriptions[$id] = $this->providerDescription($provider);
        }
        return [
            'format' => self::FORMAT, 'contract_version' => self::VERSION,
            'available' => in_array($driver, ['sqlite', 'mysql', 'pgsql'], true),
            'driver' => $driver, 'schema_version' => $catalog->schemaVersion(),
            'schema_artifact_hash' => $catalog->artifactHash(),
            'tables' => $this->coreSchema($catalog, $driver, self::CORE_TABLES),
            'providers' => $providerDescriptions, 'excluded' => $this->exclusions(self::CORE_TABLES, ['knowledge-history'], true),
            'original_file_version_scope' => 'current_and_retained_versions',
            'scopes' => ['all_core', 'selected_tables'], 'attachments_optional' => true, 'providers_require_explicit_selection' => false,
            'default_providers' => ['knowledge-history'],
            'limits' => ['records_per_read' => 250, 'attachment_bytes_per_read' => self::MAX_READ_BYTES,
                'snapshot_bytes' => self::MAX_SNAPSHOT_BYTES, 'snapshot_records' => self::MAX_RECORDS,
                'capture_seconds' => self::MAX_CAPTURE_SECONDS, 'ttl_seconds' => [60, 3600]],
            'consistency' => 'application_writer_barrier_with_readonly_transaction_and_private_copies',
            'environment_recovery' => false, 'restore_supported' => false,
        ];
    }

    public function begin(array $actor, string $issuer, array $options = []): array
    {
        $user = $this->authorize($actor, $issuer, 'begin');
        if (array_diff(array_keys($options), ['tables', 'providers', 'include_attachments', 'ttl_seconds']) !== []) {
            throw new CanonicalSnapshotException('invalid_scope', 422);
        }
        $tables = $this->selection($options['tables'] ?? self::CORE_TABLES, self::CORE_TABLES);
        $defaultOwners = $tables === self::CORE_TABLES ? ['knowledge-history'] : [];
        $owners = $this->selection($options['providers'] ?? $defaultOwners, array_keys($this->providers));
        $includeAttachments = $options['include_attachments'] ?? true;
        $ttl = $options['ttl_seconds'] ?? 900;
        if (!is_bool($includeAttachments) || !is_int($ttl) || $ttl < 60 || $ttl > 3600 || ($tables === [] && $owners === [])) {
            throw new CanonicalSnapshotException('invalid_scope', 422);
        }
        $this->ensureRoot();
        $this->purgeExpired();
        $id = bin2hex(random_bytes(24));
        $stage = $this->root . DIRECTORY_SEPARATOR . $id;
        if (!mkdir($stage, 0700) || !mkdir($stage . '/attachments', 0700)) {
            throw new CanonicalSnapshotException('snapshot_storage_unavailable', 503);
        }
        $locked = false;
        $transaction = false;
        $queryOnly = null;
        $pdo = $this->database->pdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->captureBytes = 0;
        $this->captureDeadline = microtime(true) + self::MAX_CAPTURE_SECONDS;
        $records = null;
        try {
            if ($pdo->inTransaction()) {
                throw new CanonicalSnapshotException('transaction_already_active');
            }
            $this->database->beginAdapterCutover(['operation' => 'canonical_read_snapshot', 'owner_user_id' => (int) $user['id']]);
            $locked = true;
            $this->database->updateAdapterCutoverStage('canonical_read_snapshot', ['snapshot_id' => $id], true);
            $user = $this->authorize($actor, $issuer, 'begin');
            $identity = $this->identity();
            $catalog = CoreSchemaCatalog::load();
            $schema = $this->coreSchema($catalog, $driver, $tables);
            $providerVersions = [];
            $supplemental = [];
            foreach ($owners as $owner) {
                $description = $this->providerDescription($this->providers[$owner]);
                $schema[$owner] = $description['schema'];
                $providerVersions[$owner] = $description['version'];
                if (($description['classification'] ?? 'canonical_originals') !== 'canonical_originals') {
                    $supplemental[$owner] = $description['classification'];
                }
            }
            if ($driver === 'sqlite') {
                $queryOnly = (int) $pdo->query('PRAGMA query_only')->fetchColumn();
                $pdo->exec('PRAGMA query_only = ON');
                $pdo->beginTransaction();
            } elseif ($driver === 'mysql') {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
            } elseif ($driver === 'pgsql') {
                $pdo->exec('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            } else {
                throw new CanonicalSnapshotException('snapshot_driver_unsupported', 503);
            }
            $transaction = true;
            $records = $this->createPrivate($stage . '/records.jsonl');
            $hash = hash_init('sha256');
            $count = 0;
            $counts = [];
            $attachments = [];
            foreach ($tables as $table) {
                $countKey = 'core:' . $table;
                $counts[$countKey] = 0;
                foreach ($this->coreRecords($table, $schema['core'][$table]) as $row) {
                    $this->writeRecord($records, $hash, ['owner' => 'core', 'type' => $table, 'data' => $row], $count);
                    $counts[$countKey]++;
                    if ($includeAttachments && $table === 'files') {
                        $this->captureCoreFile($stage, $row, $attachments);
                    }
                    if ($includeAttachments && $table === 'users' && ($row['avatar_kind'] ?? '') === 'photo' && ($row['avatar_value'] ?? '') !== '') {
                        $this->captureAvatar($stage, $row, $attachments);
                    }
                }
            }
            $context = ['snapshot_id' => $id, 'contract_version' => self::VERSION, 'actor_id' => (int) $user['id'],
                'created_at' => gmdate('c', ($this->clock)()), 'consistency' => 'application_writer_barrier_v1'];
            foreach ($owners as $owner) {
                $provider = $this->providers[$owner];
                foreach ($schema[$owner] as $type => $_) {
                    $counts[$owner . ':' . $type] = 0;
                }
                foreach ($provider->records($context) as $record) {
                    $type = $record['type'] ?? null;
                    if (!is_string($type) || !isset($schema[$owner][$type]) || !is_array($record['data'] ?? null)) {
                        throw new CanonicalSnapshotException('provider_record_invalid');
                    }
                    $expected = array_column($schema[$owner][$type]['columns'], 'name');
                    $actual = array_keys($record['data']);
                    if (array_diff($actual, $expected) !== [] || array_diff($expected, $actual) !== []) {
                        throw new CanonicalSnapshotException('provider_record_schema_mismatch');
                    }
                    $this->writeRecord($records, $hash, ['owner' => $owner, 'type' => $type, 'data' => $record['data']], $count);
                    $counts[$owner . ':' . $type]++;
                }
                if ($includeAttachments) {
                    foreach ($provider->attachments($context) as $attachment) {
                        $this->validateProviderAttachment($attachment);
                        $source = $provider->openAttachment($attachment['id'], $context);
                        $this->captureStream($stage, $owner, $attachment, $source, $attachments);
                    }
                }
            }
            $this->assertBudget();
            if (!fflush($records)) {
                throw new CanonicalSnapshotException('snapshot_write_failed', 503);
            }
            fclose($records);
            $records = null;
            $pdo->rollBack();
            $transaction = false;
            if ($queryOnly !== null) {
                $pdo->exec('PRAGMA query_only = ' . $queryOnly);
                $queryOnly = null;
            }
            if ($identity !== $this->identity()) {
                throw new CanonicalSnapshotException('canonical_generation_changed');
            }
            $this->authorize($actor, $issuer, 'begin');
            $now = ($this->clock)();
            $manifest = [
                'format' => self::FORMAT, 'contract_version' => self::VERSION, 'snapshot_id' => $id,
                'created_at' => $context['created_at'], 'expires_at' => gmdate('c', $now + $ttl),
                'canonical_identity' => $identity, 'schema' => $schema,
                'scope' => ['kind' => $tables === self::CORE_TABLES ? 'all_core' : 'selected_tables',
                    'tables' => $tables, 'providers' => $owners, 'include_attachments' => $includeAttachments],
                'source_of_truth_only' => $supplemental === [], 'supplemental_providers' => $supplemental,
                'original_file_version_scope' => in_array('knowledge-history', $owners, true) ? 'current_and_retained_versions' : 'current_files_table_only',
                'excluded' => $this->exclusions($tables, $owners, $includeAttachments),
                'record_count' => $count, 'counts' => $counts,
                'records' => ['encoding' => 'UTF-8 JSONL', 'size_bytes' => filesize($stage . '/records.jsonl'),
                    'sha256' => hash_final($hash), 'blocks_sha256' => $this->fileBlockHashes($stage . '/records.jsonl')],
                'attachments' => array_values($attachments), 'attachment_count' => count($attachments),
                'consistency_method' => 'application_writer_barrier_with_readonly_transaction_and_private_copies',
                'status' => 'ready', 'complete' => false, 'export_complete' => false, 'restore_tested' => false,
                'environment_recovery' => false,
            ];
            $state = [
                'manifest' => $manifest, 'owner_user_id' => (int) $user['id'], 'issuer' => $issuer,
                'password_fingerprint' => hash('sha256', (string) $user['password_hash']),
                'expires' => $now + $ttl, 'cursor_key' => bin2hex(random_bytes(32)),
                'records_read_bytes' => 0, 'attachments_read_bytes' => [], 'provider_versions' => $providerVersions,
            ];
            $this->writeState($stage, $state);
            $this->database->finishAdapterCutover(false);
            $locked = false;
            return $this->publicManifest($manifest) + ['cursor' => $this->cursor($id, 0, $state)];
        } catch (Throwable $exception) {
            if (is_resource($records)) {
                fclose($records);
            }
            try {
                if ($transaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($queryOnly !== null) {
                    $pdo->exec('PRAGMA query_only = ' . $queryOnly);
                }
            } finally {
                if ($locked) {
                    $this->database->finishAdapterCutover(false);
                }
                $this->removeStage($stage);
            }
            throw $exception;
        }
    }

    public function records(array $actor, string $issuer, string $snapshotId, ?string $cursor = null, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 250) {
            throw new CanonicalSnapshotException('invalid_record_limit', 422);
        }
        return $this->withSnapshot($actor, $issuer, $snapshotId, 'records', function (array &$state, string $stage) use ($snapshotId, $cursor, $limit): array {
            $offset = $cursor === null ? 0 : $this->cursorOffset($cursor, $snapshotId, $state);
            if ($offset > $state['records_read_bytes']) {
                throw new CanonicalSnapshotException('cursor_out_of_sequence', 422);
            }
            $path = $stage . '/records.jsonl';
            // Validate only bounded intersecting blocks on each paginated
            // read. Hashing a multi-GB file for every small page is quadratic.
            $this->verifyRange($path, $state['manifest']['records'], $offset, self::MAX_READ_BYTES + self::MAX_RECORD_BYTES);
            $handle = fopen($path, 'rb');
            if (!is_resource($handle) || fseek($handle, $offset) !== 0) {
                throw new CanonicalSnapshotException('snapshot_read_failed');
            }
            $rows = [];
            $bytes = 0;
            try {
                while (count($rows) < $limit && !feof($handle)) {
                    $line = fgets($handle, self::MAX_RECORD_BYTES + 2);
                    if ($line === false) {
                        break;
                    }
                    if (!str_ends_with($line, "\n")) {
                        throw new CanonicalSnapshotException('snapshot_record_corrupt');
                    }
                    $rows[] = json_decode($line, true, 128, JSON_THROW_ON_ERROR);
                    $bytes += strlen($line);
                    if ($bytes >= self::MAX_READ_BYTES) {
                        break;
                    }
                }
                $next = ftell($handle);
            } finally {
                fclose($handle);
            }
            $state['records_read_bytes'] = max($state['records_read_bytes'], $next);
            $done = $next === (int) $state['manifest']['records']['size_bytes'];
            return ['snapshot_id' => $snapshotId, 'records' => $rows, 'done' => $done,
                'next_cursor' => $done ? null : $this->cursor($snapshotId, $next, $state)];
        });
    }

    /** Bytes are base64 for a bounded JSON-compatible PHP/HTTP contract. */
    public function attachment(array $actor, string $issuer, string $snapshotId, string $attachmentId, int $offset = 0, int $length = self::MAX_READ_BYTES): array
    {
        if ($offset < 0 || $length < 1 || $length > self::MAX_READ_BYTES) {
            throw new CanonicalSnapshotException('invalid_attachment_range', 422);
        }
        return $this->withSnapshot($actor, $issuer, $snapshotId, 'attachment', function (array &$state, string $stage) use ($snapshotId, $attachmentId, $offset, $length): array {
            $entry = null;
            foreach ($state['manifest']['attachments'] as $candidate) {
                if ($candidate['attachment_id'] === $attachmentId) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                throw new CanonicalSnapshotException('attachment_not_in_scope', 404);
            }
            $previous = (int) ($state['attachments_read_bytes'][$attachmentId] ?? 0);
            if ($offset > $previous || $offset > $entry['size_bytes']) {
                throw new CanonicalSnapshotException('attachment_range_out_of_sequence', 422);
            }
            $path = $stage . '/attachments/' . $attachmentId;
            $this->verifyRange($path, $entry, $offset, $length);
            $handle = fopen($path, 'rb');
            if (!is_resource($handle)) {
                throw new CanonicalSnapshotException('snapshot_read_failed');
            }
            try {
                if (fseek($handle, $offset) !== 0) {
                    throw new CanonicalSnapshotException('snapshot_read_failed');
                }
                $bytes = stream_get_contents($handle, min($length, $entry['size_bytes'] - $offset));
                if (!is_string($bytes)) {
                    throw new CanonicalSnapshotException('snapshot_read_failed');
                }
            } finally {
                fclose($handle);
            }
            $next = $offset + strlen($bytes);
            $state['attachments_read_bytes'][$attachmentId] = max($previous, $next);
            return ['snapshot_id' => $snapshotId, 'attachment' => $entry, 'offset' => $offset,
                'data_base64' => base64_encode($bytes), 'next_offset' => $next, 'done' => $next === $entry['size_bytes']];
        });
    }

    public function manifest(array $actor, string $issuer, string $snapshotId): array
    {
        return $this->withSnapshot($actor, $issuer, $snapshotId, 'manifest', fn (array &$state): array => $this->publicManifest($state['manifest']));
    }

    /** Verify capture and complete retrieval; plugin output/restore remain separate. */
    public function finish(array $actor, string $issuer, string $snapshotId): array
    {
        return $this->withSnapshot($actor, $issuer, $snapshotId, 'finish', function (array &$state, string $stage): array {
            if ($state['records_read_bytes'] !== (int) $state['manifest']['records']['size_bytes']) {
                throw new CanonicalSnapshotException('records_not_fully_read');
            }
            $this->verifyFile($stage . '/records.jsonl', $state['manifest']['records']);
            foreach ($state['manifest']['attachments'] as $attachment) {
                if ((int) ($state['attachments_read_bytes'][$attachment['attachment_id']] ?? -1) !== $attachment['size_bytes']) {
                    throw new CanonicalSnapshotException('attachments_not_fully_read');
                }
                $this->verifyFile($stage . '/attachments/' . $attachment['attachment_id'], $attachment);
            }
            $state['manifest']['status'] = 'verified';
            $state['manifest']['complete'] = true;
            $state['manifest']['verified_at'] = gmdate('c', ($this->clock)());
            return $this->publicManifest($state['manifest']);
        });
    }

    public function close(array $actor, string $issuer, string $snapshotId): array
    {
        $result = $this->withSnapshot($actor, $issuer, $snapshotId, 'close', static function (array &$state): array {
            $state['closed'] = true;
            return ['snapshot_id' => $state['manifest']['snapshot_id'], 'status' => 'closed'];
        });
        $this->removeStage($this->root . DIRECTORY_SEPARATOR . $snapshotId);
        return $result;
    }

    private function authorize(array $actor, string $issuer, string $operation): array
    {
        if ((int) ($actor['id'] ?? 0) < 1 || !$this->validOwner($issuer)) {
            throw new CanonicalSnapshotException('snapshot_forbidden', 403);
        }
        $statement = $this->database->pdo()->prepare("SELECT id, role, active, password_hash, must_change_password FROM users WHERE id = ?");
        $statement->execute([(int) $actor['id']]);
        $user = $statement->fetch(PDO::FETCH_ASSOC);
        $fingerprint = $actor['password_fingerprint'] ?? (isset($actor['password_hash']) && is_string($actor['password_hash'])
            ? hash('sha256', $actor['password_hash']) : null);
        if (!is_array($user) || $user['role'] !== 'admin' || (int) $user['active'] !== 1 || (int) $user['must_change_password'] !== 0
            || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || !hash_equals(hash('sha256', (string) $user['password_hash']), $fingerprint)
            || ($this->issuerAuthorized)($issuer, $operation, $actor) !== true) {
            throw new CanonicalSnapshotException('snapshot_forbidden', 403);
        }
        return $user;
    }

    private function identity(): array
    {
        $this->database->verifyCanonicalSchema();
        $catalog = CoreSchemaCatalog::load();
        $state = $this->database->adapterStateStore()->load();
        return ['backend' => $this->database->backendId(), 'adapter_generation' => (int) ($state['generation'] ?? 0),
            'connection_fingerprint' => hash('sha256', $this->json($this->database->connectionInfo())),
            'schema_version' => $catalog->schemaVersion(), 'schema_artifact_hash' => $catalog->artifactHash()];
    }

    private function withSnapshot(array $actor, string $issuer, string $id, string $operation, callable $callback): array
    {
        $user = $this->authorize($actor, $issuer, $operation);
        if (preg_match('/\A[a-f0-9]{48}\z/D', $id) !== 1) {
            throw new CanonicalSnapshotException('snapshot_not_found', 404);
        }
        $stage = $this->root . DIRECTORY_SEPARATOR . $id;
        $resolved = realpath($stage);
        if (!is_string($resolved) || is_link($stage) || !$this->within($resolved, $this->root)) {
            throw new CanonicalSnapshotException('snapshot_not_found', 404);
        }
        $lockPath = $stage . '/lease.lock';
        if (is_link($lockPath)) {
            throw new CanonicalSnapshotException('snapshot_integrity_failed');
        }
        $lock = fopen($lockPath, 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) {
            throw new CanonicalSnapshotException('snapshot_busy');
        }
        @chmod($lockPath, 0600);
        $expired = false;
        try {
            $state = $this->readState($stage);
            if ((int) $state['owner_user_id'] !== (int) $user['id'] || $state['issuer'] !== $issuer
                || !hash_equals($state['password_fingerprint'], hash('sha256', (string) $user['password_hash']))) {
                throw new CanonicalSnapshotException('snapshot_forbidden', 403);
            }
            if (($state['closed'] ?? false) === true) {
                throw new CanonicalSnapshotException('snapshot_closed', 410);
            }
            if ($state['expires'] <= ($this->clock)()) {
                $expired = true;
                throw new CanonicalSnapshotException('snapshot_expired', 410);
            }
            if ($operation !== 'close' && $state['manifest']['canonical_identity'] !== $this->identity()) {
                throw new CanonicalSnapshotException('canonical_generation_changed');
            }
            foreach ($operation === 'close' ? [] : $state['provider_versions'] as $owner => $version) {
                if (!isset($this->providers[$owner]) || $this->providerDescription($this->providers[$owner])['version'] !== $version) {
                    throw new CanonicalSnapshotException('provider_changed');
                }
            }
            $result = $callback($state, $stage);
            $finalUser = $this->authorize($actor, $issuer, $operation);
            if (!hash_equals($state['password_fingerprint'], hash('sha256', (string) $finalUser['password_hash']))) {
                throw new CanonicalSnapshotException('snapshot_forbidden', 403);
            }
            $this->writeState($stage, $state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            if ($expired) {
                $this->removeStage($stage);
            }
        }
    }

    private function coreSchema(CoreSchemaCatalog $catalog, string $driver, array $tables): array
    {
        $schema = [];
        foreach ($tables as $table) {
            $definition = $catalog->table($driver, $table);
            $omitted = self::OMITTED_COLUMNS[$table] ?? [];
            $definition['columns'] = array_values(array_filter($definition['columns'], static fn (array $column): bool => !in_array($column['name'], $omitted, true)));
            // Preserve declared PK, FK, CHECK and UNIQUE semantics. Search
            // acceleration indexes are not necessary to describe a restore.
            $definition['indexes'] = array_values(array_filter($definition['indexes'], static fn (array $index): bool => ($index['unique'] ?? false) === true));
            $definition['omitted_columns'] = $omitted;
            $schema[$table] = $definition;
        }
        return ['core' => $schema];
    }

    /** Bounded DB pages inside the one transaction; never accept caller SQL. */
    private function coreRecords(string $table, array $schema): iterable
    {
        $driver = (string) $this->database->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $quote = $driver === 'mysql' ? '`' : '"';
        $columns = implode(', ', array_map(static fn (array $column): string => $quote . $column['name'] . $quote, $schema['columns']));
        $order = implode(', ', array_map(static fn (string $column): string => $quote . $column . $quote, $schema['primary_key']));
        if ($order === '') {
            throw new CanonicalSnapshotException('stable_record_order_unavailable');
        }
        for ($offset = 0; ; $offset += 250) {
            $statement = $this->database->pdo()->query('SELECT ' . $columns . ' FROM ' . $quote . $table . $quote . ' ORDER BY ' . $order . ' LIMIT 250 OFFSET ' . $offset);
            $seen = 0;
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $seen++;
                yield $row;
            }
            $statement->closeCursor();
            if ($seen < 250) {
                break;
            }
        }
    }

    private function writeRecord(mixed $handle, mixed $hash, array $record, int &$count): void
    {
        $this->assertBudget();
        $line = $this->json($record) . "\n";
        if (strlen($line) > self::MAX_RECORD_BYTES || ++$count > self::MAX_RECORDS) {
            throw new CanonicalSnapshotException('snapshot_record_limit_exceeded', 413);
        }
        $this->captureBytes += strlen($line);
        $this->assertBudget();
        $this->writeAll($handle, $line);
        hash_update($hash, $line);
    }

    private function captureCoreFile(string $stage, array $row, array &$attachments): void
    {
        $descriptor = ['id' => 'file:' . $row['id'], 'version' => (string) $row['stored_name'], 'name' => (string) $row['original_name'],
            'mime_type' => (string) $row['mime_type'], 'size_bytes' => (int) $row['size_bytes'],
            'reference' => ['table' => 'files', 'id' => $row['id'], 'page_id' => $row['page_id']]];
        $source = $this->openOriginal((string) $row['stored_name']);
        $this->captureStream($stage, 'core', $descriptor, $source, $attachments);
    }

    private function captureAvatar(string $stage, array $row, array &$attachments): void
    {
        $name = (string) $row['avatar_value'];
        $source = $this->openOriginal($name);
        $stat = fstat($source);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'][$extension] ?? 'application/octet-stream';
        $descriptor = ['id' => 'avatar:' . $row['id'], 'version' => $name, 'name' => $name,
            'mime_type' => $mime, 'size_bytes' => (int) $stat['size'], 'reference' => ['table' => 'users', 'id' => $row['id'], 'column' => 'avatar_value']];
        $this->captureStream($stage, 'core', $descriptor, $source, $attachments);
    }

    private function openOriginal(string $name): mixed
    {
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new CanonicalSnapshotException('original_path_invalid');
        }
        $root = realpath($this->uploadRoot);
        $path = $this->uploadRoot . DIRECTORY_SEPARATOR . $name;
        $resolved = realpath($path);
        if (!is_string($root) || !is_string($resolved) || is_link($path) || !$this->within($resolved, $root) || !is_file($resolved)) {
            throw new CanonicalSnapshotException('original_missing');
        }
        $source = fopen($resolved, 'rb');
        if (!is_resource($source)) {
            throw new CanonicalSnapshotException('original_unreadable');
        }
        return $source;
    }

    private function captureStream(string $stage, string $owner, array $descriptor, mixed $source, array &$attachments): void
    {
        if (!is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new CanonicalSnapshotException('original_stream_invalid');
        }
        $attachmentId = hash('sha256', $owner . ':' . $descriptor['id']);
        $destination = null;
        try {
            if (isset($attachments[$attachmentId])) {
                throw new CanonicalSnapshotException('duplicate_original_reference');
            }
            $destination = $this->createPrivate($stage . '/attachments/' . $attachmentId);
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($source)) {
                $this->assertBudget();
                $bytes = fread($source, self::MAX_READ_BYTES);
                if ($bytes === false || ($bytes === '' && !feof($source))) {
                    throw new CanonicalSnapshotException('original_read_failed');
                }
                $size += strlen($bytes);
                $this->captureBytes += strlen($bytes);
                $this->assertBudget();
                $this->writeAll($destination, $bytes);
                hash_update($hash, $bytes);
            }
            $sha256 = hash_final($hash);
            if ($size !== $descriptor['size_bytes'] || (isset($descriptor['sha256']) && !hash_equals($descriptor['sha256'], $sha256))) {
                throw new CanonicalSnapshotException('original_integrity_failed');
            }
            if (!fflush($destination)) {
                throw new CanonicalSnapshotException('snapshot_write_failed', 503);
            }
            $attachments[$attachmentId] = ['attachment_id' => $attachmentId, 'owner' => $owner,
                'id' => $descriptor['id'], 'version' => $descriptor['version'], 'name' => $descriptor['name'],
                'mime_type' => $descriptor['mime_type'], 'size_bytes' => $size,
                'reference' => $descriptor['reference'], 'sha256' => $sha256,
                'blocks_sha256' => $this->fileBlockHashes($stage . '/attachments/' . $attachmentId)];
        } finally {
            fclose($source);
            if (is_resource($destination)) {
                fclose($destination);
            }
        }
    }

    private function providerDescription(CanonicalSnapshotProviderInterface $provider): array
    {
        $description = $provider->describe();
        if (($description['consistency'] ?? '') !== 'application_writer_barrier_v1'
            || !is_string($description['version'] ?? null) || $description['version'] === ''
            || !is_array($description['schema'] ?? null) || $description['schema'] === []) {
            throw new CanonicalSnapshotException('provider_snapshot_unsupported', 503);
        }
        foreach ($description['schema'] as $name => $schema) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $name) !== 1
                || !is_array($schema['columns'] ?? null) || !is_array($schema['primary_key'] ?? null) || $schema['primary_key'] === []) {
                throw new CanonicalSnapshotException('provider_schema_invalid');
            }
            $columns = [];
            foreach ($schema['columns'] as $column) {
                if (!is_array($column) || !is_string($column['name'] ?? null)
                    || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $column['name']) !== 1 || isset($columns[$column['name']])) {
                    throw new CanonicalSnapshotException('provider_schema_invalid');
                }
                $columns[$column['name']] = true;
            }
            if (array_diff($schema['primary_key'], array_keys($columns)) !== []) {
                throw new CanonicalSnapshotException('provider_schema_invalid');
            }
        }
        return $description;
    }

    private function validateProviderAttachment(mixed $attachment): void
    {
        if (!is_array($attachment) || !is_string($attachment['id'] ?? null) || $attachment['id'] === ''
            || !is_string($attachment['version'] ?? null) || $attachment['version'] === ''
            || !is_string($attachment['name'] ?? null) || !is_string($attachment['mime_type'] ?? null)
            || !is_int($attachment['size_bytes'] ?? null) || $attachment['size_bytes'] < 0
            || !is_string($attachment['sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $attachment['sha256']) !== 1
            || !is_array($attachment['reference'] ?? null)) {
            throw new CanonicalSnapshotException('provider_attachment_invalid');
        }
    }

    private function selection(mixed $selected, array $allowed): array
    {
        if (!is_array($selected) || !array_is_list($selected)) {
            throw new CanonicalSnapshotException('invalid_scope', 422);
        }
        foreach ($selected as $name) {
            if (!is_string($name) || !in_array($name, $allowed, true)) {
                throw new CanonicalSnapshotException('invalid_scope', 422);
            }
        }
        return array_values(array_intersect($allowed, $selected));
    }

    private function exclusions(array $tables, array $providers, bool $attachments): array
    {
        $categories = ['rag_snapshots_chunks_embeddings_indexes_sync', 'automatic_summaries_and_search_metadata',
            'unaccepted_ocr_candidates_and_plugin_workflow_state', 'database_and_llm_credentials', 'sessions_environment_settings',
            'notifications_and_operational_audit', 'generated_public_site_artifacts'];
        if (!in_array('knowledge-history', $providers, true)) {
            $categories[] = 'knowledge_records_voice_history_and_retained_original_versions';
        }
        $acceptedSupplement = false;
        foreach ($providers as $provider) {
            if (($this->providerDescription($this->providers[$provider])['classification'] ?? '') === 'accepted_extraction_supplement') {
                $acceptedSupplement = true;
            }
        }
        if (!$acceptedSupplement) {
            $categories[] = 'accepted_ocr_llm_extraction_and_review_provenance';
        }
        return [
            'core_tables' => array_values(array_diff(self::CORE_TABLES, $tables)),
            'columns' => self::OMITTED_COLUMNS,
            'categories' => $categories,
            'core_records' => in_array('knowledge-history', $providers, true) ? [] : [['table' => 'extension_records', 'scope' => 'core:sources', 'collection' => 'file-versions',
                'original_bytes_included' => false]],
            'attachments_omitted' => !$attachments,
            'unregistered_plugin_data' => array_values(array_diff($this->knownPluginOwners, array_keys($this->providers))),
            'unselected_providers' => array_values(array_diff(array_keys($this->providers), $providers)),
            'scope_relations_may_reference_omitted_tables' => $tables !== self::CORE_TABLES,
            'erased_history_is_not_recoverable' => true,
        ];
    }

    private function assertBudget(): void
    {
        if ($this->captureBytes > self::MAX_SNAPSHOT_BYTES || microtime(true) > $this->captureDeadline) {
            throw new CanonicalSnapshotException('snapshot_capacity_exceeded', 413);
        }
        $this->database->throwIfAdapterCutoverCancelled();
    }

    private function publicManifest(array $manifest): array
    {
        return $manifest + ['manifest_sha256' => hash('sha256', $this->json($manifest))];
    }

    private function cursor(string $id, int $offset, array $state): string
    {
        $payload = $id . ':' . $offset;
        return $offset . '.' . hash_hmac('sha256', $payload, $state['cursor_key']);
    }

    private function cursorOffset(string $cursor, string $id, array $state): int
    {
        if (preg_match('/\A(0|[1-9][0-9]{0,12})\.([a-f0-9]{64})\z/D', $cursor, $match) !== 1
            || !hash_equals($match[2], hash_hmac('sha256', $id . ':' . $match[1], $state['cursor_key']))) {
            throw new CanonicalSnapshotException('invalid_snapshot_cursor', 422);
        }
        return (int) $match[1];
    }

    private function verifyFile(string $path, array $expected): void
    {
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || filesize($path) !== (int) $expected['size_bytes']
            || !hash_equals($expected['sha256'], (string) hash_file('sha256', $path))) {
            throw new CanonicalSnapshotException('snapshot_integrity_failed');
        }
    }

    private function fileBlockHashes(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new CanonicalSnapshotException('snapshot_read_failed');
        }
        $hashes = [];
        try {
            while (!feof($handle)) {
                $this->assertBudget();
                $bytes = stream_get_contents($handle, self::MAX_READ_BYTES);
                if (!is_string($bytes) || ($bytes === '' && !feof($handle))) {
                    throw new CanonicalSnapshotException('snapshot_read_failed');
                }
                if ($bytes !== '') {
                    $hashes[] = hash('sha256', $bytes);
                }
            }
        } finally {
            fclose($handle);
        }
        return $hashes;
    }

    private function verifyRange(string $path, array $expected, int $offset, int $length): void
    {
        clearstatcache(true, $path);
        $size = (int) $expected['size_bytes'];
        if (!is_file($path) || is_link($path) || filesize($path) !== $size || $offset > $size) {
            throw new CanonicalSnapshotException('snapshot_integrity_failed');
        }
        if ($offset === $size) {
            return;
        }
        $first = intdiv($offset, self::MAX_READ_BYTES);
        $last = intdiv(min($size, $offset + $length) - 1, self::MAX_READ_BYTES);
        $handle = fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new CanonicalSnapshotException('snapshot_read_failed');
        }
        try {
            if (fseek($handle, $first * self::MAX_READ_BYTES) !== 0) {
                throw new CanonicalSnapshotException('snapshot_read_failed');
            }
            for ($block = $first; $block <= $last; $block++) {
                $bytes = stream_get_contents($handle, self::MAX_READ_BYTES);
                if (!is_string($bytes) || !isset($expected['blocks_sha256'][$block])
                    || !hash_equals($expected['blocks_sha256'][$block], hash('sha256', $bytes))) {
                    throw new CanonicalSnapshotException('snapshot_integrity_failed');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureRoot(): void
    {
        if (is_link($this->root) || (!is_dir($this->root) && !mkdir($this->root, 0700))) {
            throw new CanonicalSnapshotException('private_storage_unavailable', 503);
        }
        @chmod($this->root, 0700);
    }

    private function writeState(string $stage, array $state): void
    {
        $temporary = $stage . '/state-' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = $this->createPrivate($temporary);
        try {
            $this->writeAll($handle, $this->json($state));
            if (!fflush($handle)) {
                throw new CanonicalSnapshotException('snapshot_write_failed', 503);
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $stage . '/state.json')) {
            @unlink($temporary);
            throw new CanonicalSnapshotException('snapshot_write_failed', 503);
        }
    }

    private function readState(string $stage): array
    {
        $path = $stage . '/state.json';
        if (!is_file($path) || is_link($path)) {
            throw new CanonicalSnapshotException('snapshot_incomplete');
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            throw new CanonicalSnapshotException('snapshot_read_failed');
        }
        $state = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['manifest']['format'] ?? '') !== self::FORMAT || !isset($state['owner_user_id'], $state['issuer'], $state['expires'])) {
            throw new CanonicalSnapshotException('snapshot_integrity_failed');
        }
        return $state;
    }

    private function createPrivate(string $path): mixed
    {
        $handle = fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new CanonicalSnapshotException('snapshot_write_failed', 503);
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function writeAll(mixed $handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                throw new CanonicalSnapshotException('snapshot_write_failed', 503);
            }
            $offset += $written;
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function validOwner(string $owner): bool
    {
        return preg_match('/\A[a-z][a-z0-9]*(?:[-.:][a-z0-9]+)*\z/D', $owner) === 1 && strlen($owner) <= 128;
    }

    private function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function removeStage(string $stage): void
    {
        $resolved = realpath($stage);
        if (!is_string($resolved)) {
            return;
        }
        if (is_link($stage) || !$this->within($resolved, $this->root) || dirname($resolved) !== $this->root
            || preg_match('/\A[a-f0-9]{48}\z/D', basename($resolved)) !== 1) {
            throw new CanonicalSnapshotException('snapshot_cleanup_path_invalid');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new CanonicalSnapshotException('snapshot_cleanup_failed', 503);
                }
            } elseif (!unlink($item->getPathname())) {
                throw new CanonicalSnapshotException('snapshot_cleanup_failed', 503);
            }
        }
        if (!rmdir($resolved)) {
            throw new CanonicalSnapshotException('snapshot_cleanup_failed', 503);
        }
    }

    private function purgeExpired(): void
    {
        $checked = 0;
        foreach (new DirectoryIterator($this->root) as $entry) {
            if ($checked++ >= 30) {
                break;
            }
            if (!$entry->isDir() || $entry->isLink() || preg_match('/\A[a-f0-9]{48}\z/D', $entry->getFilename()) !== 1) {
                continue;
            }
            $stage = $entry->getPathname();
            $statePath = $stage . '/state.json';
            $lease = $stage . '/lease.lock';
            if (is_link($statePath) || is_link($lease)) {
                continue;
            }
            $lock = fopen($lease, 'c+b');
            if (!is_resource($lock)) {
                continue;
            }
            $remove = false;
            try {
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    if (is_file($statePath)) {
                        $state = $this->readState($stage);
                        $remove = $state['expires'] <= ($this->clock)() || ($state['closed'] ?? false) === true;
                    } else {
                        $remove = $entry->getMTime() < ($this->clock)() - 3600;
                    }
                    flock($lock, LOCK_UN);
                }
            } finally {
                fclose($lock);
            }
            if ($remove) {
                $this->removeStage($stage);
            }
        }
    }
}
