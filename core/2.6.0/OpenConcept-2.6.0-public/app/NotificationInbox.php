<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRepository.php';

/** Owner-specific inbox state; original notifications and extension targets remain intact. */
final class NotificationInbox
{
    public const PER_PAGE = 10;
    private PluginApiRepository $records;

    public function __construct(private readonly PDO $pdo)
    {
        $this->records = new PluginApiRepository($pdo);
    }

    public static function filter(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['all', 'unread', 'archived'], true)) {
            throw new InvalidArgumentException('通知の表示条件が正しくありません。');
        }
        return $value;
    }

    public static function pageNumber(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($page)) { throw new InvalidArgumentException('通知のページ番号が正しくありません。'); }
        return $page;
    }

    /** Caller owns the authenticated read snapshot. Counts scan all history, caching each page ACL once. */
    public function page(array $user, string $filter = 'all', int $page = 1): array
    {
        self::filter($filter); self::pageNumber($page);
        $counts = ['all' => 0, 'unread' => 0, 'archived' => 0];
        $selected = []; $lastPage = []; $total = 0;
        foreach ($this->visibleRows($user, true) as $row) {
            if ($row['is_archived']) { $counts['archived']++; }
            else { $counts['all']++; if (!$row['is_read']) { $counts['unread']++; } }
            $matches = $filter === 'archived' ? $row['is_archived'] : (!$row['is_archived'] && ($filter !== 'unread' || !$row['is_read']));
            if (!$matches) { continue; }
            $rowPage = intdiv($total, self::PER_PAGE) + 1;
            if ($total % self::PER_PAGE === 0) { $lastPage = []; }
            $lastPage[] = $row;
            if ($rowPage === $page) { $selected[] = $row; }
            $total++;
        }
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page > $totalPages) { $page = $totalPages; $selected = $lastPage; }
        return ['notifications' => $this->decorate($selected, $user), 'counts' => $counts, 'unread_count' => $counts['unread'],
            'pagination' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'total_pages' => $totalPages]];
    }

    /** Compatibility for non-paginated callers; no bounded pre-ACL scan. */
    public function forUser(array $user, int $limit = 50): array
    {
        $rows = [];
        foreach ($this->visibleRows($user, false) as $row) {
            $rows[] = $row;
            if (count($rows) >= max(1, $limit)) { break; }
        }
        return $this->decorate($rows, $user);
    }

    /** Hidden notices cannot be reopened; archived notices remain accessible. */
    public function find(int $id, array $user, bool $includeHidden = false): ?array
    {
        $statement = $this->pdo->prepare($this->selectSql() . ' AND n.id = ?');
        $statement->execute([(int) $user['id'], $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (!$includeHidden && $row['inbox_hidden_key'] !== null)) { return null; }
        $cache = [];
        return $this->accessible($row, $user, $cache) ? $this->normalize($row) : null;
    }

    public function refreshCandidate(int $userId, string $type, ?int $pageId): int
    {
        $sql = $this->selectSql() . ' AND a.record_key IS NULL AND h.record_key IS NULL AND n.is_read = 0 AND n.type = ?'
            . ($pageId === null ? ' AND n.page_id IS NULL' : ' AND n.page_id = ?') . ' ORDER BY n.created_at DESC, n.id DESC LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->execute($pageId === null ? [$userId, $type] : [$userId, $type, $pageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? (int) $row['id'] : 0;
    }

    /** Every change holds the live actor, page hierarchy and notice locks in that order. */
    public function mutate(string $operation, array $requestUser, string $fingerprint, int $id = 0, bool $isRead = true): array
    {
        if (!in_array($operation, ['read', 'archive', 'delete', 'mark-all'], true) || ($operation !== 'mark-all' && $id < 1)) {
            throw new InvalidArgumentException('通知を選択してください。');
        }
        if ($this->pdo->inTransaction()) { throw new LogicException('An inbox write transaction is already active.'); }
        for ($attempt = 1; ; $attempt++) {
            try {
                beginPageWriteTransaction($this->pdo);
                $actor = lockedAuthenticatedApplicationUserForWrite($this->pdo, $requestUser, $fingerprint);
                if ($actor === null || !empty($actor['must_change_password'])) { throw new RuntimeException('ログインが必要です。', 401); }
                $result = $operation === 'mark-all' ? $this->markAll($actor) : $this->changeOne($operation, $id, $actor, $isRead);
                $this->pdo->commit();
                return $result;
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                if ($attempt < 3 && self::transientConflict($error)) { usleep(20000 * $attempt); continue; }
                throw $error;
            }
        }
    }

    public static function transientConflict(Throwable $error): bool
    {
        return $error instanceof PDOException && ((string) $error->getCode() === '40001'
            || in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true));
    }

    private function changeOne(string $operation, int $id, array $actor, bool $isRead): array
    {
        $row = $this->find($id, $actor, true);
        if ($row === null) { throw new RuntimeException('通知が見つかりません。', 404); }
        $this->lockPages($row['page_id'] === null ? [] : [$row['page_id']]);
        $lock = $this->pdo->prepare('SELECT id FROM notifications WHERE id = ? AND user_id = ?' . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'));
        $lock->execute([$id, (int) $actor['id']]); $lock->fetchColumn();
        $row = $this->find($id, $actor, true);
        if ($row === null) { throw new RuntimeException('通知が見つかりません。', 404); }
        if ($operation === 'read') {
            if ($row['is_hidden']) { throw new RuntimeException('通知が見つかりません。', 404); }
            if ($row['is_archived'] && !$isRead) { throw new RuntimeException('アーカイブ済みの通知を未読には戻せません。', 409); }
            $this->pdo->prepare('UPDATE notifications SET is_read = ? WHERE id = ? AND user_id = ?')->execute([$isRead ? 1 : 0, $id, (int) $actor['id']]);
            return ['ok' => true, 'id' => $id, 'is_read' => $isRead, 'is_archived' => $row['is_archived']];
        }
        if ($operation === 'archive') {
            if (!$row['is_read']) { throw new RuntimeException('既読の通知のみアーカイブできます。', 409); }
            $this->marker('inbox-archived', $row, $actor, 'archived_at');
            return ['ok' => true, 'id' => $id, 'is_read' => true, 'is_archived' => true];
        }
        if (!$row['is_archived']) { throw new RuntimeException('アーカイブ済みの通知のみ削除できます。', 409); }
        $this->marker('inbox-hidden', $row, $actor, 'hidden_at');
        return ['ok' => true, 'id' => $id, 'is_archived' => true, 'is_hidden' => true];
    }

    private function markAll(array $actor): array
    {
        $candidates = []; $pageIds = [];
        foreach ($this->visibleRows($actor, false) as $row) {
            if ($row['is_read']) { continue; }
            $candidates[] = $row['id'];
            if ($row['page_id'] !== null) { $pageIds[] = $row['page_id']; }
        }
        $this->lockPages($pageIds);
        sort($candidates, SORT_NUMERIC);
        $updated = 0;
        foreach ($candidates as $id) {
            $row = $this->find($id, $actor);
            if ($row === null || $row['is_archived'] || $row['is_read']) { continue; }
            $statement = $this->pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0');
            $statement->execute([$id, (int) $actor['id']]); $updated += $statement->rowCount();
        }
        return ['ok' => true, 'updated' => $updated];
    }

    private function marker(string $collection, array $row, array $actor, string $timeKey): void
    {
        $key = (string) $row['id'];
        if ($this->records->get('core:notifications', $collection, $key) !== null) { return; }
        $this->records->put('core:notifications', $collection, $key,
            ['notification_id' => $row['id'], 'user_id' => (int) $actor['id'], 'actor_user_id' => (int) $actor['id'], $timeKey => gmdate('Y-m-d\TH:i:s\Z')], 0);
    }

    private function lockPages(array $ids): void
    {
        if ($ids === []) { return; }
        $locked = lockedPageHierarchiesForUpdate($this->pdo, $ids);
        if (($locked['reason'] ?? null) !== null) { throw new RuntimeException('通知の閲覧権限が変更されました。再読み込みしてください。', 409); }
    }

    private function visibleRows(array $user, bool $includeArchived): Generator
    {
        $statement = $this->pdo->prepare($this->selectSql() . ' AND h.record_key IS NULL'
            . ($includeArchived ? '' : ' AND a.record_key IS NULL') . ' ORDER BY n.created_at DESC, n.id DESC');
        $statement->execute([(int) $user['id']]);
        $cache = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if ($this->accessible($row, $user, $cache)) { yield $this->normalize($row); }
        }
    }

    private function accessible(array $row, array $user, array &$cache): bool
    {
        if ($row['page_id'] === null) { return true; }
        $id = (int) $row['page_id'];
        return $cache[$id] ??= canViewPage($this->pdo, $user, ['id' => $id]);
    }

    private function selectSql(): string
    {
        $cast = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'CHAR' : 'TEXT';
        return "SELECT n.*, a.record_key AS inbox_archive_key, h.record_key AS inbox_hidden_key FROM notifications n "
            . "LEFT JOIN pages p ON p.id = n.page_id "
            . "LEFT JOIN extension_records a ON a.scope = 'core:notifications' AND a.collection = 'inbox-archived' AND a.record_key = CAST(n.id AS {$cast}) "
            . "LEFT JOIN extension_records h ON h.scope = 'core:notifications' AND h.collection = 'inbox-hidden' AND h.record_key = CAST(n.id AS {$cast}) "
            . 'WHERE n.user_id = ? AND (n.page_id IS NULL OR (p.id IS NOT NULL AND p.archived_at IS NULL))';
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id']; $row['user_id'] = (int) $row['user_id'];
        $row['page_id'] = $row['page_id'] === null ? null : (int) $row['page_id'];
        $row['is_read'] = (bool) $row['is_read'];
        $row['is_archived'] = $row['inbox_archive_key'] !== null;
        $row['is_hidden'] = $row['inbox_hidden_key'] !== null;
        unset($row['inbox_archive_key'], $row['inbox_hidden_key']);
        return $row;
    }

    private function decorate(array $rows, array $user): array
    {
        $runtime = $GLOBALS['pluginApiRuntime'] ?? null;
        return $runtime instanceof PluginApiRuntime ? $runtime->decorateNotifications($rows, $user) : $rows;
    }
}
