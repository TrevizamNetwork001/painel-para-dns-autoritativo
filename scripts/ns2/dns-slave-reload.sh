#!/bin/sh
set -eu
PATH='/usr/sbin:/usr/bin:/sbin:/bin'
export PATH

[ "$#" -eq 0 ] || {
    printf '%s\n' "ERRO: este script nao recebe parametros" >&2
    exit 1
}

cmd_named_checkconf=$(command -v named-checkconf 2>/dev/null || true)
cmd_rndc=$(command -v rndc 2>/dev/null || true)

[ -n "$cmd_named_checkconf" ] || {
    printf '%s\n' "ERRO: named-checkconf indisponivel" >&2
    exit 1
}
[ -n "$cmd_rndc" ] || {
    printf '%s\n' "ERRO: rndc indisponivel" >&2
    exit 1
}

"$cmd_named_checkconf" >/dev/null 2>&1 || {
    printf '%s\n' "ERRO: configuracao BIND invalida" >&2
    exit 1
}

"$cmd_rndc" reconfig >/dev/null 2>&1 || "$cmd_rndc" reload >/dev/null 2>&1 || {
    printf '%s\n' "ERRO: falha ao recarregar BIND" >&2
    exit 1
}

printf '%s\n' 'OK: BIND recarregado'
