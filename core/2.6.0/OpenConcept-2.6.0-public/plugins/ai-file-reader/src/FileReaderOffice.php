<?php

declare(strict_types=1);

/** Bounded, read-only Office ZIP reader. No archive entries are written to disk. */
final class FileReaderOffice
{
    private const MAX_ARCHIVE_BYTES = 33554432;
    private const MAX_PART_BYTES = 16777216;
    private const MAX_CELLS = 100000;

    /** @return array<string,string> */
    public static function unzip(string $bytes): array
    {
        $end = strrpos($bytes, "PK\x05\x06");
        if ($end === false || strlen($bytes) - $end < 22) {
            throw new RuntimeException('invalid_office_archive');
        }
        $record = unpack('vdisk/vstart/ventries_disk/ventries/Vsize/Voffset/vcomment', substr($bytes, $end + 4, 18));
        if ($record['disk'] !== 0 || $record['start'] !== 0 || $record['entries'] > 4096
            || $record['offset'] + $record['size'] > $end || $record['entries'] !== $record['entries_disk']) {
            throw new RuntimeException('unsupported_office_archive');
        }
        $offset = $record['offset'];
        $parts = [];
        $total = 0;
        for ($i = 0; $i < $record['entries']; $i++) {
            if (substr($bytes, $offset, 4) !== "PK\x01\x02" || strlen($bytes) - $offset < 46) {
                throw new RuntimeException('invalid_office_archive');
            }
            $item = unpack('vcreated/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', substr($bytes, $offset + 4, 42));
            $name = substr($bytes, $offset + 46, $item['name']);
            $offset += 46 + $item['name'] + $item['extra'] + $item['comment'];
            if ($name === '' || isset($parts[$name]) || str_contains($name, '\\') || str_contains($name, '../')
                || str_starts_with($name, '/') || ($item['flags'] & 1) || $item['disk'] !== 0
                || $item['size'] > self::MAX_PART_BYTES || ($total += $item['size']) > self::MAX_ARCHIVE_BYTES
                || !in_array($item['method'], [0, 8], true)) {
                throw new RuntimeException('unsupported_office_archive');
            }
            if (substr($bytes, $item['offset'], 4) !== "PK\x03\x04" || strlen($bytes) - $item['offset'] < 30) {
                throw new RuntimeException('invalid_office_archive');
            }
            $local = unpack('vname/vextra', substr($bytes, $item['offset'] + 26, 4));
            $start = $item['offset'] + 30 + $local['name'] + $local['extra'];
            if ($start + $item['compressed'] > strlen($bytes)) {
                throw new RuntimeException('invalid_office_archive');
            }
            $compressed = substr($bytes, $start, $item['compressed']);
            $content = $item['method'] === 0 ? $compressed : @gzinflate($compressed, self::MAX_PART_BYTES);
            if (!is_string($content) || strlen($content) !== $item['size'] || sprintf('%u', crc32($content)) !== sprintf('%u', $item['crc'])) {
                throw new RuntimeException('invalid_office_archive');
            }
            $parts[$name] = $content;
        }
        return $parts;
    }

    private static function xml(string $xml): DOMDocument
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('invalid_office_xml');
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('invalid_office_xml');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    /** All non-empty cells, including rows after row 1000 and hidden sheets. */
    public static function xlsx(string $bytes): array
    {
        $parts = self::unzip($bytes);
        if (!isset($parts['xl/workbook.xml'], $parts['xl/_rels/workbook.xml.rels'])) {
            throw new RuntimeException('invalid_workbook');
        }
        $relations = [];
        foreach (self::xml($parts['xl/_rels/workbook.xml.rels'])->getElementsByTagName('Relationship') as $relation) {
            if ($relation->getAttribute('TargetMode') !== 'External') {
                $target = ltrim($relation->getAttribute('Target'), '/');
                $relations[$relation->getAttribute('Id')] = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
        $strings = [];
        if (isset($parts['xl/sharedStrings.xml'])) {
            foreach (self::xml($parts['xl/sharedStrings.xml'])->getElementsByTagName('si') as $string) {
                $value = '';
                foreach ($string->getElementsByTagName('t') as $text) {
                    $value .= $text->textContent;
                }
                $strings[] = $value;
            }
        }
        $units = [];
        $issues = [];
        $count = 0;
        foreach (self::xml($parts['xl/workbook.xml'])->getElementsByTagName('sheet') as $index => $sheet) {
            $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $relations[$id] ?? '';
            if (!isset($parts[$target])) {
                throw new RuntimeException('missing_workbook_sheet');
            }
            $name = $sheet->getAttribute('name');
            $document = self::xml($parts[$target]);
            $rows = [];
            foreach ($document->getElementsByTagName('c') as $cell) {
                if (++$count > self::MAX_CELLS) {
                    throw new RuntimeException('workbook_cell_limit');
                }
                $address = $cell->getAttribute('r');
                if (!preg_match('/^[A-Z]{1,3}[1-9][0-9]{0,6}$/D', $address)) {
                    throw new RuntimeException('invalid_cell_reference');
                }
                $valueNode = $cell->getElementsByTagName('v')->item(0);
                $value = $valueNode?->textContent ?? '';
                $type = $cell->getAttribute('t');
                if ($type === 's') {
                    if (!ctype_digit($value) || !array_key_exists((int) $value, $strings)) {
                        throw new RuntimeException('invalid_shared_string');
                    }
                    $value = $strings[(int) $value];
                } elseif ($type === 'inlineStr') {
                    $value = '';
                    foreach ($cell->getElementsByTagName('t') as $text) {
                        $value .= $text->textContent;
                    }
                }
                $formula = $cell->getElementsByTagName('f')->item(0);
                if ($formula !== null) {
                    $value .= ' [formula: ' . $formula->textContent . ']';
                    if ($valueNode === null) {
                        $issues[] = 'formula_without_cached_value';
                    }
                }
                if ($value !== '') {
                    $row = (int) preg_replace('/^[A-Z]+/', '', $address);
                    $rows[$row][] = $address . '=' . $value;
                }
            }
            ksort($rows, SORT_NUMERIC);
            foreach ($rows as $row => $cells) {
                $units[] = ['id' => 's' . ($index + 1) . 'r' . $row, 'location' => $name . ' / ' . $row, 'text' => implode("\t", $cells)];
            }
            if ($document->getElementsByTagName('drawing')->length > 0) {
                $issues[] = 'embedded_visuals_unread';
            }
        }
        foreach (array_keys($parts) as $name) {
            if (preg_match('#^xl/(?:charts|media|embeddings)/#', $name)) {
                $issues[] = 'embedded_visuals_unread';
            }
            if (str_starts_with($name, 'xl/comments')) {
                $issues[] = 'workbook_comments_unread';
            }
        }
        return ['units' => $units, 'issues' => array_values(array_unique($issues)), 'coverage' => 'all_cells'];
    }

    public static function xls(string $bytes): array
    {
        require_once dirname(__DIR__) . '/vendor/simplexls/SimpleXLS.php';
        if (strlen($bytes) < 512 || strlen($bytes) > 10485760) {
            throw new RuntimeException('invalid_workbook');
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new RuntimeException('invalid_workbook');
        });
        try {
            $workbook = \Shuchkin\SimpleXLS::parseData($bytes);
            if ($workbook === false || $workbook->sheets === []) {
                throw new RuntimeException('invalid_workbook');
            }
            $units = [];
            $count = 0;
            foreach ($workbook->sheets as $index => $sheet) {
                // Walk actual sparse cells, never a DIMENSIONS-specified empty rectangle.
                $rows = $sheet['cells'] ?? [];
                ksort($rows, SORT_NUMERIC);
                foreach ($rows as $row => $cells) {
                    ksort($cells, SORT_NUMERIC);
                    $text = [];
                    foreach ($cells as $column => $value) {
                        if (++$count > self::MAX_CELLS) {
                            throw new RuntimeException('workbook_cell_limit');
                        }
                        $text[] = 'R' . ((int) $row + 1) . 'C' . ((int) $column + 1) . '=' . (string) $value;
                    }
                    $units[] = ['id' => 's' . ($index + 1) . 'r' . ((int) $row + 1), 'location' => $workbook->sheetName($index) . ' / ' . ((int) $row + 1), 'text' => implode("\t", $text)];
                }
            }
            // BIFF's visual objects and formula source are not completely exported by this reader.
            return ['units' => $units, 'issues' => ['legacy_workbook_visuals_unverified'], 'coverage' => 'all_cells'];
        } catch (Throwable $exception) {
            throw new RuntimeException('invalid_workbook', 0, $exception);
        } finally {
            restore_error_handler();
        }
    }

    public static function docx(string $bytes): array
    {
        $parts = self::unzip($bytes);
        if (!isset($parts['word/document.xml'])) {
            throw new RuntimeException('invalid_word_document');
        }
        $units = [];
        $issues = [];
        $index = 0;
        foreach ($parts as $name => $xml) {
            if (preg_match('#^word/(?:document|header[0-9]+|footer[0-9]+|footnotes|endnotes|comments)\.xml$#D', $name)) {
                $document = self::xml($xml);
                foreach ($document->getElementsByTagName('p') as $paragraph) {
                    $text = '';
                    foreach ($paragraph->getElementsByTagName('t') as $run) {
                        $text .= $run->textContent;
                    }
                    if (trim($text) !== '') {
                        $units[] = ['id' => 'p' . ++$index, 'location' => basename($name) . ' / ' . $index, 'text' => $text];
                    }
                }
                if ($document->getElementsByTagName('altChunk')->length > 0 || $document->getElementsByTagName('del')->length > 0) {
                    $issues[] = 'word_special_content_unverified';
                }
            }
            if (preg_match('#^word/(?:media|charts|embeddings)/#', $name)) {
                $issues[] = 'embedded_visuals_unread';
            }
        }
        return ['units' => $units, 'issues' => array_values(array_unique($issues)), 'coverage' => 'all_paragraphs'];
    }
}
