<?php

declare(strict_types=1);

/**
 * Single source of truth for the canonical Core schema generation.
 *
 * A generation is immutable once its catalog and release seal are published.
 * Any Core DDL change must increment this value and add a new core-vN catalog.
 */
final class CoreSchemaGeneration
{
    public const VERSION = 6;
}
