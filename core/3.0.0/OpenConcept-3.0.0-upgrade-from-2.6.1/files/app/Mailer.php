<?php

declare(strict_types=1);

require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/MailSettings.php';

final class Mailer
{
    /** @return array{sent: bool, transport: string, error: ?string} */
    public static function sendInvitation(
        string $recipientName,
        string $recipientEmail,
        string $temporaryPassword,
        string $loginUrl,
        string $inviterName,
        string $storagePath,
        bool $resent = false,
        ?string $locale = null,
        ?string $errorLocale = null
    ): array {
        $locale = self::i18n()->selectLocale($locale);
        $subject = self::text($resent ? 'mail.invitation.resendSubject' : 'mail.invitation.subject', [], $locale);
        $body = self::invitationBody(
            self::headerValue($recipientName),
            self::email($recipientEmail),
            $temporaryPassword,
            self::localizedLoginUrl(trim($loginUrl), $locale),
            self::headerValue($inviterName),
            $resent,
            $locale
        );

        return self::sendMessage($recipientName, $recipientEmail, $subject, $body, $storagePath, $errorLocale ?? $locale);
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    public static function sendTest(string $recipientEmail, string $applicationUrl, string $storagePath, ?string $locale = null): array
    {
        $locale = self::i18n()->selectLocale($locale);
        $subject = self::text('mail.test.subject', [], $locale);
        $body = self::text('mail.test.body', ['url' => self::localizedLoginUrl($applicationUrl, $locale), 'sent_at' => date('Y-m-d H:i:s T')], $locale);
        return self::sendMessage('', $recipientEmail, $subject, $body, $storagePath, $locale);
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendMessage(
        string $recipientName,
        string $recipientEmail,
        string $subject,
        string $body,
        string $storagePath,
        string $locale
    ): array {
        $transport = '';
        try {
            $settings = (new MailSettings($storagePath))->resolved();
            $transport = (string) $settings['transport'];
            MailSettings::validate($settings, true);
            $recipientEmail = self::email($recipientEmail);
            if ($recipientEmail === '') { throw new InvalidArgumentException('mail.error.recipient'); }
            $fromEmail = self::email($settings['from_email']);
            $fromName = self::headerValue($settings['from_name']);
            $replyTo = self::email($settings['reply_to'] !== '' ? $settings['reply_to'] : $fromEmail);
            $result = match ($transport) {
                'log' => self::sendToLog($storagePath, $recipientEmail, $subject, $body),
                'mail' => self::sendViaPhpMail($recipientEmail, $fromEmail, $fromName, $replyTo, $subject, $body),
                'smtp' => self::sendViaSmtp($recipientName, $recipientEmail, $fromEmail, $fromName, $replyTo, $subject, $body, $settings),
            };
        } catch (InvalidArgumentException $exception) {
            $result = self::failure($transport, str_starts_with($exception->getMessage(), 'mail.error.') ? $exception->getMessage() : 'mail.error.invalidSettings');
        } catch (Throwable) {
            $result = self::failure($transport, 'mail.error.settingsUnavailable');
        }
        if ($result['error'] !== null) { $result['error'] = self::text($result['error'], [], $locale); }
        return $result;
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendToLog(string $storagePath, string $recipientEmail, string $subject, string $body): array
    {
        $mailPath = $storagePath . DIRECTORY_SEPARATOR . 'mail';
        if (!is_dir($mailPath) && !mkdir($mailPath, 0770, true) && !is_dir($mailPath)) {
            return self::failure('log', 'mail.error.log');
        }

        try {
            $filename = sprintf('%s-invitation-%s.txt', date('Ymd-His'), bin2hex(random_bytes(4)));
        } catch (Throwable) {
            return self::failure('log', 'mail.error.log');
        }

        $content = "To: {$recipientEmail}\nSubject: {$subject}\n\n{$body}";
        $written = file_put_contents($mailPath . DIRECTORY_SEPARATOR . $filename, $content, LOCK_EX);

        return [
            'sent' => $written !== false,
            'transport' => 'log',
            'error' => $written === false ? 'mail.error.log' : null,
        ];
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendViaPhpMail(
        string $recipientEmail,
        string $fromEmail,
        string $fromName,
        string $replyTo,
        string $subject,
        string $body
    ): array {
        $headers = self::messageHeaders('', '', $fromEmail, $fromName, $replyTo, $subject, false);
        $encodedSubject = self::encodedWord($subject);
        $encodedBody = self::encodedBody($body);
        $sent = @mail($recipientEmail, $encodedSubject, $encodedBody, implode("\r\n", $headers));

        return [
            'sent' => $sent,
            'transport' => 'mail',
            'error' => $sent ? null : 'mail.error.phpMail',
        ];
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendViaSmtp(
        string $recipientName,
        string $recipientEmail,
        string $fromEmail,
        string $fromName,
        string $replyTo,
        string $subject,
        string $body,
        array $settings
    ): array {
        try {
            $config = self::smtpConfig($settings);
        } catch (RuntimeException $exception) {
            return self::failure('smtp', $exception->getMessage());
        }

        $sslOptions = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $config['host'],
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        $caBundle = trim((string) getenv('OPENCONCEPT_CA_BUNDLE'));
        if ($caBundle !== '') {
            if (!is_file($caBundle) || !is_readable($caBundle)) {
                return self::failure('smtp', 'mail.error.caBundle');
            }
            $sslOptions['cafile'] = $caBundle;
        }

        $context = stream_context_create(['ssl' => $sslOptions]);
        $scheme = $config['encryption'] === 'ssl' ? 'ssl' : 'tcp';
        $remoteHost = str_contains($config['host'], ':') ? '[' . $config['host'] . ']' : $config['host'];
        $remote = sprintf('%s://%s:%d', $scheme, $remoteHost, $config['port']);
        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client(
            $remote,
            $errorNumber,
            $errorMessage,
            $config['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            return self::failure('smtp', 'mail.error.connect');
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $config['timeout']);

        try {
            self::expectResponse($socket, [220], 'mail.error.protocol');
            $helloName = self::helloName();
            self::command($socket, 'EHLO ' . $helloName, [250], 'mail.error.protocol');

            if ($config['encryption'] === 'tls') {
                self::command($socket, 'STARTTLS', [220], 'mail.error.tls');
                $encrypted = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($encrypted !== true) {
                    throw new RuntimeException('mail.error.tls');
                }
                self::command($socket, 'EHLO ' . $helloName, [250], 'mail.error.tls');
            }

            self::command($socket, 'AUTH LOGIN', [334], 'mail.error.authentication');
            self::command($socket, base64_encode($config['username']), [334], 'mail.error.authentication');
            self::command($socket, base64_encode($config['password']), [235], 'mail.error.authentication');
            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], 'mail.error.senderRejected');
            self::command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251], 'mail.error.recipientRejected');
            self::command($socket, 'DATA', [354], 'mail.error.data');

            $headers = self::messageHeaders(
                $recipientName,
                $recipientEmail,
                $fromEmail,
                $fromName,
                $replyTo,
                $subject,
                true
            );
            $message = implode("\r\n", $headers) . "\r\n\r\n" . self::encodedBody($body);
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            self::writeAll($socket, rtrim($message, "\r\n") . "\r\n.\r\n", 'mail.error.data');
            self::expectResponse($socket, [250], 'mail.error.data');

            // DATAが受理された時点で送信成功です。QUITの失敗は送信結果を変更しません。
            @fwrite($socket, "QUIT\r\n");
        } catch (RuntimeException $exception) {
            return self::failure('smtp', $exception->getMessage());
        } catch (Throwable) {
            return self::failure('smtp', 'mail.error.protocol');
        } finally {
            fclose($socket);
        }

        return ['sent' => true, 'transport' => 'smtp', 'error' => null];
    }

    /** @return array{host: string, port: int, encryption: string, username: string, password: string, timeout: int} */
    private static function smtpConfig(array $settings): array
    {
        return [
            'host' => $settings['smtp_host'], 'port' => $settings['smtp_port'],
            'encryption' => $settings['smtp_encryption'], 'username' => $settings['smtp_username'],
            'password' => $settings['smtp_password'], 'timeout' => $settings['smtp_timeout'],
        ];
    }

    /** @param resource $socket @param list<int> $expectedCodes */
    private static function command($socket, string $command, array $expectedCodes, string $failureMessage): void
    {
        self::writeAll($socket, $command . "\r\n", $failureMessage);
        self::expectResponse($socket, $expectedCodes, $failureMessage);
    }

    /** @param resource $socket @param list<int> $expectedCodes */
    private static function expectResponse($socket, array $expectedCodes, string $failureMessage): void
    {
        $code = self::readResponseCode($socket, $failureMessage);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException($failureMessage);
        }
    }

    /** @param resource $socket */
    private static function readResponseCode($socket, string $failureMessage): int
    {
        $responseCode = null;
        for ($lineNumber = 0; $lineNumber < 100; $lineNumber++) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                throw new RuntimeException($failureMessage);
            }
            if (!preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                throw new RuntimeException($failureMessage);
            }

            $lineCode = (int) $matches[1];
            if ($responseCode === null) {
                $responseCode = $lineCode;
            } elseif ($lineCode !== $responseCode) {
                throw new RuntimeException($failureMessage);
            }

            if ($matches[2] === ' ') {
                return $lineCode;
            }
        }

        throw new RuntimeException($failureMessage);
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $data, string $failureMessage): void
    {
        $length = strlen($data);
        $written = 0;
        while ($written < $length) {
            $count = fwrite($socket, substr($data, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException($failureMessage);
            }
            $written += $count;
        }
    }

    /** @return list<string> */
    private static function messageHeaders(
        string $recipientName,
        string $recipientEmail,
        string $fromEmail,
        string $fromName,
        string $replyTo,
        string $subject,
        bool $includeRecipient
    ): array {
        $domain = substr(strrchr($fromEmail, '@') ?: '@localhost', 1) ?: 'localhost';
        try {
            $messageId = bin2hex(random_bytes(16));
        } catch (Throwable) {
            $messageId = hash('sha256', uniqid('', true));
        }

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . $messageId . '@' . $domain . '>',
            'From: ' . self::formattedAddress($fromName, $fromEmail),
            'Reply-To: <' . $replyTo . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: OpenConcept',
        ];
        if ($includeRecipient) {
            array_splice($headers, 2, 0, [
                'To: ' . self::formattedAddress($recipientName, $recipientEmail),
                'Subject: ' . self::encodedWord($subject),
            ]);
        }

        return $headers;
    }

    private static function encodedBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
    }

    private static function formattedAddress(string $name, string $email): string
    {
        $name = self::headerValue($name);
        if ($name === '') { return '<' . $email . '>'; }
        $encoded = self::encodedWord($name);
        $lines = explode("\r\n", $encoded);
        $lastLine = $lines[count($lines) - 1];
        // Keep encoded header lines within RFC 2047's 76-character limit.
        // Reserve the longer From prefix when the address has only one line.
        $prefixLength = count($lines) === 1 ? strlen('From: ') : 0;
        $separator = $prefixLength + strlen($lastLine) + strlen($email) + 3 > 76 ? "\r\n " : ' ';
        return $encoded . $separator . '<' . $email . '>';
    }

    private static function encodedWord(string $value): string
    {
        // RFC 2047 limits an encoded-word to 75 characters and encoded header
        // lines to 76. A 39-byte UTF-8 chunk needs at most 64 encoded characters,
        // leaving room for the Subject prefix without splitting a character.
        $characters = preg_split('//u', self::headerValue($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = [];
        $chunk = '';
        foreach ($characters as $character) {
            if (strlen($chunk) + strlen($character) > 39) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') { $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?='; }
        return implode("\r\n ", $words);
    }

    private static function invitationBody(
        string $name,
        string $email,
        string $password,
        string $url,
        string $inviter,
        bool $resent,
        string $locale
    ): string {
        $notice = self::text($resent ? 'mail.invitation.resendNotice' : 'mail.invitation.notice', ['inviter' => $inviter], $locale);
        return self::text('mail.invitation.body', compact('name', 'email', 'password', 'url', 'notice'), $locale);
    }

    public static function localizedLoginUrl(string $url, string $locale): string
    {
        $fragment = '';
        $fragmentAt = strpos($url, '#');
        if ($fragmentAt !== false) {
            $fragment = substr($url, $fragmentAt);
            $url = substr($url, 0, $fragmentAt);
        }
        $queryAt = strpos($url, '?');
        $query = [];
        if ($queryAt !== false) {
            parse_str(substr($url, $queryAt + 1), $query);
            $url = substr($url, 0, $queryAt);
        }
        $query['lang'] = self::i18n()->selectLocale($locale);
        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . $fragment;
    }

    private static function i18n(): I18n
    {
        static $translator;
        if (!$translator instanceof I18n) {
            $translator = new I18n();
            $translator->registerPackage('core', dirname(__DIR__) . '/locales');
        }
        return $translator;
    }

    private static function text(string $key, array $parameters, ?string $locale): string
    {
        return self::i18n()->translate($key, $parameters, $locale);
    }

    private static function email(string $email): string
    {
        $email = trim($email);
        if (preg_match('/[\x00-\x1F\x7F]/', $email) === 1) { return ''; }
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private static function headerValue(string $value): string
    {
        $value = str_replace(["\r", "\n"], '', trim($value));
        return preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }

    private static function helloName(): string
    {
        $hostname = strtolower((string) gethostname());
        $hostname = preg_replace('/[^a-z0-9.-]/', '', $hostname) ?? '';
        return $hostname !== '' ? $hostname : 'openconcept.local';
    }

    /** @return array{sent: false, transport: string, error: string} */
    private static function failure(string $transport, string $error): array
    {
        return ['sent' => false, 'transport' => $transport, 'error' => $error];
    }
}
