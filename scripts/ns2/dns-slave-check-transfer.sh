#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail "uso: $0 ZONE_NAME"

cmd_dig=$(command -v dig 2>/dev/null || true)
cmd_rndc=$(command -v rndc 2>/dev/null || true)
[ -n "$cmd_dig" ] || fail "dig indisponivel"

zone=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')
printf '%s' "$zone" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$' \
    || fail "nome de zona invalido"

rndc_status='unavailable'
if [ -n "$cmd_rndc" ]; then
    rndc_status=$("$cmd_rndc" zonestatus "$zone" 2>&1 || true)
    printf '%s' "$rndc_status" | grep -Eiq 'type:[[:space:]]*slave|zone:[[:space:]]*' \
        || fail "zona nao carregada no BIND"
fi

soa=$("$cmd_dig" +time=3 +tries=1 +short @127.0.0.1 "$zone" SOA 2>/dev/null \
    | head -n 1)
aa=$("$cmd_dig" +time=3 +tries=1 @127.0.0.1 "$zone" SOA 2>/dev/null \
    | awk '/^;; flags:/ { print ($0 ~ / aa[ ;]/ ? "yes" : "no"); exit }')

[ -n "$soa" ] || fail "SOA nao retornado pelo NS2"
serial=$(printf '%s\n' "$soa" | awk '{ print $3 }')
[ -n "$serial" ] || fail "serial SOA indisponivel"

printf 'zone=%s\n' "$zone"
printf 'soa=%s\n' "$soa"
printf 'authoritative=%s\n' "${aa:-unknown}"
printf 'serial=%s\n' "$serial"
printf 'rndc_zonestatus=%s\n' "$(printf '%s' "$rndc_status" | tr '\n' ' ' | sed 's/[[:space:]][[:space:]]*/ /g')"
