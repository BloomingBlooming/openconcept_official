<?php

declare(strict_types=1);

/** A user-facing AI error, translated at the API boundary using the user's UI locale. */
final class AiValidationException extends InvalidArgumentException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(public readonly string $translationKey, public readonly array $parameters = [])
    {
        parent::__construct($translationKey);
    }
}
