<?php
require_once dirname(__DIR__, 2) . "/app/Auth/auth.php";
require_once dirname(__DIR__, 2) . "/app/Auth/users.php";
require_once dirname(__DIR__, 2) . "/app/Support/security.php";
require_once dirname(__DIR__, 2) . "/app/Audit/audit.php";

exigir_administrador();

$erro = '';
$sucesso = $_SESSION['usuarios_sucesso'] ?? '';
$painelFlash = (string) ($_SESSION['usuarios_abrir_painel'] ?? '');
$acaoRequisicao = '';
unset($_SESSION['usuarios_sucesso']);
unset($_SESSION['usuarios_abrir_painel']);

function redirecionar_usuarios(string $mensagem, string $painel = ''): never
{
    $_SESSION['usuarios_sucesso'] = $mensagem;
    if ($painel !== '') {
        $_SESSION['usuarios_abrir_painel'] = $painel;
    }
    header('Location: usuarios.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = (string) ($_POST['acao'] ?? '');
    $acaoRequisicao = $acao;
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
            redirecionar_usuarios('Usuário criado. Ele deverá trocar a senha no primeiro acesso.', 'create-user-panel');
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
            redirecionar_usuarios('Usuário atualizado.', 'users-list-panel');
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
            redirecionar_usuarios('Senha redefinida. O usuário deverá trocá-la no próximo acesso.', 'users-list-panel');
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
            redirecionar_usuarios('Usuário removido.', 'users-list-panel');
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

$total_contas = count($usuarios);
$contas_ativas = 0;
$contas_inativas = 0;
$troca_pendente = 0;
$perfil_administrador = 0;
$perfil_moderador = 0;

foreach ($usuarios as $usuario_item) {
    if ((int) $usuario_item['ativo'] === 1) {
        $contas_ativas++;
    } else {
        $contas_inativas++;
    }

    if (!empty($usuario_item['trocar_senha'])) {
        $troca_pendente++;
    }

    if (($usuario_item['perfil'] ?? '') === 'administrador') {
        $perfil_administrador++;
    }
    if (($usuario_item['perfil'] ?? '') === 'moderador') {
        $perfil_moderador++;
    }
}

$diagnosticosUsuarios = [
    [
        'nivel' => 'ok',
        'icone' => '✓',
        'texto' => 'A conta atual continua protegida contra remoção.',
    ],
    [
        'nivel' => $contas_ativas > 0 ? 'ok' : 'bad',
        'icone' => $contas_ativas > 0 ? '✓' : '✖',
        'texto' => 'O painel tem ' . $contas_ativas . ' conta(s) ativa(s).',
    ],
    [
        'nivel' => $contas_inativas > 0 ? 'warn' : 'ok',
        'icone' => $contas_inativas > 0 ? '⚠' : '✓',
        'texto' => 'O painel tem ' . $contas_inativas . ' conta(s) inativa(s).',
    ],
    [
        'nivel' => $troca_pendente > 0 ? 'warn' : 'ok',
        'icone' => $troca_pendente > 0 ? '⚠' : '✓',
        'texto' => 'Há ' . $troca_pendente . ' conta(s) com troca de senha pendente no painel.',
    ],
    [
        'nivel' => 'ok',
        'icone' => '✓',
        'texto' => 'Perfis disponíveis no painel: Administrador e Moderador.',
    ],
];

$painelPadrao = $painelFlash;
if ($erro !== '') {
    if ($acaoRequisicao === 'criar') {
        $painelPadrao = 'create-user-panel';
    } elseif (in_array($acaoRequisicao, ['atualizar', 'redefinir_senha', 'remover'], true)) {
        $painelPadrao = 'users-list-panel';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuários</title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;font-size:14px}
.page{width:min(1280px,100%);margin:0 auto}
.page-header{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:20px}
.page-header h1{margin:0 0 6px;color:#fff;font-size:26px}
.page-header p{margin:0;color:#94a3b8;font-size:13px}
a{color:#38bdf8;text-decoration:none}
a:hover{text-decoration:underline}
.back-link{display:inline-flex;align-items:center;min-height:36px;padding:7px 11px;border:1px solid #334155;border-radius:9px;background:#071226;font-size:13px;font-weight:bold;white-space:nowrap}
.card{background:#071226;border:1px solid #1e293b;padding:18px;border-radius:14px;margin-bottom:18px}
.card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px}
.card-head h2{margin:0;color:#fff;font-size:18px}
.card-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.panel-lead{margin:4px 0 0;color:#94a3b8;font-size:12px;line-height:1.45}
.card-count{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:24px;padding:0 8px;border-radius:999px;background:#0c4a6e;color:#bae6fd;font-size:11px;font-weight:bold}
.panel-overview{padding-bottom:16px}
.panel-summary-badges,.diagnostic-badges{display:flex;gap:8px;flex-wrap:wrap}
.pill{display:inline-flex;align-items:center;gap:6px;min-height:28px;padding:5px 10px;border:1px solid #334155;border-radius:999px;background:#0f172a;color:#cbd5e1;font-size:11px;font-weight:bold;white-space:nowrap}
.pill strong{color:#fff}
.pill.ok{background:#10231a;border-color:#14532d;color:#86efac}
.pill.bad{background:#2a1114;border-color:#7f1d1d;color:#fca5a5}
.pill.warn{background:#2b2110;border-color:#854d0e;color:#fde68a}
.pill.info{background:#10233d;border-color:#1d4ed8;color:#bfdbfe}
.pill.neutral{background:#0f172a;color:#cbd5e1}
.tool-strip{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.tool-chip{display:inline-flex;align-items:center;gap:7px;min-height:32px;padding:7px 11px;border:1px solid #334155;border-radius:999px;background:#0f172a;color:#dbeafe;font-size:12px;font-weight:800;cursor:pointer;white-space:nowrap;text-decoration:none}
.tool-chip:hover{background:#13233b;border-color:#38bdf8;text-decoration:none}
.tool-chip.primary{background:#10233d;border-color:#1d4ed8;color:#bfdbfe}
.tool-chip.success{background:#10261c;border-color:#14532d;color:#bbf7d0}
.tool-chip.ghost{background:#101827;color:#cbd5e1}
.tool-chip .icon{font-size:13px;line-height:1}
.panel-collapsible[hidden]{display:none}
.panel-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.diagnostic-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.diagnostic-card{display:flex;gap:10px;align-items:flex-start;padding:11px 12px;border:1px solid #334155;border-radius:10px;background:#0f172a}
.diagnostic-card .diagnostic-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:999px;font-size:12px;font-weight:800;flex:0 0 auto}
.diagnostic-card.ok{border-color:#14532d}
.diagnostic-card.ok .diagnostic-icon{background:#14532d;color:#86efac}
.diagnostic-card.warn{border-color:#854d0e}
.diagnostic-card.warn .diagnostic-icon{background:#3b2f13;color:#fde68a}
.diagnostic-card.bad{border-color:#7f1d1d}
.diagnostic-card.bad .diagnostic-icon{background:#450a0a;color:#fca5a5}
.diagnostic-card .diagnostic-text{font-size:12px;line-height:1.45;color:#e2e8f0}
.diagnostic-footer{margin-top:12px}
.create-user-form-inner{width:100%;max-width:1080px;margin:0 auto}
.create-grid{display:grid;grid-template-columns:minmax(220px,300px) minmax(260px,340px) minmax(180px,260px) auto;gap:12px;align-items:end;width:100%}
.field label{display:block;margin:0 0 6px;color:#94a3b8;font-size:11px;font-weight:bold}
input,select,button{min-height:37px;padding:8px 10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff;font:inherit;font-size:13px}
input,select{width:100%}
input:focus,select:focus{border-color:#38bdf8;outline:none;box-shadow:0 0 0 3px #38bdf81f}
button{min-height:34px;padding:6px 9px;border-color:#2563eb;background:#2563eb;font-size:12px;font-weight:bold;cursor:pointer}
button:hover{filter:brightness(1.08)}
button:disabled{cursor:not-allowed;opacity:.65}
.create-button{min-width:84px}
.helper{margin:11px 0 0;color:#94a3b8;font-size:12px}
.erro,.sucesso{padding:11px 13px;border:1px solid;border-radius:9px;margin-bottom:15px}
.erro{border-color:#991b1b;background:#450a0a;color:#fecaca}
.sucesso{border-color:#166534;background:#052e16;color:#bbf7d0}
.users-list{border-top:1px solid #1e293b}
.users-panel[hidden]{display:none}
.user-card{padding:16px 10px;border-bottom:1px solid #1e293b}
.user-card:last-child{border-bottom:0;padding-bottom:2px}
.user-row-inner{width:100%;max-width:1120px;margin:0 auto;padding:0 12px 0 20px}
.user-summary{display:block}
.user-title-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.user-name{margin:0;color:#fff;font-size:16px}
.user-created{color:#64748b;font-size:11px}
.badges{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.badge{display:inline-flex;align-items:center;min-height:22px;padding:3px 8px;border-radius:999px;font-size:10px;font-weight:bold;letter-spacing:.01em}
.badge-active{background:#052e16;color:#86efac}
.badge-inactive{background:#450a0a;color:#fca5a5}
.badge-pending{background:#422006;color:#fde68a}
.badge-current{background:#164e63;color:#a5f3fc}
.badge-profile-admin{background:#10233d;color:#bfdbfe}
.badge-profile-mod{background:#1f2937;color:#cbd5e1}
.user-actions{display:flex;align-items:center;gap:10px 12px;padding-top:13px;flex-wrap:wrap;width:100%;max-width:1080px}
.action-block{min-width:0}
.inline-form{display:flex;align-items:center;gap:7px}
.user-actions>.action-block:first-child .inline-form{display:grid;grid-template-columns:minmax(260px,300px) auto auto;justify-content:start}
.profile-select{width:300px;max-width:100%;min-width:0}
.active-toggle{display:inline-flex;align-items:center;gap:7px;min-height:37px;padding:6px 3px;color:#cbd5e1;font-size:12px;white-space:nowrap}
.active-toggle input{width:15px;height:15px;min-height:0;margin:0;accent-color:#2563eb}
.secundario{border-color:#475569;background:#334155}
.perigo{min-height:34px;padding:6px 9px;border-color:#7f1d1d;background:transparent;color:#fca5a5;font-size:12px}
.perigo:hover{background:#450a0a}
.senha{width:290px;max-width:100%;min-width:0}
.account-tools{display:flex;align-items:center;gap:8px;min-width:0;flex-wrap:wrap}
.account-tools .inline-form{display:grid;grid-template-columns:minmax(280px,290px) auto;justify-content:start;width:auto}
.account-tools .senha{width:100%;max-width:none}
.current-action{display:flex;align-items:center;min-height:37px;color:#64748b;font-size:12px}
.password-link{display:inline-flex;align-items:center;min-height:37px;padding:7px 10px;border:1px solid #334155;border-radius:8px;background:#0f172a;font-size:13px;font-weight:bold}
.remove-form{display:flex}
.toggle-users-btn{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:6px 10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#e2e8f0;font-size:12px;font-weight:bold;cursor:pointer;white-space:nowrap}
.toggle-users-btn:hover{background:#111c33}
.toggle-users-btn[aria-expanded="true"]{border-color:#38bdf8;background:#10233d}
@media(max-width:1180px){
    .user-actions{max-width:100%}
}
@media(max-width:900px){
    .user-actions{max-width:100%}
    .create-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
    .create-button{width:100%}
    .profile-select,.senha{width:100%;max-width:100%}
    .account-tools .inline-form{grid-template-columns:1fr auto}
    .diagnostic-grid{grid-template-columns:1fr}
}
@media(max-width:700px){
    body{padding:16px 12px}
    .page-header{flex-direction:column}
    .card{padding:15px}
    .create-grid{grid-template-columns:1fr}
    .user-title-line{align-items:flex-start}
    .user-actions,.inline-form,.account-tools{align-items:stretch}
    .user-actions>.action-block:first-child .inline-form,.account-tools .inline-form{display:flex;justify-content:flex-start;flex-direction:column}
    .profile-select,.senha{width:100%;max-width:none}
    .active-toggle{width:100%}
    .account-tools,.account-tools .inline-form,.remove-form{width:100%}
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

<section class="card panel-overview">
    <div class="card-head">
        <div>
            <h2>Usuários do painel</h2>
            <p class="panel-lead">Gerencie contas administrativas, perfis, status e senhas de acesso.</p>
        </div>
        <div class="card-head-actions">
            <span class="card-count"><?= $total_contas ?> contas</span>
        </div>
    </div>
    <div class="panel-summary-badges" aria-label="Resumo das contas">
        <span class="pill neutral"><strong><?= $total_contas ?></strong>Total</span>
        <span class="pill ok"><strong><?= $contas_ativas ?></strong>Ativas</span>
        <span class="pill bad"><strong><?= $contas_inativas ?></strong>Inativas</span>
        <span class="pill warn"><strong><?= $troca_pendente ?></strong>Troca pendente</span>
    </div>
    <div class="tool-strip">
        <button type="button" class="tool-chip primary" data-panel-toggle="create-user-panel" data-open-label="Abrir cadastro" data-close-label="Ocultar cadastro">
            <span class="icon">➕</span><span class="label">Abrir cadastro</span>
        </button>
        <button type="button" class="tool-chip ghost" data-panel-toggle="users-list-panel" data-open-label="Mostrar contas" data-close-label="Ocultar contas">
            <span class="icon">👥</span><span class="label">Mostrar contas</span>
        </button>
        <button type="button" class="tool-chip success" data-panel-toggle="diagnostic-panel" data-open-label="Abrir resumo" data-close-label="Ocultar resumo">
            <span class="icon">🩺</span><span class="label">Abrir resumo</span>
        </button>
    </div>
</section>

<section class="card panel-collapsible" id="create-user-panel" hidden>
    <div class="card-head">
        <div>
            <h2>Criar usuário</h2>
            <p class="panel-lead">Usuário, senha inicial e perfil.</p>
        </div>
        <div class="card-head-actions">
            <button type="button" class="toggle-users-btn" data-panel-toggle="create-user-panel" data-open-label="Abrir cadastro" data-close-label="Ocultar cadastro"><span class="label">Abrir cadastro</span></button>
        </div>
    </div>
    <div class="create-user-form-inner">
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
    </div>
</section>

<section class="card panel-collapsible" id="users-list-panel" hidden>
    <div class="card-head">
        <div>
            <h2>Contas cadastradas</h2>
            <p class="panel-lead">Lista com perfis, status e ações rápidas por conta.</p>
        </div>
        <div class="card-head-actions">
            <span class="card-count"><?= $total_contas ?></span>
            <button type="button" id="toggle-users-list" class="toggle-users-btn" data-panel-toggle="users-list-panel" data-open-label="Mostrar contas" data-close-label="Ocultar contas"><span class="label">Mostrar contas</span></button>
        </div>
    </div>
    <div class="users-list">
        <?php foreach ($usuarios as $item): ?>
            <?php $contaAtual = (int) $item['id'] === (int) $_SESSION['usuario_id']; ?>
            <article class="user-card">
                <div class="user-row-inner">
                    <div class="user-summary">
                        <div class="user-title-line">
                            <h3 class="user-name"><?= htmlspecialchars($item['usuario']) ?></h3>
                            <div class="badges" aria-label="Status da conta">
                                <span class="badge <?= $item['perfil'] === 'administrador' ? 'badge-profile-admin' : 'badge-profile-mod' ?>">
                                    <?= $item['perfil'] === 'administrador' ? 'Administrador' : 'Moderador' ?>
                                </span>
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
                        <div class="user-created">
                            Criado em <?= date('d/m/Y H:i', strtotime($item['criado_em'])) ?>
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
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="card panel-collapsible" id="diagnostic-panel" hidden>
    <div class="card-head">
        <div>
            <h2>Resumo das contas</h2>
            <p class="panel-lead">Visão rápida do estado agregado das contas do painel.</p>
        </div>
        <div class="card-head-actions">
            <button type="button" class="toggle-users-btn" data-panel-toggle="diagnostic-panel" data-open-label="Abrir resumo" data-close-label="Ocultar resumo"><span class="label">Abrir resumo</span></button>
        </div>
    </div>
    <div class="diagnostic-grid">
        <?php foreach ($diagnosticosUsuarios as $diagnostico): ?>
            <div class="diagnostic-card <?= htmlspecialchars($diagnostico['nivel']) ?>">
                <span class="diagnostic-icon" aria-hidden="true"><?= htmlspecialchars($diagnostico['icone']) ?></span>
                <div class="diagnostic-text"><?= htmlspecialchars($diagnostico['texto']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="diagnostic-footer">
        <div class="panel-summary-badges" aria-label="Perfis disponíveis">
            <?php if ($perfil_administrador > 0): ?>
                <span class="pill info"><strong><?= $perfil_administrador ?></strong>Administrador</span>
            <?php endif; ?>
            <?php if ($perfil_moderador > 0): ?>
                <span class="pill neutral"><strong><?= $perfil_moderador ?></strong>Moderador</span>
            <?php endif; ?>
        </div>
    </div>
</section>
</main>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
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
<script>
(function () {
    const initialPanel = <?= json_encode($painelPadrao) ?>;
    const storageKey = 'usuarios_paineis_abertos';
    const panelIds = ['create-user-panel', 'users-list-panel', 'diagnostic-panel'];
    let savedStates = {};

    try {
        savedStates = JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
    } catch (e) {
        savedStates = {};
    }

    function buttonLabel(button, open) {
        const label = button.querySelector('.label');
        if (!label) return;
        label.textContent = open ? (button.dataset.closeLabel || label.textContent) : (button.dataset.openLabel || label.textContent);
    }

    function setPanelOpen(panelId, open, persist) {
        const panel = document.getElementById(panelId);
        if (!panel) return;

        panel.hidden = !open;
        document.querySelectorAll(`[data-panel-toggle="${panelId}"]`).forEach(function(button) {
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            buttonLabel(button, open);
        });

        if (persist) {
            savedStates[panelId] = open;
            try {
                localStorage.setItem(storageKey, JSON.stringify(savedStates));
            } catch (e) {}
        }
    }

    panelIds.forEach(function(panelId) {
        const shouldOpen = initialPanel
            ? initialPanel === panelId
            : savedStates[panelId] === true;
        setPanelOpen(panelId, shouldOpen, false);
    });

    if (initialPanel) {
        setPanelOpen(initialPanel, true, true);
    }

    document.querySelectorAll('[data-panel-toggle]').forEach(function(button) {
        button.addEventListener('click', function() {
            const panelId = button.dataset.panelToggle || '';
            if (!panelId) return;
            const panel = document.getElementById(panelId);
            if (!panel) return;
            setPanelOpen(panelId, panel.hidden, true);
        });
    });
})();
</script>
</body>
</html>
