<?php

declare(strict_types=1);

final class AiSearchVoiceOpenAIClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $transcriptionModel;
    private string $providerId;
    private string $authMode;
    private int $timeout;
    private ?SafeOutboundHttpClient $http = null;
    /** @var null|callable(array<string, mixed>): array<string, mixed> */
    private $transport;

    /** @param array<string, mixed>|null $configuration */
    public function __construct(?callable $transport = null, ?array $configuration = null)
    {
        $defaults = [
            'provider_id' => 'openai',
            'base_url' => rtrim((string) (getenv('OPENCONCEPT_OPENAI_BASE_URL') ?: 'https://api.openai.com/v1'), '/'),
            'auth_mode' => 'bearer',
            'api_key' => trim((string) (getenv('OPENAI_API_KEY') ?: getenv('OpenAI_key') ?: '')),
            'transcription_model' => trim((string) (getenv('OPENCONCEPT_AI_SEARCH_VOICE_STT_MODEL') ?: getenv('OPENCONCEPT_VOICE_STT_MODEL') ?: 'gpt-transcribe')),
            'timeout' => max(20, min(300, (int) (getenv('OPENCONCEPT_AI_SEARCH_VOICE_TIMEOUT') ?: 120))),
            'verify_tls' => true,
            'allow_private_network' => false,
            'allow_http' => false,
        ];
        $configuration = array_replace($defaults, $configuration ?? []);
        $this->apiKey = trim((string) $configuration['api_key']);
        $this->baseUrl = rtrim((string) $configuration['base_url'], '/');
        $this->transcriptionModel = trim((string) $configuration['transcription_model']);
        $this->providerId = (string) $configuration['provider_id'];
        $this->authMode = (string) $configuration['auth_mode'];
        $this->timeout = max(20, min(300, (int) $configuration['timeout']));
        if (class_exists('SafeOutboundHttpClient')) {
            $this->http = new SafeOutboundHttpClient(
                (bool) ($configuration['allow_private_network'] ?? false),
                !array_key_exists('verify_tls', $configuration) || (bool) $configuration['verify_tls'],
                (bool) ($configuration['allow_http'] ?? false)
            );
        }
        $this->transport = $transport;
    }

    public function transcriptionModel(): string
    {
        return $this->transcriptionModel;
    }

    /** @return array{text: string, model: string} */
    public function transcribe(string $path, string $mimeType, string $filename): array
    {
        if ($this->authMode !== 'none' && $this->apiKey === '' && $this->transport === null) {
            throw new RuntimeException('AIプロバイダーのAPIキーが設定されていません。');
        }
        if ($this->transcriptionModel === '') {
            throw new RuntimeException('音声文字起こしモデルが設定されていません。');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('録音ファイルを読み込めません。');
        }
        $request = [
            'path' => $path,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'model' => $this->transcriptionModel,
        ];
        $decoded = $this->transport !== null
            ? ($this->transport)($request)
            : $this->sendTranscription($request);
        $text = trim((string) ($decoded['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('音声から発話を検出できませんでした。');
        }
        return [
            'text' => $text,
            'model' => (string) ($decoded['model'] ?? $this->transcriptionModel),
        ];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    private function sendTranscription(array $request): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('音声入力にはPHP cURL拡張が必要です。');
        }
        $curl = curl_init($this->baseUrl . '/audio/transcriptions');
        $options = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_POSTFIELDS => [
                'file' => new CURLFile((string) $request['path'], (string) $request['mime_type'], (string) $request['filename']),
                'model' => (string) $request['model'],
                'response_format' => 'json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $this->timeout,
        ];
        if ($this->http !== null) {
            $options += $this->http->curlSecurityOptions($this->baseUrl . '/audio/transcriptions');
        }
        curl_setopt_array($curl, $options);
        if ($this->http === null) {
            $this->configureTls($curl);
        }
        $body = curl_exec($curl);
        if ($body === false) {
            $message = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('OpenAI APIに接続できません: ' . $this->safeError($message));
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI APIから解釈できない応答を受信しました。');
        }
        if ($status < 200 || $status >= 300) {
            $message = (string) ($decoded['error']['message'] ?? ('HTTP ' . $status));
            throw new RuntimeException('OpenAI APIエラー: ' . $this->safeError($message));
        }
        return $decoded;
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        $headers = [];
        if ($this->authMode === 'bearer') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        } elseif ($this->authMode === 'x-api-key') {
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }
        if ($this->providerId === 'openai') {
            $organization = trim((string) getenv('OPENAI_ORGANIZATION'));
            $project = trim((string) getenv('OPENAI_PROJECT'));
            if ($organization !== '') {
                $headers[] = 'OpenAI-Organization: ' . $organization;
            }
            if ($project !== '') {
                $headers[] = 'OpenAI-Project: ' . $project;
            }
        }
        return $headers;
    }

    private function configureTls(CurlHandle $curl): void
    {
        $caBundle = trim((string) getenv('OPENCONCEPT_CA_BUNDLE'));
        if ($caBundle === '') {
            return;
        }
        if (!is_file($caBundle) || !is_readable($caBundle)) {
            throw new RuntimeException('OPENCONCEPT_CA_BUNDLEで指定したCA証明書を読み込めません。');
        }
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }

    private function safeError(string $message): string
    {
        if ($this->apiKey !== '') {
            $message = str_replace($this->apiKey, '[REDACTED]', $message);
        }
        return substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? 'unknown error', 0, 500);
    }
}
