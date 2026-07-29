from __future__ import annotations

import importlib.util
import tempfile
import unittest
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


if __name__ == "__main__":
    unittest.main()
