#!/usr/bin/env bash

set -euo pipefail

PANEL_URL="https://dnscenter.trevizamnetwork.com.br"
AGENT_URL="${PANEL_URL}/install/dns-center-agent.py"
CHECKSUM_URL="${AGENT_URL}.sha256"
INSTALL_PATH="/usr/local/sbin/dns-center-agent"
CONFIG_DIR="/etc/dns-center-agent"
STATE_DIR="/var/lib/dns-center-agent"

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute com privilégios: curl -fsSL https://trevizamnetwork.com.br/install/agent_install.sh | sudo bash" >&2
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

curl --proto '=https' --tlsv1.2 -fsSLo "${temporary_dir}/dns-center-agent.py" "${AGENT_URL}"
curl --proto '=https' --tlsv1.2 -fsSLo "${temporary_dir}/dns-center-agent.py.sha256" "${CHECKSUM_URL}"

(
    cd "${temporary_dir}"
    sha256sum --check dns-center-agent.py.sha256
)

python3 -m py_compile "${temporary_dir}/dns-center-agent.py"
install -d -m 0750 "${CONFIG_DIR}" "${STATE_DIR}"
install -m 0750 "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"

install -m 0644 /dev/stdin /etc/systemd/system/dns-center-agent.service <<'UNIT'
[Unit]
Description=DNS Center Agent
After=network-online.target
Wants=network-online.target
Wants=dns-center-agent-operation.service

[Service]
Type=oneshot
User=root
Group=root
ExecCondition=/usr/bin/test -f /etc/dns-center-agent/agent.json
ExecStart=/usr/local/sbin/dns-center-agent --heartbeat
ExecStart=/usr/local/sbin/dns-center-agent --inventory
ExecStart=/usr/local/sbin/dns-center-agent --readiness
ExecStart=/usr/local/sbin/dns-center-agent --sync-zones
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/var/lib/dns-center-agent -/etc/bind -/etc/named -/var/named -/var/backups/dns-center-agent
UMask=0027
UNIT

install -m 0644 /dev/stdin /etc/systemd/system/dns-center-agent.timer <<'UNIT'
[Unit]
Description=Executa DNS Center Agent periodicamente

[Timer]
OnBootSec=2min
OnUnitActiveSec=5min
Persistent=true
RandomizedDelaySec=30s

[Install]
WantedBy=timers.target
UNIT

install -m 0644 /dev/stdin /etc/systemd/system/dns-center-agent-operation.service <<'UNIT'
[Unit]
Description=Executa operação BIND autorizada pelo DNS Center
After=network-online.target dns-center-agent.service
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
ExecCondition=/usr/bin/test -f /etc/dns-center-agent/agent.json
ExecStart=/usr/local/sbin/dns-center-agent --run-authorized-operation
PrivateTmp=true
ProtectHome=true
UMask=0027
UNIT

install -m 0644 /dev/stdin /etc/systemd/system/dns-center-agent-approval.service <<'UNIT'
[Unit]
Description=Solicita aprovação do DNS Center Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
Group=root
ExecCondition=/usr/bin/test ! -f /etc/dns-center-agent/agent.json
ExecStart=/usr/local/sbin/dns-center-agent --request-approval --wait 240
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/etc/dns-center-agent
UMask=0077
UNIT

install -m 0644 /dev/stdin /etc/systemd/system/dns-center-agent-approval.timer <<'UNIT'
[Unit]
Description=Consulta aprovação do DNS Center Agent

[Timer]
OnBootSec=5s
OnUnitActiveSec=5min
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now dns-center-agent.timer dns-center-agent-approval.timer
systemctl start dns-center-agent-approval.service

echo "Agente instalado. A solicitação foi enviada ao painel para aprovação."
