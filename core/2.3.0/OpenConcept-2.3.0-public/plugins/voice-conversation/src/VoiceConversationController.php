<?php

declare(strict_types=1);

final class VoiceConversationController
{
    public function __construct(
        private VoiceConversationRepository $repository,
        private VoiceOpenAIClient $client
    ) {
    }

    /** @param array<string, mixed> $user */
    public function handle(
        string $action,
        string $method,
        array $user,
        string $expectedPasswordFingerprint
    ): void
    {
        if (!str_starts_with($action, 'plugin-voice-')) {
            return;
        }
        try {
            if ($action === 'plugin-voice-conversations' && $method === 'GET') {
                jsonResponse([
                    'conversations' => $this->repository->listForUser($user, $expectedPasswordFingerprint),
                ]);
            }
            if ($action === 'plugin-voice-conversation' && $method === 'GET') {
                $conversationId = max(0, (int) ($_GET['id'] ?? 0));
                jsonResponse($this->repository->getForUser(
                    $conversationId,
                    $user,
                    $expectedPasswordFingerprint
                ));
            }
            if ($action === 'plugin-voice-conversation' && $method === 'POST') {
                requireCsrf();
                jsonResponse([
                    'conversation' => $this->repository->createForUser($user, $expectedPasswordFingerprint),
                ], 201);
            }
            if ($action === 'plugin-voice-transcribe' && $method === 'POST') {
                $this->transcribe($user, $expectedPasswordFingerprint);
            }
            if ($action === 'plugin-voice-message' && $method === 'POST') {
                $this->streamMessage($user, $expectedPasswordFingerprint);
            }
        } catch (InvalidArgumentException $exception) {
            jsonResponse(['error' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            if ((int) $exception->getCode() === 401) {
                unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
                jsonResponse(['error' => $exception->getMessage()], 401);
            }
            error_log('[voice-conversation] API error: ' . $exception->getMessage());
            jsonResponse(['error' => $this->publicError($exception)], 502);
        } catch (Throwable $exception) {
            error_log('[voice-conversation] API error: ' . $exception->getMessage());
            jsonResponse(['error' => $this->publicError($exception)], 502);
        }

        jsonResponse(['error' => '音声会話プラグインのAPI操作が見つかりません。'], 404);
    }

    /** @param array<string, mixed> $user */
    private function transcribe(array $user, string $expectedPasswordFingerprint): never
    {
        requireCsrf();
        $this->enforceRateLimit('transcription', 18);
        $upload = $_FILES['audio'] ?? null;
        if (!is_array($upload)) {
            jsonResponse(['error' => '録音データがありません。'], 422);
        }
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? '録音データがサーバーの上限を超えています。'
                : '録音データを受け取れませんでした。';
            jsonResponse(['error' => $message], 422);
        }
        $path = (string) ($upload['tmp_name'] ?? '');
        $size = (int) ($upload['size'] ?? 0);
        $limit = max(1, min(24, (int) (getenv('OPENCONCEPT_VOICE_MAX_AUDIO_MB') ?: 10))) * 1024 * 1024;
        if ($size < 1 || $size > $limit) {
            jsonResponse(['error' => '録音は' . (int) ($limit / 1024 / 1024) . 'MB以内にしてください。'], 422);
        }
        if (!is_uploaded_file($path)) {
            jsonResponse(['error' => '正しい録音アップロードではありません。'], 422);
        }

        $declaredParts = explode(';', strtolower((string) ($upload['type'] ?? '')), 2);
        $declared = trim($declaredParts[0]);
        $detected = '';
        if (class_exists('finfo')) {
            $detected = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path));
        }
        $allowed = [
            'audio/webm' => 'webm',
            'video/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'application/ogg' => 'ogg',
            'audio/mp4' => 'm4a',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/x-m4a' => 'm4a',
        ];
        $mime = isset($allowed[$detected]) ? $detected : $declared;
        if (!isset($allowed[$mime]) || ($detected !== '' && $detected !== 'application/octet-stream' && !isset($allowed[$detected]))) {
            jsonResponse(['error' => 'WebM、Ogg、MP4、MP3、WAV形式の録音を使用してください。'], 422);
        }

        $result = $this->transcribeForUser(
            $user,
            $expectedPasswordFingerprint,
            $path,
            $mime,
            'recording.' . $allowed[$mime]
        );
        jsonResponse([
            'text' => $result['text'],
            'model' => $result['model'],
            'audio_stored' => false,
            'user_id' => (int) ($user['id'] ?? 0),
        ]);
    }

    /** @param array<string, mixed> $user */
    private function streamMessage(array $user, string $expectedPasswordFingerprint): never
    {
        requireCsrf();
        $this->enforceRateLimit('message', 12);
        $userId = (int) ($user['id'] ?? 0);
        $body = bodyJson();
        $conversationId = max(0, (int) ($body['conversation_id'] ?? 0));
        $text = trim((string) ($body['text'] ?? ''));
        $source = (string) ($body['source'] ?? 'text');
        if ($conversationId < 1) {
            $conversationId = (int) $this->repository->createForUser(
                $user,
                $expectedPasswordFingerprint
            )['id'];
        }
        $userMessage = $this->repository->appendUserMessage(
            $conversationId,
            $user,
            $expectedPasswordFingerprint,
            $text,
            $source
        );
        $conversation = $this->repository->getForUser(
            $conversationId,
            $user,
            $expectedPasswordFingerprint
        )['conversation'];

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
        session_write_close();
        @set_time_limit(max(30, (int) (getenv('OPENCONCEPT_VOICE_TIMEOUT') ?: 120) + 15));
        $this->flushBuffers();
        $this->emit('ready', [
            'conversation' => $conversation,
            'message' => $userMessage,
        ]);

        try {
            $result = $this->streamConversationForUser(
                $conversationId,
                $user,
                $expectedPasswordFingerprint,
                fn (string $delta) => $this->emit('delta', ['delta' => $delta])
            );
            $assistantMessage = $this->repository->appendAssistantMessage(
                $conversationId,
                $user,
                $expectedPasswordFingerprint,
                $result['text'],
                $result
            );
            $this->emit('done', [
                'conversation_id' => $conversationId,
                'message' => $assistantMessage,
                'usage' => [
                    'input_tokens' => $result['input_tokens'],
                    'output_tokens' => $result['output_tokens'],
                ],
            ]);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
                $this->compactAfterResponse(
                    $conversationId,
                    $user,
                    $expectedPasswordFingerprint
                );
            }
        } catch (Throwable $exception) {
            error_log('[voice-conversation] stream error: ' . $exception->getMessage());
            $this->emit('error', ['error' => $this->publicError($exception)]);
        }
        exit;
    }

    /**
     * Authenticate in a fresh snapshot immediately before sending an upload to
     * the external transcription service. Kept public for deterministic boundary
     * tests; API callers still enter through handle().
     *
     * @param array<string, mixed> $user
     * @return array{text: string, model: string}
     */
    public function transcribeForUser(
        array $user,
        string $expectedPasswordFingerprint,
        string $path,
        string $mime,
        string $filename
    ): array {
        $this->repository->assertAuthenticatedForExternalSend($user, $expectedPasswordFingerprint);
        return $this->client->transcribe($path, $mime, $filename);
    }

    /**
     * Materialize the exact history and authenticate its owner in one fresh
     * snapshot immediately before opening the external stream.
     *
     * @param array<string, mixed> $user
     * @param callable(string): void $onDelta
     * @return array{text: string, response_id: string, model: string, input_tokens: int, output_tokens: int}
     */
    public function streamConversationForUser(
        int $conversationId,
        array $user,
        string $expectedPasswordFingerprint,
        callable $onDelta
    ): array {
        $history = $this->repository->historyForModel(
            $conversationId,
            $user,
            $expectedPasswordFingerprint
        );
        return $this->client->streamConversation(
            $history,
            'openconcept-voice-user-' . (int) ($user['id'] ?? 0),
            $onDelta
        );
    }

    /**
     * Re-authenticate and materialize the compaction batch immediately before
     * sending it to the external summarizer, then re-authenticate the write.
     *
     * @param array<string, mixed> $user
     */
    public function compactConversationForUser(
        int $conversationId,
        array $user,
        string $expectedPasswordFingerprint
    ): bool {
        $batch = $this->repository->compactionBatch(
            $conversationId,
            $user,
            $expectedPasswordFingerprint
        );
        if ($batch === null) {
            return false;
        }
        $summary = $this->client->summarizeConversation(
            $batch['previous_summary'],
            $batch['transcript'],
            'openconcept-voice-user-' . (int) ($user['id'] ?? 0)
        );
        $this->repository->storeSummary(
            $conversationId,
            $user,
            $expectedPasswordFingerprint,
            $batch['through_id'],
            $summary
        );
        return true;
    }

    /** @param array<string, mixed> $user */
    private function compactAfterResponse(
        int $conversationId,
        array $user,
        string $expectedPasswordFingerprint
    ): void
    {
        try {
            $this->compactConversationForUser(
                $conversationId,
                $user,
                $expectedPasswordFingerprint
            );
        } catch (Throwable $exception) {
            error_log('[voice-conversation] summary compaction failed: ' . $exception->getMessage());
        }
    }

    private function enforceRateLimit(string $bucket, int $defaultLimit): void
    {
        $now = time();
        $configured = (int) (getenv('OPENCONCEPT_VOICE_RATE_LIMIT') ?: $defaultLimit);
        $limit = max(2, min(60, $configured));
        $key = 'voice_conversation_rate_' . $bucket;
        $attempts = array_values(array_filter(
            is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [],
            static fn ($timestamp): bool => is_int($timestamp) && $timestamp > time() - 60
        ));
        if (count($attempts) >= $limit) {
            header('Retry-After: 60');
            jsonResponse(['error' => '音声会話の利用が続いています。少し待ってからもう一度お試しください。'], 429);
        }
        $attempts[] = $now;
        $_SESSION[$key] = $attempts;
    }

    /** @param array<string, mixed> $data */
    private function emit(string $event, array $data): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @ob_flush();
        flush();
    }

    private function flushBuffers(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }

    private function publicError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return 'AIとの会話を完了できませんでした。時間をおいてもう一度お試しください。';
        }
        return function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500);
    }
}
