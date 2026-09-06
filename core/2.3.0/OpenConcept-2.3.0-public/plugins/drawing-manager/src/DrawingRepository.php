<?php

declare(strict_types=1);

require_once __DIR__ . '/DrawingOcrMetadata.php';
require_once __DIR__ . '/DrawingProcessMetadata.php';

final class DrawingRepository
{
    private const STATUS_PENDING = 'pending';
    private const STATUS_APPROVED = 'approved';

    private PDO $pdo;
    private string $driver;
    private string $drawingsTable;
    private string $filesTable;
    private string $correctionCommentsTable;
    private string $usersTable;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = method_exists($pdo, 'tablePrefix') ? (string) $pdo->tablePrefix() : '';
        if ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]*_$/', $prefix) !== 1) {
            throw new RuntimeException('The database table prefix is invalid.');
        }

        $this->drawingsTable = $prefix . 'plugin_dwg_documents';
        $this->filesTable = $prefix . 'plugin_dwg_document_files';
        $this->correctionCommentsTable = $prefix . 'plugin_dwg_correction_comments';
        $this->usersTable = method_exists($pdo, 'physicalTableName')
            ? (string) $pdo->physicalTableName('users')
            : $prefix . 'users';
        foreach ($this->tableNames() as $table) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/', $table) !== 1) {
                throw new RuntimeException('The drawing manager table name is invalid.');
            }
        }
    }

    /** @return array<int, string> */
    public function tableNames(): array
    {
        return [$this->drawingsTable, $this->filesTable, $this->usersTable, $this->correctionCommentsTable];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Runs one plugin mutation after serializing and re-authenticating the
     * request actor. Resource-level locks are acquired by the operation only
     * after this actor lock, and nested repository writers leave the outer
     * transaction in charge of commit/rollback.
     *
     * @param array<string, mixed> $requestUser
     * @param array<int, string> $allowedRoles Empty means every active application role.
     */
    public function authenticatedWrite(
        array $requestUser,
        string $expectedPasswordFingerprint,
        array $allowedRoles,
        callable $operation
    ): mixed {
        if (!function_exists('beginPageWriteTransaction')
            || !function_exists('lockedAuthenticatedApplicationUserForWrite')) {
            throw new LogicException('Drawing writes require the application authentication helpers.');
        }
        if ($this->pdo->inTransaction()) {
            throw new LogicException('An authenticated drawing write transaction is already active.');
        }
        foreach ($allowedRoles as $role) {
            if (!is_string($role) || !in_array($role, ['admin', 'editor', 'viewer'], true)) {
                throw new InvalidArgumentException('The drawing writer role constraint is invalid.');
            }
        }

        beginPageWriteTransaction($this->pdo);
        try {
            $actor = lockedAuthenticatedApplicationUserForWrite(
                $this->pdo,
                $requestUser,
                $expectedPasswordFingerprint
            );
            if (!is_array($actor)) {
                throw new RuntimeException('The login state changed. Sign in again.', 401);
            }
            if ($allowedRoles !== [] && !in_array((string) ($actor['role'] ?? ''), $allowedRoles, true)) {
                throw new RuntimeException('You do not have permission to change this drawing.', 403);
            }

            $result = $operation($actor);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function migrate(): void
    {
        if ($this->driver === 'mysql') {
            $this->migrateMySql();
            return;
        }
        if ($this->driver !== 'sqlite') {
            throw new RuntimeException('図面管理が対応していないデータベースです。');
        }
        $this->migrateSqlite();
    }

    /**
     * @return array{
     *   drawings: array<int, array<string, mixed>>,
     *   counts: array{total: int, approved: int, pending: int},
     *   pagination: array{page: int, per_page: int, total: int, total_pages: int}
     * }
     */
    public function listDrawings(
        string $query = '',
        string $status = self::STATUS_APPROVED,
        int $page = 1,
        int $perPage = 20
    ): array
    {
        $query = $this->clean($query, 160);
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, 'all'], true)) {
            throw new InvalidArgumentException('図面の承認状態が正しくありません。');
        }
        if ($page < 1) {
            throw new InvalidArgumentException('図面一覧のページ番号が正しくありません。');
        }
        if (!in_array($perPage, [20, 50, 100], true)) {
            throw new InvalidArgumentException('図面一覧の表示件数が正しくありません。');
        }

        $where = ['d.archived_at IS NULL'];
        $parameters = [];
        if ($status !== 'all') {
            $where[] = 'd.approval_status = ?';
            $parameters[] = $status;
        }
        if ($query !== '') {
            $where[] = '(d.drawing_no LIKE ? OR d.title LIKE ? OR d.customer LIKE ? OR d.material LIKE ? OR d.process LIKE ? '
                . 'OR d.process_metadata_json LIKE ? '
                . 'OR EXISTS (SELECT 1 FROM `' . $this->filesTable . '` sf WHERE sf.document_id = d.id AND sf.original_name LIKE ?))';
            $needle = '%' . $query . '%';
            array_push($parameters, ...array_fill(0, 7, $needle));
        }

        $countStatement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*) FROM `%s` d WHERE %s',
            $this->drawingsTable,
            implode(' AND ', $where)
        ));
        $countStatement->execute($parameters);
        $total = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $statement = $this->pdo->prepare(sprintf(
            'SELECT d.*, '
            . '(SELECT f.id FROM `%1$s` f WHERE f.document_id = d.id ORDER BY f.id LIMIT 1) AS primary_file_id, '
            . '(SELECT f.original_name FROM `%1$s` f WHERE f.document_id = d.id ORDER BY f.id LIMIT 1) AS primary_file_name, '
            . '(SELECT f.extension FROM `%1$s` f WHERE f.document_id = d.id ORDER BY f.id LIMIT 1) AS primary_file_extension, '
            . '(SELECT COUNT(*) FROM `%1$s` f WHERE f.document_id = d.id) AS file_count '
            . 'FROM `%2$s` d WHERE %3$s ORDER BY d.updated_at DESC, d.id DESC LIMIT %4$d OFFSET %5$d',
            $this->filesTable,
            $this->drawingsTable,
            implode(' AND ', $where),
            $perPage,
            $offset
        ));
        $statement->execute($parameters);

        return [
            'drawings' => array_map([$this, 'normalizeDrawing'], $statement->fetchAll()),
            'counts' => $this->drawingCounts(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function getDrawing(int $drawingId): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT d.*, creator.name AS creator_name, updater.name AS updater_name, approver.name AS approver_name '
            . 'FROM `%s` d JOIN `%s` creator ON creator.id = d.created_by '
            . 'JOIN `%s` updater ON updater.id = d.updated_by '
            . 'LEFT JOIN `%s` approver ON approver.id = d.approved_by '
            . 'WHERE d.id = ? AND d.archived_at IS NULL',
            $this->drawingsTable,
            $this->usersTable,
            $this->usersTable,
            $this->usersTable
        ));
        $statement->execute([$drawingId]);
        $drawing = $statement->fetch();
        if (!$drawing) {
            throw new OutOfBoundsException('図面が見つかりません。');
        }

        $fileStatement = $this->pdo->prepare(sprintf(
            'SELECT f.*, uploader.name AS uploader_name FROM `%s` f '
            . 'JOIN `%s` uploader ON uploader.id = f.uploaded_by '
            . 'WHERE f.document_id = ? ORDER BY f.created_at DESC, f.id DESC',
            $this->filesTable,
            $this->usersTable
        ));
        $fileStatement->execute([$drawingId]);
        $drawing = $this->normalizeDrawing($drawing);
        $drawing['files'] = array_map([$this, 'normalizeFile'], $fileStatement->fetchAll());
        return $drawing;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function createDraftWithFile(array $data, array $file, int $userId): array
    {
        return $this->createWithFile($data, $file, $userId, false);
    }

    /**
     * Backward-compatible direct approved registration for older callers.
     * New upload flows should use createDraftWithFile() and approveDrawing().
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    public function createDrawingWithFile(array $data, array $file, int $userId): array
    {
        return $this->createWithFile($data, $file, $userId, true);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function approveDrawing(
        int $drawingId,
        array $data,
        int $userId,
        int $expectedRowVersion
    ): array {
        if ($drawingId < 1 || $userId < 1) {
            throw new InvalidArgumentException('承認対象が正しくありません。');
        }
        if ($expectedRowVersion < 1) {
            throw new InvalidArgumentException('図面の更新バージョンが正しくありません。');
        }

        $drawingNo = $this->required($data, 'drawing_no', 80, '図面番号を入力してください。');
        $title = $this->required($data, 'title', 160, '品名を入力してください。');
        if (!array_key_exists('revision_code', $data)) {
            throw new InvalidArgumentException('改訂番号を確認してください。改訂がない場合は空欄で送信してください。');
        }
        $revisionCode = $this->revisionValue($data);
        $approvalKey = $this->approvalKey($drawingNo, $revisionCode);

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $select = $this->pdo->prepare(sprintf(
                'SELECT * FROM `%s` WHERE id = ? AND archived_at IS NULL%s',
                $this->drawingsTable,
                $this->driver !== 'sqlite' ? ' FOR UPDATE' : ''
            ));
            $select->execute([$drawingId]);
            $current = $select->fetch();
            if (!$current) {
                throw new OutOfBoundsException('承認する図面が見つかりません。');
            }
            if ((string) ($current['approval_status'] ?? '') !== self::STATUS_PENDING) {
                throw new UnexpectedValueException('この図面は既に承認済みです。');
            }
            if ((int) ($current['row_version'] ?? 0) !== $expectedRowVersion) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }

            $duplicate = $this->pdo->prepare(sprintf(
                'SELECT id FROM `%s` WHERE approval_key = ? AND id <> ? AND archived_at IS NULL LIMIT 1',
                $this->drawingsTable
            ));
            $duplicate->execute([$approvalKey, $drawingId]);
            if ($duplicate->fetchColumn() !== false) {
                throw new UnexpectedValueException('同じ図面番号と改訂番号の承認済み図面があります。');
            }

            $customer = $this->updatedValue($data, $current, 'customer', 160);
            $material = $this->updatedValue($data, $current, 'material', 120);
            $process = $this->updatedValue($data, $current, 'process', 160);
            $processMetadataJson = $this->updatedProcessMetadata($data, $current);
            $department = $this->updatedValue($data, $current, 'owner_department', 120);
            $notes = $this->updatedValue($data, $current, 'notes', 2000);

            $update = $this->pdo->prepare(sprintf(
                'UPDATE `%s` SET drawing_no = ?, title = ?, revision_code = ?, customer = ?, material = ?, process = ?, '
                . 'process_metadata_json = ?, owner_department = ?, notes = ?, approval_status = ?, approval_key = ?, approved_by = ?, '
                . 'approved_at = CURRENT_TIMESTAMP, updated_by = ?, row_version = row_version + 1, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = ? AND approval_status = ? AND row_version = ? AND archived_at IS NULL',
                $this->drawingsTable
            ));
            $update->execute([
                $drawingNo, $title, $revisionCode, $customer, $material, $process, $processMetadataJson, $department, $notes,
                self::STATUS_APPROVED, $approvalKey, $userId, $userId, $drawingId,
                self::STATUS_PENDING, $expectedRowVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }
            $drawing = $this->getDrawing($drawingId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isUniqueViolation($exception)) {
                throw new UnexpectedValueException('同じ図面番号と改訂番号の承認済み図面があります。', 0, $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $drawing;
    }

    /**
     * Updates an active drawing without changing its approval state.
     *
     * The controller restricts this operation to system administrators. Keeping
     * the permission check out of the repository also makes optimistic locking
     * and the approved identity constraint reusable in deterministic tests.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateDrawing(
        int $drawingId,
        array $data,
        int $userId,
        int $expectedRowVersion
    ): array {
        if ($drawingId < 1 || $userId < 1) {
            throw new InvalidArgumentException('更新対象が正しくありません。');
        }
        if ($expectedRowVersion < 1) {
            throw new InvalidArgumentException('図面の更新バージョンが正しくありません。');
        }

        $drawingNo = $this->required($data, 'drawing_no', 80, '図面番号を入力してください。');
        $title = $this->required($data, 'title', 160, '品名を入力してください。');
        if (!array_key_exists('revision_code', $data)) {
            throw new InvalidArgumentException('改訂番号を確認してください。改訂がない場合は「-」を入力してください。');
        }
        $revisionCode = $this->revisionValue($data);

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $select = $this->pdo->prepare(sprintf(
                'SELECT * FROM `%s` WHERE id = ? AND archived_at IS NULL%s',
                $this->drawingsTable,
                $this->driver !== 'sqlite' ? ' FOR UPDATE' : ''
            ));
            $select->execute([$drawingId]);
            $current = $select->fetch();
            if (!$current) {
                throw new OutOfBoundsException('更新する図面が見つかりません。');
            }
            if ((int) ($current['row_version'] ?? 0) !== $expectedRowVersion) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }

            $approvalStatus = (string) ($current['approval_status'] ?? '');
            if (!in_array($approvalStatus, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
                throw new UnexpectedValueException('図面の承認状態が正しくありません。');
            }
            $approvalKey = $approvalStatus === self::STATUS_APPROVED
                ? $this->approvalKey($drawingNo, $revisionCode)
                : null;
            if ($approvalKey !== null) {
                $duplicate = $this->pdo->prepare(sprintf(
                    'SELECT id FROM `%s` WHERE approval_key = ? AND id <> ? AND archived_at IS NULL LIMIT 1',
                    $this->drawingsTable
                ));
                $duplicate->execute([$approvalKey, $drawingId]);
                if ($duplicate->fetchColumn() !== false) {
                    throw new UnexpectedValueException('同じ図面番号と改訂番号の承認済み図面があります。');
                }
            }

            $customer = $this->updatedValue($data, $current, 'customer', 160);
            $material = $this->updatedValue($data, $current, 'material', 120);
            $process = $this->updatedValue($data, $current, 'process', 160);
            $processMetadataJson = $this->updatedProcessMetadata($data, $current);
            $department = $this->updatedValue($data, $current, 'owner_department', 120);
            $notes = $this->updatedValue($data, $current, 'notes', 2000);

            $update = $this->pdo->prepare(sprintf(
                'UPDATE `%s` SET drawing_no = ?, title = ?, revision_code = ?, customer = ?, material = ?, process = ?, '
                . 'process_metadata_json = ?, owner_department = ?, notes = ?, approval_key = ?, updated_by = ?, '
                . 'row_version = row_version + 1, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = ? AND row_version = ? AND archived_at IS NULL',
                $this->drawingsTable
            ));
            $update->execute([
                $drawingNo, $title, $revisionCode, $customer, $material, $process, $processMetadataJson,
                $department, $notes, $approvalKey, $userId, $drawingId, $expectedRowVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }
            $drawing = $this->getDrawing($drawingId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($this->isApprovalKeyViolation($exception)) {
                throw new UnexpectedValueException('同じ図面番号と改訂番号の承認済み図面があります。', 0, $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $drawing;
    }

    /** @return array<int, array<string, mixed>> */
    public function listCorrectionComments(int $drawingId): array
    {
        if ($drawingId < 1) {
            throw new InvalidArgumentException('コメント対象が正しくありません。');
        }
        $this->assertActiveDrawingExists($drawingId, 'コメントを表示する図面が見つかりません。');

        $statement = $this->pdo->prepare(sprintf(
            'SELECT c.*, creator.name AS creator_name, resolver.name AS resolver_name '
            . 'FROM `%s` c JOIN `%s` d ON d.id = c.drawing_id '
            . 'JOIN `%s` creator ON creator.id = c.created_by '
            . 'LEFT JOIN `%s` resolver ON resolver.id = c.resolved_by '
            . 'WHERE c.drawing_id = ? AND d.archived_at IS NULL ORDER BY c.created_at ASC, c.id ASC',
            $this->correctionCommentsTable,
            $this->drawingsTable,
            $this->usersTable,
            $this->usersTable
        ));
        $statement->execute([$drawingId]);
        return array_map([$this, 'normalizeCorrectionComment'], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    public function createCorrectionComment(
        int $drawingId,
        string $body,
        int $userId,
        bool $canEditPending = true
    ): array
    {
        if ($drawingId < 1 || $userId < 1) {
            throw new InvalidArgumentException('コメント対象が正しくありません。');
        }
        $body = $this->clean($body, 2000);
        if ($body === '') {
            throw new InvalidArgumentException('誤りと訂正内容を入力してください。');
        }

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $drawing = $this->assertActiveDrawingExists(
                $drawingId,
                'コメントを投稿する図面が見つかりません。',
                true
            );
            if (!$canEditPending && (string) ($drawing['approval_status'] ?? '') !== self::STATUS_APPROVED) {
                throw new OutOfBoundsException('コメントを投稿する図面が見つかりません。');
            }
            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO `%s` (drawing_id, body, created_by, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)',
                $this->correctionCommentsTable
            ));
            $insert->execute([$drawingId, $body, $userId]);
            $commentId = (int) $this->pdo->lastInsertId();
            $comment = $this->getCorrectionComment($commentId, true);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $comment;
    }

    /** @return array<string, mixed> */
    public function resolveCorrectionComment(int $commentId, int $userId): array
    {
        if ($commentId < 1 || $userId < 1) {
            throw new InvalidArgumentException('対応するコメントが正しくありません。');
        }

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $comment = $this->getCorrectionComment($commentId, true, true);
            if (($comment['resolved_at'] ?? null) === null) {
                $update = $this->pdo->prepare(sprintf(
                    'UPDATE `%s` SET resolved_by = ?, resolved_at = CURRENT_TIMESTAMP '
                    . 'WHERE id = ? AND resolved_at IS NULL',
                    $this->correctionCommentsTable
                ));
                $update->execute([$userId, $commentId]);
                if ($update->rowCount() !== 1) {
                    throw new UnexpectedValueException('訂正依頼がほかの操作で更新されました。再読み込みしてください。');
                }
                $comment = $this->getCorrectionComment($commentId, true);
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        return $comment;
    }

    /**
     * Permanently hides a drawing from application reads while retaining its record and files.
     * Releasing approval_key keeps archived identities out of duplicate approval checks.
     */
    public function archiveDrawing(int $drawingId, int $userId, int $expectedRowVersion): void
    {
        if ($drawingId < 1 || $userId < 1) {
            throw new InvalidArgumentException('アーカイブ対象が正しくありません。');
        }
        if ($expectedRowVersion < 1) {
            throw new InvalidArgumentException('図面の更新バージョンが正しくありません。');
        }

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $select = $this->pdo->prepare(sprintf(
                'SELECT row_version FROM `%s` WHERE id = ? AND archived_at IS NULL%s',
                $this->drawingsTable,
                $this->driver !== 'sqlite' ? ' FOR UPDATE' : ''
            ));
            $select->execute([$drawingId]);
            $current = $select->fetch();
            if (!$current) {
                throw new OutOfBoundsException('アーカイブする図面が見つかりません。');
            }
            if ((int) ($current['row_version'] ?? 0) !== $expectedRowVersion) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }

            $update = $this->pdo->prepare(sprintf(
                'UPDATE `%s` SET archived_at = CURRENT_TIMESTAMP, approval_key = NULL, updated_by = ?, '
                . 'row_version = row_version + 1, updated_at = CURRENT_TIMESTAMP '
                . 'WHERE id = ? AND row_version = ? AND archived_at IS NULL',
                $this->drawingsTable
            ));
            $update->execute([$userId, $drawingId, $expectedRowVersion]);
            if ($update->rowCount() !== 1) {
                throw new UnexpectedValueException('図面情報がほかの操作で更新されました。再読み込みしてください。');
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function getFile(int $fileId): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT f.*, d.drawing_no, d.title, d.approval_status FROM `%s` f '
            . 'JOIN `%s` d ON d.id = f.document_id '
            . 'WHERE f.id = ? AND d.archived_at IS NULL',
            $this->filesTable,
            $this->drawingsTable
        ));
        $statement->execute([$fileId]);
        $file = $statement->fetch();
        if (!$file) {
            throw new OutOfBoundsException('図面ファイルが見つかりません。');
        }
        return $this->normalizeFile($file);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
    private function createWithFile(array $data, array $file, int $userId, bool $approved): array
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('図面の登録ユーザーが正しくありません。');
        }
        $drawingNo = $approved
            ? $this->required($data, 'drawing_no', 80, '図面番号を入力してください。')
            : $this->inputValue($data, 'drawing_no', 80);
        $title = $approved
            ? $this->required($data, 'title', 160, '品名を入力してください。')
            : $this->inputValue($data, 'title', 160);
        $revisionCode = $this->revisionValue($data);
        $customer = $this->inputValue($data, 'customer', 160);
        $material = $this->inputValue($data, 'material', 120);
        $process = $this->inputValue($data, 'process', 160);
        $processMetadataJson = $this->inputProcessMetadata($data);
        $department = $this->inputValue($data, 'owner_department', 120);
        $notes = $this->inputValue($data, 'notes', 2000);
        $extractionSource = $this->inputValue($data, 'extraction_source', 32, 'manual') ?: 'manual';
        $extractionConfidence = max(0, min(100, (int) ($data['extraction_confidence'] ?? 0)));
        $approvalStatus = $approved ? self::STATUS_APPROVED : self::STATUS_PENDING;
        $approvalKey = $approved ? $this->approvalKey($drawingNo, $revisionCode) : null;
        $approvedBy = $approved ? $userId : null;
        $approvedAtSql = $approved ? 'CURRENT_TIMESTAMP' : 'NULL';

        $ownsTransaction = $this->beginWriteTransaction();
        try {
            $insert = $this->pdo->prepare(sprintf(
                'INSERT INTO `%s` (drawing_no, title, revision_code, customer, material, process, process_metadata_json, owner_department, notes, '
                . 'extraction_source, extraction_confidence, approval_status, approval_key, approved_by, approved_at, '
                . 'row_version, created_by, updated_by, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, %s, 1, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
                $this->drawingsTable,
                $approvedAtSql
            ));
            $insert->execute([
                $drawingNo, $title, $revisionCode, $customer, $material, $process, $processMetadataJson, $department, $notes,
                $extractionSource, $extractionConfidence, $approvalStatus, $approvalKey, $approvedBy, $userId, $userId,
            ]);
            $drawingId = (int) $this->pdo->lastInsertId();

            $fileInsert = $this->pdo->prepare(sprintf(
                'INSERT INTO `%s` (document_id, original_name, stored_name, mime_type, extension, size_bytes, sha256, ocr_regions_json, uploaded_by, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
                $this->filesTable
            ));
            $fileInsert->execute([
                $drawingId,
                $this->required($file, 'original_name', 255, '図面ファイル名がありません。'),
                $this->required($file, 'stored_name', 96, '図面保存名がありません。'),
                $this->inputValue($file, 'mime_type', 160, 'application/octet-stream') ?: 'application/octet-stream',
                strtolower($this->inputValue($file, 'extension', 12)),
                max(1, (int) ($file['size_bytes'] ?? 0)),
                strtolower($this->inputValue($file, 'sha256', 64)),
                DrawingOcrMetadata::encode($file['ocr_regions'] ?? []),
                $userId,
            ]);
            $drawing = $this->getDrawing($drawingId);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $drawing;
        } catch (PDOException $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($approved && $this->isApprovalKeyViolation($exception)) {
                throw new UnexpectedValueException('同じ図面番号と改訂番号の承認済み図面があります。', 0, $exception);
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function assertActiveDrawingExists(int $drawingId, string $message, bool $forUpdate = false): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT id, approval_status FROM `%s` WHERE id = ? AND archived_at IS NULL%s',
            $this->drawingsTable,
            $forUpdate && $this->driver !== 'sqlite' ? ' FOR UPDATE' : ''
        ));
        $statement->execute([$drawingId]);
        $drawing = $statement->fetch();
        if (!is_array($drawing)) {
            throw new OutOfBoundsException($message);
        }
        return $drawing;
    }

    /** @return array<string, mixed> */
    private function getCorrectionComment(int $commentId, bool $requireActiveDrawing, bool $forUpdate = false): array
    {
        $activeClause = $requireActiveDrawing ? ' AND d.archived_at IS NULL' : '';
        $statement = $this->pdo->prepare(sprintf(
            'SELECT c.*, creator.name AS creator_name, resolver.name AS resolver_name '
            . 'FROM `%s` c JOIN `%s` d ON d.id = c.drawing_id '
            . 'JOIN `%s` creator ON creator.id = c.created_by '
            . 'LEFT JOIN `%s` resolver ON resolver.id = c.resolved_by '
            . 'WHERE c.id = ?%s%s',
            $this->correctionCommentsTable,
            $this->drawingsTable,
            $this->usersTable,
            $this->usersTable,
            $activeClause,
            $forUpdate && $this->driver !== 'sqlite' ? ' FOR UPDATE' : ''
        ));
        $statement->execute([$commentId]);
        $comment = $statement->fetch();
        if (!$comment) {
            throw new OutOfBoundsException('訂正依頼が見つかりません。');
        }
        return $this->normalizeCorrectionComment($comment);
    }

    /** @return array{total: int, approved: int, pending: int} */
    private function drawingCounts(): array
    {
        $counts = ['total' => 0, 'approved' => 0, 'pending' => 0];
        $statement = $this->pdo->query(sprintf(
            'SELECT approval_status, COUNT(*) AS aggregate FROM `%s` WHERE archived_at IS NULL GROUP BY approval_status',
            $this->drawingsTable
        ));
        foreach ($statement->fetchAll() as $row) {
            $status = (string) ($row['approval_status'] ?? '');
            $count = (int) ($row['aggregate'] ?? 0);
            if (in_array($status, [self::STATUS_APPROVED, self::STATUS_PENDING], true)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }
        return $counts;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeDrawing(array $row): array
    {
        foreach (['id', 'created_by', 'updated_by', 'approved_by', 'row_version', 'extraction_confidence', 'primary_file_id', 'file_count'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        $row['process_metadata'] = DrawingProcessMetadata::decode($row['process_metadata_json'] ?? '[]');
        unset($row['process_metadata_json']);
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeFile(array $row): array
    {
        foreach (['id', 'document_id', 'size_bytes', 'uploaded_by'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = (int) $row[$field];
            }
        }
        $row['ocr_regions'] = DrawingOcrMetadata::decode($row['ocr_regions_json'] ?? '[]');
        unset($row['ocr_regions_json']);
        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizeCorrectionComment(array $row): array
    {
        foreach (['id', 'drawing_id', 'created_by', 'resolved_by'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        $row['resolved'] = ($row['resolved_at'] ?? null) !== null;
        return $row;
    }

    /** @param array<string, mixed> $data */
    private function required(array $data, string $key, int $max, string $message): string
    {
        $value = $this->inputValue($data, $key, $max);
        if ($value === '') {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $current */
    private function updatedValue(array $data, array $current, string $key, int $max): string
    {
        return array_key_exists($key, $data)
            ? $this->inputValue($data, $key, $max)
            : $this->clean((string) ($current[$key] ?? ''), $max);
    }

    /** @param array<string, mixed> $data */
    private function inputValue(array $data, string $key, int $max, string $default = ''): string
    {
        if (!array_key_exists($key, $data)) {
            return $this->clean($default, $max);
        }
        if (!is_string($data[$key])) {
            throw new InvalidArgumentException('図面情報の入力形式が正しくありません。');
        }
        return $this->clean($data[$key], $max);
    }

    /** @param array<string, mixed> $data */
    private function revisionValue(array $data): string
    {
        return $this->normalizeRevisionCode($this->inputValue($data, 'revision_code', 40));
    }

    /** @param array<string, mixed> $data */
    private function inputProcessMetadata(array $data): string
    {
        if (!array_key_exists('process_metadata', $data)) {
            return '[]';
        }
        $metadata = $data['process_metadata'];
        if (!is_array($metadata) || !array_is_list($metadata)) {
            throw new InvalidArgumentException('加工工程メタデータの入力形式が正しくありません。');
        }
        foreach ($metadata as $item) {
            $code = is_string($item) ? $item : (is_array($item) ? ($item['code'] ?? null) : null);
            if (!is_string($code) || !DrawingProcessMetadata::isAllowedCode($code)) {
                throw new InvalidArgumentException('選択された加工工程が正しくありません。');
            }
        }
        return DrawingProcessMetadata::encode($metadata);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $current */
    private function updatedProcessMetadata(array $data, array $current): string
    {
        if (array_key_exists('process_metadata', $data)) {
            return $this->inputProcessMetadata($data);
        }
        return DrawingProcessMetadata::encode(DrawingProcessMetadata::decode($current['process_metadata_json'] ?? '[]'));
    }

    private function beginWriteTransaction(): bool
    {
        if ($this->pdo->inTransaction()) {
            return false;
        }
        if (function_exists('beginPageWriteTransaction')) {
            beginPageWriteTransaction($this->pdo);
            return true;
        }
        if ($this->driver === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            return true;
        }
        $this->pdo->beginTransaction();
        return true;
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    private function approvalKey(string $drawingNo, string $revisionCode): string
    {
        $revisionIdentity = $this->identityPart($this->normalizeRevisionCode($revisionCode));
        // A hyphen is the persisted display value for "no revision". Keep its
        // identity compatible with legacy approved rows that stored an empty value.
        if ($revisionIdentity === '-') {
            $revisionIdentity = '';
        }
        return hash('sha256', $this->identityPart($drawingNo) . "\0" . $revisionIdentity);
    }

    private function normalizeRevisionCode(string $revisionCode): string
    {
        $revisionCode = $this->clean($revisionCode, 40);
        return $revisionCode === '' || preg_match('/^[-－−–—]+$/u', $revisionCode) === 1
            ? '-'
            : $revisionCode;
    }

    private function identityPart(string $value): string
    {
        $value = trim(preg_replace('/[\s　]+/u', ' ', $value) ?? $value);
        return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
    }

    private function isUniqueViolation(PDOException $exception): bool
    {
        $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = $exception->getMessage();
        return in_array($state, ['23000', '23505'], true)
            || str_contains($message, 'UNIQUE')
            || str_contains($message, 'Duplicate');
    }

    private function isApprovalKeyViolation(PDOException $exception): bool
    {
        if (!$this->isUniqueViolation($exception)) {
            return false;
        }
        $message = strtolower($exception->getMessage());
        return str_contains($message, 'approval_key')
            || str_contains($message, strtolower($this->indexName($this->drawingsTable, 'approval_key')));
    }

    private function indexName(string $table, string $purpose): string
    {
        return substr($table . '_' . $purpose, 0, 54) . '_' . substr(hash('sha256', $table . ':' . $purpose), 0, 8);
    }

    private function quoted(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function migrateSqlite(): void
    {
        if (!$this->sqliteTableExists($this->drawingsTable)) {
            $this->createSqliteDrawingsTable($this->drawingsTable);
        } elseif ($this->sqliteDrawingsNeedRebuild()) {
            $this->rebuildSqliteDrawingsTable();
        }
        $this->createSqliteFilesTable();
        if (!in_array('ocr_regions_json', $this->sqliteColumnNames($this->filesTable), true)) {
            $this->pdo->exec(sprintf(
                "ALTER TABLE %s ADD COLUMN ocr_regions_json TEXT NOT NULL DEFAULT '[]'",
                $this->quoted($this->filesTable)
            ));
        }
        $this->createSqliteCorrectionCommentsTable();

        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        try {
            $table = $this->quoted($this->drawingsTable);
            $this->pdo->exec("UPDATE {$table} SET approved_by = COALESCE(approved_by, created_by), approved_at = COALESCE(approved_at, created_at) WHERE approval_status = 'approved'");
            $this->pdo->exec("UPDATE {$table} SET approved_by = NULL, approved_at = NULL, approval_key = NULL WHERE approval_status = 'pending'");
            $this->pdo->exec("UPDATE {$table} SET row_version = 1 WHERE row_version < 1");
            $this->backfillApprovalKeys(false);
            $this->createSqliteIndexes();
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function sqliteDrawingsNeedRebuild(): bool
    {
        $required = [
            'id', 'drawing_no', 'title', 'revision_code', 'customer', 'material', 'process', 'process_metadata_json', 'owner_department',
            'notes', 'extraction_source', 'extraction_confidence', 'approval_status', 'approval_key', 'approved_by',
            'approved_at', 'row_version', 'created_by', 'updated_by', 'archived_at', 'created_at', 'updated_at',
        ];
        $columns = $this->sqliteColumnNames($this->drawingsTable);
        if (array_diff($required, $columns) !== []) {
            return true;
        }
        $indexList = $this->pdo->query("PRAGMA index_list('" . str_replace("'", "''", $this->drawingsTable) . "')")->fetchAll();
        foreach ($indexList as $index) {
            if ((int) ($index['unique'] ?? 0) !== 1) {
                continue;
            }
            $name = (string) ($index['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $info = $this->pdo->query("PRAGMA index_info('" . str_replace("'", "''", $name) . "')")->fetchAll();
            $indexedColumns = array_values(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $info));
            if ($indexedColumns === ['drawing_no']) {
                return true;
            }
        }
        return false;
    }

    private function rebuildSqliteDrawingsTable(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('SQLite図面スキーマの移行はトランザクション外で実行してください。');
        }
        $foreignKeysEnabled = (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1;
        if ($foreignKeysEnabled) {
            $this->pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $transactionStarted = false;
        try {
            // Recheck after obtaining an exclusive writer lock: another request may have migrated first.
            $this->pdo->exec('BEGIN EXCLUSIVE');
            $transactionStarted = true;
            if (!$this->sqliteDrawingsNeedRebuild()) {
                $this->pdo->exec('COMMIT');
                $transactionStarted = false;
                return;
            }

            $columns = $this->sqliteColumnNames($this->drawingsTable);
            $legacyRequired = [
                'id', 'drawing_no', 'title', 'revision_code', 'customer', 'material', 'process', 'owner_department',
                'notes', 'extraction_source', 'extraction_confidence', 'created_by', 'updated_by', 'archived_at',
                'created_at', 'updated_at',
            ];
            $missing = array_values(array_diff($legacyRequired, $columns));
            if ($missing !== []) {
                throw new RuntimeException('旧図面テーブルに必要な列がありません: ' . implode(', ', $missing));
            }
            if (in_array('approval_status', $columns, true)) {
                $invalid = (int) $this->pdo->query(sprintf(
                    "SELECT COUNT(*) FROM `%s` WHERE approval_status NOT IN ('pending', 'approved')",
                    $this->drawingsTable
                ))->fetchColumn();
                if ($invalid > 0) {
                    throw new RuntimeException('旧図面テーブルに不明な承認状態があります。');
                }
            }

            $temporaryTable = $this->drawingsTable . '_schema_v5';
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoted($temporaryTable));
            $this->createSqliteDrawingsTable($temporaryTable);

            $statusExpression = in_array('approval_status', $columns, true) ? 'approval_status' : "'approved'";
            $approvedByValue = in_array('approved_by', $columns, true) ? 'approved_by' : 'created_by';
            $approvedAtValue = in_array('approved_at', $columns, true) ? 'approved_at' : 'created_at';
            $rowVersionValue = in_array('row_version', $columns, true) ? 'row_version' : '1';
            $processMetadataValue = in_array('process_metadata_json', $columns, true) ? 'process_metadata_json' : "'[]'";
            $target = $this->quoted($temporaryTable);
            $source = $this->quoted($this->drawingsTable);
            $this->pdo->exec(<<<SQL
INSERT INTO {$target}
    (id, drawing_no, title, revision_code, customer, material, process, process_metadata_json, owner_department, notes,
     extraction_source, extraction_confidence, approval_status, approval_key, approved_by, approved_at,
     row_version, created_by, updated_by, archived_at, created_at, updated_at)
SELECT id, drawing_no, title, revision_code, customer, material, process, {$processMetadataValue}, owner_department, notes,
       extraction_source, extraction_confidence, {$statusExpression}, NULL,
       CASE WHEN {$statusExpression} = 'approved' THEN COALESCE({$approvedByValue}, created_by) ELSE NULL END,
       CASE WHEN {$statusExpression} = 'approved' THEN COALESCE({$approvedAtValue}, created_at) ELSE NULL END,
       CASE WHEN {$rowVersionValue} >= 1 THEN {$rowVersionValue} ELSE 1 END,
       created_by, updated_by, archived_at, created_at, updated_at
FROM {$source}
SQL);
            $this->pdo->exec('DROP TABLE ' . $source);
            $this->pdo->exec('ALTER TABLE ' . $target . ' RENAME TO ' . $this->quoted($this->drawingsTable));
            $this->backfillApprovalKeys(false);
            $this->assertSqliteForeignKeys();
            $this->pdo->exec('COMMIT');
            $transactionStarted = false;
        } catch (Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // Preserve the original migration error.
                }
            }
            throw $exception;
        } finally {
            if ($foreignKeysEnabled) {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
        }
        $this->assertSqliteForeignKeys();
    }

    private function assertSqliteForeignKeys(): void
    {
        foreach ([$this->drawingsTable, $this->filesTable, $this->correctionCommentsTable] as $tableName) {
            if (!$this->sqliteTableExists($tableName)) {
                continue;
            }
            $violations = $this->pdo->query("PRAGMA foreign_key_check('" . str_replace("'", "''", $tableName) . "')")->fetchAll();
            if ($violations !== []) {
                throw new RuntimeException('SQLite図面テーブルの外部キー整合性を確認できませんでした。');
            }
        }
    }

    private function createSqliteDrawingsTable(string $tableName): void
    {
        $table = $this->quoted($tableName);
        $users = $this->quoted($this->usersTable);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    drawing_no TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '',
    revision_code TEXT NOT NULL DEFAULT '',
    customer TEXT NOT NULL DEFAULT '',
    material TEXT NOT NULL DEFAULT '',
    process TEXT NOT NULL DEFAULT '',
    process_metadata_json TEXT NOT NULL DEFAULT '[]',
    owner_department TEXT NOT NULL DEFAULT '',
    notes TEXT NOT NULL DEFAULT '',
    extraction_source TEXT NOT NULL DEFAULT 'manual',
    extraction_confidence INTEGER NOT NULL DEFAULT 0,
    approval_status TEXT NOT NULL DEFAULT 'pending' CHECK (approval_status IN ('pending', 'approved')),
    approval_key TEXT NULL,
    approved_by INTEGER NULL REFERENCES {$users}(id),
    approved_at TEXT NULL,
    row_version INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL REFERENCES {$users}(id),
    updated_by INTEGER NOT NULL REFERENCES {$users}(id),
    archived_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    private function createSqliteFilesTable(): void
    {
        $files = $this->quoted($this->filesTable);
        $drawings = $this->quoted($this->drawingsTable);
        $users = $this->quoted($this->usersTable);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$files} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL REFERENCES {$drawings}(id) ON DELETE CASCADE,
    original_name TEXT NOT NULL,
    stored_name TEXT NOT NULL UNIQUE,
    mime_type TEXT NOT NULL,
    extension TEXT NOT NULL,
    size_bytes INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    ocr_regions_json TEXT NOT NULL DEFAULT '[]',
    uploaded_by INTEGER NOT NULL REFERENCES {$users}(id),
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    private function createSqliteCorrectionCommentsTable(): void
    {
        $comments = $this->quoted($this->correctionCommentsTable);
        $drawings = $this->quoted($this->drawingsTable);
        $users = $this->quoted($this->usersTable);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$comments} (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    drawing_id INTEGER NOT NULL REFERENCES {$drawings}(id) ON DELETE CASCADE,
    body TEXT NOT NULL,
    created_by INTEGER NOT NULL REFERENCES {$users}(id),
    resolved_by INTEGER NULL REFERENCES {$users}(id),
    resolved_at TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
    }

    private function createSqliteIndexes(): void
    {
        $drawings = $this->quoted($this->drawingsTable);
        $files = $this->quoted($this->filesTable);
        $comments = $this->quoted($this->correctionCommentsTable);
        $approvalIndex = $this->quoted($this->indexName($this->drawingsTable, 'approval_key'));
        $statusIndex = $this->quoted($this->indexName($this->drawingsTable, 'status_updated'));
        $updatedIndex = $this->quoted($this->indexName($this->drawingsTable, 'updated'));
        $documentIndex = $this->quoted($this->indexName($this->filesTable, 'document'));
        $commentDrawingIndex = $this->quoted($this->indexName($this->correctionCommentsTable, 'drawing_created'));
        $commentResolutionIndex = $this->quoted($this->indexName($this->correctionCommentsTable, 'drawing_resolution'));
        $this->pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS {$approvalIndex} ON {$drawings} (approval_key)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$statusIndex} ON {$drawings} (approval_status, updated_at, id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$updatedIndex} ON {$drawings} (updated_at, id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$documentIndex} ON {$files} (document_id, created_at)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$commentDrawingIndex} ON {$comments} (drawing_id, created_at, id)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS {$commentResolutionIndex} ON {$comments} (drawing_id, resolved_at, id)");
    }

    private function sqliteTableExists(string $table): bool
    {
        $statement = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    /** @return array<int, string> */
    private function sqliteColumnNames(string $table): array
    {
        $rows = $this->pdo->query("PRAGMA table_info('" . str_replace("'", "''", $table) . "')")->fetchAll();
        return array_values(array_map(static fn(array $row): string => (string) ($row['name'] ?? ''), $rows));
    }

    private function migrateMySql(): void
    {
        $lockName = 'openconcept_dwg_' . substr(hash('sha256', $this->drawingsTable), 0, 40);
        $acquire = $this->pdo->prepare('SELECT GET_LOCK(?, 30)');
        $acquire->execute([$lockName]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new RuntimeException('図面管理のMySQL移行ロックを取得できません。');
        }
        try {
            $this->migrateMySqlSchema();
        } finally {
            try {
                $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $exception) {
                error_log('Drawing manager MySQL migration lock release failed: ' . $exception->getMessage());
            }
        }
    }

    private function migrateMySqlSchema(): void
    {
        $this->createMySqlTables();
        if (!$this->mysqlColumnExists($this->drawingsTable, 'process_metadata_json')) {
            $drawings = $this->quoted($this->drawingsTable);
            $this->pdo->exec("ALTER TABLE {$drawings} ADD COLUMN process_metadata_json TEXT NULL AFTER process");
        }
        $drawings = $this->quoted($this->drawingsTable);
        $this->pdo->exec("UPDATE {$drawings} SET process_metadata_json = '[]', updated_at = updated_at WHERE process_metadata_json IS NULL OR process_metadata_json = ''");
        $this->pdo->exec("ALTER TABLE {$drawings} MODIFY process_metadata_json TEXT NOT NULL");
        if (!$this->mysqlColumnExists($this->filesTable, 'ocr_regions_json')) {
            $files = $this->quoted($this->filesTable);
            $this->pdo->exec("ALTER TABLE {$files} ADD COLUMN ocr_regions_json TEXT NULL AFTER sha256");
            $this->pdo->exec("UPDATE {$files} SET ocr_regions_json = '[]' WHERE ocr_regions_json IS NULL");
            $this->pdo->exec("ALTER TABLE {$files} MODIFY ocr_regions_json TEXT NOT NULL");
        }
        $statusWasMissing = !$this->mysqlColumnExists($this->drawingsTable, 'approval_status');
        $legacyApprovedMaxId = $this->mysqlMigrationCutoverId();
        if ($statusWasMissing && $legacyApprovedMaxId === null) {
            // Persist the cut-over before the first ALTER TABLE. MySQL DDL commits
            // implicitly, so this marker is required to resume safely after a crash.
            $this->createMySqlMigrationStateTable();
            $capturedMaxId = (int) $this->pdo->query(sprintf(
                'SELECT COALESCE(MAX(id), 0) FROM %s',
                $this->quoted($this->drawingsTable)
            ))->fetchColumn();
            $stateTable = $this->quoted($this->mysqlMigrationStateTable());
            $insertState = $this->pdo->prepare(
                "INSERT INTO {$stateTable} (migration_key, migration_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP) "
                . 'ON DUPLICATE KEY UPDATE migration_value = migration_value, updated_at = updated_at'
            );
            $insertState->execute(['schema_v3_legacy_max_id', (string) $capturedMaxId]);
            $legacyApprovedMaxId = $this->mysqlMigrationCutoverId();
            if ($legacyApprovedMaxId === null) {
                throw new RuntimeException('図面管理のMySQL移行境界を保存できませんでした。');
            }
        }
        $this->ensureMySqlColumn('approval_status', "VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER extraction_confidence");
        $this->ensureMySqlColumn('approval_key', 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER approval_status');
        $this->ensureMySqlColumn('approved_by', 'BIGINT UNSIGNED NULL AFTER approval_key');
        $this->ensureMySqlColumn('approved_at', 'TIMESTAMP NULL AFTER approved_by');
        $this->ensureMySqlColumn('row_version', 'BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER approved_at');

        $invalidStatus = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$drawings} WHERE approval_status NOT IN ('pending', 'approved')"
        )->fetchColumn();
        if ($invalidStatus > 0) {
            throw new RuntimeException('図面テーブルに不明な承認状態があります。');
        }
        if ($legacyApprovedMaxId !== null && $legacyApprovedMaxId > 0) {
            $approveLegacy = $this->pdo->prepare(
                "UPDATE {$drawings} SET approval_status = 'approved', updated_at = updated_at WHERE id <= ?"
            );
            $approveLegacy->execute([$legacyApprovedMaxId]);
        }
        $this->pdo->exec("UPDATE {$drawings} SET approved_by = COALESCE(approved_by, created_by), approved_at = COALESCE(approved_at, created_at), updated_at = updated_at WHERE approval_status = 'approved'");
        $this->pdo->exec("UPDATE {$drawings} SET approved_by = NULL, approved_at = NULL, approval_key = NULL, updated_at = updated_at WHERE approval_status = 'pending'");
        $this->pdo->exec("UPDATE {$drawings} SET row_version = 1, updated_at = updated_at WHERE row_version < 1");

        $this->backfillApprovalKeys(true);
        $approvalIndex = $this->indexName($this->drawingsTable, 'approval_key');
        if (!$this->mysqlHasUniqueIndexOnColumns($this->drawingsTable, ['approval_key'])) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD UNIQUE INDEX %s (approval_key)',
                $drawings,
                $this->quoted($approvalIndex)
            ));
        }
        foreach ($this->mysqlSingleColumnUniqueIndexes($this->drawingsTable, 'drawing_no') as $oldIndex) {
            $this->pdo->exec(sprintf('ALTER TABLE %s DROP INDEX %s', $drawings, $this->quoted($oldIndex)));
        }

        $statusIndex = $this->indexName($this->drawingsTable, 'status_updated');
        if (!$this->mysqlIndexExists($this->drawingsTable, $statusIndex)) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD INDEX %s (approval_status, updated_at, id)',
                $drawings,
                $this->quoted($statusIndex)
            ));
        }
        $approvedByIndex = $this->indexName($this->drawingsTable, 'approved_by');
        if (!$this->mysqlIndexExists($this->drawingsTable, $approvedByIndex)) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD INDEX %s (approved_by)',
                $drawings,
                $this->quoted($approvedByIndex)
            ));
        }
        $approverConstraint = $this->indexName($this->drawingsTable, 'approver_fk');
        if (!$this->mysqlConstraintExists($this->drawingsTable, $approverConstraint)) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (approved_by) REFERENCES %s(id)',
                $drawings,
                $this->quoted($approverConstraint),
                $this->quoted($this->usersTable)
            ));
        }
        $this->pdo->exec("ALTER TABLE {$drawings} MODIFY approval_status VARCHAR(16) NOT NULL DEFAULT 'pending'");
        $this->clearMySqlMigrationState();
    }

    private function createMySqlTables(): void
    {
        $drawings = $this->quoted($this->drawingsTable);
        $files = $this->quoted($this->filesTable);
        $users = $this->quoted($this->usersTable);
        $approvalIndex = $this->quoted($this->indexName($this->drawingsTable, 'approval_key'));
        $statusIndex = $this->quoted($this->indexName($this->drawingsTable, 'status_updated'));
        $updatedIndex = $this->quoted($this->indexName($this->drawingsTable, 'updated'));
        $approvedByIndex = $this->quoted($this->indexName($this->drawingsTable, 'approved_by'));
        $creatorFk = $this->quoted($this->indexName($this->drawingsTable, 'creator_fk'));
        $updaterFk = $this->quoted($this->indexName($this->drawingsTable, 'updater_fk'));
        $approverFk = $this->quoted($this->indexName($this->drawingsTable, 'approver_fk'));
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$drawings} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drawing_no VARCHAR(80) NOT NULL,
    title VARCHAR(160) NOT NULL,
    revision_code VARCHAR(40) NOT NULL DEFAULT '',
    customer VARCHAR(160) NOT NULL DEFAULT '',
    material VARCHAR(120) NOT NULL DEFAULT '',
    process VARCHAR(160) NOT NULL DEFAULT '',
    process_metadata_json TEXT NOT NULL,
    owner_department VARCHAR(120) NOT NULL DEFAULT '',
    notes TEXT NOT NULL,
    extraction_source VARCHAR(32) NOT NULL DEFAULT 'manual',
    extraction_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
    approval_status VARCHAR(16) NOT NULL DEFAULT 'pending',
    approval_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at TIMESTAMP NULL,
    row_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    archived_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY {$approvalIndex} (approval_key),
    INDEX {$statusIndex} (approval_status, updated_at, id),
    INDEX {$updatedIndex} (updated_at, id),
    INDEX {$approvedByIndex} (approved_by),
    CONSTRAINT {$creatorFk} FOREIGN KEY (created_by) REFERENCES {$users}(id),
    CONSTRAINT {$updaterFk} FOREIGN KEY (updated_by) REFERENCES {$users}(id),
    CONSTRAINT {$approverFk} FOREIGN KEY (approved_by) REFERENCES {$users}(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $storedNameIndex = $this->quoted($this->indexName($this->filesTable, 'stored_name'));
        $documentIndex = $this->quoted($this->indexName($this->filesTable, 'document'));
        $documentFk = $this->quoted($this->indexName($this->filesTable, 'document_fk'));
        $uploaderFk = $this->quoted($this->indexName($this->filesTable, 'uploader_fk'));
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$files} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(96) NOT NULL,
    mime_type VARCHAR(160) NOT NULL,
    extension VARCHAR(12) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ocr_regions_json TEXT NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY {$storedNameIndex} (stored_name),
    INDEX {$documentIndex} (document_id, created_at),
    CONSTRAINT {$documentFk} FOREIGN KEY (document_id) REFERENCES {$drawings}(id) ON DELETE CASCADE,
    CONSTRAINT {$uploaderFk} FOREIGN KEY (uploaded_by) REFERENCES {$users}(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $comments = $this->quoted($this->correctionCommentsTable);
        $commentDrawingIndex = $this->quoted($this->indexName($this->correctionCommentsTable, 'drawing_created'));
        $commentResolutionIndex = $this->quoted($this->indexName($this->correctionCommentsTable, 'drawing_resolution'));
        $commentDrawingFk = $this->quoted($this->indexName($this->correctionCommentsTable, 'drawing_fk'));
        $commentCreatorFk = $this->quoted($this->indexName($this->correctionCommentsTable, 'creator_fk'));
        $commentResolverFk = $this->quoted($this->indexName($this->correctionCommentsTable, 'resolver_fk'));
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$comments} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    drawing_id BIGINT UNSIGNED NOT NULL,
    body TEXT NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    resolved_by BIGINT UNSIGNED NULL,
    resolved_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX {$commentDrawingIndex} (drawing_id, created_at, id),
    INDEX {$commentResolutionIndex} (drawing_id, resolved_at, id),
    CONSTRAINT {$commentDrawingFk} FOREIGN KEY (drawing_id) REFERENCES {$drawings}(id) ON DELETE CASCADE,
    CONSTRAINT {$commentCreatorFk} FOREIGN KEY (created_by) REFERENCES {$users}(id),
    CONSTRAINT {$commentResolverFk} FOREIGN KEY (resolved_by) REFERENCES {$users}(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function ensureMySqlColumn(string $column, string $definition): void
    {
        if ($this->mysqlColumnExists($this->drawingsTable, $column)) {
            return;
        }
        $this->pdo->exec(sprintf(
            'ALTER TABLE %s ADD COLUMN %s %s',
            $this->quoted($this->drawingsTable),
            $this->quoted($column),
            $definition
        ));
    }

    private function mysqlColumnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1'
        );
        $statement->execute([$table, $column]);
        return $statement->fetchColumn() !== false;
    }

    private function mysqlMigrationStateTable(): string
    {
        return substr($this->drawingsTable . '_migration_state', 0, 54)
            . '_'
            . substr(hash('sha256', $this->drawingsTable . ':migration_state'), 0, 8);
    }

    private function createMySqlMigrationStateTable(): void
    {
        $table = $this->quoted($this->mysqlMigrationStateTable());
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    migration_key VARCHAR(64) NOT NULL PRIMARY KEY,
    migration_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    private function mysqlMigrationCutoverId(): ?int
    {
        $tableName = $this->mysqlMigrationStateTable();
        if (!$this->mysqlTableExists($tableName)) {
            return null;
        }
        $statement = $this->pdo->prepare(sprintf(
            'SELECT migration_value FROM %s WHERE migration_key = ? LIMIT 1',
            $this->quoted($tableName)
        ));
        $statement->execute(['schema_v3_legacy_max_id']);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return null;
        }
        $value = (string) $value;
        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new RuntimeException('図面管理のMySQL移行境界が正しくありません。');
        }
        return (int) $value;
    }

    private function clearMySqlMigrationState(): void
    {
        $tableName = $this->mysqlMigrationStateTable();
        if (!$this->mysqlTableExists($tableName)) {
            return;
        }
        $table = $this->quoted($tableName);
        $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE migration_key = ?");
        $delete->execute(['schema_v3_legacy_max_id']);
        $remaining = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        if ($remaining === 0) {
            $this->pdo->exec("DROP TABLE {$table}");
        }
    }

    private function mysqlTableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
    }

    private function mysqlIndexExists(string $table, string $index): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1'
        );
        $statement->execute([$table, $index]);
        return $statement->fetchColumn() !== false;
    }

    /** @param array<int, string> $columns */
    private function mysqlHasUniqueIndexOnColumns(string $table, array $columns): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS indexed_columns "
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 GROUP BY index_name'
        );
        $statement->execute([$table]);
        $expected = implode(',', $columns);
        foreach ($statement->fetchAll() as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ((string) ($row['indexed_columns'] ?? '') === $expected) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string> */
    private function mysqlSingleColumnUniqueIndexes(string $table, string $column): array
    {
        $statement = $this->pdo->prepare(
            "SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS indexed_columns "
            . 'FROM information_schema.statistics '
            . "WHERE table_schema = DATABASE() AND table_name = ? AND non_unique = 0 AND index_name <> 'PRIMARY' "
            . 'GROUP BY index_name'
        );
        $statement->execute([$table]);
        $indexes = [];
        foreach ($statement->fetchAll() as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ((string) ($row['indexed_columns'] ?? '') === $column) {
                $indexes[] = (string) $row['index_name'];
            }
        }
        return $indexes;
    }

    private function mysqlConstraintExists(string $table, string $constraint): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.referential_constraints '
            . 'WHERE constraint_schema = DATABASE() AND table_name = ? AND constraint_name = ? LIMIT 1'
        );
        $statement->execute([$table, $constraint]);
        return $statement->fetchColumn() !== false;
    }

    private function backfillApprovalKeys(bool $manageTransaction): void
    {
        $rows = $this->pdo->query(sprintf(
            'SELECT id, drawing_no, title, revision_code, approval_status, approval_key, archived_at FROM `%s` ORDER BY id',
            $this->drawingsTable
        ))->fetchAll();
        $seen = [];
        $updates = [];
        foreach ($rows as $row) {
            $status = (string) ($row['approval_status'] ?? '');
            if (!in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED], true)) {
                throw new RuntimeException('図面テーブルに不明な承認状態があります。');
            }
            $expected = null;
            if ($status === self::STATUS_APPROVED && ($row['archived_at'] ?? null) === null) {
                $drawingNo = $this->clean((string) ($row['drawing_no'] ?? ''), 80);
                $title = $this->clean((string) ($row['title'] ?? ''), 160);
                if ($drawingNo === '' || $title === '') {
                    throw new RuntimeException('承認済み図面に図面番号または品名がありません。');
                }
                $expected = $this->approvalKey($drawingNo, $this->clean((string) ($row['revision_code'] ?? ''), 40));
                if (isset($seen[$expected])) {
                    throw new RuntimeException(sprintf(
                        '既存の承認済み図面ID %d と %d の図面番号・改訂番号が重複しています。',
                        $seen[$expected],
                        (int) $row['id']
                    ));
                }
                $seen[$expected] = (int) $row['id'];
            }
            $current = $row['approval_key'] === null ? null : (string) $row['approval_key'];
            if ($current !== $expected) {
                $updates[] = ['id' => (int) $row['id'], 'key' => $expected];
            }
        }
        if ($updates === []) {
            return;
        }

        $startedTransaction = false;
        if ($manageTransaction && !$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        try {
            $update = $this->pdo->prepare(sprintf('UPDATE `%s` SET approval_key = ?, updated_at = updated_at WHERE id = ?', $this->drawingsTable));
            foreach ($updates as $item) {
                $update->execute([$item['key'], $item['id']]);
            }
            if ($startedTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
