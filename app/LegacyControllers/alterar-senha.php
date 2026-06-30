<?php
require_once dirname(__DIR__, 2) . "/app/Auth/auth.php";
require_once dirname(__DIR__, 2) . "/app/Auth/users.php";
require_once dirname(__DIR__, 2) . "/app/Support/security.php";
require_once dirname(__DIR__, 2) . "/app/Audit/audit.php";

$erro = '';
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

        try {
            registrar_auditoria([
                'acao' => 'ALTERAR_PROPRIA_SENHA',
                'tipo_registro' => 'USUARIO',
                'nome_registro' => $usuarioAtual['usuario'],
                'valor_antigo' => 'senha protegida',
                'valor_novo' => 'senha atualizada',
                'status' => 'OK',
                'mensagem' => 'Usuário alterou a própria senha',
            ]);
        } catch (Throwable $e) {
            error_log('Falha ao registrar ALTERAR_PROPRIA_SENHA: ' . $e->getMessage());
        }

        session_regenerate_id(true);
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
        header('Location: login.php?senha_alterada=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Alterar senha</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;font-size:14px}
a{color:#38bdf8;text-decoration:none}
a:hover{text-decoration:underline}
.page{width:min(980px,100%);margin:0 auto;padding:22px 18px 18px}
.header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:18px}
.header h1{margin:0 0 6px;color:#fff;font-size:26px}
.header p{margin:0;color:#94a3b8;font-size:13px;line-height:1.45}
.back-link{display:inline-flex;align-items:center;min-height:36px;padding:7px 11px;border:1px solid #334155;border-radius:9px;background:#071226;font-size:13px;font-weight:bold;white-space:nowrap}
.card{background:#071226;border:1px solid #1e293b;border-radius:14px;padding:22px;width:min(820px,100%);margin:0 auto}
.card-head{margin-bottom:16px}
.card-head h2{margin:0 0 6px;color:#fff;font-size:20px}
.card-head p{margin:0;color:#94a3b8;font-size:13px;line-height:1.45;max-width:620px}
.card-inner{max-width:620px;margin:0 auto;width:100%}
.message,.error,.success,.alert{padding:12px 14px;border-radius:10px;margin:0 0 14px;border:1px solid}
.error{background:#450a0a;color:#fecaca;border-color:#991b1b}
.success{background:#052e16;color:#bbf7d0;border-color:#166534}
.alert{background:#332100;color:#fde68a;border-color:#854d0e}
.info-box{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1px solid #334155;border-radius:10px;background:#0f172a;color:#cbd5e1;margin:0 0 18px}
.info-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;background:#10233d;color:#bfdbfe;font-size:12px;font-weight:bold;flex:0 0 auto}
.info-box strong{display:block;color:#fff;margin-bottom:4px}
form{display:grid;gap:14px}
label{display:block;color:#cbd5e1;font-size:12px;font-weight:bold;margin-bottom:6px}
input{width:100%;padding:10px 11px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff;font:inherit;font-size:13px}
input:focus{border-color:#38bdf8;outline:none;box-shadow:0 0 0 3px #38bdf81f}
.field-row{display:grid;gap:14px}
.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding-top:4px}
button{min-height:36px;padding:8px 12px;border:1px solid #2563eb;border-radius:8px;background:#2563eb;color:#fff;font:inherit;font-size:13px;font-weight:bold;cursor:pointer}
button:hover{filter:brightness(1.08)}
.secondary-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:8px 12px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:13px;font-weight:bold;white-space:nowrap}
.helper{margin:0;color:#94a3b8;font-size:12px;line-height:1.45}
@media(max-width:700px){
    .page{padding:16px 12px}
    .header{flex-direction:column}
    .card{padding:18px}
    .card-inner{max-width:none}
    .actions{flex-direction:column;align-items:stretch}
    .actions button,.actions .secondary-button{width:100%}
}
</style>
</head>
<body>
<main class="page">
    <header class="header">
        <div>
            <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
            <h1>Alterar senha</h1>
            <p>Atualize sua senha de acesso ao painel.</p>
        </div>
    </header>

    <section class="card">
        <div class="card-inner">
            <div class="card-head">
                <h2>Alterar senha de acesso</h2>
                <p>Informe sua senha atual e defina uma nova senha para continuar usando o painel com segurança.</p>
            </div>

            <?php if (!empty($_SESSION['trocar_senha'])): ?>
            <div class="alert">Defina uma nova senha para continuar usando o painel.</div>
            <?php endif; ?>
            <?php if ($erro): ?><div class="error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
            <div class="info-box">
                <span class="info-icon" aria-hidden="true">ℹ</span>
                <div>
                    <strong>Segurança da senha</strong>
                    <div>Use uma senha forte e diferente da senha inicial. Após salvar, utilize a nova senha no próximo login.</div>
                </div>
            </div>

            <form method="POST">
                <?= csrf_field() ?>
                <div class="field-row">
                    <div>
                        <label for="senha_atual">Senha atual</label>
                        <input id="senha_atual" type="password" name="senha_atual" autocomplete="current-password" required>
                    </div>
                    <div>
                        <label for="nova_senha">Nova senha</label>
                        <input id="nova_senha" type="password" name="nova_senha" minlength="8" maxlength="128" autocomplete="new-password" required>
                    </div>
                    <div>
                        <label for="confirmar_senha">Confirmar nova senha</label>
                        <input id="confirmar_senha" type="password" name="confirmar_senha" minlength="8" maxlength="128" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit">Salvar nova senha</button>
                    <a class="secondary-button" href="dashboard.php">← Voltar ao painel</a>
                </div>
                <p class="helper">A alteração encerra sua sessão atual e exige a nova senha no próximo login.</p>
            </form>
        </div>
    </section>
</main>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
</body>
</html>
