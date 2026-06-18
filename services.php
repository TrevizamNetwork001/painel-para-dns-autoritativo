<?php

require_once "config.php";
require_once "includes/auth.php";
require_once "includes/security.php";
require_once "includes/audit.php";
require_once "includes/db.php";

$acoes = [
    'CHECK_BIND' => [
        'rotulo' => '🔍 Verificar BIND',
        'comando' => '/usr/bin/systemctl is-active bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'RELOAD_BIND' => [
        'rotulo' => '🔄 Recarregar BIND',
        'comando' => 'sudo /usr/sbin/rndc reload 2>&1',
        'servico' => 'BIND9',
    ],
    'RESTART_BIND' => [
        'rotulo' => '♻️ Reiniciar BIND',
        'comando' => 'sudo /usr/bin/systemctl restart bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'START_BIND' => [
        'rotulo' => '▶️ Iniciar BIND',
        'comando' => 'sudo /usr/bin/systemctl start bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'STOP_BIND' => [
        'rotulo' => '⏹️ Parar BIND',
        'comando' => 'sudo /usr/bin/systemctl stop bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'CHECK_FAIL2BAN' => [
        'rotulo' => '🔍 Verificar Fail2Ban',
        'comando' => 'sudo /usr/bin/fail2ban-client ping 2>&1',
        'servico' => 'Fail2Ban',
    ],
    'RESTART_FAIL2BAN' => [
        'rotulo' => '♻️ Reiniciar Fail2Ban',
        'comando' => 'sudo /usr/bin/systemctl restart fail2ban 2>&1',
        'servico' => 'Fail2Ban',
    ],
    'CHECK_SSH' => [
        'rotulo' => '🔍 Verificar SSH',
        'comando' => '/usr/bin/systemctl is-active ssh 2>&1',
        'servico' => 'SSH',
    ],
    'RESTART_SSH' => [
        'rotulo' => '♻️ Reiniciar SSH',
        'comando' => 'sudo /usr/bin/systemctl restart ssh 2>&1',
        'servico' => 'SSH',
    ],
    'CHECK_FIREWALL' => [
        'rotulo' => '🔍 Verificar firewall',
        'comando' => '/usr/bin/systemctl is-active nftables 2>&1',
        'servico' => 'Firewall',
    ],
    'RELOAD_FIREWALL' => [
        'rotulo' => '🔄 Recarregar firewall',
        'comando' => 'sudo /usr/sbin/nft -f /etc/nftables.conf 2>&1',
        'servico' => 'Firewall',
    ],
];

function status_servico(string $cmd): array
{
    $saida = [];
    $codigo = 1;

    exec($cmd, $saida, $codigo);

    $txt = trim(implode("\n", $saida));

    return [
        'ok' => $codigo === 0 && str_contains(strtolower($txt), 'active'),
        'texto' => $txt !== '' ? $txt : 'desconhecido'
    ];
}

$statusBind = status_servico('/usr/bin/systemctl is-active bind9 2>&1');
$statusFail2ban = status_servico('/usr/bin/systemctl is-active fail2ban 2>&1');
$statusSsh = status_servico('/usr/bin/systemctl is-active ssh 2>&1');
$statusFirewall = status_servico('/usr/bin/systemctl is-active nftables 2>&1');

$resultado = null;
$status = null;
$acaoExecutada = null;
$servicoExecutado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $acao = $_POST['acao'] ?? '';

    if (!isset($acoes[$acao])) {
        http_response_code(400);
        $status = 'ERRO';
        $resultado = 'Ação de serviço inválida.';
        $acaoExecutada = 'Ação inválida';
        $servicoExecutado = 'Serviço';
    } else {
        $saida = [];
        $codigo = 1;

        exec($acoes[$acao]['comando'], $saida, $codigo);

        $status = $codigo === 0 ? 'OK' : 'ERRO';
        $resultado = trim(implode("\n", $saida));
        $acaoExecutada = $acoes[$acao]['rotulo'];
        $servicoExecutado = $acoes[$acao]['servico'];

        if ($resultado === '') {
            $resultado = $codigo === 0
                ? 'Comando executado com sucesso.'
                : "O comando terminou com código {$codigo}.";
        }

        try {
            registrar_auditoria([
                'acao' => $acao,
                'tipo_registro' => 'SERVICO',
                'nome_registro' => $acoes[$acao]['servico'],
                'valor_antigo' => 'estado anterior não verificado',
                'valor_novo' => $status === 'OK' ? 'comando executado' : 'falha na execução',
                'status' => $status,
                'mensagem' => substr($resultado, 0, 4000),
            ]);
        } catch (Throwable $e) {
            error_log('Falha ao registrar auditoria de serviço: ' . $e->getMessage());
        }
    }
}

function ultimas_acoes_servicos(): array
{
    try {
        $pdo = db();

        $stmt = $pdo->query("
            SELECT usuario, acao, nome_registro, status, criado_em
            FROM audit_logs
            WHERE tipo_registro = 'SERVICO'
            ORDER BY id DESC
            LIMIT 5
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Erro ao buscar últimas ações de serviços: ' . $e->getMessage());
        return [];
    }
}

$ultimasAcoes = ultimas_acoes_servicos();

function botoes_servico(array $acoes, array $nomes): void
{
    foreach ($nomes as $nome) {
        $acao = $acoes[$nome];

        $danger = in_array($nome, [
            'STOP_BIND',
            'RESTART_SSH',
        ], true);

        ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="<?= htmlspecialchars($nome) ?>">
            <button
                type="submit"
                class="<?= $danger ? 'btn-danger' : '' ?>"
                onclick="return confirmarAcao('<?= htmlspecialchars($nome) ?>')"
            >
                <?= htmlspecialchars($acao['rotulo']) ?>
            </button>
        </form>
        <?php
    }
}

function badge_status(array $status): string
{
    if ($status['ok']) {
        return '<span class="status online">🟢 Online</span>';
    }

    return '<span class="status offline">🔴 Offline</span>';
}

function acao_curta(string $acao): string
{
    return match ($acao) {
        'CHECK_BIND' => 'Verificar BIND',
        'RELOAD_BIND' => 'Recarregar BIND',
        'RESTART_BIND' => 'Reiniciar BIND',
        'START_BIND' => 'Iniciar BIND',
        'STOP_BIND' => 'Parar BIND',
        'CHECK_FAIL2BAN' => 'Verificar Fail2Ban',
        'RESTART_FAIL2BAN' => 'Reiniciar Fail2Ban',
        'CHECK_SSH' => 'Verificar SSH',
        'RESTART_SSH' => 'Reiniciar SSH',
        'CHECK_FIREWALL' => 'Verificar firewall',
        'RELOAD_FIREWALL' => 'Recarregar firewall',
        default => $acao
    };
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Serviços</title>
<style>
body{
    margin:0;
    font-family:Arial;
    background:#0f172a;
    color:#e2e8f0;
}

.container{
    max-width:1200px;
    margin:40px auto;
    padding:0 20px;
}

.topo{
    margin-bottom:25px;
}

.topo h1{
    margin-bottom:8px;
    font-size:34px;
}

.topo p{
    color:#94a3b8;
}

a{
    color:#38bdf8;
    text-decoration:none;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
    gap:18px;
}

.card{
    background:#020617;
    border:1px solid #1e293b;
    padding:22px;
    border-radius:12px;
    margin-bottom:18px;
}

.card h2{
    margin-top:0;
    margin-bottom:8px;
}

.card small{
    color:#94a3b8;
    display:block;
    margin-bottom:18px;
}

.acoes{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
}

.acoes form{
    margin:0;
}

button{
    padding:11px 16px;
    border:0;
    border-radius:7px;
    background:#2563eb;
    color:#fff;
    cursor:pointer;
    font-weight:bold;
}

button:hover{
    background:#1d4ed8;
}

.btn-danger{
    background:#dc2626;
}

.btn-danger:hover{
    background:#b91c1c;
}

.alerta{
    background:#7c2d12;
    border:1px solid #ea580c;
    color:#fed7aa;
}

.ok{
    border-color:#16a34a;
    color:#bbf7d0;
}

.erro{
    border-color:#dc2626;
    color:#fecaca;
}

pre{
    white-space:pre-wrap;
    overflow-wrap:anywhere;
    margin:0;
}

.badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    background:#0f172a;
    border:1px solid #334155;
    color:#cbd5e1;
    font-size:12px;
    margin-bottom:12px;
}

.status{
    display:inline-block;
    margin-bottom:14px;
    padding:5px 10px;
    border-radius:999px;
    font-size:13px;
    font-weight:bold;
}

.online{
    background:#052e16;
    color:#86efac;
    border:1px solid #16a34a;
}

.offline{
    background:#450a0a;
    color:#fecaca;
    border:1px solid #dc2626;
}

.servico-bind{border-left:4px solid #3b82f6;}
.servico-fail2ban{border-left:4px solid #22c55e;}
.servico-ssh{border-left:4px solid #eab308;}
.servico-firewall{border-left:4px solid #a855f7;}

.toast{
    position:fixed;
    top:22px;
    right:22px;
    z-index:9999;
    min-width:320px;
    max-width:520px;
    padding:16px 18px;
    border-radius:10px;
    box-shadow:0 10px 30px rgba(0,0,0,.35);
    background:#020617;
    border:1px solid #334155;
}

.toast.ok{
    border-color:#16a34a;
    color:#bbf7d0;
}

.toast.erro{
    border-color:#dc2626;
    color:#fecaca;
}

.tabela{
    width:100%;
    border-collapse:collapse;
    margin-top:10px;
}

.tabela th,
.tabela td{
    border:1px solid #334155;
    padding:9px;
    font-size:13px;
}

.tabela th{
    background:#111827;
}

.tabela td{
    background:#020617;
}
</style>
</head>
<body>

<?php if ($resultado !== null): ?>
<div id="toast-retorno" class="toast <?= $status === 'OK' ? 'ok' : 'erro' ?>">
    <strong><?= $status === 'OK' ? '✅' : '❌' ?> <?= htmlspecialchars($status) ?></strong>
    <br>
    <?= htmlspecialchars($servicoExecutado ?? 'Serviço') ?> —
    <?= htmlspecialchars($acaoExecutada ?? 'Ação executada') ?>
    <br><br>
    <pre><?= htmlspecialchars($resultado) ?></pre>
</div>

<script>
setTimeout(function () {
    const msg = document.getElementById('toast-retorno');

    if (msg) {
        msg.style.transition = 'opacity 0.5s ease';
        msg.style.opacity = '0';

        setTimeout(function () {
            msg.remove();
        }, 500);
    }
}, 5000);
</script>
<?php endif; ?>

<div class="container">

    <div class="topo">
        <h1>Serviços</h1>

        <p>
            Central de gerenciamento dos serviços do servidor DNS.
        </p>

        <p>
            <a href="dashboard.php">← Voltar ao painel</a>
        </p>
    </div>

    <div class="card alerta">
        <strong>Atenção:</strong>
        ações como reiniciar SSH ou parar BIND podem impactar o acesso ao servidor e a resolução DNS.
    </div>

    <div class="grid">

        <div class="card servico-bind">
            <span class="badge">DNS</span>
            <h2>BIND9</h2>
            <?= badge_status($statusBind) ?>
            <small>Verificação, reload e controle do serviço DNS.</small>

            <div class="acoes">
                <?php botoes_servico($acoes, ['CHECK_BIND', 'RELOAD_BIND', 'RESTART_BIND', 'START_BIND', 'STOP_BIND']); ?>
            </div>
        </div>

        <div class="card servico-fail2ban">
            <span class="badge">Segurança</span>
            <h2>Fail2Ban</h2>
            <?= badge_status($statusFail2ban) ?>
            <small>Verificação e reinicialização do serviço de proteção.</small>

            <div class="acoes">
                <?php botoes_servico($acoes, ['CHECK_FAIL2BAN', 'RESTART_FAIL2BAN']); ?>
            </div>
        </div>

        <div class="card servico-ssh">
            <span class="badge">Acesso remoto</span>
            <h2>SSH</h2>
            <?= badge_status($statusSsh) ?>
            <small>Verificação e reinicialização do serviço SSH.</small>

            <div class="acoes">
                <?php botoes_servico($acoes, ['CHECK_SSH', 'RESTART_SSH']); ?>
            </div>
        </div>

        <div class="card servico-firewall">
            <span class="badge">Firewall</span>
            <h2>Firewall</h2>
            <?= badge_status($statusFirewall) ?>
            <small>Verificação e recarregamento das regras nftables.</small>

            <div class="acoes">
                <?php botoes_servico($acoes, ['CHECK_FIREWALL', 'RELOAD_FIREWALL']); ?>
            </div>
        </div>

    </div>

    <div class="card">
        <h2>Últimas ações de serviços</h2>

        <?php if (empty($ultimasAcoes)): ?>
            <p>Nenhuma ação de serviço registrada ainda.</p>
        <?php else: ?>
            <table class="tabela">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Usuário</th>
                        <th>Serviço</th>
                        <th>Ação</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ultimasAcoes as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($log['criado_em']))) ?></td>
                            <td><?= htmlspecialchars($log['usuario'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['nome_registro'] ?? '-') ?></td>
                            <td><?= htmlspecialchars(acao_curta($log['acao'] ?? '-')) ?></td>
                            <td><?= htmlspecialchars($log['status'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<script>
function confirmarAcao(acao) {
    const mensagens = {
        'STOP_BIND': 'Tem certeza que deseja parar o BIND?\n\nIsso pode interromper a resolução DNS do servidor.',
        'RESTART_SSH': 'Tem certeza que deseja reiniciar o SSH?\n\nIsso pode impactar conexões remotas ativas.'
    };

    if (mensagens[acao]) {
        return confirm(mensagens[acao]);
    }

    return true;
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
