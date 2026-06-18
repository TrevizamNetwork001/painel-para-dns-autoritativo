<?php

require_once __DIR__ . '/users.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$timeout = 900;

if (empty($_SESSION['logado'])) {

    header("Location: login.php");

    exit;
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);

if ($usuarioId <= 0 && !empty($_SESSION['usuario'])) {
    $usuarioLegado = usuario_por_login((string) $_SESSION['usuario']);

    if ($usuarioLegado && (int) $usuarioLegado['ativo'] === 1) {
        $usuarioId = (int) $usuarioLegado['id'];
        $_SESSION['usuario_id'] = $usuarioId;
        $_SESSION['usuario'] = $usuarioLegado['usuario'];
        $_SESSION['perfil'] = $usuarioLegado['perfil'];
        $_SESSION['trocar_senha'] = (int) $usuarioLegado['trocar_senha'];
        $_SESSION['auth_version'] = (int) $usuarioLegado['auth_version'];
    }
}

$usuarioAtual = $usuarioId > 0 ? usuario_por_id($usuarioId) : null;

if (
    !$usuarioAtual
    || (int) $usuarioAtual['ativo'] !== 1
    || (int) ($_SESSION['auth_version'] ?? 0) !== (int) $usuarioAtual['auth_version']
) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

$_SESSION['usuario'] = $usuarioAtual['usuario'];
$_SESSION['perfil'] = $usuarioAtual['perfil'];
$_SESSION['trocar_senha'] = (int) $usuarioAtual['trocar_senha'];

if (isset($_SESSION['ultimo_acesso'])) {

    if ((time() - $_SESSION['ultimo_acesso']) > $timeout) {

        session_unset();

        session_destroy();

        header("Location: login.php");

        exit;
    }
}

$_SESSION['ultimo_acesso'] = time();

$paginaAtual = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (
    !empty($_SESSION['trocar_senha'])
    && !in_array($paginaAtual, ['alterar-senha.php', 'logout.php'], true)
) {
    header('Location: alterar-senha.php');
    exit;
}
