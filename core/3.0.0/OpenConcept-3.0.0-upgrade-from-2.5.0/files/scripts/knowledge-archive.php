<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/app/KnowledgePortableArchive.php';
$root = dirname(__DIR__);
$operation = $argv[1] ?? '';
if (!in_array($operation, ['export', 'verify', 'restore'], true)) {
    fwrite(STDOUT, "Usage (run from the project root):\n"
        . "  php scripts/knowledge-archive.php export <admin-user-id> <new-private-file.oc.jsonl>\n"
        . "  php scripts/knowledge-archive.php verify <archive.oc.jsonl>\n"
        . "  php scripts/knowledge-archive.php restore <archive.oc.jsonl> <new-isolated-directory>\n"
        . "Restore creates an isolated SQLite copy, verifies every record and original, and disables restored credentials. It never switches the active database.\n");
    exit(in_array($operation, ['--help', '-h'], true) ? 0 : 2);
}
try {
    if ($operation === 'verify') {
        if (count($argv) !== 3) { throw new InvalidArgumentException('Expected one archive path.'); }
        $result = KnowledgePortableArchive::verify($argv[2]);
    } elseif ($operation === 'export') {
        if (count($argv) !== 4 || !ctype_digit($argv[2]) || (int) $argv[2] < 1) { throw new InvalidArgumentException('Expected administrator ID and new private output file.'); }
        require_once $root . '/app/Environment.php';
        Environment::loadProject($root);
        $db = new Database(Environment::storagePath($root));
        $query = $db->pdo()->prepare('SELECT * FROM users WHERE id = ?'); $query->execute([(int) $argv[2]]);
        $actor = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor) || !$actor['active'] || $actor['role'] !== 'admin' || $actor['must_change_password']) { throw new RuntimeException('An active administrator is required.'); }
        $service = CanonicalReadSnapshotService::forDatabase($db, $root, static fn(string $issuer): bool => $issuer === 'knowledge-archive');
        $result = (new KnowledgePortableArchive($service))->create($actor, $argv[3]);
    } else {
        if (count($argv) !== 4) { throw new InvalidArgumentException('Expected archive and new isolated directory.'); }
        $archive = realpath($argv[2]);
        if (!is_string($archive)) { throw new InvalidArgumentException('Archive unavailable.'); }
        KnowledgePortableArchive::verify($archive);
        $target = $argv[3]; $parent = realpath(dirname($target)); $name = basename($target);
        if (!is_string($parent) || file_exists($target) || is_link($target) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $name)
            || str_ends_with($name, '.')) { throw new RuntimeException('Destination must be a new named directory inside an existing parent.'); }
        $target = $parent . DIRECTORY_SEPARATOR . $name;
        $normalized = strtolower(str_replace('\\', '/', $target));
        $project = strtolower(str_replace('\\', '/', (string) realpath($root)));
        if (str_starts_with($normalized, $project . '/') && !str_starts_with($normalized, $project . '/tmp/')) {
            throw new RuntimeException('Restore destination must be outside runtime and application directories.');
        }
        if (!mkdir($target, 0700)) { throw new RuntimeException('Could not create isolated destination.'); }
        // Never load source environment, adapter state or credentials for restore.
        foreach (['OPENCONCEPT_DSN' => 'sqlite:' . $target . '/openconcept.sqlite', 'OPENCONCEPT_TABLE_PREFIX' => '',
            'OPENCONCEPT_TEST_DRIVER' => 'sqlite', 'MOKU_DSN' => ''] as $key => $value) {
            putenv($key . '=' . $value); $_ENV[$key] = $value; $_SERVER[$key] = $value;
        }
        $db = new Database($target);
        $result = KnowledgePortableArchive::restore($archive, $db, $target . DIRECTORY_SEPARATOR . 'uploads');
        $result['isolated_directory'] = $target;
    }
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) { fwrite(STDERR, 'Knowledge archive failed: ' . $error->getMessage() . "\n"); exit(1); }
