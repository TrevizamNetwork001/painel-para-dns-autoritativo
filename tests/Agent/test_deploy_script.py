import os
import pathlib
import subprocess
import tempfile
import time
import unittest


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
DEPLOY_SCRIPT = PROJECT_ROOT / "deploy" / "dns-center-deploy"


class DeployScriptTest(unittest.TestCase):
    def test_clean_install_initializes_migration_table_before_migrating(self):
        script = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        function = script.split("run_migrations() {", 1)[1].split("\n}", 1)[0]

        status = function.index("migrate:status")
        install = function.index("migrate:install")
        migrate = function.index("migrate --force")
        final_status = function.rindex("migrate:status")

        self.assertLess(status, install)
        self.assertLess(install, migrate)
        self.assertLess(migrate, final_status)


    def _function(self, name):
        script = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        return script.split(f"{name}() {{", 1)[1].split("\n}\n", 1)[0]

    def test_script_has_valid_bash_syntax(self):
        result = subprocess.run(
            ["bash", "-n", str(DEPLOY_SCRIPT)], capture_output=True, text=True
        )
        self.assertEqual(0, result.returncode, result.stderr)

    def test_prune_keeps_newest_ten_and_removes_only_old_extras(self):
        body = self._function("prune_backups")

        with tempfile.TemporaryDirectory() as directory:
            now = time.time()
            # 12 dumps: 0 = mais recente. Os 6 mais antigos têm 30 dias; os demais 1 dia.
            for index in range(12):
                age_days = 30 if index >= 6 else 1
                path = pathlib.Path(directory) / f"dns-center-2026{index:04d}.dump"
                path.write_text("x")
                pathlib.Path(str(path) + ".sha256").write_text("x")
                stamp = now - age_days * 86400 - index * 60
                os.utime(path, (stamp, stamp))

            script = (
                'fail() { echo "$*" >&2; exit 1; }\n'
                f"DNS_CENTER_BACKUP_DIR={directory}\n"
                "DNS_CENTER_BACKUP_RETENTION_DAYS=14\n"
                f"prune_backups() {{{body}\n}}\nprune_backups\n"
            )
            result = subprocess.run(
                ["bash", "-c", script], capture_output=True, text=True
            )
            self.assertEqual(0, result.returncode, result.stderr)

            remaining = sorted(p.name for p in pathlib.Path(directory).glob("*.dump"))
            # Índices 10 e 11 (fora dos 10 mais novos e com 30 dias) foram removidos.
            self.assertEqual(10, len(remaining))
            self.assertFalse(
                list(pathlib.Path(directory).glob("*0010.dump*"))
                + list(pathlib.Path(directory).glob("*0011.dump*"))
            )
            # Os que estão dentro dos 10 mais novos ficam, mesmo antigos.
            self.assertTrue((pathlib.Path(directory) / "dns-center-20260008.dump").exists())

    def test_prune_rejects_invalid_retention(self):
        body = self._function("prune_backups")
        script = (
            'fail() { echo "$*" >&2; exit 1; }\n'
            "DNS_CENTER_BACKUP_DIR=/nonexistent\n"
            "DNS_CENTER_BACKUP_RETENTION_DAYS=abc\n"
            f"prune_backups() {{{body}\n}}\nprune_backups\n"
        )
        result = subprocess.run(["bash", "-c", script], capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)

    def test_remote_upload_is_always_encrypted_and_hides_credentials(self):
        body = self._function("upload_backup_remote")

        # Sem senha configurada nada é enviado.
        self.assertLess(body.index("passphrase-file"), body.index("-T "))
        self.assertIn("nada foi enviado", body)
        # Criptografia antes do envio, e o arquivo enviado é o .gpg.
        self.assertIn("--symmetric --cipher-algo AES256", body)
        self.assertIn('-T "${encrypted}"', body)
        # Credenciais nunca na linha de comando do curl.
        self.assertIn("-K -", body)
        self.assertNotIn("--user", body)
        self.assertNotIn("-u ", body)
        # Confere o tamanho remoto e apaga o .gpg local.
        self.assertIn("content-length", body)
        self.assertIn("rm -f", body)

    def test_verify_restores_into_scratch_database_never_production(self):
        body = self._function("verify_backup")

        self.assertIn('scratch="dns_center_restore_test"', body)
        self.assertIn("pg_restore", body)
        self.assertIn("-d ${scratch}", body)
        self.assertNotIn("--clean", body)
        self.assertIn("trap drop_scratch EXIT", body)
        self.assertIn("sha256sum --check", body)

    def test_backup_command_prunes_and_uploads_and_uses_running_version(self):
        script = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        branch = script.split("        backup)", 1)[1].split("        rollback)", 1)[0]

        self.assertLess(branch.index("backup_database"), branch.index("prune_backups"))
        self.assertLess(branch.index("prune_backups"), branch.index("upload_backup_remote"))
        self.assertIn("use_running_version", branch)
        self.assertIn("backup-verify", script)


if __name__ == "__main__":
    unittest.main()
