<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Environment.php';
Environment::loadProject($root);
require_once $root . '/app/Database.php';
require_once $root . '/app/DatabaseBackupService.php';

$arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $value): bool => $value !== ''));
if (count($arguments) !== 1 || in_array($arguments[0], ['-h', '--help'], true)) {
    fwrite(STDERR, "Usage: php scripts/create-database-backup.php <private-output-directory>\n");
    exit(count($arguments) === 1 ? 0 : 2);
}

try {
    $output = $arguments[0];
    if (!$outputDirectory = realpath($output)) {
        if (!mkdir($output, 0700, true) && !is_dir($output)) {
            throw new RuntimeException('Could not create the output directory.');
        }
        $outputDirectory = realpath($output);
    }
    if (!is_string($outputDirectory)) {
        throw new RuntimeException('Could not resolve the output directory.');
    }
    $database = new Database($root . '/storage');
    $service = new DatabaseBackupService($database, $root);
    $path = $service->create($outputDirectory);
    fwrite(STDOUT, "Database backup set created: {$path}\n");
} catch (Throwable $exception) {
    $code = $exception instanceof DatabaseUnavailableException || $exception instanceof DatabaseAdapterException
        ? $exception->failureCode()
        : 'backup_failed';
    fwrite(STDERR, "Database backup failed [{$code}]: {$exception->getMessage()}\n");
    exit(1);
}
