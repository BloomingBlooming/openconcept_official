<?php

declare(strict_types=1);

final class DrawingController
{
    private const PREFIX = 'plugin-drawing-manager-';
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'dxf', 'dwg', 'step', 'stp', 'iges', 'igs'];
    private const VISION_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    private PDO $pdo;

    public function __construct(
        private DrawingRepository $repository,
        private DrawingMetadataExtractor $extractor,
        private string $storagePath
    ) {
        $this->pdo = $repository->pdo();
    }

    /** @param array<string, mixed> $user */
    public function handle(
        string $action,
        string $method,
        array $user,
        string $passwordFingerprint
    ): void
    {
        if (!str_starts_with($action, self::PREFIX)) {
            return;
        }

        try {
            if ($action === self::PREFIX . 'list' && $method === 'GET') {
                $result = $this->authenticatedRead(
                    $user,
                    $passwordFingerprint,
                    function (array $actor): array {
                        $canEdit = $this->canEdit($actor);
                        $status = $canEdit ? (string) ($_GET['status'] ?? 'all') : 'approved';
                        $page = $this->listPage($_GET['page'] ?? 1);
                        $perPage = $this->listPageSize($_GET['per_page'] ?? 20);
                        $result = $this->repository->listDrawings(
                            (string) ($_GET['q'] ?? ''),
                            $status,
                            $page,
                            $perPage
                        );
                        if (!$canEdit) {
                            $approved = (int) ($result['counts']['approved'] ?? count($result['drawings'] ?? []));
                            $result['counts'] = ['total' => $approved, 'pending' => 0, 'approved' => $approved];
                        }
                        return $result;
                    }
                );
                $this->respond($result);
            }
            if ($action === self::PREFIX . 'detail' && $method === 'GET') {
                $drawing = $this->authenticatedRead(
                    $user,
                    $passwordFingerprint,
                    function (array $actor): array {
                        $drawing = $this->repository->getDrawing($this->positiveId($_GET['id'] ?? 0));
                        $this->assertCanViewDrawing($drawing, $actor);
                        return $drawing;
                    }
                );
                $this->respond(['drawing' => $drawing]);
            }
            if ($action === self::PREFIX . 'comments' && $method === 'GET') {
                $comments = $this->authenticatedRead(
                    $user,
                    $passwordFingerprint,
                    function (array $actor): array {
                        $drawingId = $this->positiveId($_GET['id'] ?? 0);
                        $drawing = $this->repository->getDrawing($drawingId);
                        $this->assertCanViewDrawing($drawing, $actor);
                        return $this->repository->listCorrectionComments($drawingId);
                    }
                );
                $this->respond(['comments' => $comments]);
            }
            if ($action === self::PREFIX . 'file' && $method === 'GET') {
                $this->streamFile(
                    $this->positiveId($_GET['id'] ?? 0),
                    $user,
                    $passwordFingerprint
                );
            }
            if ($method !== 'POST') {
                $this->respond(['error' => '対応していない操作です。'], 405);
            }

            requireCsrf();
            if ($action === self::PREFIX . 'comment-create') {
                $this->createCorrectionComment($user, $passwordFingerprint);
            }
            if ($action === self::PREFIX . 'update') {
                $this->updateDrawing($user, $passwordFingerprint);
            }
            if ($action === self::PREFIX . 'comment-resolve') {
                $this->resolveCorrectionComment($user, $passwordFingerprint);
            }
            if ($action === self::PREFIX . 'upload') {
                $this->uploadDrawing($user, $passwordFingerprint);
            }
            if ($action === self::PREFIX . 'approve') {
                $this->approveDrawing($user, $passwordFingerprint);
            }
            if ($action === self::PREFIX . 'archive') {
                $this->archiveDrawing($user, $passwordFingerprint);
            }
            $this->respond(['error' => '図面管理APIが見つかりません。'], 404);
        } catch (OutOfBoundsException $exception) {
            $this->respond(['error' => $exception->getMessage()], 404);
        } catch (UnexpectedValueException $exception) {
            $this->respond([
                'error' => $exception->getMessage(),
                'code' => $this->conflictCode($exception->getMessage()),
            ], 409);
        } catch (InvalidArgumentException $exception) {
            $this->respond(['error' => $exception->getMessage()], 422);
        } catch (PDOException $exception) {
            $message = $exception->getMessage();
            if (str_contains(strtolower($message), 'database is locked') || str_contains(strtolower($message), 'deadlock')) {
                $this->respond([
                    'error' => '図面情報が別の操作で更新中です。最新状態を確認してもう一度お試しください。',
                    'code' => 'drawing_conflict',
                ], 409);
            }
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate')) {
                $this->respond([
                    'error' => '同じ図面番号と改訂番号の承認済み図面があります。内容を確認してください。',
                    'code' => 'drawing_duplicate',
                ], 409);
            }
            error_log('Drawing manager database error: ' . $message);
            $this->respond(['error' => '図面情報を保存できませんでした。'], 500);
        } catch (RuntimeException $exception) {
            if (in_array($exception->getCode(), [401, 403], true)) {
                $this->respond(['error' => $exception->getMessage()], $exception->getCode());
            }
            error_log('Drawing manager error: ' . $exception->getMessage());
            $this->respond(['error' => '図面管理処理を完了できませんでした。'], 500);
        } catch (Throwable $exception) {
            error_log('Drawing manager error: ' . $exception->getMessage());
            $this->respond(['error' => '図面管理処理を完了できませんでした。'], 500);
        }
    }

    /** @param array<string, mixed> $user */
    private function uploadDrawing(array $user, string $passwordFingerprint): never
    {
        $this->authenticatedRead(
            $user,
            $passwordFingerprint,
            function (array $actor): bool {
                $this->assertAllowedRoles($actor, ['admin', 'editor']);
                return true;
            }
        );
        $upload = $this->validatedUpload();
        if (in_array($upload['extension'], self::VISION_EXTENSIONS, true)) {
            $this->enforceVisionRateLimit();
        }
        $this->ensureStorage();

        $storedName = bin2hex(random_bytes(24)) . '.' . $upload['extension'];
        $destination = $this->storagePath . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($upload['temporary_path'], $destination)) {
            $this->respond(['error' => '図面ファイルを保存できませんでした。'], 500);
        }
        $removeFileOnShutdown = true;
        register_shutdown_function(static function () use ($destination, &$removeFileOnShutdown): void {
            if ($removeFileOnShutdown && is_file($destination)) {
                @unlink($destination);
            }
        });

        try {
            $sha256 = hash_file('sha256', $destination);
            if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException('図面ファイルの整合性を確認できませんでした。');
            }
            $mimeType = $this->detectMimeType($destination);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            // Re-authenticate from a fresh database snapshot immediately before
            // every external request attempt, including retries.
            $beforeVisionSend = function () use ($user, $passwordFingerprint): void {
                $this->authenticatedRead(
                    $user,
                    $passwordFingerprint,
                    function (array $actor): bool {
                        $this->assertAllowedRoles($actor, ['admin', 'editor']);
                        return true;
                    }
                );
            };
            $extraction = $this->extractSafely(
                $destination,
                $upload['original_name'],
                $upload['extension'],
                $mimeType,
                $beforeVisionSend
            );
            $fields = is_array($extraction['fields'] ?? null) ? $extraction['fields'] : [];
            $write = $this->repository->authenticatedWrite(
                $user,
                $passwordFingerprint,
                ['admin', 'editor'],
                function (array $actor) use ($fields, $extraction, $upload, $storedName, $mimeType, $sha256): array {
                    $drawing = $this->repository->createDraftWithFile([
                        'drawing_no' => (string) ($fields['drawing_no'] ?? ''),
                        'revision_code' => (string) ($fields['revision_code'] ?? ''),
                        'title' => (string) ($fields['title'] ?? ''),
                        'customer' => '',
                        'material' => '',
                        'process' => '',
                        'process_metadata' => [],
                        'owner_department' => '',
                        'notes' => '',
                        'extraction_source' => (string) ($extraction['source'] ?? 'unavailable'),
                        'extraction_confidence' => (int) ($extraction['confidence'] ?? 0),
                    ], [
                        'original_name' => $upload['original_name'],
                        'stored_name' => $storedName,
                        'mime_type' => $mimeType,
                        'extension' => $upload['extension'],
                        'size_bytes' => $upload['size_bytes'],
                        'sha256' => $sha256,
                        'ocr_regions' => is_array($extraction['ocr_regions'] ?? null) ? $extraction['ocr_regions'] : [],
                    ], (int) $actor['id']);
                    $this->recordAudit($actor, 'drawing_uploaded_pending', (int) $drawing['id'], [
                        'drawing_no' => $drawing['drawing_no'],
                        'revision_code' => $drawing['revision_code'],
                        'title' => $drawing['title'],
                        'file_name' => $upload['original_name'],
                        'sha256' => $sha256,
                        'extraction_source' => $drawing['extraction_source'],
                        'model' => (string) ($extraction['model'] ?? ''),
                        'response_id' => (string) ($extraction['response_id'] ?? ''),
                        'ai_fields' => is_array($extraction['ai_fields'] ?? null) ? array_values($extraction['ai_fields']) : [],
                        'ocr_region_count' => count(is_array($extraction['ocr_regions'] ?? null) ? $extraction['ocr_regions'] : []),
                    ]);
                    return ['drawing' => $drawing];
                }
            );
            $drawing = $write['drawing'];
            $removeFileOnShutdown = false;
        } catch (Throwable $exception) {
            @unlink($destination);
            throw $exception;
        }

        $this->respond([
            'ok' => true,
            'drawing' => $drawing,
            'extraction' => [
                'source' => (string) ($extraction['source'] ?? 'unavailable'),
                'confidence' => (int) ($extraction['confidence'] ?? 0),
                'warnings' => array_values(array_filter(
                    is_array($extraction['warnings'] ?? null) ? $extraction['warnings'] : [],
                    'is_string'
                )),
                'warning_items' => array_values(array_filter(
                    is_array($extraction['warning_items'] ?? null) ? $extraction['warning_items'] : [],
                    'is_array'
                )),
                'ai_fields' => is_array($extraction['ai_fields'] ?? null) ? array_values($extraction['ai_fields']) : [],
                'ocr_region_count' => count(is_array($extraction['ocr_regions'] ?? null) ? $extraction['ocr_regions'] : []),
            ],
        ], 201);
    }

    /** @param array<string, mixed> $user */
    private function approveDrawing(array $user, string $passwordFingerprint): never
    {
        $body = $this->jsonBody();
        if (!in_array($body['confirmed'] ?? false, [true, 1, '1'], true)) {
            throw new InvalidArgumentException('図面番号・改訂番号・品名を図面と照合したことを確認してください。');
        }

        $drawingId = $this->positiveId($body['id'] ?? 0);
        $expectedRowVersion = $this->positiveId($body['expected_row_version'] ?? 0);
        $write = $this->repository->authenticatedWrite(
            $user,
            $passwordFingerprint,
            ['admin', 'editor'],
            function (array $actor) use ($drawingId, $body, $expectedRowVersion): array {
                $before = $this->repository->getDrawing($drawingId);
                if ((string) ($before['approval_status'] ?? '') !== 'pending') {
                    throw new UnexpectedValueException('この図面は既に承認済みです。再読み込みしてください。');
                }
                $drawing = $this->repository->approveDrawing(
                    $drawingId,
                    $body,
                    (int) $actor['id'],
                    $expectedRowVersion
                );
                $files = is_array($drawing['files'] ?? null) ? $drawing['files'] : [];
                if ($files === []) {
                    throw new UnexpectedValueException('承認する図面ファイルが見つかりません。');
                }
                foreach ($files as $file) {
                    if (!is_array($file)) {
                        throw new UnexpectedValueException('承認する図面ファイルの情報が正しくありません。');
                    }
                    $this->verifiedFilePath($file);
                }
                $this->recordAudit($actor, 'drawing_approved', $drawingId, [
                    'before' => [
                        'drawing_no' => $before['drawing_no'],
                        'revision_code' => $before['revision_code'],
                        'title' => $before['title'],
                        'process' => $before['process'],
                        'process_metadata' => $before['process_metadata'],
                    ],
                    'after' => [
                        'drawing_no' => $drawing['drawing_no'],
                        'revision_code' => $drawing['revision_code'],
                        'title' => $drawing['title'],
                        'process' => $drawing['process'],
                        'process_metadata' => $drawing['process_metadata'],
                    ],
                ]);
                return ['drawing' => $drawing];
            }
        );
        $drawing = $write['drawing'];
        $this->respond(['ok' => true, 'drawing' => $drawing]);
    }

    /** @param array<string, mixed> $user */
    private function updateDrawing(array $user, string $passwordFingerprint): never
    {
        $body = $this->jsonBody();
        $drawingId = $this->positiveId($body['id'] ?? 0);
        $expectedRowVersion = $this->positiveId($body['expected_row_version'] ?? 0);
        $fields = $body['fields'] ?? null;
        if (!is_array($fields) || array_is_list($fields)) {
            throw new InvalidArgumentException('更新する図面詳細の入力形式が正しくありません。');
        }
        $write = $this->repository->authenticatedWrite(
            $user,
            $passwordFingerprint,
            ['admin'],
            function (array $actor) use ($drawingId, $fields, $expectedRowVersion): array {
                $before = $this->repository->getDrawing($drawingId);
                $drawing = $this->repository->updateDrawing(
                    $drawingId,
                    $fields,
                    (int) $actor['id'],
                    $expectedRowVersion
                );
                $this->recordAudit($actor, 'drawing_updated', $drawingId, [
                    'before' => $this->drawingAuditFields($before),
                    'after' => $this->drawingAuditFields($drawing),
                ]);
                return ['drawing' => $drawing];
            }
        );
        $drawing = $write['drawing'];
        $this->respond(['ok' => true, 'drawing' => $drawing]);
    }

    /** @param array<string, mixed> $user */
    private function createCorrectionComment(array $user, string $passwordFingerprint): never
    {
        $body = $this->jsonBody();
        $drawingId = $this->positiveId($body['drawing_id'] ?? 0);
        if (!is_string($body['body'] ?? null)) {
            throw new InvalidArgumentException('誤りと訂正内容を入力してください。');
        }
        $write = $this->repository->authenticatedWrite(
            $user,
            $passwordFingerprint,
            [],
            function (array $actor) use ($drawingId, $body): array {
                $comment = $this->repository->createCorrectionComment(
                    $drawingId,
                    $body['body'],
                    (int) $actor['id'],
                    $this->canEdit($actor)
                );
                $this->recordAudit($actor, 'drawing_correction_comment_created', $drawingId, [
                    'comment_id' => $comment['id'],
                ]);
                return ['comment' => $comment];
            }
        );
        $comment = $write['comment'];
        $this->respond(['ok' => true, 'comment' => $comment], 201);
    }

    /** @param array<string, mixed> $user */
    private function resolveCorrectionComment(array $user, string $passwordFingerprint): never
    {
        $body = $this->jsonBody();
        $commentId = $this->positiveId($body['comment_id'] ?? 0);
        $write = $this->repository->authenticatedWrite(
            $user,
            $passwordFingerprint,
            ['admin'],
            function (array $actor) use ($commentId): array {
                $comment = $this->repository->resolveCorrectionComment($commentId, (int) $actor['id']);
                $this->recordAudit($actor, 'drawing_correction_comment_resolved', (int) $comment['drawing_id'], [
                    'comment_id' => $comment['id'],
                ]);
                return ['comment' => $comment];
            }
        );
        $comment = $write['comment'];
        $this->respond(['ok' => true, 'comment' => $comment]);
    }

    /** @param array<string, mixed> $user */
    private function archiveDrawing(array $user, string $passwordFingerprint): never
    {
        $body = $this->jsonBody();
        if (!in_array($body['confirmed'] ?? false, [true, 1, '1'], true)) {
            throw new InvalidArgumentException('図面の削除はアプリ上から復元できません。警告を確認してください。');
        }

        $drawingId = $this->positiveId($body['id'] ?? 0);
        $expectedRowVersion = $this->positiveId($body['expected_row_version'] ?? 0);
        $this->repository->authenticatedWrite(
            $user,
            $passwordFingerprint,
            ['admin', 'editor'],
            function (array $actor) use ($drawingId, $expectedRowVersion): array {
                $before = $this->repository->getDrawing($drawingId);
                $this->repository->archiveDrawing(
                    $drawingId,
                    (int) $actor['id'],
                    $expectedRowVersion
                );
                $this->recordAudit($actor, 'drawing_archived', $drawingId, [
                    'drawing_no' => $before['drawing_no'],
                    'revision_code' => $before['revision_code'],
                    'title' => $before['title'],
                    'approval_status' => $before['approval_status'],
                    'row_version' => $before['row_version'],
                ]);
                return [];
            }
        );
        $this->respond(['ok' => true, 'archived' => true, 'id' => $drawingId]);
    }

    /** @param array<string, mixed> $user */
    private function streamFile(int $fileId, array $user, string $passwordFingerprint): never
    {
        $materialized = $this->authenticatedRead(
            $user,
            $passwordFingerprint,
            function (array $actor) use ($fileId): array {
                $file = $this->repository->getFile($fileId);
                $approvalStatus = (string) ($file['approval_status'] ?? '');
                if ($approvalStatus !== 'approved' && !$this->canEdit($actor)) {
                    throw new OutOfBoundsException('図面ファイルが見つかりません。');
                }
                return ['file' => $file, 'path' => $this->verifiedFilePath($file)];
            }
        );
        $file = $materialized['file'];
        $path = $materialized['path'];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $name = str_replace(["\r", "\n", '"'], '', (string) $file['original_name']);
        $inline = in_array((string) $file['extension'], ['pdf', 'png', 'jpg', 'jpeg', 'webp'], true);
        header('Content-Type: ' . (string) $file['mime_type']);
        header('Content-Length: ' . (string) filesize($path));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        // Drawing access can be revoked by archiving, so browsers must not reuse
        // a previously authorized response after the document becomes hidden.
        header('Cache-Control: private, no-store, max-age=0');
        header(sprintf("Content-Disposition: %s; filename*=UTF-8''%s", $inline ? 'inline' : 'attachment', rawurlencode($name)));
        readfile($path);
        exit;
    }

    /** @return array{temporary_path: string, original_name: string, extension: string, size_bytes: int} */
    private function validatedUpload(): array
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new InvalidArgumentException('アップロードする図面ファイルを選択してください。');
        }
        $upload = $_FILES['file'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('図面ファイルをアップロードできませんでした。');
        }
        $temporaryPath = (string) ($upload['tmp_name'] ?? '');
        if (!is_uploaded_file($temporaryPath)) {
            throw new InvalidArgumentException('アップロードファイルを確認できません。');
        }

        $originalName = $this->clean(basename(str_replace('\\', '/', (string) ($upload['name'] ?? ''))), 255);
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($originalName === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException('対応形式はPDF、画像、DXF、DWG、STEP、IGESです。');
        }
        $actualSize = filesize($temporaryPath);
        $size = is_int($actualSize) ? $actualSize : (int) ($upload['size'] ?? 0);
        $maxMegabytes = $this->uploadLimitMegabytes();
        if ($size < 1 || $size > $maxMegabytes * 1024 * 1024) {
            throw new InvalidArgumentException(sprintf('ファイルは%dMB以下にしてください。', $maxMegabytes));
        }
        return [
            'temporary_path' => $temporaryPath,
            'original_name' => $originalName,
            'extension' => $extension,
            'size_bytes' => $size,
        ];
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0770, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('図面保存先を作成できませんでした。');
        }
    }

    private function detectMimeType(string $path): string
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }
        $detected = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    /**
     * @return array{fields: array<string, string>, source: string, confidence: int, warnings: array<int, string>, warning_items?: array<int, array{key: string, parameters: array<string, int|string>}>, model?: string, response_id?: string, ai_fields?: array<int, string>, ocr_regions?: array<int, array<string, int|string>>}
     */
    private function extractSafely(
        string $path,
        string $originalName,
        string $extension,
        string $mimeType,
        callable $beforeSend
    ): array
    {
        try {
            return $this->extractor->extract($path, $originalName, $extension, $mimeType, $beforeSend);
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && in_array($exception->getCode(), [401, 403], true)) {
                throw $exception;
            }
            error_log('Drawing manager metadata extraction failed: ' . $exception->getMessage());
            try {
                $fallback = (new DrawingMetadataExtractor())->extract(
                    $path,
                    $originalName,
                    $extension,
                    $mimeType,
                    $beforeSend
                );
                $fallback['warnings'][] = 'AI読み取りを完了できなかったため、ファイル名候補を未承認図面へ保存しました。';
                $fallback['warning_items'][] = ['key' => 'warning.fallbackFilename', 'parameters' => []];
                $fallback['warnings'] = array_values(array_unique($fallback['warnings']));
                return $fallback;
            } catch (Throwable) {
                // Keep the uploaded file as an empty draft even if fallback extraction also fails.
            }
            return [
                'fields' => [
                    'drawing_no' => '',
                    'revision_code' => '',
                    'title' => '',
                    'customer' => '',
                    'material' => '',
                    'process' => '',
                ],
                'source' => 'unavailable',
                'confidence' => 0,
                'warnings' => ['AI読み取りを完了できませんでした。未承認図面で3項目を入力してください。'],
                'warning_items' => [['key' => 'warning.manualRequired', 'parameters' => []]],
                'model' => '',
                'response_id' => '',
                'ai_fields' => [],
                'ocr_regions' => [],
            ];
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(): array
    {
        if (function_exists('bodyJson')) {
            return bodyJson();
        }
        $body = json_decode((string) file_get_contents('php://input'), true);
        return is_array($body) ? $body : [];
    }

    /**
     * Re-authenticates the request actor and materializes every drawing value
     * used for authorization or response inside the same database snapshot.
     *
     * @param array<string, mixed> $requestUser
     */
    private function authenticatedRead(
        array $requestUser,
        string $expectedPasswordFingerprint,
        callable $operation
    ): mixed {
        if (!function_exists('beginConsistentPageRead')
            || !function_exists('authenticatedApplicationUserForReadSnapshot')) {
            throw new LogicException('Drawing reads require the application authentication helpers.');
        }
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $actor = authenticatedApplicationUserForReadSnapshot(
                $this->pdo,
                $requestUser,
                $expectedPasswordFingerprint
            );
            if (!is_array($actor)) {
                throw new RuntimeException('The login state changed. Sign in again.', 401);
            }
            $result = $operation($actor);
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $actor @param array<int, string> $allowedRoles */
    private function assertAllowedRoles(array $actor, array $allowedRoles): void
    {
        if (!in_array((string) ($actor['role'] ?? ''), $allowedRoles, true)) {
            throw new RuntimeException('You do not have permission to change this drawing.', 403);
        }
    }

    /** @param array<string, mixed> $drawing @param array<string, mixed> $user */
    private function assertCanViewDrawing(array $drawing, array $user): void
    {
        if ((string) ($drawing['approval_status'] ?? '') !== 'approved' && !$this->canEdit($user)) {
            throw new OutOfBoundsException('図面が見つかりません。');
        }
    }

    /** @param array<string, mixed> $file */
    private function verifiedFilePath(array $file): string
    {
        $storedName = (string) ($file['stored_name'] ?? '');
        $expectedHash = strtolower((string) ($file['sha256'] ?? ''));
        if ($storedName === '' || basename($storedName) !== $storedName || preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new UnexpectedValueException('保存された図面ファイルの情報を確認できません。');
        }
        $path = $this->storagePath . DIRECTORY_SEPARATOR . $storedName;
        $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($actualHash) || !hash_equals($expectedHash, strtolower($actualHash))) {
            throw new UnexpectedValueException('保存された図面ファイルが欠落または変更されています。承認・表示を中止しました。');
        }
        return $path;
    }

    /** @param array<string, mixed> $user */
    private function canEdit(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), ['admin', 'editor'], true);
    }

    private function positiveId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($id)) {
            throw new InvalidArgumentException('対象IDが正しくありません。');
        }
        return $id;
    }

    private function listPage(mixed $value): int
    {
        $page = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($page)) {
            throw new InvalidArgumentException('図面一覧のページ番号が正しくありません。');
        }
        return $page;
    }

    private function listPageSize(mixed $value): int
    {
        $perPage = filter_var($value, FILTER_VALIDATE_INT);
        if (!is_int($perPage) || !in_array($perPage, [20, 50, 100], true)) {
            throw new InvalidArgumentException('図面一覧の表示件数が正しくありません。');
        }
        return $perPage;
    }

    private function uploadLimitMegabytes(): int
    {
        return max(1, min(200, (int) (getenv('OPENCONCEPT_DRAWING_MAX_MB') ?: 50)));
    }

    private function conflictCode(string $message): string
    {
        if (str_contains($message, '同じ図面番号と改訂番号')) {
            return 'drawing_duplicate';
        }
        if (str_contains($message, '既に承認済み')) {
            return 'drawing_already_approved';
        }
        if (str_contains($message, '図面ファイル')) {
            return 'drawing_file_integrity';
        }
        return 'drawing_conflict';
    }

    private function enforceVisionRateLimit(): void
    {
        $now = time();
        $limit = max(1, min(30, (int) (getenv('OPENCONCEPT_DRAWING_VISION_RATE_LIMIT') ?: 6)));
        $key = 'drawing_manager_vision_rate';
        $attempts = array_values(array_filter(
            is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [],
            static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
        ));
        if (count($attempts) >= $limit) {
            header('Retry-After: 60');
            $this->respond(['error' => '図面のAI読み取りが続いています。少し待ってからもう一度お試しください。'], 429);
        }
        $attempts[] = $now;
        $_SESSION[$key] = $attempts;
    }

    private function clean(string $value, int $max): string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    /** @param array<string, mixed> $drawing @return array<string, mixed> */
    private function drawingAuditFields(array $drawing): array
    {
        $result = [];
        foreach ([
            'drawing_no', 'revision_code', 'title', 'customer', 'material', 'process',
            'process_metadata', 'owner_department', 'notes', 'approval_status', 'row_version',
        ] as $field) {
            $result[$field] = $drawing[$field] ?? null;
        }
        return $result;
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $detail */
    private function recordAudit(array $user, string $action, int $drawingId, array $detail = []): void
    {
        if (!function_exists('audit')) {
            throw new LogicException('Drawing writes require the application audit helper.');
        }
        audit($this->repository->pdo(), (int) $user['id'], $action, 'drawing', $drawingId, $detail);
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, int $status = 200): never
    {
        jsonResponse($data, $status);
    }
}
