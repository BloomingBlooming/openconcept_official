<?php

declare(strict_types=1);

require_once __DIR__ . '/DatabaseAdapterException.php';
require_once __DIR__ . '/DatabaseSelectionGuard.php';

/**
 * Mutable connection generation shared by a PrefixedPDO and every statement
 * it creates. PDOStatement otherwise remains executable after the PDO wrapper
 * has been retired by an adapter cutover.
 */
final class PrefixedPDOConnectionState
{
    private bool $retired = false;

    public function retire(): void
    {
        $this->retired = true;
    }

    public function assertActive(): void
    {
        if ($this->retired) {
            throw new DatabaseAdapterException(
                'This database connection was retired by canonical adapter cutover.',
                'canonical_backend_changed'
            );
        }
    }
}

/**
 * PDO exposes a live cursor Iterator. Guarding only Statement::getIterator()
 * is insufficient because callers may retain that Iterator across cutover.
 */
final class PrefixedPDOStatementIterator implements Iterator
{
    public function __construct(
        private readonly Iterator $iterator,
        private readonly PrefixedPDOConnectionState $connectionState
    ) {
    }

    public function rewind(): void
    {
        $this->connectionState->assertActive();
        $this->iterator->rewind();
    }

    public function current(): mixed
    {
        $this->connectionState->assertActive();
        return $this->iterator->current();
    }

    public function key(): mixed
    {
        $this->connectionState->assertActive();
        return $this->iterator->key();
    }

    public function next(): void
    {
        $this->connectionState->assertActive();
        $this->iterator->next();
    }

    public function valid(): bool
    {
        $this->connectionState->assertActive();
        return $this->iterator->valid();
    }
}

/**
 * A statement must observe the same cutover generation as its parent PDO.
 * Guard both writes and buffered/cursor reads so no retained old-source
 * statement can be used after the canonical backend changes.
 */
final class PrefixedPDOStatement extends PDOStatement
{
    protected function __construct(private readonly PrefixedPDOConnectionState $connectionState)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->connectionState->assertActive();
        return parent::execute($params);
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $this->connectionState->assertActive();
        return parent::fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $this->connectionState->assertActive();
        return parent::fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->connectionState->assertActive();
        return parent::fetchColumn($column);
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $this->connectionState->assertActive();
        return parent::fetchObject($class, $constructorArgs);
    }

    public function nextRowset(): bool
    {
        $this->connectionState->assertActive();
        return parent::nextRowset();
    }

    public function getIterator(): Iterator
    {
        $this->connectionState->assertActive();
        return new PrefixedPDOStatementIterator(
            parent::getIterator(),
            $this->connectionState
        );
    }
}

/**
 * Rewrites only OpenConcept's known logical table names.
 * This keeps application SQL readable while isolating physical tables in a shared MySQL database.
 */
final class PrefixedPDO extends PDO
{
    public const WRITER_GENERATION = 'normalized-department-acl-v1';

    private const TABLES = [
        'page_public_share_pages',
        'page_public_shares',
        'rag_source_access_departments',
        'rag_source_access_members',
        'rag_source_unit_blocks',
        'rag_source_documents',
        'rag_route_state',
        'rag_route_audit',
        'rag_index_engines',
        'rag_index_generations',
        'rag_index_sync',
        'rag_index_jobs',
        'rag_index_runs',
        'rag_settings',
        'rag_generated_tags',
        'rag_canonical_tags',
        'rag_page_tag_links',
        'rag_block_metadata',
        'rag_chunk_blocks',
        'rag_tag_aliases',
        'ai_conversations',
        'ai_chat_turns',
        'page_access_departments',
        'page_access_members',
        'rag_page_profiles',
        'rag_source_units',
        'system_settings',
        'notifications',
        'audit_logs',
        'rag_chunks',
        'rag_jobs',
        'revisions',
        'comments',
        'files',
        'pages',
        'users',
    ];

    private string $tablePrefix;

    private ?DatabaseMaintenanceMode $databaseMaintenanceMode = null;

    /** @var resource|null */
    private mixed $databaseMaintenanceLease = null;

    private ?DatabaseSelectionGuard $databaseSelectionGuard = null;

    /** @var resource|null */
    private mixed $databaseSelectionLease = null;

    private PrefixedPDOConnectionState $connectionState;

    /** @param array<int, mixed> $options */
    public function __construct(string $dsn, ?string $username, ?string $password, array $options, string $tablePrefix)
    {
        $this->tablePrefix = self::validatePrefix($tablePrefix);
        $this->connectionState = new PrefixedPDOConnectionState();
        parent::__construct($dsn, $username, $password, $options);
        if (!parent::setAttribute(PDO::ATTR_STATEMENT_CLASS, [
            PrefixedPDOStatement::class,
            [$this->connectionState],
        ])) {
            throw new DatabaseAdapterException(
                'The canonical adapter could not install statement retirement guards.',
                'maintenance_barrier_unavailable'
            );
        }
        if ((string) parent::getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            && defined('OPENCONCEPT_WRITER_GENERATION')
            && constant('OPENCONCEPT_WRITER_GENERATION') === self::WRITER_GENERATION) {
            parent::exec("SET @openconcept_writer_generation = '" . self::WRITER_GENERATION . "'");
        }
    }

    public function tablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /** @param resource $lease */
    public function attachDatabaseMaintenanceLease(
        DatabaseMaintenanceMode $mode,
        $lease
    ): void {
        if (!is_resource($lease) || is_resource($this->databaseMaintenanceLease)) {
            throw new LogicException('The PDO maintenance lease cannot be attached.');
        }
        $this->databaseMaintenanceMode = $mode;
        $this->databaseMaintenanceLease = $lease;
    }

    /** @param array<string, scalar|null> $context */
    public function beginAdapterCutover(array $context = []): void
    {
        if ($this->databaseMaintenanceMode === null || !is_resource($this->databaseMaintenanceLease)) {
            throw new LogicException('The PDO maintenance lease is unavailable.');
        }
        $this->databaseMaintenanceLease = $this->databaseMaintenanceMode
            ->upgradeSharedToExclusive($this->databaseMaintenanceLease, $context);
    }

    /** @param array<string, scalar|null> $details */
    public function updateAdapterCutoverStage(
        string $stage,
        array $details = [],
        bool $cancellable = true
    ): void {
        if ($this->databaseMaintenanceMode === null || !is_resource($this->databaseMaintenanceLease)) {
            throw new LogicException('The PDO maintenance lease is unavailable.');
        }
        $this->databaseMaintenanceMode->updateExclusiveStage(
            $this->databaseMaintenanceLease,
            $stage,
            $details,
            $cancellable
        );
    }

    public function throwIfAdapterCutoverCancelled(): void
    {
        if ($this->databaseMaintenanceMode === null || !is_resource($this->databaseMaintenanceLease)) {
            throw new LogicException('The PDO maintenance lease is unavailable.');
        }
        $this->databaseMaintenanceMode->throwIfCancellationRequested($this->databaseMaintenanceLease);
    }

    public function finishAdapterCutover(bool $activated): void
    {
        if ($this->databaseMaintenanceMode === null || !is_resource($this->databaseMaintenanceLease)) {
            throw new LogicException('The PDO maintenance lease is unavailable.');
        }
        $lease = $this->databaseMaintenanceLease;
        if ($activated) {
            // Retire statements before the exclusive lease is released. A
            // failure while releasing an already-activated cutover must not
            // make the old connection usable again.
            $this->connectionState->retire();
            // The adapter state has already committed the new canonical
            // selection. Clear the old binding while every other writer is
            // still drained; the first process on the new DB seals it again.
            $this->releaseDatabaseSelectionLease(true);
        }
        $this->databaseMaintenanceMode->finishExclusiveUpgrade($lease, $activated);
        if ($activated) {
            $this->databaseMaintenanceLease = null;
            $this->databaseMaintenanceMode = null;
        }
    }

    public function releaseDatabaseMaintenanceLease(): void
    {
        if ($this->databaseMaintenanceMode !== null && is_resource($this->databaseMaintenanceLease)) {
            $this->databaseMaintenanceMode->releaseShared($this->databaseMaintenanceLease);
        }
        $this->databaseMaintenanceLease = null;
        $this->databaseMaintenanceMode = null;
    }

    /** @param resource $lease */
    public function attachDatabaseSelectionLease(DatabaseSelectionGuard $guard, $lease): void
    {
        if (!is_resource($lease) || is_resource($this->databaseSelectionLease)) {
            throw new LogicException('The PDO database selection lease cannot be attached.');
        }
        $this->databaseSelectionGuard = $guard;
        $this->databaseSelectionLease = $lease;
    }

    public function releaseDatabaseSelectionLease(bool $canonicalCutover = false): void
    {
        if ($this->databaseSelectionGuard !== null && is_resource($this->databaseSelectionLease)) {
            if ($canonicalCutover) {
                $this->databaseSelectionGuard->releaseForCanonicalCutover($this->databaseSelectionLease);
            } else {
                $this->databaseSelectionGuard->release($this->databaseSelectionLease);
            }
        }
        $this->databaseSelectionLease = null;
        $this->databaseSelectionGuard = null;
    }

    public function __destruct()
    {
        $this->releaseDatabaseSelectionLease();
        $this->releaseDatabaseMaintenanceLease();
    }

    /** @return list<string> */
    public static function logicalTables(): array
    {
        return self::TABLES;
    }

    public function physicalTableName(string $logicalName): string
    {
        if (!in_array($logicalName, self::TABLES, true)) {
            throw new InvalidArgumentException('Unknown OpenConcept table: ' . $logicalName);
        }
        return $this->tablePrefix . $logicalName;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->assertDatabaseConnectionActive();
        return parent::prepare($this->prefixSql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->assertDatabaseConnectionActive();
        $query = $this->prefixSql($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        $this->assertDatabaseConnectionActive();
        return parent::exec($this->prefixSql($statement));
    }

    public function beginTransaction(): bool
    {
        $this->assertDatabaseConnectionActive();
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->assertDatabaseConnectionActive();
        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->assertDatabaseConnectionActive();
        return parent::rollBack();
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->assertDatabaseConnectionActive();
        return parent::setAttribute($attribute, $value);
    }

    public function inTransaction(): bool
    {
        $this->assertDatabaseConnectionActive();
        return parent::inTransaction();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        $this->assertDatabaseConnectionActive();
        return parent::lastInsertId($name);
    }

    public function getAttribute(int $attribute): mixed
    {
        $this->assertDatabaseConnectionActive();
        return parent::getAttribute($attribute);
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        $this->assertDatabaseConnectionActive();
        return parent::quote($string, $type);
    }

    public function errorCode(): ?string
    {
        $this->assertDatabaseConnectionActive();
        return parent::errorCode();
    }

    public function errorInfo(): array
    {
        $this->assertDatabaseConnectionActive();
        return parent::errorInfo();
    }

    public function prefixSql(string $sql): string
    {
        if ($this->tablePrefix !== '') {
            $tables = implode('|', array_map(static fn(string $table): string => preg_quote($table, '/'), self::TABLES));
            $sql = preg_replace_callback(
                '/(?<![A-Za-z0-9_])(`?)(?:' . $tables . ')\1(?![A-Za-z0-9_])/i',
                function (array $matches): string {
                    $quoted = str_starts_with($matches[0], '`');
                    $logicalName = $quoted ? substr($matches[0], 1, -1) : $matches[0];
                    $physicalName = $this->tablePrefix . strtolower($logicalName);
                    return $quoted ? '`' . $physicalName . '`' : $physicalName;
                },
                $sql
            ) ?? $sql;
        }

        if ((string) parent::getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            // Existing repositories use validated MySQL-style identifier
            // quoting. PostgreSQL accepts the same identifiers with ANSI
            // double quotes; string literals are deliberately untouched.
            $sql = preg_replace_callback(
                '/`([A-Za-z][A-Za-z0-9_]*)`/',
                static fn (array $matches): string => '"' . $matches[1] . '"',
                $sql
            ) ?? $sql;
        }
        return $sql;
    }

    private static function validatePrefix(string $prefix): string
    {
        if ($prefix === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,39}_$/', $prefix)) {
            throw new InvalidArgumentException('OPENCONCEPT_TABLE_PREFIX は英字で始まり、末尾がアンダースコアの40文字以内で指定してください。');
        }
        return strtolower($prefix);
    }

    private function assertDatabaseConnectionActive(): void
    {
        $this->connectionState->assertActive();
    }
}
