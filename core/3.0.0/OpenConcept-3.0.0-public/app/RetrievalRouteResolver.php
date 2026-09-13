<?php

declare(strict_types=1);

final class RetrievalRouteResolver
{
    public function __construct(private readonly RetrievalRouteStateRepository $repository)
    {
    }

    public function resolve(
        string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY
    ): RetrievalRouteResolution {
        return new RetrievalRouteResolution($this->repository->requireState($routeKey));
    }
}
