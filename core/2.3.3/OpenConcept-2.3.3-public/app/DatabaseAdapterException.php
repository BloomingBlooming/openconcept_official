<?php

declare(strict_types=1);

final class DatabaseAdapterException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        string $message,
        private readonly string $failureCode = 'adapter_error',
        ?Throwable $previous = null,
        private readonly array $details = []
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

final class DatabaseUnavailableException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly string $backend,
        string $message = 'The canonical OpenConcept database is unavailable.',
        ?Throwable $previous = null,
        private readonly string $failureCode = 'connection_failed',
        private readonly array $details = []
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    public function diagnosticCategory(): string
    {
        if ($this->failureCode === 'authentication_failed') {
            return 'authentication_failed';
        }
        if ($this->failureCode === 'permissions_insufficient') {
            return 'permissions_insufficient';
        }
        if (str_contains($this->failureCode, 'schema')) {
            return 'schema_mismatch';
        }
        if (in_array($this->failureCode, [
            'connection_failed',
            'tcp_connection_failed',
            'dns_resolution_failed',
            'tls_negotiation_failed',
            'database_not_found',
            'driver_missing',
        ], true)) {
            return 'connection_unreachable';
        }
        return 'configuration_error';
    }

    /** @return array<string, mixed> */
    public function details(): array
    {
        return $this->details;
    }
}
