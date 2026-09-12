<?php

declare(strict_types=1);

return static function (array $context): void {
    $i18n = $context['i18n'] ?? null;
    if ($i18n instanceof I18n) {
        $i18n->registerPackage('float-navi', __DIR__ . '/locales');
    }
};
