#!/usr/bin/env bash

set -euo pipefail

readonly INSTALLER_VERSION="1.0.0"
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

for command in curl python3 sha256sum systemctl install mktemp; do
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

if ! {
    install -d -m 0750 "${CONFIG_DIR}" "${STATE_DIR}"
    install -m 0750 "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"
    for unit in "${artifacts[@]:1}"; do
        install -m 0644 "${temporary_dir}/${unit}" "${SYSTEMD_DIR}/${unit}"
    done
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
