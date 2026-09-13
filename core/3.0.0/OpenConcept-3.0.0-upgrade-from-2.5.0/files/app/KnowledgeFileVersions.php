<?php

declare(strict_types=1);

require_once __DIR__ . '/PluginApiRepository.php';

final class KnowledgeFileVersions
{
    public static function retain(PDO $pdo, array $file, string $directory, int $actorId, string $operation): array
    {
        $name = (string) ($file['stored_name'] ?? '');
        $path = self::path($directory, $name);
        $hash = is_file($path) ? hash_file('sha256', $path) : false;
        if (!is_string($hash)) { throw new RuntimeException('The original file is missing; retention could not be verified.', 409); }
        $source = [
            'source_type' => 'file', 'source_id' => (string) $file['id'],
            'source_version' => hash('sha256', $name . "\0" . $hash), 'content_hash' => $hash,
            'page_id' => $file['page_id'] === null ? null : (int) $file['page_id'],
        ];
        $key = PluginApiRepository::sourceKey($source);
        $records = new PluginApiRepository($pdo);
        if ($records->get('core:sources', 'file-versions', $key) === null) {
            $records->put('core:sources', 'file-versions', $key, ['source' => $source, 'file' => $file,
                'replaced_by' => $actorId, 'replaced_at' => time(), 'operation' => $operation], 0);
        }
        return ['key' => $key, ...$source];
    }

    public static function path(string $directory, string $name): string
    {
        if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || str_contains($name, ':')
            || str_contains($name, "\0") || in_array($name, ['.', '..'], true)) {
            throw new RuntimeException('Invalid original file name.', 409);
        }
        $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (is_link($path)) { throw new RuntimeException('Linked original files are not supported.', 409); }
        return $path;
    }

    /** Preserve original page attributes; capture time is never its original edit time. */
    public static function revisionMetadata(PDO $pdo, array $page, array $metadata): array
    {
        $metadata['record_format_version'] = 2;
        $metadata['original_updated_at'] = $page['updated_at'] ?? null;
        $metadata['original_updated_by'] = isset($page['updated_by']) ? (int) $page['updated_by'] : null;
        $metadata['original_created_at'] = $page['created_at'] ?? null;
        $metadata['page_attributes'] = array_intersect_key($page, array_flip([
            'parent_id', 'title', 'icon', 'cover', 'status', 'category', 'manual_tags_json', 'blocks_json',
            'visibility', 'access_department', 'comments_enabled', 'author_id', 'updated_by',
            'created_at', 'updated_at', 'published_at', 'archived_at', 'language_code', 'translation_group_id',
            'source_page_id', 'source_revision', 'content_revision', 'translation_status', 'sort_order',
        ]));
        $blocks = json_decode((string) ($page['blocks_json'] ?? '[]'), true) ?: [];
        $metadata['file_versions'] = [];
        foreach ($blocks as $block) {
            $fileId = (int) ($block['file_id'] ?? 0);
            if ($fileId < 1 || isset($metadata['file_versions'][$fileId])) { continue; }
            $query = $pdo->prepare('SELECT * FROM files WHERE id = ?'); $query->execute([$fileId]);
            $file = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($file)) { continue; }
            $directory = function_exists('uploadStoragePath') ? uploadStoragePath() : '';
            if ($directory !== '' && is_file(self::path($directory, (string) $file['stored_name']))) {
                $metadata['file_versions'][$fileId] = self::retain($pdo, $file, $directory,
                    (int) ($page['updated_by'] ?? $page['author_id'] ?? 0), 'revision_reference');
            }
        }
        return $metadata;
    }
}
