<?php

declare(strict_types=1);

/** Core-owned extension infrastructure. Only CoreSchemaRuntime provisions it. */
final class PluginApiSchema
{
    public const TABLES = ['extension_records', 'extension_jobs', 'extension_events', 'extension_search_content'];

    /** @return list<string> */
    public static function statements(string $driver, string $prefix = ''): array
    {
        if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)
            || ($prefix !== '' && preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1)) {
            throw new InvalidArgumentException('Unsupported extension schema context.');
        }
        $text = $driver === 'mysql' ? 'LONGTEXT' : 'TEXT';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $quote = $driver === 'mysql' ? '`' : '"';
        $table = static fn (string $name): string => $quote . $prefix . $name . $quote;
        return [
            'CREATE TABLE IF NOT EXISTS ' . $table('extension_records') . " (
                scope VARCHAR(64) NOT NULL,
                collection VARCHAR(64) NOT NULL,
                record_key VARCHAR(190) NOT NULL,
                payload_json {$text} NOT NULL,
                revision BIGINT NOT NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL,
                PRIMARY KEY (scope, collection, record_key)
            )" . $suffix,
            'CREATE TABLE IF NOT EXISTS ' . $table('extension_jobs') . " (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                scope VARCHAR(64) NOT NULL,
                job_name VARCHAR(64) NOT NULL,
                dedupe_key VARCHAR(64) NOT NULL,
                payload_json {$text} NOT NULL,
                state VARCHAR(32) NOT NULL,
                attempts BIGINT NOT NULL,
                available_at BIGINT NOT NULL,
                lease_token VARCHAR(64) NULL,
                lease_until BIGINT NULL,
                last_error {$text} NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL,
                UNIQUE (scope, dedupe_key)
            )" . $suffix,
            'CREATE TABLE IF NOT EXISTS ' . $table('extension_events') . " (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                event_name VARCHAR(64) NOT NULL,
                source_key VARCHAR(64) NOT NULL,
                payload_json {$text} NOT NULL,
                created_at BIGINT NOT NULL,
                UNIQUE (event_name, source_key)
            )" . $suffix,
            'CREATE TABLE IF NOT EXISTS ' . $table('extension_search_content') . " (
                id VARCHAR(64) NOT NULL PRIMARY KEY,
                scope VARCHAR(64) NOT NULL,
                source_type VARCHAR(64) NOT NULL,
                source_id VARCHAR(190) NOT NULL,
                source_version VARCHAR(128) NOT NULL,
                content_hash VARCHAR(64) NOT NULL,
                page_id BIGINT NULL,
                body_text {$text} NOT NULL,
                provenance_json {$text} NOT NULL,
                state VARCHAR(32) NOT NULL,
                revision BIGINT NOT NULL,
                created_at BIGINT NOT NULL,
                updated_at BIGINT NOT NULL
            )" . $suffix,
        ];
    }

    public static function migrate(PDO $pdo): void
    {
        $prefix = method_exists($pdo, 'tablePrefix') ? $pdo->tablePrefix() : '';
        foreach (self::statements((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), $prefix) as $sql) {
            $pdo->exec($sql);
        }
    }
}
