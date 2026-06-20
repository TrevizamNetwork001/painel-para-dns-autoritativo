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
<title>Usuários</title>
<style>
body{margin:0;padding:25px;font-family:Arial;background:#0f172a;color:#e2e8f0;}
h1,h2{color:#fff;}
a{color:#38bdf8;text-decoration:none;}
.card{background:#020617;border:1px solid #1e293b;padding:20px;border-radius:12px;margin-bottom:20px;}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:end;}
label{display:block;color:#94a3b8;font-size:13px;margin-bottom:5px;}
input,select,button{padding:10px;border:1px solid #334155;border-radius:7px;background:#0f172a;color:#fff;box-sizing:border-box;}
input,select{width:100%;}
button{background:#2563eb;cursor:pointer;}
.perigo{background:#7f1d1d;}
.secundario{background:#334155;}
.erro,.sucesso{padding:12px;border-radius:8px;margin-bottom:18px;}
.erro{background:#7f1d1d;color:#fecaca;}
.sucesso{background:#14532d;color:#bbf7d0;}
table{width:100%;border-collapse:collapse;}
th,td{padding:10px;border-bottom:1px solid #1e293b;text-align:left;vertical-align:top;}
th{color:#94a3b8;}
.acoes{display:flex;gap:8px;flex-wrap:wrap;align-items:end;}
.acoes form{display:flex;gap:8px;align-items:end;flex-wrap:wrap;}
.status-ok{color:#4ade80;font-weight:bold;}
.status-off{color:#f87171;font-weight:bold;}
.senha{max-width:220px;}
small{color:#94a3b8;}
@media(max-width:900px){.grid{grid-template-columns:1fr;}table{display:block;overflow-x:auto;}}
</style>
</head>
<body>
<h1>Gerenciamento de usuários</h1>
<p><a href="dashboard.php">← Voltar ao painel</a></p>

<?php if ($erro): ?><div class="erro" id="mensagem-erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="sucesso" id="mensagem-sucesso"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>

<div class="card">
<h2>Criar usuário</h2>
<form method="POST" class="grid">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="criar">
<div>
<label>Usuário</label>
<input name="usuario" minlength="3" maxlength="32" pattern="[A-Za-z0-9._-]+" required>
</div>
<div>
<label>Senha inicial</label>
<input type="password" name="senha" minlength="8" maxlength="128" required>
</div>
<div>
<label>Perfil</label>
<select name="perfil">
<option value="moderador">Moderador</option>
<option value="administrador">Administrador</option>
</select>
</div>
<button type="submit">Criar</button>
</form>
<p><small>O novo usuário deverá trocar a senha no primeiro acesso.</small></p>
</div>

<div class="card">
<h2>Contas cadastradas</h2>
<table>
<thead><tr><th>Usuário</th><th>Perfil e status</th><th>Redefinir senha</th><th>Remover</th></tr></thead>
<tbody>
<?php foreach ($usuarios as $item): ?>
<tr>
<td>
<strong><?= htmlspecialchars($item['usuario']) ?></strong><br>
<small>Criado em <?= date('d/m/Y H:i', strtotime($item['criado_em'])) ?></small>
</td>
<td>
<form method="POST" class="acoes">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="atualizar">
<input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
<select name="perfil" <?= (int) $item['id'] === (int) $_SESSION['usuario_id'] ? 'disabled' : '' ?>>
<option value="moderador" <?= $item['perfil'] === 'moderador' ? 'selected' : '' ?>>Moderador</option>
<option value="administrador" <?= $item['perfil'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
</select>
<?php if ((int) $item['id'] === (int) $_SESSION['usuario_id']): ?>
<input type="hidden" name="perfil" value="<?= htmlspecialchars($item['perfil']) ?>">
<?php endif; ?>
<label>
<input type="checkbox" name="ativo" value="1" <?= $item['ativo'] ? 'checked' : '' ?> <?= (int) $item['id'] === (int) $_SESSION['usuario_id'] ? 'disabled' : '' ?>>
Ativo
</label>
<?php if ((int) $item['id'] === (int) $_SESSION['usuario_id']): ?>
<input type="hidden" name="ativo" value="1">
<?php endif; ?>
<button type="submit" class="secundario">Salvar</button>
</form>
<div class="<?= $item['ativo'] ? 'status-ok' : 'status-off' ?>">
<?= $item['ativo'] ? 'Ativo' : 'Bloqueado' ?>
<?= $item['trocar_senha'] ? ' / troca de senha pendente' : '' ?>
</div>
</td>
<td>
<?php if ((int) $item['id'] === (int) $_SESSION['usuario_id']): ?>
<a href="alterar-senha.php">Minha senha</a>
<?php else: ?>
<form method="POST" class="acoes">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="redefinir_senha">
<input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
<input class="senha" type="password" name="nova_senha" minlength="8" maxlength="128" placeholder="Nova senha" required>
<button type="submit">Redefinir</button>
</form>
<?php endif; ?>
</td>
<td>
<?php if ((int) $item['id'] !== (int) $_SESSION['usuario_id']): ?>
<form method="POST" onsubmit="return confirm('Remover este usuário?')">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="remover">
<input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
<button type="submit" class="perigo">Remover</button>
</form>
<?php else: ?>
<small>Conta atual</small>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
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
