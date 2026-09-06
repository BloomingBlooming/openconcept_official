<?php

declare(strict_types=1);

/**
 * Small, provider-neutral UI localization engine.
 *
 * Core and plugins register directories containing <locale>.json language
 * packs. Packs are data files, so adding a locale never requires editing this
 * class. English is always the final fallback and an unknown key is returned
 * verbatim instead of breaking the page.
 */
final class I18n
{
    private const DOMAIN_PATTERN = '/^(?:core|[a-z][a-z0-9]*(?:-[a-z0-9]+)*)$/D';
    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:-[A-Z][A-Za-z]{1,7})*$/D';
    private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,159}$/D';

    /** @var array<string, list<string>> */
    private array $packageDirectories = [];
    /** @var array<string, array<string, array{name: string, messages: array<string, string>}>> */
    private array $cache = [];

    public function __construct(private readonly string $fallbackLocale = 'en-US')
    {
        if (!self::isValidLocale($fallbackLocale)) {
            throw new InvalidArgumentException('The fallback locale is invalid.');
        }
    }

    public static function isValidLocale(string $locale): bool
    {
        return strlen($locale) <= 35 && preg_match(self::LOCALE_PATTERN, $locale) === 1;
    }

    public function registerPackage(string $domain, string $directory): void
    {
        if (preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException('The translation domain is invalid.');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf('The locale directory for "%s" does not exist.', $domain));
        }
        if (!in_array($resolved, $this->packageDirectories[$domain] ?? [], true)) {
            $this->packageDirectories[$domain][] = $resolved;
            unset($this->cache[$domain]);
        }
    }

    /** @return list<array{code: string, name: string}> */
    public function availableLocales(): array
    {
        $locales = [];
        foreach ($this->loadDomain('core') as $code => $pack) {
            $locales[$code] = ['code' => $code, 'name' => $pack['name']];
        }
        ksort($locales, SORT_STRING);
        return array_values($locales);
    }

    public function selectLocale(?string $requested): string
    {
        $requested = trim((string) $requested);
        $available = array_column($this->availableLocales(), 'code');
        if (self::isValidLocale($requested) && in_array($requested, $available, true)) {
            return $requested;
        }
        return in_array($this->fallbackLocale, $available, true)
            ? $this->fallbackLocale
            : ((string) ($available[0] ?? $this->fallbackLocale));
    }

    /** @return array<string, string> */
    public function catalog(string $domain, ?string $locale = null): array
    {
        $packs = $this->loadDomain($domain);
        $selected = $domain === 'core'
            ? $this->selectLocale($locale)
            : $this->selectPackageLocale($packs, $locale);
        $fallback = $packs[$this->fallbackLocale]['messages'] ?? [];
        $localized = $packs[$selected]['messages'] ?? [];
        return array_replace($fallback, $localized);
    }

    /** @param array<string, scalar|null> $parameters */
    public function translate(string $key, array $parameters = [], ?string $locale = null, string $domain = 'core'): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            return $key;
        }
        $message = $this->catalog($domain, $locale)[$key] ?? $key;
        foreach ($parameters as $name => $value) {
            if (is_string($name) && preg_match('/^[A-Za-z0-9_]{1,40}$/D', $name) === 1 && is_scalar($value)) {
                $message = str_replace('{' . $name . '}', (string) $value, $message);
            }
        }
        return $message;
    }

    /** @return array<string, mixed> */
    public function bundle(?string $locale = null): array
    {
        $selected = $this->selectLocale($locale);
        $plugins = [];
        foreach (array_keys($this->packageDirectories) as $domain) {
            if ($domain !== 'core') {
                $plugins[$domain] = $this->catalog($domain, $selected);
            }
        }
        ksort($plugins, SORT_STRING);
        return [
            'locale' => $selected,
            'fallback_locale' => $this->fallbackLocale,
            'available_locales' => $this->availableLocales(),
            'messages' => $this->catalog('core', $selected),
            'plugins' => $plugins,
        ];
    }

    /** @return array<string, array{name: string, messages: array<string, string>}> */
    private function loadDomain(string $domain): array
    {
        if (isset($this->cache[$domain])) {
            return $this->cache[$domain];
        }
        $packs = [];
        foreach ($this->packageDirectories[$domain] ?? [] as $directory) {
            $paths = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $locale = pathinfo($path, PATHINFO_FILENAME);
                if (!self::isValidLocale($locale)) {
                    continue;
                }
                $contents = file_get_contents($path);
                if ($contents === false) {
                    continue;
                }
                try {
                    $decoded = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new RuntimeException(sprintf('Invalid locale pack "%s": %s', $path, $exception->getMessage()), 0, $exception);
                }
                if (!is_array($decoded) || !is_array($decoded['messages'] ?? null)) {
                    throw new RuntimeException(sprintf('Locale pack "%s" must contain a messages object.', $path));
                }
                $messages = [];
                foreach ($decoded['messages'] as $key => $message) {
                    if (is_string($key) && preg_match(self::KEY_PATTERN, $key) === 1 && is_string($message)) {
                        $messages[$key] = $message;
                    }
                }
                $name = trim((string) ($decoded['name'] ?? $locale));
                $packs[$locale] = [
                    'name' => $name !== '' ? $name : $locale,
                    'messages' => array_replace($packs[$locale]['messages'] ?? [], $messages),
                ];
            }
        }
        return $this->cache[$domain] = $packs;
    }

    /** @param array<string, mixed> $packs */
    private function selectPackageLocale(array $packs, ?string $requested): string
    {
        $requested = $this->selectLocale($requested);
        if (isset($packs[$requested])) {
            return $requested;
        }
        return isset($packs[$this->fallbackLocale]) ? $this->fallbackLocale : $requested;
    }
}
