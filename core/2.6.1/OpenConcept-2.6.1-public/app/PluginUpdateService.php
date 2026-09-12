<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginCatalog.php';
require_once __DIR__ . '/PluginDetachment.php';

/** Exchanges verified code only. Plugin-owned data and schema are never restored over live data. */
final class PluginUpdateService
{
    private PluginDetachment $installation;
    private ?array $pending = null;
    private bool $committed = false;

    public function __construct(private readonly string $applicationRoot, private readonly string $storageRoot,
        private readonly PluginCompatibility $compatibility)
    {
        $this->installation = new PluginDetachment($applicationRoot, $storageRoot);
    }

    public function availableUpdate(array $installed, array $entries): ?array
    {
        if (!PluginManager::isValidVersion((string) ($installed['version'] ?? ''))) { return null; }
        foreach ($entries as $entry) {
            if ($entry['id'] !== $installed['id'] || PluginCompatibility::compareVersions($entry['version'], $installed['version']) <= 0) { continue; }
            $status = $this->compatibility->check($entry);
            if (!$status['compatible']) { return null; }
            return ['version' => $entry['version'], 'sha256' => $entry['sha256'], 'compatibility' => $status];
        }
        return null;
    }

    /** Network and syntax validation occur before the exclusive writer barrier. No candidate PHP is executed. */
    public function prepare(PluginCatalog $catalog, string $id, string $version, string $sha256): array
    {
        $entry = null;
        foreach ($catalog->entries() as $candidate) { if ($candidate['id'] === $id) { $entry = $candidate; break; } }
        if ($entry === null || $entry['version'] !== $version || !hash_equals($entry['sha256'], $sha256)
            || !$this->compatibility->check($entry)['compatible']) {
            throw new RuntimeException('対応する更新候補を確認できません。一覧を再読み込みしてください。', 409);
        }
        $parent = $this->updatesRoot();
        $operation = $id . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(12));
        $directory = $parent . '/' . $operation;
        if (!mkdir($directory, 0700) || !mkdir($directory . '/candidate', 0700)) {
            throw new RuntimeException('更新準備用の保存先を作成できません。', 409);
        }
        $catalog->stageUpdate($id, $version, $sha256, $directory . '/candidate');
        $manager = new PluginManager($directory . '/candidate', false, $this->compatibility);
        $plugin = $manager->plugin($id);
        if ($plugin === null || !$manager->canEnable($id)) {
            throw new RuntimeException('新版のプラグインは、この本体のAPI要件に適合しません。', 409);
        }
        $candidateRoot = $directory . '/candidate/' . $id;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($candidateRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('更新ファイルの配置が不正です。', 409); }
            if (strtolower($file->getExtension()) === 'php') {
                try { token_get_all((string) file_get_contents($file->getPathname()), TOKEN_PARSE); }
                catch (ParseError) { throw new RuntimeException('新版のPHPに構文エラーがあります。旧版を維持します。', 422); }
            }
        }
        return ['id' => $id, 'version' => $version, 'operation' => $operation,
            'manifest_sha256' => (string) hash_file('sha256', $candidateRoot . '/plugin.json'),
            'tree_sha256' => $this->treeFingerprint($candidateRoot), 'plugin' => $plugin];
    }

    /** Caller owns the installation lock and the exclusive Database lifetime barrier. */
    public function apply(array $prepared, string $expectedVersion, string $expectedToken): array
    {
        $id = $prepared['id'];
        $this->assertOperation($id, $prepared['operation']);
        $manager = new PluginManager($this->applicationRoot . '/plugins', false, $this->compatibility);
        $current = $manager->plugin($id);
        if ($current === null || $current['version'] !== $expectedVersion
            || !hash_equals($this->installation->installationToken($id), $expectedToken)
            || PluginCompatibility::compareVersions($prepared['version'], $current['version']) <= 0) {
            throw new RuntimeException('インストール済みプラグインが変更されています。一覧を再読み込みしてください。', 409);
        }
        $directory = $this->operationPath($prepared['operation']);
        $source = $this->applicationRoot . '/plugins/' . $id;
        $candidate = $directory . '/candidate/' . $id;
        $this->assertDirectory($candidate);
        $staged = (new PluginManager($directory . '/candidate', false, $this->compatibility))->plugin($id);
        if ($staged === null || $staged['version'] !== $prepared['version'] || !$this->compatibility->check($staged)['compatible']
            || !hash_equals($prepared['manifest_sha256'], (string) hash_file('sha256', $candidate . '/plugin.json'))
            || !hash_equals($prepared['tree_sha256'], $this->treeFingerprint($candidate))) {
            throw new RuntimeException('検証済みの更新ファイルが変更されています。更新をやり直してください。', 409);
        }
        if (!mkdir($directory . '/previous', 0700)) { throw new RuntimeException('旧版の退避先を作成できません。', 409); }
        $journal = ['id' => $id, 'operation' => $prepared['operation'], 'phase' => 'prepared',
            'old_manifest_sha256' => $expectedToken, 'new_manifest_sha256' => $prepared['manifest_sha256']];
        if (is_file($this->journalPath($id))) { throw new RuntimeException('このプラグインの前の更新処理が未完了です。', 409); }
        $this->writeJournal($journal);
        $this->pending = $journal;
        register_shutdown_function(function (): void {
            if ($this->pending !== null && !$this->committed) {
                try { $this->rollback(); } catch (Throwable) { /* Next Database startup recovers the durable journal. */ }
            }
        });
        if (!@rename($source, $directory . '/previous/' . $id) || !@rename($candidate, $source)) {
            throw new RuntimeException('新版を配置できませんでした。旧版を復元します。', 409);
        }
        $this->invalidatePhpCache($source);
        return ['id' => $id, 'version' => $prepared['version'], 'previous_version' => $expectedVersion,
            'backup' => $prepared['operation'], 'data_retained' => true];
    }

    public function commit(): void
    {
        if ($this->pending === null) { throw new LogicException('No update to commit.'); }
        $journal = $this->pending;
        $journal['phase'] = 'committed';
        $this->writeJournal($journal);
        $this->committed = true;
        @unlink($this->journalPath($journal['id']));
    }

    public function rollback(): void
    {
        if ($this->pending === null || $this->committed) { return; }
        $this->restoreJournal($this->pending);
        $this->pending = null;
    }

    /** Called after acquiring SH and before discovering any adapter or plugin code. */
    public static function recoverInterrupted(string $applicationRoot, string $storageRoot): void
    {
        if (!is_dir($storageRoot . '/plugin-updates')) { return; }
        $service = new self($applicationRoot, $storageRoot, new PluginCompatibility(trim((string) file_get_contents($applicationRoot . '/VERSION'))));
        foreach (glob($service->updatesRoot() . '/*.pending.json') ?: [] as $path) {
            $id = substr(basename($path), 0, -strlen('.pending.json'));
            if (!PluginManager::isValidId($id)) { throw new RuntimeException('Invalid plugin update recovery record.'); }
            $service->installation->withLock($id, function () use ($service, $path, $id): void {
                clearstatcache(true, $path);
                if (!is_file($path)) { return; }
                if (is_link($path)) { throw new RuntimeException('Unsafe plugin update recovery record.'); }
                $journal = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($journal) || ($journal['id'] ?? '') !== $id) { throw new RuntimeException('Invalid plugin update recovery identity.'); }
                if (($journal['phase'] ?? '') === 'committed') { @unlink($path); return; }
                if (($journal['phase'] ?? '') !== 'prepared') { throw new RuntimeException('Invalid plugin update recovery phase.'); }
                $service->restoreJournal($journal);
            });
        }
    }

    private function restoreJournal(array $journal): void
    {
        $id = $journal['id'];
        $this->assertOperation($id, (string) ($journal['operation'] ?? ''));
        foreach (['old_manifest_sha256', 'new_manifest_sha256'] as $key) {
            if (!is_string($journal[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $journal[$key]) !== 1) { throw new RuntimeException('Invalid recovery fingerprint.'); }
        }
        $directory = $this->operationPath($journal['operation']);
        $target = $this->applicationRoot . '/plugins/' . $id;
        $previous = $directory . '/previous/' . $id;
        $this->assertDirectory($this->applicationRoot . '/plugins');
        if (is_dir($previous)) {
            $this->assertDirectory($previous);
            if (!hash_equals($journal['old_manifest_sha256'], (string) hash_file('sha256', $previous . '/plugin.json'))) {
                throw new RuntimeException('旧版の退避ファイルを確認できません。退避データは保持されています。', 409);
            }
            if (file_exists($target) || is_link($target)) {
                if (!hash_equals($journal['new_manifest_sha256'], $this->installation->installationToken($id))) {
                    throw new RuntimeException('更新先が変更されたため、自動復元を中断しました。旧版は退避されています。', 409);
                }
                $failed = $directory . '/failed';
                if (!is_dir($failed) && !mkdir($failed, 0700)) { throw new RuntimeException('更新失敗時の退避先を作成できません。', 409); }
                $this->assertDirectory($failed);
                if (file_exists($failed . '/' . $id) || !@rename($target, $failed . '/' . $id)) { throw new RuntimeException('更新ファイルを退避できません。', 409); }
            }
            if (!@rename($previous, $target)) { throw new RuntimeException('旧版を復元できません。退避データは保持されています。', 409); }
            $this->invalidatePhpCache($target);
        } elseif (!hash_equals($journal['old_manifest_sha256'], $this->installation->installationToken($id))) {
            throw new RuntimeException('更新前のプラグインを確認できません。', 409);
        }
        if (!@unlink($this->journalPath($id))) { throw new RuntimeException('更新復旧記録を完了できません。', 409); }
    }

    private function updatesRoot(): string
    {
        $root = realpath($this->storageRoot);
        if ($root === false) { throw new RuntimeException('Plugin update storage is unavailable.'); }
        $path = $root . '/plugin-updates';
        if (!is_dir($path) && !mkdir($path, 0700)) { throw new RuntimeException('Plugin update storage cannot be created.'); }
        $this->assertDirectory($path);
        return $path;
    }

    private function operationPath(string $operation): string
    {
        $path = $this->updatesRoot() . '/' . $operation;
        $this->assertDirectory($path);
        return $path;
    }

    private function assertOperation(string $id, string $operation): void
    {
        if (!PluginManager::isValidId($id) || preg_match('/^' . preg_quote($id, '/') . '-[0-9]{14}-[a-f0-9]{24}$/D', $operation) !== 1) {
            throw new RuntimeException('Invalid plugin update identity.');
        }
    }

    private function journalPath(string $id): string { return $this->updatesRoot() . '/' . $id . '.pending.json'; }

    private function writeJournal(array $journal): void
    {
        $path = $this->journalPath($journal['id']);
        if (is_link($path)) { throw new RuntimeException('Unsafe plugin update journal.'); }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($journal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $path)) {
            throw new RuntimeException('更新の復旧記録を保存できません。旧版を維持します。', 409);
        }
    }

    private function assertDirectory(string $path): void
    {
        $real = realpath($path);
        $normalize = static fn (string $value): string => rtrim(str_replace('\\', '/', $value), '/');
        if ($real === false || !is_dir($path) || is_link($path)
            || (PHP_OS_FAMILY === 'Windows' ? strcasecmp($normalize($path), $normalize($real)) !== 0 : $normalize($path) !== $normalize($real))) {
            throw new RuntimeException('プラグイン更新先の配置を確認できません。', 409);
        }
    }

    private function invalidatePhpCache(string $path): void
    {
        clearstatcache();
        if (!function_exists('opcache_invalidate')) { return; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') { @opcache_invalidate($file->getPathname(), true); }
        }
    }

    private function treeFingerprint(string $directory): string
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->isLink()) { throw new RuntimeException('Unsafe update payload.', 409); }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            $hash = hash_file('sha256', $file->getPathname());
            if (!is_string($hash)) { throw new RuntimeException('Could not verify update payload.', 409); }
            $files[$relative] = $hash;
        }
        ksort($files, SORT_STRING);
        return hash('sha256', json_encode($files, JSON_THROW_ON_ERROR));
    }
}
