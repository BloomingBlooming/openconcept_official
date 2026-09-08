<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaException.php';

/**
 * PostgreSQL-only physical indexes introduced by canonical generation 4.
 *
 * The two expression indexes are represented in the catalog by stable
 * semantic identifiers. PostgreSQL's deparser output is accepted only when
 * it is the exact expression emitted by supported PostgreSQL 14-17 releases;
 * arbitrary expression indexes remain a fail-stop condition.
 */
final class CoreSchemaPostgreSqlIndexes
{
    public const PAGES_SEARCH_EXPRESSION = 'postgresql:pages_search_v1';
    public const RAG_CHUNKS_SEARCH_EXPRESSION = 'postgresql:rag_chunks_search_v1';

    private const PAGES_SEARCH_DEPARSED =
        "to_tsvector('simple'::regconfig, (COALESCE(title::text, ''::text) || ' '::text) || COALESCE(plain_text, ''::text))";

    private const PAGES_SEARCH_DEPARSED_QUALIFIED =
        "pg_catalog.to_tsvector('pg_catalog.simple'::regconfig, (COALESCE(title::text, ''::text) || ' '::text) || COALESCE(plain_text, ''::text))";

    private const RAG_CHUNKS_SEARCH_DEPARSED =
        "to_tsvector('simple'::regconfig, (((COALESCE(title::text, ''::text) || ' '::text) || COALESCE(chunk_summary, ''::text)) || ' '::text) || COALESCE(search_text, ''::text))";

    private const RAG_CHUNKS_SEARCH_DEPARSED_QUALIFIED =
        "pg_catalog.to_tsvector('pg_catalog.simple'::regconfig, (((COALESCE(title::text, ''::text) || ' '::text) || COALESCE(chunk_summary, ''::text)) || ' '::text) || COALESCE(search_text, ''::text))";

    /**
     * @return list<array{name: string, table: string, columns?: list<string>, expression?: string}>
     */
    public static function generation4Additions(): array
    {
        return [
            ['name' => 'i_038', 'table' => 'ai_chat_turns', 'columns' => ['page_id']],
            ['name' => 'i_039', 'table' => 'ai_chat_turns', 'columns' => ['reviewed_by']],
            ['name' => 'i_040', 'table' => 'ai_conversations', 'columns' => ['last_page_id']],
            ['name' => 'i_041', 'table' => 'audit_logs', 'columns' => ['user_id']],
            ['name' => 'i_042', 'table' => 'comments', 'columns' => ['parent_id']],
            ['name' => 'i_043', 'table' => 'comments', 'columns' => ['user_id']],
            ['name' => 'i_044', 'table' => 'files', 'columns' => ['uploaded_by']],
            ['name' => 'i_045', 'table' => 'notifications', 'columns' => ['page_id']],
            ['name' => 'i_046', 'table' => 'page_access_departments', 'columns' => ['granted_by']],
            ['name' => 'i_047', 'table' => 'page_access_members', 'columns' => ['granted_by']],
            ['name' => 'i_048', 'table' => 'page_public_share_pages', 'columns' => ['added_by']],
            ['name' => 'i_049', 'table' => 'page_public_shares', 'columns' => ['created_by']],
            ['name' => 'i_050', 'table' => 'page_public_shares', 'columns' => ['updated_by']],
            ['name' => 'i_051', 'table' => 'pages', 'columns' => ['author_id']],
            ['name' => 'i_052', 'table' => 'pages', 'expression' => self::PAGES_SEARCH_EXPRESSION],
            ['name' => 'i_053', 'table' => 'pages', 'columns' => ['updated_by']],
            ['name' => 'i_054', 'table' => 'rag_chunks', 'expression' => self::RAG_CHUNKS_SEARCH_EXPRESSION],
            ['name' => 'i_055', 'table' => 'rag_page_tag_links', 'columns' => ['tag_id']],
            ['name' => 'i_056', 'table' => 'rag_source_documents', 'columns' => ['author_id']],
            ['name' => 'i_057', 'table' => 'rag_source_documents', 'columns' => ['updated_by']],
            ['name' => 'i_058', 'table' => 'revisions', 'columns' => ['created_by']],
        ];
    }

    public static function recognizeExpression(string $logicalTable, string $deparsedExpression): string
    {
        $identity = match ($logicalTable) {
            'pages' => in_array($deparsedExpression, [
                self::PAGES_SEARCH_DEPARSED,
                self::PAGES_SEARCH_DEPARSED_QUALIFIED,
            ], true)
                ? self::PAGES_SEARCH_EXPRESSION
                : null,
            'rag_chunks' => in_array($deparsedExpression, [
                self::RAG_CHUNKS_SEARCH_DEPARSED,
                self::RAG_CHUNKS_SEARCH_DEPARSED_QUALIFIED,
            ], true)
                ? self::RAG_CHUNKS_SEARCH_EXPRESSION
                : null,
            default => null,
        };
        if (!is_string($identity)) {
            throw new CoreSchemaException([
                'reason' => 'index_expression_unsupported',
                'driver' => 'pgsql',
                'table' => $logicalTable,
            ]);
        }
        return $identity;
    }

    public static function expressionBelongsToTable(string $logicalTable, string $identity): bool
    {
        return match ($logicalTable) {
            'pages' => $identity === self::PAGES_SEARCH_EXPRESSION,
            'rag_chunks' => $identity === self::RAG_CHUNKS_SEARCH_EXPRESSION,
            default => false,
        };
    }

    public static function expressionSql(string $identity): string
    {
        return match ($identity) {
            self::PAGES_SEARCH_EXPRESSION =>
                "pg_catalog.to_tsvector('pg_catalog.simple'::pg_catalog.regconfig, "
                . "COALESCE(\"title\"::pg_catalog.text, ''::pg_catalog.text) "
                . "OPERATOR(pg_catalog.||) ' '::pg_catalog.text "
                . "OPERATOR(pg_catalog.||) COALESCE(\"plain_text\"::pg_catalog.text, ''::pg_catalog.text))",
            self::RAG_CHUNKS_SEARCH_EXPRESSION =>
                "pg_catalog.to_tsvector('pg_catalog.simple'::pg_catalog.regconfig, "
                . "COALESCE(\"title\"::pg_catalog.text, ''::pg_catalog.text) "
                . "OPERATOR(pg_catalog.||) ' '::pg_catalog.text "
                . "OPERATOR(pg_catalog.||) COALESCE(\"chunk_summary\"::pg_catalog.text, ''::pg_catalog.text) "
                . "OPERATOR(pg_catalog.||) ' '::pg_catalog.text "
                . "OPERATOR(pg_catalog.||) COALESCE(\"search_text\"::pg_catalog.text, ''::pg_catalog.text))",
            default => throw new CoreSchemaException([
                'reason' => 'index_expression_unsupported',
                'driver' => 'pgsql',
            ]),
        };
    }

    /** @return list<string> */
    public static function expressionDependencyColumns(string $identity): array
    {
        return match ($identity) {
            self::PAGES_SEARCH_EXPRESSION => ['*', 'title', 'plain_text'],
            self::RAG_CHUNKS_SEARCH_EXPRESSION => ['*', 'title', 'chunk_summary', 'search_text'],
            default => throw new CoreSchemaException([
                'reason' => 'index_expression_unsupported',
                'driver' => 'pgsql',
            ]),
        };
    }

    public static function expressionOperatorCount(string $identity): int
    {
        return match ($identity) {
            self::PAGES_SEARCH_EXPRESSION => 2,
            self::RAG_CHUNKS_SEARCH_EXPRESSION => 4,
            default => throw new CoreSchemaException([
                'reason' => 'index_expression_unsupported',
                'driver' => 'pgsql',
            ]),
        };
    }

    /**
     * Add generation-4 indexes to a database already proven exactly equal to
     * sealed generation 3. The caller owns the surrounding transaction.
     */
    public static function createGeneration4(PDO $pdo, string $prefix): void
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new CoreSchemaException(['reason' => 'driver_profile_unsupported']);
        }
        if (!$pdo->inTransaction()) {
            throw new CoreSchemaException(['reason' => 'canonical_index_upgrade_outside_transaction']);
        }
        if ($prefix === '' || preg_match('/^[a-z][a-z0-9_]{0,39}_$/D', $prefix) !== 1) {
            throw new CoreSchemaException(['reason' => 'table_prefix_invalid']);
        }
        foreach (self::generation4Additions() as $definition) {
            $index = self::quote($prefix . $definition['name']);
            $table = self::quote($prefix . $definition['table']);
            if (isset($definition['expression'])) {
                $pdo->exec(
                    'CREATE INDEX ' . $index . ' ON ' . $table . ' USING GIN ('
                    . self::expressionSql($definition['expression']) . ')'
                );
                continue;
            }
            $columns = implode(', ', array_map(self::quote(...), $definition['columns'] ?? []));
            if ($columns === '') {
                throw new CoreSchemaException(['reason' => 'canonical_index_definition_invalid']);
            }
            $pdo->exec('CREATE INDEX ' . $index . ' ON ' . $table . ' (' . $columns . ')');
        }
    }

    private static function quote(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $identifier) !== 1) {
            throw new CoreSchemaException(['reason' => 'postgresql_identifier_unsupported']);
        }
        return '"' . $identifier . '"';
    }
}
