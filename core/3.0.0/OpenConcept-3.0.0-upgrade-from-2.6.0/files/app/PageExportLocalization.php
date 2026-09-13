<?php

declare(strict_types=1);

require_once __DIR__ . '/I18n.php';

/** Localization available to exports and retired public URLs without a database. */
final class PageExportLocalization
{
    private readonly I18n $i18n;
    public readonly string $locale;

    public function __construct(?I18n $i18n = null, ?string $locale = null)
    {
        $this->i18n = $i18n ?? new I18n();
        $this->i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $this->i18n->registerPackage('page-export', dirname(__DIR__) . '/locales/page-export');
        $this->locale = $this->i18n->selectLocale($locale);
    }

    public static function guest(): self
    {
        $language = new self();
        $requested = $_GET['lang'] ?? null;
        $remembered = $_COOKIE['openconcept_ui_locale'] ?? null;
        $available = array_column($language->i18n->availableLocales(), 'code');
        $locale = is_string($requested) && in_array($requested, $available, true)
            ? $requested
            : $language->i18n->selectGuestLocale(is_string($remembered) ? $remembered : null, (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        return new self($language->i18n, $locale);
    }

    public function text(string $key, array $parameters = []): string
    {
        return $this->i18n->translate($key, $parameters, $this->locale, 'page-export');
    }

    /** Format only application timestamps; leave malformed or unknown values intact. */
    public function date(string $value, bool $dateOnly = false): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/D', $value)) {
            return $value;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) {
                return $value;
            }
        } catch (Exception) {
            return $value;
        }
        return $this->text($dateOnly ? 'dateOnly.pattern' : 'date.pattern', [
            'year' => $date->format('Y'), 'month' => $date->format('m'), 'day' => $date->format('d'),
            'hour' => $date->format('H'), 'minute' => $date->format('i'),
        ]);
    }
}
