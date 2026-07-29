from __future__ import annotations

import importlib.util
import io
import json
import logging
import tempfile
import unittest
import urllib.error
from email.message import Message
from pathlib import Path
from unittest.mock import Mock, patch


MODULE_PATH = (
    Path(__file__).resolve().parents[2]
    / "agent"
    / "dns-center-agent.py"
)

SPEC = importlib.util.spec_from_file_location(
    "dns_center_agent",
    MODULE_PATH,
)

assert SPEC is not None
assert SPEC.loader is not None

agent = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(agent)


class AgentTests(unittest.TestCase):
    def config(self, directory: str) -> dict:
        root = Path(directory)

        return {
            "base_url": "https://dns.example",
            "token": "top-secret-token",
            "server": {"name": "NS1"},
            "state_dir": str(root / "state"),
            "zones_dir": str(root / "zones"),
            "managed_include": str(root / "managed.conf"),
            "backup_dir": str(root / "backups"),
            "named_checkzone": "/usr/bin/named-checkzone",
            "named_checkconf": "/usr/bin/named-checkconf",
            "rndc": "/usr/sbin/rndc",
        }

    def manifest_item(
        self,
        update: bool = True,
        installed_version: int | None = None,
    ) -> dict:
        return {
            "id": 10,
            "publication_id": 20,
            "name": "example.com",
            "type": "primary",
            "version": 7,
            "desired_version": 7,
            "installed_version": installed_version,
            "apply_status": "pending",
            "update_available": update,
            "artifact_url": "/api/agent/zones/10/artifact?publication=20",
        }

    def test_safe_zone_filename(self) -> None:
        self.assertEqual(
            "example.com.zone",
            agent.safe_zone_filename("example.com."),
        )

        with self.assertRaises(agent.AgentError):
            agent.safe_zone_filename("../example.com")

    def test_render_managed_include(self) -> None:
        content = agent.render_managed_include(
            [
                {
                    "name": "example.com",
                    "type": "primary",
                }
            ],
            Path("/etc/bind/dns-center-zones"),
        )

        self.assertIn('zone "example.com"', content)
        self.assertIn("type master;", content)

    def test_atomic_write(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "state.json"
            agent.atomic_write(target, '{"ok": true}\n')

            self.assertEqual(
                '{"ok": true}\n',
                target.read_text(encoding="utf-8"),
            )

    def test_apply_requires_environment_and_confirmation(self) -> None:
        config = {
            "base_url": "https://dns.example",
            "token": "secret",
            "server": {"name": "NS1"},
        }

        with self.assertRaises(agent.AgentError):
            agent.sync_zones(
                config,
                True,
                "APLICAR ZONAS NS1",
            )

    def test_remote_http_is_rejected_but_localhost_is_allowed(self) -> None:
        with self.assertRaises(agent.AgentError):
            agent.normalize_base_url("http://dns.example")

        self.assertEqual(
            "http://127.0.0.1:8080",
            agent.normalize_base_url("http://127.0.0.1:8080/"),
        )

    def test_installation_catalog_contains_only_fixed_commands(self) -> None:
        commands = agent.fixed_operation_commands(
            "install_bind",
            "debian",
        )

        self.assertEqual("/usr/bin/apt-get", commands[0][0])
        self.assertIn("bind9", commands[1])

        with self.assertRaises(agent.AgentError):
            agent.fixed_operation_commands(
                "rm -rf /",
                "debian",
            )

    def test_bind_systemd_service_uses_canonical_named_unit(self) -> None:
        self.assertEqual("named", agent.bind_service_name("debian"))
        self.assertEqual("named", agent.bind_service_name("rhel"))

        with self.assertRaises(agent.AgentError):
            agent.bind_service_name("unsupported")

    def test_command_execution_disables_shell_and_has_timeout(self) -> None:
        completed = Mock(returncode=0, stdout="ok", stderr="")

        with patch.object(
            agent.subprocess,
            "run",
            return_value=completed,
        ) as run:
            agent.run_command(["/usr/bin/true"], timeout=7)

        run.assert_called_once_with(
            ["/usr/bin/true"],
            check=False,
            text=True,
            capture_output=True,
            timeout=7,
            shell=False,
        )

    def test_symlink_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            target = root / "real"
            target.mkdir()
            link = root / "link"
            link.symlink_to(target, target_is_directory=True)

            with self.assertRaises(agent.AgentError):
                agent.reject_symlink(link / "managed.conf")

    def test_named_checkzone_failure_blocks_staging(self) -> None:
        failed = Mock(returncode=1, stdout="", stderr="invalid")
        config = {
            "named_checkzone": "/usr/bin/named-checkzone",
            "named_checkconf": "/usr/bin/named-checkconf",
        }

        with tempfile.TemporaryDirectory() as directory:
            staging = Path(directory)
            (staging / "example.com.zone").write_text(
                "invalid",
                encoding="utf-8",
            )

            with patch.object(
                agent,
                "run_command",
                return_value=failed,
            ), self.assertRaises(agent.AgentError):
                agent.validate_staging(
                    config,
                    [{"name": "example.com"}],
                    staging,
                    staging / "managed.conf",
                )

    def test_configuration_failure_restores_named_conf(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            named_conf = root / "named.conf"
            managed = root / "dns-center-managed.conf"
            zones = root / "zones"
            backups = root / "backups"
            named_conf.write_text(
                'options { recursion no; };\n',
                encoding="utf-8",
            )
            failed = Mock(
                returncode=1,
                stdout="",
                stderr="invalid",
            )

            with patch.object(
                agent,
                "os_family",
                return_value="debian",
            ), patch.object(
                agent,
                "bind_paths",
                return_value={
                    "named_conf": named_conf,
                    "managed_include": managed,
                    "zones_dir": zones,
                },
            ), patch.object(
                agent,
                "detected_binary",
                side_effect=lambda candidates: candidates[0],
            ), patch.object(
                agent,
                "run_command",
                return_value=failed,
            ), patch.object(
                agent,
                "DEFAULT_BACKUP_DIR",
                backups,
            ):
                with self.assertRaises(
                    agent.AgentOperationError,
                ) as raised:
                    agent.configure_bind("configure_bind")

            self.assertTrue(raised.exception.rolled_back)
            self.assertEqual(
                'options { recursion no; };\n',
                named_conf.read_text(encoding="utf-8"),
            )
            self.assertFalse(managed.exists())

    def test_manifest_without_update_does_not_download_or_apply(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            item = self.manifest_item(False, 7)

            with patch.object(
                agent,
                "request_json",
                return_value={"zones": [item]},
            ), patch.object(agent, "request_artifact") as download, patch.object(
                agent,
                "apply_staging",
            ) as apply:
                result = agent.sync_zones(config, False, None)

            self.assertEqual("up_to_date", result["status"])
            download.assert_not_called()
            apply.assert_not_called()

    def test_manifest_with_update_downloads_to_agent_staging(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            item = self.manifest_item()

            def download(*args, **kwargs):
                destination = args[2]
                agent.atomic_write(destination, "$ORIGIN example.com.\n", 0o640)
                return {
                    "checksum": "a" * 64,
                    "size": 21,
                    "content_type": "text/plain",
                    "publication_id": "20",
                    "version": "7",
                    "server_checksum": None,
                }

            with patch.object(
                agent,
                "request_json",
                return_value={"zones": [item]},
            ), patch.object(
                agent,
                "request_artifact",
                side_effect=download,
            ), patch.object(agent, "validate_staging"):
                result = agent.sync_zones(config, False, None)

            self.assertEqual("validated", result["status"])
            self.assertIn("/staging/", result["staging_dir"])
            state = agent.read_json(Path(config["state_dir"]) / "state.json")
            self.assertEqual(20, state["downloaded_publication_id"])
            self.assertNotIn("top-secret-token", json.dumps(state))

    def test_checksum_mismatch_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            item = self.manifest_item()
            item["artifact_checksum"] = "b" * 64

            def download(*args, **kwargs):
                agent.atomic_write(args[2], "zone", 0o640)
                return {
                    "checksum": "a" * 64,
                    "size": 4,
                    "content_type": "text/plain",
                    "publication_id": "20",
                    "version": "7",
                    "server_checksum": None,
                }

            with patch.object(
                agent,
                "request_json",
                return_value={"zones": [item]},
            ), patch.object(
                agent,
                "request_artifact",
                side_effect=download,
            ), self.assertRaisesRegex(agent.AgentError, "Checksum"):
                agent.build_staging(config)

    def test_manifest_path_traversal_is_rejected(self) -> None:
        item = self.manifest_item()
        item["artifact_url"] = "/api/../secret"

        with self.assertRaises(agent.AgentError):
            agent.validate_manifest_item(item)

    def test_arbitrary_zone_filename_is_rejected(self) -> None:
        item = self.manifest_item()
        item["name"] = "example.com;touch /tmp/pwn"

        with self.assertRaises(agent.AgentError):
            agent.validate_manifest_item(item)

    def test_state_restart_preserves_installed_version(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            state_dir = Path(directory)
            state = agent.empty_publication_state()
            state["installed_publication_id"] = 20
            state["installed_version"] = 7
            state["last_apply_status"] = "applied"
            agent.save_publication_state(state_dir, state)

            loaded = agent.load_publication_state(state_dir)

            self.assertEqual(20, loaded["installed_publication_id"])
            self.assertEqual(7, loaded["installed_version"])
            self.assertEqual("applied", loaded["last_apply_status"])

    def test_same_payload_reuses_persisted_event_id(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            state = agent.empty_publication_state()
            payload = agent.publication_event_payload("applying")
            sent = []

            def request(*args, **kwargs):
                sent.append(args[2]["event_id"])
                return {"status": "applying"}

            with patch.object(agent, "request_json", side_effect=request):
                agent.report_publication(config, state, 20, "attempt", payload)
                restored = agent.load_publication_state(
                    Path(config["state_dir"])
                )
                agent.report_publication(
                    config,
                    restored,
                    20,
                    "attempt",
                    payload,
                )

            self.assertEqual(sent[0], sent[1])

    def test_divergent_payload_uses_new_event_id(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            state = agent.empty_publication_state()
            sent = []

            def request(*args, **kwargs):
                sent.append(args[2]["event_id"])
                return {"status": args[2]["status"]}

            with patch.object(agent, "request_json", side_effect=request):
                agent.report_publication(
                    config,
                    state,
                    20,
                    "attempt",
                    agent.publication_event_payload("applying"),
                )
                agent.report_publication(
                    config,
                    state,
                    20,
                    "attempt",
                    agent.publication_event_payload(
                        "failed",
                        error="falhou",
                    ),
                )

            self.assertNotEqual(sent[0], sent[1])

    def test_error_message_is_sanitized_and_limited(self) -> None:
        message = (
            "<b>erro</b>\x01 token=segredo "
            "/etc/bind/private sudo rm -rf / " + "x" * 2000
        )
        sanitized = agent.sanitize_message(message)

        self.assertNotIn("<b>", sanitized)
        self.assertNotIn("segredo", sanitized)
        self.assertNotIn("/etc/bind", sanitized)
        self.assertNotIn("sudo rm", sanitized)
        self.assertLessEqual(len(sanitized), 1000)

    def test_401_is_not_retried(self) -> None:
        error = self.http_error(401)

        with patch.object(
            agent.urllib.request,
            "urlopen",
            side_effect=error,
        ) as urlopen, self.assertRaises(agent.AgentHttpError):
            agent.request_json("GET", "https://dns.example", retries=3)

        urlopen.assert_called_once()

    def test_429_respects_retry_after(self) -> None:
        error = self.http_error(429, retry_after="4")
        response = Mock()
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)
        response.read.return_value = b'{"ok": true}'

        with patch.object(
            agent.urllib.request,
            "urlopen",
            side_effect=[error, response],
        ), patch.object(agent.time, "sleep") as sleep:
            result = agent.request_json(
                "GET",
                "https://dns.example",
                retries=2,
            )

        self.assertTrue(result["ok"])
        sleep.assert_called_once_with(4.0)

    def test_5xx_uses_bounded_backoff(self) -> None:
        error = self.http_error(503)

        with patch.object(
            agent.urllib.request,
            "urlopen",
            side_effect=error,
        ), patch.object(agent.time, "sleep") as sleep, self.assertRaises(
            agent.AgentHttpError
        ):
            agent.request_json(
                "GET",
                "https://dns.example",
                retries=2,
            )

        sleep.assert_called_once_with(1)

    def test_token_is_not_logged_by_safe_logger(self) -> None:
        logger = Mock(spec=logging.Logger)

        with patch.object(agent, "LOGGER", logger):
            agent.safe_log("authorization=top-secret-token")

        self.assertNotIn(
            "top-secret-token",
            str(logger.info.call_args),
        )

    def test_failed_apply_preserves_previous_installed_version(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            state = agent.empty_publication_state()
            state.update(
                {
                    "installed_publication_id": 19,
                    "installed_version": 6,
                    "attempt_id": "attempt",
                }
            )
            state["artifacts"]["20"] = {
                "checksum": "a" * 64,
                "version": 7,
            }
            staging = Path(directory) / "staging"
            staging.mkdir()
            include = staging / "managed.conf"
            include.write_text("", encoding="utf-8")

            with patch.object(
                agent,
                "build_staging",
                return_value=(
                    [self.manifest_item()],
                    [self.manifest_item()],
                    staging,
                    include,
                    state,
                ),
            ), patch.object(
                agent,
                "report_publication",
                return_value={"status": "applying"},
            ), patch.object(
                agent,
                "apply_staging",
                side_effect=agent.AgentError("apply failed"),
            ), patch.dict(
                agent.os.environ,
                {"DNS_CENTER_AGENT_ALLOW_APPLY": "1"},
            ), patch.object(
                agent.os,
                "geteuid",
                return_value=0,
            ), self.assertRaises(agent.AgentError):
                agent.sync_zones(config, True, "APLICAR ZONAS NS1")

            restored = agent.load_publication_state(
                Path(config["state_dir"])
            )
            self.assertEqual(6, restored["installed_version"])
            self.assertEqual("failed", restored["last_apply_status"])

    def test_staging_only_never_reports_applied(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)

            with patch.object(
                agent,
                "request_json",
                return_value={"zones": [self.manifest_item()]},
            ), patch.object(
                agent,
                "request_artifact",
                side_effect=self.fake_download,
            ), patch.object(agent, "validate_staging"), patch.object(
                agent,
                "report_publication",
            ) as report:
                result = agent.sync_zones(config, False, None)

            self.assertEqual("validated", result["status"])
            report.assert_not_called()

    def test_success_reports_applying_then_applied_after_real_apply(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            state = agent.empty_publication_state()
            state["attempt_id"] = "attempt"
            state["artifacts"]["20"] = {
                "checksum": "a" * 64,
                "version": 7,
            }
            staging = Path(directory) / "staging"
            staging.mkdir()
            include = staging / "managed.conf"
            include.write_text("", encoding="utf-8")
            statuses = []

            def report(*args, **kwargs):
                payload = args[4]
                statuses.append(payload["status"])

                return {
                    "status": payload["status"],
                    "installed_version": payload.get("installed_version"),
                }

            with patch.object(
                agent,
                "build_staging",
                return_value=(
                    [self.manifest_item()],
                    [self.manifest_item()],
                    staging,
                    include,
                    state,
                ),
            ), patch.object(
                agent,
                "report_publication",
                side_effect=report,
            ), patch.object(
                agent,
                "apply_staging",
                return_value=Path(directory) / "backup",
            ), patch.dict(
                agent.os.environ,
                {"DNS_CENTER_AGENT_ALLOW_APPLY": "1"},
            ), patch.object(agent.os, "geteuid", return_value=0):
                result = agent.sync_zones(
                    config,
                    True,
                    "APLICAR ZONAS NS1",
                )

            self.assertEqual("applied", result["status"])
            self.assertEqual(["applying", "applied"], statuses)
            restored = agent.load_publication_state(
                Path(config["state_dir"])
            )
            self.assertEqual(7, restored["installed_version"])

    def test_restart_after_real_apply_only_retries_confirmation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            state = agent.empty_publication_state()
            state.update(
                {
                    "attempt_id": "attempt",
                    "last_apply_status": "applied_pending_confirmation",
                    "installed_publication_id": 20,
                    "installed_version": 7,
                }
            )
            state["artifacts"]["20"] = {
                "checksum": "a" * 64,
                "version": 7,
            }
            staging = Path(directory) / "staging"
            staging.mkdir()
            include = staging / "managed.conf"
            include.write_text("", encoding="utf-8")

            with patch.object(
                agent,
                "build_staging",
                return_value=(
                    [self.manifest_item()],
                    [self.manifest_item()],
                    staging,
                    include,
                    state,
                ),
            ), patch.object(
                agent,
                "report_publication",
                return_value={
                    "status": "applied",
                    "installed_version": 7,
                },
            ) as report, patch.object(
                agent,
                "apply_staging",
            ) as apply, patch.dict(
                agent.os.environ,
                {"DNS_CENTER_AGENT_ALLOW_APPLY": "1"},
            ), patch.object(agent.os, "geteuid", return_value=0):
                agent.sync_zones(
                    config,
                    True,
                    "APLICAR ZONAS NS1",
                )

            apply.assert_not_called()
            self.assertEqual("applied", report.call_args.args[4]["status"])

    def test_truncated_download_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            response = self.artifact_response(
                [b"zone", b""],
                content_length="10",
            )

            with patch.object(
                agent.urllib.request,
                "urlopen",
                return_value=response,
            ), self.assertRaisesRegex(agent.AgentError, "truncado"):
                agent.request_artifact(
                    "https://dns.example/artifact",
                    "token",
                    Path(directory) / "zone",
                    retries=1,
                )

    def test_invalid_content_type_is_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            response = self.artifact_response(
                [b"<html>", b""],
                content_type="text/html",
            )

            with patch.object(
                agent.urllib.request,
                "urlopen",
                return_value=response,
            ), self.assertRaisesRegex(agent.AgentError, "Content-Type"):
                agent.request_artifact(
                    "https://dns.example/artifact",
                    "token",
                    Path(directory) / "zone",
                    retries=1,
                )

    def test_valid_download_is_atomically_saved(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            destination = Path(directory) / "zone"
            response = self.artifact_response(
                [b"zone", b""],
                content_length="4",
            )

            with patch.object(
                agent.urllib.request,
                "urlopen",
                return_value=response,
            ):
                metadata = agent.request_artifact(
                    "https://dns.example/artifact",
                    "token",
                    destination,
                    retries=1,
                )

            self.assertEqual(b"zone", destination.read_bytes())
            self.assertEqual(4, metadata["size"])

    @staticmethod
    def artifact_response(
        chunks,
        content_length: str | None = None,
        content_type: str = "text/plain",
    ):
        headers = Message()
        headers["Content-Type"] = content_type

        if content_length is not None:
            headers["Content-Length"] = content_length

        response = Mock()
        response.headers = headers
        response.read.side_effect = chunks
        response.__enter__ = Mock(return_value=response)
        response.__exit__ = Mock(return_value=False)

        return response

    @staticmethod
    def fake_download(*args, **kwargs):
        agent.atomic_write(args[2], "$ORIGIN example.com.\n", 0o640)

        return {
            "checksum": "a" * 64,
            "size": 21,
            "content_type": "text/plain",
            "publication_id": "20",
            "version": "7",
            "server_checksum": None,
        }

    @staticmethod
    def http_error(status: int, retry_after: str | None = None):
        headers = Message()

        if retry_after is not None:
            headers["Retry-After"] = retry_after

        return urllib.error.HTTPError(
            "https://dns.example",
            status,
            "failure",
            headers,
            io.BytesIO(
                b'{"error":"request_failed","message":"failure"}'
            ),
        )


if __name__ == "__main__":
    unittest.main()
