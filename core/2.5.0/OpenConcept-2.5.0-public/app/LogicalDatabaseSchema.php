<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaGeneration.php';

/**
 * Database-neutral schema snapshot used by adapter provisioning, migration,
 * and validation. It is derived from the live canonical source so additive
 * plugin tables participate without duplicating application logic per DB.
 */
final class LogicalDatabaseSchema
{
    public const VERSION = CoreSchemaGeneration::VERSION;

    /** @param array<string, array<string, mixed>> $tables */
    public function __construct(private readonly array $tables)
    {
        if ($tables === []) {
            throw new InvalidArgumentException('Logical database schema cannot be empty.');
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function tables(): array
    {
        return $this->tables;
    }

    /** @return array<string, mixed> */
    public function table(string $logicalName): array
    {
        if (!isset($this->tables[$logicalName])) {
            throw new InvalidArgumentException('Unknown logical table: ' . $logicalName);
        }
        return $this->tables[$logicalName];
    }

    /** @return list<string> */
    public function orderedTables(): array
    {
        $remaining = array_fill_keys(array_keys($this->tables), true);
        $ordered = [];
        while ($remaining !== []) {
            $progress = false;
            foreach (array_keys($remaining) as $table) {
                $dependencies = [];
                foreach ($this->tables[$table]['foreign_keys'] ?? [] as $foreignKey) {
                    $referenced = (string) ($foreignKey['referenced_table'] ?? '');
                    if ($referenced !== '' && $referenced !== $table && isset($this->tables[$referenced])) {
                        $dependencies[$referenced] = true;
                    }
                }
                if (array_intersect_key($dependencies, $remaining) !== []) {
                    continue;
                }
                $ordered[] = $table;
                unset($remaining[$table]);
                $progress = true;
            }
            if (!$progress) {
                // Cyclic cross-table references are copied with destination FK
                // checks deferred/disabled and validated explicitly afterward.
                $tail = array_keys($remaining);
                sort($tail, SORT_STRING);
                array_push($ordered, ...$tail);
                break;
            }
        }
        return $ordered;
    }

    /** @return list<string> */
    public function reverseOrderedTables(): array
    {
        return array_reverse($this->orderedTables());
    }
}
