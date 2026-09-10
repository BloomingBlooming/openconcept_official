<?php

declare(strict_types=1);

return static function (array $context): void {
    $database = $context['database'] ?? null;
    $pdo = $context['pdo'] ?? null;
    if (!$database instanceof Database || !$pdo instanceof PDO) {
        throw new RuntimeException('PostgreSQL Database Adapter requires the Core database context.');
    }
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('database-postgresql-adapter', __DIR__ . '/locales');
    }
    $controller = new DatabaseAdapterPluginController(
        new DatabaseAdapterCoordinator($database, (string) $context['app_root']),
        'postgresql',
        $pdo
    );
    Hooks::addAction(
        'api_request',
        static function (string $action, string $method, PDO $_pdo, array $user) use ($controller): void {
            $controller->handle($action, $method, $user);
        }
    );
};
