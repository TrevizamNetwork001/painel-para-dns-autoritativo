#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

BASE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
SSH_KEY='/var/www/.ssh/id_ed25519_dns_sync'
KNOWN_HOSTS='/var/www/.ssh/known_hosts'

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

usage() {
    printf '%s\n' "uso: $0 IP_OU_HOST_NS2 USUARIO_SSH [PORTA_SSH]" >&2
    exit 1
}

[ "$#" -eq 2 ] || [ "$#" -eq 3 ] || usage

host=$1
user=$2
port=${3:-22}

printf '%s' "$host" | grep -Eq '^([A-Za-z0-9][A-Za-z0-9.-]{0,252}|[0-9A-Fa-f:.]+)$' \
    || fail "IP/hostname invalido"
printf '%s' "$user" | grep -Eq '^[a-z_][a-z0-9_-]{0,31}$' \
    || fail "usuario SSH invalido"
printf '%s' "$port" | grep -Eq '^[0-9]{1,5}$' \
    || fail "porta SSH invalida"
[ "$port" -ge 1 ] && [ "$port" -le 65535 ] || fail "porta SSH invalida"

[ -r "$SSH_KEY" ] || fail "chave SSH indisponivel em $SSH_KEY"
[ -r "$KNOWN_HOSTS" ] || fail "known_hosts indisponivel em $KNOWN_HOSTS"

for file in \
    dns-slave-status.sh \
    dns-slave-reload.sh \
    dns-slave-check-transfer.sh \
    dns-slave-add-zone.sh \
    dns-slave-remove-zone.sh \
    dns-slave-migrate-layout.sh \
    dns-zone-inventory.sh \
    dns-sync-command.sh \
    dns-sync-bootstrap.sudoers \
    dns-sync.sudoers
do
    [ -f "$BASE_DIR/$file" ] || fail "arquivo ausente no pacote: $file"
done

ssh_base="
    -F /dev/null
    -i $SSH_KEY
    -o BatchMode=yes
    -o IdentitiesOnly=yes
    -o StrictHostKeyChecking=yes
    -o UserKnownHostsFile=$KNOWN_HOSTS
    -o ConnectTimeout=6
    -p $port
"

dest="$user@$host"
remote_dir="/tmp/dns-panel-ns2-agent.$$"

ssh $ssh_base "$dest" 'printf "%s\n" SSH_OK' >/dev/null \
    || fail "falha na conexao SSH"

ssh $ssh_base "$dest" "rm -rf '$remote_dir' && mkdir -p '$remote_dir'" \
    || fail "falha ao preparar diretorio temporario remoto"

scp -F /dev/null \
    -i "$SSH_KEY" \
    -o BatchMode=yes \
    -o IdentitiesOnly=yes \
    -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile="$KNOWN_HOSTS" \
    -o ConnectTimeout=6 \
    -P "$port" \
    "$BASE_DIR/dns-slave-status.sh" \
    "$BASE_DIR/dns-slave-reload.sh" \
    "$BASE_DIR/dns-slave-check-transfer.sh" \
    "$BASE_DIR/dns-slave-add-zone.sh" \
    "$BASE_DIR/dns-slave-remove-zone.sh" \
    "$BASE_DIR/dns-slave-migrate-layout.sh" \
    "$BASE_DIR/dns-zone-inventory.sh" \
    "$BASE_DIR/dns-sync-command.sh" \
    "$BASE_DIR/dns-sync-bootstrap.sudoers" \
    "$BASE_DIR/dns-sync.sudoers" \
    "$dest:$remote_dir/" >/dev/null \
    || fail "falha ao copiar pacote do agente"

remote_install="
set -eu
BOOTSTRAP='/etc/sudoers.d/dns-sync-bootstrap'
FINAL='/etc/sudoers.d/dns-sync'

sudo_n() {
    sudo -n "\$@" || {
        rc=\$?
        printf '%s' 'ERRO: comando sudo -n falhou: sudo -n' >&2
        for arg in "\$@"; do
            printf ' %s' "\$arg" >&2
        done
        printf '\n' >&2
        exit "\$rc"
    }
}

if ! sudo -n /usr/bin/install -o root -g root -m 0750 '$remote_dir'/dns-slave-status.sh /usr/local/bin/dns-slave-status.sh >/dev/null 2>&1; then
    echo 'ERRO: comando sudo -n falhou: sudo -n /usr/bin/install -o root -g root -m 0750 '$remote_dir'/dns-slave-status.sh /usr/local/bin/dns-slave-status.sh' >&2
    echo 'ERRO: sudoers bootstrap ausente ou insuficiente no NS2' >&2
    echo 'Instale temporariamente /etc/sudoers.d/dns-sync-bootstrap a partir de dns-sync-bootstrap.sudoers e rode visudo -c.' >&2
    rm -rf '$remote_dir'
    exit 1
fi

if ! getent group dns-sync >/dev/null 2>&1; then
    sudo_n /usr/sbin/groupadd --system dns-sync
fi
if ! id -nG dns-sync 2>/dev/null | tr ' ' '\n' | grep -Fx dns-sync >/dev/null 2>&1; then
    sudo_n /usr/sbin/usermod -a -G dns-sync dns-sync
fi

for f in dns-slave-status.sh dns-slave-reload.sh dns-slave-check-transfer.sh dns-slave-add-zone.sh dns-slave-remove-zone.sh dns-slave-migrate-layout.sh dns-zone-inventory.sh; do
    sudo_n /usr/bin/install -o root -g root -m 0750 '$remote_dir'/"\$f" /usr/local/bin/"\$f"
done
sudo_n /usr/bin/install -o root -g dns-sync -m 0750 '$remote_dir'/dns-sync-command.sh /usr/local/bin/dns-sync-command.sh
sudo_n /usr/sbin/visudo -cf '$remote_dir'/dns-sync.sudoers >/dev/null
sudo_n /usr/bin/install -o root -g root -m 0440 '$remote_dir'/dns-sync.sudoers "\$FINAL"
sudo_n /usr/sbin/visudo -c >/dev/null
grep_rc=0
grep_err='$remote_dir'/grep.err
sudo -n /usr/bin/grep -R '^[[:space:]]*dns-sync[[:space:]]\+ALL=(ALL)[[:space:]]\+NOPASSWD:[[:space:]]\+ALL' /etc/sudoers /etc/sudoers.d >/dev/null 2>"\$grep_err" || grep_rc=\$?
if [ "\$grep_rc" -eq 0 ]; then
    echo 'ERRO: sudoers amplo proibido para dns-sync' >&2
    exit 1
fi
if [ "\$grep_rc" -ne 1 ] || [ -s "\$grep_err" ]; then
    cat "\$grep_err" >&2
    echo 'ERRO: comando sudo -n falhou: sudo -n /usr/bin/grep -R ^[[:space:]]*dns-sync[[:space:]]\+ALL=(ALL)[[:space:]]\+NOPASSWD:[[:space:]]\+ALL /etc/sudoers /etc/sudoers.d' >&2
    exit "\$grep_rc"
fi
rm -f "\$grep_err"

sudo_n /bin/rm -f "\$BOOTSTRAP"

sudo_n /usr/local/bin/dns-slave-status.sh
rm -rf '$remote_dir'
"

ssh $ssh_base "$dest" "$remote_install" \
    || fail "falha ao instalar ou validar agente no NS2"

printf '%s\n' 'OK: agente NS2 instalado e validado'
