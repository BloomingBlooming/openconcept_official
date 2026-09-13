<?php

declare(strict_types=1);

/** Translates explicitly registered system messages, never arbitrary document data. */
final class SystemMessageLocalizer
{
    private static array $exact = [];
    private static array $templates = [];
    private static bool $coreLoaded = false;

    /** @param array<string, string> $sources Legacy literal/template => catalog key. */
    public static function register(array $sources, string $domain): void
    {
        if (preg_match('/^(?:core|[a-z][a-z0-9]*(?:-[a-z0-9]+)*)$/D', $domain) !== 1) {
            throw new InvalidArgumentException('Invalid system message domain.');
        }
        foreach ($sources as $source => $key) {
            if (!is_string($source) || !is_string($key) || $source === '' || strlen($source) > 8192
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,159}$/D', $key) !== 1) {
                throw new InvalidArgumentException('Invalid system message mapping.');
            }
            $record = ['source' => $source, 'key' => $key, 'domain' => $domain];
            if (preg_match('/\{[A-Za-z][A-Za-z0-9_]{0,39}\}/', $source) !== 1) {
                self::$exact[$source] ??= $record;
                continue;
            }
            $names = [];
            $parts = preg_split('/(\{[A-Za-z][A-Za-z0-9_]{0,39}\})/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
            $pattern = '';
            foreach ($parts as $part) {
                if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]{0,39})\}$/D', $part, $match) === 1) {
                    $name = $match[1];
                    $pattern .= in_array($name, $names, true) ? '(?P=' . $name . ')' : '(?P<' . $name . '>.*?)';
                    $names[] = $name;
                } else {
                    $pattern .= preg_quote($part, '~');
                }
            }
            self::$templates[$domain . "\0" . $source] ??= $record + ['pattern' => '~\A' . $pattern . '\z~suD', 'names' => array_unique($names)];
        }
    }

    public static function loadCore(): void
    {
        if (self::$coreLoaded) { return; }
        self::$coreLoaded = true;
        $path = dirname(__DIR__) . '/locales/system-messages/sources.json';
        $domains = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($domains as $domain => $sources) { self::register($sources, $domain); }
    }

    public static function translate(string $message, string $locale, ?I18n $i18n = null): string
    {
        self::loadCore();
        $i18n ??= $GLOBALS['i18n'] ?? null;
        if (!$i18n instanceof I18n || strlen($message) > 32768) { return $message; }
        $match = self::$exact[$message] ?? null;
        $parameters = [];
        if ($match === null) {
            foreach (self::$templates as $candidate) {
                if (preg_match($candidate['pattern'], $message, $captures) === 1) {
                    if (preg_match(str_replace('>.*?)', '>.*)', $candidate['pattern']), $message, $greedyCaptures) !== 1) { return $message; }
                    foreach ($candidate['names'] as $name) {
                        if ($captures[$name] !== $greedyCaptures[$name]) { return $message; }
                    }
                    $match = $candidate;
                    foreach ($candidate['names'] as $name) { $parameters[$name] = $captures[$name]; }
                    break;
                }
            }
        }
        if ($match === null) { return $message; }
        if ($locale === 'ja-JP' && preg_match('/[\x{3040}-\x{30ff}]/u', $match['source']) === 1) {
            return $message;
        }
        return self::message($match['key'], $parameters, $locale, $match['domain'], $i18n, $message);
    }

    /** Early maintenance responses cannot depend on an available application database. */
    public static function guestError(string $message): string
    {
        $i18n = new I18n();
        $i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $i18n->registerPackage('system-messages', dirname(__DIR__) . '/locales/system-messages');
        $requested = $_GET['lang'] ?? null;
        $remembered = $_COOKIE['openconcept_ui_locale'] ?? null;
        $locale = is_string($requested) && in_array($requested, array_column($i18n->availableLocales(), 'code'), true)
            ? $requested : $i18n->selectGuestLocale(is_string($remembered) ? $remembered : null, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        return self::translate($message, $locale, $i18n);
    }

    public static function message(string $key, array $parameters, string $locale, string $domain = 'system-messages', ?I18n $i18n = null, ?string $fallback = null): string
    {
        $i18n ??= $GLOBALS['i18n'] ?? null;
        if (!$i18n instanceof I18n) {
            $i18n = new I18n();
            $i18n->registerPackage('core', dirname(__DIR__) . '/locales');
            $i18n->registerPackage('system-messages', dirname(__DIR__) . '/locales/system-messages');
        }
        $template = $i18n->catalog($domain, $locale)[$key] ?? null;
        if (!is_string($template)) { return $fallback ?? $key; }
        $replacements = [];
        foreach ($parameters as $name => $value) {
            if (is_scalar($value) || $value === null) { $replacements['{' . $name . '}'] = (string) $value; }
        }
        // One-pass replacement keeps braces and other placeholder-like user text intact.
        return strtr($template, $replacements);
    }

    /** Localize only the top-level API error; payloads and external details remain unchanged. */
    public static function response(mixed $data, string $locale, ?I18n $i18n = null): mixed
    {
        if (is_array($data) && is_string($data['error'] ?? null)) {
            $data['error'] = self::translate($data['error'], $locale, $i18n);
        }
        return $data;
    }

    /** Display only known application-generated notification types; never rewrite stored rows. */
    public static function notification(string $message, string $type, string $locale, string $domain = 'system-messages', ?I18n $i18n = null): string
    {
        $i18n ??= $GLOBALS['i18n'] ?? null;
        if (!$i18n instanceof I18n) { return $message; }
        $keys = $domain === 'ai-file-reader' && $type === 'file_reading_review' ? ['review.notification'] : ($domain === 'system-messages' ? match ($type) {
            'account_role_changed' => ['notification.adminGranted', 'notification.adminRevoked', 'notification.roleChanged'],
            'administrator_role_changed' => ['notification.administratorChanged'],
            'account_suspended' => ['notification.suspended'],
            'administrator_suspended' => ['notification.administratorSuspended'],
            'account_reactivated' => ['notification.adminReactivated', 'notification.reactivated'],
            'password_reset' => ['notification.passwordReset'],
            'mention' => ['notification.mention'], 'reply' => ['notification.reply'], 'comment' => ['notification.comment'],
            'update' => ['notification.update'],
            'static_publication_permission' => ['error.publicationStorageUnsafe', 'error.publicationPermissionsWindows', 'error.publicationPermissionsUnix'],
            default => [],
        } : []);
        foreach ($keys as $key) {
            foreach (['en-US', 'ja-JP', 'vi-VN', 'ko-KR', 'zh-CN'] as $sourceLocale) {
                $template = $i18n->catalog($domain, $sourceLocale)[$key] ?? null;
                if (!is_string($template)) { continue; }
                $pattern = ''; $names = [];
                foreach (preg_split('/(\{[A-Za-z][A-Za-z0-9_]{0,39}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                    if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]{0,39})\}$/D', $part, $match) === 1) {
                        $name = $match[1];
                        $pattern .= in_array($name, $names, true) ? '(?P=' . $name . ')' : '(?P<' . $name . '>.*?)';
                        $names[] = $name;
                    } else { $pattern .= preg_quote($part, '~'); }
                }
                if (preg_match('~\A' . $pattern . '\z~suD', $message, $captures) !== 1) { continue; }
                // Historical messages have no structured parameters. If delimiters also
                // occur in human text, there is no safe way to infer their boundaries.
                $greedy = str_replace('>.*?)', '>.*)', $pattern);
                if (preg_match('~\A' . $greedy . '\z~suD', $message, $greedyCaptures) !== 1) { return $message; }
                foreach ($names as $name) {
                    if ($captures[$name] !== $greedyCaptures[$name]) { return $message; }
                }
                $parameters = [];
                foreach ($names as $name) { $parameters[$name] = $captures[$name]; }
                return self::message($key, $parameters, $locale, $domain, $i18n, $message);
            }
        }
        return $message;
    }

    /** Metadata is trusted only while it still describes the exact stored message. */
    public static function storedNotification(string $message, array $metadata, string $locale, ?I18n $i18n = null): ?string
    {
        if (!is_string($metadata['source_sha256'] ?? null)
            || !hash_equals($metadata['source_sha256'], hash('sha256', $message))
            || !is_string($metadata['key'] ?? null) || !is_string($metadata['domain'] ?? null)
            || !is_array($metadata['parameters'] ?? null)) { return null; }
        return self::message($metadata['key'], $metadata['parameters'], $locale, $metadata['domain'], $i18n, $message);
    }
}
