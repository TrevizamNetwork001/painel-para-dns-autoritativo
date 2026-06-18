#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

BASE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PANEL_DIR=$(CDPATH= cd -- "$BASE_DIR/../.." && pwd)
SSH_KEY='/var/www/.ssh/id_ed25519_dns_sync'
KNOWN_HOSTS='/var/www/.ssh/known_hosts'

fail() {
    printf '%s\n' "ERRO: $*" >&2
    exit 1
}

usage() {
    printf '%s\n' "uso: $0 IP_OU_HOST_NS2 USUARIO_SSH [PORTA_SSH] [ZONA_TESTE]" >&2
    exit 1
}

[ "$#" -ge 2 ] && [ "$#" -le 4 ] || usage

host=$1
user=$2
port=${3:-22}
zone=${4:-legacy.example}

printf '%s' "$host" | grep -Eq '^([A-Za-z0-9][A-Za-z0-9.-]{0,252}|[0-9A-Fa-f:.]+)$' \
    || fail "IP/hostname invalido"
printf '%s' "$user" | grep -Eq '^[a-z_][a-z0-9_-]{0,31}$' \
    || fail "usuario SSH invalido"
printf '%s' "$port" | grep -Eq '^[0-9]{1,5}$' \
    || fail "porta SSH invalida"
[ "$port" -ge 1 ] && [ "$port" -le 65535 ] || fail "porta SSH invalida"

ssh_cmd="/usr/bin/ssh -F /dev/null -i $SSH_KEY -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=$KNOWN_HOSTS -o ConnectTimeout=6 -p $port $user@$host"

printf '%s\n' '[1/5] SSH como www-data'
sudo -u www-data $ssh_cmd 'printf "%s\n" SSH_OK'

printf '%s\n' '[2/5] install do agente'
sudo -u www-data "$BASE_DIR/install-ns2-agent.sh" "$host" "$user" "$port"

printf '%s\n' '[3/5] dns-slave-status.sh'
sudo -u www-data $ssh_cmd 'sudo -n /usr/local/bin/dns-slave-status.sh'

printf '%s\n' '[4/5] dns-slave-check-transfer.sh'
sudo -u www-data $ssh_cmd "sudo -n /usr/local/bin/dns-slave-check-transfer.sh '$zone'"

printf '%s\n' '[5/5] validacao no painel dns-servers.php'
php -r '
require $argv[1] . "/includes/dns_servers.php";
$ok = false;
foreach (dns_servers_listar() as $server) {
    if (($server["hostname"] ?? "") === $argv[2] || ($server["ip4"] ?? "") === $argv[2] || ($server["ip6"] ?? "") === $argv[2]) {
        printf(
            "id=%s agente=%s bind=%s zonas_slave=%s ultimo_status=%s\n",
            $server["id"],
            $server["agente_status"],
            $server["bind_status"],
            $server["zonas_slave"] === null ? "NULL" : $server["zonas_slave"],
            $server["ultimo_status"]
        );
        $ok = $server["agente_status"] === "instalado"
            && $server["bind_status"] === "ok"
            && (int) $server["zonas_slave"] > 0;
    }
}
exit($ok ? 0 : 1);
' "$PANEL_DIR" "$host"

printf '%s\n' 'OK: testes finais NS2 concluidos'
