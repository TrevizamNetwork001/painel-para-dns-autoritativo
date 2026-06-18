#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

case "${SSH_ORIGINAL_COMMAND:-}" in
    'sudo -n /usr/local/bin/dns-slave-status.sh')
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-status.sh
        ;;
    'sudo -n /usr/local/bin/dns-slave-status.sh --connection-only')
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-status.sh --connection-only
        ;;
    'sudo -n /usr/local/bin/dns-zone-inventory.sh')
        exec /usr/bin/sudo -n /usr/local/bin/dns-zone-inventory.sh
        ;;
    'sudo -n /usr/local/bin/dns-slave-reload.sh')
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-reload.sh
        ;;
    'sudo -n /usr/local/bin/dns-slave-migrate-layout.sh')
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-migrate-layout.sh
        ;;
    sudo\ -n\ /usr/local/bin/dns-slave-add-zone.sh\ *)
        set -- ${SSH_ORIGINAL_COMMAND}
        [ "$#" -eq 6 ] || {
            printf '%s\n' 'ERRO: comando SSH invalido' >&2
            exit 126
        }
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-add-zone.sh "$4" "$5" "$6"
        ;;
    sudo\ -n\ /usr/local/bin/dns-slave-check-transfer.sh\ *)
        set -- ${SSH_ORIGINAL_COMMAND}
        [ "$#" -eq 4 ] || {
            printf '%s\n' 'ERRO: comando SSH invalido' >&2
            exit 126
        }
        exec /usr/bin/sudo -n /usr/local/bin/dns-slave-check-transfer.sh "$4"
        ;;
    *)
        printf '%s\n' 'ERRO: comando SSH nao permitido nesta fase' >&2
        exit 126
        ;;
esac
