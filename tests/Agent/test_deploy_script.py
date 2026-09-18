import hashlib
import hmac
import http.server
import os
import pathlib
import shutil
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.parse


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
DEPLOY_SCRIPT = PROJECT_ROOT / "deploy" / "dns-center-deploy"


ACCESS_KEY = "AKIATESTTESTTEST0000"
SECRET_KEY = "s3cr3tTESTTESTTESTTESTTESTTESTTESTTEST00"


class FakeR2(http.server.BaseHTTPRequestHandler):
    """R2 falso que verifica a assinatura SigV4 com uma implementação independente."""

    objects: dict = {}
    rejected: list = []

    def log_message(self, *args):
        pass

    def _verify(self, body_hash_from_body: str) -> bool:
        auth = self.headers.get("Authorization", "")
        if not auth.startswith("AWS4-HMAC-SHA256 "):
            return False
        fields = dict(
            part.strip().split("=", 1) for part in auth[len("AWS4-HMAC-SHA256 "):].split(",")
        )
        access_key, date, region, service, _ = fields["Credential"].split("/")
        if access_key != ACCESS_KEY:
            return False
        signed = fields["SignedHeaders"].split(";")
        payload = self.headers.get("x-amz-content-sha256", body_hash_from_body)
        canonical_headers = "".join(f"{h}:{self.headers[h].strip()}\n" for h in signed)
        parsed = urllib.parse.urlsplit(self.path)
        canonical = "\n".join([
            self.command, parsed.path, parsed.query, canonical_headers, ";".join(signed), payload,
        ])
        amz_date = self.headers["x-amz-date"]
        to_sign = "\n".join([
            "AWS4-HMAC-SHA256", amz_date, f"{date}/{region}/{service}/aws4_request",
            hashlib.sha256(canonical.encode()).hexdigest(),
        ])

        def mac(key, msg):
            return hmac.new(key, msg.encode(), hashlib.sha256).digest()

        key = mac(mac(mac(mac(("AWS4" + SECRET_KEY).encode(), date), region), service), "aws4_request")
        expected = hmac.new(key, to_sign.encode(), hashlib.sha256).hexdigest()
        return hmac.compare_digest(expected, fields["Signature"])

    def _handle(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length and self.command == "PUT" else b""
        if not self._verify(hashlib.sha256(body).hexdigest()):
            FakeR2.rejected.append((self.command, self.path))
            self.send_response(403)
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        if self.command == "PUT":
            FakeR2.objects[self.path] = body
            self.send_response(200)
            self.send_header("Content-Length", "0")
        elif self.command == "HEAD" and self.path in FakeR2.objects:
            self.send_response(200)
            self.send_header("Content-Length", str(len(FakeR2.objects[self.path])))
        else:
            self.send_response(404)
            self.send_header("Content-Length", "0")
        self.end_headers()

    do_PUT = do_HEAD = _handle


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

    def _run_upload(self, panel_lines, remote_env_content=None):
        """Executa upload_backup_remote do script real contra um R2 falso."""
        if not (shutil.which("gpg") and shutil.which("curl")):
            self.skipTest("gpg/curl indisponíveis")

        FakeR2.objects = {}
        FakeR2.rejected = []
        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), FakeR2)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        port = server.server_address[1]

        script_text = DEPLOY_SCRIPT.read_text(encoding="utf-8")
        panel = self._function("panel_backup_config")
        upload = self._function("upload_backup_remote")

        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            dump = root / "dns-center-20260918T000000Z.dump"
            dump.write_bytes(os.urandom(4096))
            passphrase = root / "backup.pass"
            passphrase.write_text("senha-de-teste")
            passphrase.chmod(0o600)
            remote_env = root / "backup-r2.env"
            if remote_env_content is not None:
                remote_env.write_text(remote_env_content)
                remote_env.chmod(0o600)

            script = (
                'fail() { echo "$*" >&2; exit 1; }\n'
                'require_command() { command -v "$1" >/dev/null || fail "ausente $1"; }\n'
                "compose() {\n"
                "  cat <<'PANEL_EOF'\n"
                f"{panel_lines}\n"
                "PANEL_EOF\n"
                "}\n"
                f"DNS_CENTER_BACKUP_PASSPHRASE_FILE={passphrase}\n"
                f"DNS_CENTER_BACKUP_REMOTE_ENV={remote_env}\n"
                f"DNS_CENTER_BACKUP_R2_ENDPOINT=http://127.0.0.1:{port}\n"
                f"panel_backup_config() {{{panel}\n}}\n"
                f"upload_backup_remote() {{{upload}\n}}\n"
                f"upload_backup_remote {dump}\n"
            )
            result = subprocess.run(["bash", "-c", script], capture_output=True, text=True)
            encrypted_leftover = list(root.glob("*.gpg"))
            objects = dict(FakeR2.objects)
            dump_bytes = dump.read_bytes()

            decrypted = None
            if objects:
                (root / "remote.gpg").write_bytes(next(iter(objects.values())))
                proc = subprocess.run(
                    ["gpg", "--batch", "--quiet", "--pinentry-mode", "loopback",
                     "--passphrase-file", str(passphrase), "-d", str(root / "remote.gpg")],
                    capture_output=True,
                )
                decrypted = proc.stdout

        return result, objects, list(FakeR2.rejected), encrypted_leftover, dump_bytes, decrypted

    def test_upload_from_panel_credentials_signs_encrypts_and_verifies(self):
        lines = "\n".join([
            "R2_ACCOUNT_ID=bec407d758365446312d1c62e87d8acf",
            "R2_BUCKET=dns-center-backups",
            "R2_PREFIX=dns-center/",
            f"R2_ACCESS_KEY_ID={ACCESS_KEY}",
            f"R2_SECRET_ACCESS_KEY={SECRET_KEY}",
        ])
        result, objects, rejected, leftover, dump_bytes, decrypted = self._run_upload(lines)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual([], rejected, "assinatura SigV4 recusada pelo R2 falso")
        self.assertIn("criptografada", result.stdout)
        self.assertEqual(1, len(objects))
        path = next(iter(objects))
        self.assertTrue(path.startswith("/dns-center-backups/dns-center/dns-center-"))
        self.assertTrue(path.endswith(".dump.gpg"))
        # O que foi enviado não é o dump em claro, mas abre com a senha e é idêntico.
        self.assertNotEqual(dump_bytes, next(iter(objects.values())))
        self.assertEqual(dump_bytes, decrypted)
        self.assertEqual([], leftover, ".gpg temporário deve ser apagado")
        # As chaves nunca aparecem na saída do script.
        self.assertNotIn(SECRET_KEY, result.stdout + result.stderr)

    def test_upload_falls_back_to_env_file_and_skips_when_nothing_configured(self):
        env = (
            "R2_ACCOUNT_ID=bec407d758365446312d1c62e87d8acf\n"
            "R2_BUCKET=dns-center-backups\nR2_PREFIX=dns-center/\n"
            f"R2_ACCESS_KEY_ID={ACCESS_KEY}\nR2_SECRET_ACCESS_KEY={SECRET_KEY}\n"
        )
        result, objects, rejected, _, _, _ = self._run_upload("", env)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(1, len(objects))
        self.assertEqual([], rejected)

        result, objects, _, _, _, _ = self._run_upload("", None)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("não configurada", result.stdout)
        self.assertEqual({}, objects)

    def test_upload_with_wrong_secret_fails_and_leaves_no_encrypted_file(self):
        lines = "\n".join([
            "R2_ACCOUNT_ID=bec407d758365446312d1c62e87d8acf",
            "R2_BUCKET=dns-center-backups",
            "R2_PREFIX=dns-center/",
            f"R2_ACCESS_KEY_ID={ACCESS_KEY}",
            "R2_SECRET_ACCESS_KEY=chaveErradaChaveErradaChaveErrada00",
        ])
        result, objects, rejected, leftover, _, _ = self._run_upload(lines)

        self.assertNotEqual(0, result.returncode)
        self.assertEqual({}, objects)
        self.assertTrue(rejected)
        self.assertEqual([], leftover)


if __name__ == "__main__":
    unittest.main()
