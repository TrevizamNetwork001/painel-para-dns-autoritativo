#!/usr/bin/env bash

set +e

PROJECT_DIR="${DNS_CENTER_DIR:-/opt/dns-center}"

if ! command -v docker >/dev/null 2>&1; then
    echo "Erro: Docker não está disponível."
    exit 1
fi

cd "$PROJECT_DIR" 2>/dev/null || {
    echo "Erro: diretório do DNS Center inválido: $PROJECT_DIR"
    exit 1
}

docker compose exec -T \
    -e APP_ENV=testing \
    -e DB_CONNECTION=pgsql \
    -e DB_DATABASE=dns_center_testing \
    app php artisan dns-center:check-testing-database

exit $?
