<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Environment.php';
Environment::loadProject(dirname(__DIR__));
require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/PluginManager.php';

$pluginId = (string) ($_GET['plugin'] ?? '');
$database = new Database(dirname(__DIR__) . '/storage');
$manager = new PluginManager(dirname(__DIR__) . '/plugins', false);
$pdo = $database->pdo();
if (PluginManager::isValidId($pluginId)) {
    $state = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $state->execute(['plugin.' . $pluginId . '.enabled']);
    $storedState = $state->fetchColumn();
    if ($storedState !== false) {
        $manager->applyEnabledStates([$pluginId => (string) $storedState === '1']);
    }
}
$asset = $manager->resolveAsset(
    $pluginId,
    (string) ($_GET['v'] ?? ''),
    (string) ($_GET['file'] ?? '')
);

if ($asset === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Plugin asset not found.';
    exit;
}

$size = filesize($asset['path']);
if ($size === false) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $asset['type']);
header('Content-Length: ' . $size);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
readfile($asset['path']);
