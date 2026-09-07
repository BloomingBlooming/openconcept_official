<?php

declare(strict_types=1);

final class RagProviderRegistry
{
    /** @var array<string, RetrieverProviderInterface> */
    private array $retrievers = [];

    /** @var array<string, RagEngineInterface> */
    private array $engines = [];

    /** @var array<string, Closure(array<string, mixed>): RagRetrieverInterface> */
    private array $ragRetrieverFactories = [];

    /** @var array<string, Closure(array<string, mixed>): ChatProviderInterface> */
    private array $chatProviderFactories = [];

    public function registerRetriever(string $id, RetrieverProviderInterface $provider): void
    {
        $this->assertId($id);
        if (isset($this->retrievers[$id])) {
            throw new LogicException('RAG retriever is already registered: ' . $id);
        }
        $this->retrievers[$id] = $provider;
    }

    public function registerEngine(string $id, RagEngineInterface $engine): void
    {
        $this->assertId($id);
        if (isset($this->engines[$id])) {
            throw new LogicException('RAG engine is already registered: ' . $id);
        }
        if (!str_starts_with($engine->capabilities()->apiVersion, '1')) {
            throw new LogicException('RAG engine uses an unsupported API version: ' . $id);
        }
        $this->engines[$id] = $engine;
    }

    /** @param callable(array<string, mixed>): RagRetrieverInterface $factory */
    public function registerRagRetrieverFactory(string $id, callable $factory): void
    {
        $this->assertId($id);
        if (isset($this->ragRetrieverFactories[$id])) {
            throw new LogicException('RAG retriever factory is already registered: ' . $id);
        }
        $this->ragRetrieverFactories[$id] = Closure::fromCallable($factory);
    }

    /** @param callable(array<string, mixed>): ChatProviderInterface $factory */
    public function registerChatProviderFactory(string $id, callable $factory): void
    {
        $this->assertId($id);
        if (isset($this->chatProviderFactories[$id])) {
            throw new LogicException('Chat provider factory is already registered: ' . $id);
        }
        $this->chatProviderFactories[$id] = Closure::fromCallable($factory);
    }

    public function retriever(string $id): ?RetrieverProviderInterface
    {
        $providers = Hooks::filter('rag_retriever_providers', $this->retrievers);
        return $this->validatedProviders($providers, RetrieverProviderInterface::class)[$id] ?? null;
    }

    public function engine(string $id): ?RagEngineInterface
    {
        $engines = Hooks::filter('rag_engines', $this->engines);
        return $this->validatedProviders($engines, RagEngineInterface::class)[$id] ?? null;
    }

    /** @param array<string, mixed> $configuration */
    public function ragRetriever(string $id, array $configuration = []): ?RagRetrieverInterface
    {
        $factories = Hooks::filter('rag_retriever_factories', $this->ragRetrieverFactories);
        $factory = $this->validatedFactories($factories)[$id] ?? null;
        if (!$factory instanceof Closure) {
            return null;
        }
        $provider = $factory($configuration);
        if (!$provider instanceof RagRetrieverInterface
            || !str_starts_with($provider->capabilities()->apiVersion, '1')) {
            throw new RuntimeException('RAG retriever factory returned an incompatible provider.');
        }
        return $provider;
    }

    /** @param array<string, mixed> $configuration */
    public function chatProvider(string $id, array $configuration = []): ?ChatProviderInterface
    {
        $factories = Hooks::filter('chat_provider_factories', $this->chatProviderFactories);
        $factory = $this->validatedFactories($factories)[$id] ?? null;
        if (!$factory instanceof Closure) {
            return null;
        }
        $provider = $factory($configuration);
        if (!$provider instanceof ChatProviderInterface) {
            throw new RuntimeException('Chat provider factory returned an incompatible provider.');
        }
        return $provider;
    }

    /** @return list<string> */
    public function providerIds(): array
    {
        $ids = array_unique([
            ...array_keys($this->validatedProviders(
                Hooks::filter('rag_retriever_providers', $this->retrievers),
                RetrieverProviderInterface::class
            )),
            ...array_keys($this->validatedProviders(
                Hooks::filter('rag_engines', $this->engines),
                RagEngineInterface::class
            )),
            ...array_keys($this->validatedFactories(
                Hooks::filter('rag_retriever_factories', $this->ragRetrieverFactories)
            )),
        ]);
        sort($ids, SORT_STRING);
        return array_values($ids);
    }

    /** @return list<string> */
    public function chatProviderIds(): array
    {
        $ids = array_keys($this->validatedFactories(
            Hooks::filter('chat_provider_factories', $this->chatProviderFactories)
        ));
        sort($ids, SORT_STRING);
        return array_values($ids);
    }

    /** @return array<string, object> */
    private function validatedProviders(mixed $providers, string $interface): array
    {
        if (!is_array($providers)) {
            throw new RuntimeException('RAG provider registration filter must return an array.');
        }
        $validated = [];
        foreach ($providers as $id => $provider) {
            if (!is_string($id) || !$provider instanceof $interface) {
                throw new RuntimeException('RAG provider registration is invalid.');
            }
            $this->assertId($id);
            $validated[$id] = $provider;
        }
        return $validated;
    }

    /** @return array<string, Closure> */
    private function validatedFactories(mixed $factories): array
    {
        if (!is_array($factories)) {
            throw new RuntimeException('Provider factory registration filter must return an array.');
        }
        $validated = [];
        foreach ($factories as $id => $factory) {
            if (!is_string($id) || !is_callable($factory)) {
                throw new RuntimeException('Provider factory registration is invalid.');
            }
            $this->assertId($id);
            $validated[$id] = $factory instanceof Closure ? $factory : Closure::fromCallable($factory);
        }
        return $validated;
    }

    private function assertId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $id) !== 1) {
            throw new InvalidArgumentException('RAG provider ID is invalid.');
        }
    }
}
