<?php

declare(strict_types=1);

require_once __DIR__ . '/src/AiSearchVoiceOpenAIClient.php';
require_once __DIR__ . '/src/AiSearchVoiceController.php';

return static function (array $context): void {
    // Translate known system errors at the Core response boundary; keep older Core compatibility.
    if (class_exists('SystemMessageLocalizer')) {
        SystemMessageLocalizer::register(
            json_decode((string) file_get_contents(__DIR__ . '/locales/system-messages.json'), true, 32, JSON_THROW_ON_ERROR),
            'ai-search-voice'
        );
    }
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('ai-search-voice', __DIR__ . '/locales');
    }
    $settingsStore = $context['ai_provider_settings'] ?? null;
    $configuration = $settingsStore instanceof AiProviderSettings ? $settingsStore->resolved() : null;
    $controller = new AiSearchVoiceController(new AiSearchVoiceOpenAIClient(null, $configuration));
    Hooks::addAction(
        'api_request',
        static function (string $action, string $method, PDO $pdo, array $user) use ($controller): void {
            $controller->handle($action, $method, $user);
        }
    );
};
