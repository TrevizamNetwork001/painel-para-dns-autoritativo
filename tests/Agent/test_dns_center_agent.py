from __future__ import annotations

import importlib.util
import io
import json
import logging
import argparse
import os
import stat
import subprocess
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
            "serial": 2026073001,
            "version": 7,
            "desired_version": 7,
            "installed_version": installed_version,
            "apply_status": "pending",
            "update_available": update,
            "artifact_url": "/api/agent/zones/10/artifact?publication=20",
            "transfer": {
                "primary_addresses": ["192.0.2.10"],
                "secondary_addresses": [],
                "tsig": None,
            },
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
                    "authorized_listen_addresses": ["192.0.2.10"],
                }
            ],
            Path("/etc/bind/dns-center-zones"),
        )

        self.assertIn('zone "example.com"', content)
        self.assertIn("type master;", content)
        self.assertNotIn("options {", content)

    def test_parse_rndc_zonestatus_normalizes_runtime_values(self) -> None:
        facts = agent.parse_rndc_zonestatus(
            "\n".join(
                [
                    "serial: 2026073001",
                    "state: running",
                    "last loaded: Thu, 30 Jul 2026 13:00:00 GMT",
                    "next refresh: Thu, 30 Jul 2026 14:00:00 GMT in 120 seconds",
                    "expires: Thu, 13 Aug 2026 13:00:00 GMT",
                    "primary: 192.0.2.10#53",
                ]
            )
        )

        self.assertEqual(2026073001, facts["observed_serial"])
        self.assertEqual("running", facts["zone_state"])
        self.assertEqual(
            "2026-07-30T13:00:00+00:00",
            facts["last_refresh_at"],
        )
        self.assertEqual(
            "2026-07-30T14:00:00+00:00",
            facts["next_retry_at"],
        )
        self.assertEqual("192.0.2.10", facts["primary_address"])

    def test_parse_rndc_zonestatus_ignores_invalid_runtime_values(self) -> None:
        facts = agent.parse_rndc_zonestatus(
            "last loaded: never\nprimary: invalid-host#53"
        )

        self.assertNotIn("last_refresh_at", facts)
        self.assertNotIn("primary_address", facts)

    def test_authoritative_status_classifies_bind_failures(self) -> None:
        cases = [
            ("transfer failed: bad key", "transfer_failed"),
            ("network unreachable", "primary_unreachable"),
            ("zone has expired", "expired"),
        ]
        for log, expected in cases:
            with self.subTest(expected=expected):
                status = agent.zone_status_from_facts(
                    "secondary",
                    2026073008,
                    {},
                    f"example.com {log}",
                    "example.com",
                )
                self.assertEqual(expected, status["status"])
                self.assertLessEqual(len(status["error"]), 1000)

    def test_authoritative_status_detects_recovered_serial(self) -> None:
        status = agent.zone_status_from_facts(
            "secondary",
            2026073008,
            {"observed_serial": 2026073008},
            "\n".join([
                "2026-07-30T19:40:13+0000 host "
                "example.com refresh: failure",
                "2026-07-30T19:40:31+0000 host "
                "example.com transferred serial 2026073008 "
                "from 192.0.2.10#53",
            ]),
            "example.com",
        )

        self.assertEqual("synchronized", status["status"])
        self.assertEqual("succeeded", status["transfer_status"])
        self.assertEqual(
            "2026-07-30T19:40:31+00:00",
            status["last_transfer_at"],
        )
        self.assertEqual("192.0.2.10", status["primary_address"])

    def test_bind_load_facts_keep_latest_reload_and_sanitized_error(
        self,
    ) -> None:
        failed = agent.bind_load_facts(
            "2026-07-30T19:40:00+0000 host "
            "zone load failed: token=secret /etc/bind/private.zone"
        )
        self.assertIsNone(failed["last_reload_at"])
        self.assertNotIn("secret", failed["load_error"])
        self.assertNotIn("/etc/bind", failed["load_error"])

        recovered = agent.bind_load_facts(
            "\n".join([
                "2026-07-30T19:40:00+0000 host zone load failed: error",
                "2026-07-30T19:41:00+0000 host "
                "reloading configuration succeeded",
            ])
        )
        self.assertEqual(
            "2026-07-30T19:41:00+00:00",
            recovered["last_reload_at"],
        )
        self.assertIsNone(recovered["load_error"])

    def test_observation_does_not_send_tsig_and_reuses_pending_event(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            manifest = [{
                **self.manifest_item(False, 7),
                "authorized_listen_addresses": ["192.0.2.10"],
                "transfer": {
                    "primary_addresses": ["192.0.2.10"],
                    "secondary_addresses": ["192.0.2.11"],
                    "tsig": {
                        "name": "key",
                        "algorithm": "hmac-sha256",
                        "secret": "never-send-this-secret",
                    },
                },
            }]
            zonestatus = Mock(
                returncode=0,
                stdout="serial: 2026073001\nstate: running\n",
                stderr="",
            )

            with patch.object(
                agent,
                "bind_recent_events",
                return_value="",
            ), patch.object(
                agent,
                "run_command",
                return_value=zonestatus,
            ) as command, patch.object(
                agent,
                "service_details",
                return_value={"active": True},
            ), patch.object(
                agent,
                "port_53_listeners",
                return_value={"tcp_53": True, "udp_53": True},
            ), patch.object(
                agent,
                "recursion_flag",
                return_value=False,
            ):
                first = agent.collect_authoritative_observation(config, manifest)
                second = agent.collect_authoritative_observation(config, manifest)

            self.assertEqual(first, second)
            self.assertEqual(1, command.call_count)
            self.assertNotIn(
                "never-send-this-secret",
                json.dumps(first),
            )
            self.assertEqual(
                2026073001,
                first["zones"][0]["observed_serial"],
            )

    def test_render_primary_secondary_with_tsig_notify_and_ixfr(self) -> None:
        tsig = {
            "name": "xfr-example",
            "algorithm": "hmac-sha256",
            "secret": "YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=",
        }
        primary = {
            "name": "example.com",
            "type": "primary",
            "transfer": {
                "primary_addresses": ["192.0.2.10"],
                "secondary_addresses": ["192.0.2.11"],
                "tsig": tsig,
            },
        }
        secondary = {
            "name": "example.net",
            "type": "secondary",
            "transfer": {
                "primary_addresses": ["192.0.2.10"],
                "secondary_addresses": ["192.0.2.11"],
                "tsig": tsig,
            },
        }

        content = agent.render_managed_include(
            [primary, secondary],
            Path("/etc/bind/zones"),
        )

        self.assertEqual(1, content.count('key "xfr-example" {'))
        self.assertIn('allow-transfer { key "xfr-example"; };', content)
        self.assertIn('also-notify { 192.0.2.11 key "xfr-example"; };', content)
        self.assertIn("notify explicit;", content)
        self.assertNotIn("provide-ixfr", content)
        self.assertIn('masters { 192.0.2.10 key "xfr-example"; };', content)
        self.assertIn("request-ixfr yes;", content)

    def test_atomic_write(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            target = Path(directory) / "state.json"
            agent.atomic_write(target, '{"ok": true}\n')

            self.assertEqual(
                '{"ok": true}\n',
                target.read_text(encoding="utf-8"),
            )

    def test_request_approval_saves_one_time_credential_after_panel_approval(
        self,
    ) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(config=str(config_path), wait=30)
            approved_response = {
                    "ok": True,
                    "status": "approved",
                    "agent": {
                        "uuid": "12e0a9ac-18d0-4efc-aaf3-93ce41545ad6",
                        "token": "permanent-token",
                    },
                    "server": {
                        "id": 1,
                        "name": "NS1",
                        "hostname": "ns1.example.test",
                        "role": "primary",
                    },
                }

            def approval_responses(
                method: str,
                url: str,
                payload: dict,
            ) -> dict:
                if url.endswith("/status"):
                    return approved_response

                return {
                    "ok": True,
                    "status": "pending",
                    "request_id": payload["request_id"],
                    "matched": True,
                }

            with patch.object(
                agent,
                "request_json",
                side_effect=approval_responses,
            ) as request, patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ):
                result = agent.request_approval(args)

            self.assertEqual(0, result)
            config = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertEqual(agent.OFFICIAL_BASE_URL, config["base_url"])
            self.assertEqual("permanent-token", config["token"])
            self.assertFalse(
                config_path.with_name("install-request.json").exists()
            )
            self.assertEqual(2, request.call_count)

    def test_request_approval_persists_custom_panel_url(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(config=str(config_path), wait=0)

            with patch.dict(
                os.environ,
                {"DNS_CENTER_PANEL_URL": "https://panel.example.test/"},
            ), patch.object(
                agent,
                "request_json",
                side_effect=lambda method, url, payload: {
                    "ok": True,
                    "status": "pending",
                    "request_id": payload["request_id"],
                },
            ) as request, patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ):
                result = agent.request_approval(args)

            self.assertEqual(0, result)
            pending = json.loads(
                config_path.with_name("install-request.json").read_text(
                    encoding="utf-8"
                )
            )
            self.assertEqual(
                "https://panel.example.test",
                pending["base_url"],
            )
            self.assertEqual(
                "https://panel.example.test/api/agent/install-requests",
                request.call_args.args[1],
            )

    def test_request_approval_requires_persistence_confirmation(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(config=str(config_path), wait=0)

            with patch.object(
                agent,
                "request_json",
                return_value={"ok": True, "status": "pending"},
            ), patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ), patch("sys.stdout", new_callable=io.StringIO) as stdout:
                with self.assertRaisesRegex(
                    agent.AgentError,
                    "não confirmou a persistência",
                ):
                    agent.request_approval(args)

            self.assertNotIn("Solicitação enviada", stdout.getvalue())

    def test_parser_removes_legacy_activation_code(self) -> None:
        parser = agent.build_parser()

        with self.assertRaises(SystemExit):
            parser.parse_args(["--enroll", "--code", "legacy"])

    def test_enroll_reads_code_from_stdin_without_reinstalling(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            code = "secure-panel-code-" + "x" * 32

            with patch("sys.stdin", io.StringIO(code + "\n")), patch.object(
                agent,
                "request_json",
                side_effect=lambda method, url, payload: {
                    "ok": True,
                    "status": "pending",
                    "request_id": payload["request_id"],
                    "matched": True,
                },
            ) as request, patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ), patch.object(agent.subprocess, "run") as subprocess_run:
                self.assertEqual(0, agent.request_approval(args))

            sent = request.call_args.args[2]
            self.assertEqual(code, sent["enrollment_code"])
            self.assertFalse(subprocess_run.called)
            pending = json.loads(
                config_path.with_name("install-request.json").read_text()
            )
            self.assertNotIn("enrollment_code", pending)
            self.assertIn("enrollment_code_hash", pending)
            self.assertEqual(0o600, stat.S_IMODE(
                config_path.with_name("install-request.json").stat().st_mode
            ))

    def test_enroll_refuses_active_credential_and_never_reads_code_from_argv(self) -> None:
        parser = agent.build_parser()
        parsed = parser.parse_args(["--enroll", "--stdin"])
        self.assertTrue(parsed.enroll)
        self.assertFalse(hasattr(parsed, "code"))

        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            agent.save_config(config_path, {"token": "active"})
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            with patch("sys.stdin", io.StringIO("x" * 48 + "\n")):
                with self.assertRaisesRegex(agent.AgentError, "credencial ativa"):
                    agent.request_approval(args)

    def test_enroll_requires_https_and_persists_request_id_for_retry(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            with patch("sys.stdin", io.StringIO("x" * 48 + "\n")), patch.dict(
                os.environ, {"DNS_CENTER_PANEL_URL": "http://panel.invalid"}
            ):
                with self.assertRaisesRegex(agent.AgentError, "localhost"):
                    agent.request_approval(args)

            self.assertFalse(config_path.exists())

    def test_enroll_with_invalid_code_fails_without_persisting_credential(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            code = "x" * 48

            with patch("sys.stdin", io.StringIO(code + "\n")), patch.object(
                agent,
                "request_json",
                side_effect=agent.AgentHttpError(
                    422, "invalid_enrollment_code", "Código temporário inválido ou expirado."
                ),
            ), patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ):
                with self.assertRaises(agent.AgentHttpError):
                    agent.request_approval(args)

            self.assertFalse(config_path.exists())

    def test_enroll_with_expired_code_fails_without_persisting_credential(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            code = "x" * 48

            with patch("sys.stdin", io.StringIO(code + "\n")), patch.object(
                agent,
                "request_json",
                side_effect=agent.AgentHttpError(
                    422, "invalid_enrollment_code", "Código temporário expirado."
                ),
            ), patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ):
                with self.assertRaisesRegex(agent.AgentHttpError, "expirado"):
                    agent.request_approval(args)

            self.assertFalse(config_path.exists())

    def test_enroll_retry_with_same_code_reuses_pending_request_id(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            code = "secure-panel-code-" + "y" * 32

            def pending_response(method: str, url: str, payload: dict) -> dict:
                return {
                    "ok": True,
                    "status": "pending",
                    "request_id": payload["request_id"],
                    "matched": True,
                }

            with patch.object(
                agent, "request_json", side_effect=pending_response
            ) as request, patch.object(
                agent,
                "os_release",
                return_value={"NAME": "Debian", "VERSION_ID": "13"},
            ):
                with patch("sys.stdin", io.StringIO(code + "\n")):
                    agent.request_approval(argparse.Namespace(
                        config=str(config_path), wait=0, enroll=True, stdin=True
                    ))
                first_request_id = request.call_args.args[2]["request_id"]

                with patch("sys.stdin", io.StringIO(code + "\n")):
                    agent.request_approval(argparse.Namespace(
                        config=str(config_path), wait=0, enroll=True, stdin=True
                    ))
                second_request_id = request.call_args.args[2]["request_id"]

            self.assertEqual(first_request_id, second_request_id)

    def test_enroll_enables_approval_timer_when_root_and_unit_present(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config_path = Path(directory) / "agent.json"
            args = argparse.Namespace(
                config=str(config_path), wait=0, enroll=True, stdin=True
            )
            code = "secure-panel-code-" + "z" * 32
            not_enabled = subprocess.CompletedProcess([], returncode=1)
            enabled = subprocess.CompletedProcess([], returncode=0)

            with patch("sys.stdin", io.StringIO(code + "\n")), patch.object(
                agent,
                "request_json",
                side_effect=lambda method, url, payload: {
                    "ok": True,
                    "status": "pending",
                    "request_id": payload["request_id"],
                    "matched": True,
                },
            ), patch.object(
                agent, "os_release", return_value={"NAME": "Debian", "VERSION_ID": "13"}
            ), patch.object(
                agent.os, "geteuid", return_value=0
            ), patch.object(
                Path, "is_file", return_value=True
            ), patch.object(
                agent, "detected_binary", return_value="/usr/bin/systemctl"
            ), patch.object(
                agent, "run_command", side_effect=[not_enabled, enabled]
            ) as run_command:
                self.assertEqual(0, agent.request_approval(args))

            run_command.assert_any_call(
                ["/usr/bin/systemctl", "enable", "--now", "dns-center-agent-approval.timer"],
                timeout=30,
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

    def test_zones_directory_is_group_writable_for_secondary_copy(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            zones = Path(directory) / "zones"
            bind_group = Mock(gr_gid=1234)

            with patch.object(
                agent.grp,
                "getgrnam",
                return_value=bind_group,
            ), patch.object(agent.os, "geteuid", return_value=0), patch.object(
                agent.os,
                "chown",
            ) as chown:
                agent.ensure_zones_directory(zones, "debian")

            self.assertEqual(0o2770, zones.stat().st_mode & 0o7777)
            chown.assert_called_once_with(zones, 0, 1234)

    def test_strip_tsig_secrets_removes_clear_text_secret(self) -> None:
        config_text = (
            'key "rndc-key" {\n'
            "    algorithm hmac-sha256;\n"
            '    secret "c3VwZXJzZWNyZXQtdmFsdWU=";\n'
            "};\n"
        )
        sanitized = agent.strip_tsig_secrets(config_text)
        self.assertNotIn("c3VwZXJzZWNyZXQ", sanitized)
        self.assertIn('secret "[removido]"', sanitized)

    def test_parse_named_conf_zones_normalizes_master_and_slave(self) -> None:
        config_text = agent.strip_tsig_secrets(
            'zone "legacy.example" {\n'
            "    type master;\n"
            '    file "/var/cache/bind/master-aut/legacy.example.hosts";\n'
            "};\n"
            'zone "example-secondary.com" {\n'
            "    type slave;\n"
            '    file "/var/cache/bind/slave/example-secondary.com";\n'
            '    masters { 10.0.0.1; };\n'
            "};\n"
        )
        zones = agent.parse_named_conf_zones(config_text)

        self.assertEqual(2, len(zones))
        self.assertEqual("legacy.example", zones[0]["name"])
        self.assertEqual("primary", zones[0]["detected_type"])
        self.assertEqual("master", zones[0]["detected_syntax"])
        self.assertEqual(
            "/var/cache/bind/master-aut/legacy.example.hosts",
            zones[0]["file"],
        )
        self.assertEqual("secondary", zones[1]["detected_type"])
        self.assertEqual("slave", zones[1]["detected_syntax"])
        self.assertIn("10.0.0.1", zones[1]["masters"])

    def test_parse_named_conf_zones_never_leaks_key_secret(self) -> None:
        config_text = agent.strip_tsig_secrets(
            'key "rndc-key" { algorithm hmac-sha256; secret "topsecretvalue"; };\n'
            'zone "example.com" {\n'
            "    type master;\n"
            '    file "/var/cache/bind/master-aut/example.com.hosts";\n'
            '    allow-transfer { key rndc-key; };\n'
            "};\n"
        )
        zones = agent.parse_named_conf_zones(config_text)

        self.assertEqual(1, len(zones))
        self.assertIn("rndc-key", zones[0]["key_references"])
        self.assertNotIn("topsecretvalue", json.dumps(zones))

    def test_safe_file_metadata_rejects_symlink(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            real_dir = Path(directory) / "real"
            real_dir.mkdir()
            (real_dir / "zone.hosts").write_text("data", encoding="utf-8")
            link_dir = Path(directory) / "link"
            link_dir.symlink_to(real_dir)

            metadata, warning = agent.safe_file_metadata(link_dir / "zone.hosts")

            self.assertIsNone(metadata)
            self.assertIsNotNone(warning)

    def test_safe_file_metadata_computes_hash_and_mode(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            zonefile = Path(directory) / "zone.hosts"
            zonefile.write_text("$ORIGIN example.com.\n", encoding="utf-8")
            zonefile.chmod(0o640)

            metadata, warning = agent.safe_file_metadata(zonefile)

            self.assertIsNone(warning)
            self.assertIsNotNone(metadata)
            self.assertEqual(len("$ORIGIN example.com.\n".encode()), metadata["size"])
            self.assertTrue(len(metadata["sha256"]) == 64)

    def test_parse_canonical_zone_dump_handles_soa_owner_continuation_and_txt(
        self,
    ) -> None:
        dump = (
            "$ORIGIN legacy.example.\n"
            "$TTL 3600\n"
            "@\t\t\t3600\tIN\tSOA\tns1.legacy.example. hostmaster.legacy.example. "
            "2026082701 3600 900 1209600 300\n"
            "@\t\t\t3600\tIN\tNS\tns1.legacy.example.\n"
            "@\t\t\t3600\tIN\tNS\tns2.legacy.example.\n"
            "www\t\t\t3600\tIN\tA\t198.51.100.242\n"
            "\t\t\t3600\tIN\tA\t198.51.100.243\n"
            "mail\t\t\t3600\tIN\tMX\t10 mail.legacy.example.\n"
            'txt1\t\t\t3600\tIN\tTXT\t"v=spf1 -all"\n'
        )

        parsed = agent.parse_canonical_zone_dump(dump, "legacy.example")

        self.assertEqual(2026082701, parsed["soa"]["serial"])
        self.assertEqual(300, parsed["soa"]["minimum"])
        names = [record["name"] for record in parsed["records"]]
        self.assertEqual(["www", "www"], [n for n in names if n == "www"])
        mx_record = next(r for r in parsed["records"] if r["type"] == "MX")
        self.assertEqual("10 mail.legacy.example.", mx_record["rdata"])
        txt_record = next(r for r in parsed["records"] if r["type"] == "TXT")
        self.assertEqual('"v=spf1 -all"', txt_record["rdata"])

    def test_parse_canonical_zone_dump_preserves_unsupported_rr_without_crashing(
        self,
    ) -> None:
        dump = (
            "$ORIGIN example.com.\n"
            "$TTL 3600\n"
            "sip._tcp\t3600\tIN\tSRV\t10 20 5060 sip.example.com.\n"
            "www\t3600\tIN\tA\t1.2.3.4\n"
        )

        parsed = agent.parse_canonical_zone_dump(dump, "example.com")

        self.assertEqual(["SRV"], parsed["unsupported_record_types"])
        self.assertEqual(1, len(parsed["records"]))
        self.assertEqual("A", parsed["records"][0]["type"])

    def test_parse_canonical_zone_dump_handles_ptr_and_reverse_zone(self) -> None:
        dump = (
            "$ORIGIN 192.0.2.in-addr.arpa.\n"
            "$TTL 3600\n"
            "1\t3600\tIN\tPTR\tns1.legacy.example.\n"
            "2\t3600\tIN\tPTR\tmail.legacy.example.\n"
        )

        parsed = agent.parse_canonical_zone_dump(dump, "192.0.2.in-addr.arpa")

        self.assertEqual(2, len(parsed["records"]))
        self.assertEqual("PTR", parsed["records"][0]["type"])
        self.assertEqual("1", parsed["records"][0]["name"])

    def test_parse_canonical_zone_dump_handles_large_zone_within_limit(self) -> None:
        lines = ["$ORIGIN big.example.com.", "$TTL 3600"]
        for index in range(1030):
            lines.append(f"host{index}\t3600\tIN\tA\t10.0.{index // 256}.{index % 256}")
        dump = "\n".join(lines) + "\n"

        parsed = agent.parse_canonical_zone_dump(dump, "big.example.com")

        self.assertEqual(1030, len(parsed["records"]))

    def test_parse_canonical_zone_dump_enforces_record_limit(self) -> None:
        original_limit = agent.DISCOVERY_MAX_RECORDS_PER_ZONE
        agent.DISCOVERY_MAX_RECORDS_PER_ZONE = 10
        try:
            lines = ["$ORIGIN example.com.", "$TTL 3600"]
            for index in range(20):
                lines.append(f"host{index}\t3600\tIN\tA\t10.0.0.{index}")
            dump = "\n".join(lines) + "\n"

            with self.assertRaises(agent.AgentError):
                agent.parse_canonical_zone_dump(dump, "example.com")
        finally:
            agent.DISCOVERY_MAX_RECORDS_PER_ZONE = original_limit

    def test_discover_bind_zones_never_calls_write_commands_and_skips_secondary_dump(
        self,
    ) -> None:
        commands: list[list[str]] = []

        def fake_run_command(command: list[str], timeout: int = 30):
            commands.append(command)
            if command[:2] == ["/usr/sbin/rndc", "status"]:
                return Mock(returncode=0, stdout="server is up and running\nnumber of zones: 2 (0 automatic)\n", stderr="")
            if command[:2] == ["/usr/bin/named-checkconf", "-p"]:
                return Mock(
                    returncode=0,
                    stdout=(
                        'zone "example.com" {\n    type master;\n'
                        '    file "/var/cache/bind/master-aut/example.com.hosts";\n};\n'
                        'zone "example-secondary.com" {\n    type slave;\n'
                        '    file "/var/cache/bind/slave/example-secondary.com";\n};\n'
                    ),
                    stderr="",
                )
            if command[:2] == ["/usr/sbin/rndc", "zonestatus"]:
                return Mock(
                    returncode=0,
                    stdout="serial: 2026082701\nnodes: 3\nsecure: no\ndynamic: no\n",
                    stderr="",
                )
            if command[0] == "/usr/bin/named-checkzone":
                return Mock(
                    returncode=0,
                    stdout=(
                        "$ORIGIN example.com.\n$TTL 3600\n"
                        "@\t3600\tIN\tSOA\tns1.example.com. hostmaster.example.com. 1 3600 900 1209600 300\n"
                        "www\t3600\tIN\tA\t1.2.3.4\n"
                    ),
                    stderr="",
                )
            raise AssertionError(f"unexpected command: {command}")

        with tempfile.TemporaryDirectory() as directory:
            zonefile = Path(directory) / "example.com.hosts"
            zonefile.write_text("dummy", encoding="utf-8")

            with patch.object(
                agent, "detected_binary",
                side_effect=lambda candidates: candidates[0],
            ), patch.object(
                agent, "detected_named_conf", return_value="/etc/bind/named.conf",
            ), patch.object(
                agent, "run_command", side_effect=fake_run_command,
            ), patch.object(
                agent, "safe_file_metadata",
                side_effect=lambda path: (
                    {"size": 5, "mode": "0640", "mtime": "2026-08-27T00:00:00+00:00",
                     "owner": "bind", "group": "bind", "sha256": "a" * 64},
                    None,
                ),
            ):
                result = agent.discover_bind_zones({})

        write_tokens = ("reload", "reconfig", "freeze", "thaw", "addzone", "delzone")
        for command in commands:
            self.assertFalse(any(token in command for token in write_tokens))
            self.assertNotIn("systemctl", command[0])

        zone_names = {zone["name"]: zone for zone in result["zones"]}
        self.assertIn("example.com", zone_names)
        self.assertIn("example-secondary.com", zone_names)
        self.assertIsNotNone(zone_names["example.com"]["records"])
        self.assertIsNone(zone_names["example-secondary.com"]["records"])
        self.assertFalse(
            any(c[0] == "/usr/bin/named-checkzone" and "example-secondary.com" in c
                for c in commands)
        )

    def test_run_authorized_operation_dispatches_discovery_without_readiness(
        self,
    ) -> None:
        with patch.object(
            agent, "request_json",
            return_value={"operation": {
                "id": 7, "action": "discover_bind_zones",
                "authorization_nonce": "nonce", "authorized_at": None,
            }},
        ) as request, patch.object(
            agent, "discover_bind_zones", return_value={"zones": []},
        ) as discover, patch.object(
            agent, "send_readiness",
        ) as readiness:
            result = agent.run_authorized_operation({"base_url": "https://panel.test", "token": "t"})

        self.assertEqual("succeeded", result["status"])
        discover.assert_called_once()
        readiness.assert_not_called()
        self.assertGreaterEqual(request.call_count, 2)

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

    def test_primary_apply_reloads_zone_after_reconfig(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            config = self.config(directory)
            paths = {
                "zones_dir": Path(config["zones_dir"]),
                "managed_include": Path(config["managed_include"]),
                "backup_dir": Path(config["backup_dir"]),
                "named_conf": Path(directory) / "named.conf",
                "options_config": Path(directory) / "named.conf.options",
                "managed_options_include": (
                    Path(directory) / "dns-center-options.conf"
                ),
            }
            paths["backup_dir"].mkdir(parents=True)
            paths["named_conf"].write_text(
                'include "named.conf.options";\n',
                encoding="utf-8",
            )
            paths["options_config"].write_text(
                "options {\n    recursion yes;\n};\n",
                encoding="utf-8",
            )
            staging = Path(directory) / "staging"
            staging.mkdir()
            include = staging / "managed.conf"
            include.write_text("// valid\n", encoding="utf-8")
            (staging / "example.com.zone").write_text(
                "$ORIGIN example.com.\n",
                encoding="utf-8",
            )
            completed = Mock(returncode=0, stdout="", stderr="")

            with patch.object(
                agent,
                "config_paths",
                return_value=paths,
            ), patch.object(
                agent,
                "ensure_zones_directory",
            ), patch.object(
                agent,
                "run_command",
                return_value=completed,
            ) as run:
                agent.apply_staging(
                    config,
                    [{
                        "name": "example.com",
                        "type": "primary",
                        "authorized_listen_addresses": ["192.0.2.10"],
                    }],
                    staging,
                    include,
                )

            commands = [call.args[0] for call in run.call_args_list]
            self.assertIn(["/usr/sbin/rndc", "reconfig"], commands)
            self.assertIn(
                ["/usr/sbin/rndc", "reload", "example.com"],
                commands,
            )
            self.assertIn(
                "recursion no;",
                paths["managed_options_include"].read_text(encoding="utf-8"),
            )

    def test_authoritative_options_are_scoped_to_authorized_addresses(
        self,
    ) -> None:
        rendered = agent.render_authoritative_options(
            ["2001:db8::10", "192.0.2.10"]
        )

        self.assertIn("recursion no;", rendered)
        self.assertIn("allow-recursion { none; };", rendered)
        self.assertIn("allow-query-cache { none; };", rendered)
        self.assertIn("listen-on { 192.0.2.10; };", rendered)
        self.assertIn("listen-on-v6 { 2001:db8::10; };", rendered)
        self.assertNotIn("allow-query { any; };", rendered)

    def test_options_include_replaces_only_conflicting_directives(self) -> None:
        original = """
options {
    directory "/var/cache/bind";
    recursion yes;
    listen-on-v6 { any; };
    dnssec-validation auto;
};
"""
        include = Path("/etc/bind/dns-center-options.conf")
        updated = agent.install_authoritative_options_include(
            original,
            include,
        )

        self.assertIn('directory "/var/cache/bind";', updated)
        self.assertIn("dnssec-validation auto;", updated)
        self.assertNotIn("recursion yes;", updated)
        self.assertNotIn("listen-on-v6 { any; };", updated)
        self.assertEqual(
            1,
            updated.count(f'include "{include}";'),
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
                    "authoritative_serial": payload.get("authoritative_serial"),
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
            ), patch.object(
                agent,
                "authoritative_serial",
                return_value=2026073001,
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
                    "authoritative_serial": 2026073001,
                },
            ) as report, patch.object(
                agent,
                "apply_staging",
            ) as apply, patch.dict(
                agent.os.environ,
                {"DNS_CENTER_AGENT_ALLOW_APPLY": "1"},
            ), patch.object(
                agent,
                "authoritative_serial",
                return_value=2026073001,
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
