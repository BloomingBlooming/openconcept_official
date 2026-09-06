<?php

declare(strict_types=1);

/**
 * Runs the normal RAG maintenance batch after any successful login.
 *
 * The CLI worker and this web-triggered worker share the same file lock so a
 * burst of logins cannot claim work alongside an already-running batch.
 */
final class RagLoginWorker
{
    public const DEFAULT_LIMIT = 10;

    private PDO $pdo;
    private OpenAIClient $client;
    private string $storagePath;
    private string $deploymentWriteGatePath;

    public function __construct(
        PDO $pdo,
        OpenAIClient $client,
        string $storagePath,
        ?string $deploymentWriteGatePath = null
    ) {
        $this->pdo = $pdo;
        $this->client = $client;
        $this->storagePath = rtrim($storagePath, "/\\");
        $this->deploymentWriteGatePath = $deploymentWriteGatePath
            ?? $this->storagePath . DIRECTORY_SEPARATOR . '.deployment-write-gate';
    }

    /** @return array<string, mixed> */
    public function run(int $limit = self::DEFAULT_LIMIT): array
    {
        if (is_file($this->deploymentWriteGatePath)) {
            return $this->deploymentGateResult();
        }

        $lockPath = $this->storagePath . DIRECTORY_SEPARATOR . 'rag-worker.lock';
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            throw new RuntimeException('RAG worker lock could not be opened.');
        }

        try {
            if (!@flock($lockHandle, LOCK_EX | LOCK_NB)) {
                return ['state' => 'already_running', 'trigger' => 'login'];
            }

            // A deployment may start between the first marker check and lock
            // acquisition. Do not connect the worker pipeline to that write
            // window even though the login request itself has authenticated.
            if (is_file($this->deploymentWriteGatePath)) {
                return $this->deploymentGateResult();
            }

            $pipeline = new RagPipeline($this->pdo, $this->client);
            $workerId = 'login-' . (gethostname() ?: 'web') . '-' . getmypid();
            $output = [
                'state' => 'completed',
                'trigger' => 'login',
                'model' => $this->client->model(),
                'source_schema_version' => RagPipeline::SOURCE_SCHEMA_VERSION,
                'prompt_version' => RagPipeline::PROMPT_VERSION,
                'inactive_sources_deactivated' => $pipeline->deactivateInactiveCurrentSources(),
                'timestamp_scan_queued' => $pipeline->queueUpdatedPublished(),
                'processed' => $pipeline->processQueued(
                    max(1, min(100, $limit)),
                    $workerId
                ),
            ];
            if (class_exists(Hooks::class, false)) {
                $ragCore = Hooks::filter('rag_core_service', null);
                if ($ragCore instanceof RagCoreService) {
                    $output['rag_core'] = $ragCore->processQueued(
                        max(1, min(100, $limit)),
                        $workerId . '-rag-core'
                    );
                }
                $output['plugin_maintenance'] = Hooks::filter(
                    'rag_maintenance',
                    [],
                    max(1, min(100, $limit)),
                    $workerId
                );
            }
            $this->writeLog($output);
            return $output;
        } catch (Throwable $exception) {
            $this->writeLog([
                'state' => 'error',
                'trigger' => 'login',
                'model' => $this->client->model(),
                'error' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @return array{state: string, trigger: string} */
    private function deploymentGateResult(): array
    {
        return ['state' => 'deployment_write_gate_active', 'trigger' => 'login'];
    }

    /** @param array<string, mixed> $entry */
    private function writeLog(array $entry): void
    {
        $line = date('c') . ' '
            . json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . PHP_EOL;
        $written = @file_put_contents(
            $this->storagePath . DIRECTORY_SEPARATOR . 'rag-worker.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
        if ($written === false) {
            error_log('OpenConcept login-triggered RAG worker log could not be written.');
        }
    }
}
