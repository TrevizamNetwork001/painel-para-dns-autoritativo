#!/usr/bin/env bash
# Protege o host contra crescimento silencioso do uso de disco. Não depende de
# Laravel, PostgreSQL ou Redis. A limpeza conservadora existente é mantida,
# mas nunca é iniciada quando o filesystem já está CRITICAL/EMERGENCY.

set -euo pipefail

WARN_THRESHOLD="${DNS_CENTER_DISK_WARN_PERCENT:-80}"
CRITICAL_THRESHOLD="${DNS_CENTER_DISK_CRITICAL_PERCENT:-90}"
EMERGENCY_THRESHOLD="${DNS_CENTER_DISK_EMERGENCY_PERCENT:-95}"
MOUNT="${DNS_CENTER_DISK_MOUNT:-/}"
STATE_DIR="${DNS_CENTER_STATE_DIR:-/var/lib/dns-center-deploy}"
KEEP_RECENT_VERSIONS="${DNS_CENTER_KEEP_IMAGE_VERSIONS:-3}"
WEBHOOK_URL="${DNS_CENTER_DISK_WEBHOOK_URL:-}"
WEBHOOK_TIMEOUT="${DNS_CENTER_DISK_WEBHOOK_TIMEOUT_SECONDS:-5}"
DOCKER_COMMAND="${DNS_CENTER_DOCKER_COMMAND:-docker}"
STATE_FILE="${STATE_DIR}/disk-guard.state"

log() {
    printf '%s %s\n' "$(date --iso-8601=seconds)" "$*"
}

fail() {
    printf 'ERRO: %s\n' "$*" >&2
    exit 1
}

require_integer() {
    local name="$1" value="$2"
    case "${value}" in
        ''|*[!0-9]*) fail "${name} deve ser um número" ;;
    esac
}

require_integer DNS_CENTER_DISK_WARN_PERCENT "${WARN_THRESHOLD}"
require_integer DNS_CENTER_DISK_CRITICAL_PERCENT "${CRITICAL_THRESHOLD}"
require_integer DNS_CENTER_DISK_EMERGENCY_PERCENT "${EMERGENCY_THRESHOLD}"
require_integer DNS_CENTER_KEEP_IMAGE_VERSIONS "${KEEP_RECENT_VERSIONS}"
require_integer DNS_CENTER_DISK_WEBHOOK_TIMEOUT_SECONDS "${WEBHOOK_TIMEOUT}"

[ "${WARN_THRESHOLD}" -lt "${CRITICAL_THRESHOLD}" ] \
    && [ "${CRITICAL_THRESHOLD}" -lt "${EMERGENCY_THRESHOLD}" ] \
    && [ "${EMERGENCY_THRESHOLD}" -le 100 ] \
    || fail "limites devem obedecer WARNING < CRITICAL < EMERGENCY <= 100"

install -d -m 0700 "${STATE_DIR}"

# Impede duas instâncias e evita limpeza concorrente com deploy/backup.
if command -v flock >/dev/null 2>&1; then
    exec 8>"${STATE_DIR}/disk-guard.lock"
    flock -n 8 || { log "SKIP filesystem=${MOUNT} motivo=disk-guard-concorrente"; exit 0; }
    exec 9>"${STATE_DIR}/deploy.lock"
    flock -n 9 || { log "SKIP filesystem=${MOUNT} motivo=deploy-em-andamento"; exit 0; }
fi

read_usage() {
    local line
    line="$(df -P "${MOUNT}" 2>/dev/null | awk 'NR==2 { print $5, $4 }')" \
        || fail "não foi possível ler o filesystem ${MOUNT}"
    read -r used_percent free_blocks <<< "${line}"
    used_percent="${used_percent%%%}"
    case "${used_percent}:${free_blocks}" in
        *[!0-9:]*|:*) fail "resposta inválida de df para ${MOUNT}" ;;
    esac
    free_human="$(df -h -P "${MOUNT}" 2>/dev/null | awk 'NR==2 { print $4}')" \
        || fail "não foi possível ler o espaço livre de ${MOUNT}"
}

severity_for() {
    local used="$1"
    if [ "${used}" -ge "${EMERGENCY_THRESHOLD}" ]; then
        printf 'EMERGENCY\n'
    elif [ "${used}" -ge "${CRITICAL_THRESHOLD}" ]; then
        printf 'CRITICAL\n'
    elif [ "${used}" -ge "${WARN_THRESHOLD}" ]; then
        printf 'WARNING\n'
    else
        printf 'OK\n'
    fi
}

notify_webhook() {
    local event="$1" level="$2" payload
    [ -n "${WEBHOOK_URL}" ] || return 0
    case "${WEBHOOK_URL}" in
        https://*) ;;
        *) log "NOTIFY_ERROR event=${event} level=${level} motivo=url-https-invalida"; return 0 ;;
    esac
    case "${WEBHOOK_URL}" in
        *$'\r'*|*$'\n'*|*$'\t'*|*\"*|*' '*)
            log "NOTIFY_ERROR event=${event} level=${level} motivo=url-https-invalida"
            return 0
            ;;
    esac
    if ! command -v curl >/dev/null 2>&1; then
        log "NOTIFY_ERROR event=${event} level=${level} motivo=curl-indisponivel"
        return 0
    fi

    # O endpoint segue por stdin de configuração e nunca aparece no log ou argv.
    payload="$(printf \
        '{"source":"dns-center-disk-guard","event":"%s","level":"%s","filesystem":"%s","used_percent":%s,"free":"%s"}' \
        "${event}" "${level}" "${MOUNT//[^A-Za-z0-9_./-]/}" "${used_percent}" "${free_human//[^A-Za-z0-9_.-]/}")"
    if ! printf 'url = "%s"\n' "${WEBHOOK_URL}" \
        | curl --config - --fail --silent --show-error \
            --max-time "${WEBHOOK_TIMEOUT}" \
            -H 'Content-Type: application/json' --data-binary "${payload}" \
            >/dev/null 2>&1; then
        log "NOTIFY_ERROR event=${event} level=${level} motivo=webhook-indisponivel"
    fi
}

prune_old_release_images() {
    local normalized protected repo tag
    protected="$(cat "${STATE_DIR}/current-version" "${STATE_DIR}/previous-version" 2>/dev/null || true)"

    for repo in dns-center-app dns-center-web; do
        "${DOCKER_COMMAND}" images --format '{{.Tag}}' "${repo}" 2>/dev/null \
            | grep -E '^v?[0-9]+\.[0-9]+\.[0-9]+$' \
            | while IFS= read -r tag; do printf '%s\t%s\n' "${tag#v}" "${tag}"; done \
            | sort -V -k1,1 | head -n "-${KEEP_RECENT_VERSIONS}" | cut -f2- \
            | while IFS= read -r tag; do
                normalized="${tag#v}"
                if printf '%s\n' "${protected}" | sed 's/^v//' | grep -qx "${normalized}"; then
                    continue
                fi
                "${DOCKER_COMMAND}" rmi "${repo}:${tag}" >/dev/null 2>&1 \
                    && log "CLEANUP imagem-antiga=${repo}:${tag}" || true
            done || true
    done
}

read_usage
severity="$(severity_for "${used_percent}")"
previous="OK"
[ ! -r "${STATE_FILE}" ] || read -r previous < "${STATE_FILE}"

log "STATUS level=${severity} filesystem=${MOUNT} used=${used_percent}% free=${free_human}"

if [ "${severity}" != "${previous}" ]; then
    if [ "${severity}" = "OK" ]; then
        log "RECOVERY previous=${previous} filesystem=${MOUNT} used=${used_percent}% free=${free_human}"
        notify_webhook recovery OK
    else
        log "ALERT level=${severity} previous=${previous} filesystem=${MOUNT} used=${used_percent}% free=${free_human}"
        notify_webhook alert "${severity}"
    fi
    printf '%s\n' "${severity}" > "${STATE_FILE}"
fi

if [ "${severity}" = "CRITICAL" ] || [ "${severity}" = "EMERGENCY" ]; then
    log "CLEANUP_SKIPPED level=${severity} motivo=intervencao-manual-obrigatoria"
    exit 0
fi

if command -v "${DOCKER_COMMAND}" >/dev/null 2>&1; then
    "${DOCKER_COMMAND}" image prune -f >/dev/null 2>&1 || true
    prune_old_release_images
    "${DOCKER_COMMAND}" builder prune -f --filter 'until=168h' >/dev/null 2>&1 || true
else
    log "CLEANUP_SKIPPED motivo=docker-indisponivel"
fi
