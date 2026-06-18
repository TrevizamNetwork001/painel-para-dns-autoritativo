#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

CONF='/etc/bind/named.conf.local'
SLAVE_AUT_DIR='/var/cache/bind/slave-aut'
SLAVE_REV_DIR='/var/cache/bind/slave-rev'

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -eq 2 ] || [ "$#" -eq 3 ] || fail "uso: $0 ZONE_NAME MASTER_IP [ZONE_FILE_COMPAT]"

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_rndc=$(command -v rndc 2>/dev/null || true)
[ -n "$cmd_named_checkconf" ] || fail "named-checkconf indisponivel"
[ -n "$cmd_rndc" ] || fail "rndc indisponivel"

zone=$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')
master_ip=$2

printf '%s' "$zone" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$' \
    || fail "nome de zona invalido"

case "$master_ip" in
    ''|*[!0-9A-Fa-f:.]*) fail "IP do master invalido" ;;
esac

case "$zone" in
    *.in-addr.arpa|*.ip6.arpa)
        zone_dir=$SLAVE_REV_DIR
        zone_file=$zone.rev
        ;;
    *)
        zone_dir=$SLAVE_AUT_DIR
        zone_file=$zone.hosts
        ;;
esac
zone_path=$zone_dir/$zone_file

install -d -o root -g bind -m 0770 "$SLAVE_AUT_DIR" "$SLAVE_REV_DIR"
touch "$CONF"
chown root:bind "$CONF"
chmod 0644 "$CONF"

exec 9>"$CONF.lock"
flock -x 9

marker="// dns-panel-zone: $zone"
if grep -Fqx "$marker" "$CONF" || grep -Eq "^[[:space:]]*zone[[:space:]]+\"$zone\"[[:space:]]*\\{" "$CONF"; then
    fail "zona ja cadastrada"
fi

tmp=$(mktemp "${CONF}.tmp.XXXXXX")
backup=$(mktemp "${CONF}.bak.XXXXXX")
trap 'rm -f "$tmp" "$backup"' EXIT HUP INT TERM
cp -p "$CONF" "$tmp"
cp -p "$CONF" "$backup"

cat >>"$tmp" <<EOF

$marker
zone "$zone" {
    type slave;
    masters { $master_ip; };
    file "$zone_path";
};
// dns-panel-end: $zone
EOF

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

printf 'OK: zona slave %s adicionada em %s\n' "$zone" "$zone_path"
