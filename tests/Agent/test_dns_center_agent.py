from __future__ import annotations

import importlib.util
import tempfile
import unittest
from pathlib import Path


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


if __name__ == "__main__":
    unittest.main()
