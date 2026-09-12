<?php

declare(strict_types=1);

/** Status-only updates for descendants already locked and authorized by save-page. */
final class PageSubtreeStatus
{
    /** @return list<int> Changed descendant IDs (the root is saved by save-page). */
    public static function apply(PDO $pdo, array $lockedRows, int $rootId, string $status, array $actor): array
    {
        if (!$pdo->inTransaction() || !in_array($status, ['draft', 'review', 'published', 'private'], true)) {
            throw new LogicException('A locked page transaction and an active page status are required.');
        }
        $changedIds = [];
        $revision = $pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $update = $pdo->prepare(<<<'SQL'
UPDATE pages SET status = ?, visibility = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP,
published_at = CASE WHEN ? = 'private' THEN NULL WHEN ? = 'published' AND published_at IS NULL THEN CURRENT_TIMESTAMP ELSE published_at END
WHERE id = ?
SQL);
        foreach ($lockedRows as $pageId => $page) {
            $pageId = (int) $pageId;
            if ($pageId === $rootId) { continue; }
            // Retain each page's sharing settings, except for the same private
            // visibility transition used when changing a single page's status.
            $visibility = $status === 'private' ? 'private'
                : ($page['visibility'] === 'private' ? 'company' : (string) $page['visibility']);
            if ($page['status'] === $status && $page['visibility'] === $visibility) { continue; }
            $meta = [
                'status' => $page['status'], 'category' => $page['category'],
                'tags' => json_decode($page['tags_json'], true),
                'manual_tags' => json_decode((string) ($page['manual_tags_json'] ?? '[]'), true),
                'visibility' => $page['visibility'],
                'access_department' => (string) ($page['access_department'] ?? ''),
                'access_departments' => pageAccessDepartments($pdo, $pageId, $page),
                'access_member_ids' => pageAccessMemberIds($pdo, $pageId),
                'language_code' => (string) ($page['language_code'] ?? 'und'),
                'content_revision' => (int) ($page['content_revision'] ?? 1),
                'translation_status' => (string) ($page['translation_status'] ?? 'original'),
                'source_revision' => $page['source_revision'] === null ? null : (int) $page['source_revision'],
            ];
            $meta = KnowledgeFileVersions::revisionMetadata($pdo, $page, $meta);
            $revision->execute([$pageId, $page['title'], $page['icon'], $page['blocks_json'],
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), (int) $actor['id']]);
            $update->execute([$status, $visibility, (int) $actor['id'], $status, $status, $pageId]);
            if ($visibility !== 'group') {
                $pdo->prepare('DELETE FROM page_access_members WHERE page_id = ?')->execute([$pageId]);
            }
            audit($pdo, (int) $actor['id'], 'updated', 'page', $pageId, [
                'title' => $page['title'], 'source_page_id' => $rootId,
                'previous_status' => $page['status'], 'status' => $status,
            ]);
            if ((int) $page['author_id'] !== (int) $actor['id']) {
                $actorName = cleanText((string) $actor['name'], 120) ?: 'メンバー';
                createOrRefreshNotification($pdo, (int) $page['author_id'], 'update',
                    "{$actorName}さんが「{$page['title']}」を更新しました", $pageId);
            }
            $changedIds[] = $pageId;
        }
        invalidateRagPageGenerations($pdo, $changedIds);
        return $changedIds;
    }
}
