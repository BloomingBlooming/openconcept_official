<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaException.php';

/**
 * Verifies the independently published digest for an immutable Core catalog.
 */
final class CoreSchemaReleaseSeal
{
    public static function pathForCatalog(string $catalogPath): string
    {
        if (!str_ends_with(strtolower($catalogPath), '.json')) {
            throw new CoreSchemaException([
                'reason' => 'catalog_release_seal_path_invalid',
                'catalog' => basename($catalogPath),
            ]);
        }
        return substr($catalogPath, 0, -5) . '.sha256';
    }

    public static function assertMatches(string $catalogPath, string $artifactHash): void
    {
        $sealPath = self::pathForCatalog($catalogPath);
        if (!is_file($sealPath) || !is_readable($sealPath)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_release_seal_unavailable',
                'catalog' => basename($catalogPath),
                'seal' => basename($sealPath),
            ]);
        }
        $contents = file_get_contents($sealPath);
        if (!is_string($contents)
            || preg_match('/\A(sha256:[a-f0-9]{64})\r?\n?\z/D', $contents, $matches) !== 1) {
            throw new CoreSchemaException([
                'reason' => 'catalog_release_seal_invalid',
                'seal' => basename($sealPath),
            ]);
        }
        if (!hash_equals($matches[1], $artifactHash)) {
            throw new CoreSchemaException([
                'reason' => 'catalog_release_seal_mismatch',
                'catalog' => basename($catalogPath),
                'seal' => basename($sealPath),
            ]);
        }
    }
}
