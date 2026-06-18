#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -le 1 ] || fail "uso: $0 [--connection-only]"
[ "$#" -eq 0 ] || [ "$1" = '--connection-only' ] || fail "uso: $0 [--connection-only]"

if [ "${1:-}" = '--connection-only' ]; then
    printf '%s\n' 'SSH_OK'
    exit 0
fi

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_named=$(command -v named 2>/dev/null || true)
hostname_value=$(hostname -f 2>/dev/null || hostname 2>/dev/null || printf '%s' unknown)
debian_version=$(cat /etc/debian_version 2>/dev/null || printf '%s' unknown)
bind_version=$([ -n "$cmd_named" ] && "$cmd_named" -v 2>/dev/null | sed 's/^BIND //' || printf '%s' unavailable)
bind_active=$(systemctl is-active bind9 2>/dev/null || true)
bind_enabled=$(systemctl is-enabled bind9 2>/dev/null || true)
named_checkconf='erro'

if [ -n "$cmd_named_checkconf" ] && "$cmd_named_checkconf" >/dev/null 2>&1; then
    named_checkconf='ok'
fi

slave_zones=0
directories='unavailable'
zone_files=$(mktemp)
trap 'rm -f "$zone_files"' EXIT HUP INT TERM

if [ -n "$cmd_named_checkconf" ] && "$cmd_named_checkconf" -p >/dev/null 2>&1; then
    "$cmd_named_checkconf" -p 2>/dev/null \
        | awk '
            /^[[:space:]]*zone[[:space:]]+"/ {
                in_zone = 1
                slave = 0
                file = ""
            }
            in_zone && /type[[:space:]]+slave[[:space:]]*;/ { slave = 1 }
            in_zone && /file[[:space:]]+"/ {
                line = $0
                sub(/^.*file[[:space:]]+"/, "", line)
                sub(/".*$/, "", line)
                file = line
            }
            in_zone && /^\};/ {
                if (slave == 1) {
                    count++
                    if (file != "") print file > files
                }
                in_zone = 0
            }
            END { print count + 0 > count_file }
        ' files="$zone_files" count_file="$zone_files.count"
    slave_zones=$(cat "$zone_files.count" 2>/dev/null || printf '%s' 0)
    rm -f "$zone_files.count"
fi

if [ -s "$zone_files" ]; then
    directories=$(awk '
        {
            path = $0
            sub(/\/[^\/]*$/, "", path)
            if (path == $0) path = "."
            seen[path] = 1
        }
        END {
            first = 1
            for (path in seen) {
                if (!first) printf ","
                printf "%s", path
                first = 0
            }
        }
    ' "$zone_files")
fi

printf 'hostname=%s\n' "$hostname_value"
printf 'debian_version=%s\n' "$debian_version"
printf 'bind_version=%s\n' "$bind_version"
printf 'bind_active=%s\n' "${bind_active:-unknown}"
printf 'bind_enabled=%s\n' "${bind_enabled:-unknown}"
printf 'named_checkconf=%s\n' "$named_checkconf"
printf 'slave_zones=%s\n' "$slave_zones"
printf 'directories=%s\n' "${directories:-unavailable}"
printf 'timestamp=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

[ "${bind_active:-unknown}" = 'active' ] && [ "$named_checkconf" = 'ok' ]
