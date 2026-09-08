<?php

declare(strict_types=1);

final class DeploymentRequirementException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        private readonly string $failureCode,
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
