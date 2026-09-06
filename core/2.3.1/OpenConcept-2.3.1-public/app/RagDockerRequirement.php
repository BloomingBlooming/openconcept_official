<?php

declare(strict_types=1);

final class RagDockerRequirement
{
    private DeploymentRuntimeInspector $runtimeInspector;
    private RagStackAttestation $attestation;

    public function __construct(private readonly string $applicationRoot)
    {
        $this->runtimeInspector = new DeploymentRuntimeInspector($applicationRoot);
        $this->attestation = new RagStackAttestation($applicationRoot);
    }

    /** @return array<string, mixed> */
    public function status(?string $canonicalBackend = null): array
    {
        $runtime = $this->runtimeInspector->inspect($canonicalBackend);
        $attestation = $this->attestation->status();
        $profileReady = ($runtime['deployment_profile_valid'] ?? false) === true
            && ($runtime['deployment_profile'] ?? null) === 'rag-docker';
        $ready = $profileReady && ($attestation['valid'] ?? false) === true;
        $code = $ready
            ? 'ready'
            : (!$profileReady ? 'rag_stack_attestation_required' : (string) ($attestation['code'] ?? 'rag_stack_attestation_invalid'));
        return [
            'ready' => $ready,
            'code' => $code,
            'runtime' => $runtime,
            'attestation' => $attestation,
        ];
    }

    public function assertReady(string $operation, ?string $canonicalBackend = null): void
    {
        $status = $this->status($canonicalBackend);
        if (($status['ready'] ?? false) === true) {
            return;
        }
        throw new DeploymentRequirementException(
            'The Docker RAG deployment profile must pass the administrator CLI doctor before ' . $operation . '.',
            (string) ($status['code'] ?? 'rag_stack_attestation_required'),
            [
                'operation' => $operation,
                'deployment_profile' => $status['runtime']['deployment_profile'] ?? null,
                'attestation_code' => $status['attestation']['code'] ?? null,
            ]
        );
    }

    public function attestation(): RagStackAttestation
    {
        return $this->attestation;
    }
}
