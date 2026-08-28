#!/usr/bin/env bash

set -euo pipefail

readonly INSTALLER_VERSION="1.3.0"
ENROLL_MODE="${1:-}"
PANEL_URL="${DNS_CENTER_PANEL_URL:-https://dnscenter.trevizamnetwork.com.br}"
PANEL_URL="${PANEL_URL%/}"
INSTALL_PATH="/usr/local/sbin/dns-center-agent"
CONFIG_DIR="/etc/dns-center-agent"
STATE_DIR="/var/lib/dns-center-agent"
SYSTEMD_DIR="/etc/systemd/system"

case "${PANEL_URL}" in
    https://*) CURL_PROTO="=https" ;;
    http://localhost|http://localhost:*|http://127.0.0.1|http://127.0.0.1:*)
        CURL_PROTO="=http,https"
        ;;
    *)
        echo "DNS_CENTER_PANEL_URL deve usar HTTPS (HTTP somente em localhost)." >&2
        exit 1
        ;;
esac

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute o instalador com privilégios de root." >&2
    exit 1
fi

install_curl_if_missing() {
    if command -v curl >/dev/null 2>&1; then
        return
    fi

    echo "curl não encontrado; instalando o pré-requisito automaticamente..."
    if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends curl ca-certificates
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y curl ca-certificates
    elif command -v yum >/dev/null 2>&1; then
        yum install -y curl ca-certificates
    else
        echo "Não foi possível instalar curl automaticamente: gerenciador de pacotes não suportado." >&2
        exit 1
    fi
}

install_curl_if_missing

for command in curl python3 sha256sum systemctl install mktemp cmp; do
    if ! command -v "${command}" >/dev/null 2>&1; then
        echo "Dependência ausente: ${command}" >&2
        exit 1
    fi
done

temporary_dir="$(mktemp -d /tmp/dns-center-agent-install.XXXXXX)"
trap 'rm -rf -- "${temporary_dir}"' EXIT

artifacts=(
    dns-center-agent.py
    dns-center-agent.service
    dns-center-agent.timer
    dns-center-agent-operation.service
    dns-center-agent-approval.service
    dns-center-agent-approval.timer
)

for artifact in "${artifacts[@]}"; do
    curl --proto "${CURL_PROTO}" --tlsv1.2 \
        -fsSLo "${temporary_dir}/${artifact}" \
        "${PANEL_URL}/install/${artifact}"
    curl --proto "${CURL_PROTO}" --tlsv1.2 \
        -fsSLo "${temporary_dir}/${artifact}.sha256" \
        "${PANEL_URL}/install/${artifact}.sha256"
    (
        cd "${temporary_dir}"
        sha256sum --check "${artifact}.sha256"
    )
done

python3 -m py_compile "${temporary_dir}/dns-center-agent.py"

backup_dir="${temporary_dir}/previous"
install -d -m 0700 "${backup_dir}"
if [ -e "${INSTALL_PATH}" ]; then
    install -m 0750 "${INSTALL_PATH}" "${backup_dir}/dns-center-agent.py"
fi
for unit in "${artifacts[@]:1}"; do
    if [ -e "${SYSTEMD_DIR}/${unit}" ]; then
        install -m 0644 \
            "${SYSTEMD_DIR}/${unit}" \
            "${backup_dir}/${unit}"
    fi
done

rollback_installation() {
    systemctl disable --now \
        dns-center-agent.timer \
        dns-center-agent-approval.timer >/dev/null 2>&1 || true

    if [ -e "${backup_dir}/dns-center-agent.py" ]; then
        install -m 0750 \
            "${backup_dir}/dns-center-agent.py" \
            "${INSTALL_PATH}"
    else
        rm -f -- "${INSTALL_PATH}"
    fi

    for unit in "${artifacts[@]:1}"; do
        if [ -e "${backup_dir}/${unit}" ]; then
            install -m 0644 \
                "${backup_dir}/${unit}" \
                "${SYSTEMD_DIR}/${unit}"
        else
            rm -f -- "${SYSTEMD_DIR}/${unit}"
        fi
    done
    systemctl daemon-reload
}

request_enrollment() {
    if [ "${ENROLL_MODE}" = "--enroll" ]; then
        printf 'Código temporário de vínculo: ' > /dev/tty
        IFS= read -rs enrollment_code < /dev/tty
        printf '\n' > /dev/tty
        if [ "${#enrollment_code}" -lt 32 ]; then
            echo "Código temporário inválido." >&2
            return 1
        fi
        printf '%s\n' "${enrollment_code}" \
            | DNS_CENTER_PANEL_URL="${PANEL_URL}" \
                "${INSTALL_PATH}" --enroll --stdin --wait 0
        unset enrollment_code
    else
        DNS_CENTER_PANEL_URL="${PANEL_URL}" \
            "${INSTALL_PATH}" --request-approval --wait 0
    fi
}

upgrade_agent() {
    if [ ! -e "${INSTALL_PATH}" ]; then
        echo "Nenhum agente instalado em ${INSTALL_PATH}; use a instalação completa (sem --upgrade-agent)." >&2
        exit 1
    fi
    if [ -L "${INSTALL_PATH}" ]; then
        echo "${INSTALL_PATH} é um link simbólico; abortando por segurança." >&2
        exit 1
    fi

    local binary_changed=0 units_changed=0 unit

    if ! cmp -s "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"; then
        binary_changed=1
        install -m 0750 "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"

        if ! "${INSTALL_PATH}" --version >/dev/null 2>&1 \
            || ! "${INSTALL_PATH}" --help 2>&1 | grep -q -- '--enroll'; then
            echo "Falha na validação do novo agente; restaurando binário anterior." >&2
            install -m 0750 "${backup_dir}/dns-center-agent.py" "${INSTALL_PATH}"
            exit 1
        fi
    fi

    for unit in "${artifacts[@]:1}"; do
        if [ -e "${SYSTEMD_DIR}/${unit}" ] \
            && cmp -s "${temporary_dir}/${unit}" "${SYSTEMD_DIR}/${unit}"; then
            continue
        fi
        install -m 0644 "${temporary_dir}/${unit}" "${SYSTEMD_DIR}/${unit}"
        units_changed=1
    done

    if [ "${units_changed}" -eq 1 ]; then
        systemctl daemon-reload
    fi

    if [ "${binary_changed}" -eq 0 ] && [ "${units_changed}" -eq 0 ]; then
        echo "Agente já está atualizado; nada foi alterado."
    else
        echo "Agente atualizado com sucesso pelo instalador ${INSTALLER_VERSION}."
    fi
    echo "Configuração, state e vínculos existentes foram preservados."
    echo "Nenhum serviço/timer foi (re)iniciado e o BIND não foi tocado."
    echo "Verifique com: ${INSTALL_PATH} --help"
}

if [ "${ENROLL_MODE}" = "--upgrade-agent" ]; then
    upgrade_agent
    exit 0
fi

if ! {
    install -d -m 0750 "${CONFIG_DIR}" "${STATE_DIR}"
    install -m 0750 "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"
    for unit in "${artifacts[@]:1}"; do
        install -m 0644 "${temporary_dir}/${unit}" "${SYSTEMD_DIR}/${unit}"
    done
    request_enrollment
    systemctl daemon-reload
    systemctl enable --now \
        dns-center-agent.timer \
        dns-center-agent-approval.timer
    systemctl start dns-center-agent-approval.service
}; then
    echo "Falha ao ativar o agente; restaurando artefatos anteriores." >&2
    rollback_installation
    exit 1
fi

printf 'DNS Center Agent instalado pelo instalador %s.\n' "${INSTALLER_VERSION}"
echo "A solicitação foi enviada ao painel para aprovação."
