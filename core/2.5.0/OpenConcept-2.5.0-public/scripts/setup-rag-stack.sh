#!/bin/sh
set -eu

topology="${1:-full}"
if [ "$topology" != "full" ] && [ "$topology" != "hybrid" ]; then
    echo 'Usage: scripts/setup-rag-stack.sh [full|hybrid]' >&2
    exit 2
fi

root="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
compose="$root/compose.rag.yaml"
environment_file="$root/.env.rag"
wait_timeout="${OPENCONCEPT_RAG_SETUP_TIMEOUT:-1800}"

generate_rag_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
    elif command -v php >/dev/null 2>&1; then
        php -r 'echo bin2hex(random_bytes(32));'
    elif command -v od >/dev/null 2>&1 && [ -r /dev/urandom ]; then
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
    else
        echo 'OpenSSL, PHP, or /dev/urandom with od is required to generate .env.rag securely.' >&2
        return 1
    fi
}

if [ ! -f "$environment_file" ]; then
    postgres_password="$(generate_rag_secret)"
    embedding_secret="$(generate_rag_secret)"
    [ "${#postgres_password}" -eq 64 ] && [ "${#embedding_secret}" -eq 64 ] || {
        echo 'Secure .env.rag secret generation failed.' >&2
        exit 1
    }
    if [ "$topology" = 'hybrid' ]; then
        application_url='http://127.0.0.1:8080'
    else
        application_url=''
    fi
    umask 077
    if ! (set -C; {
        printf '%s\n' '# Generated locally by setup-rag-stack.sh. Never upload or commit this file.'
        printf '%s\n' 'OPENCONCEPT_RAG_COMPOSE_PROJECT=openconcept-v2-3-rag'
        printf '%s\n' 'OPENCONCEPT_HTTP_PORT=8080'
        printf '%s\n' 'OPENCONCEPT_POSTGRES_PORT=5433'
        printf '%s\n' 'OPENCONCEPT_EMBEDDING_PORT=8001'
        printf 'OPENCONCEPT_APP_URL=%s\n\n' "$application_url"
        printf '%s\n' 'POSTGRES_DB=openconcept'
        printf '%s\n' 'POSTGRES_USER=openconcept'
        printf 'POSTGRES_PASSWORD=%s\n\n' "$postgres_password"
        printf 'OPENCONCEPT_BGE_M3_API_KEY=%s\n' "$embedding_secret"
        printf '%s\n' 'OPENCONCEPT_BGE_M3_MODEL=BAAI/bge-m3'
        printf '%s\n' 'OPENCONCEPT_BGE_M3_REVISION='
        printf '%s\n' 'OPENCONCEPT_BGE_M3_DIMENSIONS=1024'
        printf '%s\n' 'OPENCONCEPT_BGE_M3_DEVICE=cpu'
        printf '%s\n' 'OPENCONCEPT_BGE_M3_BATCH_SIZE=16'
        printf '%s\n\n' 'OPENCONCEPT_RAG_WORKER_INTERVAL=15'
        printf '%s\n' '# Optional existing manually managed MySQL source for MySQL -> PostgreSQL.'
        printf '%s\n' 'OPENCONCEPT_DSN='
        printf '%s\n' 'OPENCONCEPT_DB_USER='
        printf '%s\n' 'OPENCONCEPT_DB_PASSWORD='
        printf '%s\n' 'OPENCONCEPT_TABLE_PREFIX=openconcept_'
    } > "$environment_file"); then
        echo 'Could not create .env.rag without overwriting an existing file.' >&2
        exit 1
    fi
    chmod 600 "$environment_file"
    echo 'Generated a private .env.rag with unique PostgreSQL and embedding secrets.'
    echo 'Review its non-secret ports and URL. For full topology, set OPENCONCEPT_APP_URL, then run this command again.'
    exit 0
fi
command -v docker >/dev/null 2>&1 || { echo 'Docker CLI is required.' >&2; exit 1; }
case "$wait_timeout" in
    ''|*[!0-9]*) echo 'OPENCONCEPT_RAG_SETUP_TIMEOUT must be an integer from 60 to 7200 seconds.' >&2; exit 1 ;;
esac
if [ "$wait_timeout" -lt 60 ] || [ "$wait_timeout" -gt 7200 ]; then
    echo 'OPENCONCEPT_RAG_SETUP_TIMEOUT must be an integer from 60 to 7200 seconds.' >&2
    exit 1
fi
project_name="$(sed -n 's/^OPENCONCEPT_RAG_COMPOSE_PROJECT=\([a-z0-9][a-z0-9_-]*\)$/\1/p' "$environment_file" | head -n 1)"
project_name="${project_name:-openconcept-v2-3-rag}"
postgres_password="$(sed -n 's/^POSTGRES_PASSWORD=//p' "$environment_file" | head -n 1)"
embedding_secret="$(sed -n 's/^OPENCONCEPT_BGE_M3_API_KEY=//p' "$environment_file" | head -n 1)"
case "$postgres_password:$embedding_secret" in
    *replace-with-*) echo '.env.rag contains a distributed placeholder secret, which is rejected.' >&2; exit 1 ;;
esac
if [ "${#postgres_password}" -lt 32 ] || [ "${#embedding_secret}" -lt 32 ] || [ "$postgres_password" = "$embedding_secret" ]; then
    echo '.env.rag must contain different PostgreSQL and embedding secrets of at least 32 characters.' >&2
    exit 1
fi
if [ "$topology" = 'full' ]; then
    application_url="$(sed -n 's/^OPENCONCEPT_APP_URL=//p' "$environment_file" | head -n 1)"
    [ -n "$application_url" ] || { echo 'Set OPENCONCEPT_APP_URL in .env.rag before starting the full topology.' >&2; exit 1; }
fi
docker version >/dev/null
docker compose version >/dev/null
docker compose --project-name "$project_name" --env-file "$environment_file" -f "$compose" config --quiet

if [ "$topology" = 'full' ]; then
    docker compose --project-name "$project_name" --env-file "$environment_file" -f "$compose" up --detach --build \
        --force-recreate --wait --wait-timeout "$wait_timeout" \
        postgres rag-embedding openconcept-web openconcept-worker
else
    docker compose --project-name "$project_name" --env-file "$environment_file" -f "$compose" up --detach --build \
        --force-recreate --wait --wait-timeout "$wait_timeout" \
        postgres rag-embedding
fi

# Init scripts run only for a fresh data directory. Re-run the distributed,
# idempotent extension script so existing volumes receive pgvector and pg_trgm.
docker compose --project-name "$project_name" --env-file "$environment_file" -f "$compose" exec -T postgres sh -c \
    'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -f /docker-entrypoint-initdb.d/10-openconcept-extensions.sql'

if [ "$topology" = 'full' ]; then
    docker compose --project-name "$project_name" --env-file "$environment_file" -f "$compose" --profile tools run --rm \
        rag-stack-doctor --record --topology=full
else
    command -v php >/dev/null 2>&1 || {
        echo 'Hybrid topology requires the same Host Native PHP CLI used by OpenConcept.' >&2
        exit 1
    }
    OPENCONCEPT_DEPLOYMENT_PROFILE=rag-docker OPENCONCEPT_RAG_COMPOSE_PROJECT="$project_name" \
        php "$root/scripts/rag-stack-doctor.php" --record --topology=hybrid \
        "--project-name=$project_name" "--environment-file=$environment_file"
fi
echo "OpenConcept Docker RAG stack is ready ($topology topology)."
