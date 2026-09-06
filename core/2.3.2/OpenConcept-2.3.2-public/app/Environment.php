<?php

declare(strict_types=1);

final class Environment
{
    /** @var array<string, array{file: string, hash: string}> */
    private static array $loadedSources = [];

    public static function loadProject(string $root): void
    {
        $root = rtrim($root, "/\\");

        // Only the deployment environment is part of the normal bootstrap.
        // Route-specific credential files are loaded by their guarded launcher
        // or maintenance command. A leftover Docker credential file must never
        // silently select a database for a Laragon, native SQLite, or managed
        // adapter deployment.
        self::load($root . '/.env');
    }

    public static function load(string $path, bool $override = false): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) || (!$override && getenv($name) !== false)) {
                continue;
            }

            $length = strlen($value);
            if ($length >= 2 && (($value[0] === '"' && $value[$length - 1] === '"') || ($value[0] === "'" && $value[$length - 1] === "'"))) {
                $quote = $value[0];
                $value = substr($value, 1, -1);
                if ($quote === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $value);
                }
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            self::$loadedSources[$name] = [
                'file' => basename($path),
                'hash' => hash('sha256', $value),
            ];
        }
    }

    /**
     * Returns only the origin of an effective environment value. The value
     * itself is deliberately never exposed through this diagnostic surface.
     */
    public static function source(string $name): string
    {
        $value = getenv($name);
        if ($value === false) {
            return '';
        }

        $source = self::$loadedSources[$name] ?? null;
        if (is_array($source)
            && hash_equals((string) ($source['hash'] ?? ''), hash('sha256', (string) $value))) {
            return (string) ($source['file'] ?? '');
        }

        if (in_array($name, ['OPENCONCEPT_DSN', 'MOKU_DSN'], true)) {
            $inheritedSource = (string) getenv('OPENCONCEPT_INTERNAL_DSN_SOURCE');
            if (in_array($inheritedSource, ['.env', '.env.mysql.local'], true)) {
                return $inheritedSource;
            }
        }

        return 'process_environment';
    }
}
