<?php

declare(strict_types=1);

final class RagStackAttestation
{
    private const SCHEMA_VERSION = 1;
    private const FULL_SERVICES = [
        'openconcept-web',
        'openconcept-worker',
        'postgres',
        'rag-embedding',
    ];
    private const HYBRID_SERVICES = ['postgres', 'rag-embedding'];

    private string $root;
    private string $directory;
    private string $attestationPath;
    private string $keyPath;

    public function __construct(string $applicationRoot)
    {
        $this->root = realpath($applicationRoot) ?: rtrim($applicationRoot, "/\\");
        $this->directory = $this->root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rag-stack';
        $this->attestationPath = $this->directory . DIRECTORY_SEPARATOR . 'runtime.json';
        $this->keyPath = $this->directory . DIRECTORY_SEPARATOR . 'runtime.key';
    }

    /** @param array<string, mixed> $evidence @return array<string, mixed> */
    public function issue(array $evidence): array
    {
        $this->validateEvidence($evidence);
        $this->ensurePrivateDirectory();
        $key = $this->loadOrCreateKey();
        $now = time();
        $ttlDays = filter_var(getenv('OPENCONCEPT_RAG_ATTESTATION_TTL_DAYS') ?: 30, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 365],
        ]);
        if ($ttlDays === false) {
            throw new InvalidArgumentException('OPENCONCEPT_RAG_ATTESTATION_TTL_DAYS must be between 1 and 365.');
        }
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'deployment_profile' => 'rag-docker',
            'installation_id' => hash_hmac('sha256', 'openconcept-rag-stack-installation', $key),
            'application_fingerprint' => $this->currentConfigurationFingerprint(),
            'issued_at' => gmdate(DATE_ATOM, $now),
            'expires_at' => gmdate(DATE_ATOM, $now + ($ttlDays * 86400)),
            'docker' => $evidence['docker'],
            'compose' => $evidence['compose'],
            'services' => $evidence['services'],
            'volumes' => array_values(array_map('strval', $evidence['volumes'])),
            'health' => $evidence['health'],
        ];
        $payload['signature'] = $this->sign($payload, $key);
        $this->atomicWrite(
            $this->attestationPath,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL
        );
        $this->applyRuntimeOwnership($this->attestationPath);
        return $this->status();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        if (!is_file($this->attestationPath) || !is_file($this->keyPath)) {
            return $this->invalid('rag_stack_attestation_required', 'Docker RAG stack setup evidence is not installed.');
        }
        $json = @file_get_contents($this->attestationPath);
        $keyEncoded = trim((string) @file_get_contents($this->keyPath));
        if (!is_string($json) || $json === '' || $keyEncoded === '') {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence is unreadable.');
        }
        $key = base64_decode($keyEncoded, true);
        if (!is_string($key) || strlen($key) !== 32) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup key is invalid.');
        }
        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence is malformed.');
        }
        if (!is_array($payload) || ($payload['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence uses an unsupported schema.');
        }
        $signature = is_string($payload['signature'] ?? null) ? $payload['signature'] : '';
        if ($signature === '' || !hash_equals($this->sign($payload, $key), $signature)) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence failed signature validation.');
        }
        if (!hash_equals(
            hash_hmac('sha256', 'openconcept-rag-stack-installation', $key),
            (string) ($payload['installation_id'] ?? '')
        )) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack evidence belongs to another installation.');
        }
        $expiresAt = strtotime((string) ($payload['expires_at'] ?? ''));
        $issuedAt = strtotime((string) ($payload['issued_at'] ?? ''));
        if ($issuedAt === false || $expiresAt === false || $issuedAt > time() + 300 || $expiresAt <= time()) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence is expired or has invalid timestamps.');
        }
        if (!hash_equals($this->currentConfigurationFingerprint(), (string) ($payload['application_fingerprint'] ?? ''))) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence does not match this application release.');
        }
        try {
            $this->validateEvidence($payload);
        } catch (Throwable) {
            return $this->invalid('rag_stack_attestation_invalid', 'Docker RAG stack setup evidence is incomplete.');
        }

        return [
            'valid' => true,
            'code' => 'ready',
            'message' => 'Docker RAG stack setup evidence is valid.',
            'deployment_profile' => 'rag-docker',
            'issued_at' => $payload['issued_at'],
            'expires_at' => $payload['expires_at'],
            'application_fingerprint' => $payload['application_fingerprint'],
            'docker' => $payload['docker'],
            'compose' => $payload['compose'],
            'services' => $payload['services'],
            'volumes' => $payload['volumes'],
            'health' => $payload['health'],
        ];
    }

    public function currentConfigurationFingerprint(): string
    {
        $paths = [
            'VERSION',
            'compose.rag.yaml',
            'docker/openconcept/Dockerfile',
            'docker/postgres/init/10-openconcept-extensions.sql',
            'docker/rag-embedding/Dockerfile',
            'docker/rag-embedding/requirements.txt',
            'docker/rag-embedding/app.py',
            'plugins/database-postgresql-adapter/plugin.json',
            'app/RagCoreRuntime.php',
            'app/RagCoreController.php',
            'app/DeterministicBlockChunker.php',
            'app/BgeM3EmbeddingProvider.php',
            'app/StandardRagRepository.php',
            'app/ReciprocalRankFusion.php',
            'app/StandardRagRetriever.php',
        ];
        $inventory = [];
        foreach ($paths as $relative) {
            $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $inventory[$relative] = is_file($path) ? hash_file('sha256', $path) : 'missing';
        }
        return hash('sha256', json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidence(array $evidence): void
    {
        $docker = is_array($evidence['docker'] ?? null) ? $evidence['docker'] : [];
        $compose = is_array($evidence['compose'] ?? null) ? $evidence['compose'] : [];
        $services = is_array($evidence['services'] ?? null) ? $evidence['services'] : [];
        $volumes = is_array($evidence['volumes'] ?? null) ? $evidence['volumes'] : [];
        $health = is_array($evidence['health'] ?? null) ? $evidence['health'] : [];
        $project = (string) ($compose['project'] ?? '');
        $expectedProject = trim((string) getenv('OPENCONCEPT_RAG_COMPOSE_PROJECT')) ?: 'openconcept-v2-3-rag';
        if (trim((string) ($docker['engine_version'] ?? '')) === ''
            || trim((string) ($compose['version'] ?? '')) === ''
            || $project !== $expectedProject
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/D', $project) !== 1) {
            throw new InvalidArgumentException('Docker and Compose evidence is invalid.');
        }
        $topology = (string) ($compose['topology'] ?? '');
        $requiredServices = match ($topology) {
            'full' => self::FULL_SERVICES,
            'hybrid' => self::HYBRID_SERVICES,
            default => throw new InvalidArgumentException('Docker RAG stack topology is invalid.'),
        };
        foreach ($requiredServices as $service) {
            $state = is_array($services[$service] ?? null) ? $services[$service] : [];
            if (($state['running'] ?? false) !== true
                || (string) ($state['project'] ?? '') !== $project
                || (string) ($state['service'] ?? '') !== $service
                || trim((string) ($state['image'] ?? '')) === '') {
                throw new InvalidArgumentException('Required Docker RAG service evidence is invalid.');
            }
        }
        $minimumVolumes = $topology === 'full' ? 3 : 2;
        if (count($volumes) < $minimumVolumes || ($health['postgresql'] ?? false) !== true
            || ($health['pgvector'] ?? false) !== true || ($health['pg_trgm'] ?? false) !== true
            || ($health['embedding'] ?? false) !== true) {
            throw new InvalidArgumentException('Docker RAG stack health or volume evidence is incomplete.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload, string $key): string
    {
        unset($payload['signature']);
        return base64_encode(hash_hmac('sha256', $this->canonicalJson($payload), $key, true));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function ensurePrivateDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('RAG stack state directory could not be created.');
        }
        @chmod($this->directory, 0700);
    }

    private function loadOrCreateKey(): string
    {
        if (is_file($this->keyPath)) {
            $decoded = base64_decode(trim((string) file_get_contents($this->keyPath)), true);
            if (!is_string($decoded) || strlen($decoded) !== 32) {
                throw new RuntimeException('RAG stack attestation key is invalid.');
            }
            return $decoded;
        }
        $key = random_bytes(32);
        $this->atomicWrite($this->keyPath, base64_encode($key) . PHP_EOL);
        @chmod($this->keyPath, 0600);
        $this->applyRuntimeOwnership($this->keyPath);
        return $key;
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('RAG stack state could not be written.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('RAG stack state could not be installed atomically.');
        }
    }

    private function applyRuntimeOwnership(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        $uid = filter_var(getenv('OPENCONCEPT_RUNTIME_UID') ?: 33, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $gid = filter_var(getenv('OPENCONCEPT_RUNTIME_GID') ?: 33, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($uid === false || $gid === false) {
            throw new RuntimeException('OpenConcept runtime UID or GID is invalid.');
        }
        @chown($this->directory, $uid);
        @chgrp($this->directory, $gid);
        @chown($path, $uid);
        @chgrp($path, $gid);
    }

    /** @return array<string, mixed> */
    private function invalid(string $code, string $message): array
    {
        return [
            'valid' => false,
            'code' => $code,
            'message' => $message,
            'deployment_profile' => 'rag-docker',
        ];
    }
}
