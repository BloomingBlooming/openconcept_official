<?php

declare(strict_types=1);

final class ReciprocalRankFusion
{
    /**
     * @param array<string, list<array<string, mixed>>> $rankings
     * @return array<string, array{row: array<string, mixed>, rrf: float, methods: list<string>, raw: array<string, float>}>
     */
    public static function combine(array $rankings, int $k = 60): array
    {
        $k = max(1, min(1000, $k));
        $combined = [];
        foreach ($rankings as $method => $rows) {
            foreach ($rows as $offset => $row) {
                $key = (string) ($row['chunk_id'] ?? '');
                if ($key === '') {
                    throw new InvalidArgumentException('RRF candidate chunk_id is required.');
                }
                if (!isset($combined[$key])) {
                    $combined[$key] = ['row' => $row, 'rrf' => 0.0, 'methods' => [], 'raw' => []];
                }
                $combined[$key]['rrf'] += 1.0 / ($k + $offset + 1);
                $combined[$key]['methods'][] = (string) $method;
                $combined[$key]['raw'][(string) $method] = (float) ($row['raw_score'] ?? 0.0);
            }
        }
        uasort($combined, static function (array $left, array $right): int {
            $score = $right['rrf'] <=> $left['rrf'];
            return $score !== 0
                ? $score
                : strcmp((string) $left['row']['chunk_id'], (string) $right['row']['chunk_id']);
        });
        return $combined;
    }
}
