import os
import pathlib
import stat
import subprocess
import tempfile
import unittest
import fcntl

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

    def _run(
        self,
        directory: pathlib.Path,
        used_percent: int,
        env: dict | None = None,
        image_tags: list[str] | None = None,
        state: dict | None = None,
        df_fails: bool = False,
        docker_available: bool = True,
        curl_exit: int = 0,
    ):
        # Stub "df -P/-h -P <mount>" com uma saída fixa no formato que o
        # script espera (mesmas colunas do df real), sem depender do
        # filesystem de verdade.
        df_script = "exit 1" if df_fails else (
            f"printf 'Filesystem 1024-blocks Used Available Capacity Mounted\\n"
            f"/dev/fake 1000 500 500 {used_percent}%% /\\n'"
        )
        self._fake_bin(directory, "df", df_script)
        # docker FALSO: nunca toca no Docker real do host que roda os testes.
        # Registra cada chamada em calls.log; "images" devolve as tags dadas.
        tags = "\\n".join(image_tags or [])
        self._fake_bin(
            directory,
            "docker",
            f'echo "$@" >> "{directory}/calls.log"\n'
            f'if [ "$1" = "images" ]; then printf "{tags}\\n"; fi\n',
        )
        self._fake_bin(
            directory,
            "curl",
            f'cat > "{directory}/curl-config.log"\n'
            f'echo "$@" > "{directory}/curl-args.log"\nexit {curl_exit}',
        )
        state_dir = directory / "state"
        state_dir.mkdir(exist_ok=True)
        for name, value in (state or {}).items():
            (state_dir / name).write_text(value + "\n")

        run_env = {
            "PATH": f"{directory}:{os.environ.get('PATH', '')}",
            "DNS_CENTER_STATE_DIR": str(state_dir),
            "DNS_CENTER_DOCKER_COMMAND": "docker" if docker_available else "docker-missing-for-test",
            **(env or {}),
        }

        return subprocess.run(
            ["bash", str(SCRIPT)],
            capture_output=True,
            text=True,
            env=run_env,
        )

    def _calls(self, directory: pathlib.Path) -> list[str]:
        log = directory / "calls.log"
        return log.read_text().splitlines() if log.exists() else []

    def test_logs_usage_without_warning_below_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), used_percent=42)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("level=OK", result.stdout)
        self.assertIn("used=42%", result.stdout)
        self.assertNotIn("ALERT", result.stdout)

    def test_warns_when_usage_reaches_the_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), used_percent=85)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("level=WARNING", result.stdout)
        self.assertIn("ALERT", result.stdout)
        self.assertIn("used=85%", result.stdout)

    def test_custom_threshold_is_respected(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory),
                used_percent=60,
                env={"DNS_CENTER_DISK_WARN_PERCENT": "50"},
            )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("level=WARNING", result.stdout)

    def test_severity_boundaries_and_critical_never_clean(self):
        expected = [(70, "OK"), (80, "WARNING"), (90, "CRITICAL"),
                    (95, "EMERGENCY"), (100, "EMERGENCY")]
        for percent, level in expected:
            with self.subTest(percent=percent), tempfile.TemporaryDirectory() as directory:
                directory = pathlib.Path(directory)
                result = self._run(directory, used_percent=percent)
                calls = self._calls(directory)
                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn(f"level={level}", result.stdout)
                if percent >= 90:
                    self.assertIn("CLEANUP_SKIPPED", result.stdout)
                    self.assertEqual([], calls)

    def test_recovery_is_logged_and_notified_once(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            first = self._run(
                directory, 95,
                env={"DNS_CENTER_DISK_WEBHOOK_URL": "https://example.invalid/secret-token"},
            )
            recovered = self._run(
                directory, 70,
                env={"DNS_CENTER_DISK_WEBHOOK_URL": "https://example.invalid/secret-token"},
            )
            repeated = self._run(
                directory, 70,
                env={"DNS_CENTER_DISK_WEBHOOK_URL": "https://example.invalid/secret-token"},
            )

        self.assertIn("ALERT level=EMERGENCY", first.stdout)
        self.assertIn("RECOVERY previous=EMERGENCY", recovered.stdout)
        self.assertNotIn("RECOVERY", repeated.stdout)
        self.assertNotIn("secret-token", first.stdout + first.stderr)

    def test_webhook_failure_does_not_fail_guard_or_expose_url(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory), 90, curl_exit=7,
                env={"DNS_CENTER_DISK_WEBHOOK_URL": "https://example.invalid/private-token"},
            )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("NOTIFY_ERROR", result.stdout)
        self.assertNotIn("private-token", result.stdout + result.stderr)

    def test_fails_when_filesystem_cannot_be_read(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), 0, df_fails=True)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("filesystem", result.stderr)

    def test_docker_unavailable_is_non_fatal(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory), 70, docker_available=False,
            )
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("docker-indisponivel", result.stdout)

    def test_concurrent_execution_is_skipped(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            state_dir = directory / "state"
            state_dir.mkdir()
            lock = (state_dir / "disk-guard.lock").open("w")
            self.addCleanup(lock.close)
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            result = self._run(directory, 70)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("disk-guard-concorrente", result.stdout)

    def test_rejects_non_numeric_threshold(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory),
                used_percent=10,
                env={"DNS_CENTER_DISK_WARN_PERCENT": "abc"},
            )

        self.assertNotEqual(0, result.returncode)
        self.assertIn("DNS_CENTER_DISK_WARN_PERCENT", result.stderr)

    def test_removes_old_release_images_but_protects_current_previous_and_newest(self):
        tags = ["1.3.50", "1.3.51", "1.3.52", "1.3.53", "1.3.54", "latest"]

        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            result = self._run(
                directory,
                used_percent=10,
                image_tags=tags,
                state={"current-version": "1.3.54", "previous-version": "1.3.53"},
            )
            calls = self._calls(directory)

        self.assertEqual(0, result.returncode, result.stderr)
        removed = [c for c in calls if c.startswith("rmi ")]
        # 5 versões numéricas, poupa as 3 mais novas (52, 53, 54) -> só 50 e 51 saem.
        self.assertIn("rmi dns-center-app:1.3.50", removed)
        self.assertIn("rmi dns-center-app:1.3.51", removed)
        self.assertNotIn("rmi dns-center-app:1.3.52", removed)
        self.assertNotIn("rmi dns-center-app:1.3.53", removed)
        self.assertNotIn("rmi dns-center-app:1.3.54", removed)
        # "latest" nunca é considerada versão de release.
        self.assertFalse(any("latest" in c for c in removed))

    def test_previous_version_is_protected_even_when_it_is_old(self):
        # Reproduz o defeito corrigido: o alvo do rollback é uma versão antiga
        # (fora das 3 mais recentes) e NÃO pode ser removida.
        tags = ["1.3.40", "1.3.51", "1.3.52", "1.3.53", "1.3.54"]

        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            self._run(
                directory,
                used_percent=10,
                image_tags=tags,
                state={"current-version": "1.3.54", "previous-version": "1.3.40"},
            )
            calls = self._calls(directory)

        self.assertFalse(any(c == "rmi dns-center-app:1.3.40" for c in calls))
        self.assertFalse(any(c == "rmi dns-center-web:1.3.40" for c in calls))

    def test_v_prefixed_release_tags_are_sorted_and_protected(self):
        tags = ["v1.3.63", "v1.3.64", "v1.3.65", "v1.3.66", "v1.3.67",
                "v1.3.68", "v1.3.69"]
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            result = self._run(
                directory, used_percent=70, image_tags=tags,
                state={"current-version": "v1.3.69", "previous-version": "v1.3.68"},
            )
            removed = [c for c in self._calls(directory) if c.startswith("rmi ")]

        self.assertEqual(0, result.returncode, result.stderr)
        for version in ("63", "64", "65", "66"):
            self.assertIn(f"rmi dns-center-app:v1.3.{version}", removed)
        for version in ("67", "68", "69"):
            self.assertNotIn(f"rmi dns-center-app:v1.3.{version}", removed)

    def test_never_uses_age_based_image_prune(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = pathlib.Path(directory)
            self._run(directory, used_percent=10, image_tags=["1.3.54"])
            calls = self._calls(directory)

        self.assertFalse(any(c.startswith("image prune -a") for c in calls))
        self.assertIn("image prune -f", calls)

    def test_survives_with_no_release_images_and_no_state_files(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(pathlib.Path(directory), used_percent=10, image_tags=[])

        self.assertEqual(0, result.returncode, result.stderr)

    def test_rejects_non_numeric_keep_versions(self):
        with tempfile.TemporaryDirectory() as directory:
            result = self._run(
                pathlib.Path(directory),
                used_percent=10,
                env={"DNS_CENTER_KEEP_IMAGE_VERSIONS": "x"},
            )

        self.assertNotEqual(0, result.returncode)


if __name__ == "__main__":
    unittest.main()
