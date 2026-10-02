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

    def _run(
        self,
        directory: pathlib.Path,
        used_percent: int,
        env: dict | None = None,
        image_tags: list[str] | None = None,
        state: dict | None = None,
    ):
        # Stub "df -P/-h -P <mount>" com uma saída fixa no formato que o
        # script espera (mesmas colunas do df real), sem depender do
        # filesystem de verdade.
        self._fake_bin(
            directory,
            "df",
            f"printf 'Filesystem 1024-blocks Used Available Capacity Mounted\\n"
            f"/dev/fake 1000 500 500 {used_percent}%% /\\n'\n",
        )
        # docker FALSO: nunca toca no Docker real do host que roda os testes.
        # Registra cada chamada em calls.log; "images" devolve as tags dadas.
        tags = "\\n".join(image_tags or [])
        self._fake_bin(
            directory,
            "docker",
            f'echo "$@" >> "{directory}/calls.log"\n'
            f'if [ "$1" = "images" ]; then printf "{tags}\\n"; fi\n',
        )
        state_dir = directory / "state"
        state_dir.mkdir(exist_ok=True)
        for name, value in (state or {}).items():
            (state_dir / name).write_text(value + "\n")

        run_env = {
            "PATH": f"{directory}:{os.environ.get('PATH', '')}",
            "DNS_CENTER_STATE_DIR": str(state_dir),
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
