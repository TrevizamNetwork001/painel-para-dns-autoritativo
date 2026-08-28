import pathlib
import unittest


PROJECT_ROOT = pathlib.Path(__file__).resolve().parents[2]
INSTALL_SCRIPT = PROJECT_ROOT / "public" / "install" / "agent_install.sh"


def upgrade_function_body(script: str) -> str:
    return script.split("upgrade_agent() {", 1)[1].split("\n}\n", 1)[0]


class AgentInstallUpgradeModeTest(unittest.TestCase):
    def setUp(self) -> None:
        self.script = INSTALL_SCRIPT.read_text(encoding="utf-8")
        self.upgrade_body = upgrade_function_body(self.script)

    def test_upgrade_mode_requires_existing_installation(self) -> None:
        self.assertIn('[ ! -e "${INSTALL_PATH}" ]', self.upgrade_body)
        self.assertIn("use a instalação completa", self.upgrade_body)

    def test_installer_bootstraps_curl_with_supported_package_managers(self) -> None:
        self.assertIn("install_curl_if_missing", self.script)
        self.assertIn("apt-get install -y --no-install-recommends curl ca-certificates", self.script)
        self.assertIn("dnf install -y curl ca-certificates", self.script)
        self.assertIn("yum install -y curl ca-certificates", self.script)

    def test_upgrade_mode_rejects_symlink_target(self) -> None:
        self.assertIn('[ -L "${INSTALL_PATH}" ]', self.upgrade_body)

    def test_upgrade_never_touches_config_or_state_dir(self) -> None:
        self.assertNotIn("${CONFIG_DIR}", self.upgrade_body)
        self.assertNotIn("${STATE_DIR}", self.upgrade_body)

    def test_upgrade_never_calls_bind_or_service_management(self) -> None:
        for token in (
            "rndc", "named-checkzone", "named-checkconf", "install_bind",
            "configure_bind", "systemctl enable", "systemctl start",
            "systemctl restart",
        ):
            self.assertNotIn(token, self.upgrade_body)

    def test_upgrade_never_triggers_enrollment(self) -> None:
        self.assertNotIn("request_enrollment", self.upgrade_body)
        self.assertNotIn("enrollment_code", self.upgrade_body)
        self.assertNotIn("--enroll ", self.upgrade_body)

    def test_binary_replacement_is_conditional_on_checksum_diff(self) -> None:
        self.assertIn('cmp -s "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"', self.upgrade_body)
        changed_index = self.upgrade_body.index("binary_changed=1")
        install_index = self.upgrade_body.index(
            'install -m 0750 "${temporary_dir}/dns-center-agent.py" "${INSTALL_PATH}"'
        )
        self.assertLess(changed_index, install_index)

    def test_new_binary_is_validated_for_version_and_enroll_flag(self) -> None:
        self.assertIn('"${INSTALL_PATH}" --version', self.upgrade_body)
        self.assertIn("--help", self.upgrade_body)
        self.assertIn("grep -q -- '--enroll'", self.upgrade_body)

    def test_validation_failure_restores_previous_binary_and_aborts(self) -> None:
        failure_index = self.upgrade_body.index("Falha na validação do novo agente")
        restore_index = self.upgrade_body.index(
            'install -m 0750 "${backup_dir}/dns-center-agent.py" "${INSTALL_PATH}"',
            failure_index,
        )
        exit_index = self.upgrade_body.index("exit 1", restore_index)
        self.assertLess(failure_index, restore_index)
        self.assertLess(restore_index, exit_index)

    def test_units_are_replaced_only_when_content_differs(self) -> None:
        self.assertIn("cmp -s \"${temporary_dir}/${unit}\" \"${SYSTEMD_DIR}/${unit}\"", self.upgrade_body)
        self.assertIn("continue", self.upgrade_body)

    def test_daemon_reload_only_runs_when_units_changed(self) -> None:
        guard_index = self.upgrade_body.index('[ "${units_changed}" -eq 1 ]')
        reload_index = self.upgrade_body.index("systemctl daemon-reload", guard_index)
        self.assertGreater(reload_index, guard_index)

    def test_second_run_with_no_changes_reports_idempotent_no_op(self) -> None:
        self.assertIn('"${binary_changed}" -eq 0', self.upgrade_body)
        self.assertIn('"${units_changed}" -eq 0', self.upgrade_body)
        self.assertIn("já está atualizado; nada foi alterado", self.upgrade_body)

    def test_upgrade_mode_is_dispatched_before_the_full_install_and_enroll_flow(self) -> None:
        dispatch_index = self.script.index('"${ENROLL_MODE}" = "--upgrade-agent"')
        install_flow_index = self.script.index("request_enrollment\n")
        self.assertLess(dispatch_index, install_flow_index)

    def test_checksum_verification_and_backup_happen_before_upgrade_dispatch(self) -> None:
        checksum_index = self.script.index("sha256sum --check")
        backup_index = self.script.index('backup_dir="${temporary_dir}/previous"')
        dispatch_index = self.script.index('"${ENROLL_MODE}" = "--upgrade-agent"')
        self.assertLess(checksum_index, dispatch_index)
        self.assertLess(backup_index, dispatch_index)


if __name__ == "__main__":
    unittest.main()
