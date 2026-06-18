<?php
session_start();
require_once __DIR__ . "/includes/audit.php";

$usuario = audit_usuario_atual();

try {
    registrar_auditoria([
        'usuario' => $usuario,
        'acao' => 'LOGOUT',
        'status' => 'OK',
        'mensagem' => 'Logout realizado',
    ]);
} catch (Throwable $e) {
    error_log('Falha ao registrar LOGOUT: ' . $e->getMessage());
}

session_unset();
session_destroy();

header("Location: login.php");
exit;
