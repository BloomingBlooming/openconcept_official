<?php

declare(strict_types=1);

/**
 * Fail-closed error raised when the versioned Core schema contract cannot be
 * proven against the live database.
 */
final class CoreSchemaException extends RuntimeException
{
    public const FAILURE_CODE = 'canonical_schema_mismatch';

    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct(self::FAILURE_CODE, 0, $previous);
    }

    public function failureCode(): string
    {
        return self::FAILURE_CODE;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
