#!/usr/bin/env python3

from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

AGENT_VERSION = "0.2.0"
DEFAULT_CONFIG = Path("/etc/dns-center-agent/agent.json")
DEFAULT_STATE_DIR = Path("/var/lib/dns-center-agent")
DEFAULT_ZONES_DIR = Path("/etc/bind/dns-center-zones")
DEFAULT_INCLUDE = Path("/etc/bind/dns-center-managed.conf")
DEFAULT_BACKUP_DIR = Path("/var/backups/dns-center-agent")


class AgentError(RuntimeError):
    pass


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def read_machine_id() -> str:
    for path in (
        Path("/etc/machine-id"),
        Path("/var/lib/dbus/machine-id"),
    ):
        try:
            value = path.read_text(encoding="utf-8").strip()
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

    return hashlib.sha256(source.encode("utf-8")).hexdigest()


def normalize_base_url(url: str) -> str:
    normalized = url.strip().rstrip("/")

    if not normalized.startswith(("https://", "http://")):
        raise AgentError("A URL deve começar com https:// ou http://")

    return normalized


def read_json(path: Path) -> dict[str, Any]:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except FileNotFoundError as exception:
        raise AgentError(f"Configuração não encontrada: {path}") from exception
    except (OSError, json.JSONDecodeError) as exception:
        raise AgentError(f"Configuração inválida: {path}") from exception

    if not isinstance(payload, dict):
        raise AgentError("A configuração deve ser um objeto JSON.")

    return payload


def atomic_write(
    path: Path,
    content: str,
    mode: int = 0o600,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)

    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{path.name}.",
        dir=str(path.parent),
    )

    temporary = Path(temporary_name)

    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            handle.write(content)
            handle.flush()
            os.fsync(handle.fileno())

        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if temporary.exists():
            temporary.unlink(missing_ok=True)


def save_config(path: Path, payload: dict[str, Any]) -> None:
    atomic_write(
        path,
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        0o600,
    )


def request_json(
    method: str,
    url: str,
    payload: dict[str, Any] | None = None,
    token: str | None = None,
    timeout: int = 30,
) -> dict[str, Any]:
    body = None

    if payload is not None:
        body = json.dumps(payload).encode("utf-8")

    headers = {
        "Accept": "application/json",
        "User-Agent": f"dns-center-agent/{AGENT_VERSION}",
    }

    if payload is not None:
        headers["Content-Type"] = "application/json"

    if token:
        headers["Authorization"] = f"Bearer {token}"

    request = urllib.request.Request(
        url,
        data=body,
        method=method,
        headers=headers,
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            content = response.read().decode("utf-8")

            if not content:
                return {}

            parsed = json.loads(content)

            if not isinstance(parsed, dict):
                raise AgentError("Resposta JSON inesperada.")

            return parsed
    except urllib.error.HTTPError as exception:
        content = exception.read().decode("utf-8", errors="replace")

        try:
            details = json.loads(content)
        except json.JSONDecodeError:
            details = {"message": content or str(exception)}

        message = details.get("message", str(exception))
        raise AgentError(
            f"HTTP {exception.code}: {message}"
        ) from exception
    except urllib.error.URLError as exception:
        raise AgentError(
            f"Falha de conexão: {exception.reason}"
        ) from exception
    except json.JSONDecodeError as exception:
        raise AgentError("Resposta JSON inválida.") from exception


def request_text(
    url: str,
    token: str,
    timeout: int = 30,
) -> str:
    request = urllib.request.Request(
        url,
        method="GET",
        headers={
            "Accept": "text/plain",
            "Authorization": f"Bearer {token}",
            "User-Agent": f"dns-center-agent/{AGENT_VERSION}",
        },
    )

    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            return response.read().decode("utf-8")
    except urllib.error.HTTPError as exception:
        content = exception.read().decode("utf-8", errors="replace")
        raise AgentError(
            f"HTTP {exception.code}: {content or exception.reason}"
        ) from exception
    except urllib.error.URLError as exception:
        raise AgentError(
            f"Falha de conexão: {exception.reason}"
        ) from exception


def run_command(command: list[str]) -> subprocess.CompletedProcess[str]:
    try:
        return subprocess.run(
            command,
            check=False,
            text=True,
            capture_output=True,
        )
    except OSError as exception:
        raise AgentError(
            f"Não foi possível executar {command[0]}: {exception}"
        ) from exception


def command_path(config: dict[str, Any], key: str, default: str) -> str:
    value = str(config.get(key, default))

    if not value.startswith("/"):
        raise AgentError(f"{key} deve usar caminho absoluto.")

    return value


def config_paths(config: dict[str, Any]) -> dict[str, Path]:
    return {
        "state_dir": Path(
            config.get("state_dir", str(DEFAULT_STATE_DIR))
        ),
        "zones_dir": Path(
            config.get("zones_dir", str(DEFAULT_ZONES_DIR))
        ),
        "managed_include": Path(
            config.get("managed_include", str(DEFAULT_INCLUDE))
        ),
        "backup_dir": Path(
            config.get("backup_dir", str(DEFAULT_BACKUP_DIR))
        ),
    }


def agent_uuid(config_path: Path) -> str:
    if config_path.exists():
        current = read_json(config_path)
        value = current.get("agent_uuid")

        if isinstance(value, str):
            try:
                return str(uuid.UUID(value))
            except ValueError:
                pass

    source = read_machine_id() + "|" + socket.gethostname()

    return str(uuid.uuid5(uuid.NAMESPACE_DNS, source))


def enroll(args: argparse.Namespace) -> int:
    config_path = Path(args.config)

    payload = request_json(
        "POST",
        normalize_base_url(args.url) + "/api/agent/enroll",
        {
            "activation_code": args.code,
            "agent_uuid": agent_uuid(config_path),
            "fingerprint": build_fingerprint(),
            "hostname": socket.gethostname(),
            "agent_version": AGENT_VERSION,
        },
    )

    token = payload.get("agent", {}).get("token")
    assigned_uuid = payload.get("agent", {}).get("uuid")

    if not isinstance(token, str) or not token:
        raise AgentError("O painel não retornou o token permanente.")

    config = {
        "base_url": normalize_base_url(args.url),
        "token": token,
        "agent_uuid": assigned_uuid,
        "server": payload.get("server", {}),
        "state_dir": str(DEFAULT_STATE_DIR),
        "zones_dir": str(DEFAULT_ZONES_DIR),
        "managed_include": str(DEFAULT_INCLUDE),
        "backup_dir": str(DEFAULT_BACKUP_DIR),
        "named_checkzone": "/usr/bin/named-checkzone",
        "named_checkconf": "/usr/bin/named-checkconf",
        "rndc": "/usr/sbin/rndc",
        "enrolled_at": utc_now(),
    }

    save_config(config_path, config)

    print("Agente registrado com sucesso.")
    print(f"Configuração: {config_path}")

    return 0


def status(args: argparse.Namespace) -> int:
    config_path = Path(args.config)
    config = read_json(config_path)
    paths = config_paths(config)

    output = {
        "version": AGENT_VERSION,
        "config": str(config_path),
        "server": config.get("server", {}),
        "paths": {key: str(value) for key, value in paths.items()},
        "last_state": None,
    }

    state_file = paths["state_dir"] / "state.json"

    if state_file.exists():
        try:
            output["last_state"] = read_json(state_file)
        except AgentError:
            output["last_state"] = {"error": "state inválido"}

    print(json.dumps(output, indent=2, ensure_ascii=False))

    return 0


def heartbeat(config: dict[str, Any]) -> dict[str, Any]:
    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/heartbeat",
        {
            "hostname": socket.gethostname(),
            "agent_version": AGENT_VERSION,
            "status": "online",
            "capabilities": {
                "bind_authoritative": True,
                "zone_staging": True,
                "named_checkzone": True,
                "named_checkconf": True,
                "atomic_apply": True,
                "rollback": True,
            },
        },
        str(config["token"]),
    )


def inventory(config: dict[str, Any]) -> dict[str, Any]:
    named = run_command(["/usr/sbin/named", "-v"])

    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/inventory",
        {
            "hostname": socket.gethostname(),
            "operating_system": platform.system(),
            "operating_system_version": platform.release(),
            "bind_version": (
                named.stdout.strip()
                or named.stderr.strip()
                or None
            ),
            "agent_version": AGENT_VERSION,
            "capabilities": {
                "bind_authoritative": True,
                "zone_staging": True,
                "named_checkzone": True,
                "named_checkconf": True,
                "atomic_apply": True,
                "rollback": True,
            },
            "inventory": {
                "machine": platform.machine(),
                "python": platform.python_version(),
                "processor": platform.processor(),
            },
        },
        str(config["token"]),
    )


def safe_zone_filename(name: str) -> str:
    normalized = name.strip().lower().rstrip(".")

    if not normalized:
        raise AgentError("Nome de zona vazio.")

    allowed = set("abcdefghijklmnopqrstuvwxyz0123456789.-")

    if any(character not in allowed for character in normalized):
        raise AgentError(f"Nome de zona inseguro: {name}")

    if ".." in normalized or normalized.startswith("."):
        raise AgentError(f"Nome de zona inseguro: {name}")

    return normalized + ".zone"


def render_managed_include(
    manifest: list[dict[str, Any]],
    zones_dir: Path,
) -> str:
    lines = [
        "// Managed by DNS Center",
        "// Do not edit manually.",
        "",
    ]

    for item in manifest:
        name = str(item["name"]).rstrip(".")
        zone_type = str(item.get("type", "primary"))
        filename = safe_zone_filename(name)
        bind_type = "master" if zone_type == "primary" else "slave"

        lines.extend(
            [
                f'zone "{name}" {{',
                f"    type {bind_type};",
                f'    file "{zones_dir / filename}";',
                "};",
                "",
            ]
        )

    return "\n".join(lines)


def validate_staging(
    config: dict[str, Any],
    manifest: list[dict[str, Any]],
    staging_dir: Path,
    include_path: Path,
) -> None:
    named_checkzone = command_path(
        config,
        "named_checkzone",
        "/usr/bin/named-checkzone",
    )
    named_checkconf = command_path(
        config,
        "named_checkconf",
        "/usr/bin/named-checkconf",
    )

    for item in manifest:
        name = str(item["name"]).rstrip(".")
        zonefile = staging_dir / safe_zone_filename(name)
        result = run_command(
            [named_checkzone, name, str(zonefile)]
        )

        if result.returncode != 0:
            raise AgentError(
                "named-checkzone falhou para "
                f"{name}: {result.stderr.strip()}"
            )

    result = run_command([named_checkconf, str(include_path)])

    if result.returncode != 0:
        raise AgentError(
            "named-checkconf falhou: "
            f"{result.stderr.strip()}"
        )


def build_staging(
    config: dict[str, Any],
) -> tuple[list[dict[str, Any]], Path, Path]:
    base_url = normalize_base_url(str(config["base_url"]))
    token = str(config["token"])
    paths = config_paths(config)

    response = request_json(
        "GET",
        base_url + "/api/agent/zones",
        token=token,
    )

    manifest = response.get("zones")

    if not isinstance(manifest, list):
        raise AgentError("Manifesto de zonas inválido.")

    state_dir = paths["state_dir"]
    state_dir.mkdir(parents=True, exist_ok=True)

    staging_dir = Path(
        tempfile.mkdtemp(
            prefix="staging-",
            dir=str(state_dir),
        )
    )

    for item in manifest:
        if not isinstance(item, dict):
            raise AgentError("Entrada inválida no manifesto.")

        name = str(item.get("name", ""))
        artifact_url = str(item.get("artifact_url", ""))

        if not artifact_url.startswith("/"):
            raise AgentError(
                f"URL de artefato inválida para {name}."
            )

        content = request_text(
            base_url + artifact_url,
            token,
        )

        atomic_write(
            staging_dir / safe_zone_filename(name),
            content,
            0o640,
        )

    include_path = staging_dir / "dns-center-managed.conf"
    include_content = render_managed_include(
        manifest,
        paths["zones_dir"],
    )
    atomic_write(include_path, include_content, 0o640)

    validate_staging(
        config,
        manifest,
        staging_dir,
        include_path,
    )

    return manifest, staging_dir, include_path


def backup_current(paths: dict[str, Path]) -> Path:
    backup_dir = paths["backup_dir"] / datetime.now().strftime(
        "%Y%m%d-%H%M%S"
    )
    backup_dir.mkdir(parents=True, exist_ok=False)

    if paths["managed_include"].exists():
        shutil.copy2(
            paths["managed_include"],
            backup_dir / "dns-center-managed.conf",
        )

    zones_backup = backup_dir / "zones"
    zones_backup.mkdir()

    if paths["zones_dir"].exists():
        for zonefile in paths["zones_dir"].glob("*.zone"):
            if zonefile.is_file():
                shutil.copy2(zonefile, zones_backup / zonefile.name)

    return backup_dir


def restore_backup(
    paths: dict[str, Path],
    backup_dir: Path,
) -> None:
    include_backup = backup_dir / "dns-center-managed.conf"

    if include_backup.exists():
        shutil.copy2(include_backup, paths["managed_include"])
    else:
        paths["managed_include"].unlink(missing_ok=True)

    paths["zones_dir"].mkdir(parents=True, exist_ok=True)

    for current in paths["zones_dir"].glob("*.zone"):
        current.unlink()

    zones_backup = backup_dir / "zones"

    if zones_backup.exists():
        for zonefile in zones_backup.glob("*.zone"):
            shutil.copy2(zonefile, paths["zones_dir"] / zonefile.name)


def apply_staging(
    config: dict[str, Any],
    manifest: list[dict[str, Any]],
    staging_dir: Path,
    include_path: Path,
) -> Path:
    paths = config_paths(config)
    paths["zones_dir"].mkdir(parents=True, exist_ok=True)
    paths["managed_include"].parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    backup_dir = backup_current(paths)

    try:
        expected = set()

        for item in manifest:
            name = str(item["name"])
            filename = safe_zone_filename(name)
            expected.add(filename)

            source = staging_dir / filename
            target = paths["zones_dir"] / filename

            atomic_write(
                target,
                source.read_text(encoding="utf-8"),
                0o640,
            )

        for current in paths["zones_dir"].glob("*.zone"):
            if current.name not in expected:
                current.unlink()

        atomic_write(
            paths["managed_include"],
            include_path.read_text(encoding="utf-8"),
            0o640,
        )

        named_checkconf = command_path(
            config,
            "named_checkconf",
            "/usr/bin/named-checkconf",
        )
        result = run_command(
            [named_checkconf, str(paths["managed_include"])]
        )

        if result.returncode != 0:
            raise AgentError(
                "Validação final falhou: "
                f"{result.stderr.strip()}"
            )

        rndc = command_path(config, "rndc", "/usr/sbin/rndc")
        result = run_command([rndc, "reconfig"])

        if result.returncode != 0:
            raise AgentError(
                "rndc reconfig falhou: "
                f"{result.stderr.strip()}"
            )

        return backup_dir
    except Exception:
        restore_backup(paths, backup_dir)

        try:
            rndc = command_path(config, "rndc", "/usr/sbin/rndc")
            run_command([rndc, "reconfig"])
        except AgentError:
            pass

        raise


def sync_zones(
    config: dict[str, Any],
    apply: bool,
    confirmation: str | None,
) -> dict[str, Any]:
    manifest, staging_dir, include_path = build_staging(config)
    paths = config_paths(config)

    result: dict[str, Any] = {
        "status": "validated",
        "dry_run": not apply,
        "zones": len(manifest),
        "staging_dir": str(staging_dir),
        "managed_include_preview": str(include_path),
        "created_at": utc_now(),
    }

    if apply:
        server_name = str(
            config.get("server", {}).get("name", "")
        )
        expected = f"APLICAR ZONAS {server_name}".strip()

        if os.environ.get("DNS_CENTER_AGENT_ALLOW_APPLY") != "1":
            raise AgentError(
                "Apply bloqueado: defina "
                "DNS_CENTER_AGENT_ALLOW_APPLY=1."
            )

        if confirmation != expected:
            raise AgentError(
                f"Confirmação inválida. Use: {expected}"
            )

        if os.geteuid() != 0:
            raise AgentError("Apply exige execução como root.")

        backup_dir = apply_staging(
            config,
            manifest,
            staging_dir,
            include_path,
        )

        result.update(
            {
                "status": "applied",
                "dry_run": False,
                "backup_dir": str(backup_dir),
                "zones_dir": str(paths["zones_dir"]),
                "managed_include": str(
                    paths["managed_include"]
                ),
            }
        )

    state_dir = paths["state_dir"]
    state_dir.mkdir(parents=True, exist_ok=True)
    atomic_write(
        state_dir / "state.json",
        json.dumps(result, indent=2, ensure_ascii=False) + "\n",
        0o600,
    )

    return result


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Agente operacional do DNS Center"
    )

    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG),
        help="Arquivo JSON de configuração.",
    )

    parser.add_argument(
        "--version",
        action="version",
        version=AGENT_VERSION,
    )

    actions = parser.add_mutually_exclusive_group(required=True)

    actions.add_argument("--enroll", action="store_true")
    actions.add_argument("--status", action="store_true")
    actions.add_argument("--heartbeat", action="store_true")
    actions.add_argument("--inventory", action="store_true")
    actions.add_argument("--sync-zones", action="store_true")

    parser.add_argument("--url")
    parser.add_argument("--code")
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--confirm")

    return parser


def main() -> int:
    args = build_parser().parse_args()

    try:
        if args.enroll:
            if not args.url or not args.code:
                raise AgentError(
                    "--enroll exige --url e --code."
                )

            return enroll(args)

        if args.status:
            return status(args)

        config = read_json(Path(args.config))

        if args.heartbeat:
            print(
                json.dumps(
                    heartbeat(config),
                    indent=2,
                    ensure_ascii=False,
                )
            )
            return 0

        if args.inventory:
            print(
                json.dumps(
                    inventory(config),
                    indent=2,
                    ensure_ascii=False,
                )
            )
            return 0

        if args.sync_zones:
            print(
                json.dumps(
                    sync_zones(
                        config,
                        args.apply,
                        args.confirm,
                    ),
                    indent=2,
                    ensure_ascii=False,
                )
            )
            return 0

        raise AgentError("Operação não reconhecida.")
    except AgentError as exception:
        print(f"ERRO: {exception}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
