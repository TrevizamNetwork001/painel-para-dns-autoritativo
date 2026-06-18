<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/users.php';

$erro = '';
$sucesso = '';
$usuarioAtual = usuario_por_id((int) $_SESSION['usuario_id']);

if (!$usuarioAtual) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $senhaAtual = (string) ($_POST['senha_atual'] ?? '');
    $novaSenha = (string) ($_POST['nova_senha'] ?? '');
    $confirmacao = (string) ($_POST['confirmar_senha'] ?? '');

    if (!password_verify($senhaAtual, $usuarioAtual['senha_hash'])) {
        $erro = 'A senha atual está incorreta.';
    } elseif (!usuario_senha_valida($novaSenha)) {
        $erro = 'A nova senha deve ter entre 8 e 128 caracteres.';
    } elseif ($novaSenha !== $confirmacao) {
        $erro = 'A confirmação da nova senha não confere.';
    } elseif (password_verify($novaSenha, $usuarioAtual['senha_hash'])) {
        $erro = 'A nova senha deve ser diferente da senha atual.';
    } else {
        $novaVersao = ((int) $usuarioAtual['auth_version']) + 1;
        $stmt = db()->prepare("
            UPDATE usuarios
            SET senha_hash = :senha_hash,
                trocar_senha = 0,
                auth_version = :auth_version,
                atualizado_em = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':senha_hash' => password_hash($novaSenha, PASSWORD_DEFAULT),
            ':auth_version' => $novaVersao,
            ':id' => $usuarioAtual['id'],
        ]);

        $_SESSION['auth_version'] = $novaVersao;
        $_SESSION['trocar_senha'] = 0;
        $usuarioAtual = usuario_por_id((int) $usuarioAtual['id']);
        $sucesso = 'Senha alterada com sucesso. Você será redirecionado para a página inicial em 4 segundos.';

        registrar_auditoria([
            'acao' => 'ALTERAR_PROPRIA_SENHA',
            'tipo_registro' => 'USUARIO',
            'nome_registro' => $usuarioAtual['usuario'],
            'valor_antigo' => 'senha protegida',
            'valor_novo' => 'senha atualizada',
            'status' => 'OK',
            'mensagem' => 'Usuário alterou a própria senha',
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Alterar senha</title>
<?php if ($sucesso): ?>
<meta http-equiv="refresh" content="4;url=dashboard.php">
<?php endif; ?>
<style>
body{margin:0;font-family:Arial;background:#0f172a;color:#e2e8f0;}
.container{max-width:560px;margin:50px auto;padding:0 20px;}
.card{background:#020617;border:1px solid #1e293b;padding:26px;border-radius:12px;}
h1{margin-top:0;color:#fff;}
label{display:block;margin-top:15px;color:#cbd5e1;}
input{width:100%;box-sizing:border-box;padding:11px;margin-top:6px;border:1px solid #334155;border-radius:7px;background:#0f172a;color:#fff;}
button{margin-top:20px;padding:11px 18px;border:0;border-radius:7px;background:#2563eb;color:#fff;cursor:pointer;}
a{color:#38bdf8;text-decoration:none;}
.erro,.sucesso,.aviso{padding:12px;border-radius:8px;margin-bottom:16px;}
.erro{background:#7f1d1d;color:#fecaca;}
.sucesso{background:#14532d;color:#bbf7d0;}
.aviso{background:#78350f;color:#fde68a;}
</style>
</head>
<body>
<div class="container">
<div class="card">
<h1>Alterar senha</h1>

<?php if (!empty($_SESSION['trocar_senha'])): ?>
<div class="aviso">Defina uma nova senha para continuar usando o painel.</div>
<?php endif; ?>
<?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="sucesso"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<form method="POST">
<?= csrf_field() ?>
<label>Senha atual</label>
<input type="password" name="senha_atual" autocomplete="current-password" required>

<label>Nova senha</label>
<input type="password" name="nova_senha" minlength="8" maxlength="128" autocomplete="new-password" required>

<label>Confirmar nova senha</label>
<input type="password" name="confirmar_senha" minlength="8" maxlength="128" autocomplete="new-password" required>

<button type="submit">Salvar nova senha</button>
</form>

<?php if (empty($_SESSION['trocar_senha'])): ?>
<p><a href="dashboard.php">← Voltar ao painel</a></p>
<?php endif; ?>
</div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php if ($sucesso): ?>
<script>
setTimeout(function(){ window.location.href = 'dashboard.php'; }, 4000);
</script>
<?php endif; ?>
</body>
</html>
