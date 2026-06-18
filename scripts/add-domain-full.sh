#!/bin/bash

set -euo pipefail

DOMAIN=${1:-}
IPV4=${2:-}
IPV6=${3:-}
IPV4_NS2=${4:-}
IPV6_NS2=${5:-}
REV4_LIST=${6:-}
REV6_PREFIX=${7:-}

if [[ ! "$DOMAIN" =~ ^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$ ]]; then
    echo "[ERRO] Domínio inválido."
    exit 1
fi

ZONE_FILE="/var/cache/bind/master-aut/${DOMAIN}.hosts"
CONF="/etc/bind/named.conf.local"
SERIAL=$(date '+%Y%m%d01')

if [ -e "$ZONE_FILE" ]; then
    echo "[ERRO] A zona já existe."
    exit 1
fi

CONF_BACKUP=$(mktemp)
cp "$CONF" "$CONF_BACKUP"
CREATED_FILES=()

rollback() {
    cp "$CONF_BACKUP" "$CONF"
    for file in "${CREATED_FILES[@]}"; do
        rm -f "$file"
    done
    rm -f "$CONF_BACKUP"
}

trap rollback ERR

IPV4_NS2=${IPV4_NS2:-$IPV4}
IPV6_NS2=${IPV6_NS2:-$IPV6}

{
    echo "\$ORIGIN ${DOMAIN}."
    echo "\$TTL 3600"
    echo
    echo "@ IN SOA ns1.${DOMAIN}. hostmaster.${DOMAIN}. ("
    echo "    ${SERIAL}"
    echo "    900"
    echo "    3600"
    echo "    2419200"
    echo "    300"
    echo ")"
    echo
    echo "@ IN NS ns1.${DOMAIN}."
    echo "@ IN NS ns2.${DOMAIN}."
    echo "@ IN A ${IPV4}"
    [ -n "$IPV6" ] && echo "@ IN AAAA ${IPV6}"
    echo "ns1 IN A ${IPV4}"
    echo "ns2 IN A ${IPV4_NS2}"
    [ -n "$IPV6" ] && echo "ns1 IN AAAA ${IPV6}"
    [ -n "$IPV6_NS2" ] && echo "ns2 IN AAAA ${IPV6_NS2}"
    echo "www IN A ${IPV4}"
    [ -n "$IPV6" ] && echo "www IN AAAA ${IPV6}"
} > "$ZONE_FILE"
CREATED_FILES+=("$ZONE_FILE")

/usr/bin/named-checkzone "$DOMAIN" "$ZONE_FILE"

{
    echo
    echo "zone \"${DOMAIN}\" {"
    echo "    type master;"
    echo "    file \"${ZONE_FILE}\";"
    echo "};"
} >> "$CONF"

IFS=';' read -ra BLOCKS <<< "$REV4_LIST"
for BLOCK in "${BLOCKS[@]}"; do
    BLOCK=$(printf '%s' "$BLOCK" | xargs)
    [ -z "$BLOCK" ] && continue

    REVADDR=$(printf '%s' "$BLOCK" | awk -F. '{print $3"."$2"."$1}')
    REVFILE="/var/cache/bind/master-rev/${BLOCK}.rev"
    REVZONE="${REVADDR}.in-addr.arpa"

    if [ -e "$REVFILE" ] || grep -Fq "zone \"${REVZONE}\"" "$CONF"; then
        echo "[ERRO] A zona reversa ${REVZONE} já existe."
        false
    fi

    cat > "$REVFILE" <<EOF
\$TTL 3600
@ IN SOA ns1.${DOMAIN}. hostmaster.${DOMAIN}. (
    ${SERIAL}
    900
    3600
    2419200
    300
)
@ IN NS ns1.${DOMAIN}.
@ IN NS ns2.${DOMAIN}.
\$ORIGIN ${REVZONE}.
\$GENERATE 0-255 \$ PTR host-\$.${DOMAIN}.
EOF
    CREATED_FILES+=("$REVFILE")

    /usr/bin/named-checkzone "$REVZONE" "$REVFILE"
    cat >> "$CONF" <<EOF

zone "${REVZONE}" {
    type master;
    file "${REVFILE}";
};
EOF
done

if [ -n "$REV6_PREFIX" ]; then
    REVZONE=$(php -r '
        [$ip, $prefix] = explode("/", $argv[1], 2);
        if (((int) $prefix % 4) !== 0) { exit(2); }
        $hex = bin2hex(inet_pton($ip));
        $nibbles = substr($hex, 0, (int) $prefix / 4);
        echo implode(".", array_reverse(str_split($nibbles))) . ".ip6.arpa";
    ' "$REV6_PREFIX")
    REV6_FILE="/var/cache/bind/master-rev/${DOMAIN}.rev6"

    if [ -e "$REV6_FILE" ] || grep -Fq "zone \"${REVZONE}\"" "$CONF"; then
        echo "[ERRO] A zona reversa ${REVZONE} já existe."
        false
    fi

    cat > "$REV6_FILE" <<EOF
\$TTL 3600
@ IN SOA ns1.${DOMAIN}. hostmaster.${DOMAIN}. (
    ${SERIAL}
    900
    3600
    2419200
    300
)
@ IN NS ns1.${DOMAIN}.
@ IN NS ns2.${DOMAIN}.
EOF
    CREATED_FILES+=("$REV6_FILE")

    /usr/bin/named-checkzone "$REVZONE" "$REV6_FILE"
    cat >> "$CONF" <<EOF

zone "${REVZONE}" {
    type master;
    file "${REV6_FILE}";
};
EOF
fi

/usr/sbin/rndc reload
trap - ERR
rm -f "$CONF_BACKUP"
echo "[OK] Domínio e zonas reversas criados."
