<?php

declare(strict_types=1);

/** Safe, structured failure raised by the outbound HTTP policy/client. */
final class OutboundHttpException extends RuntimeException
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        private readonly string $failureCode,
        string $message,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    /** @return array<string, scalar|null> */
    public function details(): array
    {
        return $this->details;
    }
}

/** Safe, structured failure returned by an embedding provider. */
final class EmbeddingServiceException extends RuntimeException
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        private readonly string $failureCode,
        string $message,
        private readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    /** @return array<string, scalar|null> */
    public function details(): array
    {
        return $this->details;
    }
}

/** Public API-safe provider failure. Credentials and response bodies are excluded. */
final class RagProviderUnavailableException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        private readonly string $failureCode,
        string $message,
        private readonly array $details = []
    ) {
        parent::__construct($message);
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

/** Public API-safe failure from the shared AI/LLM provider connection. */
final class AiProviderRequestException extends RuntimeException
{
    /** @param array<string, scalar|null> $details */
    public function __construct(
        private readonly string $failureCode,
        string $message,
        private readonly array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function failureCode(): string
    {
        return $this->failureCode;
    }

    /** @return array<string, scalar|null> */
    public function details(): array
    {
        return $this->details;
    }
}
