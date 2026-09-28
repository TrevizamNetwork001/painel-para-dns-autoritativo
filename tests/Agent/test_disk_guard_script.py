import os
import pathlib
import stat
import subprocess
import tempfile
import unittest

PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT = PROJECT_ROOT / "deploy" / "disk-guard.sh"


class DiskGuardScriptTest(unittest.TestCase):
    def test_script_has_valid_bash_syntax(self):
        result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)

    def _fake_bin(self, directory: pathlib.Path, name: str, script: str) -> None:
        path = directory / name
        path.write_text(f"#!/bin/sh\n{script}\n")
        path.chmod(path.stat().st_mode | stat.S_IEXEC | stat.S_IXGRP | stat.S_IXOTH)

    def _run(self, directory: pathlib.Path, used_percent: int, env: dict | None = None):
        # Stub "df -P/-h -P <mount>" com uma saída fixa no formato que o
        # script espera (mesmas colunas do df real), sem depender do
        # filesystem de verdade.
        self._fake_bin(
            directory,
            "df",
            f"printf 'Filesystem 1024-blocks Used Available Capacity Mounted\\n"
            f"/dev/fake 1000 500 500 {used_percent}%% /\\n'\n",
        )
        # O df de verdade fica escondido atrás do fake (que vem primeiro no PATH);
        # o resto do PATH real continua disponível (awk, date, docker se houver).
        run_env = {"PATH": f"{directory}:{os.environ.get('PATH', '')}", **(env or {})}

        return subprocess.run(
            ["bash", str(SCRIPT)],
            capture_output=True,
            text=True,
            env=run_env,
        )

    def test_logs_usage_without_warning_below_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), used_percent=42)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("42% em uso", result.stdout)
        self.assertNotIn("AVISO", result.stdout)

    def test_warns_when_usage_reaches_the_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), used_percent=85)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("85% em uso", result.stdout)
        self.assertIn("AVISO", result.stdout)
        self.assertIn("85%", result.stdout.split("AVISO", 1)[1])

    def test_custom_threshold_is_respected(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory),
                used_percent=60,
                env={"DNS_CENTER_DISK_WARN_PERCENT": "50"},
            )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("AVISO", result.stdout)

    def test_rejects_non_numeric_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory),
                used_percent=10,
                env={"DNS_CENTER_DISK_WARN_PERCENT": "abc"},
            )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("DNS_CENTER_DISK_WARN_PERCENT", result.stderr)


if __name__ == "__main__":
    unittest.main()
