<?php

declare(strict_types=1);

/**
 * Shared rich-text sanitizer for authenticated and anonymous renderers.
 *
 * Public pages deliberately reuse the exact same allow-list as page writes so
 * rendering never has to trust that every stored row passed through a recent
 * editor version.
 */
final class SafeHtml
{
    public static function sanitize(string $html): string
    {
        $html = strip_tags($html, '<strong><b><em><i><u><s><del><code><a><mark><br>');
        $html = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? '';
        // strip_tags() keeps attributes on allowed elements. Public rendering
        // must not inherit contenteditable, autofocus, form-associated, or
        // other browser behavior from legacy rows, so formatting elements are
        // reconstructed without attributes. Links are rebuilt separately
        // below with the only attributes they are allowed to retain.
        $html = preg_replace_callback(
            '/<(strong|b|em|i|u|s|del|code|mark|br)\b[^>]*>/iu',
            static fn(array $matches): string => '<' . strtolower((string) $matches[1]) . '>',
            $html
        ) ?? '';
        $html = preg_replace_callback('/<a\s+([^>]*)>/iu', static function (array $matches): string {
            if (preg_match('/href\s*=\s*["\']([^"\']+)["\']/iu', $matches[1], $href)) {
                $url = trim($href[1]);
                if (preg_match('/^(https?:\/\/|mailto:)/i', $url)) {
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener noreferrer">';
                }
            }
            return '<a>';
        }, $html) ?? '';
        return self::slice($html, 20000);
    }

    private static function slice(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }
        if (function_exists('iconv_substr')) {
            $result = iconv_substr($value, 0, $max, 'UTF-8');
            return $result === false ? '' : $result;
        }
        return substr($value, 0, $max);
    }
}
