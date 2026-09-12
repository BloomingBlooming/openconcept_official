<?php

declare(strict_types=1);

final class RetrievalRouteTransitionService
{
    private const CUTOVER_GATE_REQUIRED =
        'Future activation and rollback require the dedicated Cutover Gate, which is not available in this phase.';

    /** @var list<string> */
    private const PHASE_ONE_TRANSITION_KINDS = [
        'change_current',
        'start_shadow',
        'change_shadow_candidate',
        'stop_shadow',
    ];

    public function __construct(private readonly RetrievalRouteStateRepository $repository)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function transition(
        int $expectedRevision,
        string $targetMode,
        string $targetCurrentPath,
        ?string $targetFutureGeneration,
        int $actorUserId,
        string $actorRole,
        string $transitionKind,
        array $metadata = [],
        string $routeKey = RetrievalRouteState::ROUTE_KEY_PRIMARY
    ): RetrievalRouteState {
        if ($actorRole !== 'admin' || $actorUserId < 1) {
            throw new RuntimeException('Retrieval route administrator access is required.');
        }
        $this->assertPhaseOneTransitionKind($transitionKind);
        $this->assertSafeMetadata($metadata);

        return $this->repository->compareAndSwap(
            $routeKey,
            $expectedRevision,
            function (RetrievalRouteState $previous) use (
                $targetMode,
                $targetCurrentPath,
                $targetFutureGeneration,
                $transitionKind
            ): RetrievalRouteState {
                if (($previous->mode === RetrievalRouteState::MODE_FUTURE_SHADOW
                        && $targetMode === RetrievalRouteState::MODE_FUTURE_ACTIVE)
                    || ($previous->mode === RetrievalRouteState::MODE_FUTURE_ACTIVE
                        && $targetMode === RetrievalRouteState::MODE_FUTURE_SHADOW)) {
                    throw new InvalidArgumentException(self::CUTOVER_GATE_REQUIRED);
                }

                $allowed = [
                    RetrievalRouteState::MODE_CURRENT_ONLY => [
                        RetrievalRouteState::MODE_CURRENT_ONLY,
                        RetrievalRouteState::MODE_FUTURE_SHADOW,
                    ],
                    RetrievalRouteState::MODE_FUTURE_SHADOW => [
                        RetrievalRouteState::MODE_CURRENT_ONLY,
                        RetrievalRouteState::MODE_FUTURE_SHADOW,
                        RetrievalRouteState::MODE_FUTURE_ACTIVE,
                    ],
                    RetrievalRouteState::MODE_FUTURE_ACTIVE => [
                        RetrievalRouteState::MODE_FUTURE_SHADOW,
                        RetrievalRouteState::MODE_FUTURE_ACTIVE,
                    ],
                ];
                if (!in_array($targetMode, $allowed[$previous->mode] ?? [], true)) {
                    throw new InvalidArgumentException('Direct retrieval route transition is forbidden.');
                }
                if ($targetMode !== $previous->mode
                    && $targetCurrentPath !== $previous->currentPath) {
                    throw new InvalidArgumentException('A route mode transition cannot also replace its Current path.');
                }
                if ($previous->mode === RetrievalRouteState::MODE_FUTURE_ACTIVE
                    && $targetMode === RetrievalRouteState::MODE_FUTURE_ACTIVE
                    && $targetFutureGeneration !== $previous->futureGeneration) {
                    throw new InvalidArgumentException('An active Future generation cannot be replaced without shadow validation.');
                }
                $this->assertTransitionKindMatches(
                    $transitionKind,
                    $previous,
                    $targetMode,
                    $targetCurrentPath,
                    $targetFutureGeneration
                );

                $readiness = match (true) {
                    $targetMode === RetrievalRouteState::MODE_CURRENT_ONLY =>
                        RetrievalRouteState::READINESS_NOT_APPLICABLE,
                    $targetCurrentPath === RetrievalRouteState::CURRENT_NONE =>
                        RetrievalRouteState::READINESS_NO_TARGET,
                    $previous->mode === RetrievalRouteState::MODE_CURRENT_ONLY
                        && $targetMode === RetrievalRouteState::MODE_FUTURE_SHADOW =>
                        RetrievalRouteState::READINESS_NOT_READY,
                    $targetCurrentPath !== $previous->currentPath
                        || $targetFutureGeneration !== $previous->futureGeneration =>
                        RetrievalRouteState::READINESS_NOT_READY,
                    default => $previous->rollbackReadiness,
                };
                $next = new RetrievalRouteState(
                    $previous->routeKey,
                    $targetMode,
                    $targetCurrentPath,
                    $targetFutureGeneration,
                    $previous->configRevision + 1,
                    $readiness,
                    $previous->createdAt,
                    gmdate('Y-m-d H:i:s')
                );

                if ($next->mode === $previous->mode
                    && $next->currentPath === $previous->currentPath
                    && $next->futureGeneration === $previous->futureGeneration
                    && $next->rollbackReadiness === $previous->rollbackReadiness) {
                    throw new InvalidArgumentException('Retrieval route transition does not change state.');
                }
                return $next;
            },
            'administrator',
            $actorUserId,
            $transitionKind,
            $metadata
        );
    }

    private function assertPhaseOneTransitionKind(string $transitionKind): void
    {
        if (!in_array($transitionKind, self::PHASE_ONE_TRANSITION_KINDS, true)) {
            throw new InvalidArgumentException('Retrieval route transition kind is not available in this phase.');
        }
    }

    private function assertTransitionKindMatches(
        string $transitionKind,
        RetrievalRouteState $previous,
        string $targetMode,
        string $targetCurrentPath,
        ?string $targetFutureGeneration
    ): void {
        $matches = match ($transitionKind) {
            'change_current' =>
                $previous->mode === RetrievalRouteState::MODE_CURRENT_ONLY
                && $targetMode === RetrievalRouteState::MODE_CURRENT_ONLY
                && $targetFutureGeneration === null
                && $targetCurrentPath !== $previous->currentPath,
            'start_shadow' =>
                $previous->mode === RetrievalRouteState::MODE_CURRENT_ONLY
                && $targetMode === RetrievalRouteState::MODE_FUTURE_SHADOW,
            'change_shadow_candidate' =>
                $previous->mode === RetrievalRouteState::MODE_FUTURE_SHADOW
                && $targetMode === RetrievalRouteState::MODE_FUTURE_SHADOW
                && ($targetCurrentPath !== $previous->currentPath
                    || $targetFutureGeneration !== $previous->futureGeneration),
            'stop_shadow' =>
                $previous->mode === RetrievalRouteState::MODE_FUTURE_SHADOW
                && $targetMode === RetrievalRouteState::MODE_CURRENT_ONLY,
            default => false,
        };
        if (!$matches) {
            throw new InvalidArgumentException(
                'Retrieval route transition kind does not match the requested state change.'
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function assertSafeMetadata(array $metadata): void
    {
        if ($metadata !== [] && array_is_list($metadata)) {
            throw new InvalidArgumentException('Retrieval route audit metadata must be a keyed object.');
        }
        $items = 0;
        $this->assertSafeMetadataValue($metadata, 0, $items, true);
    }

    private function assertSafeMetadataValue(
        mixed $value,
        int $depth,
        int &$items,
        bool $keyedObject = false
    ): void {
        if ($depth > 4) {
            throw new InvalidArgumentException('Retrieval route audit metadata is too deeply nested.');
        }
        if (is_array($value)) {
            if (count($value) > 32) {
                throw new InvalidArgumentException('Retrieval route audit metadata contains too many entries.');
            }
            $isList = array_is_list($value);
            if ($keyedObject && $value !== [] && $isList) {
                throw new InvalidArgumentException('Retrieval route audit metadata must be a keyed object.');
            }
            foreach ($value as $key => $entry) {
                $items++;
                if ($items > 64) {
                    throw new InvalidArgumentException('Retrieval route audit metadata contains too many entries.');
                }
                if (!$isList) {
                    if (!is_string($key)
                        || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1) {
                        throw new InvalidArgumentException('Retrieval route audit metadata key is invalid.');
                    }
                    $this->assertMetadataKeyIsNotSensitive($key);
                }
                $this->assertSafeMetadataValue($entry, $depth + 1, $items);
            }
            return;
        }
        if (is_string($value)) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
                throw new InvalidArgumentException(
                    'Retrieval route audit metadata strings must be bounded machine-readable codes.'
                );
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }
        throw new InvalidArgumentException('Retrieval route audit metadata value type is invalid.');
    }

    private function assertMetadataKeyIsNotSensitive(string $key): void
    {
        $segments = preg_split('/_+/', strtolower($key), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (['password', 'passwd', 'passphrase', 'secret', 'token', 'credential', 'credentials'] as $term) {
            if (in_array($term, $segments, true)) {
                throw new InvalidArgumentException('Retrieval route audit metadata contains a sensitive key.');
            }
        }
        $compact = implode('', $segments);
        foreach ([
            'apikey',
            'accesskey',
            'privatekey',
            'clientsecret',
            'accesstoken',
            'refreshtoken',
            'idtoken',
            'bearertoken',
            'authorization',
            'sessioncookie',
        ] as $term) {
            if (str_contains($compact, $term)) {
                throw new InvalidArgumentException('Retrieval route audit metadata contains a sensitive key.');
            }
        }
    }
}
