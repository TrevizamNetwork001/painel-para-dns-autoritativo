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

STATE_DIR="${DNS_CENTER_STATE_DIR:-/var/lib/dns-center-deploy}"
KEEP_RECENT_VERSIONS="${DNS_CENTER_KEEP_IMAGE_VERSIONS:-3}"

case "${KEEP_RECENT_VERSIONS}" in
    ''|*[!0-9]*) echo "ERRO: DNS_CENTER_KEEP_IMAGE_VERSIONS deve ser um número" >&2; exit 1 ;;
esac

prune_old_release_images() {
    # NÃO usa "docker image prune -a --filter until=": esse filtro olha a data de
    # CRIAÇÃO da imagem, então a imagem da versão anterior (alvo do
    # `dns-center-deploy rollback`) seria apagada depois de 7 dias, mesmo sendo a
    # única que importa para reverter. Aqui só se removem tags de release antigas
    # que NÃO sejam a versão atual, a anterior nem as N mais recentes.
    local protected repo tag
    protected="$(cat "${STATE_DIR}/current-version" "${STATE_DIR}/previous-version" 2>/dev/null || true)"

    for repo in dns-center-app dns-center-web; do
        # versões em ordem crescente; poupa as KEEP mais recentes
        docker images --format '{{.Tag}}' "${repo}" 2>/dev/null \
            | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V | head -n "-${KEEP_RECENT_VERSIONS}" \
            | while IFS= read -r tag; do
                if printf '%s\n' "${protected}" | grep -qx "${tag}"; then
                    continue
                fi
                # rmi (sem -f) recusa imagem em uso por container.
                docker rmi "${repo}:${tag}" >/dev/null 2>&1 \
                    && log "imagem antiga removida: ${repo}:${tag}" || true
            done || true
    done
}

if command -v docker >/dev/null 2>&1; then
    # Dangling (sem tag, sobra de build) — sempre seguro.
    docker image prune -f >/dev/null 2>&1 || true

    prune_old_release_images

    # Cache de build com mais de 7 dias (não afeta rollback).
    docker builder prune -f --filter 'until=168h' >/dev/null 2>&1 || true
fi

read -r used_percent < <(df -P "${MOUNT}" | awk 'NR==2 { gsub("%", "", $5); print $5 }')
free_human="$(df -h -P "${MOUNT}" | awk 'NR==2 { print $4 }')"

log "disco ${MOUNT}: ${used_percent}% em uso, ${free_human} livre"

if [ "${used_percent}" -ge "${WARN_THRESHOLD}" ]; then
    log "AVISO: disco ${MOUNT} em ${used_percent}% (limite ${WARN_THRESHOLD}%) — revise manualmente; a limpeza automática de imagens/cache já rodou acima."
fi
