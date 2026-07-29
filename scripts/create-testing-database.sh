#!/usr/bin/env bash

set +e

PROJECT_DIR="${DNS_CENTER_DIR:-/opt/dns-center}"
TEST_DATABASE="dns_center_testing"

if ! command -v docker >/dev/null 2>&1; then
    echo "Erro: Docker não está disponível."
    exit 1
fi

cd "$PROJECT_DIR" 2>/dev/null || {
    echo "Erro: diretório do DNS Center inválido: $PROJECT_DIR"
    exit 1
}

docker compose version >/dev/null 2>&1
if [ "$?" -ne 0 ]; then
    echo "Erro: Docker Compose não está disponível."
    exit 1
fi

POSTGRES_CONTAINER_ID="$(docker compose ps -q postgres 2>/dev/null)"
if [ -z "$POSTGRES_CONTAINER_ID" ]; then
    echo "Erro: o container postgres não está em execução."
    exit 1
fi

EXISTS="$(docker compose exec -T postgres sh -c \
    'psql -U "$POSTGRES_USER" -d postgres -tAc \
    "SELECT 1 FROM pg_database WHERE datname = '\''dns_center_testing'\''"' \
    2>/dev/null)"

if [ "$?" -ne 0 ]; then
    echo "Erro: não foi possível consultar o PostgreSQL."
    exit 1
fi

if [ "$(printf '%s' "$EXISTS" | tr -d '[:space:]')" = "1" ]; then
    echo "Banco $TEST_DATABASE já existe. Nenhuma alteração necessária."
    exit 0
fi

docker compose exec -T postgres sh -c \
    'createdb -U "$POSTGRES_USER" -O "$POSTGRES_USER" dns_center_testing'
RESULT=$?

if [ "$RESULT" -ne 0 ]; then
    echo "Erro: não foi possível criar o banco $TEST_DATABASE."
    exit "$RESULT"
fi

echo "Banco $TEST_DATABASE criado com segurança para o usuário da aplicação."
exit 0
