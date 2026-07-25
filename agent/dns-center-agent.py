#!/usr/bin/env python3

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import socket
import stat
import sys
import urllib.error
import urllib.request
import uuid
from pathlib import Path
from typing import Any

AGENT_VERSION = "0.1.0"
DEFAULT_CONFIG = Path(
    "/etc/dns-center-agent/agent.json"
)


class AgentError(RuntimeError):
    pass


def read_machine_id() -> str:
    paths = (
        Path("/etc/machine-id"),
        Path("/var/lib/dbus/machine-id"),
    )

    for path in paths:
        try:
            value = path.read_text(
                encoding="utf-8"
            ).strip()

            if value:
                return value
        except OSError:
            continue

    return socket.gethostname()


def build_fingerprint() -> str:
    source = "|".join(
        [
            read_machine_id(),
            socket.gethostname(),
            platform.machine(),
            platform.system(),
        ]
    )

    return hashlib.sha256(
        source.encode("utf-8")
    ).hexdigest()


def load_existing_uuid(config_path: Path) -> str | None:
    if not config_path.exists():
        return None

    try:
        data = json.loads(
            config_path.read_text(
                encoding="utf-8"
            )
        )
    except (OSError, json.JSONDecodeError):
        return None

    value = data.get("agent_uuid")

    if isinstance(value, str):
        try:
            return str(uuid.UUID(value))
        except ValueError:
            return None

    return None


def build_agent_uuid(config_path: Path) -> str:
    existing = load_existing_uuid(config_path)

    if existing:
        return existing

    namespace_source = (
        read_machine_id()
        + "|"
        + socket.gethostname()
    )

    return str(
        uuid.uuid5(
            uuid.NAMESPACE_DNS,
            namespace_source,
        )
    )


def normalize_base_url(url: str) -> str:
    normalized = url.strip().rstrip("/")

    if not normalized.startswith(
        ("https://", "http://")
    ):
        raise AgentError(
            "A URL deve começar com https:// ou http://"
        )

    return normalized


def post_json(
    url: str,
    payload: dict[str, Any],
    timeout: int = 20,
) -> dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")

    request = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Accept": "application/json",
            "Content-Type": "application/json",
            "User-Agent":
                f"dns-center-agent/{AGENT_VERSION}",
        },
    )

    try:
        with urllib.request.urlopen(
            request,
            timeout=timeout,
        ) as response:
            content = response.read().decode(
                "utf-8"
            )

            return json.loads(content)
    except urllib.error.HTTPError as exception:
        content = exception.read().decode(
            "utf-8",
            errors="replace",
        )

        try:
            details = json.loads(content)
        except json.JSONDecodeError:
            details = {
                "message": content
                or str(exception),
            }

        message = details.get(
            "message",
            "Falha HTTP durante o registro.",
        )

        errors = details.get("errors")

        if isinstance(errors, dict):
            first_error = next(
                iter(errors.values()),
                None,
            )

            if (
                isinstance(first_error, list)
                and first_error
            ):
                message = str(first_error[0])

        raise AgentError(
            f"Registro recusado: {message}"
        ) from exception
    except urllib.error.URLError as exception:
        raise AgentError(
            f"Não foi possível acessar o painel: "
            f"{exception.reason}"
        ) from exception
    except json.JSONDecodeError as exception:
        raise AgentError(
            "O painel retornou uma resposta inválida."
        ) from exception


def save_config(
    config_path: Path,
    data: dict[str, Any],
) -> None:
    config_path.parent.mkdir(
        parents=True,
        exist_ok=True,
        mode=0o700,
    )

    temporary_path = config_path.with_suffix(
        ".tmp"
    )

    temporary_path.write_text(
        json.dumps(
            data,
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )

    os.chmod(temporary_path, 0o600)
    temporary_path.replace(config_path)
    os.chmod(config_path, 0o600)


def enroll(args: argparse.Namespace) -> int:
    config_path = Path(args.config)
    base_url = normalize_base_url(args.url)
    agent_uuid = build_agent_uuid(config_path)
    fingerprint = build_fingerprint()

    payload = {
        "activation_code": args.code,
        "agent_uuid": agent_uuid,
        "fingerprint": fingerprint,
        "hostname": socket.getfqdn()
        or socket.gethostname(),
        "agent_version": AGENT_VERSION,
    }

    result = post_json(
        f"{base_url}/api/agent/enroll",
        payload,
    )

    if not result.get("ok"):
        raise AgentError(
            result.get(
                "message",
                "O registro não foi concluído.",
            )
        )

    agent = result.get("agent") or {}
    server = result.get("server") or {}

    token = agent.get("token")

    if not isinstance(token, str) or not token:
        raise AgentError(
            "O painel não retornou a credencial."
        )

    configuration = {
        "version": 1,
        "panel_url": base_url,
        "agent_uuid": agent_uuid,
        "agent_token": token,
        "fingerprint": fingerprint,
        "server": {
            "id": server.get("id"),
            "name": server.get("name"),
            "hostname": server.get("hostname"),
            "role": server.get("role"),
        },
    }

    save_config(config_path, configuration)

    print("Agente registrado com sucesso.")
    print(f"Servidor: {server.get('name')}")
    print(f"UUID: {agent_uuid}")
    print(f"Configuração: {config_path}")
    print("Permissão da configuração: 0600")

    return 0


def show_status(args: argparse.Namespace) -> int:
    config_path = Path(args.config)

    if not config_path.exists():
        print("Agente ainda não registrado.")
        return 1

    mode = stat.S_IMODE(
        config_path.stat().st_mode
    )

    try:
        data = json.loads(
            config_path.read_text(
                encoding="utf-8"
            )
        )
    except (
        OSError,
        json.JSONDecodeError,
    ) as exception:
        raise AgentError(
            f"Configuração inválida: {exception}"
        ) from exception

    print("Agente registrado.")
    print(
        f"Servidor: "
        f"{data.get('server', {}).get('name')}"
    )
    print(f"UUID: {data.get('agent_uuid')}")
    print(f"Painel: {data.get('panel_url')}")
    print(f"Permissão: {oct(mode)}")

    if mode != 0o600:
        print(
            "AVISO: a configuração deve usar 0600."
        )

    return 0


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Agente do DNS Center"
    )

    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG),
        help="Arquivo local de configuração.",
    )

    parser.add_argument(
        "--version",
        action="version",
        version=AGENT_VERSION,
    )

    actions = parser.add_mutually_exclusive_group(
        required=True
    )

    actions.add_argument(
        "--enroll",
        action="store_true",
        help="Registrar o agente no painel.",
    )

    actions.add_argument(
        "--status",
        action="store_true",
        help="Mostrar o estado local.",
    )

    parser.add_argument(
        "--url",
        help="URL base do DNS Center.",
    )

    parser.add_argument(
        "--code",
        help="Código temporário de ativação.",
    )

    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()

    try:
        if args.enroll:
            if not args.url or not args.code:
                parser.error(
                    "--enroll exige --url e --code"
                )

            return enroll(args)

        if args.status:
            return show_status(args)

        parser.error("Nenhuma operação informada.")
    except AgentError as exception:
        print(
            f"ERRO: {exception}",
            file=sys.stderr,
        )

        return 1

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
