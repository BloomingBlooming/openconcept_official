<?php

declare(strict_types=1);

/**
 * Runtime-local AI connection settings.
 *
 * Secrets live under storage/, encrypted with an installation-local key. They
 * are intentionally kept out of the canonical database so database migration,
 * export, and backup workflows do not copy API credentials between systems.
 */
final class AiProviderSettings
{
    private const VERSION = 1;
    private const DEFAULT_PROFILE = 'ai-provider';
    public const RAG_ANSWER_PROFILE = 'rag-answer-provider';
    public const RAG_EMBEDDING_PROFILE = 'rag-embedding-provider';

    private string $storageRoot;
    private string $directory;
    private string $settingsPath;
    private string $keyPath;
    private string $lockPath;
    private string $aad;

    public function __construct(string $storageRoot, string $profile = self::DEFAULT_PROFILE)
    {
        $storageRoot = rtrim($storageRoot, "/\\");
        if ($storageRoot === '') {
            throw new InvalidArgumentException('AI provider storage root is required.');
        }
        if (!in_array(
            $profile,
            [self::DEFAULT_PROFILE, 'translation-provider', self::RAG_ANSWER_PROFILE, self::RAG_EMBEDDING_PROFILE],
            true
        )) {
            throw new InvalidArgumentException('AI provider settings profile is invalid.');
        }
        $this->storageRoot = $storageRoot;
        $this->directory = $storageRoot . DIRECTORY_SEPARATOR . $profile;
        $this->settingsPath = $this->directory . DIRECTORY_SEPARATOR . 'settings.json';
        $this->keyPath = $this->directory . DIRECTORY_SEPARATOR . 'credential.key';
        $this->lockPath = $this->directory . DIRECTORY_SEPARATOR . 'settings.lock';
        $this->aad = match ($profile) {
            self::DEFAULT_PROFILE => 'OpenConcept AI provider settings v1',
            'translation-provider' => 'OpenConcept translation provider settings v1',
            self::RAG_ANSWER_PROFILE => 'OpenConcept RAG answer provider settings v1',
            self::RAG_EMBEDDING_PROFILE => 'OpenConcept RAG embedding provider settings v1',
        };
    }

    /** Return an independently encrypted settings store under the same installation storage root. */
    public function scoped(string $profile): self
    {
        return new self($this->storageRoot, $profile);
    }

    public function hasStoredSettings(): bool
    {
        return is_file($this->settingsPath);
    }

    /** @param array<string, mixed> $input */
    public function validate(array $input): void
    {
        $this->normalizeSettings($input);
        $this->validateApiKey((string) ($input['api_key'] ?? ''));
    }

    public function storedApiKeyFor(string $baseUrl, string $authMode): string
    {
        $resolved = $this->resolved();
        if (($resolved['api_key_source'] ?? '') !== 'stored'
            || rtrim(trim((string) ($resolved['base_url'] ?? '')), '/') !== rtrim(trim($baseUrl), '/')
            || strtolower(trim((string) ($resolved['auth_mode'] ?? 'bearer'))) !== strtolower(trim($authMode))) {
            return '';
        }
        return trim((string) ($resolved['api_key'] ?? ''));
    }

    /** @return array<string, mixed> */
    public static function environmentDefaults(): array
    {
        $apiKey = trim((string) (getenv('OPENAI_API_KEY') ?: getenv('OpenAI_key') ?: ''));
        return [
            'provider_id' => 'openai',
            'display_name' => 'OpenAI',
            'base_url' => rtrim((string) (getenv('OPENCONCEPT_OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'), '/'),
            'endpoint_mode' => 'responses',
            'structured_output_mode' => 'json_schema',
            'auth_mode' => 'bearer',
            'text_model' => trim((string) (getenv('OPENCONCEPT_OPENAI_MODEL') ?: 'gpt-5.6-luna')),
            'transcription_model' => trim((string) (getenv('OPENCONCEPT_VOICE_STT_MODEL') ?: 'gpt-transcribe')),
            'translation_model' => trim((string) (getenv('OPENCONCEPT_TRANSLATION_OPENAI_MODEL') ?: getenv('OPENCONCEPT_OPENAI_MODEL') ?: 'gpt-5.6-luna')),
            'vision_model' => trim((string) (getenv('OPENCONCEPT_DRAWING_VISION_MODEL') ?: getenv('OPENCONCEPT_OPENAI_MODEL') ?: 'gpt-5.6-luna')),
            'timeout' => max(15, min(300, (int) (getenv('OPENCONCEPT_OPENAI_TIMEOUT') ?: 120))),
            'verify_tls' => true,
            'allow_private_network' => false,
            'allow_http' => false,
            'api_key' => $apiKey,
            'api_key_source' => $apiKey === '' ? 'none' : 'environment',
        ];
    }

    /** @return array<string, mixed> */
    public function resolved(): array
    {
        $environment = self::environmentDefaults();
        $record = $this->readRecord();
        if ($record === null) {
            return $environment;
        }

        $settings = $this->normalizeSettings((array) ($record['settings'] ?? []));
        $sealed = $record['secret'] ?? null;
        $storedSecret = is_array($sealed) ? $this->unseal($sealed) : '';
        if ($settings['auth_mode'] === 'none') {
            $settings['api_key'] = '';
            $settings['api_key_source'] = 'none';
        } elseif ($storedSecret !== '') {
            $settings['api_key'] = $storedSecret;
            $settings['api_key_source'] = 'stored';
        } else {
            $settings['api_key'] = (string) $environment['api_key'];
            $settings['api_key_source'] = $environment['api_key_source'];
        }
        return $settings;
    }

    /** @return array<string, mixed> */
    public function publicState(): array
    {
        $resolved = $this->resolved();
        $record = $this->readRecord();
        $secret = (string) ($resolved['api_key'] ?? '');
        unset($resolved['api_key']);
        $source = (string) ($resolved['api_key_source'] ?? 'none');
        unset($resolved['api_key_source']);
        return [
            'settings' => $resolved,
            'secret' => [
                'configured' => $source === 'stored',
                'available' => $secret !== '' || (string) ($resolved['auth_mode'] ?? '') === 'none',
                'source' => $source,
                'hint' => $secret === '' ? '' : ('••••' . substr($secret, -4)),
            ],
            'runtime' => is_array($record['runtime'] ?? null) ? $record['runtime'] : null,
        ];
    }

    /** @param array<string, scalar|null> $details @return array<string, mixed> */
    public function recordRuntimeStatus(string $status, array $details = []): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $status) !== 1) {
            throw new InvalidArgumentException('AI provider runtime status is invalid.');
        }
        $safeDetails = [];
        foreach ($details as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || preg_match('/secret|token|password|api.?key/i', $key) === 1 || (!is_scalar($value) && $value !== null)) {
                continue;
            }
            $safeDetails[$key] = is_string($value) ? substr($value, 0, 2048) : $value;
        }
        $this->ensureDirectory();
        $lock = @fopen($this->lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new RuntimeException('AI provider settings lock could not be opened.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('AI provider settings lock could not be acquired.');
            }
            $record = $this->readRecordUnlocked();
            if (!is_array($record)) {
                throw new RuntimeException('AI provider settings must be saved before runtime status is recorded.');
            }
            $record['runtime'] = [
                'status' => $status,
                'details' => $safeDetails,
                'checked_at' => gmdate(DATE_ATOM),
            ];
            $this->writeRecordUnlocked($record);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->publicState();
    }

    /**
     * Empty api_key preserves the stored key. clear_api_key removes only the
     * stored key and allows the server environment fallback to become active.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $settings = $this->normalizeSettings($input);
        $newSecret = trim((string) ($input['api_key'] ?? ''));
        $this->validateApiKey($newSecret);

        $this->ensureDirectory();
        $lock = @fopen($this->lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new RuntimeException('AI provider settings lock could not be opened.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('AI provider settings lock could not be acquired.');
            }
            $current = $this->readRecordUnlocked();
            $secret = is_array($current['secret'] ?? null) ? $current['secret'] : null;
            if ((bool) ($input['clear_api_key'] ?? false)) {
                $secret = null;
            } elseif ($newSecret !== '') {
                $secret = $this->seal($newSecret);
            }
            if ($settings['auth_mode'] === 'none') {
                $secret = null;
            }
            $record = [
                'version' => self::VERSION,
                'settings' => $settings,
                'secret' => $secret,
                'runtime' => is_array($current['runtime'] ?? null) ? $current['runtime'] : null,
                'updated_at' => gmdate(DATE_ATOM),
            ];
            $this->writeRecordUnlocked($record);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->publicState();
    }

    /** @return array<string, mixed>|null */
    private function readRecord(): ?array
    {
        if (!is_file($this->settingsPath)) {
            return null;
        }
        $this->ensureDirectory();
        $lock = @fopen($this->lockPath, 'c+b');
        if (!is_resource($lock)) {
            throw new RuntimeException('AI provider settings lock could not be opened.');
        }
        try {
            if (!flock($lock, LOCK_SH)) {
                throw new RuntimeException('AI provider settings lock could not be acquired.');
            }
            return $this->readRecordUnlocked();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed>|null */
    private function readRecordUnlocked(): ?array
    {
        if (!is_file($this->settingsPath)) {
            return null;
        }
        $json = file_get_contents($this->settingsPath);
        if (!is_string($json) || $json === '' || strlen($json) > 65536) {
            throw new RuntimeException('AI provider settings are unreadable.');
        }
        $record = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($record) || (int) ($record['version'] ?? 0) !== self::VERSION
            || !is_array($record['settings'] ?? null)
            || (!is_array($record['secret'] ?? null) && ($record['secret'] ?? null) !== null)) {
            throw new RuntimeException('AI provider settings are invalid.');
        }
        return $record;
    }

    /** @param array<string, mixed> $record */
    private function writeRecordUnlocked(array $record): void
    {
        $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $this->directory . DIRECTORY_SEPARATOR . 'settings-' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('AI provider settings temporary file could not be created.');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('AI provider settings could not be persisted.');
            }
        } finally {
            fclose($handle);
        }
        @chmod($temporary, 0600);
        if (is_file($this->settingsPath) && DIRECTORY_SEPARATOR === '\\' && !@unlink($this->settingsPath)) {
            @unlink($temporary);
            throw new RuntimeException('AI provider settings could not replace the prior configuration.');
        }
        if (!@rename($temporary, $this->settingsPath)) {
            @unlink($temporary);
            throw new RuntimeException('AI provider settings could not be activated.');
        }
        @chmod($this->settingsPath, 0600);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function normalizeSettings(array $input): array
    {
        $providerId = strtolower(trim((string) ($input['provider_id'] ?? 'openai')));
        if (!in_array($providerId, ['openai', 'openai-compatible'], true)) {
            throw new InvalidArgumentException('AI provider type is invalid.');
        }
        $displayName = $this->text((string) ($input['display_name'] ?? ($providerId === 'openai' ? 'OpenAI' : 'OpenAI Compatible')), 80, 'AI provider name');
        $allowPrivate = (bool) ($input['allow_private_network'] ?? false);
        $allowHttp = (bool) ($input['allow_http'] ?? false);
        $baseUrl = rtrim(trim((string) ($input['base_url'] ?? '')), '/');
        $endpointMode = (string) ($input['endpoint_mode'] ?? 'responses');
        $structuredMode = (string) ($input['structured_output_mode'] ?? 'json_schema');
        $authMode = (string) ($input['auth_mode'] ?? 'bearer');

        if ($providerId === 'openai') {
            $displayName = 'OpenAI';
            $baseUrl = 'https://api.openai.com/v1';
            $endpointMode = 'responses';
            $structuredMode = 'json_schema';
            $authMode = 'bearer';
            $allowPrivate = false;
            $allowHttp = false;
        }
        if (!in_array($endpointMode, ['responses', 'chat_completions'], true)) {
            throw new InvalidArgumentException('AI provider endpoint mode is invalid.');
        }
        if (!in_array($structuredMode, ['json_schema', 'json_object', 'prompt'], true)) {
            throw new InvalidArgumentException('AI provider structured output mode is invalid.');
        }
        if (!in_array($authMode, ['bearer', 'x-api-key', 'none'], true)) {
            throw new InvalidArgumentException('AI provider authentication mode is invalid.');
        }
        $this->validateBaseUrl($baseUrl, $allowHttp);

        return [
            'provider_id' => $providerId,
            'display_name' => $displayName,
            'base_url' => $baseUrl,
            'endpoint_mode' => $endpointMode,
            'structured_output_mode' => $structuredMode,
            'auth_mode' => $authMode,
            'text_model' => $this->text((string) ($input['text_model'] ?? ''), 190, 'AI text model'),
            'transcription_model' => $this->text((string) ($input['transcription_model'] ?? ''), 190, 'AI transcription model'),
            'translation_model' => $this->text((string) ($input['translation_model'] ?? $input['text_model'] ?? ''), 190, 'AI translation model'),
            'vision_model' => $this->text((string) ($input['vision_model'] ?? $input['text_model'] ?? ''), 190, 'AI vision model'),
            'timeout' => max(15, min(300, (int) ($input['timeout'] ?? 120))),
            'verify_tls' => !array_key_exists('verify_tls', $input) || (bool) $input['verify_tls'],
            'allow_private_network' => $allowPrivate,
            'allow_http' => $allowHttp,
        ];
    }

    private function validateBaseUrl(string $url, bool $allowHttp): void
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20]/', $url) === 1) {
            throw new InvalidArgumentException('AI provider Base URL is invalid.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
        $host = (string) (is_array($parts) ? ($parts['host'] ?? '') : '');
        if (!is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array($scheme, ['https', 'http'], true) || ($scheme === 'http' && !$allowHttp)) {
            throw new InvalidArgumentException('AI provider Base URL must use an allowed HTTP scheme without credentials or fragments.');
        }
    }

    private function text(string $value, int $maximumLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maximumLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function validateApiKey(string $apiKey): void
    {
        $apiKey = trim($apiKey);
        if ($apiKey !== '' && (strlen($apiKey) > 4096 || preg_match('/[\r\n\x00]/', $apiKey) === 1)) {
            throw new InvalidArgumentException('AI provider API key is invalid.');
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('AI provider settings directory could not be created.');
        }
        @chmod($this->directory, 0700);
    }

    /** @return array{algorithm: string, nonce: string, tag: string, ciphertext: string} */
    private function seal(string $plaintext): array
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required to store AI provider credentials safely.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, $this->aad);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('AI provider credential encryption failed.');
        }
        return [
            'algorithm' => 'aes-256-gcm',
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /** @param array<string, mixed> $sealed */
    private function unseal(array $sealed): string
    {
        if (($sealed['algorithm'] ?? '') !== 'aes-256-gcm') {
            throw new RuntimeException('AI provider credential envelope is unsupported.');
        }
        $nonce = base64_decode((string) ($sealed['nonce'] ?? ''), true);
        $tag = base64_decode((string) ($sealed['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($sealed['ciphertext'] ?? ''), true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            throw new RuntimeException('AI provider credential envelope is invalid.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, $this->aad);
        if (!is_string($plaintext)) {
            throw new RuntimeException('AI provider credential authentication failed.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        $this->ensureDirectory();
        if (is_file($this->keyPath)) {
            $key = file_get_contents($this->keyPath);
            if (is_string($key) && strlen($key) === 32) {
                return $key;
            }
            throw new RuntimeException('AI provider credential key is invalid.');
        }
        $key = random_bytes(32);
        $handle = @fopen($this->keyPath, 'xb');
        if (is_resource($handle)) {
            try {
                if (fwrite($handle, $key) !== 32 || !fflush($handle)) {
                    throw new RuntimeException('AI provider credential key could not be persisted.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($this->keyPath, 0600);
            return $key;
        }
        $existing = file_get_contents($this->keyPath);
        if (!is_string($existing) || strlen($existing) !== 32) {
            throw new RuntimeException('AI provider credential key could not be created.');
        }
        return $existing;
    }
}
