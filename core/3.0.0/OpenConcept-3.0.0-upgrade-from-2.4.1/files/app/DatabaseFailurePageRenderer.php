<?php

declare(strict_types=1);

require_once __DIR__ . '/PageExportLocalization.php';

/** Human-readable diagnostics only; does not connect to or select a database. */
final class DatabaseFailurePageRenderer
{
    private readonly I18n $i18n;
    private readonly string $locale;

    public function __construct(?string $locale = null)
    {
        $this->i18n = new I18n();
        $this->i18n->registerPackage('core', dirname(__DIR__) . '/locales');
        $this->i18n->registerPackage('maintenance-screen', dirname(__DIR__) . '/locales/maintenance-screen');
        $this->locale = $this->i18n->selectLocale($locale ?? PageExportLocalization::guest()->locale);
    }

    public function text(string $key): string
    {
        return $this->i18n->translate('failure.' . $key, [], $this->locale, 'maintenance-screen');
    }

    /** @return array{title:string,message:string,action:string} */
    public function diagnostic(string $category): array
    {
        $category = in_array($category, ['connection_unreachable','authentication_failed','permissions_insufficient','schema_mismatch','configuration_error'], true) ? $category : 'configuration_error';
        return ['title'=>$this->text($category.'.title'),'message'=>$this->text($category.'.message'),'action'=>$this->text($category.'.action')];
    }

    public function html(string $category, string $backend, string $failureCode, array $safeRecovery): string
    {
        $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $selection = $category === 'database_selection_conflict';
        $diagnostic = $this->diagnostic($category);
        $title = $h($selection ? $this->text('selection.title') : $diagnostic['title']);
        $message = $h($selection ? $this->text('selection.message') : $diagnostic['message']);
        $action = $h($selection ? $this->text('selection.action') : $diagnostic['action']);
        $recoveryHtml = '';
        if ($category === 'permissions_insufficient' && $safeRecovery !== []) {
            $recoveryHtml = '<h2>'.$h($this->text('recovery')).'</h2><dl>';
            foreach (['permission_definition'=>$this->text('definition'),'recovery_sql'=>$this->text('sql'),'recovery_sql_sha256'=>'SQL SHA-256'] as $key=>$label) {
                $recoveryHtml .= '<dt>'.$h($label).'</dt><dd><code>'.$h((string)($safeRecovery[$key]??'')).'</code></dd>';
            }
            $recoveryHtml .= '</dl>';
        }
        return '<!doctype html><html lang="'.$h($this->locale).'"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            .'<title>OpenConcept '.$title.'</title></head><body style="font-family:system-ui;max-width:760px;margin:8vh auto;padding:24px">'
            .'<h1>'.$title.'</h1><p>'.$message.'</p><p><strong>'.$h($this->text('backend')).':</strong> '.$h($backend)
            .' / <strong>'.$h($this->text('code')).':</strong> <code>'.$h($failureCode).'</code></p><p>'.$action.'</p>'
            .$recoveryHtml.'<p>'.$h($this->text($selection?'selection.policy':'stopped')).'</p></body></html>';
    }
}
