<?php

declare(strict_types=1);

final class Environment
{
    /** Explicit local HTTP fixtures must not read the developer's runtime secrets. */
    public static function storagePath(string $root): string
    {
        $override = trim((string) getenv('OPENCONCEPT_ISOLATED_STORAGE_PATH'));
        if ($override !== '' && in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            && getenv('OPENCONCEPT_ISOLATED_HTTP_TEST') === '1') {
            $resolved = realpath($override);
            $temporaryRoot = realpath(rtrim($root, '/\\') . '/tmp');
            if (!is_string($resolved) || !is_string($temporaryRoot)
                || !str_starts_with(str_replace('\\', '/', $resolved), rtrim(str_replace('\\', '/', $temporaryRoot), '/') . '/')) {
                throw new RuntimeException('The isolated storage path must be an existing project tmp directory.');
            }
            return $resolved;
        }
        return rtrim($root, '/\\') . '/storage';
    }

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
