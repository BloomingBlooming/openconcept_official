<?php

declare(strict_types=1);

require_once __DIR__ . '/src/FileReaderOffice.php';
require_once __DIR__ . '/src/FileReaderClient.php';
require_once __DIR__ . '/src/FileReaderService.php';

return static function (array $context): void {
    $api = $context['plugin_api'] ?? null;
    if (!is_object($api)) {
        throw new RuntimeException('AI File Reader requires OpenConcept Plugin API 1.0.0.');
    }
    if (isset($context['i18n'])) {
        $context['i18n']->registerPackage('ai-file-reader', __DIR__ . '/locales');
    }
    (new FileReaderService($api, $context['ai_provider_settings'] ?? null, null, $context['i18n'] ?? null))->register();
};
