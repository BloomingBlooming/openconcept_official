<?php

declare(strict_types=1);

final class PageSaveInputException extends InvalidArgumentException
{
    public readonly string $failureCode;

    public function __construct(string $field)
    {
        $this->failureCode = 'invalid_page_save_input';
        parent::__construct('ページの保存データの形式が正しくありません（' . $field . '）。本文を保持したまま、送信内容を確認してください。', 422);
    }
}

/**
 * Validate the full-page write contract before normalization can silently turn
 * missing/malformed content into an empty page. This applies equally to normal
 * JSON and decoded Base64; it does not classify prose as executable SQL.
 *
 * HTML sanitization, live authorization, and parameterized SQL remain required
 * after this structural check. A successful check does not make string-built SQL
 * safe, nor does it authorize access to a page.
 */
final class PageSaveInput
{
    private const BLOCK_TYPES = [
        'paragraph', 'lead', 'heading1', 'heading2', 'heading3', 'bullet',
        'number', 'todo', 'quote', 'code', 'divider', 'callout', 'image',
        'file', 'table',
    ];

    public static function validate(array $body, ?stdClass $nativeBody = null): void
    {
        if ($nativeBody !== null) {
            self::nativeShape($nativeBody);
        }
        if (!isset($body['id']) || !is_int($body['id']) || $body['id'] < 1) {
            self::reject('id');
        }
        if (!array_key_exists('title', $body) || !is_string($body['title'])) {
            self::reject('title');
        }
        if (!array_key_exists('blocks', $body)) {
            self::reject('blocks');
        }
        self::list($body['blocks'], 'blocks', 500);
        foreach ($body['blocks'] as $index => $block) {
            self::block($block, 'blocks[' . $index . ']');
        }

        foreach (['icon', 'category', 'language_code', 'access_department'] as $field) {
            self::optionalString($body, $field, $field);
        }
        self::optionalChoice($body, 'status', ['draft', 'review', 'published', 'private', 'archived'], 'status');
        self::optionalChoice($body, 'visibility', ['company', 'department', 'group', 'private'], 'visibility');
        self::optionalChoice($body, 'cover', ['none', 'mint', 'blue', 'sand', 'coral', 'lavender', 'night', 'black', 'navy', 'midnight', 'red', 'primary-blue', 'yellow'], 'cover');
        foreach (['tags', 'manual_tags'] as $field) {
            if (array_key_exists($field, $body)) {
                // Existing tag normalization still enforces the final tag count.
                self::stringList($body[$field], $field);
            }
        }
        if (array_key_exists('access_departments', $body)) {
            self::stringList($body['access_departments'], 'access_departments', 50);
        }
        if (array_key_exists('access_member_ids', $body)) {
            self::list($body['access_member_ids'], 'access_member_ids', 200);
            foreach ($body['access_member_ids'] as $memberId) {
                if (!is_int($memberId) || $memberId < 1) {
                    self::reject('access_member_ids');
                }
            }
        }
        self::optionalBoolean($body, 'comments_enabled', 'comments_enabled');
        self::optionalBoolean($body, 'apply_status_to_descendants', 'apply_status_to_descendants');
        // expected_access_state keeps its existing API-specific validation and
        // conflict response, so this transport change cannot weaken that guard.
    }

    /** Preserve the JSON object/list distinction lost by associative decoding. */
    private static function nativeShape(stdClass $body): void
    {
        foreach (['blocks', 'tags', 'manual_tags', 'access_departments', 'access_member_ids'] as $field) {
            if (property_exists($body, $field) && !is_array($body->{$field})) {
                self::reject($field);
            }
        }
        foreach ($body->blocks ?? [] as $index => $block) {
            $field = 'blocks[' . $index . ']';
            if (!$block instanceof stdClass) {
                self::reject($field);
            }
            if (($block->type ?? null) !== 'table') {
                continue;
            }
            foreach (['table_rows', 'table_widths'] as $key) {
                if (property_exists($block, $key) && !is_array($block->{$key})) {
                    self::reject($field . '.' . $key);
                }
            }
            foreach ($block->table_rows ?? [] as $rowIndex => $row) {
                $rowField = $field . '.table_rows[' . $rowIndex . ']';
                if (!is_array($row)) {
                    self::reject($rowField);
                }
                foreach ($row as $column => $cell) {
                    if (!is_string($cell) && !$cell instanceof stdClass) {
                        self::reject($rowField . '[' . $column . ']');
                    }
                }
            }
        }
        if (property_exists($body, 'expected_access_state')) {
            if (!$body->expected_access_state instanceof stdClass) {
                self::reject('expected_access_state');
            }
            foreach (['access_departments', 'access_member_ids'] as $key) {
                if (property_exists($body->expected_access_state, $key) && !is_array($body->expected_access_state->{$key})) {
                    self::reject('expected_access_state.' . $key);
                }
            }
        }
    }

    private static function block(mixed $block, string $field): void
    {
        if (!is_array($block) || array_is_list($block)) {
            self::reject($field);
        }
        if (!isset($block['type']) || !is_string($block['type']) || !in_array($block['type'], self::BLOCK_TYPES, true)) {
            self::reject($field . '.type');
        }
        $type = $block['type'];
        self::optionalString($block, 'id', $field . '.id');
        if (!array_key_exists('content', $block) && !in_array($type, ['divider', 'file', 'table'], true)) {
            self::reject($field . '.content');
        }
        self::optionalString($block, 'content', $field . '.content');
        if ($type === 'todo') {
            self::optionalBoolean($block, 'checked', $field . '.checked');
        }
        if ($type === 'callout') {
            self::optionalString($block, 'emoji', $field . '.emoji');
        }
        if (in_array($type, ['image', 'file'], true)) {
            foreach (['file_id', 'file_size'] as $key) {
                if (array_key_exists($key, $block) && (!is_int($block[$key]) || $block[$key] < 0)) {
                    self::reject($field . '.' . $key);
                }
            }
            self::optionalString($block, 'file_name', $field . '.file_name');
            self::optionalChoice($block, 'file_category', ['', 'pdf', 'excel', 'word', 'markdown', 'text', 'image'], $field . '.file_category');
        }
        if ($type === 'table') {
            self::table($block, $field);
        }
    }

    private static function table(array $block, string $field): void
    {
        if (array_key_exists('table_rows', $block)) {
            self::list($block['table_rows'], $field . '.table_rows', 50);
            foreach ($block['table_rows'] as $rowIndex => $row) {
                $rowField = $field . '.table_rows[' . $rowIndex . ']';
                self::list($row, $rowField, 12);
                foreach ($row as $column => $cell) {
                    // Legacy table cells are strings; current cells are objects.
                    if (is_string($cell)) {
                        continue;
                    }
                    $cellField = $rowField . '[' . $column . ']';
                    if (!is_array($cell) || ($cell !== [] && array_is_list($cell))) {
                        self::reject($cellField);
                    }
                    self::optionalString($cell, 'content', $cellField . '.content');
                    self::optionalChoice($cell, 'align', ['left', 'center', 'right'], $cellField . '.align');
                    self::optionalChoice($cell, 'background', ['default', 'gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'], $cellField . '.background');
                }
            }
        }
        if (array_key_exists('table_widths', $block)) {
            self::list($block['table_widths'], $field . '.table_widths', 12);
            foreach ($block['table_widths'] as $width) {
                if ((!is_int($width) && !is_float($width)) || !is_finite((float) $width)) {
                    self::reject($field . '.table_widths');
                }
            }
        }
    }

    private static function optionalString(array $input, string $key, string $field): void
    {
        if (array_key_exists($key, $input) && !is_string($input[$key])) {
            self::reject($field);
        }
    }

    private static function optionalBoolean(array $input, string $key, string $field): void
    {
        // PDO and legacy page responses can use integer 0/1 for booleans.
        if (array_key_exists($key, $input) && !in_array($input[$key], [true, false, 0, 1], true)) {
            self::reject($field);
        }
    }

    private static function optionalChoice(array $input, string $key, array $allowed, string $field): void
    {
        if (array_key_exists($key, $input) && (!is_string($input[$key]) || !in_array($input[$key], $allowed, true))) {
            self::reject($field);
        }
    }

    private static function stringList(mixed $value, string $field, int $maximum = PHP_INT_MAX): void
    {
        self::list($value, $field, $maximum);
        foreach ($value as $item) {
            if (!is_string($item)) {
                self::reject($field);
            }
        }
    }

    private static function list(mixed $value, string $field, int $maximum): void
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            self::reject($field);
        }
    }

    private static function reject(string $field): never
    {
        throw new PageSaveInputException($field);
    }
}
