#!/usr/bin/env python3

from __future__ import annotations

import argparse
import base64
import filecmp
import hashlib
import html
import getpass
import ipaddress
import json
import logging
import os
import platform
import pwd
import grp
import re
import secrets
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from collections.abc import Callable
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from typing import Any

AGENT_VERSION = "0.7.1"
OFFICIAL_BASE_URL = "https://dnscenter.trevizamnetwork.com.br"
DEFAULT_CONFIG = Path("/etc/dns-center-agent/agent.json")
DEFAULT_STATE_DIR = Path("/var/lib/dns-center-agent")
DEFAULT_ZONES_DIR = Path("/etc/bind/dns-center-zones")
DEFAULT_INCLUDE = Path("/etc/bind/dns-center-managed.conf")
DEFAULT_BACKUP_DIR = Path("/var/backups/dns-center-agent")
DEFAULT_MAX_ARTIFACT_BYTES = 2 * 1024 * 1024
DEFAULT_RETRIES = 3
RETRYABLE_HTTP_CODES = {429, 500, 502, 503, 504}
EXPECTED_ARTIFACT_TYPES = {"text/plain", "application/octet-stream"}

LOGGER = logging.getLogger("dns-center-agent")


class AgentError(RuntimeError):
    pass


class AgentOperationError(AgentError):
    def __init__(self, message: str, rolled_back: bool = False) -> None:
        super().__init__(message)
        self.rolled_back = rolled_back


class AgentHttpError(AgentError):
    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        retry_after: float | None = None,
    ) -> None:
        super().__init__(f"HTTP {status}: {sanitize_message(message)}")
        self.status = status
        self.code = code
        self.retry_after = retry_after


def sanitize_message(message: object) -> str:
    sanitized = html.unescape(str(message))
    sanitized = re.sub(r"<[^>]*>", " ", sanitized)
    sanitized = re.sub(r"[\x00-\x1f\x7f]", " ", sanitized)
    sanitized = re.sub(
        # Consume the rest of the line, not just one whitespace-delimited
        # token — "Authorization: Bearer <jwt>" has the scheme and the
        # actual credential as two separate tokens, and a bare \S+ here
        # would redact only "Bearer", leaking the credential itself.
        r"\b(token|secret|password|authorization|api[_-]?key)\b"
        r"\s*[:=]?\s*\S.*",
        r"\1=[removido]",
        sanitized,
        flags=re.IGNORECASE,
    )
    sanitized = re.sub(
        r"(?<!\w)(?:/[A-Za-z0-9._-]+){2,}",
        "[caminho removido]",
        sanitized,
    )
    sanitized = re.sub(
        r"`[^`]*`|\$\([^)]*\)",
        "[comando removido]",
        sanitized,
    )
    sanitized = re.sub(
        r"\b(?:sudo|bash|sh|powershell|cmd(?:\.exe)?|rm|curl|wget)"
        r"\s+[^.;]*",
        "[comando removido]",
        sanitized,
        flags=re.IGNORECASE,
    )
    sanitized = re.sub(r"\s+", " ", sanitized).strip()

    return sanitized[:1000]


def safe_log(message: object) -> None:
    LOGGER.info("%s", sanitize_message(message))


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
    parsed = urllib.parse.urlparse(normalized)

    if not normalized.startswith(("https://", "http://")):
        raise AgentError("A URL deve começar com https:// ou http://")

    if (
        parsed.scheme == "http"
        and parsed.hostname not in {"127.0.0.1", "::1", "localhost"}
    ):
        raise AgentError(
            "HTTP sem TLS é permitido somente para localhost."
        )

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


def write_json_state(path: Path, payload: dict[str, Any], mode: int = 0o600) -> None:
    atomic_write(
        path,
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        mode,
    )


def save_config(path: Path, payload: dict[str, Any]) -> None:
    write_json_state(path, payload)


def request_json(
    method: str,
    url: str,
    payload: dict[str, Any] | None = None,
    token: str | None = None,
    timeout: int = 30,
    retries: int = DEFAULT_RETRIES,
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

    for attempt in range(retries):
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
            error = http_error(exception)

            if (
                error.status not in RETRYABLE_HTTP_CODES
                or attempt == retries - 1
            ):
                raise error from exception

            bounded_backoff(attempt, error.retry_after)
        except urllib.error.URLError as exception:
            if attempt == retries - 1:
                raise AgentError(
                    "Falha de conexão: "
                    + sanitize_message(exception.reason)
                ) from exception

            bounded_backoff(attempt)
        except json.JSONDecodeError as exception:
            raise AgentError("Resposta JSON inválida.") from exception

    raise AgentError("Tentativas de rede esgotadas.")


def retry_after_seconds(value: str | None) -> float | None:
    if value is None:
        return None

    try:
        return max(0.0, min(float(value), 60.0))
    except ValueError:
        return None


def bounded_backoff(
    attempt: int,
    retry_after: float | None = None,
) -> None:
    delay = retry_after if retry_after is not None else min(2 ** attempt, 30)
    time.sleep(delay)


def http_error(exception: urllib.error.HTTPError) -> AgentHttpError:
    content = exception.read(8192).decode("utf-8", errors="replace")

    try:
        details = json.loads(content)
    except json.JSONDecodeError:
        details = {}

    if not isinstance(details, dict):
        details = {}

    code = str(details.get("error", "http_error"))
    message = str(details.get("message", exception.reason))

    return AgentHttpError(
        exception.code,
        code,
        message,
        retry_after_seconds(exception.headers.get("Retry-After")),
    )


def _download_stream_to_file(
    response: Any,
    destination: Path,
    max_bytes: int,
    file_mode: int,
    declared_length: int | None,
    expected_checksum: str | None = None,
) -> tuple[str, int]:
    """Shared checksum-then-atomic-write core for a single already-open
    response, used by both request_artifact (authenticated, header-driven)
    and download_public_artifact (public installer endpoint, sidecar
    checksum). Returns (sha256_hex, size). If expected_checksum is given,
    it's verified before the file is moved into place."""
    destination.parent.mkdir(parents=True, exist_ok=True)
    reject_symlink(destination)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{destination.name}.",
        dir=str(destination.parent),
    )
    temporary = Path(temporary_name)
    digest = hashlib.sha256()
    size = 0

    try:
        with os.fdopen(descriptor, "wb") as handle:
            while True:
                chunk = response.read(min(65536, max_bytes + 1 - size))

                if not chunk:
                    break

                size += len(chunk)

                if size > max_bytes:
                    raise AgentError("Artefato excede o tamanho máximo.")

                digest.update(chunk)
                handle.write(chunk)

            handle.flush()
            os.fsync(handle.fileno())

        if declared_length is not None and size != declared_length:
            raise AgentError("Download truncado do artefato.")

        checksum = digest.hexdigest()

        if expected_checksum is not None and checksum != expected_checksum:
            raise AgentError("Checksum não confere para o artefato baixado.")

        os.chmod(temporary, file_mode)
        os.replace(temporary, destination)
    finally:
        temporary.unlink(missing_ok=True)

    return digest.hexdigest(), size


def request_artifact(
    url: str,
    token: str,
    destination: Path,
    max_bytes: int = DEFAULT_MAX_ARTIFACT_BYTES,
    timeout: int = 30,
    retries: int = DEFAULT_RETRIES,
) -> dict[str, Any]:
    request = urllib.request.Request(
        url,
        method="GET",
        headers={
            "Accept": "text/plain",
            "Authorization": f"Bearer {token}",
            "User-Agent": f"dns-center-agent/{AGENT_VERSION}",
        },
    )

    for attempt in range(retries):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                content_type = (
                    response.headers.get_content_type()
                    if response.headers.get("Content-Type")
                    else None
                )

                if (
                    content_type is not None
                    and content_type not in EXPECTED_ARTIFACT_TYPES
                ):
                    raise AgentError("Content-Type do artefato inválido.")

                content_length = response.headers.get("Content-Length")
                declared_length = None

                if content_length is not None:
                    try:
                        declared_length = int(content_length)
                    except ValueError as exception:
                        raise AgentError(
                            "Content-Length do artefato inválido."
                        ) from exception

                    if declared_length < 0 or declared_length > max_bytes:
                        raise AgentError(
                            "Artefato excede o tamanho máximo."
                        )

                checksum, size = _download_stream_to_file(
                    response, destination, max_bytes, 0o640, declared_length
                )

                return {
                    "checksum": checksum,
                    "size": size,
                    "content_type": content_type,
                    "publication_id": response.headers.get(
                        "X-DNS-Publication-Id"
                    ),
                    "version": response.headers.get("X-DNS-Zone-Version"),
                    "server_checksum": response.headers.get(
                        "X-DNS-Artifact-SHA256"
                    ),
                }
        except urllib.error.HTTPError as exception:
            error = http_error(exception)

            if (
                error.status not in RETRYABLE_HTTP_CODES
                or attempt == retries - 1
            ):
                raise error from exception

            bounded_backoff(attempt, error.retry_after)
        except urllib.error.URLError as exception:
            if attempt == retries - 1:
                raise AgentError(
                    "Falha de conexão: "
                    + sanitize_message(exception.reason)
                ) from exception

            bounded_backoff(attempt)

    raise AgentError("Tentativas de download esgotadas.")


def run_command(
    command: list[str],
    timeout: int = 30,
    max_output_bytes: int = 8000,
) -> subprocess.CompletedProcess[str]:
    """Run a fixed argv list with shell disabled.

    `max_output_bytes` bounds stdout/stderr for the *default* short
    diagnostic case (rndc status, checkconf errors, ...). Callers that
    legitimately expect large output (e.g. a full zone dump) must pass an
    explicit, larger limit — never rely on the 8000-byte default, which
    would otherwise silently truncate mid-record. Truncation is exposed via
    `result.stdout_truncated`/`result.stderr_truncated` so callers can
    detect and refuse a partial capture instead of parsing it as complete.
    """
    try:
        result = subprocess.run(
            command,
            check=False,
            text=True,
            capture_output=True,
            timeout=timeout,
            shell=False,
        )
        result.stdout_truncated = len(result.stdout) > max_output_bytes
        result.stderr_truncated = len(result.stderr) > max_output_bytes
        result.stdout = result.stdout[:max_output_bytes]
        result.stderr = result.stderr[:max_output_bytes]
        return result
    except subprocess.TimeoutExpired as exception:
        raise AgentError(
            f"Tempo limite excedido ao executar {command[0]}."
        ) from exception
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
    family = os_family()
    defaults = (
        bind_paths(family)
        if family in {"debian", "rhel"}
        else {
            "named_conf": Path("/etc/bind/named.conf"),
            "options_config": Path("/etc/bind/named.conf.options"),
            "managed_options_include": Path(
                "/etc/bind/dns-center-options.conf"
            ),
        }
    )
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
        "named_conf": Path(
            config.get("named_conf", str(defaults["named_conf"]))
        ),
        "options_config": Path(
            config.get(
                "options_config",
                str(defaults["options_config"]),
            )
        ),
        "managed_options_include": Path(
            config.get(
                "managed_options_include",
                str(defaults["managed_options_include"]),
            )
        ),
    }


def os_release() -> dict[str, str]:
    values: dict[str, str] = {}

    try:
        lines = Path("/etc/os-release").read_text(
            encoding="utf-8"
        ).splitlines()
    except OSError:
        return values

    for line in lines:
        if "=" in line:
            key, value = line.split("=", 1)
            values[key] = value.strip().strip('"')

    return values


def os_family() -> str:
    release = os_release()
    identifiers = " ".join(
        [release.get("ID", ""), release.get("ID_LIKE", "")]
    ).lower()

    if any(item in identifiers for item in ("debian", "ubuntu")):
        return "debian"

    if any(
        item in identifiers
        for item in ("rhel", "fedora", "centos", "rocky", "almalinux")
    ):
        return "rhel"

    return "unsupported"


SYSTEMCTL_CANDIDATES = ("/usr/bin/systemctl", "/bin/systemctl")
NAMED_CANDIDATES = ("/usr/sbin/named", "/usr/bin/named")
RNDC_CANDIDATES = ("/usr/sbin/rndc", "/usr/bin/rndc")
DIG_CANDIDATES = ("/usr/bin/dig", "/usr/local/bin/dig")
NAMED_CHECKCONF_CANDIDATES = (
    "/usr/bin/named-checkconf",
    "/usr/sbin/named-checkconf",
)
NAMED_CHECKZONE_CANDIDATES = (
    "/usr/bin/named-checkzone",
    "/usr/sbin/named-checkzone",
)


def detected_binary(candidates: tuple[str, ...]) -> str | None:
    for candidate in candidates:
        path = Path(candidate)

        if path.is_file() and os.access(path, os.X_OK):
            return str(path)

    return None


def detected_named_conf() -> str | None:
    for candidate in ("/etc/bind/named.conf", "/etc/named.conf"):
        if Path(candidate).is_file():
            return candidate

    return None


def port_53_listeners() -> dict[str, bool]:
    def present(files: tuple[str, ...]) -> bool:
        for filename in files:
            try:
                lines = Path(filename).read_text(
                    encoding="ascii"
                ).splitlines()[1:]
            except OSError:
                continue

            for line in lines:
                fields = line.split()

                if len(fields) > 1 and fields[1].endswith(":0035"):
                    return True

        return False

    return {
        "tcp_53": present(("/proc/net/tcp", "/proc/net/tcp6")),
        "udp_53": present(("/proc/net/udp", "/proc/net/udp6")),
    }


def service_details(family: str) -> dict[str, Any]:
    service_name = "bind9" if family == "debian" else "named"
    systemctl = detected_binary(SYSTEMCTL_CANDIDATES)
    active = False

    if systemctl and family != "unsupported":
        active = (
            run_command(
                [systemctl, "is-active", "--quiet", service_name],
                timeout=10,
            ).returncode
            == 0
        )

    user = "bind" if family == "debian" else "named"

    try:
        account = pwd.getpwnam(user)
        group = grp.getgrgid(account.pw_gid).gr_name
    except KeyError:
        user = None
        group = None

    return {
        "name": service_name if family != "unsupported" else None,
        "active": active,
        "user": user,
        "group": group,
    }


def security_modules() -> dict[str, str]:
    apparmor = "absent"

    if Path("/sys/module/apparmor").exists():
        apparmor = "present"

        try:
            enabled = Path(
                "/sys/module/apparmor/parameters/enabled"
            ).read_text(encoding="ascii").strip().upper()

            if enabled == "Y":
                apparmor = "enforcing"
        except OSError:
            pass

    selinux = "absent"
    getenforce = detected_binary(
        ("/usr/sbin/getenforce", "/usr/bin/getenforce")
    )

    if getenforce:
        value = run_command(
            [getenforce],
            timeout=10,
        ).stdout.strip().lower()
        selinux = value if value in {
            "enforcing",
            "permissive",
            "disabled",
        } else "unknown"

    return {"apparmor": apparmor, "selinux": selinux}


def readiness_report() -> dict[str, Any]:
    family = os_family()
    named = detected_binary(NAMED_CANDIDATES)
    named_checkconf = detected_binary(NAMED_CHECKCONF_CANDIDATES)
    named_checkzone = detected_binary(NAMED_CHECKZONE_CANDIDATES)
    rndc = detected_binary(RNDC_CANDIDATES)
    named_conf = detected_named_conf()
    zones_dir = (
        "/etc/bind/dns-center-zones"
        if family == "debian"
        else "/var/named/dns-center-zones"
    )
    include_dir = str(Path(named_conf).parent) if named_conf else None
    bind_version = None

    if named:
        result = run_command([named, "-v"], timeout=10)
        bind_version = (
            result.stdout.strip() or result.stderr.strip() or None
        )

    try:
        ipv4 = bool(
            socket.getaddrinfo(
                socket.gethostname(),
                None,
                socket.AF_INET,
            )
        )
    except socket.gaierror:
        ipv4 = False

    ipv6 = False

    try:
        ipv6_addresses = Path("/proc/net/if_inet6").read_text(
            encoding="ascii"
        ).splitlines()
        ipv6 = any(
            line.split()[0]
            != "00000000000000000000000000000001"
            for line in ipv6_addresses
            if line.split()
        )
    except OSError:
        pass

    return {
        "event_id": str(uuid.uuid4()),
        "detected_at": utc_now(),
        "os_family": family,
        "bind_installed": named is not None,
        "bind_version": bind_version,
        "paths": {
            "named_conf": named_conf,
            "include_dir": include_dir,
            "zones_dir": zones_dir,
            "named_checkconf": named_checkconf,
            "named_checkzone": named_checkzone,
            "rndc": rndc,
        },
        "service": service_details(family),
        "listeners": port_53_listeners(),
        "network": {
            "ipv4": ipv4,
            "ipv6": ipv6,
        },
        "security": security_modules(),
        "permissions": {
            "can_manage_include": bool(
                include_dir and os.access(include_dir, os.W_OK)
            ),
            "can_manage_zones": os.access(
                str(Path(zones_dir).parent),
                os.W_OK,
            ),
        },
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


def ensure_approval_timer() -> None:
    unit = Path("/etc/systemd/system/dns-center-agent-approval.timer")
    systemctl = detected_binary(SYSTEMCTL_CANDIDATES)
    if os.geteuid() != 0 or not unit.is_file() or not systemctl:
        return

    enabled = run_command(
        [systemctl, "is-enabled", "--quiet", unit.name], timeout=30
    )
    if enabled.returncode == 0:
        return

    result = run_command(
        [systemctl, "enable", "--now", unit.name], timeout=30
    )
    if result.returncode != 0:
        raise AgentError("Não foi possível habilitar o timer de aprovação.")


def request_approval(args: argparse.Namespace) -> int:
    config_path = Path(args.config)
    pending_path = config_path.with_name("install-request.json")

    enrollment_mode = bool(getattr(args, "enroll", False))
    enrollment_code: str | None = None
    if enrollment_mode:
        if config_path.exists():
            raise AgentError(
                "Este agente já possui credencial ativa; use o fluxo explícito de rotação."
            )
        if getattr(args, "stdin", False):
            enrollment_code = sys.stdin.readline().strip()
        elif sys.stdin.isatty():
            enrollment_code = getpass.getpass("Código temporário de vínculo: ").strip()
        else:
            raise AgentError("Use --stdin ou um terminal interativo para informar o código.")
        if len(enrollment_code) < 32:
            raise AgentError("Código temporário inválido.")

    if config_path.exists():
        raise AgentError("Este agente já possui uma credencial ativa.")

    if pending_path.exists():
        pending = read_json(pending_path)
        if enrollment_code:
            supplied_hash = hashlib.sha256(
                enrollment_code.encode("utf-8")
            ).hexdigest()
            if pending.get("enrollment_code_hash") != supplied_hash:
                archived = pending_path.with_name(
                    "install-request.previous.json"
                )
                os.replace(pending_path, archived)
                pending = {}

    else:
        pending = {}

    if pending:
        base_url = normalize_base_url(
            str(pending.get("base_url", OFFICIAL_BASE_URL))
        )
    else:
        base_url = normalize_base_url(
            os.environ.get("DNS_CENTER_PANEL_URL", OFFICIAL_BASE_URL)
        )
        pending = {
            "request_id": str(uuid.uuid4()),
            "request_token": secrets.token_urlsafe(48),
            "agent_uuid": agent_uuid(config_path),
            "fingerprint": build_fingerprint(),
            "hostname": socket.gethostname(),
            "base_url": base_url,
            "created_at": utc_now(),
        }
        if enrollment_code:
            pending["enrollment_code"] = enrollment_code
            pending["enrollment_code_hash"] = hashlib.sha256(
                enrollment_code.encode("utf-8")
            ).hexdigest()
        save_config(pending_path, pending)

    release = os_release()
    payload = request_json(
        "POST",
        base_url + "/api/agent/install-requests",
        {
            **pending,
            "agent_version": AGENT_VERSION,
            "operating_system": release.get("NAME"),
            "operating_system_version": release.get("VERSION_ID"),
        },
    )

    if (
        payload.get("ok") is not True
        or payload.get("status") not in {"pending", "approved"}
        or payload.get("request_id") != pending["request_id"]
    ):
        raise AgentError(
            "O painel não confirmou a persistência da solicitação."
        )

    if "enrollment_code" in pending:
        pending.pop("enrollment_code", None)
        save_config(pending_path, pending)

    print("Solicitação enviada ao painel.")
    print(f"Identificador: {payload.get('request_id', pending['request_id'])}")
    print("Aguardando aprovação administrativa.")
    ensure_approval_timer()

    wait_seconds = max(0, min(int(args.wait), 86400))
    deadline = time.monotonic() + wait_seconds

    while wait_seconds > 0 and time.monotonic() < deadline:
        result = request_json(
            "POST",
            base_url + "/api/agent/install-requests/status",
            {
                "request_id": pending["request_id"],
                "request_token": pending["request_token"],
            },
        )
        status_value = result.get("status")

        if status_value == "approved":
            token = result.get("agent", {}).get("token")
            assigned_uuid = result.get("agent", {}).get("uuid")

            if not isinstance(token, str) or not token:
                raise AgentError("O painel não retornou a credencial permanente.")

            config = {
                "base_url": base_url,
                "token": token,
                "agent_uuid": assigned_uuid,
                "server": result.get("server", {}),
                "state_dir": str(DEFAULT_STATE_DIR),
                "zones_dir": str(DEFAULT_ZONES_DIR),
                "managed_include": str(DEFAULT_INCLUDE),
                "backup_dir": str(DEFAULT_BACKUP_DIR),
                "named_checkzone": "/usr/bin/named-checkzone",
                "named_checkconf": "/usr/bin/named-checkconf",
                "rndc": "/usr/sbin/rndc",
                "request_timeout": 30,
                "max_artifact_bytes": DEFAULT_MAX_ARTIFACT_BYTES,
                "enrolled_at": utc_now(),
            }
            save_config(config_path, config)
            pending_path.unlink(missing_ok=True)
            print("Vínculo aprovado e agente registrado.")
            return 0

        if status_value in {"rejected", "expired"}:
            raise AgentError(f"Solicitação {status_value} pelo painel.")

        time.sleep(5)

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
    named = detected_binary(NAMED_CANDIDATES)
    named_checkzone = detected_binary(NAMED_CHECKZONE_CANDIDATES)
    named_checkconf = detected_binary(NAMED_CHECKCONF_CANDIDATES)

    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/heartbeat",
        {
            "hostname": socket.gethostname(),
            "agent_version": AGENT_VERSION,
            "status": "online",
            "capabilities": {
                "bind_authoritative": named is not None,
                "zone_staging": True,
                "named_checkzone": named_checkzone is not None,
                "named_checkconf": named_checkconf is not None,
                "atomic_apply": True,
                "rollback": True,
            },
        },
        str(config["token"]),
    )


def inventory(config: dict[str, Any]) -> dict[str, Any]:
    report = readiness_report()

    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/inventory",
        {
            "hostname": socket.gethostname(),
            "operating_system": platform.system(),
            "operating_system_version": platform.release(),
            "bind_version": report["bind_version"],
            "agent_version": AGENT_VERSION,
            "capabilities": {
                "bind_authoritative": report["bind_installed"],
                "zone_staging": True,
                "named_checkzone": bool(
                    report["paths"]["named_checkzone"]
                ),
                "named_checkconf": bool(
                    report["paths"]["named_checkconf"]
                ),
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


def send_readiness(config: dict[str, Any]) -> dict[str, Any]:
    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/bind/readiness",
        readiness_report(),
        str(config["token"]),
    )


def fixed_operation_commands(
    action: str,
    family: str,
) -> list[list[str]]:
    if action == "configure_bind":
        return []

    if action != "install_bind":
        raise AgentError("Operação não pertence ao catálogo local.")

    if family == "debian":
        return [
            ["/usr/bin/apt-get", "update"],
            [
                "/usr/bin/apt-get",
                "install",
                "-y",
                "--no-install-recommends",
                "bind9",
                "bind9-utils",
            ],
        ]

    if family == "rhel":
        return [
            [
                "/usr/bin/dnf",
                "install",
                "-y",
                "bind",
                "bind-utils",
            ],
        ]

    raise AgentError("Distribuição sem instalação BIND allowlisted.")


def bind_paths(family: str) -> dict[str, Path]:
    if family == "debian":
        return {
            "named_conf": Path("/etc/bind/named.conf"),
            "options_config": Path("/etc/bind/named.conf.options"),
            "managed_include": Path(
                "/etc/bind/dns-center-managed.conf"
            ),
            "managed_options_include": Path(
                "/etc/bind/dns-center-options.conf"
            ),
            "zones_dir": Path("/etc/bind/dns-center-zones"),
        }

    if family == "rhel":
        return {
            "named_conf": Path("/etc/named.conf"),
            "options_config": Path("/etc/named.conf"),
            "managed_include": Path(
                "/etc/named/dns-center-managed.conf"
            ),
            "managed_options_include": Path(
                "/etc/named/dns-center-options.conf"
            ),
            "zones_dir": Path("/var/named/dns-center-zones"),
        }

    raise AgentError("Distribuição sem caminhos BIND allowlisted.")


def bind_service_name(family: str) -> str:
    if family in {"debian", "rhel"}:
        return "named"

    raise AgentError("Distribuição sem serviço BIND allowlisted.")


HARDENED_OPTION_NAMES = {
    "recursion",
    "allow-recursion",
    "allow-query-cache",
    "minimal-responses",
    "version",
    "hostname",
    "auth-nxdomain",
    "transfer-format",
    "listen-on",
    "listen-on-v6",
}


def render_authoritative_options(addresses: list[str]) -> str:
    normalized = sorted({
        str(ipaddress.ip_address(address))
        for address in addresses
    })
    ipv4 = [address for address in normalized if ":" not in address]
    ipv6 = [address for address in normalized if ":" in address]

    return "\n".join([
        "// Managed by DNS Center",
        "// Authoritative-only policy. Do not edit manually.",
        "recursion no;",
        "allow-recursion { none; };",
        "allow-query-cache { none; };",
        "minimal-responses yes;",
        "version none;",
        "hostname none;",
        "auth-nxdomain no;",
        "transfer-format many-answers;",
        "listen-on { " + ("; ".join(ipv4) if ipv4 else "none") + "; };",
        "listen-on-v6 { " + ("; ".join(ipv6) if ipv6 else "none") + "; };",
        "",
    ])


def _options_block_bounds(content: str) -> tuple[int, int]:
    match = re.search(r"\boptions\s*\{", content)
    if not match:
        raise AgentError("Bloco options do BIND não foi encontrado.")

    start = match.end()
    depth = 1
    quote = False
    escaped = False
    for index in range(start, len(content)):
        character = content[index]
        if quote:
            if escaped:
                escaped = False
            elif character == "\\":
                escaped = True
            elif character == '"':
                quote = False
            continue
        if character == '"':
            quote = True
        elif character == "{":
            depth += 1
        elif character == "}":
            depth -= 1
            if depth == 0:
                return start, index

    raise AgentError("Bloco options do BIND está incompleto.")


def _remove_option_directives(body: str) -> str:
    result: list[str] = []
    index = 0
    depth = 0
    quote = False
    escaped = False
    statement_start = 0

    while index < len(body):
        character = body[index]
        if quote:
            if escaped:
                escaped = False
            elif character == "\\":
                escaped = True
            elif character == '"':
                quote = False
        elif character == '"':
            quote = True
        elif character == "{":
            depth += 1
        elif character == "}":
            depth = max(0, depth - 1)
        elif character == ";" and depth == 0:
            statement = body[statement_start:index + 1]
            comparable = re.sub(
                r"^\s*(?://[^\n]*\n|/\*.*?\*/\s*)*",
                "",
                statement,
                flags=re.DOTALL,
            )
            name_match = re.match(
                r"\s*([a-z][a-z0-9-]*)\b",
                comparable,
                flags=re.IGNORECASE,
            )
            if (
                not name_match
                or name_match.group(1).lower() not in HARDENED_OPTION_NAMES
            ):
                result.append(statement)
            statement_start = index + 1
        index += 1

    result.append(body[statement_start:])
    return "".join(result)


def install_authoritative_options_include(
    content: str,
    include_path: Path,
) -> str:
    start, end = _options_block_bounds(content)
    include_statement = f'include "{include_path}";'
    body = _remove_option_directives(content[start:end])
    body = re.sub(
        r'\s*include\s+"[^"]*dns-center-options\.conf"\s*;',
        "",
        body,
    )
    body = body.rstrip() + "\n\n    // DNS Center authoritative policy\n"
    body += f"    {include_statement}\n"

    return content[:start] + body + content[end:]


def ensure_zones_directory(path: Path, family: str) -> None:
    path.mkdir(parents=True, exist_ok=True)
    group_name = "bind" if family == "debian" else "named"

    try:
        group_id = grp.getgrnam(group_name).gr_gid
    except KeyError as exception:
        raise AgentError(
            f"Grupo de runtime do BIND não encontrado: {group_name}."
        ) from exception

    if os.geteuid() == 0:
        os.chown(path, 0, group_id)

    os.chmod(path, 0o2770)


def reject_symlink(path: Path) -> None:
    current = path

    while current != current.parent:
        if current.is_symlink():
            raise AgentError(
                f"Caminho simbólico não permitido: {path}"
            )
        current = current.parent


DISCOVERY_MAX_ZONEFILE_BYTES = DEFAULT_MAX_ARTIFACT_BYTES
DISCOVERY_MAX_RECORDS_PER_ZONE = 20000
DISCOVERY_MAX_ZONES = 200

SUPPORTED_DISCOVERY_RECORD_TYPES = {
    "A", "AAAA", "CNAME", "MX", "TXT", "CAA", "NS", "PTR",
}


def strip_tsig_secrets(text: str) -> str:
    """Remove BIND `secret "...";` clauses before any further processing.

    named-checkconf -p echoes the effective configuration, including TSIG
    key blocks with the secret in clear text. This must run before the text
    touches any log, error message, or outgoing payload — sanitize_message
    alone does not recognize this syntax.
    """
    return re.sub(
        r'secret\s+"[^"]*"\s*;',
        'secret "[removido]";',
        text,
        flags=re.IGNORECASE,
    )


def parse_rndc_status_summary(output: str) -> dict[str, Any]:
    summary: dict[str, Any] = {}
    match = re.search(
        r"number of zones:\s*(\d+)\s*\((\d+)\s*automatic\)",
        output,
        re.IGNORECASE,
    )
    if match:
        summary["zones_loaded"] = int(match.group(1))
        summary["zones_automatic"] = int(match.group(2))
    summary["server_running"] = "server is up and running" in output.lower()
    return summary


def parse_named_conf_zones(checkconf_output: str) -> list[dict[str, Any]]:
    """Parse `zone "name" { ... };` stanzas from named-checkconf -p output.

    Caller must strip TSIG secrets from the input beforehand. Brace-depth
    and quote-aware, matching the style of _options_block_bounds above.
    """
    zones: list[dict[str, Any]] = []
    pattern = re.compile(r'zone\s+"([^"]*)"(?:\s+\S+)?\s*\{', re.IGNORECASE)
    length = len(checkconf_output)
    index = 0

    while True:
        match = pattern.search(checkconf_output, index)
        if not match:
            break

        name = match.group(1).rstrip(".")
        cursor = match.end()
        depth = 1
        quote = False
        escaped = False

        while cursor < length and depth > 0:
            character = checkconf_output[cursor]
            if quote:
                if escaped:
                    escaped = False
                elif character == "\\":
                    escaped = True
                elif character == '"':
                    quote = False
            elif character == '"':
                quote = True
            elif character == "{":
                depth += 1
            elif character == "}":
                depth -= 1
            cursor += 1

        body = checkconf_output[match.end():max(match.end(), cursor - 1)]
        index = cursor

        type_match = re.search(r"\btype\s+(\S+?)\s*;", body, re.IGNORECASE)
        file_match = re.search(r'\bfile\s+"([^"]*)"\s*;', body, re.IGNORECASE)
        masters_blocks = re.findall(
            r"\b(?:masters|primaries)\s*\{([^}]*)\}", body, re.IGNORECASE
        )
        allow_transfer = re.search(
            r"\ballow-transfer\s*\{([^}]*)\}", body, re.IGNORECASE
        )
        also_notify = re.search(
            r"\balso-notify\s*\{([^}]*)\}", body, re.IGNORECASE
        )
        key_references = sorted(set(
            re.findall(r"\bkey\s+([A-Za-z0-9_.-]+)\s*;", body)
        ))

        raw_type = (type_match.group(1) if type_match else "").lower()
        detected_type = {
            "master": "primary",
            "primary": "primary",
            "slave": "secondary",
            "secondary": "secondary",
        }.get(raw_type)

        masters = [
            address.strip().rstrip(";")
            for group in masters_blocks
            for address in group.split(";")
            if address.strip()
        ]

        zones.append({
            "name": name,
            "detected_syntax": raw_type or None,
            "detected_type": detected_type,
            "file": file_match.group(1) if file_match else None,
            "masters": masters,
            "allow_transfer": bool(
                allow_transfer and allow_transfer.group(1).strip()
            ),
            "also_notify": bool(
                also_notify and also_notify.group(1).strip()
            ),
            "key_references": key_references,
        })

    return zones


def safe_file_metadata(
    path: Path,
) -> tuple[dict[str, Any] | None, str | None]:
    try:
        reject_symlink(path)
    except AgentError:
        return None, "Caminho ignorado por segurança (link simbólico)."

    try:
        info = path.stat()
    except OSError:
        return None, "Arquivo de zona não encontrado."

    if not path.is_file():
        return None, "Caminho de zona não é um arquivo regular."

    metadata: dict[str, Any] = {
        "size": info.st_size,
        "mode": oct(stat.S_IMODE(info.st_mode)),
        "mtime": datetime.fromtimestamp(
            info.st_mtime, tz=timezone.utc
        ).isoformat(),
    }

    try:
        metadata["owner"] = pwd.getpwuid(info.st_uid).pw_name
    except KeyError:
        metadata["owner"] = str(info.st_uid)

    try:
        metadata["group"] = grp.getgrgid(info.st_gid).gr_name
    except KeyError:
        metadata["group"] = str(info.st_gid)

    if info.st_size <= DISCOVERY_MAX_ZONEFILE_BYTES:
        try:
            digest = hashlib.sha256()
            with path.open("rb") as handle:
                for chunk in iter(lambda: handle.read(65536), b""):
                    digest.update(chunk)
            metadata["sha256"] = digest.hexdigest()
        except OSError:
            pass

    return metadata, None


def parse_discovery_zonestatus(output: str) -> dict[str, Any]:
    facts: dict[str, Any] = {}

    for raw_line in output.splitlines():
        if ":" not in raw_line:
            continue
        label, value = raw_line.split(":", 1)
        label = label.strip().lower()
        value = value.strip()
        if not value:
            continue
        if label == "serial":
            match = re.search(r"\d+", value)
            if match:
                facts["serial"] = int(match.group())
        elif label == "nodes":
            match = re.search(r"\d+", value)
            if match:
                facts["node_count"] = int(match.group())
        elif label == "secure":
            facts["secure"] = value.lower().startswith("yes")
        elif label == "dynamic":
            facts["dynamic"] = value.lower().startswith("yes")

    return facts


def _tokenize_zone_line(line: str) -> list[str]:
    tokens: list[str] = []
    current = ""
    quote = False
    escaped = False

    for character in line:
        if quote:
            current += character
            if escaped:
                escaped = False
            elif character == "\\":
                escaped = True
            elif character == '"':
                quote = False
            continue
        if character == '"':
            quote = True
            current += character
            continue
        if character.isspace():
            if current:
                tokens.append(current)
                current = ""
            continue
        current += character

    if current:
        tokens.append(current)

    return tokens


def _parse_soa_rdata(tokens: list[str]) -> dict[str, Any] | None:
    if len(tokens) < 7:
        return None
    try:
        return {
            "mname": tokens[0],
            "rname": tokens[1],
            "serial": int(tokens[2]),
            "refresh": int(tokens[3]),
            "retry": int(tokens[4]),
            "expire": int(tokens[5]),
            "minimum": int(tokens[6]),
        }
    except ValueError:
        return None


def parse_canonical_zone_dump(
    text: str,
    zone_name: str,
) -> dict[str, Any]:
    """Parse the canonical, fully-resolved output of `named-checkzone -D`.

    This is deliberately NOT a raw zonefile parser: named-checkzone already
    resolved $ORIGIN/$TTL, multi-line parentheses and comments, so the
    grammar left to handle here is far smaller (one logical record per
    line, owner may be blank to mean "same as previous record").
    """
    origin = zone_name.rstrip(".") + "."
    default_ttl: int | None = None
    last_owner: str | None = None
    records: list[dict[str, Any]] = []
    unsupported_types: set[str] = set()
    soa: dict[str, Any] | None = None

    # (content, owner_omitted) — owner_omitted is decided from the leading
    # whitespace of the FIRST physical line of each logical record, which is
    # how master-file format actually distinguishes "blank owner, same as
    # previous" from an explicit owner: an owner name can itself be a pure
    # number (common in reverse zones), so digit-based heuristics are wrong.
    joined_lines: list[tuple[str, bool]] = []
    buffer = ""
    depth = 0
    first_raw_of_record: str | None = None
    for raw_line in text.splitlines():
        if first_raw_of_record is None:
            first_raw_of_record = raw_line
        buffer += (" " if buffer else "") + raw_line
        depth += raw_line.count("(") - raw_line.count(")")
        if depth <= 0:
            stripped_buffer = buffer.strip()
            if stripped_buffer:
                owner_omitted = bool(first_raw_of_record) and first_raw_of_record[:1] in (" ", "\t")
                joined_lines.append((stripped_buffer, owner_omitted))
            buffer = ""
            depth = 0
            first_raw_of_record = None
    if buffer.strip():
        owner_omitted = bool(first_raw_of_record) and first_raw_of_record[:1] in (" ", "\t")
        joined_lines.append((buffer.strip(), owner_omitted))

    for stripped, owner_omitted in joined_lines:
        if not stripped or stripped.startswith(";"):
            continue
        if stripped.startswith("$ORIGIN"):
            parts = stripped.split()
            if len(parts) >= 2:
                origin = parts[1] if parts[1].endswith(".") else parts[1] + "."
            continue
        if stripped.startswith("$TTL"):
            parts = stripped.split()
            if len(parts) >= 2 and parts[1].isdigit():
                default_ttl = int(parts[1])
            continue
        if stripped.startswith("$"):
            continue

        tokens = _tokenize_zone_line(stripped)
        if not tokens:
            continue

        cursor = 0
        owner = last_owner
        if not owner_omitted:
            owner = tokens[0]
            cursor = 1
        if owner is None:
            continue
        last_owner = owner

        ttl = default_ttl
        if cursor < len(tokens) and tokens[cursor].isdigit():
            ttl = int(tokens[cursor])
            cursor += 1

        if cursor < len(tokens) and tokens[cursor].upper() in {"IN", "CH", "HS"}:
            cursor += 1

        if cursor >= len(tokens):
            continue

        rr_type = tokens[cursor].upper()
        cursor += 1
        rdata_tokens = tokens[cursor:]

        normalized_owner = origin if owner == "@" else owner

        if rr_type == "SOA":
            soa = _parse_soa_rdata(rdata_tokens)
            continue

        if rr_type not in SUPPORTED_DISCOVERY_RECORD_TYPES:
            unsupported_types.add(rr_type)
            continue

        records.append({
            "name": normalized_owner,
            "ttl": ttl,
            "type": rr_type,
            "rdata": " ".join(rdata_tokens),
        })

        if len(records) > DISCOVERY_MAX_RECORDS_PER_ZONE:
            raise AgentError(
                f"Zona {zone_name} excede o limite de registros para descoberta."
            )

    return {
        "origin": origin,
        "default_ttl": default_ttl,
        "soa": soa,
        "records": records,
        "unsupported_record_types": sorted(unsupported_types),
    }


def discover_bind_zones(config: dict[str, Any]) -> dict[str, Any]:
    """Read-only inventory of an existing BIND install. Never writes."""
    rndc = detected_binary(RNDC_CANDIDATES)
    named_checkconf = detected_binary(NAMED_CHECKCONF_CANDIDATES)
    named_checkzone = detected_binary(NAMED_CHECKZONE_CANDIDATES)
    named_conf = detected_named_conf()

    if not (rndc and named_checkconf and named_conf):
        raise AgentError(
            "Ferramentas BIND necessárias para descoberta não encontradas."
        )

    reject_symlink(Path(named_conf))

    status_result = run_command([rndc, "status"], timeout=15)
    bind_status = parse_rndc_status_summary(
        sanitize_message(status_result.stdout)
    )

    checkconf_result = run_command(
        [named_checkconf, "-p", named_conf], timeout=30
    )
    if checkconf_result.returncode != 0:
        raise AgentError(
            "named-checkconf falhou ao expandir a configuração: "
            + sanitize_message(
                checkconf_result.stderr or checkconf_result.stdout
            )
        )

    sanitized_config_text = strip_tsig_secrets(checkconf_result.stdout)
    declared_zones = parse_named_conf_zones(
        sanitized_config_text
    )[:DISCOVERY_MAX_ZONES]

    zones: list[dict[str, Any]] = []

    for declared in declared_zones:
        zone_name = declared["name"]
        zone_report: dict[str, Any] = {
            **declared,
            "warnings": [],
            "records": None,
            "soa": None,
            "unsupported_record_types": [],
            "validation_status": "ok",
            "validation_message": None,
            "file_metadata": None,
        }

        if rndc:
            zonestatus_result = run_command(
                [rndc, "zonestatus", zone_name], timeout=15
            )
            if zonestatus_result.returncode == 0:
                zone_report.update(
                    parse_discovery_zonestatus(zonestatus_result.stdout)
                )

        file_path = declared.get("file")
        file_meta = None
        if file_path:
            file_meta, file_warning = safe_file_metadata(Path(file_path))
            zone_report["file_metadata"] = file_meta
            if file_warning:
                zone_report["warnings"].append(file_warning)

        is_primary = declared.get("detected_type") == "primary"
        within_limit = bool(
            file_meta and file_meta.get("size", 0) <= DISCOVERY_MAX_ZONEFILE_BYTES
        )

        if is_primary and file_path and named_checkzone and within_limit:
            dump_result = run_command(
                [named_checkzone, "-D", zone_name, file_path],
                timeout=30,
                max_output_bytes=DISCOVERY_MAX_ZONEFILE_BYTES,
            )
            if dump_result.returncode != 0:
                zone_report["validation_status"] = "error"
                zone_report["validation_message"] = sanitize_message(
                    dump_result.stderr or dump_result.stdout
                )
            elif getattr(dump_result, "stdout_truncated", False):
                # Never parse a partial dump as if it were complete — that
                # would silently persist a wrong (incomplete) record set.
                zone_report["validation_status"] = "warning"
                zone_report["validation_message"] = (
                    "Saída de named-checkzone -D excede o limite de "
                    "captura; conteúdo não lido nesta descoberta."
                )
            else:
                parsed = parse_canonical_zone_dump(
                    strip_tsig_secrets(dump_result.stdout), zone_name
                )
                zone_report["soa"] = parsed["soa"]
                zone_report["records"] = parsed["records"]
                zone_report["unsupported_record_types"] = (
                    parsed["unsupported_record_types"]
                )
                if not zone_report.get("node_count"):
                    zone_report["node_count"] = len(parsed["records"])
                if parsed["unsupported_record_types"]:
                    zone_report["warnings"].append(
                        "Contém tipos de registro que o DNS Center ainda "
                        "não consegue editar: "
                        + ", ".join(parsed["unsupported_record_types"])
                    )
        elif is_primary and file_meta and not within_limit:
            zone_report["validation_status"] = "warning"
            zone_report["validation_message"] = (
                "Zonefile excede o limite de tamanho para leitura de "
                "conteúdo nesta descoberta."
            )

        zones.append(zone_report)

    return {
        "event_id": str(uuid.uuid4()),
        "discovered_at": utc_now(),
        "config_source": named_conf,
        "bind_status": bind_status,
        "zones": zones,
    }


SELF_UPGRADE_ARTIFACTS = (
    "dns-center-agent.py",
    "dns-center-agent.service",
    "dns-center-agent.timer",
    "dns-center-agent-operation.service",
    "dns-center-agent-approval.service",
    "dns-center-agent-approval.timer",
)

DEFAULT_INSTALL_PATH = Path("/usr/local/sbin/dns-center-agent")
DEFAULT_SYSTEMD_DIR = Path("/etc/systemd/system")


def download_public_artifact(
    base_url: str,
    name: str,
    destination: Path,
    max_bytes: int,
    timeout: int = 30,
    retries: int = DEFAULT_RETRIES,
) -> str:
    """Download a public /install/<name> artifact, verifying its .sha256
    sidecar. Shares the checksum-then-atomic-write core with
    request_artifact, but for the unauthenticated installer endpoints."""
    checksum_request = urllib.request.Request(
        f"{base_url}/install/{name}.sha256",
        method="GET",
        headers={"User-Agent": f"dns-center-agent/{AGENT_VERSION}"},
    )
    with urllib.request.urlopen(checksum_request, timeout=timeout) as response:
        checksum_body = response.read(1024).decode("utf-8", errors="replace")

    expected = checksum_body.strip().split()[0].lower() if checksum_body.strip() else ""
    if not re.fullmatch(r"[0-9a-f]{64}", expected):
        raise AgentError(f"Checksum inválido recebido para {name}.")

    request = urllib.request.Request(
        f"{base_url}/install/{name}",
        method="GET",
        headers={"User-Agent": f"dns-center-agent/{AGENT_VERSION}"},
    )

    for attempt in range(retries):
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                checksum, _size = _download_stream_to_file(
                    response, destination, max_bytes, 0o750, None, expected
                )

                return checksum
        except urllib.error.HTTPError as exception:
            error = http_error(exception)

            if (
                error.status not in RETRYABLE_HTTP_CODES
                or attempt == retries - 1
            ):
                raise error from exception

            bounded_backoff(attempt, error.retry_after)
        except urllib.error.URLError as exception:
            if attempt == retries - 1:
                raise AgentError(
                    "Falha de conexão: "
                    + sanitize_message(exception.reason)
                ) from exception

            bounded_backoff(attempt)


def upgrade_agent_self(config: dict[str, Any]) -> dict[str, Any]:
    """Download and install a newer agent binary/units in place.

    Never touches BIND, never touches /etc/bind or zone files. Reuses the
    exact safety model already audited in
    public/install/agent_install.sh --upgrade-agent (checksum verification,
    backup, atomic replace, validate --version/--help before accepting,
    restore on failure) but is triggered by the panel-authorized operation
    poll instead of a manual shell invocation, so it can run unattended.
    """
    if os.geteuid() != 0:
        raise AgentError("Auto-atualização do agente exige root.")

    install_path = DEFAULT_INSTALL_PATH
    if not install_path.is_file():
        raise AgentError(
            "Instalação do agente não encontrada em "
            f"{install_path}; use a instalação completa."
        )
    reject_symlink(install_path)

    base_url = normalize_base_url(str(config["base_url"]))
    binary_changed = False
    units_changed = False

    # dir= pins the temp dir to install_path's own filesystem so the
    # os.replace() swaps below are guaranteed atomic renames. The default
    # system temp dir (often a separate tmpfs mount) does not guarantee
    # this and os.replace() across filesystems raises OSError (EXDEV).
    with tempfile.TemporaryDirectory(
        prefix="dns-center-agent-selfupgrade-",
        dir=str(install_path.parent),
    ) as tmp_name:
        tmp_dir = Path(tmp_name)
        downloaded: dict[str, Path] = {}

        for artifact in SELF_UPGRADE_ARTIFACTS:
            destination = tmp_dir / artifact
            download_public_artifact(
                base_url, artifact, destination, DISCOVERY_MAX_ZONEFILE_BYTES
            )
            downloaded[artifact] = destination

        new_binary = downloaded["dns-center-agent.py"]
        compile_check = run_command(
            [sys.executable, "-m", "py_compile", str(new_binary)],
            timeout=30,
        )
        if compile_check.returncode != 0:
            raise AgentError(
                "Novo binário do agente falhou na validação de sintaxe."
            )

        backup_binary = tmp_dir / "dns-center-agent.previous"
        shutil.copy2(install_path, backup_binary)

        if not filecmp.cmp(new_binary, install_path, shallow=False):
            binary_changed = True
            os.chmod(new_binary, 0o750)
            try:
                os.replace(new_binary, install_path)
            except OSError as exception:
                raise AgentError(
                    f"Falha ao instalar o novo binário: {exception}"
                ) from exception

            version_check = run_command(
                [str(install_path), "--version"], timeout=10
            )
            help_check = run_command(
                [str(install_path), "--help"], timeout=10
            )
            if (
                version_check.returncode != 0
                or help_check.returncode != 0
                or "--enroll" not in help_check.stdout
            ):
                try:
                    os.chmod(backup_binary, 0o750)
                    os.replace(backup_binary, install_path)
                except OSError as exception:
                    raise AgentError(
                        "Falha na validação do novo agente e falha ao "
                        f"restaurar o binário anterior: {exception}"
                    ) from exception
                raise AgentError(
                    "Falha na validação do novo agente; binário anterior "
                    "restaurado."
                )

        systemd_dir = DEFAULT_SYSTEMD_DIR
        unit_backups: dict[Path, Path] = {}
        units_replaced: list[Path] = []

        try:
            for unit in SELF_UPGRADE_ARTIFACTS[1:]:
                destination = systemd_dir / unit
                if destination.is_file() and filecmp.cmp(
                    downloaded[unit], destination, shallow=False
                ):
                    continue
                reject_symlink(destination)

                try:
                    if destination.is_file():
                        backup_unit = tmp_dir / f"{unit}.previous"
                        shutil.copy2(destination, backup_unit)
                        unit_backups[destination] = backup_unit

                    os.chmod(downloaded[unit], 0o644)
                    shutil.copy2(downloaded[unit], destination)
                except OSError as exception:
                    raise AgentError(
                        f"Falha ao atualizar a unit {unit}: {exception}"
                    ) from exception

                units_replaced.append(destination)
                units_changed = True

            if units_changed:
                systemctl = detected_binary(
                    ("/usr/bin/systemctl", "/bin/systemctl")
                )
                if not systemctl:
                    raise AgentError(
                        "systemctl não foi detectado; não é possível "
                        "recarregar as units atualizadas."
                    )

                reload_result = run_command(
                    [systemctl, "daemon-reload"], timeout=30
                )
                if reload_result.returncode != 0:
                    raise AgentError(
                        "systemctl daemon-reload falhou após atualizar "
                        "as units."
                    )
        except Exception:
            # Restore every unit file this run touched before propagating,
            # so a failed upgrade never leaves a mixed/unknown set of units
            # on disk — mirrors the binary's own restore-on-failure above.
            for destination in units_replaced:
                backup_unit = unit_backups.get(destination)
                try:
                    if backup_unit is not None:
                        shutil.copy2(backup_unit, destination)
                    else:
                        destination.unlink(missing_ok=True)
                except OSError:
                    pass

            systemctl = detected_binary(SYSTEMCTL_CANDIDATES)
            if systemctl:
                try:
                    run_command([systemctl, "daemon-reload"], timeout=30)
                except AgentError:
                    pass

            raise

    return {
        "binary_changed": binary_changed,
        "units_changed": units_changed,
        "previous_version": AGENT_VERSION,
        "changed": binary_changed or units_changed,
    }


def configure_bind(action: str) -> dict[str, Any]:
    if os.geteuid() != 0:
        raise AgentError("Operação BIND autorizada exige root.")

    family = os_family()
    paths = bind_paths(family)

    for path in paths.values():
        reject_symlink(path)

    systemctl = detected_binary(SYSTEMCTL_CANDIDATES)
    service = bind_service_name(family)
    was_installed = (
        detected_binary(NAMED_CANDIDATES)
        is not None
    )

    if action == "install_bind" and was_installed:
        action = "configure_bind"

    masked = False

    if action == "install_bind" and systemctl:
        mask = run_command(
            [systemctl, "mask", "--runtime", service],
            timeout=30,
        )

        if mask.returncode != 0:
            raise AgentError("Não foi possível bloquear o serviço antes da instalação.")

        masked = True

    try:
        for command in fixed_operation_commands(action, family):
            result = run_command(command, timeout=600)

            if result.returncode != 0:
                raise AgentError(
                    "Instalação allowlisted falhou: "
                    + (result.stderr.strip() or "sem detalhe")
                )

        named_checkconf = detected_binary(NAMED_CHECKCONF_CANDIDATES)

        if not named_checkconf or not paths["named_conf"].is_file():
            raise AgentError("BIND instalado sem configuração validável.")

        backup_dir = DEFAULT_BACKUP_DIR / datetime.now().strftime(
            "%Y%m%d-%H%M%S"
        )
        backup_dir.mkdir(parents=True, exist_ok=False)
        named_backup = backup_dir / "named.conf"
        shutil.copy2(paths["named_conf"], named_backup)
        include_existed = paths["managed_include"].exists()

        if include_existed:
            shutil.copy2(
                paths["managed_include"],
                backup_dir / "dns-center-managed.conf",
            )

        include_statement = (
            f'include "{paths["managed_include"]}";'
        )
        current = paths["named_conf"].read_text(encoding="utf-8")

        try:
            ensure_zones_directory(paths["zones_dir"], family)

            if not include_existed:
                atomic_write(
                    paths["managed_include"],
                    "// Managed by DNS Center\n",
                    0o640,
                )

            if include_statement not in current:
                atomic_write(
                    paths["named_conf"],
                    current.rstrip()
                    + "\n\n// DNS Center managed include\n"
                    + include_statement
                    + "\n",
                    stat.S_IMODE(paths["named_conf"].stat().st_mode),
                )

            validation = run_command(
                [named_checkconf, str(paths["named_conf"])],
                timeout=30,
            )

            if validation.returncode != 0:
                raise AgentError(
                    "named-checkconf bloqueou a configuração."
                )

            if masked and systemctl:
                run_command(
                    [systemctl, "unmask", "--runtime", service],
                    timeout=30,
                )
                masked = False

            if was_installed:
                rndc = detected_binary(
                    ("/usr/sbin/rndc", "/usr/bin/rndc")
                )

                if not rndc:
                    raise AgentError("rndc não foi detectado.")

                reload_result = run_command(
                    [rndc, "reconfig"],
                    timeout=30,
                )
            elif systemctl:
                reload_result = run_command(
                    [systemctl, "enable", "--now", service],
                    timeout=60,
                )
            else:
                raise AgentError("systemd não foi detectado.")

            if reload_result.returncode != 0:
                raise AgentError("Ativação segura do BIND falhou.")
        except Exception as exception:
            shutil.copy2(named_backup, paths["named_conf"])

            if include_existed:
                shutil.copy2(
                    backup_dir / "dns-center-managed.conf",
                    paths["managed_include"],
                )
            else:
                paths["managed_include"].unlink(missing_ok=True)

            raise AgentOperationError(
                str(exception),
                rolled_back=True,
            ) from exception
    finally:
        if masked and systemctl:
            run_command(
                [systemctl, "unmask", "--runtime", service],
                timeout=30,
            )

    report = readiness_report()

    return {
        "bind_installed": report["bind_installed"],
        "bind_version": report["bind_version"],
        "managed_include_created": paths["managed_include"].is_file(),
        "backup_created": True,
        "configuration_valid": True,
        "service_active": report["service"]["active"],
        "tcp_53": report["listeners"]["tcp_53"],
        "udp_53": report["listeners"]["udp_53"],
        "rolled_back": False,
    }


def report_operation(
    config: dict[str, Any],
    operation: dict[str, Any],
    status_value: str,
    result: dict[str, Any] | None = None,
    error: str | None = None,
) -> dict[str, Any]:
    return request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + f"/api/agent/bind/operations/{operation['id']}/report",
        {
            "event_id": str(uuid.uuid4()),
            "authorization_nonce": operation["authorization_nonce"],
            "status": status_value,
            "result": result,
            "error": error,
        },
        str(config["token"]),
    )


def run_authorized_operation(
    config: dict[str, Any],
) -> dict[str, Any]:
    response = request_json(
        "GET",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/bind/operations/next",
        token=str(config["token"]),
    )
    operation = response.get("operation")

    if operation is None:
        return {"status": "idle"}

    if not isinstance(operation, dict):
        raise AgentError("Operação autorizada inválida.")

    action = operation.get("action")

    if action not in {
        "install_bind", "configure_bind", "discover_bind_zones", "upgrade_agent",
    }:
        # Tell the panel immediately instead of leaving the operation stuck
        # in "authorized" until its TTL sweep expires it — an older agent
        # that predates a newly-added action would otherwise go silent.
        report_operation(config, operation, "running")
        report_operation(
            config,
            operation,
            "failed",
            error="Ação não suportada por esta versão do agente.",
        )
        raise AgentError("Operação não pertence ao catálogo local.")

    report_operation(config, operation, "running")

    try:
        if action == "discover_bind_zones":
            result = discover_bind_zones(config)
        elif action == "upgrade_agent":
            result = upgrade_agent_self(config)
        else:
            result = configure_bind(str(action))
    except AgentError as exception:
        message = sanitize_message(exception)
        rolled_back = (
            exception.rolled_back
            if isinstance(exception, AgentOperationError)
            else False
        )
        report_operation(
            config,
            operation,
            "failed",
            result={"rolled_back": rolled_back},
            error=message,
        )
        raise

    # The action itself already succeeded at this point. Reporting that to
    # the panel is now best-effort: if this call fails (network blip), we
    # must NOT report "failed" for work that actually succeeded — leave the
    # operation "running" for the panel's TTL sweep to notice instead of
    # lying about the outcome.
    report_operation(config, operation, "succeeded", result=result)

    if action not in {"discover_bind_zones", "upgrade_agent"}:
        send_readiness(config)

    return {"status": "succeeded", "result": result}


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


def empty_publication_state() -> dict[str, Any]:
    return {
        "desired_publication_id": None,
        "desired_version": None,
        "downloaded_publication_id": None,
        "installed_publication_id": None,
        "installed_version": None,
        "last_apply_status": None,
        "last_apply_at": None,
        "last_apply_error": None,
        "attempt_id": None,
        "events": {},
        "artifacts": {},
    }


def load_publication_state(state_dir: Path) -> dict[str, Any]:
    state_file = state_dir / "state.json"

    if not state_file.exists():
        return empty_publication_state()

    state = read_json(state_file)
    clean = empty_publication_state()

    for key in clean:
        if key in state:
            clean[key] = state[key]

    if not isinstance(clean["events"], dict):
        clean["events"] = {}

    if not isinstance(clean["artifacts"], dict):
        clean["artifacts"] = {}

    return clean


def save_publication_state(
    state_dir: Path,
    state: dict[str, Any],
) -> None:
    allowed = set(empty_publication_state())
    payload = {key: state.get(key) for key in allowed}
    events = payload.get("events")

    if isinstance(events, dict) and len(events) > 256:
        payload["events"] = dict(list(events.items())[-256:])

    write_json_state(state_dir / "state.json", payload)


def _require_positive_int(
    value: object,
    message: str,
    *,
    max_value: int | None = None,
) -> None:
    if (
        not isinstance(value, int)
        or isinstance(value, bool)
        or value < 1
        or (max_value is not None and value > max_value)
    ):
        raise AgentError(message)


def validate_manifest_item(item: object) -> dict[str, Any]:
    if not isinstance(item, dict):
        raise AgentError("Entrada inválida no manifesto.")

    publication_id = item.get("publication_id")
    desired_version = item.get("desired_version", item.get("version"))
    installed_version = item.get("installed_version")
    serial = item.get("serial")

    _require_positive_int(
        publication_id, "publication_id inválido no manifesto."
    )
    _require_positive_int(
        desired_version, "Versão desejada inválida no manifesto."
    )

    if installed_version is not None:
        _require_positive_int(
            installed_version, "Versão instalada inválida no manifesto."
        )

    _require_positive_int(
        serial,
        "Serial SOA inválido no manifesto.",
        max_value=4294967295,
    )

    name = str(item.get("name", ""))
    safe_zone_filename(name)
    zone_type = str(item.get("type", item.get("kind", "primary")))

    if zone_type not in {"primary", "secondary"}:
        raise AgentError("Tipo de zona inválido no manifesto.")

    artifact_value = item.get("artifact_url")
    artifact_url = str(artifact_value) if artifact_value is not None else ""

    if zone_type == "primary":
        parsed = urllib.parse.urlparse(artifact_url)

        if (
            not artifact_url.startswith("/")
            or artifact_url.startswith("//")
            or parsed.scheme
            or parsed.netloc
            or ".." in parsed.path.split("/")
        ):
            raise AgentError(f"URL de artefato inválida para {name}.")
    elif artifact_url:
        raise AgentError("Secondary não deve receber artefato de zona.")

    transfer = item.get("transfer")

    if not isinstance(transfer, dict):
        raise AgentError(f"Topologia de transferência inválida para {name}.")

    primary_addresses = validate_transfer_addresses(
        transfer.get("primary_addresses"),
        "primary",
    )
    secondary_addresses = validate_transfer_addresses(
        transfer.get("secondary_addresses"),
        "secondary",
    )
    tsig = validate_tsig(transfer.get("tsig"))

    if zone_type == "secondary" and not primary_addresses:
        raise AgentError(f"Secondary {name} sem endereço do primary.")

    if secondary_addresses and tsig is None:
        raise AgentError(f"Zona {name} com secondary exige TSIG.")

    normalized = dict(item)
    normalized["publication_id"] = publication_id
    normalized["desired_version"] = desired_version
    normalized["installed_version"] = installed_version
    normalized["name"] = name
    normalized["type"] = zone_type
    normalized["artifact_url"] = artifact_url
    normalized["serial"] = serial
    normalized["transfer"] = {
        "primary_addresses": primary_addresses,
        "secondary_addresses": secondary_addresses,
        "tsig": tsig,
    }
    normalized["id"] = int(item.get("id", 0))
    if normalized["id"] < 1:
        raise AgentError("zone_id inválido no manifesto.")
    normalized["authorized_listen_addresses"] = validate_transfer_addresses(
        item.get("authorized_listen_addresses", []),
        "listen",
    )

    return normalized


def validate_transfer_addresses(value: object, label: str) -> list[str]:
    if not isinstance(value, list):
        raise AgentError(f"Endereços {label} inválidos.")

    addresses: list[str] = []

    for address in value:
        try:
            normalized = str(ipaddress.ip_address(str(address)))
        except ValueError as exception:
            raise AgentError(f"Endereço {label} inválido.") from exception

        if normalized not in addresses:
            addresses.append(normalized)

    return addresses


def validate_tsig(value: object) -> dict[str, str] | None:
    if value is None:
        return None

    if not isinstance(value, dict):
        raise AgentError("Configuração TSIG inválida.")

    name = str(value.get("name", ""))
    algorithm = str(value.get("algorithm", ""))
    secret = str(value.get("secret", ""))

    if not re.fullmatch(r"[A-Za-z0-9._-]{1,255}", name):
        raise AgentError("Nome TSIG inválido.")

    if algorithm not in {"hmac-sha256", "hmac-sha384", "hmac-sha512"}:
        raise AgentError("Algoritmo TSIG inválido.")

    try:
        decoded = base64.b64decode(secret, validate=True)
    except (ValueError, TypeError) as exception:
        raise AgentError("Segredo TSIG inválido.") from exception

    if len(decoded) < 16:
        raise AgentError("Segredo TSIG muito curto.")

    return {"name": name, "algorithm": algorithm, "secret": secret}


def publication_event_payload(
    status_value: str,
    installed_version: int | None = None,
    checksum: str | None = None,
    error: str | None = None,
    authoritative_serial: int | None = None,
) -> dict[str, Any]:
    payload: dict[str, Any] = {
        "status": status_value,
        "installed_version": installed_version,
        "artifact_checksum": checksum,
        "error": sanitize_message(error) if error else None,
        "authoritative_serial": authoritative_serial,
    }

    return payload


def report_publication(
    config: dict[str, Any],
    state: dict[str, Any],
    publication_id: int,
    attempt_id: str,
    payload: dict[str, Any],
) -> dict[str, Any]:
    canonical = json.dumps(payload, sort_keys=True, separators=(",", ":"))
    payload_hash = hashlib.sha256(canonical.encode("utf-8")).hexdigest()
    event_key = f"{publication_id}:{attempt_id}:{payload_hash}"
    events = state.setdefault("events", {})
    event_id = events.get(event_key)

    if not isinstance(event_id, str):
        event_id = str(uuid.uuid4())
        events[event_key] = event_id
        save_publication_state(config_paths(config)["state_dir"], state)

    request_payload = {
        "event_id": event_id,
        "agent_timestamp": utc_now(),
        **{key: value for key, value in payload.items() if value is not None},
    }

    try:
        return request_json(
            "POST",
            normalize_base_url(str(config["base_url"]))
            + f"/api/agent/publications/{publication_id}/apply",
            request_payload,
            str(config["token"]),
        )
    except AgentHttpError as exception:
        if exception.status == 409 and exception.code == "event_replay":
            events.pop(event_key, None)
            save_publication_state(config_paths(config)["state_dir"], state)

        raise


def render_managed_include(
    manifest: list[dict[str, Any]],
    zones_dir: Path,
) -> str:
    lines = [
        "// Managed by DNS Center",
        "// Do not edit manually.",
        "",
    ]

    tsig_keys: dict[str, dict[str, str]] = {}

    for item in manifest:
        tsig = item.get("transfer", {}).get("tsig")

        if isinstance(tsig, dict):
            tsig_keys[str(tsig["name"])] = tsig

    for tsig in tsig_keys.values():
        lines.extend(
            [
                f'key "{tsig["name"]}" {{',
                f'    algorithm {tsig["algorithm"]};',
                f'    secret "{tsig["secret"]}";',
                "};",
                "",
            ]
        )

    for item in manifest:
        name = str(item["name"]).rstrip(".")
        zone_type = str(item.get("type", "primary"))
        filename = safe_zone_filename(name)
        bind_type = "master" if zone_type == "primary" else "slave"
        transfer = item.get("transfer", {})
        tsig = transfer.get("tsig")

        lines.extend([
            f'zone "{name}" {{',
            f"    type {bind_type};",
            f'    file "{zones_dir / filename}";',
        ])

        if zone_type == "primary":
            secondary_addresses = transfer.get("secondary_addresses", [])

            if secondary_addresses and isinstance(tsig, dict):
                key_name = tsig["name"]
                lines.append(f'    allow-transfer {{ key "{key_name}"; }};')
                notify_targets = " ".join(
                    f'{address} key "{key_name}";'
                    for address in secondary_addresses
                )
                lines.append(f"    also-notify {{ {notify_targets} }};")
                lines.append("    notify explicit;")
            else:
                lines.append("    allow-transfer { none; };")
        else:
            if not isinstance(tsig, dict):
                raise AgentError(f"Secondary {name} sem TSIG.")

            key_name = tsig["name"]
            masters = " ".join(
                f'{address} key "{key_name}";'
                for address in transfer.get("primary_addresses", [])
            )
            lines.append(f"    masters {{ {masters} }};")
            lines.append("    request-ixfr yes;")

        lines.extend(["};", ""])

    return "\n".join(lines)


def current_zone_serial(zonefile: Path) -> int | None:
    """Best-effort SOA serial read from an on-disk zone file.

    Used only to guard against writing a regressed serial; a file that
    doesn't exist yet or can't be parsed is treated as "no prior serial"
    (first deployment), not as a block.
    """
    if not zonefile.is_file():
        return None

    try:
        content = zonefile.read_text(encoding="ascii", errors="ignore")
    except OSError:
        return None

    match = re.search(r"\bSOA\b[^0-9]*?(\d{1,10})", content)

    return int(match.group(1)) if match else None


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
    zones_dir = config_paths(config)["zones_dir"]

    for item in manifest:
        if item.get("type") != "primary":
            continue

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

        deployed_serial = current_zone_serial(
            zones_dir / safe_zone_filename(name)
        )
        new_serial = item.get("serial")
        if (
            deployed_serial is not None
            and isinstance(new_serial, int)
            and new_serial <= deployed_serial
        ):
            raise AgentError(
                f"Serial SOA regressivo para {name}: recebido "
                f"{new_serial}, servidor já tem {deployed_serial}."
            )

    result = run_command([named_checkconf, str(include_path)])

    if result.returncode != 0:
        detail = result.stderr.strip() or result.stdout.strip()
        raise AgentError(
            "named-checkconf falhou: "
            f"{detail}"
        )


def build_staging(
    config: dict[str, Any],
    apply: bool = False,
) -> tuple[
    list[dict[str, Any]],
    list[dict[str, Any]],
    Path | None,
    Path | None,
    dict[str, Any],
]:
    base_url = normalize_base_url(str(config["base_url"]))
    token = str(config["token"])
    paths = config_paths(config)

    response = request_json(
        "GET",
        base_url + "/api/agent/zones",
        token=token,
    )

    raw_manifest = response.get("zones")

    if not isinstance(raw_manifest, list):
        raise AgentError("Manifesto de zonas inválido.")

    state_dir = paths["state_dir"]
    state_dir.mkdir(parents=True, exist_ok=True)
    reject_symlink(state_dir)
    state = load_publication_state(state_dir)
    manifest = [validate_manifest_item(item) for item in raw_manifest]
    updates = [
        item
        for item in manifest
        if bool(item.get("update_available"))
    ]

    if not updates:
        return manifest, [], None, None, state

    attempt_id = state.get("attempt_id")

    try:
        attempt_id = str(uuid.UUID(str(attempt_id)))
    except ValueError:
        attempt_id = None

    if attempt_id is None or state.get("last_apply_status") not in {
        "downloaded",
        "applying",
        "applied_pending_confirmation",
    }:
        attempt_id = str(uuid.uuid4())
        state["attempt_id"] = attempt_id

    staging_root = state_dir / "staging"
    reject_symlink(staging_root)
    staging_dir = staging_root / attempt_id

    if staging_dir.exists():
        reject_symlink(staging_dir)
        shutil.rmtree(staging_dir)

    staging_dir.mkdir(parents=True, mode=0o750)
    artifacts_dir = state_dir / "artifacts"
    reject_symlink(artifacts_dir)
    artifacts_dir.mkdir(parents=True, exist_ok=True)

    for item in manifest:
        name = item["name"]
        publication_id = item["publication_id"]
        version = item["desired_version"]
        if item["type"] == "secondary":
            continue

        artifact_key = str(publication_id)
        artifact_path = artifacts_dir / f"{publication_id}.zone"
        reject_symlink(artifact_path)
        recorded = state["artifacts"].get(artifact_key, {})
        reuse = (
            isinstance(recorded, dict)
            and recorded.get("version") == version
            and isinstance(recorded.get("checksum"), str)
            and artifact_path.is_file()
            and not artifact_path.is_symlink()
        )

        if reuse:
            actual_checksum = hashlib.sha256(
                artifact_path.read_bytes()
            ).hexdigest()
            reuse = actual_checksum == recorded["checksum"]

        if not reuse:
            try:
                metadata = request_artifact(
                    base_url + item["artifact_url"],
                    token,
                    artifact_path,
                    int(
                        config.get(
                            "max_artifact_bytes",
                            DEFAULT_MAX_ARTIFACT_BYTES,
                        )
                    ),
                    int(config.get("request_timeout", 30)),
                )

                if (
                    metadata["publication_id"] is not None
                    and metadata["publication_id"] != str(publication_id)
                ):
                    raise AgentError("Publicação divergente no download.")

                if (
                    metadata["version"] is not None
                    and metadata["version"] != str(version)
                ):
                    raise AgentError("Versão divergente no download.")

                expected_checksum = item.get("artifact_checksum")
                server_checksum = metadata["server_checksum"]

                if expected_checksum is None and server_checksum is None:
                    raise AgentError(
                        "Nenhum checksum de referência disponível para "
                        "verificar o artefato baixado."
                    )

                if (
                    expected_checksum is not None
                    and str(expected_checksum).lower()
                    != metadata["checksum"]
                ):
                    raise AgentError("Checksum do artefato divergente.")

                if (
                    server_checksum is not None
                    and server_checksum.lower() != metadata["checksum"]
                ):
                    raise AgentError("Checksum do download divergente.")
            except AgentError as exception:
                artifact_path.unlink(missing_ok=True)
                error = sanitize_message(exception)
                state["desired_publication_id"] = publication_id
                state["desired_version"] = version
                state["last_apply_status"] = "failed"
                state["last_apply_at"] = utc_now()
                state["last_apply_error"] = error
                save_publication_state(state_dir, state)

                # A dry run (apply=False) must have no externally visible
                # side effects: never tell the panel a real "failed"
                # publication attempt happened just because a preview-only
                # download/checksum check found a problem.
                if apply and not (
                    isinstance(exception, AgentHttpError)
                    and exception.status in {401, 403}
                ):
                    try:
                        report_publication(
                            config,
                            state,
                            publication_id,
                            str(attempt_id),
                            publication_event_payload(
                                "failed",
                                error=error,
                            ),
                        )
                    except AgentError as report_error:
                        safe_log(
                            "Falha ao confirmar download inválido da "
                            f"publicação {publication_id}: {report_error}"
                        )

                state["attempt_id"] = None
                save_publication_state(state_dir, state)
                raise

            state["artifacts"][artifact_key] = {
                "version": version,
                "checksum": metadata["checksum"],
                "size": metadata["size"],
            }
            state["desired_publication_id"] = publication_id
            state["desired_version"] = version
            state["downloaded_publication_id"] = publication_id
            state["last_apply_status"] = "downloaded"
            state["last_apply_error"] = None
            save_publication_state(state_dir, state)

        target = staging_dir / safe_zone_filename(name)
        reject_symlink(target)
        atomic_write(
            target,
            artifact_path.read_text(encoding="utf-8"),
            0o640,
        )

    include_path = staging_dir / "dns-center-managed.conf"
    include_content = render_managed_include(
        manifest,
        paths["zones_dir"],
    )
    atomic_write(include_path, include_content, 0o640)

    try:
        validate_staging(
            config,
            manifest,
            staging_dir,
            include_path,
        )
    except AgentError as exception:
        error = sanitize_message(exception)
        state["last_apply_status"] = "failed"
        state["last_apply_at"] = utc_now()
        state["last_apply_error"] = error

        # Same dry-run contract as the download/checksum failure above:
        # a preview-only run must never report a real "failed" publication
        # event to the panel.
        if apply:
            for item in updates:
                try:
                    report_publication(
                        config,
                        state,
                        item["publication_id"],
                        str(attempt_id),
                        publication_event_payload(
                            "failed",
                            checksum=state["artifacts"]
                            .get(str(item["publication_id"]), {})
                            .get("checksum"),
                            error=error,
                        ),
                    )
                except AgentError as report_error:
                    safe_log(
                        "Falha ao confirmar staging inválido da publicação "
                        f"{item['publication_id']}: {report_error}"
                    )

        state["attempt_id"] = None
        save_publication_state(state_dir, state)
        raise

    return manifest, updates, staging_dir, include_path, state


_BIND_CONFIG_BACKUP_FILES = (
    ("dns-center-managed.conf", "managed_include"),
    ("bind-options.conf", "options_config"),
    ("dns-center-options.conf", "managed_options_include"),
)


def backup_current(paths: dict[str, Path]) -> Path:
    for path in (
        paths["backup_dir"],
        paths["zones_dir"],
        paths["managed_include"],
        paths["options_config"],
        paths["managed_options_include"],
    ):
        reject_symlink(path)

    backup_dir = paths["backup_dir"] / datetime.now().strftime(
        "%Y%m%d-%H%M%S"
    )
    backup_dir.mkdir(parents=True, exist_ok=False)

    for filename, key in _BIND_CONFIG_BACKUP_FILES:
        if paths[key].exists():
            shutil.copy2(paths[key], backup_dir / filename)

    zones_backup = backup_dir / "zones"
    zones_backup.mkdir()

    if paths["zones_dir"].exists():
        for zonefile in paths["zones_dir"].glob("*.zone"):
            reject_symlink(zonefile)

            if zonefile.is_file():
                shutil.copy2(zonefile, zones_backup / zonefile.name)

    return backup_dir


def restore_backup(
    paths: dict[str, Path],
    backup_dir: Path,
) -> None:
    for path in (
        paths["zones_dir"],
        paths["managed_include"],
        paths["options_config"],
        paths["managed_options_include"],
        backup_dir,
    ):
        reject_symlink(path)

    for filename, key in _BIND_CONFIG_BACKUP_FILES:
        backup_file = backup_dir / filename

        if backup_file.exists():
            shutil.copy2(backup_file, paths[key])
        else:
            paths[key].unlink(missing_ok=True)

    paths["zones_dir"].mkdir(parents=True, exist_ok=True)

    for current in paths["zones_dir"].glob("*.zone"):
        reject_symlink(current)
        current.unlink()

    zones_backup = backup_dir / "zones"

    if zones_backup.exists():
        for zonefile in zones_backup.glob("*.zone"):
            reject_symlink(zonefile)
            shutil.copy2(zonefile, paths["zones_dir"] / zonefile.name)


def apply_staging(
    config: dict[str, Any],
    manifest: list[dict[str, Any]],
    staging_dir: Path,
    include_path: Path,
) -> Path:
    paths = config_paths(config)

    for path in (
        paths["zones_dir"],
        paths["managed_include"],
        paths["named_conf"],
        paths["options_config"],
        paths["managed_options_include"],
        staging_dir,
        include_path,
    ):
        reject_symlink(path)

    ensure_zones_directory(paths["zones_dir"], os_family())
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

            if item.get("type") == "secondary":
                continue

            source = staging_dir / filename
            target = paths["zones_dir"] / filename
            reject_symlink(source)
            reject_symlink(target)

            atomic_write(
                target,
                source.read_text(encoding="utf-8"),
                0o640,
            )

        for current in paths["zones_dir"].glob("*.zone"):
            reject_symlink(current)

            if current.name not in expected:
                current.unlink()

        atomic_write(
            paths["managed_include"],
            include_path.read_text(encoding="utf-8"),
            0o640,
        )
        if not paths["options_config"].is_file():
            raise AgentError("Configuração options do BIND não foi encontrada.")

        addresses = [
            address
            for item in manifest
            for address in item.get("authorized_listen_addresses", [])
        ]
        if not addresses:
            raise AgentError(
                "Nenhum endereço autorizado para listeners do BIND."
            )
        options_mode = stat.S_IMODE(paths["options_config"].stat().st_mode)
        hardened_options_config = install_authoritative_options_include(
            paths["options_config"].read_text(encoding="utf-8"),
            paths["managed_options_include"],
        )
        atomic_write(
            paths["managed_options_include"],
            render_authoritative_options(addresses),
            0o640,
        )
        atomic_write(
            paths["options_config"],
            hardened_options_config,
            options_mode,
        )

        named_checkconf = command_path(
            config,
            "named_checkconf",
            "/usr/bin/named-checkconf",
        )
        result = run_command(
            [named_checkconf, str(paths["named_conf"])]
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

        for item in manifest:
            if item.get("type") != "primary":
                continue

            result = run_command(
                [rndc, "reload", str(item["name"]).rstrip(".")]
            )

            if result.returncode != 0:
                detail = result.stderr.strip() or result.stdout.strip()
                raise AgentError(
                    "rndc reload falhou para "
                    f"{item['name']}: {detail}"
                )

        return backup_dir
    except Exception:
        restore_backup(paths, backup_dir)

        try:
            rndc = command_path(config, "rndc", "/usr/sbin/rndc")
            run_command([rndc, "reconfig"])

            # "rndc reconfig" alone does not force BIND to re-read the
            # content of a zone it already has loaded — only
            # "rndc reload <zone>" does. Any zone that successfully
            # reloaded to the NEW content before this failure would
            # otherwise keep serving it from memory even though its
            # on-disk file was just reverted above, silently diverging
            # disk, memory and the panel's recorded status. Best-effort:
            # a failure here must not mask the original exception.
            for item in manifest:
                if item.get("type") != "primary":
                    continue

                try:
                    run_command(
                        [rndc, "reload", str(item["name"]).rstrip(".")]
                    )
                except AgentError:
                    pass
        except AgentError:
            pass

        raise


def authoritative_serial(
    config: dict[str, Any],
    zone_name: str,
    expected_serial: int,
) -> int:
    rndc = command_path(config, "rndc", "/usr/sbin/rndc")
    attempts = max(1, min(int(config.get("serial_confirmation_attempts", 10)), 60))

    for attempt in range(attempts):
        result = run_command([rndc, "zonestatus", zone_name], timeout=15)
        match = re.search(
            r"^\s*serial:\s*(\d+)\s*$",
            result.stdout,
            flags=re.IGNORECASE | re.MULTILINE,
        )

        if result.returncode == 0 and match:
            observed = int(match.group(1))

            if observed == expected_serial:
                return observed

        if attempt < attempts - 1:
            time.sleep(1)

    raise AgentError(
        f"BIND não confirmou o serial SOA esperado para {zone_name}."
    )


def normalize_rndc_datetime(value: str) -> str | None:
    candidate = re.sub(
        r"\s+\(.*\)\s*$|\s+in\s+\d+\s+seconds?\s*$",
        "",
        value,
        flags=re.IGNORECASE,
    ).strip()

    try:
        parsed = parsedate_to_datetime(candidate)
    except (TypeError, ValueError, OverflowError):
        return None

    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)

    return parsed.astimezone(timezone.utc).isoformat()


def parse_rndc_zonestatus(output: str) -> dict[str, Any]:
    values: dict[str, Any] = {}
    mapping = {
        "serial": "observed_serial",
        "state": "zone_state",
        "last loaded": "last_refresh_at",
        "next refresh": "next_retry_at",
        "expires": "expires_at",
        "master": "primary_address",
        "primary": "primary_address",
    }
    for raw_line in output.splitlines():
        if ":" not in raw_line:
            continue
        label, value = raw_line.split(":", 1)
        key = mapping.get(label.strip().lower())
        value = value.strip()
        if not key or not value:
            continue
        if key == "observed_serial":
            match = re.search(r"\d+", value)
            if match:
                values[key] = int(match.group())
        elif key in {"last_refresh_at", "next_retry_at", "expires_at"}:
            parsed = normalize_rndc_datetime(value)
            if parsed:
                values[key] = parsed
        elif key == "primary_address":
            address = value.rsplit("#", 1)[0].strip("[]")
            try:
                values[key] = str(ipaddress.ip_address(address))
            except ValueError:
                continue
        else:
            values[key] = sanitize_message(value)

    state_text = " ".join(
        str(value) for value in values.values()
    ).lower()
    values["status"] = (
        "transferring" if "transfer" in state_text
        else "unknown"
    )
    return values


def bind_recent_events(config: dict[str, Any]) -> str:
    journalctl = detected_binary(
        ("/usr/bin/journalctl", "/bin/journalctl")
    )
    if not journalctl:
        return ""
    family = os_family()
    service = (
        bind_service_name(family)
        if family in {"debian", "rhel"}
        else "named"
    )
    result = run_command(
        [
            journalctl,
            "--unit",
            service,
            "--since",
            "-10 minutes",
            "--no-pager",
            "--lines",
            "200",
            "--output",
            "short-iso",
        ],
        timeout=15,
    )
    return result.stdout if result.returncode == 0 else ""


def journal_event_time(lines: str, terms: tuple[str, ...]) -> str | None:
    for line in reversed(lines.splitlines()):
        if not any(term in line.lower() for term in terms):
            continue
        match = re.match(
            r"(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:[.,]\d+)?[+-]\d{4})",
            line,
        )
        if not match:
            return None
        candidate = match.group(1).replace(",", ".")
        try:
            parsed = datetime.strptime(
                re.sub(r"(\.\d{6})\d+", r"\1", candidate),
                "%Y-%m-%dT%H:%M:%S.%f%z"
                if "." in candidate
                else "%Y-%m-%dT%H:%M:%S%z",
            )
        except ValueError:
            return None
        return parsed.astimezone(timezone.utc).isoformat()

    return None


def bind_load_facts(recent_log: str) -> dict[str, str | None]:
    reload_terms = (
        "reloading configuration succeeded",
        "received control channel command 'reconfig'",
        "received control channel command 'reload",
        "zone reload queued",
    )
    error_terms = (
        "reloading configuration failed",
        "loading configuration: failure",
        "not loaded due to errors",
        "zone load failed",
        "loading from master file",
    )
    last_reload_at = journal_event_time(recent_log, reload_terms)
    load_error = None

    for line in reversed(recent_log.splitlines()):
        lowered = line.lower()
        if any(term in lowered for term in reload_terms):
            break
        if any(term in lowered for term in error_terms) and any(
            marker in lowered
            for marker in ("failed", "failure", "error", "not loaded")
        ):
            load_error = sanitize_message(line)
            break

    return {
        "last_reload_at": last_reload_at,
        "load_error": load_error,
    }


def zone_status_from_facts(
    role: str,
    expected_serial: int,
    facts: dict[str, Any],
    recent_log: str,
    zone_name: str,
) -> dict[str, Any]:
    observed = facts.get("observed_serial")
    relevant = "\n".join(
        line for line in recent_log.splitlines()
        if zone_name.rstrip(".").lower() in line.lower()
    )
    status = str(facts.get("status", "unknown"))
    error = None
    transfer_status = None
    last_transfer_at = journal_event_time(
        relevant,
        ("transfer status: success", "transferred serial"),
    )
    last_failure_at = None
    latest_event = None
    for line in relevant.splitlines():
        event_line = line.lower()
        if any(term in event_line for term in (
            "transfer failed",
            "refresh: failure",
            "bad key",
            "not authoritative",
        )):
            latest_event = "transfer_failed"
        elif any(term in event_line for term in (
            "network unreachable",
            "connection refused",
            "timed out",
            "no route to host",
        )):
            latest_event = "primary_unreachable"
        elif "expired" in event_line or "zone has expired" in event_line:
            latest_event = "expired"
        elif (
            "transfer status: success" in event_line
            or "transferred serial" in event_line
        ):
            latest_event = "transfer_succeeded"

    if latest_event == "transfer_failed":
        status = "transfer_failed"
        transfer_status = "failed"
        error = sanitize_message(relevant[-1000:])
        last_failure_at = journal_event_time(
            relevant,
            ("transfer failed", "refresh: failure", "bad key"),
        )
    elif latest_event == "primary_unreachable":
        status = "primary_unreachable"
        transfer_status = "failed"
        error = sanitize_message(relevant[-1000:])
        last_failure_at = journal_event_time(
            relevant,
            (
                "network unreachable",
                "connection refused",
                "timed out",
                "no route to host",
            ),
        )
    elif latest_event == "expired":
        status = "expired"
        error = sanitize_message(relevant[-1000:])
        last_failure_at = journal_event_time(
            relevant,
            ("expired", "zone has expired"),
        )
    elif observed is not None and observed == expected_serial:
        status = "synchronized"
        transfer_status = "succeeded" if role == "secondary" else None
    elif observed is not None:
        status = "serial_mismatch"
    elif role == "secondary":
        status = "awaiting_transfer"

    if not facts.get("primary_address"):
        match = re.search(
            r"(?:primary|from)\s+(\[?[0-9a-f:.]+\]?)#\d+",
            relevant,
            flags=re.IGNORECASE,
        )
        if match:
            candidate = match.group(1).strip("[]")
            try:
                facts["primary_address"] = str(
                    ipaddress.ip_address(candidate)
                )
            except ValueError:
                pass

    return {
        **facts,
        "status": status,
        "transfer_status": transfer_status,
        "last_transfer_at": last_transfer_at,
        "last_failure_at": last_failure_at,
        "error": error,
        "source": (
            "bind_journal"
            if error
            else str(facts.get("source", "rndc_zonestatus"))
        ),
    }


def load_observation_state(state_dir: Path) -> dict[str, Any]:
    path = state_dir / "observability.json"
    if not path.exists():
        return {"sequence": 0, "pending": None, "last_zones": {}}
    state = read_json(path)
    return {
        "sequence": max(0, int(state.get("sequence", 0))),
        "pending": state.get("pending"),
        "last_zones": (
            state.get("last_zones")
            if isinstance(state.get("last_zones"), dict)
            else {}
        ),
    }


def save_observation_state(state_dir: Path, state: dict[str, Any]) -> None:
    write_json_state(state_dir / "observability.json", state)


def recursion_flag(config: dict[str, Any], addresses: list[str]) -> bool | None:
    dig = detected_binary(DIG_CANDIDATES)
    if not dig or not addresses:
        return None
    result = run_command(
        [dig, f"@{addresses[0]}", ".", "SOA", "+norecurse", "+time=2", "+tries=1"],
        timeout=8,
    )
    header = next(
        (line for line in result.stdout.splitlines() if "flags:" in line),
        "",
    )
    return bool(re.search(r"\bra\b", header))


def local_soa_serial(
    config: dict[str, Any],
    zone_name: str,
    addresses: list[str],
) -> int | None:
    dig = detected_binary(DIG_CANDIDATES)
    if not dig:
        return None

    targets = addresses or ["127.0.0.1"]
    for address in targets[:2]:
        result = run_command(
            [
                dig,
                f"@{address}",
                zone_name.rstrip("."),
                "SOA",
                "+norecurse",
                "+short",
                "+time=2",
                "+tries=1",
            ],
            timeout=8,
        )
        if result.returncode != 0:
            continue
        match = re.search(
            r"\s(\d{1,10})\s+\d+\s+\d+\s+\d+\s+\d+\s*$",
            result.stdout.strip(),
        )
        if match:
            return int(match.group(1))

    return None


def collect_authoritative_observation(
    config: dict[str, Any],
    manifest: list[dict[str, Any]],
) -> dict[str, Any]:
    paths = config_paths(config)
    state = load_observation_state(paths["state_dir"])
    pending = state.get("pending")
    if isinstance(pending, dict):
        return pending

    rndc = command_path(config, "rndc", "/usr/sbin/rndc")
    logs = bind_recent_events(config)
    zones: list[dict[str, Any]] = []
    listen_addresses: list[str] = []
    observed_at = utc_now()
    previous_zones = state.get("last_zones", {})

    for item in manifest:
        item_addresses = item.get("authorized_listen_addresses", [])
        listen_addresses.extend(item_addresses)
        result = run_command(
            [rndc, "zonestatus", str(item["name"]).rstrip(".")],
            timeout=15,
        )
        facts = parse_rndc_zonestatus(result.stdout)
        if facts.get("observed_serial") is None:
            fallback_serial = local_soa_serial(
                config,
                str(item["name"]),
                item_addresses,
            )
            if fallback_serial is not None:
                facts["observed_serial"] = fallback_serial
                facts["source"] = "dig_soa_local"
        if result.returncode != 0:
            facts["status"] = "unknown"
            facts["error"] = sanitize_message(
                result.stderr or result.stdout
            )
        status = zone_status_from_facts(
            str(item["type"]),
            int(item["serial"]),
            facts,
            logs,
            str(item["name"]),
        )
        previous = previous_zones.get(str(item["id"]), {})
        if not isinstance(previous, dict):
            previous = {}
        if (
            item["type"] == "secondary"
            and status["status"] == "synchronized"
        ):
            status["last_transfer_at"] = (
                status.get("last_transfer_at")
                or previous.get("last_transfer_at")
            )
        else:
            status["last_transfer_at"] = previous.get("last_transfer_at")
        if status["status"] in {
            "transfer_failed",
            "primary_unreachable",
            "expired",
        }:
            status["last_failure_at"] = (
                status.get("last_failure_at")
                or previous.get("last_failure_at")
                or observed_at
            )
        else:
            status["last_failure_at"] = previous.get("last_failure_at")

        zone_payload = {
            "zone_id": int(item["id"]),
            "role": str(item["type"]),
            **{
                key: value for key, value in status.items()
                if key in {
                    "observed_serial",
                    "status",
                    "zone_state",
                    "last_refresh_at",
                    "next_retry_at",
                    "expires_at",
                    "primary_address",
                    "transfer_status",
                    "last_transfer_at",
                    "last_failure_at",
                    "error",
                    "source",
                }
            },
        }
        zones.append(zone_payload)

    service = service_details(os_family())
    listeners = port_53_listeners()
    load_facts = bind_load_facts(logs)
    sequence = int(state["sequence"]) + 1
    payload = {
        "event_id": str(uuid.uuid4()),
        "sequence": sequence,
        "observed_at": observed_at,
        "server": {
            "service_active": bool(service["active"]),
            "tcp_53": listeners["tcp_53"],
            "udp_53": listeners["udp_53"],
            "recursion_enabled": recursion_flag(config, listen_addresses),
            "available": bool(
                service["active"]
                and listeners["tcp_53"]
                and listeners["udp_53"]
            ),
            "last_reload_at": load_facts["last_reload_at"],
            "load_error": load_facts["load_error"],
        },
        "zones": zones,
    }
    state["pending"] = payload
    save_observation_state(paths["state_dir"], state)
    return payload


def send_authoritative_observation(config: dict[str, Any]) -> dict[str, Any]:
    response = request_json(
        "GET",
        normalize_base_url(str(config["base_url"])) + "/api/agent/zones",
        token=str(config["token"]),
    )
    raw_manifest = response.get("zones")
    if not isinstance(raw_manifest, list):
        raise AgentError("Manifesto de zonas inválido.")
    manifest = [validate_manifest_item(item) for item in raw_manifest]
    payload = collect_authoritative_observation(config, manifest)
    result = request_json(
        "POST",
        normalize_base_url(str(config["base_url"]))
        + "/api/agent/bind/observations",
        payload,
        str(config["token"]),
        timeout=30,
    )
    state_dir = config_paths(config)["state_dir"]
    state = load_observation_state(state_dir)
    state["sequence"] = int(payload["sequence"])
    state["pending"] = None
    state["last_zones"] = {
        str(zone["zone_id"]): {
            "status": zone["status"],
            "last_transfer_at": zone.get("last_transfer_at"),
            "last_failure_at": zone.get("last_failure_at"),
        }
        for zone in payload["zones"]
    }
    save_observation_state(state_dir, state)
    return result


def sync_zones(
    config: dict[str, Any],
    apply: bool,
    confirmation: str | None,
) -> dict[str, Any]:
    (
        manifest,
        updates,
        staging_dir,
        include_path,
        state,
    ) = build_staging(config, apply=apply)
    paths = config_paths(config)

    if not updates:
        applied_items = [
            item
            for item in manifest
            if item.get("installed_version") == item["desired_version"]
        ]

        if applied_items:
            latest = max(
                applied_items,
                key=lambda item: item["desired_version"],
            )
            state["installed_publication_id"] = latest["publication_id"]
            state["installed_version"] = latest["installed_version"]
            state["last_apply_status"] = "applied"
            state["last_apply_error"] = None
            state["attempt_id"] = None
            save_publication_state(paths["state_dir"], state)

        return {
            "status": "up_to_date",
            "dry_run": not apply,
            "zones": len(manifest),
            "updates": 0,
            "created_at": utc_now(),
        }

    if staging_dir is None or include_path is None:
        raise AgentError("Staging ausente para atualização.")

    result: dict[str, Any] = {
        "status": "validated",
        "dry_run": not apply,
        "zones": len(manifest),
        "updates": len(updates),
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

        attempt_id = str(state["attempt_id"])
        pending_confirmation = (
            state.get("last_apply_status")
            == "applied_pending_confirmation"
        )

        if not pending_confirmation:
            for item in updates:
                checksum = state["artifacts"].get(
                    str(item["publication_id"]),
                    {},
                ).get("checksum")
                report_publication(
                    config,
                    state,
                    item["publication_id"],
                    attempt_id,
                    publication_event_payload(
                        "applying",
                        checksum=checksum,
                    ),
                )

            state["last_apply_status"] = "applying"
            state["last_apply_at"] = utc_now()
            state["last_apply_error"] = None
            save_publication_state(paths["state_dir"], state)

            try:
                backup_dir = apply_staging(
                    config,
                    manifest,
                    staging_dir,
                    include_path,
                )
            except Exception as exception:
                error = sanitize_message(exception)
                state["last_apply_status"] = "failed"
                state["last_apply_at"] = utc_now()
                state["last_apply_error"] = error

                for item in updates:
                    checksum = state["artifacts"].get(
                        str(item["publication_id"]),
                        {},
                    ).get("checksum")

                    try:
                        report_publication(
                            config,
                            state,
                            item["publication_id"],
                            attempt_id,
                            publication_event_payload(
                                "failed",
                                checksum=checksum,
                                error=error,
                            ),
                        )
                    except AgentError as report_error:
                        safe_log(
                            "Falha ao confirmar erro da publicação "
                            f"{item['publication_id']}: {report_error}"
                        )

                state["attempt_id"] = None
                save_publication_state(paths["state_dir"], state)
                raise AgentError(error) from exception

            latest = max(
                updates,
                key=lambda item: item["desired_version"],
            )
            state["installed_publication_id"] = latest["publication_id"]
            state["installed_version"] = latest["desired_version"]
            state["last_apply_status"] = "applied_pending_confirmation"
            state["last_apply_at"] = utc_now()
            state["last_apply_error"] = None
            save_publication_state(paths["state_dir"], state)
        else:
            backup_dir = None

        for item in updates:
            checksum = state["artifacts"].get(
                str(item["publication_id"]),
                {},
            ).get("checksum")
            observed_serial = authoritative_serial(
                config,
                str(item["name"]),
                int(item["serial"]),
            )
            response = report_publication(
                config,
                state,
                item["publication_id"],
                attempt_id,
                publication_event_payload(
                    "applied",
                    installed_version=item["desired_version"],
                    checksum=checksum,
                    authoritative_serial=observed_serial,
                ),
            )

            if (
                response.get("status") != "applied"
                or response.get("installed_version")
                != item["desired_version"]
                or response.get("authoritative_serial")
                != observed_serial
            ):
                raise AgentError(
                    "Painel não confirmou a versão aplicada."
                )

            state["installed_publication_id"] = item["publication_id"]
            state["installed_version"] = item["desired_version"]

        result.update(
            {
                "status": "applied",
                "dry_run": False,
                "backup_dir": (
                    str(backup_dir) if backup_dir is not None else None
                ),
                "zones_dir": str(paths["zones_dir"]),
                "managed_include": str(
                    paths["managed_include"]
                ),
            }
        )
        state["last_apply_status"] = "applied"
        state["last_apply_at"] = utc_now()
        state["last_apply_error"] = None
        state["attempt_id"] = None
        save_publication_state(paths["state_dir"], state)

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

    actions.add_argument("--request-approval", action="store_true")
    actions.add_argument("--enroll", action="store_true")
    actions.add_argument("--status", action="store_true")
    actions.add_argument("--heartbeat", action="store_true")
    actions.add_argument("--inventory", action="store_true")
    actions.add_argument("--readiness", action="store_true")
    actions.add_argument("--run-authorized-operation", action="store_true")
    actions.add_argument("--sync-zones", action="store_true")
    actions.add_argument("--observe-bind", action="store_true")

    parser.add_argument("--wait", type=int, default=1800)
    parser.add_argument(
        "--stdin",
        action="store_true",
        help="Lê o código temporário da entrada padrão; nunca use o código em argv.",
    )
    parser.add_argument("--apply", action="store_true")
    parser.add_argument("--confirm")

    return parser


def main() -> int:
    args = build_parser().parse_args()

    try:
        if args.request_approval or args.enroll:
            return request_approval(args)

        if args.status:
            return status(args)

        config = read_json(Path(args.config))

        handlers: list[tuple[bool, Callable[[], dict[str, Any]]]] = [
            (args.heartbeat, lambda: heartbeat(config)),
            (args.inventory, lambda: inventory(config)),
            (args.readiness, lambda: send_readiness(config)),
            (
                args.run_authorized_operation,
                lambda: run_authorized_operation(config),
            ),
            (
                args.sync_zones,
                lambda: sync_zones(config, args.apply, args.confirm),
            ),
            (args.observe_bind, lambda: send_authoritative_observation(config)),
        ]

        for selected, handler in handlers:
            if selected:
                print(json.dumps(handler(), indent=2, ensure_ascii=False))
                return 0

        raise AgentError("Operação não reconhecida.")
    except AgentError as exception:
        print(f"ERRO: {sanitize_message(exception)}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
