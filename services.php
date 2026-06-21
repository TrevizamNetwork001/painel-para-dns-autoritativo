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
$servicosMonitorados = 4;
$servicosAtivos = (int) $statusBind['ok'] + (int) $statusFail2ban['ok'] + (int) $statusSsh['ok'] + (int) $statusFirewall['ok'];
$servicosInativos = $servicosMonitorados - $servicosAtivos;
$ultimaVerificacao = date('d/m/Y H:i');

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
                class="action-chip <?= $danger ? 'danger' : '' ?>"
                onclick="return confirmarAcao('<?= htmlspecialchars($nome) ?>')"
            >
                <span class="chip-icon" aria-hidden="true"><?= $danger ? '⚠' : '⚙' ?></span>
                <?= htmlspecialchars($acao['rotulo']) ?>
            </button>
        </form>
        <?php
    }
}

function badge_status(array $status): string
{
    if ($status['ok']) {
        return '<span class="status-badge status-online">Ativo</span>';
    }

    return '<span class="status-badge status-offline">Inativo</span>';
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
:root{
    color-scheme: dark;
    --bg:#0b1220;
    --panel:#0f172a;
    --panel-2:#111c33;
    --border:#24324a;
    --text:#e2e8f0;
    --muted:#94a3b8;
    --accent:#38bdf8;
    --accent-2:#60a5fa;
}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:radial-gradient(circle at top,#101b33 0,var(--bg) 44%,#070b14 100%);
    color:var(--text);
}

.container{
    max-width:1120px;
    margin:0 auto;
    padding:22px 18px 34px;
}

.topbar{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:14px;
}

.topo h1{
    margin:0 0 4px;
    font-size:28px;
}

.lead{
    margin:0 0 8px;
    color:var(--muted);
    line-height:1.45;
}

.top-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
    padding-top:4px;
}

a{
    color:var(--accent);
    text-decoration:none;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:12px;
}

.metric-strip{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin:12px 0 10px;
}

.metric-card{
    background:rgba(17,28,51,.72);
    border:1px solid var(--border);
    border-radius:12px;
    padding:11px 12px;
}

.metric-label{
    display:block;
    color:var(--muted);
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.04em;
}

.metric-value{
    display:block;
    font-size:18px;
    font-weight:800;
    margin-top:4px;
    color:var(--text);
}

.card{
    background:linear-gradient(180deg, rgba(15,23,42,.92), rgba(11,18,32,.94));
    border:1px solid rgba(36,50,74,.9);
    padding:13px;
    border-radius:12px;
    margin-bottom:0;
    box-shadow:0 10px 28px rgba(0,0,0,.16);
}

.card h2{
    margin:0;
    font-size:17px;
    letter-spacing:-0.01em;
}

.card small{
    color:var(--muted);
    display:block;
    margin:7px 0 10px;
    line-height:1.4;
}

.card-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:3px;
}

.card-head h2{
    margin:0;
}

.service-tag{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.18);
    background:rgba(148,163,184,.10);
    color:#dbe7f5;
    font-size:11px;
    white-space:nowrap;
}

.acoes{
    display:flex;
    flex-wrap:wrap;
    gap:7px;
    margin-top:8px;
}

.acoes form{
    margin:0;
}

.action-chip{
    display:inline-flex;
    align-items:center;
    gap:5px;
    width:auto;
    padding:7px 10px;
    border:1px solid rgba(56,189,248,.30);
    border-radius:10px;
    background:rgba(17,26,47,.92);
    color:#fff;
    cursor:pointer;
    font-weight:700;
    font-size:12px;
    line-height:1.2;
    min-height:30px;
    transition:background-color .15s ease,border-color .15s ease,color .15s ease,transform .15s ease;
}

.action-chip:hover,
.action-chip:focus{
    border-color:#3b82f6;
    outline:none;
    background:#13243a;
    transform:translateY(-1px);
}

.action-chip.danger{
    background:rgba(220,38,38,.14);
    border-color:rgba(220,38,38,.35);
    color:#fecaca;
}

.action-chip.danger:hover,
.action-chip.danger:focus{
    background:rgba(220,38,38,.2);
    border-color:#ef4444;
}

.chip-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    line-height:1;
}

.alerta{
    background:rgba(124,45,18,.24);
    border:1px solid rgba(234,88,12,.35);
    color:#fed7aa;
    padding:12px 14px;
    line-height:1.45;
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
    color:#cbd5e1;
}

.badge{
    display:inline-block;
    padding:3px 8px;
    border-radius:999px;
    background:#0f172a;
    border:1px solid #334155;
    color:#cbd5e1;
    font-size:11px;
    margin-bottom:8px;
}

.status-badge{
    display:inline-block;
    padding:4px 9px;
    border-radius:999px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
}

.status-online{
    background:rgba(22,163,74,.14);
    color:#86efac;
    border:1px solid #16a34a;
}

.status-offline{
    background:rgba(69,10,10,.4);
    color:#fecaca;
    border:1px solid #dc2626;
}

.service-card{
    display:flex;
    flex-direction:column;
    min-height:100%;
    position:relative;
    overflow:hidden;
}

.service-card::before{
    content:"";
    position:absolute;
    inset:0 0 auto 0;
    height:3px;
    background:#475569;
}

.servico-bind::before{background:#3b82f6;}
.servico-fail2ban::before{background:#22c55e;}
.servico-ssh::before{background:#eab308;}
.servico-firewall::before{background:#a855f7;}

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
    overflow:hidden;
}

.tabela th,
.tabela td{
    border:1px solid #334155;
    padding:8px 9px;
    font-size:12px;
    vertical-align:top;
}

.tabela th{
    background:#111827;
    color:#dbe7f5;
    text-align:left;
}

.tabela td{
    background:#020617;
}

.tabela tr:hover td{
    background:#0b1327;
}

.table-wrap{
    overflow:auto;
    border:1px solid var(--border);
    border-radius:12px;
    margin-top:8px;
}

.section-title{
    margin:0 0 8px;
    font-size:17px;
}

.service-body{
    flex:1 1 auto;
    position:relative;
    z-index:1;
}

.service-footer{
    margin-top:auto;
    position:relative;
    z-index:1;
}

.table-wrap{
    overflow:auto;
    border:1px solid var(--border);
    border-radius:12px;
    margin-top:10px;
}

@media (max-width: 720px){
    .container{padding:16px 12px 28px}
    .topbar{display:block}
    .topo h1{font-size:24px}
    .card{padding:14px}
    .metric-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
    .top-actions{justify-content:flex-start;margin-top:12px;padding-top:0}
    .actions,.acoes{display:grid}
    .action-chip{width:100%;justify-content:center}
}

@media (max-width: 520px){
    .metric-strip{grid-template-columns:1fr}
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

    <div class="topbar">
        <div class="topo">
            <h1>Serviços</h1>

            <p class="lead">
                Central de gerenciamento dos serviços do servidor DNS.
            </p>
        </div>

        <div class="top-actions">
            <a class="action-chip" href="dashboard.php"><span class="chip-icon" aria-hidden="true">←</span>Voltar ao painel</a>
        </div>
    </div>

    <div class="card alerta">
        <strong>Atenção:</strong>
        ações como reiniciar SSH ou parar BIND podem impactar o acesso ao servidor e a resolução DNS.
    </div>

    <section class="metric-strip" aria-label="Resumo dos serviços">
        <div class="metric-card">
            <span class="metric-label">Monitorados</span>
            <span class="metric-value"><?= (int) $servicosMonitorados ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Ativos</span>
            <span class="metric-value"><?= (int) $servicosAtivos ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Inativos</span>
            <span class="metric-value"><?= (int) $servicosInativos ?></span>
        </div>
        <div class="metric-card">
            <span class="metric-label">Última verificação</span>
            <span class="metric-value" style="font-size:15px"><?= htmlspecialchars($ultimaVerificacao) ?></span>
        </div>
    </section>

    <div class="grid">

        <div class="card servico-bind service-card">
            <div class="service-body">
                <div class="card-head">
                    <h2>BIND9</h2>
                    <span class="service-tag">DNS</span>
                </div>
                <?= badge_status($statusBind) ?>
                <small>Verificação, reload e controle do serviço DNS.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_BIND', 'RELOAD_BIND', 'RESTART_BIND', 'START_BIND', 'STOP_BIND']); ?>
            </div>
        </div>

        <div class="card servico-fail2ban service-card">
            <div class="service-body">
                <div class="card-head">
                    <h2>Fail2Ban</h2>
                    <span class="service-tag">Segurança</span>
                </div>
                <?= badge_status($statusFail2ban) ?>
                <small>Verificação e reinicialização do serviço de proteção.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_FAIL2BAN', 'RESTART_FAIL2BAN']); ?>
            </div>
        </div>

        <div class="card servico-ssh service-card">
            <div class="service-body">
                <div class="card-head">
                    <h2>SSH</h2>
                    <span class="service-tag">Acesso remoto</span>
                </div>
                <?= badge_status($statusSsh) ?>
                <small>Verificação e reinicialização do serviço SSH.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_SSH', 'RESTART_SSH']); ?>
            </div>
        </div>

        <div class="card servico-firewall service-card">
            <div class="service-body">
                <div class="card-head">
                    <h2>Firewall</h2>
                    <span class="service-tag">Firewall</span>
                </div>
                <?= badge_status($statusFirewall) ?>
                <small>Verificação e recarregamento das regras nftables.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_FIREWALL', 'RELOAD_FIREWALL']); ?>
            </div>
        </div>

    </div>

    <div class="card">
        <h2 class="section-title">Últimas ações de serviços</h2>

        <?php if (empty($ultimasAcoes)): ?>
            <p>Nenhuma ação de serviço registrada ainda.</p>
        <?php else: ?>
            <div class="table-wrap">
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
            </div>
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
