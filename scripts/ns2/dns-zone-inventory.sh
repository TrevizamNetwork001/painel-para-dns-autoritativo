#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

[ "$#" -eq 0 ] || fail "uso: $0"

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_dig=$(command -v dig 2>/dev/null || true)
[ -n "$cmd_named_checkconf" ] || fail "named-checkconf indisponivel"

zones_file=$(mktemp)
trap 'rm -f "$zones_file"' EXIT HUP INT TERM

dns_query_targets() {
    printf '%s\n' '127.0.0.1'
    printf '%s\n' '::1'
    if command -v hostname >/dev/null 2>&1; then
        hostname -I 2>/dev/null | tr ' ' '\n' | sed '/^$/d'
    fi
}

dns_soa_serial() {
    zone=$1
    [ -n "$cmd_dig" ] || return 1

    dns_query_targets | while IFS= read -r target; do
        [ -n "$target" ] || continue
        soa=$("$cmd_dig" "@$target" "$zone" SOA +short +time=2 +tries=1 2>/dev/null | head -n 1 || true)
        serial=$(printf '%s\n' "$soa" | awk '{ print $3 }')
        if printf '%s' "$serial" | grep -Eq '^[0-9]+$'; then
            printf '%s\n' "$serial"
            return 0
        fi
    done
}

"$cmd_named_checkconf" -p 2>/dev/null | awk '
    /^[[:space:]]*zone[[:space:]]+"/ {
        in_zone = 1
        zone = $0
        sub(/^.*zone[[:space:]]+"/, "", zone)
        sub(/".*$/, "", zone)
        type = ""
        file = ""
        masters = ""
    }
    in_zone && /type[[:space:]]+/ {
        line = $0
        sub(/^.*type[[:space:]]+/, "", line)
        sub(/[[:space:];].*$/, "", line)
        type = line
    }
    in_zone && /file[[:space:]]+"/ {
        line = $0
        sub(/^.*file[[:space:]]+"/, "", line)
        sub(/".*$/, "", line)
        file = line
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
        if ((type == "master" || type == "slave") &&
            zone != "." &&
            zone != "localhost" &&
            zone != "127.in-addr.arpa" &&
            zone != "0.in-addr.arpa" &&
            zone != "255.in-addr.arpa") {
            gsub(/\t/, " ", zone)
            gsub(/\t/, " ", type)
            gsub(/\t/, " ", file)
            gsub(/\t/, " ", masters)
            printf "%s\t%s\t%s\t%s\n", zone, type, file, masters
        }
        in_zone = 0
    }
' >"$zones_file"

while IFS="$(printf '\t')" read -r zone type file masters; do
    [ -n "$zone" ] || continue
    serial=''
    status='ok'
    message=''

    serial=$(dns_soa_serial "$zone" | head -n 1 || true)

    if [ -z "$serial" ]; then
        status='sem_soa'
        message='SOA indisponivel via enderecos locais'
    fi

    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\n' \
        "$zone" "$type" "$serial" "$file" "$masters" "$status" "$message"
done <"$zones_file"
