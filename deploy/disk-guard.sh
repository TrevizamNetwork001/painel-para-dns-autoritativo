#!/usr/bin/env bash
# Evita que o disco encha de novo com imagens/cache Docker acumulados
# (foi exatamente o que bloqueou o build da 1.3.52 em 28/09/2026) e avisa
# cedo se o uso continuar subindo por outro motivo.
#
# Instalar via deploy/cron.d/dns-center-disk-guard. Logs em
# /var/log/dns-center-disk-guard.log.

set -euo pipefail

WARN_THRESHOLD="${DNS_CENTER_DISK_WARN_PERCENT:-80}"
MOUNT="${DNS_CENTER_DISK_MOUNT:-/}"

log() {
    printf '%s %s\n' "$(date --iso-8601=seconds)" "$*"
}

case "${WARN_THRESHOLD}" in
    ''|*[!0-9]*) echo "ERRO: DNS_CENTER_DISK_WARN_PERCENT deve ser um número" >&2; exit 1 ;;
esac

if command -v docker >/dev/null 2>&1; then
    # Dangling (sem tag, sobra de build) — sempre seguro, nunca atinge
    # imagem em uso por container ativo.
    docker image prune -f >/dev/null 2>&1 || true

    # Imagens sem tag referenciada por nenhum container, com mais de 7 dias —
    # mantém as últimas releases recentes disponíveis para rollback rápido.
    docker image prune -a -f --filter 'until=168h' >/dev/null 2>&1 || true

    # Cache de build com mais de 7 dias.
    docker builder prune -f --filter 'until=168h' >/dev/null 2>&1 || true
fi

read -r used_percent < <(df -P "${MOUNT}" | awk 'NR==2 { gsub("%", "", $5); print $5 }')
free_human="$(df -h -P "${MOUNT}" | awk 'NR==2 { print $4 }')"

log "disco ${MOUNT}: ${used_percent}% em uso, ${free_human} livre"

if [ "${used_percent}" -ge "${WARN_THRESHOLD}" ]; then
    log "AVISO: disco ${MOUNT} em ${used_percent}% (limite ${WARN_THRESHOLD}%) — revise manualmente; a limpeza automática de imagens/cache já rodou acima."
fi
