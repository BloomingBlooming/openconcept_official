<?php

declare(strict_types=1);

final class OpenConceptRagDocumentGateway implements RagDocumentGatewayInterface
{
    /** @var Closure(array<string, mixed>, array<string, mixed>): bool */
    private Closure $accessVerifier;

    /**
     * @param callable(array<string, mixed>, array<string, mixed>): bool $accessVerifier
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $workspace,
        callable $accessVerifier
    ) {
        if ($workspace === '') {
            throw new InvalidArgumentException('RAG workspace ID is required.');
        }
        $this->accessVerifier = Closure::fromCallable($accessVerifier);
    }

    public function workspaceId(): string
    {
        return $this->workspace;
    }

    public function snapshot(string $documentId): ?DocumentSnapshot
    {
        $pageId = $this->pageId($documentId);
        if ($pageId === null) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT d.id, d.page_id, d.source_hash, d.title, d.tags_json, d.language_code,
       d.source_updated_at, d.content_revision, d.visibility, d.access_department,
       d.author_id
FROM rag_source_documents d
JOIN pages p ON p.id = d.page_id
WHERE d.page_id = ? AND d.is_current = 1
  AND p.status = 'published' AND p.archived_at IS NULL
  AND d.content_revision = p.content_revision
  AND d.source_updated_at = p.updated_at
ORDER BY d.id DESC
LIMIT 1
SQL);
        $statement->execute([$pageId]);
        $document = $statement->fetch();
        if (!is_array($document)) {
            return null;
        }

        $units = $this->pdo->prepare(<<<'SQL'
SELECT u.unit_key, u.block_type, u.content, u.unit_index, u.heading_path_json,
       u.content_sha256, ub.block_id
FROM rag_source_units u
LEFT JOIN rag_source_unit_blocks ub ON ub.source_unit_id = u.id
WHERE u.source_document_id = ?
ORDER BY u.unit_index, ub.block_id
SQL);
        $units->execute([(int) $document['id']]);
        $blocks = [];
        foreach ($units->fetchAll() as $unit) {
            $headingPath = json_decode((string) ($unit['heading_path_json'] ?? '[]'), true);
            $blocks[] = new DocumentBlock(
                (string) (($unit['block_id'] ?? '') !== '' ? $unit['block_id'] : $unit['unit_key']),
                (string) $unit['block_type'],
                (string) $unit['content'],
                (int) $unit['unit_index'],
                [
                    'unit_key' => (string) $unit['unit_key'],
                    'heading_path' => is_array($headingPath) ? array_values($headingPath) : [],
                    'content_sha256' => (string) $unit['content_sha256'],
                ]
            );
        }
        $tags = json_decode((string) ($document['tags_json'] ?? '[]'), true);
        $tags = is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
        $sourceHash = (string) $document['source_hash'];
        $updatedAt = $this->dateTime((string) $document['source_updated_at']);
        $permissions = $this->permissionSnapshot((int) $document['id'], $document);
        $aclVersion = hash('sha256', json_encode($permissions, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $permissions['acl_version'] = $aclVersion;
        return new DocumentSnapshot(
            (string) $pageId,
            $sourceHash,
            $this->workspace,
            (string) $document['title'],
            (string) ($document['language_code'] ?: 'und'),
            $tags,
            $blocks,
            $sourceHash,
            $aclVersion,
            $updatedAt,
            $permissions
        );
    }

    public function snapshots(): iterable
    {
        $statement = $this->pdo->query(<<<'SQL'
SELECT DISTINCT d.page_id
FROM rag_source_documents d
JOIN pages p ON p.id = d.page_id
WHERE d.is_current = 1 AND p.status = 'published' AND p.archived_at IS NULL
  AND d.content_revision = p.content_revision AND d.source_updated_at = p.updated_at
ORDER BY d.page_id
SQL);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $documentId) {
            $snapshot = $this->snapshot((string) $documentId);
            if ($snapshot instanceof DocumentSnapshot) {
                yield $snapshot;
            }
        }
    }

    public function verifyEvidence(array $evidence, AccessScope $scope): array
    {
        if (!hash_equals($this->workspace, $scope->workspaceId)) {
            return [];
        }
        $userStatement = $this->pdo->prepare(
            "SELECT * FROM users WHERE id = ? AND active = 1 AND role <> 'suspended'"
        );
        $userStatement->execute([$scope->userId]);
        $user = $userStatement->fetch();
        if (!is_array($user)) {
            return [];
        }

        $verified = [];
        foreach ($evidence as $item) {
            if (!$item instanceof Evidence) {
                continue;
            }
            $pageId = $this->pageId($item->documentId);
            if ($pageId === null) {
                continue;
            }
            $pageStatement = $this->pdo->prepare(<<<'SQL'
SELECT p.*, d.id AS source_document_id, d.source_hash
FROM pages p
JOIN rag_source_documents d ON d.page_id = p.id AND d.is_current = 1
WHERE p.id = ? AND p.status = 'published' AND p.archived_at IS NULL
  AND d.content_revision = p.content_revision
  AND d.source_updated_at = p.updated_at
ORDER BY d.id DESC
LIMIT 1
SQL);
            $pageStatement->execute([$pageId]);
            $page = $pageStatement->fetch();
            if (!is_array($page)
                || !hash_equals((string) $page['source_hash'], $item->revisionId)
                || !(($this->accessVerifier)($user, $page))) {
                continue;
            }
            $sourceBlocks = $this->sourceBlocks((int) $page['source_document_id'], $item->blockIds);
            if (count($sourceBlocks) !== count(array_unique($item->blockIds))) {
                continue;
            }
            $excerpt = $this->verifiedExcerpt($item->excerpt, $sourceBlocks);
            $verified[] = new Evidence(
                (string) $pageId,
                (string) $page['source_hash'],
                array_values(array_unique($item->blockIds)),
                $excerpt,
                $item->score,
                $item->retrievalMethod,
                $item->metadata,
                $item->stableChunkId()
            );
        }
        return $verified;
    }

    /** @param array<string, mixed> $document @return array<string, mixed> */
    private function permissionSnapshot(int $sourceDocumentId, array $document): array
    {
        $principals = ['user:' . (int) ($document['author_id'] ?? 0)];
        $members = $this->pdo->prepare(
            'SELECT user_id FROM rag_source_access_members WHERE source_document_id = ? ORDER BY user_id'
        );
        $members->execute([$sourceDocumentId]);
        foreach ($members->fetchAll(PDO::FETCH_COLUMN) as $userId) {
            $principals[] = 'user:' . (int) $userId;
        }
        $departments = $this->pdo->prepare(
            'SELECT department FROM rag_source_access_departments WHERE source_document_id = ? ORDER BY department'
        );
        $departments->execute([$sourceDocumentId]);
        foreach ($departments->fetchAll(PDO::FETCH_COLUMN) as $department) {
            $department = trim((string) $department);
            if ($department !== '') {
                $principals[] = 'group:' . $department;
            }
        }
        $fallbackDepartment = trim((string) ($document['access_department'] ?? ''));
        if ($fallbackDepartment !== '') {
            $principals[] = 'group:' . $fallbackDepartment;
        }
        return [
            'visibility' => (string) ($document['visibility'] ?? 'restricted'),
            'allowed_principals' => array_values(array_unique(array_filter(
                $principals,
                static fn (string $principal): bool => $principal !== 'user:0'
            ))),
        ];
    }

    /** @param list<string> $blockIds @return array<string, string> */
    private function sourceBlocks(int $sourceDocumentId, array $blockIds): array
    {
        $blockIds = array_values(array_unique(array_filter($blockIds, 'is_string')));
        if ($blockIds === [] || count($blockIds) > 100) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($blockIds), '?'));
        $statement = $this->pdo->prepare(<<<SQL
SELECT ub.block_id, u.content
FROM rag_source_unit_blocks ub
JOIN rag_source_units u ON u.id = ub.source_unit_id
WHERE u.source_document_id = ? AND ub.block_id IN ({$placeholders})
ORDER BY u.unit_index
SQL);
        $statement->execute([$sourceDocumentId, ...$blockIds]);
        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $blockId = (string) $row['block_id'];
            $result[$blockId] = trim(($result[$blockId] ?? '') . "\n" . (string) $row['content']);
        }
        return $result;
    }

    /** @param array<string, string> $sourceBlocks */
    private function verifiedExcerpt(string $candidate, array $sourceBlocks): string
    {
        $source = trim(implode("\n", $sourceBlocks));
        $candidate = trim($candidate);
        if ($candidate !== '' && str_contains($this->normalizeText($source), $this->normalizeText($candidate))) {
            return $this->sliceText($candidate, 1200);
        }
        return $this->sliceText($source, 1200);
    }

    private function pageId(string $documentId): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $documentId) !== 1) {
            return null;
        }
        $pageId = (int) $documentId;
        return $pageId > 0 ? $pageId : null;
    }

    private function dateTime(string $value): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return new DateTimeImmutable('@0');
        }
    }

    private function normalizeText(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function sliceText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
