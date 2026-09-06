<?php

declare(strict_types=1);

require_once __DIR__ . '/CoreSchemaCatalog.php';
require_once __DIR__ . '/CoreSchemaException.php';
require_once __DIR__ . '/CoreSchemaRuntime.php';

/**
 * Read-only compatibility facade for retrieval-route callers.
 *
 * Route tables are part of the mandatory Core36 schema. They no longer own a
 * migration marker, perform DDL, or degrade independently. The Database boot
 * lifecycle is the only writer; any mismatch is a canonical-schema failure
 * and must stop the process.
 */
final class RetrievalRouteSchemaRuntime
{
    private function __construct(private readonly int $schemaVersion)
    {
    }

    public static function boot(
        PDO $pdo,
        ?bool $verifyOnly = null,
        bool $writesAllowed = true
    ): self {
        // The legacy arguments remain for API compatibility only. This facade
        // is intentionally read-only in normal, verify-only, and gated boots.
        unset($verifyOnly, $writesAllowed);
        $catalog = CoreSchemaCatalog::load();
        try {
            $report = (new CoreSchemaRuntime($pdo, $catalog))->preflight(false);
        } catch (CoreSchemaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CoreSchemaException([
                'reason' => 'canonical_route_schema_verification_failed',
            ], $exception);
        }
        if (!is_array($report)) {
            throw new CoreSchemaException([
                'reason' => 'canonical_schema_upgrade_required',
                'target_schema_version' => $catalog->schemaVersion(),
            ]);
        }
        return new self($catalog->schemaVersion());
    }

    public function available(): bool
    {
        return true;
    }

    /** @return array{available: true, failure_code: null, schema_version: int, target_schema_version: int} */
    public function status(): array
    {
        return [
            'available' => true,
            'failure_code' => null,
            'schema_version' => $this->schemaVersion,
            'target_schema_version' => CoreSchemaCatalog::SCHEMA_VERSION,
        ];
    }
}
