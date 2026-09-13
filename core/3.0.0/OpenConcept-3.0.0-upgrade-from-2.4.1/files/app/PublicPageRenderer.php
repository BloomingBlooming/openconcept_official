<?php

declare(strict_types=1);

require_once __DIR__ . '/PageExportLocalization.php';

/** Produces one inert, JavaScript-free page for a static public site. */
final class PublicPageRenderer
{
    public const FORMAT_VERSION = 'static-public-page-v1';

    public function __construct(private readonly I18n $i18n)
    {
    }

    /** @param array<string, mixed> $context */
    public function html(
        array $context,
        string $applicationBaseUrl,
        string $siteBaseUrl,
        string $currentPath = 'index.html'
    ): string {
        $page = is_array($context['page'] ?? null) ? $context['page'] : [];
        $navigation = is_array($context['navigation'] ?? null) ? $context['navigation'] : [];
        $pagePaths = is_array($context['page_paths'] ?? null) ? $context['page_paths'] : [];
        $imagePaths = is_array($context['image_paths'] ?? null) ? $context['image_paths'] : [];
        $includedIds = array_values(array_filter(
            array_map('intval', is_array($context['included_page_ids'] ?? null) ? $context['included_page_ids'] : []),
            static fn(int $pageId): bool => $pageId > 0
        ));
        $includedSet = array_fill_keys($includedIds, true);
        $publicationLocale = (string) ($context['publication_locale'] ?? 'en-US');
        $locale = $this->localeForPage((string) ($page['language_code'] ?? ''), $publicationLocale);
        $rawTitle = trim((string) ($page['title'] ?? '')) ?: $this->t('page.untitled', $locale);
        $title = $this->h($rawTitle);
        $icon = $this->h((string) ($page['icon'] ?? ''));
        $category = $this->h((string) ($page['category'] ?? ''));
        $workspaceName = trim((string) ($context['workspace_name'] ?? 'OpenConcept')) ?: 'OpenConcept';
        $organizationName = trim((string) ($context['organization_name'] ?? ''));
        $pageId = (int) ($page['id'] ?? 0);
        $cover = in_array((string) ($page['cover'] ?? ''), ['none', 'mint', 'blue', 'sand', 'coral', 'lavender', 'night', 'black', 'navy', 'midnight', 'red', 'primary-blue', 'yellow'], true)
            ? (string) $page['cover']
            : 'none';
        $canonicalPath = (string) ($pagePaths[$pageId] ?? $currentPath);
        $canonicalUrl = $this->absoluteSiteUrl($siteBaseUrl, $canonicalPath);
        $stylesheetUrl = $this->relativePath($currentPath, 'assets/public-share.css');

        $navigationById = [];
        $navHtml = '';
        foreach ($navigation as $entry) {
            if (!is_array($entry) || (int) ($entry['id'] ?? 0) < 1) {
                continue;
            }
            $entryId = (int) $entry['id'];
            $targetPath = (string) ($pagePaths[$entryId] ?? '');
            if ($targetPath === '') {
                continue;
            }
            $navigationById[$entryId] = $entry;
            $depth = max(0, min(8, (int) ($entry['depth'] ?? 0)));
            $navHtml .= '<a class="public-nav-link depth-' . $depth . ($entryId === $pageId ? ' active' : '') . '" href="'
                . $this->h($this->relativePath($currentPath, $targetPath)) . '"><span class="public-nav-icon">'
                . $this->h((string) ($entry['icon'] ?? '')) . '</span><span>'
                . $this->h((string) ($entry['title'] ?? '')) . '</span></a>';
        }

        $breadcrumbs = [];
        $cursor = $navigationById[$pageId] ?? null;
        $seen = [];
        while (is_array($cursor) && !isset($seen[(int) $cursor['id']])) {
            $cursorId = (int) $cursor['id'];
            $seen[$cursorId] = true;
            array_unshift($breadcrumbs, $cursor);
            $parentId = (int) ($cursor['parent_id'] ?? 0);
            $cursor = $parentId > 0 ? ($navigationById[$parentId] ?? null) : null;
        }
        $breadcrumbHtml = implode('<span aria-hidden="true">/</span>', array_map(
            function (array $entry) use ($pageId, $pagePaths, $currentPath): string {
                $entryId = (int) $entry['id'];
                return $entryId === $pageId
                    ? '<span aria-current="page">' . $this->h((string) $entry['title']) . '</span>'
                    : '<a href="' . $this->h($this->relativePath($currentPath, (string) $pagePaths[$entryId])) . '">'
                        . $this->h((string) $entry['title']) . '</a>';
            },
            $breadcrumbs
        ));

        $children = array_values(array_filter(
            $navigation,
            static fn($entry): bool => is_array($entry) && (int) ($entry['parent_id'] ?? 0) === $pageId
        ));
        $childrenHtml = '';
        if ($children !== []) {
            $links = '';
            foreach ($children as $child) {
                $childId = (int) $child['id'];
                $targetPath = (string) ($pagePaths[$childId] ?? '');
                if ($targetPath === '') {
                    continue;
                }
                $links .= '<a class="public-child-link" href="'
                    . $this->h($this->relativePath($currentPath, $targetPath)) . '"><span>'
                    . $this->h((string) ($child['icon'] ?? '')) . '</span><strong>'
                    . $this->h((string) ($child['title'] ?? '')) . '</strong><span aria-hidden="true">/</span></a>';
            }
            if ($links !== '') {
                $childrenHtml = '<nav class="public-children" aria-label="'
                    . $this->h($this->t('publicPage.childPages', $locale)) . '"><h2>'
                    . $this->h($this->t('publicPage.childPages', $locale)) . '</h2>' . $links . '</nav>';
            }
        }

        $tagsHtml = '';
        foreach (is_array($page['tags'] ?? null) ? $page['tags'] : [] as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $cleanTag = trim(strip_tags((string) $tag));
            if ($cleanTag !== '') {
                $tagsHtml .= '<span class="public-tag">#' . $this->h($cleanTag) . '</span>';
            }
        }
        $updatedAt = (new PageExportLocalization($this->i18n, $locale))->date(substr((string) ($page['updated_at'] ?? ''), 0, 10), true);
        $metaHtml = ($category !== '' ? '<span class="public-category">' . $category . '</span>' : '')
            . $tagsHtml
            . ($updatedAt !== '' ? '<span class="public-updated">'
                . $this->h($this->t('publicPage.updated', $locale, ['date' => $updatedAt])) . '</span>' : '');
        $blocksHtml = $this->blocks(
            is_array($page['blocks'] ?? null) ? $page['blocks'] : [],
            $pageId,
            $includedSet,
            $pagePaths,
            $imagePaths,
            $currentPath,
            $applicationBaseUrl,
            $locale
        );
        $brandSubtitle = $organizationName !== '' && $organizationName !== $workspaceName
            ? '<small>' . $this->h($organizationName) . '</small>'
            : '';
        $description = $this->description((string) ($page['plain_text'] ?? ''), $rawTitle);
        $csp = "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

        return '<!doctype html><html lang="' . $this->h($locale) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="Content-Security-Policy" content="' . $this->h($csp) . '">'
            . '<meta name="referrer" content="no-referrer"><meta name="robots" content="index,follow">'
            . '<meta name="generator" content="OpenConcept ' . self::FORMAT_VERSION . '">'
            . '<meta name="description" content="' . $this->h($description) . '">'
            . '<meta property="og:type" content="article"><meta property="og:title" content="' . $title . '">'
            . '<meta property="og:description" content="' . $this->h($description) . '">'
            . '<meta property="og:url" content="' . $this->h($canonicalUrl) . '">'
            . '<title>' . $title . ' - ' . $this->h($workspaceName) . '</title>'
            . '<link rel="canonical" href="' . $this->h($canonicalUrl) . '">'
            . '<link rel="sitemap" type="application/xml" href="'
            . $this->h($this->relativePath($currentPath, 'sitemap.xml')) . '">'
            . '<link rel="stylesheet" href="' . $this->h($stylesheetUrl) . '"></head><body>'
            . '<header class="public-header"><div class="public-brand"><span class="public-brand-mark">OC</span><span><strong>'
            . $this->h($workspaceName) . '</strong>' . $brandSubtitle . '</span></div>'
            . '<span class="public-badge">' . $this->h($this->t('publicPage.badge', $locale)) . '</span></header>'
            . '<div class="public-layout"><aside class="public-sidebar"><div class="public-nav-title">'
            . $this->h($this->t('publicPage.navigation', $locale)) . '</div><nav>' . $navHtml . '</nav></aside>'
            . '<main class="public-main"><div class="public-cover cover-' . $cover . '"></div><article class="public-page">'
            . '<nav class="public-breadcrumbs" aria-label="' . $this->h($this->t('publicPage.breadcrumbs', $locale)) . '">'
            . $breadcrumbHtml . '</nav><div class="public-page-icon">' . $icon . '</div><h1>' . $title . '</h1>'
            . '<div class="public-meta">' . $metaHtml . '</div><div class="public-content">' . $blocksHtml . '</div>'
            . $childrenHtml . '<footer class="public-footer"><span>OpenConcept</span><span>'
            . $this->h($this->t('publicPage.readOnly', $locale)) . '</span></footer></article></main></div></body></html>';
    }

    /** @param array<int, mixed> $blocks @param array<int, bool> $includedSet @param array<int|string, string> $pagePaths @param array<string, string> $imagePaths */
    private function blocks(
        array $blocks,
        int $pageId,
        array $includedSet,
        array $pagePaths,
        array $imagePaths,
        string $currentPath,
        string $applicationBaseUrl,
        string $locale
    ): string {
        $html = '';
        $number = 0;
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? 'paragraph');
            $content = $this->linkedContent((string) ($block['content'] ?? ''), $includedSet, $pagePaths, $currentPath, $applicationBaseUrl);
            if ($type !== 'number') {
                $number = 0;
            }
            if ($type === 'divider') {
                $html .= '<hr class="public-block public-divider">';
            } elseif (in_array($type, ['heading1', 'heading2', 'heading3'], true)) {
                $level = ['heading1' => 2, 'heading2' => 3, 'heading3' => 4][$type];
                $html .= '<h' . $level . ' class="public-block">' . $content . '</h' . $level . '>';
            } elseif ($type === 'bullet') {
                $html .= '<div class="public-block public-list"><span>&bull;</span><div>' . $content . '</div></div>';
            } elseif ($type === 'number') {
                $number++;
                $html .= '<div class="public-block public-list"><span>' . $number . '.</span><div>' . $content . '</div></div>';
            } elseif ($type === 'todo') {
                $html .= '<div class="public-block public-list public-todo"><span>' . (!empty($block['checked']) ? '&#9745;' : '&#9744;')
                    . '</span><div>' . $content . '</div></div>';
            } elseif ($type === 'quote') {
                $html .= '<blockquote class="public-block public-quote">' . $content . '</blockquote>';
            } elseif ($type === 'callout') {
                $html .= '<aside class="public-block public-callout"><span>' . $this->h((string) ($block['emoji'] ?? ''))
                    . '</span><div>' . $content . '</div></aside>';
            } elseif ($type === 'code') {
                $html .= '<pre class="public-block public-code"><code>' . $this->h((string) ($block['content'] ?? '')) . '</code></pre>';
            } elseif ($type === 'table') {
                $html .= $this->table($block, $includedSet, $pagePaths, $currentPath, $applicationBaseUrl);
            } elseif ($type === 'image') {
                $fileId = (int) ($block['file_id'] ?? 0);
                $imagePath = (string) ($imagePaths[$pageId . ':' . $fileId] ?? '');
                if ($fileId > 0 && $imagePath !== '' && (string) ($block['file_category'] ?? '') === 'image') {
                    $caption = trim(strip_tags($content));
                    $alt = $caption !== '' ? $caption : (string) ($block['file_name'] ?? $this->t('publicPage.image', $locale));
                    $html .= '<figure class="public-block public-image"><img src="'
                        . $this->h($this->relativePath($currentPath, $imagePath)) . '" alt="'
                        . $this->h($alt) . '" loading="lazy">'
                        . ($caption !== '' ? '<figcaption>' . $content . '</figcaption>' : '') . '</figure>';
                }
            } elseif ($type === 'file') {
                $kind = strtoupper((string) ($block['file_category'] ?? 'FILE'));
                $name = trim((string) ($block['file_name'] ?? '')) ?: $this->t('publicPage.attachment', $locale);
                $html .= '<div class="public-block public-file"><span>' . $this->h($kind) . '</span><strong>' . $this->h($name)
                    . '</strong><small>' . $this->h($this->t('publicPage.attachmentReadOnly', $locale)) . '</small></div>';
            } elseif (trim(strip_tags($content)) !== '') {
                $class = $type === 'lead' ? ' public-lead' : '';
                $html .= '<div class="public-block' . $class . '"><p>' . $content . '</p></div>';
            }
        }
        return $html !== '' ? $html : '<p class="public-empty">' . $this->h($this->t('publicPage.empty', $locale)) . '</p>';
    }

    /** @param array<string, mixed> $block @param array<int, bool> $includedSet @param array<int|string, string> $pagePaths */
    private function table(array $block, array $includedSet, array $pagePaths, string $currentPath, string $applicationBaseUrl): string
    {
        $rows = is_array($block['table_rows'] ?? null) ? $block['table_rows'] : [];
        if ($rows === []) {
            return '';
        }
        $columnCount = max(array_map(static fn($row): int => is_array($row) ? count($row) : 0, $rows));
        if ($columnCount < 1) {
            return '';
        }
        $body = '';
        foreach ($rows as $row) {
            $row = is_array($row) ? array_values($row) : [];
            $cells = '';
            for ($column = 0; $column < $columnCount; $column++) {
                $cell = $row[$column] ?? [];
                $cell = is_array($cell) ? $cell : ['content' => (string) $cell];
                $align = in_array($cell['align'] ?? '', ['left', 'center', 'right'], true) ? (string) $cell['align'] : 'left';
                $background = in_array($cell['background'] ?? '', ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'], true)
                    ? ' background-' . (string) $cell['background'] : '';
                $cells .= '<td class="align-' . $align . $background . '">'
                    . $this->linkedContent((string) ($cell['content'] ?? ''), $includedSet, $pagePaths, $currentPath, $applicationBaseUrl)
                    . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        return '<div class="public-block public-table-wrap"><table><tbody>' . $body . '</tbody></table></div>';
    }

    /** @param array<int, bool> $includedSet @param array<int|string, string> $pagePaths */
    private function linkedContent(string $html, array $includedSet, array $pagePaths, string $currentPath, string $applicationBaseUrl): string
    {
        // strip_tags() deliberately preserves element text. For a public,
        // read-only artifact, executable/control containers and their payloads
        // must disappear together so script or form secrets cannot become
        // visible text after the tags themselves are removed.
        $html = preg_replace(
            '#<(script|style|form|button|textarea|select|option|iframe|object|embed|template|noscript)\b[^>]*>.*?</\1\s*>#isu',
            '',
            $html
        ) ?? '';
        $html = preg_replace('#<(input|iframe|object|embed)\b[^>]*\/?>#isu', '', $html) ?? '';
        $safe = SafeHtml::sanitize($html);
        return preg_replace_callback(
            '/<a(?:\s+href="([^"]*)"\s+target="_blank"\s+rel="noopener noreferrer")?>(.*?)<\/a>/isu',
            function (array $matches) use ($includedSet, $pagePaths, $currentPath, $applicationBaseUrl): string {
                $label = (string) ($matches[2] ?? '');
                $href = htmlspecialchars_decode((string) ($matches[1] ?? ''), ENT_QUOTES);
                if ($href === '') {
                    return '<span class="public-unavailable-link">' . $label . '</span>';
                }
                $internalPageId = $this->workspacePageId($href, $applicationBaseUrl);
                if ($internalPageId !== null) {
                    $targetPath = (string) ($pagePaths[$internalPageId] ?? '');
                    if (!isset($includedSet[$internalPageId]) || $targetPath === '') {
                        return '<span class="public-unavailable-link">' . $label . '</span>';
                    }
                    return '<a href="' . $this->h($this->relativePath($currentPath, $targetPath)) . '">' . $label . '</a>';
                }
                if ($this->sameOrigin($href, $applicationBaseUrl)) {
                    return '<span class="public-unavailable-link">' . $label . '</span>';
                }
                if (preg_match('/^(?:https?:\/\/|mailto:)/i', $href) !== 1) {
                    return '<span class="public-unavailable-link">' . $label . '</span>';
                }
                return '<a href="' . $this->h($href) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
            },
            $safe
        ) ?? '';
    }

    private function workspacePageId(string $href, string $baseUrl): ?int
    {
        $hrefParts = parse_url($href);
        $baseParts = parse_url($baseUrl);
        if (!is_array($hrefParts) || !is_array($baseParts)) {
            return null;
        }
        $basePath = rtrim((string) ($baseParts['path'] ?? ''), '/');
        $hrefPath = rtrim((string) ($hrefParts['path'] ?? ''), '/');
        $sameAppPath = $hrefPath === $basePath || $hrefPath === $basePath . '/index.php';
        if (!$this->sameOrigin($href, $baseUrl) || !$sameAppPath) {
            return null;
        }
        return preg_match('/^page-([1-9][0-9]*)$/D', (string) ($hrefParts['fragment'] ?? ''), $matches) === 1
            ? (int) $matches[1] : null;
    }

    private function sameOrigin(string $href, string $baseUrl): bool
    {
        $hrefParts = parse_url($href);
        $baseParts = parse_url($baseUrl);
        if (!is_array($hrefParts) || !is_array($baseParts)) {
            return false;
        }
        $hrefScheme = strtolower((string) ($hrefParts['scheme'] ?? ''));
        $baseScheme = strtolower((string) ($baseParts['scheme'] ?? ''));
        if (!in_array($hrefScheme, ['http', 'https'], true) || $hrefScheme !== $baseScheme) {
            return false;
        }
        if (strtolower((string) ($hrefParts['host'] ?? '')) !== strtolower((string) ($baseParts['host'] ?? ''))) {
            return false;
        }
        $defaultPort = static fn(string $scheme): int => $scheme === 'https' ? 443 : 80;
        return (int) ($hrefParts['port'] ?? $defaultPort($hrefScheme))
            === (int) ($baseParts['port'] ?? $defaultPort($baseScheme));
    }

    private function relativePath(string $fromFile, string $toFile): string
    {
        $fromDirectory = str_replace('\\', '/', dirname($fromFile));
        $from = array_values(array_filter(explode('/', $fromDirectory), static fn(string $part): bool => $part !== '' && $part !== '.'));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', $toFile)), static fn(string $part): bool => $part !== '' && $part !== '.'));
        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }
        $relative = str_repeat('../', count($from)) . implode('/', $to);
        return $relative !== '' ? $relative : './';
    }

    private function absoluteSiteUrl(string $siteBaseUrl, string $relativePath): string
    {
        $base = rtrim($siteBaseUrl, '/') . '/';
        return $relativePath === 'index.html' ? $base : $base . $relativePath;
    }

    private function localeForPage(string $pageLanguage, string $publicationLocale): string
    {
        $available = array_column($this->i18n->availableLocales(), 'code');
        if ($pageLanguage !== '' && $pageLanguage !== 'und' && in_array($pageLanguage, $available, true)) {
            return $pageLanguage;
        }
        return $this->i18n->selectLocale($publicationLocale);
    }

    private function description(string $plainText, string $fallback): string
    {
        $value = preg_replace('/\s+/u', ' ', trim(strip_tags($plainText))) ?? '';
        if ($value === '') {
            $value = $fallback;
        }
        return function_exists('mb_substr') ? mb_substr($value, 0, 160) : substr($value, 0, 160);
    }

    /** @param array<string, scalar|null> $parameters */
    private function t(string $key, string $locale, array $parameters = []): string
    {
        return $this->i18n->translate($key, $parameters, $locale);
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
