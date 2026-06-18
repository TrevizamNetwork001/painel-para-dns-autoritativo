#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

LOCAL_CONF='/etc/bind/named.conf.local'
LEGACY_CONF='/etc/bind/named.conf.slaves'
SLAVE_AUT_DIR='/var/cache/bind/slave-aut'
SLAVE_REV_DIR='/var/cache/bind/slave-rev'

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -eq 0 ] || fail "uso: $0"

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_rndc=$(command -v rndc 2>/dev/null || true)
[ -n "$cmd_named_checkconf" ] || fail "named-checkconf indisponivel"
[ -n "$cmd_rndc" ] || fail "rndc indisponivel"
[ -f "$LOCAL_CONF" ] || fail "named.conf.local inexistente"

stamp=$(date +%Y%m%d%H%M%S)
local_backup="$LOCAL_CONF.bak-dns-panel-$stamp"
legacy_backup="$LEGACY_CONF.bak-dns-panel-$stamp"
legacy_zones=$(mktemp)
tmp=$(mktemp "${LOCAL_CONF}.tmp.XXXXXX")
trap 'rm -f "$legacy_zones" "$tmp"' EXIT HUP INT TERM

cp -p "$LOCAL_CONF" "$local_backup"
if [ -f "$LEGACY_CONF" ]; then
    cp -p "$LEGACY_CONF" "$legacy_backup"
    awk '
        /^[[:space:]]*zone[[:space:]]+"/ {
            in_zone = 1
            zone = $0
            sub(/^.*zone[[:space:]]+"/, "", zone)
            sub(/".*$/, "", zone)
            masters = ""
        }
        in_zone && /masters[[:space:]]*\{/ {
            line = $0
            sub(/^.*masters[[:space:]]*\{/, "", line)
            sub(/\}.*/, "", line)
            gsub(/[;[:space:]]+/, " ", line)
            sub(/^ /, "", line)
            sub(/ $/, "", line)
            masters = line
        }
        in_zone && /^\};/ {
            if (zone != "" && masters != "") {
                print zone "\t" masters
            }
            in_zone = 0
        }
    ' "$LEGACY_CONF" >"$legacy_zones"
else
    : >"$legacy_zones"
fi

install -d -o root -g bind -m 0770 "$SLAVE_AUT_DIR" "$SLAVE_REV_DIR"
awk 'index($0, "include \"/etc/bind/named.conf.slaves\";") == 0 { print }' "$LOCAL_CONF" >"$tmp"

while IFS="$(printf '\t')" read -r zone masters; do
    [ -n "$zone" ] || continue
    zone=$(printf '%s' "$zone" | tr '[:upper:]' '[:lower:]' | sed 's/\.$//')
    printf '%s' "$zone" | grep -Eq '^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$' \
        || fail "nome de zona invalido no legado: $zone"
    case "$zone" in
        *.in-addr.arpa|*.ip6.arpa)
            zone_path="$SLAVE_REV_DIR/$zone.rev"
            ;;
        *)
            zone_path="$SLAVE_AUT_DIR/$zone.hosts"
            ;;
    esac
    if grep -Eq "^[[:space:]]*zone[[:space:]]+\"$zone\"[[:space:]]*\\{" "$tmp"; then
        continue
    fi
    cat >>"$tmp" <<EOF

// dns-panel-zone: $zone
zone "$zone" {
    type slave;
    masters { $masters; };
    file "$zone_path";
};
// dns-panel-end: $zone
EOF
done <"$legacy_zones"

install -o root -g bind -m 0644 "$tmp" "$LOCAL_CONF"

if ! "$cmd_named_checkconf" >/dev/null 2>&1; then
    install -o root -g bind -m 0644 "$local_backup" "$LOCAL_CONF"
    fail "named-checkconf falhou; named.conf.local restaurado do backup"
fi

if ! "$cmd_rndc" reconfig >/dev/null 2>&1; then
    install -o root -g bind -m 0644 "$local_backup" "$LOCAL_CONF"
    "$cmd_rndc" reconfig >/dev/null 2>&1 || true
    fail "reload BIND falhou; named.conf.local restaurado do backup"
fi

printf 'OK: layout slave migrado para %s\n' "$LOCAL_CONF"
printf 'backup_local=%s\n' "$local_backup"
if [ -f "$legacy_backup" ]; then
    printf 'backup_legacy=%s\n' "$legacy_backup"
fi
printf 'legacy_conf_preservado=%s\n' "$LEGACY_CONF"
printf 'legacy_dir_preservado=%s\n' '/var/cache/bind/slaves'
