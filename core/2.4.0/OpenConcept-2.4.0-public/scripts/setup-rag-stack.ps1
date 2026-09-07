[CmdletBinding()]
param(
    [ValidateSet('full', 'hybrid')]
    [string] $Topology = 'full',
    [ValidateRange(60, 7200)]
    [int] $WaitTimeoutSeconds = 1800
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$compose = Join-Path $root 'compose.rag.yaml'
$environmentFile = Join-Path $root '.env.rag'

function New-RagSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Initialize-RagEnvironment {
    param(
        [Parameter(Mandatory = $true)]
        [string] $Path,
        [Parameter(Mandatory = $true)]
        [string] $SelectedTopology
    )

    $applicationUrl = if ($SelectedTopology -eq 'hybrid') { 'http://127.0.0.1:8080' } else { '' }
    $lines = @(
        '# Generated locally by setup-rag-stack.ps1. Never upload or commit this file.',
        'OPENCONCEPT_RAG_COMPOSE_PROJECT=openconcept-v2-3-rag',
        'OPENCONCEPT_HTTP_PORT=8080',
        'OPENCONCEPT_POSTGRES_PORT=5433',
        'OPENCONCEPT_EMBEDDING_PORT=8001',
        "OPENCONCEPT_APP_URL=$applicationUrl",
        '',
        'POSTGRES_DB=openconcept',
        'POSTGRES_USER=openconcept',
        "POSTGRES_PASSWORD=$(New-RagSecret)",
        '',
        "OPENCONCEPT_BGE_M3_API_KEY=$(New-RagSecret)",
        'OPENCONCEPT_BGE_M3_MODEL=BAAI/bge-m3',
        'OPENCONCEPT_BGE_M3_REVISION=',
        'OPENCONCEPT_BGE_M3_DIMENSIONS=1024',
        'OPENCONCEPT_BGE_M3_DEVICE=cpu',
        'OPENCONCEPT_BGE_M3_BATCH_SIZE=16',
        'OPENCONCEPT_RAG_WORKER_INTERVAL=15',
        '',
        '# Optional existing manually managed MySQL source for MySQL -> PostgreSQL.',
        'OPENCONCEPT_DSN=',
        'OPENCONCEPT_DB_USER=',
        'OPENCONCEPT_DB_PASSWORD=',
        'OPENCONCEPT_TABLE_PREFIX=openconcept_'
    )
    $utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
    $content = [string]::Join([Environment]::NewLine, $lines) + [Environment]::NewLine
    $contentBytes = $utf8WithoutBom.GetBytes($content)
    $stream = [System.IO.File]::Open(
        $Path,
        [System.IO.FileMode]::CreateNew,
        [System.IO.FileAccess]::Write,
        [System.IO.FileShare]::None
    )
    try {
        $stream.Write($contentBytes, 0, $contentBytes.Length)
        $stream.Flush()
    } finally {
        $stream.Dispose()
    }
    if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
        & chmod 600 -- $Path
        if ($LASTEXITCODE -ne 0) {
            throw 'Could not restrict .env.rag permissions to the current operating-system user.'
        }
    }
}

if (-not (Test-Path -LiteralPath $environmentFile -PathType Leaf)) {
    Initialize-RagEnvironment -Path $environmentFile -SelectedTopology $Topology
    Write-Host 'Generated a private .env.rag with unique PostgreSQL and embedding secrets.'
    Write-Host 'Review its non-secret ports and URL. For full topology, set OPENCONCEPT_APP_URL, then run this command again.'
    return
}
if ($null -eq (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Docker CLI is required.'
}

$projectName = 'openconcept-v2-3-rag'
$ragEnvironment = @{}
foreach ($line in Get-Content -LiteralPath $environmentFile -Encoding utf8) {
    if ($line -match '^([A-Z][A-Z0-9_]*)=(.*)$') {
        $ragEnvironment[$Matches[1]] = $Matches[2]
    }
}
if (([string] $ragEnvironment['OPENCONCEPT_RAG_COMPOSE_PROJECT']) -match '^[a-z0-9][a-z0-9_-]{0,62}$') {
    $projectName = [string] $ragEnvironment['OPENCONCEPT_RAG_COMPOSE_PROJECT']
}
$postgresPassword = [string] $ragEnvironment['POSTGRES_PASSWORD']
$embeddingSecret = [string] $ragEnvironment['OPENCONCEPT_BGE_M3_API_KEY']
$invalidSecrets = $postgresPassword.Length -lt 32 -or $embeddingSecret.Length -lt 32 -or $postgresPassword -eq $embeddingSecret -or $postgresPassword -like 'replace-with-*' -or $embeddingSecret -like 'replace-with-*'
if ($invalidSecrets) {
    throw '.env.rag must contain different PostgreSQL and embedding secrets of at least 32 characters. Distributed placeholder values are rejected.'
}
if ($Topology -eq 'full' -and [string]::IsNullOrWhiteSpace(([string] $ragEnvironment['OPENCONCEPT_APP_URL']))) {
    throw 'Set OPENCONCEPT_APP_URL in .env.rag before starting the full topology.'
}

& docker version | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Engine is unavailable.'
}
& docker compose version | Out-Null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Compose v2 is unavailable.'
}

$arguments = @('--project-name', $projectName, '--env-file', $environmentFile, '-f', $compose)
& docker compose @arguments config --quiet
if ($LASTEXITCODE -ne 0) {
    throw 'compose.rag.yaml or .env.rag is invalid.'
}

if ($Topology -eq 'full') {
    & docker compose @arguments up --detach --build --force-recreate --wait --wait-timeout $WaitTimeoutSeconds postgres rag-embedding openconcept-web openconcept-worker
} else {
    & docker compose @arguments up --detach --build --force-recreate --wait --wait-timeout $WaitTimeoutSeconds postgres rag-embedding
}
if ($LASTEXITCODE -ne 0) {
    throw 'Docker RAG services could not be started.'
}

# docker-entrypoint-initdb.d runs only for a new PostgreSQL data directory.
# Re-run the distributed, idempotent extension script so existing volumes are
# brought to the same pgvector + pg_trgm Standard RAG-ready state as new ones.
$postgresUser = [string] $ragEnvironment['POSTGRES_USER']
$postgresDatabase = [string] $ragEnvironment['POSTGRES_DB']
& docker compose @arguments exec -T postgres psql -v ON_ERROR_STOP=1 -U $postgresUser -d $postgresDatabase -f '/docker-entrypoint-initdb.d/10-openconcept-extensions.sql'
if ($LASTEXITCODE -ne 0) {
    throw 'The Docker PostgreSQL service could not enable the distributed PostgreSQL extensions.'
}

if ($Topology -eq 'full') {
    & docker compose @arguments --profile tools run --rm rag-stack-doctor --record --topology=full
} else {
    if ($null -eq (Get-Command php -ErrorAction SilentlyContinue)) {
        throw 'Hybrid topology requires the same Host Native PHP CLI used by OpenConcept.'
    }
    $priorProfile = [Environment]::GetEnvironmentVariable('OPENCONCEPT_DEPLOYMENT_PROFILE', 'Process')
    $priorProject = [Environment]::GetEnvironmentVariable('OPENCONCEPT_RAG_COMPOSE_PROJECT', 'Process')
    try {
        [Environment]::SetEnvironmentVariable('OPENCONCEPT_DEPLOYMENT_PROFILE', 'rag-docker', 'Process')
        [Environment]::SetEnvironmentVariable('OPENCONCEPT_RAG_COMPOSE_PROJECT', $projectName, 'Process')
        & php (Join-Path $root 'scripts/rag-stack-doctor.php') --record --topology=hybrid "--project-name=$projectName" "--environment-file=$environmentFile"
    } finally {
        [Environment]::SetEnvironmentVariable('OPENCONCEPT_DEPLOYMENT_PROFILE', $priorProfile, 'Process')
        [Environment]::SetEnvironmentVariable('OPENCONCEPT_RAG_COMPOSE_PROJECT', $priorProject, 'Process')
    }
}
if ($LASTEXITCODE -ne 0) {
    throw 'Docker RAG stack doctor failed. The plugins remain unavailable.'
}

Write-Host "OpenConcept Docker RAG stack is ready ($Topology topology)."
