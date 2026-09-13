<?php

declare(strict_types=1);

final class OpenAITranslationProvider implements TranslationProviderInterface, TranslationProviderHealthCheckInterface
{
    public function __construct(
        private readonly OpenAITranslationClient $client,
        private readonly string $providerLabel = 'Translation Provider'
    ) {
    }

    public function id(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return $this->providerLabel;
    }

    public function isAvailable(): bool
    {
        return $this->client->isConfigured();
    }

    public function translate(array $segments, string $sourceLanguage, string $targetLanguage): array
    {
        return $this->client->translate($segments, $sourceLanguage, $targetLanguage);
    }

    public function healthCheck(): array
    {
        return $this->client->healthCheck();
    }
}
