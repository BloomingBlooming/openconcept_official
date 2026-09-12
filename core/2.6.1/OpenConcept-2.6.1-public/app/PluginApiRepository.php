<?php

declare(strict_types=1);

final class PluginApiConflict extends RuntimeException {}

/** Durable state in the selected canonical DB; this class never creates tables. */
final class PluginApiRepository
{
    private int $transactionDepth = 0;
    public function __construct(private readonly PDO $pdo, private readonly mixed $clock = null) {}

    public function now(): int { return is_callable($this->clock) ? (int) ($this->clock)() : time(); }

    public function transaction(callable $operation): mixed
    {
        if ($this->transactionDepth > 0 || $this->pdo->inTransaction()) {
            return $operation();
        }
        $sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        $sqlite ? $this->pdo->exec('BEGIN IMMEDIATE') : $this->pdo->beginTransaction();
        $this->transactionDepth++;
        try {
            $result = $operation();
            $sqlite ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            try {
                // MySQL can already have rolled back a deadlocked transaction.
                // Keep the original SQLSTATE/driver code so the owning service
                // can safely retry its complete DB-only operation. SQLite uses
                // SQL BEGIN above, so retain its matching SQL rollback path.
                if ($sqlite) { $this->pdo->exec('ROLLBACK'); }
                elseif ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            } catch (Throwable) { /* Cleanup must not replace the original failure. */ }
            throw $error;
        } finally {
            $this->transactionDepth--;
        }
    }

    public function get(string $scope, string $collection, string $key): ?array
    {
        $row = $this->one('SELECT payload_json, revision FROM extension_records WHERE scope = ? AND collection = ? AND record_key = ?', [$scope, $collection, $key]);
        return $row === null ? null : ['value' => self::decode($row['payload_json']), 'revision' => (int) $row['revision']];
    }

    public function put(string $scope, string $collection, string $key, array $value, ?int $expectedRevision = null): int
    {
        $json = self::encode($value);
        return $this->transaction(function () use ($scope, $collection, $key, $json, $expectedRevision): int {
            $old = $this->get($scope, $collection, $key);
            $revision = $old['revision'] ?? 0;
            if ($expectedRevision !== null && $expectedRevision !== $revision) {
                throw new PluginApiConflict('The extension record has changed.');
            }
            if ($old === null) {
                try {
                    $this->execute('INSERT INTO extension_records (scope, collection, record_key, payload_json, revision, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)', [$scope, $collection, $key, $json, $this->now(), $this->now()]);
                } catch (PDOException $error) {
                    if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                        throw new PluginApiConflict('The extension record was created concurrently.', 0, $error);
                    }
                    throw $error;
                }
            } else {
                $count = $this->execute('UPDATE extension_records SET payload_json = ?, revision = ?, updated_at = ? WHERE scope = ? AND collection = ? AND record_key = ? AND revision = ?', [$json, $revision + 1, $this->now(), $scope, $collection, $key, $revision]);
                if ($count !== 1) { throw new PluginApiConflict('The extension record has changed.'); }
            }
            return $revision + 1;
        });
    }

    public function list(string $scope, string $collection, ?string $after, int $limit): array
    {
        $limit = max(1, min(250, $limit));
        $rows = $this->all('SELECT record_key, payload_json, revision FROM extension_records WHERE scope = ? AND collection = ? AND record_key > ? ORDER BY record_key LIMIT ' . ($limit + 1), [$scope, $collection, $after ?? '']);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(static fn (array $r): array => ['key' => $r['record_key'], 'value' => self::decode($r['payload_json']), 'revision' => (int) $r['revision']], $rows);
        return ['items' => $items, 'next_cursor' => $more ? end($rows)['record_key'] : null];
    }

    public function enqueue(string $scope, string $name, array $payload, string $dedupeKey, int $delay): string
    {
        $key = hash('sha256', $name . "\0" . $dedupeKey);
        $id = hash('sha256', $scope . "\0" . $key);
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = 'INSERT INTO extension_jobs (id, scope, job_name, dedupe_key, payload_json, state, attempts, available_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)';
        $sql .= $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT (scope, dedupe_key) DO NOTHING';
        $guardSql = 'INSERT INTO extension_records (scope, collection, record_key, payload_json, revision, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)';
        $guardSql .= $driver === 'mysql' ? ' ON DUPLICATE KEY UPDATE revision = revision' : ' ON CONFLICT (scope, collection, record_key) DO NOTHING';
        $this->execute($guardSql, ['core:jobs', 'lease-scope', $scope, '{}', $this->now(), $this->now()]);
        $this->execute($sql, [$id, $scope, $name, $key, self::encode($payload), 'queued', $this->now() + max(0, $delay), $this->now(), $this->now()]);
        return $id;
    }

    /** Claim only handlers eligible for this trigger; old owners lose all write authority. */
    public function claim(array $handlers, int $leaseSeconds = 180): ?array
    {
        if ($handlers === []) { return null; }
        return $this->transaction(function () use ($handlers, $leaseSeconds): ?array {
            $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $scopes = [];
            foreach ($handlers as [$scope, $name]) { $scopes[$scope][] = $name; }
            ksort($scopes, SORT_STRING); $row = null;
            foreach ($scopes as $scope => $names) {
                // Lock a single persistent scope row before looking at jobs. Different
                // job names in one plugin cannot bypass its concurrency limit of one.
                $guard = $this->one('SELECT record_key FROM extension_records WHERE scope = ? AND collection = ? AND record_key = ?' . $lock, ['core:jobs', 'lease-scope', $scope]);
                if ($guard === null) { continue; }
                $this->execute('UPDATE extension_jobs SET state = ?, lease_token = NULL, lease_until = NULL, last_error = ?, updated_at = ? WHERE scope = ? AND state = ? AND lease_until <= ? AND attempts >= 5', ['failed', 'lease-expired-retry-limit', $this->now(), $scope, 'running', $this->now()]);
                if ($this->one('SELECT id FROM extension_jobs WHERE scope = ? AND state = ? AND lease_until > ? LIMIT 1' . $lock, [$scope, 'running', $this->now()]) !== null) { continue; }
                $marks = implode(', ', array_fill(0, count($names), '?'));
                $row = $this->one('SELECT * FROM extension_jobs WHERE scope = ? AND ((state = \'queued\' AND available_at <= ?) OR (state = \'running\' AND lease_until <= ?)) AND job_name IN (' . $marks . ') ORDER BY available_at, id LIMIT 1' . $lock, [$scope, $this->now(), $this->now(), ...$names]);
                if ($row !== null) { break; }
            }
            if ($row === null) { return null; }
            $token = bin2hex(random_bytes(24));
            $this->execute('UPDATE extension_jobs SET state = ?, lease_token = ?, lease_until = ?, attempts = attempts + 1, updated_at = ? WHERE id = ?', ['running', $token, $this->now() + max(30, min(900, $leaseSeconds)), $this->now(), $row['id']]);
            $row['lease_token'] = $token;
            $row['attempts'] = (int) $row['attempts'] + 1;
            $row['payload'] = self::decode($row['payload_json']);
            return $row;
        });
    }

    public function assertLease(array $lease): void
    {
        $lock = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $row = $this->one('SELECT id FROM extension_jobs WHERE id = ? AND state = ? AND lease_token = ? AND lease_until > ?' . $lock, [$lease['id'], 'running', $lease['lease_token'], $this->now()]);
        if ($row === null) { throw new PluginApiConflict('The job lease expired or was replaced.'); }
    }

    public function heartbeat(array $lease, int $seconds = 180): void
    {
        $count = $this->execute('UPDATE extension_jobs SET lease_until = ?, updated_at = ? WHERE id = ? AND state = ? AND lease_token = ? AND lease_until > ?', [$this->now() + max(30, min(900, $seconds)), $this->now(), $lease['id'], 'running', $lease['lease_token'], $this->now()]);
        // MySQL reports changed rows: a same-second heartbeat may legitimately
        // leave the deadline unchanged. Re-prove ownership instead of treating
        // that no-op as a lost lease.
        if ($count !== 1) { $this->assertLease($lease); }
    }

    public function finish(array $lease, ?string $error = null): bool
    {
        $retry = $error !== null && (int) $lease['attempts'] < 5;
        return $this->execute('UPDATE extension_jobs SET state = ?, available_at = ?, lease_token = NULL, lease_until = NULL, last_error = ?, updated_at = ? WHERE id = ? AND state = ? AND lease_token = ? AND lease_until > ?', [$error === null ? 'completed' : ($retry ? 'queued' : 'failed'), $this->now() + ($retry ? min(3600, 30 * (2 ** ((int) $lease['attempts'] - 1))) : 0), $error === null ? null : mb_substr($error, 0, 1000), $this->now(), $lease['id'], 'running', $lease['lease_token'], $this->now()]) === 1;
    }

    public function jobs(string $scope, ?string $state, ?string $cursor, int $limit): array
    {
        if ($state !== null && !in_array($state, ['queued', 'running', 'completed', 'failed'], true)) { throw new InvalidArgumentException('Unknown job state.'); }
        $limit = max(1, min(250, $limit)); $params = [$scope, $cursor ?? ''];
        $filter = $state === null ? '' : ' AND state = ?';
        if ($state !== null) { $params[] = $state; }
        $rows = $this->all('SELECT * FROM extension_jobs WHERE scope = ? AND id > ?' . $filter . ' ORDER BY id LIMIT ' . ($limit + 1), $params);
        $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        return ['items' => array_map(self::publicJob(...), $rows), 'next_cursor' => $more ? end($rows)['id'] : null];
    }

    public function getJob(string $scope, string $id): ?array
    {
        $row = $this->one('SELECT * FROM extension_jobs WHERE scope = ? AND id = ?', [$scope, $id]);
        return $row === null ? null : self::publicJob($row);
    }

    private static function publicJob(array $row): array
    {
        $row['payload'] = self::decode($row['payload_json']); $row['attempts'] = (int) $row['attempts'];
        unset($row['payload_json'], $row['lease_token']);
        return $row;
    }

    public function event(string $name, string $sourceKey, array $payload): bool
    {
        $id = sprintf('%020d', (int) (microtime(true) * 1000000)) . bin2hex(random_bytes(16));
        $sql = 'INSERT INTO extension_events (id, event_name, source_key, payload_json, created_at) VALUES (?, ?, ?, ?, ?)';
        $sql .= $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ON DUPLICATE KEY UPDATE id = id' : ' ON CONFLICT (event_name, source_key) DO NOTHING';
        return $this->execute($sql, [$id, $name, $sourceKey, self::encode($payload), $this->now()]) === 1;
    }

    public function events(string $name, ?string $after, int $limit): array
    {
        $limit = max(1, min(250, $limit));
        $rows = $this->all('SELECT id, payload_json FROM extension_events WHERE event_name = ? AND id > ? ORDER BY id LIMIT ' . ($limit + 1), [$name, $after ?? '']);
        $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
        return ['items' => array_map(static fn (array $row): array => self::decode($row['payload_json']) + ['event_id' => $row['id']], $rows), 'next_cursor' => $more ? end($rows)['id'] : null];
    }

    public function accept(string $scope, array $source, string $text, array $provenance): array
    {
        $id = hash('sha256', $scope . "\0" . self::sourceKey($source));
        $old = $this->one('SELECT revision FROM extension_search_content WHERE id = ?', [$id]);
        $revision = (int) ($old['revision'] ?? 0) + 1;
        if ($old === null) {
            $this->execute('INSERT INTO extension_search_content (id, scope, source_type, source_id, source_version, content_hash, page_id, body_text, provenance_json, state, revision, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$id, $scope, $source['source_type'], (string) $source['source_id'], $source['source_version'], $source['content_hash'], $source['page_id'] ?? null, $text, self::encode($provenance), 'accepted', $revision, $this->now(), $this->now()]);
        } else {
            $count = $this->execute('UPDATE extension_search_content SET body_text = ?, provenance_json = ?, state = ?, revision = ?, updated_at = ? WHERE id = ? AND revision = ?', [$text, self::encode($provenance), 'accepted', $revision, $this->now(), $id, $revision - 1]);
            if ($count !== 1) { throw new PluginApiConflict('Accepted data was concurrently changed.'); }
        }
        return ['id' => $id, 'revision' => $revision, 'source' => $source];
    }

    public function searchRows(string $query, int $limit): array
    {
        $terms = preg_split('/\s+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($terms === []) { return []; }
        $clauses = []; $params = [];
        foreach (array_slice($terms, 0, 8) as $term) {
            $clauses[] = "body_text LIKE ? ESCAPE '!'";
            $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        }
        return $this->all('SELECT * FROM extension_search_content WHERE state = \'accepted\' AND (' . implode(' OR ', $clauses) . ') ORDER BY updated_at DESC, id LIMIT ' . max(1, min(500, $limit)), $params);
    }

    public function searchData(string $scope, array $ref): ?array
    {
        $id = hash('sha256', $scope . "\0" . self::sourceKey($ref));
        $row = $this->one('SELECT id, state, revision, provenance_json, updated_at FROM extension_search_content WHERE id = ? AND scope = ?', [$id, $scope]);
        if ($row === null) { return null; }
        $row['revision'] = (int) $row['revision'];
        $row['provenance'] = self::decode($row['provenance_json']); unset($row['provenance_json']);
        return $row;
    }

    public function invalidateSearchData(string $scope, array $ref, int $revision): array
    {
        $id = hash('sha256', $scope . "\0" . self::sourceKey($ref));
        if ($this->execute('UPDATE extension_search_content SET state = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND scope = ? AND revision = ?', ['invalidated', $this->now(), $id, $scope, $revision]) !== 1) {
            throw new PluginApiConflict('The accepted content version changed.');
        }
        return $this->searchData($scope, $ref);
    }

    public function fileStates(string $key): array
    {
        return array_map(static fn (array $row): array => ['scope' => $row['scope']] + self::decode($row['payload_json']), $this->all('SELECT scope, payload_json FROM extension_records WHERE collection = ? AND record_key = ? ORDER BY scope', ['file-state', $key]));
    }

    public static function sourceKey(array $ref): string
    {
        return hash('sha256', (string) ($ref['source_type'] ?? '') . "\0" . (string) ($ref['source_id'] ?? '') . "\0" . (string) ($ref['source_version'] ?? '') . "\0" . (string) ($ref['content_hash'] ?? ''));
    }

    public static function encode(array $value): string
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 16777216) { throw new LengthException('Extension record exceeds 16 MiB.'); }
        return $json;
    }

    public static function decode(string $value): array { return json_decode($value, true, 64, JSON_THROW_ON_ERROR); }
    private function execute(string $sql, array $params): int { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->rowCount(); }
    private function one(string $sql, array $params): ?array { $s = $this->pdo->prepare($sql); $s->execute($params); $r = $s->fetch(PDO::FETCH_ASSOC); return $r === false ? null : $r; }
    private function all(string $sql, array $params): array { $s = $this->pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
}
