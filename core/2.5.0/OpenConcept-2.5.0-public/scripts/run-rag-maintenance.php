<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/app/bootstrap.php';

$options = getopt('', ['limit:']);
$limit = max(1, min(100, (int) ($options['limit'] ?? 25)));
$workerId = 'docker-' . (gethostname() ?: 'worker') . '-' . getmypid();
$coreResult = $ragCoreRuntime instanceof RagCoreRuntime
    ? $ragCoreRuntime->processQueued($limit, $workerId)
    : ['state' => 'core_schema_unavailable'];
$pluginResults = Hooks::filter('rag_maintenance', [], $limit, $workerId);

fwrite(STDOUT, json_encode([
    'state' => 'completed',
    'worker_id' => $workerId,
    'rag_core' => $coreResult,
    'plugin_results' => $pluginResults,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
