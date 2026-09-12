<?php

declare(strict_types=1);

final class JsonRequestBodyException extends RuntimeException
{
    public function __construct(string $message, int $status, public readonly string $failureCode)
    {
        parent::__construct($message, $status);
    }
}

/**
 * Optional transport compatibility for authenticated JSON requests.
 * AES-GCM protects transport integrity, not application authorization. Data must
 * use the same authorization, HTML sanitization and bound SQL as normal JSON.
 */
final class JsonRequestBody
{
    public const MAX_JSON_BYTES = 16 * 1024 * 1024;
    public const MAX_DEPTH = 64;
    public const SETTING_KEY = 'waf_compatibility_enabled';
    public const KEY_SETTING = 'waf_compatibility_key';
    public const ENCODING = 'aes-256-gcm-v1';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function maximumWireBytes(string $encoding): int
    {
        return $encoding === self::ENCODING
            ? 4 * (int) ceil((self::MAX_JSON_BYTES + self::IV_BYTES + self::TAG_BYTES) / 3) + 1024
            : self::MAX_JSON_BYTES;
    }

    public static function encryptionAvailable(): bool
    {
        return function_exists('openssl_decrypt') && function_exists('openssl_encrypt')
            && function_exists('openssl_get_cipher_methods')
            && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true);
    }

    public static function decodeKey(?string $value): ?string
    {
        if (!is_string($value) || strlen($value) !== 44) {
            return null;
        }
        $key = base64_decode($value, true);
        return is_string($key) && strlen($key) === 32 && base64_encode($key) === $value ? $key : null;
    }

    /** @return array<string, mixed> */
    public static function decode(string $raw, string $encoding, string $contentType, bool $enabled, ?string $encodedKey = null, string $action = '', string $method = 'POST', ?stdClass &$nativeBody = null): array
    {
        if ($encoding !== '' && $encoding !== self::ENCODING) {
            throw new JsonRequestBodyException('送信データの符号化方式に対応していません。', 415, 'unsupported_body_encoding');
        }
        if ($encoding === self::ENCODING) {
            if ($method !== 'POST') {
                throw new JsonRequestBodyException('この操作では暗号化した送信データを受け付けません。', 415, 'encoded_body_not_allowed');
            }
            if (!$enabled) {
                throw new JsonRequestBodyException('WAF誤検知を回避する設定が無効になっています。最新の設定を取得して保存を再試行してください。', 409, 'waf_compatibility_disabled');
            }
            if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
                throw new JsonRequestBodyException('暗号化した送信データの形式が正しくありません。', 415, 'invalid_encoded_content_type');
            }
        }
        if (strlen($raw) > self::maximumWireBytes($encoding)) {
            throw new JsonRequestBodyException('送信データが上限の16MiBを超えています。', 413, 'request_body_too_large');
        }
        if ($encoding === self::ENCODING) {
            $raw = self::decrypt($raw, $encodedKey, $action);
        }
        if (strlen($raw) > self::MAX_JSON_BYTES) {
            throw new JsonRequestBodyException('送信データが上限の16MiBを超えています。', 413, 'request_body_too_large');
        }
        try {
            $body = json_decode($raw, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            $native = func_num_args() >= 8
                ? json_decode($raw, false, self::MAX_DEPTH, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException) {
            throw new JsonRequestBodyException('送信データのJSON形式が正しくありません。', 400, 'invalid_json_body');
        }
        if (!str_starts_with(ltrim($raw, " \t\r\n"), '{') || !is_array($body)) {
            throw new JsonRequestBodyException('送信データはJSONオブジェクトで指定してください。', 400, 'invalid_json_object');
        }
        // Keep the native shape for schema validation: associative decoding
        // alone turns both an empty object and an empty list into PHP [].
        $nativeBody = $native instanceof stdClass ? $native : null;
        return $body;
    }

    private static function decrypt(string $raw, ?string $encodedKey, string $action): string
    {
        if (!self::encryptionAvailable()) {
            throw new JsonRequestBodyException('このサーバーではAES-GCM暗号化を利用できません。管理者に設定の確認を依頼してください。', 503, 'waf_encryption_unavailable');
        }
        $key = self::decodeKey($encodedKey);
        if ($key === null) {
            throw new JsonRequestBodyException('WAF誤検知を回避する暗号鍵がありません。管理者が設定を有効にして再保存してください。', 409, 'waf_compatibility_key_missing');
        }
        try {
            $envelope = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::invalidEncryptedBody();
        }
        if (!is_array($envelope) || array_keys($envelope) !== ['payload'] || !is_string($envelope['payload'])) {
            self::invalidEncryptedBody();
        }
        $sealed = base64_decode($envelope['payload'], true);
        if (!is_string($sealed) || base64_encode($sealed) !== $envelope['payload']
            || strlen($sealed) < self::IV_BYTES + self::TAG_BYTES) {
            self::invalidEncryptedBody();
        }
        if (strlen($sealed) > self::MAX_JSON_BYTES + self::IV_BYTES + self::TAG_BYTES) {
            throw new JsonRequestBodyException('送信データが上限の16MiBを超えています。', 413, 'request_body_too_large');
        }
        $iv = substr($sealed, 0, self::IV_BYTES);
        $tag = substr($sealed, -self::TAG_BYTES);
        $ciphertext = substr($sealed, self::IV_BYTES, -self::TAG_BYTES);
        $plain = openssl_decrypt(
            $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag,
            'OpenConcept:' . self::ENCODING . ':POST:' . $action
        );
        if (!is_string($plain)) {
            self::invalidEncryptedBody();
        }
        return $plain;
    }

    private static function invalidEncryptedBody(): never
    {
        throw new JsonRequestBodyException('暗号化した送信データを検証できませんでした。最新の設定を取得して保存を再試行してください。', 400, 'encrypted_body_invalid');
    }
}
