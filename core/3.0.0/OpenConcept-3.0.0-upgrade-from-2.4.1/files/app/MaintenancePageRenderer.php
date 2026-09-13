<?php

declare(strict_types=1);

require_once __DIR__ . '/PageExportLocalization.php';

/** Status-only rendering: deliberately independent of database/bootstrap availability. */
final class MaintenancePageRenderer
{
    public function html(array $status, string $csrf, ?string $locale = null): string
    {
        $i18n = new I18n();
        $i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $i18n->registerPackage('maintenance-screen', dirname(__DIR__) . '/locales/maintenance-screen');
        $locale = $i18n->selectLocale($locale ?? PageExportLocalization::guest()->locale);
        $messages = $i18n->catalog('maintenance-screen', $locale);
        $h = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $t = static fn(string $key): string => $h($messages[$key]);
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;
        $initialJson = json_encode($status, $jsonFlags);
        $csrfJson = json_encode($csrf, $jsonFlags);
        $messagesJson = json_encode($messages, $jsonFlags);
        $locale = $h($locale);
        return <<<HTML
<!doctype html>
<html lang="{$locale}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>OpenConcept — {$t('title')}</title>
<style>
:root{color-scheme:light;font-family:system-ui,sans-serif;background:#f6f5f2;color:#24332f}body{margin:0;min-height:100vh;display:grid;place-items:center}main{width:min(620px,calc(100% - 32px));padding:28px;border:1px solid #d9d5ca;border-radius:14px;background:#fff;box-shadow:0 18px 48px rgba(31,50,44,.12)}h1{margin-top:0;font-size:1.45rem}.status{margin:20px 0;padding:16px;border-radius:10px;background:#fff8e6;border:1px solid #e1bd69}button{border:0;border-radius:8px;padding:10px 16px;font:inherit;font-weight:700;cursor:pointer;background:#9b2c2c;color:#fff}button:disabled{cursor:not-allowed;opacity:.55}small{display:block;margin-top:16px;color:#63706c}
</style></head><body><main>
<h1>{$t('heading')}</h1><p>{$t('help')}</p>
<div class="status" role="status"><strong id="migrationStage">{$t('checking')}</strong><div id="migrationElapsed"></div></div>
<button id="cancelMigration" type="button" hidden>{$t('cancel')}</button><small id="migrationMessage">{$t('refresh')}</small>
</main><script>
(() => {
    const initial = {$initialJson};
    const csrf = {$csrfJson};
    const messages = {$messagesJson};
    const stage = document.getElementById('migrationStage');
    const elapsed = document.getElementById('migrationElapsed');
    const cancel = document.getElementById('cancelMigration');
    const message = document.getElementById('migrationMessage');
    const render = status => {
        if (!status?.active) { location.reload(); return; }
        stage.textContent = messages['stage.' + status.stage] || messages['stage.unknown'];
        elapsed.textContent = messages.elapsed.replace('{seconds}', String(Number(status.elapsed_seconds || 0)));
        cancel.hidden = !(status.owned_by_session && status.cancellable);
        cancel.disabled = Boolean(status.cancel_requested);
        if (status.cancel_requested) message.textContent = messages.cancelled;
    };
    const status = async () => {
        const response = await fetch('api.php?action=database-migration-status', { headers: { Accept: 'application/json' } });
        const data = await response.json();
        if (response.ok) render(data.status);
    };
    cancel.onclick = async () => {
        cancel.disabled = true;
        const response = await fetch('api.php?action=database-migration-cancel', {
            method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) { message.textContent = data.error || messages.cancelFailed; cancel.disabled = false; return; }
        render(data.status); message.textContent = messages.cancelled;
    };
    render(initial);
    setInterval(() => status().catch(() => {}), 500);
})();
</script></body></html>
HTML;
    }
}
