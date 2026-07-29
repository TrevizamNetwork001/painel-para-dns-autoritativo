#!/usr/bin/env bash

set +e

PROJECT_DIR="${DNS_CENTER_DIR:-/opt/dns-center}"

if ! command -v docker >/dev/null 2>&1; then
    echo "Erro: Docker não está disponível."
    exit 1
fi

if [ ! -d "$PROJECT_DIR" ] || [ ! -f "$PROJECT_DIR/compose.yaml" ]; then
    echo "Erro: diretório do DNS Center inválido: $PROJECT_DIR"
    exit 1
fi

cd "$PROJECT_DIR" || {
    echo "Erro: não foi possível acessar $PROJECT_DIR."
    exit 1
}

docker compose version >/dev/null 2>&1
if [ "$?" -ne 0 ]; then
    echo "Erro: Docker Compose não está disponível."
    exit 1
fi

APP_CONTAINER_ID="$(docker compose ps -q app 2>/dev/null)"
if [ -z "$APP_CONTAINER_ID" ]; then
    echo "Erro: o container app não está em execução."
    exit 1
fi

APP_RUNNING="$(docker inspect -f '{{.State.Running}}' "$APP_CONTAINER_ID" 2>/dev/null)"
if [ "$APP_RUNNING" != "true" ]; then
    echo "Erro: o container app não está em execução."
    exit 1
fi

echo "Iniciando redefinição local segura de senha..."
docker compose exec app php artisan dns-center:reset-password
RESULT=$?

if [ "$RESULT" -ne 0 ]; then
    echo "A redefinição não foi concluída."
fi

exit "$RESULT"
