<?php

declare(strict_types=1);

// Anonymous public pages intentionally do not include app/bootstrap.php: that
// file starts an authenticated session and boots plugins. This bootstrap is
// read-only, sessionless, and contains only the dependencies needed to resolve
// and render an explicit public share.
require_once __DIR__ . '/Environment.php';
Environment::loadProject(dirname(__DIR__));
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/SafeHtml.php';
require_once __DIR__ . '/PublicPageRenderer.php';
require_once __DIR__ . '/StaticPublicSitePublisher.php';
require_once __DIR__ . '/PublicPageSharing.php';

$database = new Database(dirname(__DIR__) . '/storage');
$pdo = $database->pdo();
$i18n = new I18n('en-US');
$i18n->registerPackage('core', dirname(__DIR__) . '/locales');
