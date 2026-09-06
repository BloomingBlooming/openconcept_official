<?php

declare(strict_types=1);

final class PageExporter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function pageForUser(int $pageId, array $user, string $expectedPasswordFingerprint): array
    {
        $ownsSnapshot = beginConsistentPageRead($this->pdo);
        try {
            $snapshotUser = authenticatedApplicationUserForReadSnapshot(
                $this->pdo,
                $user,
                $expectedPasswordFingerprint
            );
            if (!$snapshotUser) {
                throw new RuntimeException('ページを出力する権限がありません。', 403);
            }
            $user = $snapshotUser;

            $statement = $this->pdo->prepare(<<<'SQL'
SELECT p.*, u.name AS author_name, u.avatar_color AS author_color, u.department AS author_department,
       e.name AS editor_name
FROM pages p
JOIN users u ON u.id = p.author_id
JOIN users e ON e.id = p.updated_by
WHERE p.id = ? AND p.archived_at IS NULL
SQL);
            $statement->execute([$pageId]);
            $page = $statement->fetch();
            if (!$page) {
                throw new RuntimeException('ページが見つかりません。', 404);
            }
            if (!canViewPage($this->pdo, $user, $page)) {
                throw new RuntimeException('このページを出力する権限がありません。', 403);
            }

            $page['id'] = (int) $page['id'];
            $page['blocks'] = json_decode((string) $page['blocks_json'], true) ?: [];
            $page['tags'] = visiblePageTags($this->pdo, $pageId, $page);
            unset($page['blocks_json'], $page['tags_json'], $page['manual_tags_json'], $page['plain_text']);
            if ($ownsSnapshot) {
                $this->pdo->commit();
            }
            return $page;
        } catch (Throwable $exception) {
            if ($ownsSnapshot && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function filename(array $page, string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?: 'txt');
        $title = preg_replace('/[<>:"\/\\|?*\x00-\x1F\x7F]/u', ' ', (string) ($page['title'] ?? '')) ?? '';
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '', " .\t\n\r\0\x0B");
        $title = utf8Slice($title, 90);
        if ($title === '') {
            $title = 'OpenConcept-page-' . max(1, (int) ($page['id'] ?? 0));
        }
        return $title . '.' . $extension;
    }

    public function markdown(array $page, string $baseUrl): string
    {
        $statusNames = ['draft' => '下書き', 'review' => '承認待ち', 'published' => '公開', 'private' => '非公開', 'archived' => 'アーカイブ'];
        $lines = [
            '# ' . trim((string) ($page['icon'] ?? '📄') . ' ' . (string) ($page['title'] ?? '無題')),
            '',
            '> ステータス: ' . ($statusNames[(string) ($page['status'] ?? '')] ?? (string) ($page['status'] ?? '')) . '  ',
            '> カテゴリ: ' . (string) ($page['category'] ?? 'ナレッジ') . '  ',
            '> タグ: ' . ($page['tags'] ? implode(', ', array_map(static fn($tag): string => '#' . (string) $tag, $page['tags'])) : 'なし') . '  ',
            '> 作成者: ' . (string) ($page['author_name'] ?? '') . '  ',
            '> 更新日時: ' . (string) ($page['updated_at'] ?? ''),
            '',
        ];

        $number = 0;
        foreach ($page['blocks'] ?? [] as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $content = $this->inlineMarkdown((string) ($block['content'] ?? ''));
            if ($type !== 'number') {
                $number = 0;
            }
            if ($type === 'divider') {
                $lines[] = '---';
            } elseif ($type === 'heading1') {
                $lines[] = '## ' . $content;
            } elseif ($type === 'heading2') {
                $lines[] = '### ' . $content;
            } elseif ($type === 'heading3') {
                $lines[] = '#### ' . $content;
            } elseif ($type === 'bullet') {
                $lines[] = '- ' . $content;
            } elseif ($type === 'number') {
                $number++;
                $lines[] = $number . '. ' . $content;
            } elseif ($type === 'todo') {
                $lines[] = '- [' . (!empty($block['checked']) ? 'x' : ' ') . '] ' . $content;
            } elseif ($type === 'quote') {
                $lines[] = $this->prefixLines($content, '> ');
            } elseif ($type === 'callout') {
                $lines[] = $this->prefixLines(trim((string) ($block['emoji'] ?? '💡') . ' ' . $content), '> ');
            } elseif ($type === 'code') {
                $code = str_replace(["\r\n", "\r"], "\n", (string) ($block['content'] ?? ''));
                $fence = '```';
                while (str_contains($code, $fence)) {
                    $fence .= '`';
                }
                $lines[] = $fence . "\n" . $code . "\n" . $fence;
            } elseif ($type === 'table') {
                $table = $this->markdownTable($block['table_rows'] ?? []);
                if ($table !== '') {
                    $lines[] = $table;
                }
            } elseif ($type === 'image') {
                $name = trim((string) ($block['file_name'] ?? '画像')) ?: '画像';
                $caption = trim(htmlspecialchars_decode(strip_tags(sanitizeHtml((string) ($block['content'] ?? ''))), ENT_QUOTES));
                $fileId = (int) ($block['file_id'] ?? 0);
                if ($fileId > 0) {
                    $url = rtrim($baseUrl, '/') . '/api.php?action=file-content&id=' . $fileId;
                    $lines[] = '![' . $this->escapeMarkdownText($caption !== '' ? $caption : $name) . '](' . $url . ')';
                    if ($caption !== '') {
                        $lines[] = '*' . $content . '*';
                    }
                } else {
                    $lines[] = '🖼️ ' . $this->escapeMarkdownText($name);
                }
            } elseif ($type === 'file') {
                $name = trim((string) ($block['file_name'] ?? '添付ファイル')) ?: '添付ファイル';
                $fileId = (int) ($block['file_id'] ?? 0);
                if ($fileId > 0) {
                    $url = rtrim($baseUrl, '/') . '/api.php?action=file-content&id=' . $fileId;
                    $lines[] = '[' . $this->escapeMarkdownText($name) . '](' . $url . ')';
                } else {
                    $lines[] = '📎 ' . $this->escapeMarkdownText($name);
                }
            } elseif (trim($content) !== '') {
                $lines[] = $content;
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines)) . "\n";
    }

    public function html(array $page, string $mode, string $baseUrl, string $nonce, bool $autoPrint = true): string
    {
        if (!in_array($mode, ['print', 'pdf'], true)) {
            throw new InvalidArgumentException('出力形式が正しくありません。');
        }
        $title = $this->h((string) ($page['title'] ?? '無題'));
        $documentTitle = $this->h($mode === 'pdf'
            ? $this->filename($page, 'pdf')
            : (string) ($page['title'] ?? '無題') . ' - 印刷');
        $icon = $this->h((string) ($page['icon'] ?? '📄'));
        $category = $this->h((string) ($page['category'] ?? 'ナレッジ'));
        $author = $this->h((string) ($page['author_name'] ?? ''));
        $updatedAt = $this->h((string) ($page['updated_at'] ?? ''));
        $statusNames = ['draft' => '下書き', 'review' => '承認待ち', 'published' => '公開', 'private' => '非公開', 'archived' => 'アーカイブ'];
        $status = $this->h($statusNames[(string) ($page['status'] ?? '')] ?? (string) ($page['status'] ?? ''));
        $tags = implode('', array_map(fn($tag): string => '<span>#' . $this->h((string) $tag) . '</span>', $page['tags'] ?? []));
        $blocks = $this->htmlBlocks($page['blocks'] ?? [], $baseUrl);
        $instruction = $mode === 'pdf'
            ? '<strong>PDFとして保存</strong><span>表示された印刷画面の保存先で「PDFに保存」を選択してください。</span>'
            : '<strong>ページを印刷</strong><span>プリンターと用紙設定を確認して印刷してください。</span>';
        $button = $mode === 'pdf' ? 'PDF保存画面を開く' : '印刷画面を開く';
        $accent = $this->coverColor((string) ($page['cover'] ?? 'none'));
        $autoScript = $autoPrint ? "window.addEventListener('load',()=>setTimeout(triggerPrint,450));" : '';
        $generatedAt = $this->h(date('Y-m-d H:i'));

        return <<<HTML
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$documentTitle}</title>
<style nonce="{$nonce}">
@page{size:A4 portrait;margin:15mm 15mm 18mm}*{box-sizing:border-box}html{background:#eceeeb;color:#252824;font-family:"Noto Sans JP","Yu Gothic",Meiryo,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}body{margin:0}.export-toolbar{position:sticky;top:0;z-index:3;min-height:58px;padding:10px 18px;display:flex;align-items:center;gap:12px;background:#fff;border-bottom:1px solid #dfe3df;box-shadow:0 2px 12px rgba(32,44,38,.08)}.export-toolbar .brand{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;background:#e2ece6;color:#304b45;font-weight:700}.export-toolbar .copy{flex:1}.export-toolbar strong,.export-toolbar span{display:block}.export-toolbar strong{font-size:13px}.export-toolbar span{margin-top:2px;color:#6f746f;font-size:11px}.export-toolbar button{min-height:34px;padding:0 13px;border:1px solid #cbd4ce;border-radius:8px;background:#fff;color:#304b45;font:inherit;font-size:11px;font-weight:600;cursor:pointer}.export-toolbar button.primary{border-color:#304b45;background:#304b45;color:#fff}.sheet{width:210mm;min-height:297mm;margin:22px auto;padding:17mm 17mm 20mm;background:#fff;box-shadow:0 12px 42px rgba(33,42,37,.12);border-top:7px solid {$accent}}.page-icon{font-size:35px;line-height:1}.page-title{margin:13px 0 10px;font-size:30px;line-height:1.32;letter-spacing:-.035em}.meta{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-bottom:28px;color:#747974;font-size:9.5px}.meta span{padding:3px 7px;border-radius:999px;background:#f0f3f0}.meta .plain{padding:0;background:none}.content{font-size:11.5pt;line-height:1.8;overflow-wrap:anywhere}.block{margin:0 0 4.5mm;break-inside:avoid}.block p{margin:0}.block h2,.block h3,.block h4{margin:7mm 0 2.5mm;line-height:1.45;break-after:avoid}.block h2{font-size:19pt}.block h3{font-size:15pt}.block h4{font-size:12.5pt}.list{display:flex;gap:8px}.list .marker{min-width:19px;color:#59615c;text-align:right}.todo .marker{font-family:"Segoe UI Symbol",sans-serif}.quote{padding:2mm 0 2mm 4mm;border-left:3px solid #91a49a;color:#4d5651}.callout{padding:4mm 4.5mm;display:flex;gap:10px;border:1px solid #dfe8e2;border-radius:7px;background:#f2f6f3}.callout .emoji{font-size:15pt}.code{padding:4mm;border:1px solid #dfe2df;border-radius:7px;background:#f4f5f3;font:9.5pt/1.6 Consolas,"Yu Gothic",monospace;white-space:pre-wrap;word-break:break-word}.divider{height:1px;margin:6mm 0;background:#dfe2de}.table-wrap{overflow:hidden;border-radius:6px}.table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:9.5pt}.table td{padding:2.4mm;border:1px solid #d9ddd9;vertical-align:top;overflow-wrap:anywhere}.table td.align-center{text-align:center}.table td.align-right{text-align:right}.table tr{break-inside:avoid}.image{margin:4mm 0 6mm;text-align:center}.image img{display:block;max-width:100%;max-height:180mm;margin:0 auto;border-radius:5px}.image figcaption{margin-top:2mm;color:#747974;font-size:9pt}.file{display:flex;align-items:center;gap:9px;padding:3mm 3.5mm;border:1px solid #dfe2df;border-radius:7px}.file .kind{min-width:28px;color:#596a62;font-size:8pt;font-weight:700}.file a{color:#376d5a;text-decoration:none}.content a{color:#315f82;text-decoration:underline;text-underline-offset:2px}.footer{margin-top:15mm;padding-top:4mm;border-top:1px solid #e3e5e2;display:flex;justify-content:space-between;color:#8b8f8b;font-size:8pt}.empty{color:#8a8f8b}.background-gray{background:#f1f1ef}.background-brown{background:#f4eeee}.background-orange{background:#faebdd}.background-yellow{background:#fbf3db}.background-green{background:#edf3ec}.background-blue{background:#e7f3f8}.background-purple{background:#f6f3f8}.background-pink{background:#faf1f5}.background-red{background:#fdebec}
@media(max-width:850px){.export-toolbar{align-items:flex-start;flex-wrap:wrap}.export-toolbar .copy{min-width:calc(100% - 45px)}.sheet{width:100%;min-height:0;margin:0;padding:24px 20px;box-shadow:none}}
@media print{html,body{background:#fff}.export-toolbar{display:none}.sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none;border-top-width:5px}.page-title{font-size:25pt}.content{font-size:10.5pt}.block{break-inside:avoid}.footer{position:relative}.no-print{display:none!important}}
</style>
</head>
<body class="mode-{$mode}">
<div class="export-toolbar no-print"><div class="brand">OC</div><div class="copy">{$instruction}</div><button type="button" id="closePreview">閉じる</button><button class="primary" type="button" id="printAgain">{$button}</button></div>
<article class="sheet">
<header><div class="page-icon">{$icon}</div><h1 class="page-title">{$title}</h1><div class="meta"><span>{$status}</span><span>{$category}</span>{$tags}<span class="plain">作成者 {$author}</span><span class="plain">更新 {$updatedAt}</span></div></header>
<main class="content">{$blocks}</main>
<footer class="footer"><span>OpenConcept</span><span>出力 {$generatedAt}</span></footer>
</article>
<script nonce="{$nonce}">const triggerPrint=()=>window.print();document.getElementById('printAgain').addEventListener('click',triggerPrint);document.getElementById('closePreview').addEventListener('click',()=>window.close());{$autoScript}</script>
</body>
</html>
HTML;
    }

    /** @param array<int, mixed> $blocks */
    private function htmlBlocks(array $blocks, string $baseUrl): string
    {
        $html = '';
        $number = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $content = sanitizeHtml((string) ($block['content'] ?? ''));
            if ($type !== 'number') {
                $number = 0;
            }
            if ($type === 'divider') {
                $html .= '<div class="block divider"></div>';
            } elseif (in_array($type, ['heading1', 'heading2', 'heading3'], true)) {
                $level = ['heading1' => 2, 'heading2' => 3, 'heading3' => 4][$type];
                $html .= '<section class="block"><h' . $level . '>' . $content . '</h' . $level . '></section>';
            } elseif ($type === 'bullet') {
                $html .= '<div class="block list"><span class="marker">•</span><div>' . $content . '</div></div>';
            } elseif ($type === 'number') {
                $number++;
                $html .= '<div class="block list"><span class="marker">' . $number . '.</span><div>' . $content . '</div></div>';
            } elseif ($type === 'todo') {
                $html .= '<div class="block list todo"><span class="marker">' . (!empty($block['checked']) ? '☑' : '☐') . '</span><div>' . $content . '</div></div>';
            } elseif ($type === 'quote') {
                $html .= '<blockquote class="block quote">' . $content . '</blockquote>';
            } elseif ($type === 'callout') {
                $html .= '<aside class="block callout"><span class="emoji">' . $this->h((string) ($block['emoji'] ?? '💡')) . '</span><div>' . $content . '</div></aside>';
            } elseif ($type === 'code') {
                $html .= '<pre class="block code"><code>' . $this->h((string) ($block['content'] ?? '')) . '</code></pre>';
            } elseif ($type === 'table') {
                $html .= $this->htmlTable($block['table_rows'] ?? [], $block['table_widths'] ?? []);
            } elseif ($type === 'image') {
                $name = trim((string) ($block['file_name'] ?? '画像')) ?: '画像';
                $fileId = (int) ($block['file_id'] ?? 0);
                if ($fileId > 0) {
                    $url = rtrim($baseUrl, '/') . '/api.php?action=file-content&id=' . $fileId;
                    $caption = trim(strip_tags($content));
                    $html .= '<figure class="block image"><img src="' . $this->h($url) . '" alt="' . $this->h($caption !== '' ? $caption : $name) . '">' . ($caption !== '' ? '<figcaption>' . $content . '</figcaption>' : '') . '</figure>';
                }
            } elseif ($type === 'file') {
                $name = trim((string) ($block['file_name'] ?? '添付ファイル')) ?: '添付ファイル';
                $fileId = (int) ($block['file_id'] ?? 0);
                $kind = strtoupper((string) ($block['file_category'] ?? 'FILE'));
                $label = $fileId > 0
                    ? '<a href="' . $this->h(rtrim($baseUrl, '/') . '/api.php?action=file-content&id=' . $fileId) . '">' . $this->h($name) . '</a>'
                    : $this->h($name);
                $html .= '<div class="block file"><span class="kind">' . $this->h($kind) . '</span><div>' . $label . '</div></div>';
            } elseif (trim(strip_tags($content)) !== '') {
                $html .= '<div class="block"><p>' . $content . '</p></div>';
            }
        }
        return $html !== '' ? $html : '<p class="empty">本文はありません。</p>';
    }

    private function htmlTable(mixed $rows, mixed $widths): string
    {
        if (!is_array($rows) || $rows === []) {
            return '';
        }
        $columnCount = max(array_map(static fn($row): int => is_array($row) ? count($row) : 0, $rows));
        if ($columnCount < 1) {
            return '';
        }
        $body = '';
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $cells = '';
            for ($column = 0; $column < $columnCount; $column++) {
                $cell = $row[$column] ?? [];
                $cell = is_array($cell) ? $cell : ['content' => (string) $cell];
                $background = in_array($cell['background'] ?? '', ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'], true) ? (string) $cell['background'] : '';
                $align = in_array($cell['align'] ?? '', ['left', 'center', 'right'], true) ? (string) $cell['align'] : 'left';
                $classes = trim(($background ? 'background-' . $background . ' ' : '') . 'align-' . $align);
                $cells .= '<td class="' . $classes . '">' . sanitizeHtml((string) ($cell['content'] ?? '')) . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        return '<div class="block table-wrap"><table class="table"><tbody>' . $body . '</tbody></table></div>';
    }

    private function markdownTable(mixed $rows): string
    {
        if (!is_array($rows) || $rows === []) {
            return '';
        }
        $columnCount = max(array_map(static fn($row): int => is_array($row) ? count($row) : 0, $rows));
        if ($columnCount < 1) {
            return '';
        }
        $output = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? array_values($row) : [];
            $cells = [];
            for ($column = 0; $column < $columnCount; $column++) {
                $cell = $row[$column] ?? [];
                $content = is_array($cell) ? (string) ($cell['content'] ?? '') : (string) $cell;
                $cells[] = str_replace(["|", "\r\n", "\r", "\n"], ['\\|', '<br>', '<br>', '<br>'], trim($this->inlineMarkdown($content)));
            }
            $output[] = '| ' . implode(' | ', $cells) . ' |';
        }
        array_splice($output, 1, 0, ['| ' . implode(' | ', array_fill(0, $columnCount, '---')) . ' |']);
        return implode("\n", $output);
    }

    private function inlineMarkdown(string $html): string
    {
        $safe = sanitizeHtml($html);
        if (!class_exists('DOMDocument')) {
            $plain = preg_replace('/<br\s*\/?\s*>/iu', "\n", $safe) ?? $safe;
            return $this->escapeMarkdownText(htmlspecialchars_decode(strip_tags($plain), ENT_QUOTES));
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div id="openconcept-export-root">' . $safe . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $dom->getElementById('openconcept-export-root');
        if (!$root) {
            return '';
        }
        $result = '';
        foreach ($root->childNodes as $node) {
            $result .= $this->markdownNode($node);
        }
        return trim($result);
    }

    private function markdownNode(object $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return $this->escapeMarkdownText((string) $node->nodeValue);
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }
        $tag = strtolower((string) $node->nodeName);
        if ($tag === 'br') {
            return "\n";
        }
        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= $this->markdownNode($child);
        }
        return match ($tag) {
            'strong', 'b' => '**' . $children . '**',
            'em', 'i' => '*' . $children . '*',
            's', 'del' => '~~' . $children . '~~',
            'code' => $this->inlineCode((string) $node->textContent),
            'a' => $this->markdownLink($node, $children),
            default => $children,
        };
    }

    private function markdownLink(object $node, string $label): string
    {
        $href = trim((string) ($node->attributes?->getNamedItem('href')?->nodeValue ?? ''));
        return $href !== '' ? '[' . ($label !== '' ? $label : $this->escapeMarkdownText($href)) . '](' . str_replace(')', '%29', $href) . ')' : $label;
    }

    private function inlineCode(string $text): string
    {
        $fence = '`';
        while (str_contains($text, $fence)) {
            $fence .= '`';
        }
        return $fence . $text . $fence;
    }

    private function escapeMarkdownText(string $text): string
    {
        return str_replace(['\\', '*', '_', '[', ']'], ['\\\\', '\\*', '\\_', '\\[', '\\]'], $text);
    }

    private function prefixLines(string $text, string $prefix): string
    {
        return $prefix . str_replace("\n", "\n" . $prefix, $text);
    }

    private function coverColor(string $cover): string
    {
        return match ($cover) {
            'mint' => '#9eb8aa',
            'blue' => '#9eb7c4',
            'sand' => '#c8b89d',
            'coral' => '#c99d91',
            'lavender' => '#afa6c2',
            'night' => '#4c665c',
            default => '#8fa79b',
        };
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
