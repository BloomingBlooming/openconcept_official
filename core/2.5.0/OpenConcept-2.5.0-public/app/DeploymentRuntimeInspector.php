<?php

declare(strict_types=1);

final class DeploymentRuntimeInspector
{
    public const PROFILES = ['web-hosting', 'rag-docker', 'development'];

    public function __construct(private readonly string $applicationRoot)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(?string $canonicalBackend = null): array
    {
        $declared = strtolower(trim((string) getenv('OPENCONCEPT_DEPLOYMENT_PROFILE')));
        $warnings = [];
        if ($declared === '') {
            $declared = 'web-hosting';
            $warnings[] = 'deployment_profile_defaulted';
        } elseif (!in_array($declared, self::PROFILES, true)) {
            $warnings[] = 'deployment_profile_invalid';
        }

        $signals = [];
        if ($this->truthyEnvironment('OPENCONCEPT_CONTAINER')) {
            $signals[] = 'environment';
        }
        if (DIRECTORY_SEPARATOR === '/' && is_file('/.dockerenv')) {
            $signals[] = 'dockerenv';
        }
        if (DIRECTORY_SEPARATOR === '/') {
            foreach (['/proc/1/cgroup', '/proc/self/cgroup'] as $path) {
                $content = @file_get_contents($path);
                if (is_string($content) && preg_match('/(?:docker|containerd|kubepods|podman)/i', $content) === 1) {
                    $signals[] = 'cgroup';
                    break;
                }
            }
        }
        if (getenv('CONTAINER_SANDBOX_MOUNT_POINT') !== false) {
            $signals[] = 'windows_container_environment';
        }
        $signals = array_values(array_unique($signals));
        $container = $signals !== [];

        if ($declared === 'web-hosting' && $container) {
            $warnings[] = 'container_runtime_with_web_hosting_profile';
        }
        if ($declared === 'rag-docker' && !$container) {
            // Host-native PHP connected to the Docker RAG stack is supported,
            // but the distinction must stay visible to administrators.
            $warnings[] = 'host_native_runtime_with_rag_docker_profile';
        }

        $root = realpath($this->applicationRoot) ?: rtrim($this->applicationRoot, "/\\");
        return [
            'deployment_profile' => $declared,
            'deployment_profile_valid' => in_array($declared, self::PROFILES, true),
            'application_release' => trim((string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'VERSION')),
            'runtime' => $container ? 'container' : 'host-native',
            'container_detected' => $container,
            'container_signals' => $signals,
            'os_family' => PHP_OS_FAMILY,
            'architecture' => php_uname('m'),
            'php_sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'pdo_drivers' => PDO::getAvailableDrivers(),
            'canonical_backend' => $canonicalBackend,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function truthyEnvironment(string $name): bool
    {
        return in_array(strtolower(trim((string) getenv($name))), ['1', 'true', 'yes', 'on'], true);
    }
}
