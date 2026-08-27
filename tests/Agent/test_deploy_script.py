import pathlib
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


if __name__ == "__main__":
    unittest.main()
