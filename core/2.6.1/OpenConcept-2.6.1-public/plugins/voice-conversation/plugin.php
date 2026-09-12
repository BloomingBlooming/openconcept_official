<?php

declare(strict_types=1);

require_once __DIR__ . '/src/VoiceConversationRepository.php';
require_once __DIR__ . '/src/VoiceOpenAIClient.php';
require_once __DIR__ . '/src/VoiceConversationController.php';

return static function (array $context): void {
    $pdo = $context['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('AI音声会話プラグインにはPDO接続が必要です。');
    }
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('voice-conversation', __DIR__ . '/locales');
    }

    $verifyOnly = defined('OPENCONCEPT_PLUGIN_VERIFY_ONLY')
        && constant('OPENCONCEPT_PLUGIN_VERIFY_ONLY') === true;
    $schemaVersion = setting($pdo, 'plugin.voice-conversation.schema_version', '0');
    if ($verifyOnly && $schemaVersion !== '1') {
        throw new RuntimeException('Voice conversation schema version 1 is required for verify-only boot.');
    }

    $repository = new VoiceConversationRepository($pdo);
    if (!$verifyOnly && $schemaVersion !== '1') {
        $repository->migrate();
        setSetting($pdo, 'plugin.voice-conversation.schema_version', '1');
    }
    $settingsStore = $context['ai_provider_settings'] ?? null;
    $configuration = $settingsStore instanceof AiProviderSettings ? $settingsStore->resolved() : null;
    $controller = new VoiceConversationController($repository, new VoiceOpenAIClient(null, $configuration));
    Hooks::addAction(
        'api_request',
        static function (string $action, string $method, PDO $requestPdo, array $user) use ($controller): void {
            if (!str_starts_with($action, 'plugin-voice-')) {
                return;
            }
            $expectedPasswordFingerprint = sessionPasswordFingerprint();
            if ($expectedPasswordFingerprint === null) {
                unset($_SESSION['user_id'], $_SESSION['user_password_fingerprint']);
                jsonResponse([
                    'error' => 'ログイン状態を確認できません。もう一度ログインしてください。',
                ], 401);
            }
            $controller->handle($action, $method, $user, $expectedPasswordFingerprint);
        }
    );
};
