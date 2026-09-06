<?php

declare(strict_types=1);

final class DrawingProcessMetadata
{
    /** @var array<string, string> */
    private const OPTIONS = [
        'material_heat_treatment' => '材料熱処理',
        'lathe' => '旋盤',
        'machining' => 'マシニング',
        'drilling' => 'ボール盤',
        'milling' => 'フライス',
        'post_heat_treatment' => '加工後熱処理',
        'wire_cut' => 'ワイヤー',
        'surface_grinding' => '平研磨',
        'outer_diameter_grinding' => '外径研磨',
        'inner_diameter_grinding' => '内径研磨',
        'other_grinding' => 'その他研磨',
        'surface_treatment' => '表面処理',
        'cleaning' => '洗浄',
        'specified_packaging' => '指定梱包',
        'other' => 'その他',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return self::OPTIONS;
    }

    public static function isAllowedCode(string $code): bool
    {
        return array_key_exists($code, self::OPTIONS);
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public static function normalize(mixed $metadata): array
    {
        if (!is_array($metadata) || !array_is_list($metadata)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($metadata as $item) {
            $code = is_string($item) ? $item : (is_array($item) ? ($item['code'] ?? null) : null);
            if (!is_string($code) || !self::isAllowedCode($code) || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $normalized[] = ['code' => $code, 'label' => self::OPTIONS[$code]];
        }
        return $normalized;
    }

    public static function encode(mixed $metadata): string
    {
        return json_encode(
            self::normalize($metadata),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    public static function decode(mixed $json): array
    {
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        try {
            return self::normalize(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            return [];
        }
    }
}
