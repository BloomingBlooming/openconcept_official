<?php

declare(strict_types=1);

require_once __DIR__ . '/SafeHtml.php';

/**
 * Registers the packaged Japanese operation manual as ordinary OpenConcept
 * pages during the one-time workspace initialization transaction.
 *
 * The Markdown document is the single source of truth. Its H1 becomes the
 * manual root and each H2 becomes a child page, so the installed copy remains
 * editable while the distribution still contains a directly readable manual.
 */
final class DefaultOperationManual
{
    public const VERSION = '3.0.0-ja-1';
    public const LOCALE = 'ja-JP';
    public const MARKER_KEY = 'default_content.operation_manual.ja-JP';
    public const SOURCE_FILE = 'openconcept-operation-manual.ja.md';

    /** @var list<string> */
    private const CHAPTER_ICONS = ['🧭', '📝', '🧩', '🔎', '📌', '🔐', '✦', '↗', '◉', '🛠️', '🤝', '🎯', '🔐', '◷', '💡'];

    /** @var list<string> */
    private const CHAPTER_COVERS = ['blue', 'mint', 'sand', 'blue', 'coral', 'lavender', 'lavender', 'blue', 'sand', 'coral', 'mint', 'blue', 'sand', 'lavender', 'mint'];

    /**
     * @return array{created: bool, root_page_id: int|null, page_ids: list<int>, version: string}
     */
    public static function register(PDO $pdo, int $administratorId, ?string $sourcePath = null): array
    {
        $existing = self::marker($pdo);
        if ($existing !== null) {
            return [
                'created' => false,
                'root_page_id' => isset($existing['root_page_id']) ? (int) $existing['root_page_id'] : null,
                'page_ids' => array_values(array_map('intval', is_array($existing['page_ids'] ?? null) ? $existing['page_ids'] : [])),
                'version' => (string) ($existing['version'] ?? self::VERSION),
            ];
        }

        $definition = self::definition($sourcePath);
        $administrator = $pdo->prepare("SELECT id, department FROM users WHERE id = ? AND active = 1 AND role = 'admin'");
        $administrator->execute([$administratorId]);
        $owner = $administrator->fetch();
        if (!is_array($owner)) {
            throw new RuntimeException('The default operation manual requires an active administrator.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }

        try {
            // Recheck after acquiring the caller's write transaction. The
            // initialization endpoint already serializes first setup, while
            // this second check keeps direct maintenance calls idempotent.
            $existing = self::marker($pdo);
            if ($existing !== null) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return [
                    'created' => false,
                    'root_page_id' => isset($existing['root_page_id']) ? (int) $existing['root_page_id'] : null,
                    'page_ids' => array_values(array_map('intval', is_array($existing['page_ids'] ?? null) ? $existing['page_ids'] : [])),
                    'version' => (string) ($existing['version'] ?? self::VERSION),
                ];
            }

            $rootOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM pages WHERE parent_id IS NULL AND archived_at IS NULL')->fetchColumn();
            $returnsInsertedId = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
            $insertPageSql = <<<'SQL'
INSERT INTO pages
    (parent_id, sort_order, title, icon, cover, status, category, tags_json, manual_tags_json,
     blocks_json, plain_text, visibility, access_department, comments_enabled, language_code,
     author_id, updated_by, is_favorite, published_at)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
SQL;
            if ($returnsInsertedId) {
                $insertPageSql .= ' RETURNING id';
            }
            $insertPage = $pdo->prepare($insertPageSql);
            $insertDepartment = $pdo->prepare('INSERT INTO page_access_departments (page_id, department, granted_by) VALUES (?, ?, ?)');
            $insertAudit = $pdo->prepare('INSERT INTO audit_logs (user_id, action, subject_type, subject_id, detail_json) VALUES (?, ?, ?, ?, ?)');
            $department = trim((string) ($owner['department'] ?? ''));

            $rootPageId = self::insertPage(
                $pdo,
                $insertPage,
                $insertDepartment,
                $insertAudit,
                null,
                $rootOrder,
                $definition['title'],
                '📚',
                'mint',
                $definition['blocks'],
                $administratorId,
                $department,
                true,
                $returnsInsertedId
            );
            $pageIds = [$rootPageId];

            foreach ($definition['chapters'] as $index => $chapter) {
                $pageIds[] = self::insertPage(
                    $pdo,
                    $insertPage,
                    $insertDepartment,
                    $insertAudit,
                    $rootPageId,
                    ($index + 1) * 10,
                    $chapter['title'],
                    self::CHAPTER_ICONS[$index] ?? '📄',
                    self::CHAPTER_COVERS[$index] ?? 'none',
                    $chapter['blocks'],
                    $administratorId,
                    $department,
                    false,
                    $returnsInsertedId
                );
            }

            $marker = json_encode([
                'version' => self::VERSION,
                'locale' => self::LOCALE,
                'source_file' => 'docs/' . self::SOURCE_FILE,
                'source_sha256' => $definition['source_sha256'],
                'root_page_id' => $rootPageId,
                'page_ids' => $pageIds,
                'page_count' => count($pageIds),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $insertMarker = $pdo->prepare('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)');
            $insertMarker->execute([self::MARKER_KEY, $marker]);

            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'created' => true,
                'root_page_id' => $rootPageId,
                'page_ids' => $pageIds,
                'version' => self::VERSION,
            ];
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array{title: string, blocks: list<array<string, mixed>>, chapters: list<array{title: string, blocks: list<array<string, mixed>>}>, source_sha256: string}
     */
    public static function definition(?string $sourcePath = null): array
    {
        $path = $sourcePath ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . self::SOURCE_FILE;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The packaged OpenConcept operation manual is missing or unreadable.');
        }
        $source = file_get_contents($path);
        if (!is_string($source) || trim($source) === '') {
            throw new RuntimeException('The packaged OpenConcept operation manual is missing or empty.');
        }
        $source = preg_replace('/^\xEF\xBB\xBF/', '', $source) ?? $source;
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $lines = explode("\n", $source);
        $title = '';
        $rootLines = [];
        $chapters = [];
        $currentChapter = null;

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+)$/u', $line, $matches) === 1) {
                if ($title !== '') {
                    throw new RuntimeException('The packaged operation manual must contain exactly one H1 title.');
                }
                $title = trim((string) $matches[1]);
                continue;
            }
            if (preg_match('/^##\s+(.+)$/u', $line, $matches) === 1) {
                $chapters[] = ['title' => trim((string) $matches[1]), 'lines' => []];
                $currentChapter = count($chapters) - 1;
                continue;
            }
            if ($currentChapter === null) {
                $rootLines[] = $line;
            } else {
                $chapters[$currentChapter]['lines'][] = $line;
            }
        }

        if ($title === '' || count($chapters) < 5) {
            throw new RuntimeException('The packaged operation manual does not contain the required title and chapters.');
        }

        $rootBlocks = self::blocks($rootLines, 'root');
        $parsedChapters = [];
        foreach ($chapters as $index => $chapter) {
            if ($chapter['title'] === '') {
                throw new RuntimeException('The packaged operation manual contains an untitled chapter.');
            }
            $parsedChapters[] = [
                'title' => $chapter['title'],
                'blocks' => self::blocks($chapter['lines'], 'c' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)),
            ];
        }

        return [
            'title' => $title,
            'blocks' => $rootBlocks,
            'chapters' => $parsedChapters,
            'source_sha256' => hash('sha256', $source),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function marker(PDO $pdo): ?array
    {
        $statement = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $statement->execute([self::MARKER_KEY]);
        $value = $statement->fetchColumn();
        if (!is_string($value)) {
            return null;
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // A marker is an idempotency boundary. Never create a second copy
            // merely because installation-local metadata was damaged.
            return ['version' => self::VERSION];
        }
        return is_array($decoded) ? $decoded : ['version' => self::VERSION];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function insertPage(
        PDO $pdo,
        PDOStatement $insertPage,
        PDOStatement $insertDepartment,
        PDOStatement $insertAudit,
        ?int $parentId,
        int $sortOrder,
        string $title,
        string $icon,
        string $cover,
        array $blocks,
        int $administratorId,
        string $department,
        bool $favorite,
        bool $returnsInsertedId
    ): int {
        $tags = ['OpenConcept', '操作説明書', 'V3.0.0'];
        $tagsJson = json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $blocksJson = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $plainText = self::plainText($blocks);
        $insertPage->execute([
            $parentId,
            $sortOrder,
            $title,
            $icon,
            $cover,
            'published',
            '操作ガイド',
            $tagsJson,
            $tagsJson,
            $blocksJson,
            $plainText,
            'company',
            $department,
            0,
            self::LOCALE,
            $administratorId,
            $administratorId,
            $favorite ? 1 : 0,
        ]);
        $pageId = $returnsInsertedId
            ? (int) $insertPage->fetchColumn()
            : (int) $pdo->lastInsertId();
        if ($pageId < 1) {
            throw new RuntimeException('The default operation manual page ID could not be determined.');
        }
        if ($department !== '') {
            $insertDepartment->execute([$pageId, $department, $administratorId]);
        }
        $insertAudit->execute([
            $administratorId,
            'created',
            'page',
            $pageId,
            json_encode([
                'default_content' => 'operation_manual',
                'version' => self::VERSION,
                'locale' => self::LOCALE,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        return $pageId;
    }

    /** @param list<string> $lines @return list<array<string, mixed>> */
    private static function blocks(array $lines, string $pageKey): array
    {
        $blocks = [];
        $paragraph = [];
        $counter = 0;
        $firstParagraph = true;
        $append = static function (string $type, string $content = '', array $extra = []) use (&$blocks, &$counter, $pageKey): void {
            $counter++;
            $blocks[] = [
                'id' => 'manual-v1-' . $pageKey . '-' . str_pad((string) $counter, 3, '0', STR_PAD_LEFT),
                'type' => $type,
                'content' => $content,
                ...$extra,
            ];
        };
        $flushParagraph = static function () use (&$paragraph, &$firstParagraph, $append): void {
            if ($paragraph === []) {
                return;
            }
            $append($firstParagraph ? 'lead' : 'paragraph', self::inline(implode(' ', $paragraph)));
            $paragraph = [];
            $firstParagraph = false;
        };

        $count = count($lines);
        for ($index = 0; $index < $count; $index++) {
            $line = rtrim($lines[$index]);
            $trimmed = trim($line);
            if ($trimmed === '') {
                $flushParagraph();
                continue;
            }

            if (str_starts_with($trimmed, '```')) {
                $flushParagraph();
                $code = [];
                $closed = false;
                for ($index++; $index < $count; $index++) {
                    if (str_starts_with(trim($lines[$index]), '```')) {
                        $closed = true;
                        break;
                    }
                    $code[] = rtrim($lines[$index], "\r\n");
                }
                if (!$closed) {
                    throw new RuntimeException('The packaged operation manual contains an unterminated code block.');
                }
                $append(
                    'code',
                    htmlspecialchars(implode("\n", $code), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                );
                continue;
            }

            if (preg_match('/^\|.*\|$/u', $trimmed) === 1
                && $index + 1 < $count
                && preg_match('/^\|(?:\s*:?-+:?\s*\|)+$/u', trim($lines[$index + 1])) === 1) {
                $flushParagraph();
                $tableLines = [$trimmed];
                $index += 2;
                while ($index < $count && preg_match('/^\|.*\|$/u', trim($lines[$index])) === 1) {
                    $tableLines[] = trim($lines[$index]);
                    $index++;
                }
                $index--;
                $rows = [];
                $columnCount = 0;
                foreach ($tableLines as $tableLine) {
                    $cells = array_map('trim', explode('|', trim($tableLine, '|')));
                    $columnCount = max($columnCount, count($cells));
                    $rows[] = $cells;
                }
                $tableRows = [];
                foreach ($rows as $rowIndex => $cells) {
                    $row = [];
                    for ($column = 0; $column < $columnCount; $column++) {
                        $row[] = [
                            'content' => self::inline((string) ($cells[$column] ?? '')),
                            'align' => 'left',
                            'background' => $rowIndex === 0 ? 'gray' : 'default',
                        ];
                    }
                    $tableRows[] = $row;
                }
                $append('table', '', [
                    'table_rows' => $tableRows,
                    'table_widths' => array_fill(0, $columnCount, round(100 / max(1, $columnCount), 4)),
                ]);
                continue;
            }

            if (preg_match('/^####\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('heading3', self::inline((string) $matches[1]));
                continue;
            }
            if (preg_match('/^###\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('heading2', self::inline((string) $matches[1]));
                continue;
            }
            if ($trimmed === '---') {
                $flushParagraph();
                $append('divider');
                continue;
            }
            if (preg_match('/^>\s*\[!(NOTE|TIP|WARNING|IMPORTANT)\]\s*(.*)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $emoji = match ($matches[1]) {
                    'TIP' => '💡',
                    'WARNING' => '⚠️',
                    'IMPORTANT' => '🔐',
                    default => 'ℹ️',
                };
                $append('callout', self::inline((string) $matches[2]), ['emoji' => $emoji]);
                continue;
            }
            if (preg_match('/^>\s*(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('quote', self::inline((string) $matches[1]));
                continue;
            }
            if (preg_match('/^-\s+\[([ xX])\]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('todo', self::inline((string) $matches[2]), ['checked' => strtolower((string) $matches[1]) === 'x']);
                continue;
            }
            if (preg_match('/^[-*]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('bullet', self::inline((string) $matches[1]));
                continue;
            }
            if (preg_match('/^\d+[.)]\s+(.+)$/u', $trimmed, $matches) === 1) {
                $flushParagraph();
                $append('number', self::inline((string) $matches[1]));
                continue;
            }
            $paragraph[] = $trimmed;
        }
        $flushParagraph();

        if ($blocks === []) {
            $append('paragraph', 'この章の内容はありません。');
        }
        if (count($blocks) > 500) {
            throw new RuntimeException('A packaged operation manual page exceeds the block limit.');
        }
        return $blocks;
    }

    private static function inline(string $text): string
    {
        $parts = preg_split('/(`[^`\n]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            if (str_starts_with($part, '`') && str_ends_with($part, '`') && strlen($part) >= 2) {
                $html .= '<code>' . htmlspecialchars(substr($part, 1, -1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>';
                continue;
            }
            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
            $html .= $escaped;
        }
        return SafeHtml::sanitize($html);
    }

    /** @param list<array<string, mixed>> $blocks */
    private static function plainText(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'table') {
                foreach ((array) ($block['table_rows'] ?? []) as $row) {
                    $lines[] = implode("\t", array_map(
                        static fn(array $cell): string => html_entity_decode(strip_tags((string) ($cell['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        is_array($row) ? $row : []
                    ));
                }
                continue;
            }
            $lines[] = html_entity_decode(strip_tags((string) ($block['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $plain = trim(implode("\n", $lines));
        if (function_exists('mb_substr')) {
            return mb_substr($plain, 0, 100000);
        }
        return substr($plain, 0, 100000);
    }
}
