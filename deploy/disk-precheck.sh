#!/usr/bin/env bash

# Precheck reutilizável para pulls, builds, CI e homologações. Não limpa nada.

set -euo pipefail

MOUNT="${DNS_CENTER_DISK_MOUNT:-/}"
MAX_USED_PERCENT="${DNS_CENTER_PRECHECK_MAX_USED_PERCENT:-80}"
MIN_FREE_BYTES="${DNS_CENTER_PRECHECK_MIN_FREE_BYTES:-5368709120}"
RESERVE_BYTES="${DNS_CENTER_PRECHECK_RESERVE_BYTES:-5368709120}"
ESTIMATED_BYTES="${DNS_CENTER_PRECHECK_ESTIMATED_BYTES:-0}"
OPERATION="${DNS_CENTER_PRECHECK_OPERATION:-operacao-pesada}"

usage() {
    printf 'Uso: %s [--estimated-bytes N] [--operation NOME]\n' "$0"
}

fail_config() {
    printf 'PRECHECK_ERROR: %s\n' "$*" >&2
    exit 21
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --estimated-bytes) [ "$#" -ge 2 ] || fail_config "valor ausente"; ESTIMATED_BYTES="$2"; shift 2 ;;
        --operation) [ "$#" -ge 2 ] || fail_config "valor ausente"; OPERATION="$2"; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) fail_config "argumento desconhecido: $1" ;;
    esac
done

for pair in \
    "DNS_CENTER_PRECHECK_MAX_USED_PERCENT:${MAX_USED_PERCENT}" \
    "DNS_CENTER_PRECHECK_MIN_FREE_BYTES:${MIN_FREE_BYTES}" \
    "DNS_CENTER_PRECHECK_RESERVE_BYTES:${RESERVE_BYTES}" \
    "DNS_CENTER_PRECHECK_ESTIMATED_BYTES:${ESTIMATED_BYTES}"; do
    name="${pair%%:*}"
    value="${pair#*:}"
    case "${value}" in
        ''|*[!0-9]*) fail_config "${name} deve ser um inteiro não negativo" ;;
    esac
done
[ "${MAX_USED_PERCENT}" -le 100 ] || fail_config "percentual máximo deve ser <= 100"

line="$(df -P -B1 "${MOUNT}" 2>/dev/null | awk 'NR==2 { print $4, $5 }')" \
    || fail_config "não foi possível ler o filesystem ${MOUNT}"
read -r free_bytes used_percent <<< "${line}"
used_percent="${used_percent%%%}"
case "${free_bytes}:${used_percent}" in
    *[!0-9:]*|:*) fail_config "resposta inválida de df para ${MOUNT}" ;;
esac

required_after_estimate=$((RESERVE_BYTES + ESTIMATED_BYTES))
blocked=""
if [ "${used_percent}" -ge "${MAX_USED_PERCENT}" ]; then
    blocked="uso ${used_percent}% >= limite ${MAX_USED_PERCENT}%"
elif [ "${free_bytes}" -lt "${MIN_FREE_BYTES}" ]; then
    blocked="livre ${free_bytes} bytes < mínimo ${MIN_FREE_BYTES} bytes"
elif [ "${free_bytes}" -lt "${required_after_estimate}" ]; then
    blocked="estimativa ${ESTIMATED_BYTES} bytes compromete reserva ${RESERVE_BYTES} bytes"
fi

if [ -n "${blocked}" ]; then
    printf 'PRECHECK_BLOCKED operation=%s filesystem=%s used=%s%% free_bytes=%s estimated_bytes=%s reserve_bytes=%s reason=%s\n' \
        "${OPERATION}" "${MOUNT}" "${used_percent}" "${free_bytes}" \
        "${ESTIMATED_BYTES}" "${RESERVE_BYTES}" "${blocked}" >&2
    exit 20
fi

printf 'PRECHECK_OK operation=%s filesystem=%s used=%s%% free_bytes=%s estimated_bytes=%s reserve_bytes=%s\n' \
    "${OPERATION}" "${MOUNT}" "${used_percent}" "${free_bytes}" \
    "${ESTIMATED_BYTES}" "${RESERVE_BYTES}"
