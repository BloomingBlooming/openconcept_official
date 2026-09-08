<?php

declare(strict_types=1);

/** A request-pinned view of one retrieval-route configuration revision. */
final class RetrievalRouteResolution
{
    public readonly string $routeKey;
    public readonly string $mode;
    public readonly string $currentPath;
    public readonly ?string $futureGeneration;
    public readonly int $configRevision;
    public readonly string $rollbackReadiness;
    public readonly string $activeRoute;

    public function __construct(RetrievalRouteState $state)
    {
        $this->routeKey = $state->routeKey;
        $this->mode = $state->mode;
        $this->currentPath = $state->currentPath;
        $this->futureGeneration = $state->futureGeneration;
        $this->configRevision = $state->configRevision;
        $this->rollbackReadiness = $state->rollbackReadiness;
        $this->activeRoute = $state->activeRoute();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route_key' => $this->routeKey,
            'mode' => $this->mode,
            'current_path' => $this->currentPath,
            'future_generation' => $this->futureGeneration,
            'config_revision' => $this->configRevision,
            'rollback_readiness' => $this->rollbackReadiness,
            'active_route' => $this->activeRoute,
        ];
    }
}
