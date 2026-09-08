<?php

declare(strict_types=1);

/**
 * Provider boundary owned by Core. Implementations belong in plugins.
 */
interface TranslationProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function isAvailable(): bool;

    /**
     * Translate only the supplied text values. Segment IDs are opaque and
     * must be returned unchanged; page/block structure never crosses this
     * boundary as writable provider output.
     *
     * @param list<array{id: string, text: string, context: string}> $segments
     * @return array<string, string> translated text indexed by segment ID
     */
    public function translate(array $segments, string $sourceLanguage, string $targetLanguage): array;
}

interface TranslationProviderHealthCheckInterface
{
    /** @return array<string, mixed> */
    public function healthCheck(): array;
}

final class TranslationProviderRegistry
{
    /** @var array<string, TranslationProviderInterface> */
    private array $providers = [];

    public function register(TranslationProviderInterface $provider): void
    {
        $id = $provider->id();
        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $id) !== 1) {
            throw new InvalidArgumentException('Translation provider IDs must be lower-case and hyphenated.');
        }
        if (isset($this->providers[$id])) {
            throw new LogicException(sprintf('Translation provider "%s" is already registered.', $id));
        }
        $this->providers[$id] = $provider;
    }

    public function provider(string $id): TranslationProviderInterface
    {
        $provider = $this->providers[$id] ?? null;
        if (!$provider instanceof TranslationProviderInterface) {
            throw new RuntimeException('指定された翻訳プロバイダーは利用できません。', 422);
        }
        if (!$provider->isAvailable()) {
            throw new RuntimeException('翻訳プロバイダーのAPIキーまたは設定が不足しています。', 422);
        }
        return $provider;
    }

    public function registeredProvider(string $id): TranslationProviderInterface
    {
        $provider = $this->providers[$id] ?? null;
        if (!$provider instanceof TranslationProviderInterface) {
            throw new RuntimeException('指定された翻訳プロバイダーは登録されていません。', 422);
        }
        return $provider;
    }

    /** @return list<array{id: string, label: string, available: bool}> */
    public function summaries(): array
    {
        $summaries = [];
        foreach ($this->providers as $provider) {
            $summaries[] = [
                'id' => $provider->id(),
                'label' => $provider->label(),
                'available' => $provider->isAvailable(),
            ];
        }
        return $summaries;
    }
}
