<?php

declare(strict_types=1);

/**
 * Immutable, provider-neutral retrieval-route aggregate.
 *
 * The effective route is derived from mode and currentPath. It is deliberately
 * not persisted as a second active-route flag.
 */
final class RetrievalRouteState
{
    public const ROUTE_KEY_PRIMARY = 'primary';

    public const MODE_CURRENT_ONLY = 'CURRENT_ONLY';
    public const MODE_FUTURE_SHADOW = 'FUTURE_SHADOW';
    public const MODE_FUTURE_ACTIVE = 'FUTURE_ACTIVE';

    public const CURRENT_NONE = 'none';
    public const CURRENT_STANDARD = 'current_standard';
    public const CURRENT_CUSTOM_V1 = 'current_custom_v1';

    public const READINESS_NOT_APPLICABLE = 'not_applicable';
    public const READINESS_NOT_READY = 'not_ready';
    public const READINESS_REBUILD_REQUIRED = 'rebuild_required';
    public const READINESS_READY = 'ready';
    public const READINESS_NO_TARGET = 'no_target';

    public const ACTIVE_NONE = 'none';
    public const ACTIVE_FUTURE = 'future';

    /** @var list<string> */
    private const MODES = [
        self::MODE_CURRENT_ONLY,
        self::MODE_FUTURE_SHADOW,
        self::MODE_FUTURE_ACTIVE,
    ];

    /** @var list<string> */
    private const CURRENT_PATHS = [
        self::CURRENT_NONE,
        self::CURRENT_STANDARD,
        self::CURRENT_CUSTOM_V1,
    ];

    /** @var list<string> */
    private const READINESS_VALUES = [
        self::READINESS_NOT_APPLICABLE,
        self::READINESS_NOT_READY,
        self::READINESS_REBUILD_REQUIRED,
        self::READINESS_READY,
        self::READINESS_NO_TARGET,
    ];

    public function __construct(
        public readonly string $routeKey,
        public readonly string $mode,
        public readonly string $currentPath,
        public readonly ?string $futureGeneration,
        public readonly int $configRevision,
        public readonly string $rollbackReadiness,
        public readonly string $createdAt,
        public readonly string $updatedAt
    ) {
        $this->assertValid();
    }

    /** @param array<string, mixed> $configuration */
    public static function fromLegacyConfiguration(
        array $configuration,
        string $routeKey = self::ROUTE_KEY_PRIMARY,
        ?string $now = null
    ): self {
        $retrieval = is_array($configuration['retrieval'] ?? null)
            ? $configuration['retrieval']
            : [];
        $currentPath = match ((string) ($retrieval['mode'] ?? 'unconfigured')) {
            'standard' => self::CURRENT_STANDARD,
            'custom' => self::CURRENT_CUSTOM_V1,
            default => self::CURRENT_NONE,
        };
        $timestamp = $now ?? gmdate('Y-m-d H:i:s');

        return new self(
            $routeKey,
            self::MODE_CURRENT_ONLY,
            $currentPath,
            null,
            1,
            self::READINESS_NOT_APPLICABLE,
            $timestamp,
            $timestamp
        );
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['route_key'] ?? ''),
            (string) ($row['mode'] ?? ''),
            (string) ($row['current_path'] ?? ''),
            ($row['future_generation'] ?? null) === null
                ? null
                : (string) $row['future_generation'],
            (int) ($row['config_revision'] ?? 0),
            (string) ($row['rollback_readiness'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['updated_at'] ?? '')
        );
    }

    public function activeRoute(): string
    {
        return $this->mode === self::MODE_FUTURE_ACTIVE
            ? self::ACTIVE_FUTURE
            : $this->currentPath;
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private function assertValid(): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $this->routeKey) !== 1) {
            throw new InvalidArgumentException('Retrieval route key is invalid.');
        }
        if (!in_array($this->mode, self::MODES, true)) {
            throw new InvalidArgumentException('Retrieval route mode is invalid.');
        }
        if (!in_array($this->currentPath, self::CURRENT_PATHS, true)) {
            throw new InvalidArgumentException('Retrieval current path is invalid.');
        }
        if (!in_array($this->rollbackReadiness, self::READINESS_VALUES, true)) {
            throw new InvalidArgumentException('Retrieval rollback readiness is invalid.');
        }
        if ($this->configRevision < 1) {
            throw new InvalidArgumentException('Retrieval route config revision must be positive.');
        }
        if ($this->createdAt === '' || strlen($this->createdAt) > 40
            || $this->updatedAt === '' || strlen($this->updatedAt) > 40) {
            throw new InvalidArgumentException('Retrieval route timestamps are invalid.');
        }
        if ($this->futureGeneration !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,189}$/D', $this->futureGeneration) !== 1) {
            throw new InvalidArgumentException('Future retrieval generation is invalid.');
        }

        if ($this->mode === self::MODE_CURRENT_ONLY) {
            if ($this->futureGeneration !== null
                || $this->rollbackReadiness !== self::READINESS_NOT_APPLICABLE) {
                throw new InvalidArgumentException('CURRENT_ONLY route state is contradictory.');
            }
            return;
        }

        if ($this->futureGeneration === null) {
            throw new InvalidArgumentException('A Future route requires a Future generation.');
        }
        if ($this->currentPath === self::CURRENT_NONE) {
            if ($this->rollbackReadiness !== self::READINESS_NO_TARGET) {
                throw new InvalidArgumentException('A Future route without a Current target must declare no_target.');
            }
            return;
        }
        if (!in_array($this->rollbackReadiness, [
            self::READINESS_NOT_READY,
            self::READINESS_REBUILD_REQUIRED,
            self::READINESS_READY,
        ], true)) {
            throw new InvalidArgumentException('Future rollback readiness is contradictory.');
        }
    }
}
