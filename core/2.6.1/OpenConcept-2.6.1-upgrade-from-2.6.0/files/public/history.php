<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
$historyUser = currentUser($pdo);
if ($historyUser === null || !empty($historyUser['must_change_password'])) { header('Location: index.php'); exit; }
header('Cache-Control: no-store');
$historyLocale = uiLocale($historyUser);
$historyLabels = [];
foreach (['title','description','back','search','question','from','to','dateBasis','sourceTime','captureTime','allTypes','unknown','empty','more','pageSize','pageSizeOption','pageRange','pageNumber','previousPage','nextPage','pagination','limited','open','import','importHelp','template','imported','export','exported','busy','failed','original','details','relations','append','appendText','correction','retraction','verification','decision','observation','saved','askAi','aiAnswer','sourceWarning','page_revision','comment','ai_turn','voice_message','message','relation','file_version','role','sequence','kind','statement','proposal','report','parent_id','relates_to','attachment','execution_result','user','assistant','system','developer','tool','conversation'] as $key) {
    $historyLabels[$key] = $i18n->translate('knowledge.' . $key, [], $historyLocale);
}
$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
foreach (['matchingVersions','savedVersion','sameBody','scanning','searchFinished','searchProgress','searchIncomplete','resumeSearch','changes','compareCurrent','compareNext','previousVersion','nextVersion','bodyChanged','bodyUnchanged','noRecordedChanges','comparisonPartial','compareBody','before','after','snapshotExplanation',
    'field_title','field_icon','field_cover','field_blocks_json','field_status','field_category','field_manual_tags_json','field_visibility','field_access_department','field_comments_enabled','field_language_code','field_parent_id','field_sort_order','field_translation_status'] as $key) {
    $historyLabels[$key] = $i18n->translate('knowledge.' . $key, [], $historyLocale);
}
foreach (['none','mint','blue','sand','coral','lavender','night','black','navy','midnight','red','primary-blue','yellow'] as $cover) {
    $historyLabels['cover_' . $cover] = $i18n->translate('page.cover.' . $cover, [], $historyLocale);
}
$historyBoot = ['csrf' => $_SESSION['csrf'], 'labels' => $historyLabels, 'admin' => $historyUser['role'] === 'admin',
    'waf' => wafCompatibilitySettings($pdo, true)];
$historyAssetVersions = [];
foreach (['css', 'js'] as $extension) { $historyAssetVersions[$extension] = substr(hash_file('sha256', __DIR__ . '/assets/knowledge-history.' . $extension), 0, 12); }
?><!doctype html>
<html lang="<?= $h($historyLocale) ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($historyLabels['title']) ?> · OpenConcept</title>
<meta name="theme-color" content="#f6f5f2">
<script src="assets/theme.js?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/theme.js'), 0, 12) ?>"></script>
<link rel="stylesheet" href="assets/knowledge-history.css?v=<?= $h($historyAssetVersions['css']) ?>">
<link rel="stylesheet" href="assets/theme.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/theme.css'), 0, 12) ?>">
</head>
<body><main class="knowledge-shell">
<header class="knowledge-header"><a href="index.php" class="knowledge-brand"><img src="app-icon.php" alt="" width="32" height="32">OpenConcept</a><a href="index.php"><?= $h($historyLabels['back']) ?></a></header>
<section class="knowledge-heading"><h1><?= $h($historyLabels['title']) ?></h1><p><?= $h($historyLabels['description']) ?></p></section>
<form id="knowledge-search" class="knowledge-filters">
<label class="knowledge-question"><?= $h($historyLabels['question']) ?><input name="q" maxlength="1000" autocomplete="off"></label>
<label><?= $h($historyLabels['allTypes']) ?><select name="type"><option value=""><?= $h($historyLabels['allTypes']) ?></option><?php foreach (['message','relation','page_revision','comment','ai_turn','voice_message','file_version'] as $type): ?><option value="<?= $h($type) ?>"><?= $h($historyLabels[$type]) ?></option><?php endforeach; ?></select></label>
<label><?= $h($historyLabels['dateBasis']) ?><select name="date_basis"><option value="recorded_at"><?= $h($historyLabels['captureTime']) ?></option><option value="occurred_at"><?= $h($historyLabels['sourceTime']) ?></option></select></label>
<label><?= $h($historyLabels['from']) ?><input name="from" type="date"></label><label><?= $h($historyLabels['to']) ?><input name="to" type="date"></label>
<button type="submit" class="primary"><?= $h($historyLabels['search']) ?></button><button id="knowledge-ai" type="button"><?= $h($historyLabels['askAi']) ?></button>
</form>
<div class="knowledge-tools"><label class="knowledge-import"><?= $h($historyLabels['import']) ?><input id="knowledge-file" type="file" accept="application/json,.json"></label><button id="knowledge-template" type="button"><?= $h($historyLabels['template']) ?></button><?php if ($historyUser['role'] === 'admin'): ?><button id="knowledge-export" type="button"><?= $h($historyLabels['export']) ?></button><?php endif; ?></div>
<p class="knowledge-note"><?= $h($historyLabels['importHelp']) ?></p><p id="knowledge-status" role="status" aria-live="polite"></p>
<section id="knowledge-ai-result" hidden><h2><?= $h($historyLabels['aiAnswer']) ?></h2><pre></pre><div></div></section>
<div class="knowledge-columns"><section aria-label="<?= $h($historyLabels['title']) ?>">
<div id="knowledge-list-controls" class="knowledge-list-controls"><label class="knowledge-page-size"><?= $h($historyLabels['pageSize']) ?><select id="knowledge-page-size"><?php foreach ([10, 25, 50] as $size): ?><option value="<?= $size ?>"><?= $h(str_replace('{count}', (string) $size, $historyLabels['pageSizeOption'])) ?></option><?php endforeach; ?></select></label></div>
<?php foreach (['top', 'bottom'] as $position): ?>
<nav class="knowledge-pagination" aria-label="<?= $h($historyLabels['pagination']) ?>">
<span data-knowledge-page-summary<?= $position === 'top' ? ' role="status" aria-live="polite" tabindex="-1"' : '' ?>></span>
<div class="knowledge-page-buttons"><button data-knowledge-page="previous" type="button" disabled><?= $h($historyLabels['previousPage']) ?></button><span data-knowledge-page-number></span><button data-knowledge-page="next" type="button" disabled><?= $h($historyLabels['nextPage']) ?></button></div>
</nav>
<?php if ($position === 'top'): ?><div id="knowledge-results" aria-busy="false"></div><?php endif; ?>
<?php endforeach; ?>
</section><article id="knowledge-record" aria-live="polite"><p><?= $h($historyLabels['open']) ?></p></article></div>
</main><script id="knowledge-boot" type="application/json"><?= json_encode($historyBoot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?></script><script src="assets/knowledge-history.js?v=<?= $h($historyAssetVersions['js']) ?>" defer></script></body></html>
