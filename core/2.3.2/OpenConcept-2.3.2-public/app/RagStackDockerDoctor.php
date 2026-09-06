<?php

declare(strict_types=1);

final class RagStackDockerDoctor
{
    private const FULL_SERVICES = [
        'openconcept-web',
        'openconcept-worker',
        'postgres',
        'rag-embedding',
    ];
    private const HYBRID_SERVICES = ['postgres', 'rag-embedding'];

    /** @var Closure(list<string>): array{exit_code: int, stdout: string, stderr: string} */
    private Closure $runner;
    private string $root;
    /** @var list<string> */
    private array $pdoDrivers;

    /** @param null|callable(list<string>): array{exit_code: int, stdout: string, stderr: string} $runner */
    public function __construct(string $applicationRoot, ?callable $runner = null, ?array $pdoDrivers = null)
    {
        $this->root = realpath($applicationRoot) ?: rtrim($applicationRoot, "/\\");
        $this->runner = $runner !== null
            ? Closure::fromCallable($runner)
            : $this->defaultRunner(...);
        $this->pdoDrivers = $pdoDrivers === null
            ? PDO::getAvailableDrivers()
            : array_values(array_filter($pdoDrivers, 'is_string'));
    }

    /** @return array<string, mixed> */
    public function inspect(
        ?string $composeFile = null,
        ?string $projectName = null,
        string $topology = 'full',
        ?string $environmentFile = null
    ): array
    {
        if (!in_array($topology, ['full', 'hybrid'], true)) {
            throw new InvalidArgumentException('The Docker RAG topology must be full or hybrid.');
        }
        $requiredServices = $topology === 'full' ? self::FULL_SERVICES : self::HYBRID_SERVICES;
        $composeFile ??= $this->root . DIRECTORY_SEPARATOR . 'compose.rag.yaml';
        $expectedCompose = realpath($this->root . DIRECTORY_SEPARATOR . 'compose.rag.yaml');
        $resolvedCompose = realpath($composeFile);
        if ($expectedCompose === false || $resolvedCompose === false || $resolvedCompose !== $expectedCompose) {
            throw new DeploymentRequirementException(
                'The audited compose.rag.yaml file is required.',
                'rag_stack_attestation_invalid'
            );
        }
        $resolvedEnvironment = null;
        if ($environmentFile !== null) {
            $expectedEnvironment = realpath($this->root . DIRECTORY_SEPARATOR . '.env.rag');
            $resolvedEnvironment = realpath($environmentFile);
            if ($expectedEnvironment === false || $resolvedEnvironment === false
                || $resolvedEnvironment !== $expectedEnvironment) {
                throw new DeploymentRequirementException(
                    'The project .env.rag file is required for Host Native Docker RAG inspection.',
                    'rag_stack_attestation_invalid'
                );
            }
        }
        $projectName = trim((string) ($projectName ?? getenv('OPENCONCEPT_RAG_COMPOSE_PROJECT')));
        if ($projectName === '') {
            $projectName = 'openconcept-v2-3-rag';
        }
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $projectName) !== 1) {
            throw new InvalidArgumentException('The Docker Compose project name is invalid.');
        }

        $engineVersion = trim($this->required(['docker', 'version', '--format', '{{.Server.Version}}'], 'docker_engine_unavailable'));
        $composeVersion = trim($this->required(['docker', 'compose', 'version', '--short'], 'docker_compose_unavailable'));
        $composeVersionMatches = [];
        $composeVersionValid = preg_match(
            '/^(?:v)?(?<major>[0-9]+)\.[0-9]+(?:\.[0-9]+)?(?:[-+._A-Za-z0-9]*)?$/D',
            $composeVersion,
            $composeVersionMatches
        ) === 1 && (int) ($composeVersionMatches['major'] ?? 0) >= 2;
        if ($engineVersion === '' || !$composeVersionValid) {
            throw new DeploymentRequirementException(
                'Docker Engine and Docker Compose v2 or later are required.',
                'rag_stack_unhealthy'
            );
        }
        if (!in_array('pgsql', $this->pdoDrivers, true)) {
            throw new DeploymentRequirementException(
                'The OpenConcept PHP runtime must provide pdo_pgsql.',
                'driver_missing'
            );
        }
        $dockerInfo = json_decode(trim($this->required(['docker', 'info', '--format', '{{json .}}'], 'docker_engine_unavailable')), true);
        $cpuCount = is_array($dockerInfo) ? (int) ($dockerInfo['NCPU'] ?? 0) : 0;
        $memoryBytes = is_array($dockerInfo) ? (int) ($dockerInfo['MemTotal'] ?? 0) : 0;
        $minimumCpu = max(1, (int) (getenv('OPENCONCEPT_RAG_MIN_CPU') ?: 2));
        $minimumMemoryBytes = max(1, (int) (getenv('OPENCONCEPT_RAG_MIN_MEMORY_MB') ?: 4096)) * 1024 * 1024;
        $minimumDiskBytes = max(1, (int) (getenv('OPENCONCEPT_RAG_MIN_DISK_MB') ?: 10240)) * 1024 * 1024;
        $freeDiskBytes = @disk_free_space($this->root);
        if ($cpuCount < $minimumCpu || $memoryBytes < $minimumMemoryBytes
            || !is_float($freeDiskBytes) && !is_int($freeDiskBytes)
            || (float) $freeDiskBytes < $minimumDiskBytes) {
            throw new DeploymentRequirementException(
                'The Docker host does not meet the Standard RAG CPU, memory, or free-disk requirement.',
                'rag_stack_unhealthy',
                [
                    'cpu_required' => $minimumCpu,
                    'memory_mb_required' => (int) ($minimumMemoryBytes / 1024 / 1024),
                    'disk_mb_required' => (int) ($minimumDiskBytes / 1024 / 1024),
                ]
            );
        }

        $base = ['docker', 'compose', '--project-name', $projectName];
        if ($resolvedEnvironment !== null) {
            array_push($base, '--env-file', $resolvedEnvironment);
        }
        array_push($base, '-f', $resolvedCompose);
        $declaredServices = $this->nonEmptyLines($this->required([...$base, 'config', '--services'], 'rag_stack_unhealthy'));
        foreach ($requiredServices as $service) {
            if (!in_array($service, $declaredServices, true)) {
                throw new DeploymentRequirementException(
                    'The Docker RAG stack is missing a required service.',
                    'rag_stack_unhealthy',
                    ['service' => $service]
                );
            }
        }
        $volumes = $this->nonEmptyLines($this->required([...$base, 'config', '--volumes'], 'rag_stack_unhealthy'));
        if (count($volumes) < ($topology === 'full' ? 3 : 2)) {
            throw new DeploymentRequirementException(
                'The Docker RAG stack must declare persistent application, database, and model volumes.',
                'rag_stack_unhealthy'
            );
        }

        $services = [];
        foreach ($requiredServices as $service) {
            $ids = $this->nonEmptyLines($this->required([...$base, 'ps', '--all', '-q', $service], 'rag_stack_unhealthy'));
            if (count($ids) !== 1 || preg_match('/^[a-f0-9]{12,64}$/D', $ids[0]) !== 1) {
                throw new DeploymentRequirementException(
                    'Each required Docker RAG service must resolve to exactly one container.',
                    'rag_stack_unhealthy',
                    ['service' => $service]
                );
            }
            $template = '{{.State.Running}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{.Config.Image}}|{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}';
            $inspection = trim($this->required(['docker', 'inspect', '--format', $template, $ids[0]], 'rag_stack_unhealthy'));
            $parts = explode('|', $inspection, 5);
            if (count($parts) !== 5) {
                throw new DeploymentRequirementException('Docker service inspection returned an invalid result.', 'rag_stack_unhealthy');
            }
            [$running, $health, $image, $labelProject, $labelService] = $parts;
            $services[$service] = [
                'running' => $running === 'true',
                'health' => $health,
                'image' => trim($image),
                'project' => trim($labelProject),
                'service' => trim($labelService),
            ];
            if ($running !== 'true' || trim($labelProject) !== $projectName || trim($labelService) !== $service) {
                throw new DeploymentRequirementException(
                    'A Docker RAG service is stopped or belongs to another Compose project.',
                    'rag_stack_unhealthy',
                    ['service' => $service]
                );
            }
        }

        if (($services['postgres']['health'] ?? '') !== 'healthy'
            || ($services['rag-embedding']['health'] ?? '') !== 'healthy'
            || ($topology === 'full' && ($services['openconcept-web']['health'] ?? '') !== 'healthy')) {
            throw new DeploymentRequirementException(
                'The Docker RAG application, PostgreSQL, or Embedding service is not healthy.',
                'rag_stack_unhealthy'
            );
        }

        $pgVectorVersion = trim($this->required([
            ...$base,
            'exec', '-T', 'postgres', 'sh', '-c',
            'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT extversion FROM pg_extension WHERE extname = \'vector\'"',
        ], 'pgvector_missing'));
        if ($pgVectorVersion === '' || preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+._A-Za-z0-9]*)?$/D', $pgVectorVersion) !== 1) {
            throw new DeploymentRequirementException('The PostgreSQL vector extension is not installed.', 'pgvector_missing');
        }
        $pgTrgmVersion = trim($this->required([
            ...$base,
            'exec', '-T', 'postgres', 'sh', '-c',
            'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tAc "SELECT extversion FROM pg_extension WHERE extname = \'pg_trgm\'"',
        ], 'pg_trgm_missing'));
        if ($pgTrgmVersion === '' || preg_match('/^[0-9]+(?:\.[0-9]+){0,3}(?:[-+._A-Za-z0-9]*)?$/D', $pgTrgmVersion) !== 1) {
            throw new DeploymentRequirementException('The PostgreSQL pg_trgm extension is not installed.', 'pg_trgm_missing');
        }
        $embeddingJson = trim($this->required([
            ...$base,
            'exec', '-T', 'rag-embedding', 'python', '-c',
            "import urllib.request; print(urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=5).read().decode('utf-8'))",
        ], 'rag_stack_unhealthy'));
        try {
            $embeddingHealth = json_decode($embeddingJson, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new DeploymentRequirementException('The Embedding service health response is invalid.', 'rag_stack_unhealthy');
        }
        if (!is_array($embeddingHealth) || ($embeddingHealth['status'] ?? null) !== 'ready'
            || trim((string) ($embeddingHealth['model'] ?? '')) === ''
            || (int) ($embeddingHealth['dimensions'] ?? 0) < 1) {
            throw new DeploymentRequirementException('The Embedding model is not ready.', 'rag_stack_unhealthy');
        }

        return [
            'docker' => [
                'engine_version' => $engineVersion,
                'cpu_count' => $cpuCount,
                'memory_mb' => (int) floor($memoryBytes / 1024 / 1024),
                'free_disk_mb' => (int) floor((float) $freeDiskBytes / 1024 / 1024),
            ],
            'compose' => ['version' => $composeVersion, 'project' => $projectName, 'topology' => $topology],
            'services' => $services,
            'volumes' => $volumes,
            'health' => [
                'postgresql' => true,
                'pgvector' => true,
                'pgvector_version' => $pgVectorVersion,
                'pg_trgm' => true,
                'pg_trgm_version' => $pgTrgmVersion,
                'embedding' => true,
                'embedding_model' => (string) $embeddingHealth['model'],
                'embedding_revision' => $embeddingHealth['revision'] ?? null,
                'embedding_dimensions' => (int) $embeddingHealth['dimensions'],
            ],
        ];
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function defaultRunner(array $command): array
    {
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, $this->root, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'process_start_failed'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [
            'exit_code' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function required(array $command, string $failureCode): string
    {
        $result = ($this->runner)($command);
        if (($result['exit_code'] ?? 1) !== 0) {
            throw new DeploymentRequirementException(
                'A required Docker RAG stack check failed.',
                $failureCode,
                ['check' => $this->safeCheckName($command)]
            );
        }
        return (string) ($result['stdout'] ?? '');
    }

    /** @return list<string> */
    private function nonEmptyLines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: []), static fn (string $line): bool => $line !== ''));
    }

    private function safeCheckName(array $command): string
    {
        if (($command[0] ?? null) !== 'docker') {
            return 'unknown';
        }
        if (($command[1] ?? null) === 'compose') {
            return 'docker_compose_' . (string) ($command[count($command) - 1] ?? 'command');
        }
        return 'docker_' . (string) ($command[1] ?? 'command');
    }
}
