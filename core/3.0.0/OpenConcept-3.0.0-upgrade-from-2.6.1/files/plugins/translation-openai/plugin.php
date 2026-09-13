<?php

declare(strict_types=1);

require_once __DIR__ . '/src/OpenAITranslationClient.php';
require_once __DIR__ . '/src/OpenAITranslationProvider.php';

return static function (array $context): void {
    // Translate known system errors at the Core response boundary; keep older Core compatibility.
    if (class_exists('SystemMessageLocalizer')) {
        SystemMessageLocalizer::register(
            json_decode((string) file_get_contents(__DIR__ . '/locales/system-messages.json'), true, 32, JSON_THROW_ON_ERROR),
            'translation-openai'
        );
    }
    $registry = $context['translation_providers'] ?? null;
    if (!$registry instanceof TranslationProviderRegistry) {
        throw new RuntimeException('Translation Provider Plugin requires the Core translation provider registry.');
    }
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('translation-openai', __DIR__ . '/locales');
    }
    $settingsStore = $context['translation_provider_settings'] ?? null;
    $configuration = $settingsStore instanceof TranslationProviderSettings
        ? $settingsStore->resolved()
        : null;
    $label = trim((string) ($configuration['display_name'] ?? '')) ?: 'Translation Provider';
    $registry->register(new OpenAITranslationProvider(
        new OpenAITranslationClient(null, $configuration),
        $label
    ));
};
