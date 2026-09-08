<?php

declare(strict_types=1);

/**
 * Core translation orchestration. It owns authorization, relationships,
 * revisions and transactional writes, while text generation is delegated to
 * a registered TranslationProviderInterface implementation.
 */
final class TranslationService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TranslationProviderRegistry $providers
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(int $requestedPageId, array $user): array
    {
        $source = $this->authorizedSourcePage($requestedPageId, $user);
        $sourceId = (int) $source['id'];
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.*
FROM pages p
WHERE p.archived_at IS NULL
  AND (p.id = ? OR p.source_page_id = ?)
ORDER BY CASE WHEN p.id = ? THEN 0 ELSE 1 END, p.language_code, p.id
SQL);
        $statement->execute([$sourceId, $sourceId, $sourceId]);
        $versions = [];
        foreach ($statement->fetchAll() as $page) {
            if (!canViewPage($this->pdo, $user, $page)) {
                continue;
            }
            $isOriginal = (int) $page['id'] === $sourceId;
            $sourceRevision = $isOriginal ? null : (int) ($page['source_revision'] ?? 0);
            $versions[] = [
                'page_id' => (int) $page['id'],
                'language' => (string) ($page['language_code'] ?? 'und'),
                'role' => $isOriginal ? 'original' : 'translation',
                'translation_status' => $isOriginal ? 'original' : (string) ($page['translation_status'] ?? 'machine_translated'),
                'source_revision' => $sourceRevision,
                'content_revision' => (int) ($page['content_revision'] ?? 1),
                'outdated' => !$isOriginal && $sourceRevision < (int) ($source['content_revision'] ?? 1),
                'can_edit' => canEditPage($this->pdo, $user, $page),
                'title' => (string) $page['title'],
            ];
        }
        $descendants = $this->descendantOverview($source, $user);
        return [
            'source_page_id' => $sourceId,
            'source_language' => (string) ($source['language_code'] ?? 'und'),
            'source_revision' => (int) ($source['content_revision'] ?? 1),
            'translation_group_id' => $this->displayGroupId($source),
            'versions' => $versions,
            'providers' => $this->providers->summaries(),
            'default_provider_id' => (string) setting($this->pdo, 'translation.default_provider_id', 'openai'),
            'descendants' => $descendants,
        ];
    }

    /** @return array{page_id: int, created: bool, source_revision: int} */
    public function createOrUpdate(
        int $requestedPageId,
        string $targetLanguage,
        string $providerId,
        bool $replaceExisting,
        array $user,
        string $expectedPasswordFingerprint
    ): array {
        return $this->createOrUpdateTree(
            $requestedPageId,
            [],
            $targetLanguage,
            $providerId,
            $replaceExisting,
            $user,
            $expectedPasswordFingerprint
        );
    }

    /**
     * Translate the requested source page plus selected descendants while
     * retaining the selected source hierarchy in the translated pages.
     * Descendant ancestors are added automatically so a grandchild can never
     * be materialized without its translated parent.
     *
     * @param array<int, mixed> $selectedPageIds Descendant source page IDs; the root is implicit.
     * @return array{
     *   page_id: int,
     *   created: bool,
     *   source_revision: int,
     *   page_count: int,
     *   created_count: int,
     *   updated_count: int,
     *   pages: list<array{source_page_id: int, page_id: int, parent_id: ?int, created: bool, source_revision: int}>
     * }
     */
    public function createOrUpdateTree(
        int $requestedPageId,
        array $selectedPageIds,
        string $targetLanguage,
        string $providerId,
        bool $replaceExisting,
        array $user,
        string $expectedPasswordFingerprint
    ): array {
        $targetLanguage = trim($targetLanguage);
        if (!I18n::isValidLocale($targetLanguage)) {
            throw new RuntimeException('翻訳先の言語コードが正しくありません。', 422);
        }
        if (!canCreatePage($user)) {
            throw new RuntimeException('翻訳版を作成する権限がありません。', 403);
        }

        if (count($selectedPageIds) > 50) {
            throw new RuntimeException('一度に翻訳できる子ページは50件までです。', 422);
        }

        // These snapshots are immutable provider inputs. No transaction or
        // database lock is held while waiting for an external provider.
        $rootSource = $this->authorizedSourcePage($requestedPageId, $user);
        $rootSourceId = (int) $rootSource['id'];
        $tree = $this->authorizedSourceTree($rootSource, $user);
        $treeById = [];
        foreach ($tree as $entry) {
            $treeById[(int) $entry['page']['id']] = $entry;
        }

        $selected = [$rootSourceId => true];
        foreach ($selectedPageIds as $selectedPageId) {
            if (is_int($selectedPageId)) {
                $pageId = $selectedPageId;
            } elseif (is_string($selectedPageId) && preg_match('/^[1-9][0-9]*$/D', $selectedPageId) === 1) {
                $pageId = (int) $selectedPageId;
            } else {
                throw new RuntimeException('翻訳対象ページの指定が正しくありません。', 422);
            }
            if ($pageId === $rootSourceId) {
                continue;
            }
            if (!isset($treeById[$pageId])) {
                throw new RuntimeException('翻訳対象に指定できない子ページが含まれています。', 422);
            }
            $cursor = $pageId;
            $visited = [];
            while ($cursor !== $rootSourceId) {
                if (isset($visited[$cursor]) || !isset($treeById[$cursor])) {
                    throw new RuntimeException('翻訳対象ページの階層が正しくありません。', 422);
                }
                $visited[$cursor] = true;
                $selected[$cursor] = true;
                $parentId = $treeById[$cursor]['page']['parent_id'];
                if ($parentId === null) {
                    throw new RuntimeException('翻訳対象ページの階層が正しくありません。', 422);
                }
                $cursor = (int) $parentId;
            }
        }

        $sources = [];
        foreach ($tree as $entry) {
            $sourceId = (int) $entry['page']['id'];
            if (isset($selected[$sourceId])) {
                $sources[] = $entry['page'];
            }
        }
        if ($sources === [] || count($sources) > 51) {
            throw new RuntimeException('翻訳対象ページの件数が正しくありません。', 422);
        }

        $prepared = [];
        foreach ($sources as $source) {
            $sourceId = (int) $source['id'];
            $sourceLanguage = (string) ($source['language_code'] ?? 'und');
            if ($targetLanguage === $sourceLanguage) {
                throw new RuntimeException('原文と異なる翻訳先言語を選択してください。', 422);
            }
            $existing = $this->translationForLanguage($sourceId, $targetLanguage);
            if ($existing && !$replaceExisting) {
                throw new RuntimeException('選択範囲に、この言語の翻訳版が既に存在するページがあります。', 409);
            }
            if ($existing && !canEditPage($this->pdo, $user, $existing)) {
                throw new RuntimeException('既存の翻訳版を更新する権限がありません。', 403);
            }
            $prepared[$sourceId] = [
                'source' => $source,
                'existing' => $existing,
                'source_revision' => (int) ($source['content_revision'] ?? 1),
                'target_revision' => $existing ? (int) ($existing['content_revision'] ?? 1) : null,
            ];
        }

        $provider = $this->providers->provider($providerId);
        foreach ($prepared as $sourceId => &$item) {
            $source = $item['source'];
            $plan = $this->translationPlan($source);
            $translated = $provider->translate(
                $plan['segments'],
                (string) ($source['language_code'] ?? 'und'),
                $targetLanguage
            );
            $materialized = $this->materialize($plan, $translated);
            $translatedBlocks = sanitizeBlocks($materialized['blocks']);
            $translatedTitle = cleanText($materialized['title'], 240);
            if ($translatedTitle === '') {
                throw new RuntimeException('翻訳結果にページタイトルがありません。', 502);
            }
            $item['translated_blocks'] = $translatedBlocks;
            $item['translated_title'] = $translatedTitle;
        }
        unset($item);

        $lockIds = array_keys($prepared);
        foreach ($prepared as $item) {
            if (is_array($item['existing'])) {
                $lockIds[] = (int) $item['existing']['id'];
            }
        }

        $pageResults = [];
        $translatedIdsBySource = [];
        $createdCount = 0;
        beginPageWriteTransaction($this->pdo);
        try {
            $liveUser = lockedAuthenticatedApplicationUserForWrite(
                $this->pdo,
                $user,
                $expectedPasswordFingerprint
            );
            if ($liveUser === null) {
                throw new RuntimeException('ログイン状態が変更されました。もう一度ログインしてください。', 401);
            }
            if (!canCreatePage($liveUser)) {
                throw new RuntimeException('翻訳版を作成する権限がありません。', 403);
            }
            $locked = lockedPageHierarchiesForUpdate($this->pdo, $lockIds);
            if ($locked['reason'] !== null) {
                throw new RuntimeException('翻訳対象ページの階層が変更または削除されました。', 409);
            }

            foreach ($prepared as $sourceId => $item) {
                $source = $item['source'];
                $liveSource = $locked['rows'][$sourceId] ?? null;
                if (!is_array($liveSource) || $liveSource['archived_at'] !== null) {
                    throw new RuntimeException('原文ページが変更または削除されました。', 409);
                }
                if (!canViewPage($this->pdo, $liveUser, $liveSource)) {
                    throw new RuntimeException('原文ページを閲覧する権限がありません。', 403);
                }
                if ((int) ($liveSource['content_revision'] ?? 1) !== (int) $item['source_revision']
                    || !hash_equals($this->sourceFingerprint($source), $this->sourceFingerprint($liveSource))) {
                    throw new RuntimeException('翻訳中に原文またはページ階層が更新されました。最新の原文からもう一度翻訳してください。', 409);
                }

                $existing = $item['existing'];
                $targetId = is_array($existing) ? (int) $existing['id'] : 0;
                $liveExisting = $targetId > 0 ? ($locked['rows'][$targetId] ?? null) : null;
                if ($targetId > 0) {
                    if (!is_array($liveExisting)
                        || (int) ($liveExisting['source_page_id'] ?? 0) !== $sourceId
                        || (string) ($liveExisting['language_code'] ?? '') !== $targetLanguage
                        || (int) ($liveExisting['content_revision'] ?? 1) !== (int) $item['target_revision']) {
                        throw new RuntimeException('翻訳版が別の操作で変更されました。', 409);
                    }
                    if (!canEditPage($this->pdo, $liveUser, $liveExisting)) {
                        throw new RuntimeException('既存の翻訳版を更新する権限がありません。', 403);
                    }
                } elseif ($this->translationForLanguage($sourceId, $targetLanguage, true)) {
                    throw new RuntimeException('この言語の翻訳版は別の操作で作成されました。', 409);
                }

                $isRoot = $sourceId === $rootSourceId;
                $sourceParentId = $liveSource['parent_id'] === null ? null : (int) $liveSource['parent_id'];
                if ($isRoot) {
                    $translatedParentId = $sourceParentId;
                    $translatedSortOrder = $liveExisting ? (int) $liveExisting['sort_order'] : null;
                } else {
                    if ($sourceParentId === null || !isset($translatedIdsBySource[$sourceParentId])) {
                        throw new RuntimeException('翻訳ページの親子関係を構築できませんでした。', 409);
                    }
                    $translatedParentId = $translatedIdsBySource[$sourceParentId];
                    $translatedSortOrder = (int) ($liveSource['sort_order'] ?? 0);
                }

                $groupId = trim((string) ($liveSource['translation_group_id'] ?? ''));
                if ($groupId === '') {
                    $groupId = 'tg-' . bin2hex(random_bytes(16));
                    $this->pdo->prepare('UPDATE pages SET translation_group_id = ? WHERE id = ?')->execute([$groupId, $sourceId]);
                }
                $translatedBlocks = $item['translated_blocks'];
                $translatedTitle = (string) $item['translated_title'];
                $snapshotRevision = (int) $item['source_revision'];
                $blockMap = $this->blockMap($source, $translatedBlocks);
                $created = !$liveExisting;
                if ($liveExisting) {
                    $this->insertRevision($liveExisting, $liveUser);
                    $update = $this->pdo->prepare(<<<'SQL'
UPDATE pages
SET parent_id = ?, sort_order = ?, title = ?, blocks_json = ?, plain_text = ?, source_revision = ?, translation_status = 'machine_translated',
    translation_block_map_json = ?, content_revision = content_revision + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP
WHERE id = ?
SQL);
                    $update->execute([
                        $translatedParentId,
                        $translatedSortOrder ?? (int) $liveExisting['sort_order'],
                        $translatedTitle,
                        json_encode($translatedBlocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        utf8Slice(blocksPlainText($translatedBlocks), 100000),
                        $snapshotRevision,
                        json_encode($blockMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                        (int) $liveUser['id'],
                        $targetId,
                    ]);
                    invalidateRagPageGenerations($this->pdo, [$targetId]);
                } else {
                    $targetId = $this->insertTranslationPage(
                        $liveSource,
                        $liveUser,
                        $translatedTitle,
                        $translatedBlocks,
                        $blockMap,
                        $groupId,
                        $targetLanguage,
                        $snapshotRevision,
                        $translatedParentId,
                        $translatedSortOrder
                    );
                    $createdCount++;
                }

                $translatedIdsBySource[$sourceId] = $targetId;
                $pageResults[] = [
                    'source_page_id' => $sourceId,
                    'page_id' => $targetId,
                    'parent_id' => $translatedParentId,
                    'created' => $created,
                    'source_revision' => $snapshotRevision,
                ];
                audit($this->pdo, (int) $liveUser['id'], $created ? 'translation_created' : 'translation_updated', 'page', $targetId, [
                    'source_page_id' => $sourceId,
                    'source_revision' => $snapshotRevision,
                    'language' => $targetLanguage,
                    'provider' => $providerId,
                    'hierarchy_root_page_id' => $rootSourceId,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        foreach ($pageResults as $pageResult) {
            $sourceId = (int) $pageResult['source_page_id'];
            $targetId = (int) $pageResult['page_id'];
            if ($pageResult['created']) {
                Hooks::action('article_created', ['id' => $targetId, 'source_page_id' => $sourceId, 'source' => 'translation']);
            } else {
                Hooks::action('article_updated', ['id' => $targetId, 'source_page_id' => $sourceId, 'source' => 'translation'], $prepared[$sourceId]['existing']);
            }
            queueRagPage($this->pdo, $targetId);
        }

        $rootResult = $pageResults[0];
        return [
            'page_id' => (int) $rootResult['page_id'],
            'created' => (bool) $rootResult['created'],
            'source_revision' => (int) $rootResult['source_revision'],
            'page_count' => count($pageResults),
            'created_count' => $createdCount,
            'updated_count' => count($pageResults) - $createdCount,
            'pages' => $pageResults,
        ];
    }

    /** @return array<string, mixed> */
    private function authorizedSourcePage(int $requestedPageId, array $user): array
    {
        $requested = $this->page($requestedPageId);
        if (!$requested || $requested['archived_at'] !== null || !canViewPage($this->pdo, $user, $requested)) {
            throw new RuntimeException('原文ページを閲覧する権限がありません。', 403);
        }
        if ((int) ($requested['source_page_id'] ?? 0) < 1) {
            return $requested;
        }
        $sourceId = (int) $requested['source_page_id'];
        $source = $this->page($sourceId);
        if (!$source || $source['archived_at'] !== null || !canViewPage($this->pdo, $user, $source)) {
            throw new RuntimeException('原文ページを閲覧する権限がありません。', 403);
        }
        return $source;
    }

    /**
     * @return list<array{page: array<string, mixed>, depth: int}>
     */
    private function authorizedSourceTree(array $root, array $user): array
    {
        $rows = $this->pdo->query(<<<'SQL'
SELECT *
FROM pages
WHERE archived_at IS NULL AND source_page_id IS NULL
ORDER BY parent_id, sort_order, id
SQL)->fetchAll();
        $children = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parentId = $row['parent_id'] === null ? 0 : (int) $row['parent_id'];
            $children[$parentId][] = $row;
        }

        $rootId = (int) $root['id'];
        $result = [['page' => $root, 'depth' => 0]];
        $visited = [$rootId => true];
        $walk = function (int $parentId, int $depth) use (&$walk, &$result, &$visited, $children, $user): void {
            if ($depth > 64) {
                throw new RuntimeException('ページ階層が深すぎるため翻訳できません。', 422);
            }
            foreach ($children[$parentId] ?? [] as $child) {
                $childId = (int) $child['id'];
                if (isset($visited[$childId])) {
                    throw new RuntimeException('ページ階層に循環があるため翻訳できません。', 422);
                }
                if (!canViewPage($this->pdo, $user, $child)) {
                    continue;
                }
                $visited[$childId] = true;
                $result[] = ['page' => $child, 'depth' => $depth];
                $walk($childId, $depth + 1);
            }
        };
        $walk($rootId, 1);
        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function descendantOverview(array $root, array $user): array
    {
        $tree = $this->authorizedSourceTree($root, $user);
        $translations = $this->pdo->prepare(<<<'SQL'
SELECT *
FROM pages
WHERE source_page_id = ? AND archived_at IS NULL
ORDER BY language_code, id
SQL);
        $descendants = [];
        foreach ($tree as $entry) {
            $page = $entry['page'];
            $pageId = (int) $page['id'];
            if ($pageId === (int) $root['id']) {
                continue;
            }
            $translations->execute([$pageId]);
            $versions = [];
            foreach ($translations->fetchAll() as $translation) {
                if (!is_array($translation) || !canViewPage($this->pdo, $user, $translation)) {
                    continue;
                }
                $versions[] = [
                    'page_id' => (int) $translation['id'],
                    'language' => (string) ($translation['language_code'] ?? 'und'),
                    'translation_status' => (string) ($translation['translation_status'] ?? 'machine_translated'),
                    'source_revision' => (int) ($translation['source_revision'] ?? 0),
                    'outdated' => (int) ($translation['source_revision'] ?? 0) < (int) ($page['content_revision'] ?? 1),
                    'can_edit' => canEditPage($this->pdo, $user, $translation),
                    'title' => (string) $translation['title'],
                ];
            }
            $descendants[] = [
                'page_id' => $pageId,
                'parent_page_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
                'depth' => (int) $entry['depth'],
                'title' => (string) $page['title'],
                'icon' => (string) ($page['icon'] ?? '📄'),
                'language' => (string) ($page['language_code'] ?? 'und'),
                'content_revision' => (int) ($page['content_revision'] ?? 1),
                'translations' => $versions,
            ];
        }
        return $descendants;
    }

    /** @return array<string, mixed>|null */
    private function page(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pages WHERE id = ?');
        $statement->execute([$id]);
        $page = $statement->fetch();
        return is_array($page) ? $page : null;
    }

    /** @return array<string, mixed>|null */
    private function translationForLanguage(int $sourceId, string $language, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM pages WHERE source_page_id = ? AND language_code = ? AND archived_at IS NULL ORDER BY id LIMIT 1';
        if ($forUpdate && (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$sourceId, $language]);
        $page = $statement->fetch();
        return is_array($page) ? $page : null;
    }

    /** @return array{segments: list<array{id: string, text: string, context: string}>, title: array<int, mixed>, blocks: array<int, array<string, mixed>>, templates: array<string, array<int, mixed>>} */
    private function translationPlan(array $source): array
    {
        $segments = [];
        $templates = [];
        $blocks = json_decode((string) ($source['blocks_json'] ?? '[]'), true);
        $blocks = is_array($blocks) ? $blocks : [];
        $titleTemplate = $this->htmlTemplate(htmlspecialchars((string) $source['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'title', 'page title', $segments);
        foreach ($blocks as $index => &$block) {
            if (!is_array($block)) {
                continue;
            }
            $block['id'] = 'b-' . bin2hex(random_bytes(8));
            $type = (string) ($block['type'] ?? 'paragraph');
            if (!in_array($type, ['code', 'divider', 'file'], true)) {
                $key = 'block-' . $index . '-content';
                $templates[$key] = $this->htmlTemplate((string) ($block['content'] ?? ''), $key, 'block ' . $type, $segments);
            }
            if ($type === 'table') {
                foreach ($block['table_rows'] ?? [] as $rowIndex => $row) {
                    foreach (is_array($row) ? $row : [] as $columnIndex => $cell) {
                        $key = 'block-' . $index . '-cell-' . $rowIndex . '-' . $columnIndex;
                        $templates[$key] = $this->htmlTemplate((string) ($cell['content'] ?? ''), $key, 'table cell', $segments);
                    }
                }
            }
        }
        unset($block);
        return ['segments' => $segments, 'title' => $titleTemplate, 'blocks' => $blocks, 'templates' => $templates];
    }

    /** @param list<array{id: string, text: string, context: string}> $segments @return array<int, mixed> */
    private function htmlTemplate(string $html, string $prefix, string $context, array &$segments): array
    {
        $parts = preg_split('/(<[^>]+>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $template = [];
        $sequence = 0;
        foreach ($parts as $part) {
            if ($part === '' || str_starts_with($part, '<')) {
                $template[] = ['literal' => $part];
                continue;
            }
            $decoded = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim($decoded) === '') {
                $template[] = ['literal' => $part];
                continue;
            }
            preg_match('/^(\s*)(.*?)(\s*)$/us', $decoded, $matches);
            $id = $prefix . '-' . $sequence++;
            [$text, $protected] = $this->protectNonTranslatableText((string) ($matches[2] ?? $decoded));
            $segments[] = ['id' => $id, 'text' => $text, 'context' => $context];
            $template[] = [
                'segment' => $id,
                'prefix' => (string) ($matches[1] ?? ''),
                'suffix' => (string) ($matches[3] ?? ''),
                'protected' => $protected,
            ];
        }
        return $template;
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function protectNonTranslatableText(string $text): array
    {
        $pattern = <<<'REGEX'
~(?:
    https?://[A-Za-z0-9\-._\~:/?#\[\]@!$&'()*+,;=%]+
  | mailto:[A-Za-z0-9.!#$%&'*+/=?^_`{|}\~-]+@[A-Za-z0-9.-]+
  | [A-Za-z0-9.!#$%&'*+/=?^_`{|}\~-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}
  | \b[A-Za-z]:[\\/][^\s<>"']+
  | (?<![A-Za-z0-9])(?:\.\.?[\\/]|/)[A-Za-z0-9._\~\\/-]+
  | [^\s/\\<>:"']+\.(?:pdf|docx?|xlsx?|pptx?|csv|tsv|json|xml|ya?ml|md|txt|png|jpe?g|gif|webp|svg|zip|php|js|css|sql)\b
  | \$[A-Za-z_][A-Za-z0-9_]*
  | \b[A-Za-z_][A-Za-z0-9_]*\(\)
  | \b[A-Za-z_][A-Za-z0-9_]*_[A-Za-z0-9_]+\b
  | \b[A-Z]?[a-z]+[A-Z][A-Za-z0-9]*\b
  | \b[A-Z][A-Z0-9_]{1,}\b
  | \b(?:oc-(?:page|chunk)-[A-Za-z0-9-]+|[A-Z]{2,10}-\d[A-Za-z0-9-]*)\b
  | \{\{?[A-Za-z_][A-Za-z0-9_.-]*\}?\}
  | %(?:\d+\$)?[bcdeEfFgGosuxX]
)~ux
REGEX;
        $protected = [];
        $replaced = preg_replace_callback($pattern, static function (array $match) use (&$protected): string {
            do {
                $placeholder = '__OPENCONCEPT_LITERAL_' . bin2hex(random_bytes(12)) . '__';
            } while (str_contains($match[0], $placeholder) || isset($protected[$placeholder]));
            $protected[$placeholder] = (string) $match[0];
            return $placeholder;
        }, $text);
        if (!is_string($replaced)) {
            throw new RuntimeException('翻訳対象テキストを安全に分解できませんでした。', 422);
        }
        return [$replaced, $protected];
    }

    /** @return array{title: string, blocks: array<int, array<string, mixed>>} */
    private function materialize(array $plan, array $translated): array
    {
        $expected = array_column($plan['segments'], 'id');
        sort($expected, SORT_STRING);
        $actual = array_keys($translated);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('翻訳プロバイダーの応答に不足または不明なsegment IDがあります。', 502);
        }
        foreach ($translated as $id => $text) {
            if (!is_string($id) || !is_string($text) || strlen($text) > 80000) {
                throw new RuntimeException('翻訳プロバイダーの応答形式が正しくありません。', 502);
            }
        }
        $blocks = $plan['blocks'];
        foreach ($blocks as $index => &$block) {
            $contentKey = 'block-' . $index . '-content';
            if (isset($plan['templates'][$contentKey])) {
                $block['content'] = $this->renderTemplate($plan['templates'][$contentKey], $translated);
            }
            if (($block['type'] ?? '') === 'table' && is_array($block['table_rows'] ?? null)) {
                foreach ($block['table_rows'] as $rowIndex => &$row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($row as $columnIndex => &$cell) {
                        if (!is_array($cell)) {
                            continue;
                        }
                        $key = 'block-' . $index . '-cell-' . $rowIndex . '-' . $columnIndex;
                        if (isset($plan['templates'][$key])) {
                            $cell['content'] = $this->renderTemplate($plan['templates'][$key], $translated);
                        }
                    }
                    unset($cell);
                }
                unset($row);
            }
        }
        unset($block);
        return [
            'title' => html_entity_decode(strip_tags($this->renderTemplate($plan['title'], $translated)), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'blocks' => $blocks,
        ];
    }

    /** @param array<int, mixed> $template @param array<string, string> $translated */
    private function renderTemplate(array $template, array $translated): string
    {
        $html = '';
        foreach ($template as $part) {
            if (isset($part['literal'])) {
                $html .= (string) $part['literal'];
                continue;
            }
            $id = (string) ($part['segment'] ?? '');
            $translatedText = (string) ($translated[$id] ?? '');
            foreach ((array) ($part['protected'] ?? []) as $placeholder => $original) {
                if (!is_string($placeholder) || !is_string($original) || substr_count($translatedText, $placeholder) !== 1) {
                    throw new RuntimeException('翻訳プロバイダーが保護対象のURLまたは識別子を変更しました。', 502);
                }
            }
            $translatedText = htmlspecialchars($translatedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ((array) ($part['protected'] ?? []) as $placeholder => $original) {
                $translatedText = str_replace(
                    $placeholder,
                    htmlspecialchars((string) $original, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    $translatedText
                );
            }
            $html .= (string) ($part['prefix'] ?? '')
                . $translatedText
                . (string) ($part['suffix'] ?? '');
        }
        return $html;
    }

    /** @return array<string, string> */
    private function blockMap(array $source, array $translatedBlocks): array
    {
        $sourceBlocks = json_decode((string) ($source['blocks_json'] ?? '[]'), true);
        $sourceBlocks = is_array($sourceBlocks) ? $sourceBlocks : [];
        $map = [];
        foreach ($translatedBlocks as $index => $block) {
            $sourceId = (string) ($sourceBlocks[$index]['id'] ?? '');
            $translatedId = (string) ($block['id'] ?? '');
            if ($sourceId !== '' && $translatedId !== '') {
                $map[$translatedId] = $sourceId;
            }
        }
        return $map;
    }

    private function insertTranslationPage(
        array $source,
        array $user,
        string $title,
        array $blocks,
        array $blockMap,
        string $groupId,
        string $targetLanguage,
        int $sourceRevision,
        ?int $parentId,
        ?int $sortOrder
    ): int {
        if ($sortOrder === null) {
            $sort = $parentId === null
                ? $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id IS NULL AND archived_at IS NULL')
                : $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id = ? AND archived_at IS NULL');
            $sort->execute($parentId === null ? [] : [$parentId]);
            $sortOrder = (int) $sort->fetchColumn();
        }
        $private = (string) $source['status'] === 'private' || (string) $source['visibility'] === 'private';
        $status = $private ? 'private' : 'draft';
        $visibility = $private ? 'private' : (string) $source['visibility'];
        $accessDepartments = pageAccessDepartments($this->pdo, (int) $source['id'], $source);
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pages
    (parent_id, sort_order, title, icon, cover, status, category, tags_json, manual_tags_json, blocks_json, plain_text,
     visibility, access_department, comments_enabled, author_id, updated_by, language_code, translation_group_id,
     source_page_id, source_revision, translation_status, translation_block_map_json, content_revision)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'machine_translated', ?, 1)
SQL);
        $manualTags = (string) ($source['manual_tags_json'] ?? $source['tags_json'] ?? '[]');
        $statement->execute([
            $parentId,
            $sortOrder,
            $title,
            (string) $source['icon'],
            (string) $source['cover'],
            $status,
            (string) $source['category'],
            $manualTags,
            $manualTags,
            json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            utf8Slice(blocksPlainText($blocks), 100000),
            $visibility,
            legacyPageAccessDepartment($accessDepartments, (string) ($source['access_department'] ?? '')),
            (int) ($source['comments_enabled'] ?? 1),
            (int) $user['id'],
            (int) $user['id'],
            $targetLanguage,
            $groupId,
            (int) $source['id'],
            $sourceRevision,
            json_encode($blockMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        replacePageAccessDepartments($this->pdo, $id, $accessDepartments, (int) $user['id']);
        if ($visibility === 'group') {
            $copy = $this->pdo->prepare('INSERT INTO page_access_members (page_id, user_id, granted_by) SELECT ?, user_id, ? FROM page_access_members WHERE page_id = ?');
            $copy->execute([$id, (int) $user['id'], (int) $source['id']]);
        }
        return $id;
    }

    private function insertRevision(array $page, array $user): void
    {
        $meta = [
            'status' => (string) $page['status'],
            'category' => (string) $page['category'],
            'tags' => json_decode((string) $page['tags_json'], true) ?: [],
            'manual_tags' => json_decode((string) ($page['manual_tags_json'] ?? '[]'), true) ?: [],
            'visibility' => (string) $page['visibility'],
            'access_department' => (string) ($page['access_department'] ?? ''),
            'access_departments' => pageAccessDepartments($this->pdo, (int) $page['id'], $page),
            'access_member_ids' => pageAccessMemberIds($this->pdo, (int) $page['id']),
            'language_code' => (string) ($page['language_code'] ?? 'und'),
            'content_revision' => (int) ($page['content_revision'] ?? 1),
            'translation_status' => (string) ($page['translation_status'] ?? 'original'),
            'source_revision' => $page['source_revision'] === null ? null : (int) $page['source_revision'],
        ];
        $revision = $this->pdo->prepare('INSERT INTO revisions (page_id, title, icon, blocks_json, meta_json, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $revision->execute([
            (int) $page['id'],
            (string) $page['title'],
            (string) $page['icon'],
            (string) $page['blocks_json'],
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            (int) $user['id'],
        ]);
    }

    private function sourceFingerprint(array $page): string
    {
        return hash('sha256', json_encode([
            'title' => (string) ($page['title'] ?? ''),
            'blocks_json' => (string) ($page['blocks_json'] ?? '[]'),
            'content_revision' => (int) ($page['content_revision'] ?? 1),
            'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
            'sort_order' => (int) ($page['sort_order'] ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function displayGroupId(array $source): string
    {
        $group = trim((string) ($source['translation_group_id'] ?? ''));
        return $group !== '' ? $group : 'page-' . (int) $source['id'];
    }
}
