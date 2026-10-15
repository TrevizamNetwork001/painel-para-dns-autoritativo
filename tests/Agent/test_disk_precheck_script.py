import os
import pathlib
import stat
import subprocess
import tempfile
import unittest


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
SCRIPT = PROJECT_ROOT / "deploy" / "disk-precheck.sh"


class DiskPrecheckScriptTest(unittest.TestCase):
    def _run(self, used: int, free: int, estimated: int = 0, env=None, df_fails=False):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            fake_df = root / "df"
            if df_fails:
                body = "exit 1"
            else:
                body = (
                    "printf 'Filesystem 1B-blocks Used Available Use%% Mounted\\n'\n"
                    f"printf '/dev/fake 10000000000 1 {free} {used}%% /\\n'"
                )
            fake_df.write_text(f"#!/bin/sh\n{body}\n")
            fake_df.chmod(fake_df.stat().st_mode | stat.S_IEXEC)
            run_env = {
                "PATH": f"{root}:{os.environ.get('PATH', '')}",
                "DNS_CENTER_PRECHECK_MIN_FREE_BYTES": "5000000000",
                "DNS_CENTER_PRECHECK_RESERVE_BYTES": "5000000000",
                **(env or {}),
            }
            return subprocess.run(
                ["bash", str(SCRIPT), "--operation", "test-build",
                 "--estimated-bytes", str(estimated)],
                capture_output=True, text=True, env=run_env,
            )

    def test_script_has_valid_bash_syntax(self):
        result = subprocess.run(["bash", "-n", str(SCRIPT)], capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr)

    def test_allows_operation_with_safe_margin(self):
        result = self._run(70, 10_000_000_000, estimated=2_000_000_000)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("PRECHECK_OK", result.stdout)

    def test_blocks_at_eighty_percent(self):
        result = self._run(80, 10_000_000_000)
        self.assertEqual(20, result.returncode)
        self.assertIn("PRECHECK_BLOCKED", result.stderr)

    def test_blocks_when_free_space_is_below_minimum(self):
        result = self._run(70, 4_999_999_999)
        self.assertEqual(20, result.returncode)
        self.assertIn("mínimo", result.stderr)

    def test_blocks_when_estimate_would_consume_reserve(self):
        result = self._run(70, 6_000_000_000, estimated=2_000_000_000)
        self.assertEqual(20, result.returncode)
        self.assertIn("compromete reserva", result.stderr)

    def test_filesystem_failure_has_distinct_exit_code(self):
        result = self._run(0, 0, df_fails=True)
        self.assertEqual(21, result.returncode)
        self.assertIn("PRECHECK_ERROR", result.stderr)

    def test_invalid_configuration_has_distinct_exit_code(self):
        result = self._run(70, 10_000_000_000, env={
            "DNS_CENTER_PRECHECK_MAX_USED_PERCENT": "invalid",
        })
        self.assertEqual(21, result.returncode)


if __name__ == "__main__":
    unittest.main()
