<?php

declare(strict_types=1);

final class DrawingOcrMetadata
{
    private const FIELDS = ['drawing_no', 'revision_code', 'title'];
    private const MAX_REGIONS = 24;

    /**
     * @return array<int, array{field: string, text: string, page: int, x: int, y: int, width: int, height: int}>
     */
    public static function normalize(mixed $regions): array
    {
        if (!is_array($regions) || !array_is_list($regions)) {
            return [];
        }

        $cleaned = [];
        $seen = [];
        foreach (array_slice($regions, 0, self::MAX_REGIONS) as $region) {
            if (!is_array($region)) {
                continue;
            }
            $field = $region['field'] ?? null;
            $text = $region['text'] ?? null;
            if (!is_string($field) || !in_array($field, self::FIELDS, true) || !is_string($text)) {
                continue;
            }

            $text = preg_replace('/[ \t]*\R+[ \t]*/u', ' ', $text) ?? '';
            $text = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? '');
            if ($text === '') {
                continue;
            }
            $text = function_exists('mb_substr') ? mb_substr($text, 0, 160, 'UTF-8') : substr($text, 0, 160);

            $numbers = [];
            foreach (['page', 'x', 'y', 'width', 'height'] as $key) {
                if (!is_int($region[$key] ?? null)) {
                    continue 2;
                }
                $numbers[$key] = $region[$key];
            }
            if ($numbers['page'] < 1 || $numbers['page'] > 100
                || $numbers['x'] < 0 || $numbers['y'] < 0
                || $numbers['width'] < 1 || $numbers['height'] < 1
                || $numbers['x'] + $numbers['width'] > 1000
                || $numbers['y'] + $numbers['height'] > 1000) {
                continue;
            }

            $dedupeKey = implode("\0", [
                $field,
                $text,
                (string) $numbers['page'],
                (string) $numbers['x'],
                (string) $numbers['y'],
                (string) $numbers['width'],
                (string) $numbers['height'],
            ]);
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            $cleaned[] = [
                'field' => $field,
                'text' => $text,
                'page' => $numbers['page'],
                'x' => $numbers['x'],
                'y' => $numbers['y'],
                'width' => $numbers['width'],
                'height' => $numbers['height'],
            ];
        }
        return $cleaned;
    }

    public static function encode(mixed $regions): string
    {
        return json_encode(self::normalize($regions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array{field: string, text: string, page: int, x: int, y: int, width: int, height: int}>
     */
    public static function decode(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        try {
            return self::normalize(json_decode($json, true, 64, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return [];
        }
    }
}
