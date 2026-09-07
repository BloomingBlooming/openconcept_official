<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/app/Environment.php';
Environment::loadProject($root);
require_once $root . '/app/DeploymentRequirementException.php';
require_once $root . '/app/DeploymentRuntimeInspector.php';
require_once $root . '/app/RagStackAttestation.php';
require_once $root . '/app/RagDockerRequirement.php';
require_once $root . '/app/RagStackDockerDoctor.php';

$options = getopt('', ['record', 'attestation-status', 'project-name:', 'compose-file:', 'environment-file:', 'topology:']);
$requirement = new RagDockerRequirement($root);

try {
    if (array_key_exists('attestation-status', $options)) {
        $result = $requirement->status();
    } else {
        $composeFile = isset($options['compose-file']) ? (string) $options['compose-file'] : null;
        $environmentFile = isset($options['environment-file']) ? (string) $options['environment-file'] : null;
        $projectName = isset($options['project-name']) ? (string) $options['project-name'] : null;
        $topology = isset($options['topology']) ? (string) $options['topology'] : 'full';
        $evidence = (new RagStackDockerDoctor($root))->inspect($composeFile, $projectName, $topology, $environmentFile);
        $result = array_key_exists('record', $options)
            ? $requirement->attestation()->issue($evidence)
            : ['valid' => true, 'code' => 'doctor_passed', 'evidence' => $evidence];
    }
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(($result['valid'] ?? false) === true || ($result['ready'] ?? false) === true ? 0 : 1);
} catch (DeploymentRequirementException $exception) {
    fwrite(STDERR, json_encode([
        'valid' => false,
        'code' => $exception->failureCode(),
        'message' => $exception->getMessage(),
        'details' => $exception->details(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'valid' => false,
        'code' => 'rag_stack_doctor_failed',
        'message' => 'The Docker RAG stack doctor could not complete.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit(1);
}
