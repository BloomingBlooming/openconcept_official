<?php

declare(strict_types=1);

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
        bool $resent = false
    ): array {
        $subject = $resent
            ? '【OpenConcept】招待メールを再送しました'
            : '【OpenConcept】アカウントが作成されました';
        $body = self::invitationBody(
            self::headerValue($recipientName),
            self::email($recipientEmail),
            $temporaryPassword,
            trim($loginUrl),
            self::headerValue($inviterName),
            $resent
        );

        return self::sendMessage($recipientName, $recipientEmail, $subject, $body, $storagePath);
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    public static function sendTest(string $recipientEmail, string $applicationUrl, string $storagePath): array
    {
        $subject = '【OpenConcept】招待メール送信テスト';
        $sentAt = date('Y-m-d H:i:s T');
        $body = <<<TEXT
OpenConceptの招待メール送信機能から配信されたテストメールです。

公開URL: {$applicationUrl}
送信日時: {$sentAt}

このメールに一時パスワードは含まれておらず、アカウントも作成されていません。

OpenConcept
TEXT;

        return self::sendMessage('メール送信テスト', $recipientEmail, $subject, $body, $storagePath);
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendMessage(
        string $recipientName,
        string $recipientEmail,
        string $subject,
        string $body,
        string $storagePath
    ): array {
        $transport = strtolower(trim((string) (getenv('OPENCONCEPT_MAIL_TRANSPORT') ?: 'mail')));
        $fromEmail = self::email((string) (getenv('OPENCONCEPT_MAIL_FROM') ?: ''));
        $fromName = self::headerValue((string) (getenv('OPENCONCEPT_MAIL_FROM_NAME') ?: 'OpenConcept'));
        $replyToValue = (string) (getenv('OPENCONCEPT_MAIL_REPLY_TO') ?: $fromEmail);
        $replyTo = self::email($replyToValue);
        $recipientEmail = self::email($recipientEmail);

        if ($recipientEmail === '') {
            return self::failure($transport, '送信先メールアドレスが正しくありません。');
        }
        if ($fromEmail === '') {
            return self::failure($transport, '送信元メールアドレスが設定されていません。OPENCONCEPT_MAIL_FROMを確認してください。');
        }
        if ($replyTo === '') {
            return self::failure($transport, '返信先メールアドレスが正しくありません。OPENCONCEPT_MAIL_REPLY_TOを確認してください。');
        }

        if ($transport === 'log') {
            return self::sendToLog($storagePath, $recipientEmail, $subject, $body);
        }
        if ($transport === 'mail') {
            return self::sendViaPhpMail($recipientEmail, $fromEmail, $fromName, $replyTo, $subject, $body);
        }
        if ($transport === 'smtp') {
            return self::sendViaSmtp(
                $recipientName,
                $recipientEmail,
                $fromEmail,
                $fromName,
                $replyTo,
                $subject,
                $body
            );
        }

        return self::failure($transport, '未対応のメール送信方式です。OPENCONCEPT_MAIL_TRANSPORTを確認してください。');
    }

    /** @return array{sent: bool, transport: string, error: ?string} */
    private static function sendToLog(string $storagePath, string $recipientEmail, string $subject, string $body): array
    {
        $mailPath = $storagePath . DIRECTORY_SEPARATOR . 'mail';
        if (!is_dir($mailPath) && !mkdir($mailPath, 0770, true) && !is_dir($mailPath)) {
            return self::failure('log', 'メールログ用ディレクトリを作成できません。');
        }

        try {
            $filename = sprintf('%s-invitation-%s.txt', date('Ymd-His'), bin2hex(random_bytes(4)));
        } catch (Throwable) {
            return self::failure('log', 'メールログのファイル名を作成できません。');
        }

        $content = "To: {$recipientEmail}\nSubject: {$subject}\n\n{$body}";
        $written = file_put_contents($mailPath . DIRECTORY_SEPARATOR . $filename, $content, LOCK_EX);

        return [
            'sent' => $written !== false,
            'transport' => 'log',
            'error' => $written === false ? 'メールログを書き込めません。' : null,
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
            'error' => $sent ? null : 'PHPのmail()で送信できませんでした。メール環境を確認してください。',
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
        string $body
    ): array {
        try {
            $config = self::smtpConfig();
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
                return self::failure('smtp', 'OPENCONCEPT_CA_BUNDLEで指定されたCA証明書を読み込めません。');
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
            return self::failure('smtp', 'SMTPサーバーに接続できませんでした。ホスト、ポート、暗号化方式を確認してください。');
        }

        stream_set_blocking($socket, true);
        stream_set_timeout($socket, $config['timeout']);

        try {
            self::expectResponse($socket, [220], 'SMTPサーバーから開始応答を受信できませんでした。');
            $helloName = self::helloName();
            self::command($socket, 'EHLO ' . $helloName, [250], 'SMTPサーバーがEHLOを受け付けませんでした。');

            if ($config['encryption'] === 'tls') {
                self::command($socket, 'STARTTLS', [220], 'SMTPサーバーがSTARTTLSを受け付けませんでした。');
                $encrypted = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($encrypted !== true) {
                    throw new RuntimeException('SMTPのTLS暗号化を開始できませんでした。');
                }
                self::command($socket, 'EHLO ' . $helloName, [250], 'TLS接続後のEHLOに失敗しました。');
            }

            self::command($socket, 'AUTH LOGIN', [334], 'SMTP認証に失敗しました。');
            self::command($socket, base64_encode($config['username']), [334], 'SMTP認証に失敗しました。');
            self::command($socket, base64_encode($config['password']), [235], 'SMTP認証に失敗しました。');
            self::command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], '送信元メールアドレスがSMTPサーバーに拒否されました。');
            self::command($socket, 'RCPT TO:<' . $recipientEmail . '>', [250, 251], '送信先メールアドレスがSMTPサーバーに拒否されました。');
            self::command($socket, 'DATA', [354], 'SMTPサーバーがメール本文を受け付けませんでした。');

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
            self::writeAll($socket, rtrim($message, "\r\n") . "\r\n.\r\n", 'SMTPサーバーへメール本文を送信できませんでした。');
            self::expectResponse($socket, [250], 'SMTPサーバーがメールを受理しませんでした。');

            // DATAが受理された時点で送信成功です。QUITの失敗は送信結果を変更しません。
            @fwrite($socket, "QUIT\r\n");
        } catch (RuntimeException $exception) {
            return self::failure('smtp', $exception->getMessage());
        } catch (Throwable) {
            return self::failure('smtp', 'SMTP送信中に予期しないエラーが発生しました。');
        } finally {
            fclose($socket);
        }

        return ['sent' => true, 'transport' => 'smtp', 'error' => null];
    }

    /** @return array{host: string, port: int, encryption: string, username: string, password: string, timeout: int} */
    private static function smtpConfig(): array
    {
        $host = strtolower(trim((string) getenv('OPENCONCEPT_SMTP_HOST')));
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9.-]+$/D', $host))) {
            throw new RuntimeException('SMTPホストが設定されていません。OPENCONCEPT_SMTP_HOSTを確認してください。');
        }

        $port = (int) (getenv('OPENCONCEPT_SMTP_PORT') ?: 465);
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('SMTPポートが正しくありません。OPENCONCEPT_SMTP_PORTを確認してください。');
        }

        $encryption = strtolower(trim((string) (getenv('OPENCONCEPT_SMTP_ENCRYPTION') ?: 'ssl')));
        $encryption = match ($encryption) {
            'ssl', 'smtps' => 'ssl',
            'tls', 'starttls' => 'tls',
            'none', '' => 'none',
            default => throw new RuntimeException('SMTP暗号化方式が正しくありません。sslまたはtlsを指定してください。'),
        };
        if ($encryption === 'none' && !self::isLoopbackHost($host)) {
            throw new RuntimeException('暗号化なしのSMTP接続はローカルテストでのみ使用できます。');
        }

        $username = trim((string) getenv('OPENCONCEPT_SMTP_USERNAME'));
        $password = (string) getenv('OPENCONCEPT_SMTP_PASSWORD');
        if ($username === '' || $password === '') {
            throw new RuntimeException('SMTPのユーザー名またはパスワードが設定されていません。');
        }

        $timeout = (int) (getenv('OPENCONCEPT_SMTP_TIMEOUT') ?: 10);
        $timeout = max(3, min(60, $timeout));

        return compact('host', 'port', 'encryption', 'username', 'password', 'timeout');
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
        return $name === '' ? '<' . $email . '>' : self::encodedWord($name) . ' <' . $email . '>';
    }

    private static function encodedWord(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode(self::headerValue($value)) . '?=';
    }

    private static function invitationBody(
        string $name,
        string $email,
        string $password,
        string $url,
        string $inviter,
        bool $resent
    ): string
    {
        $notice = $resent
            ? "招待メールが再送され、新しい一時パスワードが発行されました。以前の一時パスワードは使用できません。"
            : "{$inviter}さんが、OpenConceptにあなたのアカウントを作成しました。";
        return <<<TEXT
{$name} 様

{$notice}

ログインURL: {$url}
メールアドレス: {$email}
一時パスワード: {$password}

初回ログイン時に、新しいパスワードへの変更が必要です。この一時パスワードは他の方と共有しないでください。

OpenConcept
TEXT;
    }

    private static function email(string $email): string
    {
        $email = trim(str_replace(["\r", "\n"], '', $email));
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

    private static function isLoopbackHost(string $host): bool
    {
        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /** @return array{sent: false, transport: string, error: string} */
    private static function failure(string $transport, string $error): array
    {
        return ['sent' => false, 'transport' => $transport, 'error' => $error];
    }
}
