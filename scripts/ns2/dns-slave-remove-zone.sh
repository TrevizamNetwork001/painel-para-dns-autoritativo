#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

CONF='/etc/bind/named.conf.local'

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -eq 1 ] || fail "uso: $0 ZONE_NAME"

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_rndc=$(command -v rndc 2>/dev/null || true)
[ -n "$cmd_named_checkconf" ] || fail "named-checkconf indisponivel"
[ -n "$cmd_rndc" ] || fail "rndc indisponivel"

zone=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')
printf '%s' "$zone" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$' \
    || fail "nome de zona invalido"

[ -f "$CONF" ] || fail "named.conf.local inexistente"

exec 9>"$CONF.lock"
flock -x 9

start="// dns-panel-zone: $zone"
end="// dns-panel-end: $zone"
grep -Fqx "$start" "$CONF" || fail "zona nao cadastrada pelo painel"

tmp=$(mktemp "${CONF}.tmp.XXXXXX")
backup=$(mktemp "${CONF}.bak.XXXXXX")
trap 'rm -f "$tmp" "$backup"' EXIT HUP INT TERM
cp -p "$CONF" "$backup"

awk -v start="$start" -v end="$end" '
    $0 == start { skip = 1; next }
    skip && $0 == end { skip = 0; next }
    !skip { print }
' "$CONF" >"$tmp"

install -o root -g bind -m 0644 "$tmp" "$CONF"

if ! "$cmd_named_checkconf" >/dev/null 2>&1; then
    install -o root -g bind -m 0644 "$backup" "$CONF"
    fail "configuracao global invalida; alteracao revertida"
fi

if ! "$cmd_rndc" reconfig >/dev/null 2>&1; then
    install -o root -g bind -m 0644 "$backup" "$CONF"
    "$cmd_rndc" reconfig >/dev/null 2>&1 || true
    fail "falha no reload; alteracao revertida"
fi

printf 'OK: zona slave %s removida da configuracao\n' "$zone"
