<?php

declare(strict_types=1);

final class AiSearchVoiceController
{
    public function __construct(private AiSearchVoiceOpenAIClient $client)
    {
    }

    /** @param array<string, mixed> $user */
    public function handle(string $action, string $method, array $user): void
    {
        if ($action !== 'plugin-ai-search-voice-transcribe') {
            return;
        }
        if ($method !== 'POST') {
            jsonResponse(['error' => '音声文字起こしはPOSTで送信してください。'], 405);
        }
        try {
            $this->transcribe((int) ($user['id'] ?? 0));
        } catch (InvalidArgumentException $exception) {
            jsonResponse(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            error_log('[ai-search-voice] transcription error: ' . $exception->getMessage());
            jsonResponse(['error' => $this->publicError($exception)], 502);
        }
    }

    private function transcribe(int $userId): never
    {
        requireCsrf();
        $this->enforceRateLimit();
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
        $limit = max(1, min(24, (int) (getenv('OPENCONCEPT_AI_SEARCH_VOICE_MAX_AUDIO_MB') ?: 10))) * 1024 * 1024;
        if ($size < 1 || $size > $limit) {
            jsonResponse(['error' => '録音は' . (int) ($limit / 1024 / 1024) . 'MB以内にしてください。'], 422);
        }
        if (!is_uploaded_file($path)) {
            jsonResponse(['error' => '正しい録音アップロードではありません。'], 422);
        }

        $declared = trim(explode(';', strtolower((string) ($upload['type'] ?? '')), 2)[0]);
        $detected = class_exists('finfo') ? strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file($path)) : '';
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

        $result = $this->client->transcribe($path, $mime, 'recording.' . $allowed[$mime]);
        jsonResponse([
            'text' => $result['text'],
            'model' => $result['model'],
            'audio_stored' => false,
            'user_id' => $userId,
        ]);
    }

    private function enforceRateLimit(): void
    {
        $now = time();
        $limit = max(2, min(60, (int) (getenv('OPENCONCEPT_AI_SEARCH_VOICE_RATE_LIMIT') ?: 18)));
        $key = 'ai_search_voice_transcription_attempts';
        $attempts = array_values(array_filter(
            is_array($_SESSION[$key] ?? null) ? $_SESSION[$key] : [],
            static fn($timestamp): bool => is_int($timestamp) && $timestamp > $now - 60
        ));
        if (count($attempts) >= $limit) {
            header('Retry-After: 60');
            jsonResponse(['error' => '音声入力の利用が続いています。少し待ってからもう一度お試しください。'], 429);
        }
        $attempts[] = $now;
        $_SESSION[$key] = $attempts;
    }

    private function publicError(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            return '音声を文字にできませんでした。時間をおいてもう一度お試しください。';
        }
        return function_exists('mb_substr') ? mb_substr($message, 0, 500, 'UTF-8') : substr($message, 0, 500);
    }
}
