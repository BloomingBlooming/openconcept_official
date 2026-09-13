<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginSecretStore.php';

/** Local mail configuration; credentials never enter canonical records or API output. */
final class MailSettings
{
    private PluginSecretStore $secrets;

    public function __construct(private readonly string $storagePath)
    {
        $this->secrets = new PluginSecretStore($storagePath);
    }

    /** @return array<string, mixed> */
    public static function environmentDefaults(): array
    {
        return [
            'transport' => strtolower(trim((string) (getenv('OPENCONCEPT_MAIL_TRANSPORT') ?: 'mail'))),
            'from_email' => trim((string) getenv('OPENCONCEPT_MAIL_FROM')),
            'from_name' => (string) (getenv('OPENCONCEPT_MAIL_FROM_NAME') ?: 'OpenConcept'),
            'reply_to' => trim((string) getenv('OPENCONCEPT_MAIL_REPLY_TO')),
            'smtp_host' => strtolower(trim((string) getenv('OPENCONCEPT_SMTP_HOST'))),
            'smtp_port' => (int) (getenv('OPENCONCEPT_SMTP_PORT') ?: 465),
            'smtp_encryption' => match (strtolower(trim((string) (getenv('OPENCONCEPT_SMTP_ENCRYPTION') ?: 'ssl')))) {
                'smtps' => 'ssl', 'starttls' => 'tls', default => strtolower(trim((string) (getenv('OPENCONCEPT_SMTP_ENCRYPTION') ?: 'ssl'))),
            },
            'smtp_username' => trim((string) getenv('OPENCONCEPT_SMTP_USERNAME')),
            'smtp_password' => (string) getenv('OPENCONCEPT_SMTP_PASSWORD'),
            'smtp_timeout' => max(3, min(60, (int) (getenv('OPENCONCEPT_SMTP_TIMEOUT') ?: 10))),
        ];
    }

    /** @return array<string, mixed> */
    public function resolved(): array
    {
        $record = $this->record();
        $settings = $record === null ? self::environmentDefaults() : $record['settings'];
        // The guarded local launcher and test harness deliberately force log mode.
        if (strtolower(trim((string) getenv('OPENCONCEPT_MAIL_TRANSPORT'))) === 'log') {
            $settings['transport'] = 'log';
        }
        return $settings;
    }

    /** @return array<string, mixed> */
    public function publicState(): array
    {
        $record = $this->record();
        $settings = $record === null ? self::environmentDefaults() : $record['settings'];
        $configured = true;
        try { self::validate($settings, true); } catch (InvalidArgumentException) { $configured = false; }
        $passwordConfigured = (string) ($settings['smtp_password'] ?? '') !== '';
        unset($settings['smtp_password']);
        return [
            'settings' => $settings,
            'password_configured' => $passwordConfigured,
            'configured' => $configured,
            'source' => $record === null ? 'environment' : 'saved',
            'effective_transport' => strtolower(trim((string) getenv('OPENCONCEPT_MAIL_TRANSPORT'))) === 'log' ? 'log' : $settings['transport'],
        ];
    }

    /** A blank password retains the existing credential only for the same SMTP destination/account. */
    public function save(array $input): array
    {
        $directory = rtrim($this->storagePath, '/\\') . '/mail-settings';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('mail.error.settingsUnavailable');
        }
        $lock = @fopen($directory . '/.lock', 'c+b');
        if (!is_resource($lock)) { throw new RuntimeException('mail.error.settingsUnavailable'); }
        try {
            if (!flock($lock, LOCK_EX)) { throw new RuntimeException('mail.error.settingsUnavailable'); }
            $record = $this->record();
            $current = $record === null ? self::environmentDefaults() : $record['settings'];
            $allowed = array_keys(self::environmentDefaults());
            foreach ($input as $key => $value) {
                if (!in_array($key, [...$allowed, 'clear_password'], true)) {
                    throw new InvalidArgumentException('mail.error.invalidSettings');
                }
                if ($key === 'clear_password') {
                    if (!is_bool($value)) { throw new InvalidArgumentException('mail.error.invalidSettings'); }
                } elseif (in_array($key, ['smtp_port', 'smtp_timeout'], true)) {
                    if (!(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))) {
                        throw new InvalidArgumentException('mail.error.invalidSettings');
                    }
                } elseif (!is_string($value)) { throw new InvalidArgumentException('mail.error.invalidSettings'); }
            }
            $settings = array_replace($current, array_intersect_key($input, array_flip($allowed)));
            foreach (['transport', 'from_email', 'from_name', 'reply_to', 'smtp_host', 'smtp_encryption', 'smtp_username'] as $field) {
                $settings[$field] = trim((string) $settings[$field]);
            }
            $settings['smtp_host'] = strtolower($settings['smtp_host']);
            $settings['smtp_port'] = (int) $settings['smtp_port'];
            $settings['smtp_timeout'] = (int) $settings['smtp_timeout'];
            $newPassword = (string) ($input['smtp_password'] ?? '');
            if (($input['clear_password'] ?? false) && $newPassword !== '') {
                throw new InvalidArgumentException('mail.error.invalidSettings');
            }
            if ($input['clear_password'] ?? false) {
                $settings['smtp_password'] = '';
            } elseif ($newPassword === '') {
                $settings['smtp_password'] = (string) ($current['smtp_password'] ?? '');
                foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username'] as $binding) {
                    if ((string) $settings[$binding] !== (string) $current[$binding] && $settings['smtp_password'] !== '') {
                        throw new InvalidArgumentException('mail.error.passwordRequiredForChange');
                    }
                }
            }
            self::validate($settings);
            // Store the whole connection and its password together, using the existing AES-GCM store.
            // A single atomic encrypted envelope prevents partial connection/credential updates.
            $this->secrets->set('core:mail', 'settings', json_encode([
                'version' => 1, 'settings' => $settings,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $this->publicState();
    }

    /** @param array<string, mixed> $settings */
    public static function validate(array $settings, bool $forSending = false): void
    {
        if (!in_array($settings['transport'] ?? '', ['smtp', 'mail', 'log'], true)) {
            throw new InvalidArgumentException('mail.error.transport');
        }
        if (!self::validEmail((string) ($settings['from_email'] ?? ''))) {
            throw new InvalidArgumentException('mail.error.from');
        }
        if (($settings['reply_to'] ?? '') !== '' && !self::validEmail((string) $settings['reply_to'])) {
            throw new InvalidArgumentException('mail.error.replyTo');
        }
        foreach (['from_name' => 200, 'smtp_username' => 320, 'smtp_password' => 4096] as $key => $length) {
            if (strlen((string) ($settings[$key] ?? '')) > $length
                || preg_match('/[\x00-\x1F\x7F]/', (string) ($settings[$key] ?? '')) === 1) {
                throw new InvalidArgumentException('mail.error.invalidSettings');
            }
        }
        if (($settings['transport'] ?? '') !== 'smtp') { return; }
        $host = (string) ($settings['smtp_host'] ?? '');
        if ($host === '' || strlen($host) > 253 || (!filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/iD', $host) !== 1)) {
            throw new InvalidArgumentException('mail.error.host');
        }
        if ((int) ($settings['smtp_port'] ?? 0) < 1 || (int) $settings['smtp_port'] > 65535) {
            throw new InvalidArgumentException('mail.error.port');
        }
        if (!in_array($settings['smtp_encryption'] ?? '', ['ssl', 'tls', 'none'], true)) {
            throw new InvalidArgumentException('mail.error.encryption');
        }
        if ($settings['smtp_encryption'] === 'none' && !self::isLoopbackHost($host)) {
            throw new InvalidArgumentException('mail.error.insecure');
        }
        if ((int) ($settings['smtp_timeout'] ?? 0) < 3 || (int) $settings['smtp_timeout'] > 60) {
            throw new InvalidArgumentException('mail.error.timeout');
        }
        if ($forSending && (($settings['smtp_username'] ?? '') === '' || ($settings['smtp_password'] ?? '') === '')) {
            throw new InvalidArgumentException('mail.error.credentials');
        }
    }

    public static function isLoopbackHost(string $host): bool
    {
        return strtolower($host) === 'localhost' || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
    }

    private static function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && preg_match('/[\x00-\x20\x7F]/', $email) !== 1
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function record(): ?array
    {
        $json = $this->secrets->get('core:mail', 'settings');
        if ($json === null) { return null; }
        $record = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_array($record['settings'] ?? null)
            || array_diff(array_keys(self::environmentDefaults()), array_keys($record['settings'])) !== []) {
            throw new RuntimeException('mail.error.settingsUnavailable');
        }
        return $record;
    }
}
