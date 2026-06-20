<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/users.php';

exigir_administrador();

$erro = '';
$sucesso = $_SESSION['usuarios_sucesso'] ?? '';
unset($_SESSION['usuarios_sucesso']);

function redirecionar_usuarios(string $mensagem): never
{
    $_SESSION['usuarios_sucesso'] = $mensagem;
    header('Location: usuarios.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = (string) ($_POST['acao'] ?? '');
    $pdo = db();

    try {
        if ($acao === 'criar') {
            $nome = strtolower(trim((string) ($_POST['usuario'] ?? '')));
            $senha = (string) ($_POST['senha'] ?? '');
            $perfil = (string) ($_POST['perfil'] ?? 'moderador');

            if (!usuario_nome_valido($nome)) {
                throw new RuntimeException('Use de 3 a 32 caracteres: letras, números, ponto, hífen ou sublinhado.');
            }
            if (!usuario_senha_valida($senha)) {
                throw new RuntimeException('A senha deve ter entre 8 e 128 caracteres.');
            }
            if (!usuario_perfil_valido($perfil)) {
                throw new RuntimeException('Perfil inválido.');
            }
            if (usuario_por_login($nome)) {
                throw new RuntimeException('Esse nome de usuário já existe.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO usuarios (usuario, senha_hash, perfil, ativo, trocar_senha)
                VALUES (:usuario, :senha_hash, :perfil, 1, 1)
            ");
            $stmt->execute([
                ':usuario' => $nome,
                ':senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
                ':perfil' => $perfil,
            ]);

            registrar_auditoria([
                'acao' => 'CRIAR_USUARIO',
                'tipo_registro' => 'USUARIO',
                'nome_registro' => $nome,
                'valor_antigo' => 'inexistente',
                'valor_novo' => usuario_perfil_legivel($perfil),
                'status' => 'OK',
                'mensagem' => 'Usuário criado',
            ]);
            redirecionar_usuarios('Usuário criado. Ele deverá trocar a senha no primeiro acesso.');
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $alvo = $id ? usuario_por_id($id) : null;

        if (!$alvo) {
            throw new RuntimeException('Usuário não encontrado.');
        }

        if ($acao === 'atualizar') {
            $perfil = (string) ($_POST['perfil'] ?? '');
            $ativo = isset($_POST['ativo']) ? 1 : 0;

            if (!usuario_perfil_valido($perfil)) {
                throw new RuntimeException('Perfil inválido.');
            }
            if ((int) $alvo['id'] === (int) $_SESSION['usuario_id'] &&
                ($perfil !== $alvo['perfil'] || $ativo !== (int) $alvo['ativo'])) {
                throw new RuntimeException('Você não pode alterar o próprio perfil ou bloquear a própria conta.');
            }
            if ($alvo['perfil'] === 'administrador' &&
                ($perfil !== 'administrador' || $ativo === 0) &&
                total_administradores_ativos((int) $alvo['id']) === 0) {
                throw new RuntimeException('O painel deve manter pelo menos um administrador ativo.');
            }

            $stmt = $pdo->prepare("
                UPDATE usuarios
                SET perfil = :perfil,
                    ativo = :ativo,
                    auth_version = auth_version + 1,
                    atualizado_em = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([':perfil' => $perfil, ':ativo' => $ativo, ':id' => $alvo['id']]);

            registrar_auditoria([
                'acao' => 'ALTERAR_USUARIO',
                'tipo_registro' => 'USUARIO',
                'nome_registro' => $alvo['usuario'],
                'valor_antigo' => usuario_perfil_legivel($alvo['perfil']) . ($alvo['ativo'] ? ' / ativo' : ' / bloqueado'),
                'valor_novo' => usuario_perfil_legivel($perfil) . ($ativo ? ' / ativo' : ' / bloqueado'),
                'status' => 'OK',
                'mensagem' => 'Perfil ou status do usuário alterado',
            ]);
            redirecionar_usuarios('Usuário atualizado.');
        }

        if ($acao === 'redefinir_senha') {
            $senha = (string) ($_POST['nova_senha'] ?? '');

            if ((int) $alvo['id'] === (int) $_SESSION['usuario_id']) {
                throw new RuntimeException('Use a página "Minha senha" para alterar sua própria senha.');
            }
            if (!usuario_senha_valida($senha)) {
                throw new RuntimeException('A nova senha deve ter entre 8 e 128 caracteres.');
            }

            $stmt = $pdo->prepare("
                UPDATE usuarios
                SET senha_hash = :senha_hash,
                    trocar_senha = 1,
                    auth_version = auth_version + 1,
                    atualizado_em = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ':senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
                ':id' => $alvo['id'],
            ]);

            registrar_auditoria([
                'acao' => 'REDEFINIR_SENHA_USUARIO',
                'tipo_registro' => 'USUARIO',
                'nome_registro' => $alvo['usuario'],
                'valor_antigo' => 'senha protegida',
                'valor_novo' => 'troca obrigatória',
                'status' => 'OK',
                'mensagem' => 'Senha redefinida por administrador',
            ]);
            redirecionar_usuarios('Senha redefinida. O usuário deverá trocá-la no próximo acesso.');
        }

        if ($acao === 'remover') {
            if ((int) $alvo['id'] === (int) $_SESSION['usuario_id']) {
                throw new RuntimeException('Você não pode remover a própria conta.');
            }
            if ($alvo['perfil'] === 'administrador' &&
                (int) $alvo['ativo'] === 1 &&
                total_administradores_ativos((int) $alvo['id']) === 0) {
                throw new RuntimeException('O painel deve manter pelo menos um administrador ativo.');
            }

            $stmt = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
            $stmt->execute([':id' => $alvo['id']]);

            registrar_auditoria([
                'acao' => 'REMOVER_USUARIO',
                'tipo_registro' => 'USUARIO',
                'nome_registro' => $alvo['usuario'],
                'valor_antigo' => usuario_perfil_legivel($alvo['perfil']),
                'valor_novo' => 'removido',
                'status' => 'OK',
                'mensagem' => 'Usuário removido',
            ]);
            redirecionar_usuarios('Usuário removido.');
        }

        throw new RuntimeException('Ação inválida.');
    } catch (PDOException $e) {
        error_log('Erro ao administrar usuário: ' . $e->getMessage());
        $erro = str_contains($e->getMessage(), 'UNIQUE')
            ? 'Esse nome de usuário já existe.'
            : 'Não foi possível concluir a operação.';
    } catch (RuntimeException $e) {
        $erro = $e->getMessage();
    }
}

$usuarios = db()
    ->query('SELECT * FROM usuarios ORDER BY ativo DESC, usuario COLLATE NOCASE')
    ->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuários</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:28px;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0}
.page{width:min(1280px,100%);margin:0 auto}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:24px}
.page-header h1{margin:0 0 7px;color:#fff;font-size:28px}
.page-header p{margin:0;color:#94a3b8;font-size:14px}
a{color:#38bdf8;text-decoration:none}
a:hover{text-decoration:underline}
.back-link{display:inline-flex;align-items:center;min-height:40px;padding:9px 13px;border:1px solid #334155;border-radius:10px;background:#071226;font-weight:bold;white-space:nowrap}
.card{background:#071226;border:1px solid #1e293b;padding:22px;border-radius:16px;margin-bottom:22px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:14px;margin-bottom:18px}
.card-head h2{margin:0;color:#fff;font-size:20px}
.card-count{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:28px;padding:0 10px;border-radius:999px;background:#0c4a6e;color:#bae6fd;font-size:12px;font-weight:bold}
.create-grid{display:grid;grid-template-columns:minmax(180px,1fr) minmax(210px,1.15fr) minmax(170px,.75fr) auto;gap:16px;align-items:end}
.field label{display:block;margin:0 0 7px;color:#94a3b8;font-size:12px;font-weight:bold}
input,select,button{min-height:42px;padding:10px 12px;border:1px solid #334155;border-radius:9px;background:#0f172a;color:#fff;font:inherit}
input,select{width:100%}
input:focus,select:focus{border-color:#38bdf8;outline:none;box-shadow:0 0 0 3px #38bdf81f}
button{border-color:#2563eb;background:#2563eb;font-weight:bold;cursor:pointer}
button:hover{filter:brightness(1.08)}
button:disabled{cursor:not-allowed;opacity:.65}
.create-button{min-width:110px}
.helper{margin:14px 0 0;color:#94a3b8;font-size:13px}
.erro,.sucesso{padding:13px 15px;border:1px solid;border-radius:10px;margin-bottom:18px}
.erro{border-color:#991b1b;background:#450a0a;color:#fecaca}
.sucesso{border-color:#166534;background:#052e16;color:#bbf7d0}
.users-list{border-top:1px solid #1e293b}
.user-card{padding:21px 2px;border-bottom:1px solid #1e293b}
.user-card:last-child{border-bottom:0;padding-bottom:2px}
.user-summary{display:flex;justify-content:space-between;align-items:flex-start;gap:18px}
.user-name{margin:0 0 6px;color:#fff;font-size:18px}
.user-created{color:#64748b;font-size:12px}
.badges{display:flex;justify-content:flex-end;gap:7px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;min-height:26px;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:bold}
.badge-active{background:#052e16;color:#86efac}
.badge-inactive{background:#450a0a;color:#fca5a5}
.badge-pending{background:#422006;color:#fde68a}
.badge-current{background:#164e63;color:#a5f3fc}
.user-actions{display:grid;grid-template-columns:minmax(310px,1fr) minmax(330px,1fr);gap:24px;padding-top:18px;align-items:center}
.action-block{min-width:0}
.inline-form{display:flex;align-items:center;gap:9px}
.profile-select{min-width:150px}
.active-toggle{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:8px 4px;color:#cbd5e1;font-size:13px;white-space:nowrap}
.active-toggle input{width:16px;height:16px;min-height:0;margin:0;accent-color:#2563eb}
.secundario{border-color:#475569;background:#334155}
.perigo{min-height:38px;padding:8px 11px;border-color:#7f1d1d;background:transparent;color:#fca5a5;font-size:13px}
.perigo:hover{background:#450a0a}
.senha{min-width:0;max-width:260px}
.account-tools{display:flex;align-items:center;justify-content:flex-end;gap:10px;min-width:0}
.account-tools .inline-form{flex:1;justify-content:flex-end}
.current-action{display:flex;align-items:center;min-height:42px;color:#64748b;font-size:13px}
.password-link{display:inline-flex;align-items:center;min-height:42px;padding:9px 12px;border:1px solid #334155;border-radius:9px;background:#0f172a;font-weight:bold}
.remove-form{display:flex}
@media(max-width:1050px){
    .create-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .create-button{width:100%}
    .user-actions{grid-template-columns:1fr}
    .account-tools,.account-tools .inline-form{justify-content:flex-start}
}
@media(max-width:700px){
    body{padding:18px 13px}
    .page-header{flex-direction:column}
    .card{padding:17px}
    .create-grid,.user-actions{grid-template-columns:1fr}
    .user-summary{flex-direction:column}
    .badges{justify-content:flex-start}
    .inline-form,.account-tools{align-items:stretch;flex-direction:column}
    .profile-select,.senha{max-width:none}
    .active-toggle{width:100%}
    .account-tools .inline-form,.remove-form{width:100%}
    .remove-form button{width:auto;align-self:flex-start}
}
</style>
</head>
<body>
<main class="page">
<header class="page-header">
    <div>
        <h1>Gerenciamento de usuários</h1>
        <p>Crie contas e gerencie perfis, acesso e senhas.</p>
    </div>
    <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
</header>

<?php if ($erro): ?><div class="erro" id="mensagem-erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="sucesso" id="mensagem-sucesso"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<section class="card">
    <div class="card-head">
        <h2>Criar usuário</h2>
    </div>
    <form method="POST" class="create-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="criar">
        <div class="field">
            <label for="novo-usuario">Usuário</label>
            <input id="novo-usuario" name="usuario" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]+" required>
        </div>
        <div class="field">
            <label for="senha-inicial">Senha inicial</label>
            <input id="senha-inicial" type="password" name="senha" minlength="8" maxlength="128" required>
        </div>
        <div class="field">
            <label for="novo-perfil">Perfil</label>
            <select id="novo-perfil" name="perfil">
                <option value="moderador">Moderador</option>
                <option value="administrador">Administrador</option>
            </select>
        </div>
        <button type="submit" class="create-button">Criar</button>
    </form>
    <p class="helper">O novo usuário deverá trocar a senha no primeiro acesso.</p>
</section>

<section class="card">
    <div class="card-head">
        <h2>Contas cadastradas</h2>
        <span class="card-count"><?= count($usuarios) ?></span>
    </div>
    <div class="users-list">
        <?php foreach ($usuarios as $item): ?>
            <?php $contaAtual = (int) $item['id'] === (int) $_SESSION['usuario_id']; ?>
            <article class="user-card">
                <div class="user-summary">
                    <div>
                        <h3 class="user-name"><?= htmlspecialchars($item['usuario']) ?></h3>
                        <div class="user-created">
                            Criado em <?= date('d/m/Y H:i', strtotime($item['criado_em'])) ?>
                        </div>
                    </div>
                    <div class="badges" aria-label="Status da conta">
                        <span class="badge <?= $item['ativo'] ? 'badge-active' : 'badge-inactive' ?>">
                            <?= $item['ativo'] ? 'Ativo' : 'Inativo' ?>
                        </span>
                        <?php if ($item['trocar_senha']): ?>
                            <span class="badge badge-pending">Troca de senha pendente</span>
                        <?php endif; ?>
                        <?php if ($contaAtual): ?>
                            <span class="badge badge-current">Conta atual</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="user-actions">
                    <div class="action-block">
                        <form method="POST" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="acao" value="atualizar">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <select class="profile-select" name="perfil" aria-label="Perfil de <?= htmlspecialchars($item['usuario']) ?>" <?= $contaAtual ? 'disabled' : '' ?>>
                                <option value="moderador" <?= $item['perfil'] === 'moderador' ? 'selected' : '' ?>>Moderador</option>
                                <option value="administrador" <?= $item['perfil'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                            </select>
                            <?php if ($contaAtual): ?>
                                <input type="hidden" name="perfil" value="<?= htmlspecialchars($item['perfil']) ?>">
                            <?php endif; ?>
                            <label class="active-toggle">
                                <input type="checkbox" name="ativo" value="1" <?= $item['ativo'] ? 'checked' : '' ?> <?= $contaAtual ? 'disabled' : '' ?>>
                                Conta ativa
                            </label>
                            <?php if ($contaAtual): ?>
                                <input type="hidden" name="ativo" value="1">
                            <?php endif; ?>
                            <button type="submit" class="secundario">Salvar</button>
                        </form>
                    </div>

                    <div class="action-block account-tools">
                        <?php if ($contaAtual): ?>
                            <a class="password-link" href="alterar-senha.php">Minha senha</a>
                        <?php else: ?>
                            <form method="POST" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="redefinir_senha">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <input class="senha" type="password" name="nova_senha" minlength="8" maxlength="128" placeholder="Nova senha" aria-label="Nova senha para <?= htmlspecialchars($item['usuario']) ?>" required>
                                <button type="submit">Redefinir</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$contaAtual): ?>
                            <form method="POST" class="remove-form" onsubmit="return confirm('Remover este usuário?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                                <button type="submit" class="perigo">Remover</button>
                            </form>
                        <?php else: ?>
                            <span class="current-action">Esta conta não pode ser removida</span>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php if ($sucesso || $erro): ?>
<script>
setTimeout(function(){
    ['mensagem-sucesso','mensagem-erro'].forEach(function(id){
        const aviso = document.getElementById(id);
        if (aviso) aviso.remove();
    });
}, 4000);
</script>
<?php endif; ?>
</body>
</html>
