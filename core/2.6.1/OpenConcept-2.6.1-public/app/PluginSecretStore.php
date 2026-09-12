<?php

declare(strict_types=1);

/** Local encrypted credentials, deliberately outside canonical/exported records. */
final class PluginSecretStore
{
    private string $directory;
    public function __construct(string $storagePath)
    {
        $this->directory = rtrim($storagePath, '/\\') . '/plugin-secrets';
    }

    public function get(string $scope, string $name): ?string
    {
        $path = $this->path($scope, $name);
        if (!is_file($path)) { return null; }
        $envelope = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $cipher = base64_decode((string) ($envelope['cipher'] ?? ''), true);
        if (!is_string($iv) || strlen($iv) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($cipher)) {
            throw new RuntimeException('The plugin credential envelope is invalid.');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key(false), OPENSSL_RAW_DATA, $iv, $tag, $scope . "\0" . $name);
        if (!is_string($plain)) { throw new RuntimeException('The plugin credential could not be decrypted.'); }
        return $plain;
    }

    public function set(string $scope, string $name, ?string $value): void
    {
        $path = $this->path($scope, $name);
        if ($value !== null && strlen($value) > 16384) { throw new InvalidArgumentException('The plugin credential is too long.'); }
        $this->ensureDirectory();
        $lock = fopen($this->directory . '/.lock', 'c+b');
        if (!is_resource($lock) || !flock($lock, LOCK_EX)) { throw new RuntimeException('Plugin credential storage is unavailable.'); }
        try {
            if ($value === null || $value === '') { if (is_file($path) && !unlink($path)) { throw new RuntimeException('Plugin credential removal failed.'); } return; }
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->key(true), OPENSSL_RAW_DATA, $iv, $tag, $scope . "\0" . $name);
            if (!is_string($cipher)) { throw new RuntimeException('Plugin credential encryption failed.'); }
            $this->atomicWrite($path, json_encode(['version' => 1, 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'cipher' => base64_encode($cipher)], JSON_THROW_ON_ERROR));
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function path(string $scope, string $name): string
    {
        if (preg_match('/^(?:core:)?[a-z][a-z0-9-]{0,63}$/D', $scope) !== 1 || preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid plugin credential key.');
        }
        return $this->directory . '/' . hash('sha256', $scope . "\0" . $name) . '.json';
    }
    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Plugin credential storage is unavailable.');
        }
        if (!is_file($this->directory . '/.htaccess')) { file_put_contents($this->directory . '/.htaccess', "Require all denied\nDeny from all\n"); }
    }
    private function key(bool $create): string
    {
        $path = $this->directory . '/credential.key';
        if (!is_file($path) && $create) { $this->atomicWrite($path, random_bytes(32)); }
        $key = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($key) || strlen($key) !== 32) { throw new RuntimeException('The plugin credential key is unavailable.'); }
        return $key;
    }
    private function atomicWrite(string $path, string $bytes): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) { throw new RuntimeException('Plugin credential write failed.'); }
            @chmod($temporary, 0600);
            if (!rename($temporary, $path)) { throw new RuntimeException('Plugin credential replacement failed.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }
}
