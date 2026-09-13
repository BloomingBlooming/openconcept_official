<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginManager.php';

/** Moves a failed installation out of discovery, preserving all bytes and application data. */
final class PluginDetachment
{
    private ?array $moved = null;

    public function __construct(private readonly string $applicationRoot, private readonly string $storageRoot) {}

    public function managementState(array $plugin, string $backend, array $adapterState = []): array
    {
        $id = (string) $plugin['id'];
        $reason = empty($plugin['error']) ? 'plugin_not_in_error' : null;
        $adapter = match ($id) { 'database-mysql-adapter' => 'mysql', 'database-postgresql-adapter' => 'postgresql', default => null };
        if ($adapter !== null) {
            if ($backend === $adapter) { $reason = 'current_database_adapter'; }
            elseif (!empty($adapterState['config']) || !empty($adapterState['replacement'])
                || !in_array((string) ($adapterState['lifecycle'] ?? 'INSTALLED'), ['INSTALLED', 'DISABLED', 'ERROR'], true)) {
                $reason = 'database_adapter_in_use';
            }
        }
        try { $token = $this->installationToken($id); }
        catch (Throwable) { $token = ''; $reason = 'unsafe_plugin_path'; }
        return ['installation_token' => $token, 'can_detach' => $reason === null, 'detach_block_reason' => $reason];
    }

    public function installationToken(string $id): string
    {
        $directory = $this->pluginDirectory($id);
        $manifest = $directory . '/plugin.json';
        if (!is_file($manifest) || is_link($manifest) || !$this->samePath($manifest, (string) realpath($manifest))) {
            throw new RuntimeException('プラグインの配置を確認できません。', 409);
        }
        $hash = hash_file('sha256', $manifest);
        if (!is_string($hash)) { throw new RuntimeException('プラグインの配置を確認できません。', 409); }
        return $hash;
    }

    /** Shared by enable, install and detach; obtain before database actor/scope locks. */
    public function withLock(string $id, callable $operation): mixed
    {
        $lock = $this->acquireLock($id);
        try { return $operation(); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function acquireLock(string $id): mixed
    {
        if (!PluginManager::isValidId($id)) { throw new InvalidArgumentException('プラグインを確認してください。'); }
        $directory = $this->storageDirectory('.plugin-installation-locks');
        $path = $directory . '/' . $id . '.lock';
        if (is_link($path) || (file_exists($path) && !$this->samePath($path, (string) realpath($path)))) {
            throw new RuntimeException('プラグインの操作ロックを確認できません。', 409);
        }
        $handle = fopen($path, 'c');
        if (!is_resource($handle)) { throw new RuntimeException('プラグインを操作できません。', 409); }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('プラグインは別の操作で更新中です。再度お試しください。', 409);
        }
        return $handle;
    }

    /** Caller has the installation lock and an authenticated write transaction. */
    public function move(string $id, string $expectedToken): string
    {
        if ($this->moved !== null) { throw new LogicException('A plugin was already moved.'); }
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedToken) !== 1
            || !hash_equals($this->installationToken($id), $expectedToken)) {
            throw new RuntimeException('プラグインが更新されています。一覧を再読み込みしてください。', 409);
        }
        $source = $this->pluginDirectory($id);
        $parent = $this->storageDirectory('detached-plugins');
        $name = $id . '-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(12));
        $destination = $parent . '/' . $name;
        // Both resolved parents and the exact source are checked before a directory move.
        if (file_exists($destination) || is_link($destination) || !@rename($source, $destination)) {
            throw new RuntimeException('プラグインを取り外せませんでした。フォルダーの書き込み権限を確認してください。', 409);
        }
        $this->moved = ['source' => $source, 'destination' => $destination];
        return $name;
    }

    public function restoreAfterRollback(): void
    {
        if ($this->moved === null) { return; }
        $source = $this->moved['source']; $destination = $this->moved['destination'];
        $app = realpath($this->applicationRoot);
        $parent = is_string($app) ? $app . '/plugins' : '';
        if ($parent === '' || is_link($parent) || !$this->samePath($parent, (string) realpath($parent))
            || !$this->samePath(dirname($source), $parent) || file_exists($source) || is_link($source) || !is_dir($destination) || is_link($destination)
            || !$this->samePath($destination, (string) realpath($destination)) || !@rename($destination, $source)) {
            throw new RuntimeException('プラグインの取り外しを完了できませんでした。保存データと退避したコードは保持されています。', 409);
        }
        $this->moved = null;
    }

    private function pluginDirectory(string $id): string
    {
        if (!PluginManager::isValidId($id)) { throw new InvalidArgumentException('プラグインを確認してください。'); }
        $app = realpath($this->applicationRoot);
        if (!is_string($app)) { throw new RuntimeException('アプリケーションの配置を確認できません。', 409); }
        $parent = $app . '/plugins'; $path = $parent . '/' . $id;
        if (!is_dir($path)) { throw new RuntimeException('プラグインが見つかりません。', 404); }
        if (is_link($parent) || is_link($path) || !$this->samePath($parent, (string) realpath($parent))
            || !$this->samePath($path, (string) realpath($path))) {
            throw new RuntimeException('プラグインの配置が安全に取り外せる形式ではありません。', 409);
        }
        return (string) realpath($path);
    }

    private function storageDirectory(string $name): string
    {
        $storage = realpath($this->storageRoot);
        if (!is_string($storage)) { throw new RuntimeException('保存先を確認できません。', 409); }
        $directory = $storage . '/' . $name;
        if (!file_exists($directory) && !@mkdir($directory, 0700)) { throw new RuntimeException('プラグイン操作の保存先を作成できません。', 409); }
        if (!is_dir($directory) || is_link($directory) || !$this->samePath($directory, (string) realpath($directory))) {
            throw new RuntimeException('プラグイン操作の保存先を確認できません。', 409);
        }
        return (string) realpath($directory);
    }

    private function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
    }
}
