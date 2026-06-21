<?php

require_once "config.php";
require_once "includes/auth.php";
require_once "includes/security.php";
require_once "includes/audit.php";
require_once "includes/db.php";

$acoes = [
    'CHECK_BIND' => [
        'rotulo' => 'Verificar BIND',
        'comando' => '/usr/bin/systemctl is-active bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'RELOAD_BIND' => [
        'rotulo' => 'Recarregar BIND',
        'comando' => 'sudo /usr/sbin/rndc reload 2>&1',
        'servico' => 'BIND9',
    ],
    'RESTART_BIND' => [
        'rotulo' => 'Reiniciar BIND',
        'comando' => 'sudo /usr/bin/systemctl restart bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'START_BIND' => [
        'rotulo' => 'Iniciar BIND',
        'comando' => 'sudo /usr/bin/systemctl start bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'STOP_BIND' => [
        'rotulo' => 'Parar BIND',
        'comando' => 'sudo /usr/bin/systemctl stop bind9 2>&1',
        'servico' => 'BIND9',
    ],
    'CHECK_FAIL2BAN' => [
        'rotulo' => 'Verificar Fail2Ban',
        'comando' => 'sudo /usr/bin/fail2ban-client ping 2>&1',
        'servico' => 'Fail2Ban',
    ],
    'RESTART_FAIL2BAN' => [
        'rotulo' => 'Reiniciar Fail2Ban',
        'comando' => 'sudo /usr/bin/systemctl restart fail2ban 2>&1',
        'servico' => 'Fail2Ban',
    ],
    'CHECK_SSH' => [
        'rotulo' => 'Verificar SSH',
        'comando' => '/usr/bin/systemctl is-active ssh 2>&1',
        'servico' => 'SSH',
    ],
    'RESTART_SSH' => [
        'rotulo' => 'Reiniciar SSH',
        'comando' => 'sudo /usr/bin/systemctl restart ssh 2>&1',
        'servico' => 'SSH',
    ],
    'CHECK_FIREWALL' => [
        'rotulo' => 'Verificar firewall',
        'comando' => '/usr/bin/systemctl is-active nftables 2>&1',
        'servico' => 'Firewall',
    ],
    'RELOAD_FIREWALL' => [
        'rotulo' => 'Recarregar firewall',
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
$historicoLink = is_file(__DIR__ . '/auditoria.php') ? 'auditoria.php' : null;

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
                <span class="chip-icon" aria-hidden="true"><?= service_action_icon($nome) ?></span>
                <?= htmlspecialchars($acao['rotulo']) ?>
            </button>
        </form>
        <?php
    }
}

function badge_status(array $status): string
{
    if ($status['ok']) {
        return '<span class="status-pill status-active">Ativo</span>';
    }

    return '<span class="status-pill status-inactive">Inativo</span>';
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

function service_metric_icon(string $key): string
{
    return ui_icon_svg(match ($key) {
        'monitorados' => 'list',
        'ativos' => 'check',
        'inativos' => 'circle-x',
        'ultima' => 'history',
        default => 'dot',
    }, 16);
}

function service_action_icon(string $acao): string
{
    return ui_icon_svg(match ($acao) {
        'CHECK_BIND', 'CHECK_FAIL2BAN', 'CHECK_SSH', 'CHECK_FIREWALL' => 'search',
        'RELOAD_BIND', 'RELOAD_FIREWALL' => 'refresh-cw',
        'RESTART_BIND', 'RESTART_FAIL2BAN', 'RESTART_SSH' => 'rotate-cw',
        'START_BIND' => 'play',
        'STOP_BIND' => 'square',
        default => 'dot',
    }, 14);
}

function service_service_icon(string $service): string
{
    return ui_icon_svg(match ($service) {
        'bind' => 'globe',
        'fail2ban' => 'shield',
        'ssh' => 'terminal',
        'firewall' => 'shield-check',
        default => 'dot',
    }, 18);
}

function ui_icon_svg(string $name, int $size = 16): string
{
    $svg = match ($name) {
        'list' => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'circle-x' => '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'history' => '<path d="M3 3v5h5"/><path d="M3.2 8A9 9 0 1 1 6 18"/><path d="M12 8v4l3 2"/>',
        'alert-triangle' => '<path d="M10.3 3.3 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>',
        'refresh-cw' => '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
        'rotate-cw' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/>',
        'play' => '<path d="M8 5v14l11-7z"/>',
        'square' => '<rect x="5" y="5" width="14" height="14" rx="2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18"/><path d="M12 3a15 15 0 0 0 0 18"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/>',
        'terminal' => '<path d="m4 17 6-6-6-6"/><path d="M12 19h8"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
        'dot' => '<circle cx="12" cy="12" r="1.5"/>',
        default => '<circle cx="12" cy="12" r="1.5"/>',
    };

    return '<svg class="ui-icon ui-icon-' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" xmlns="http://www.w3.org/2000/svg" width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svg . '</svg>';
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
    font-family:Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    background:radial-gradient(circle at top,#101b33 0,var(--bg) 44%,#070b14 100%);
    color:var(--text);
}

.container{
    max-width:1120px;
    margin:0 auto;
    padding:22px 18px 34px;
}

.services-header{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:18px;
}

.page-header{
    display:flex;
    flex-direction:column;
    gap:4px;
}

.services-title{
    margin:0 0 4px;
    font-size:40px;
    font-weight:800;
    line-height:1.08;
    letter-spacing:-0.02em;
}

.services-subtitle{
    margin:0;
    color:#b8d7f0;
    line-height:1.45;
    font-size:16px;
}

.top-actions{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    justify-content:flex-end;
    padding-top:2px;
}

.top-actions .action-chip{
    width:auto;
}

a{
    color:var(--accent);
    text-decoration:none;
}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:12px;
    align-items:start;
}

.metric-strip{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:10px;
    margin:0 0 12px;
}

.metric-card{
    display:flex;
    gap:12px;
    align-items:flex-start;
    min-height:64px;
    background:linear-gradient(180deg, rgba(15,23,42,.94), rgba(10,18,32,.95));
    border:1px solid rgba(56,189,248,.16);
    border-radius:14px;
    padding:13px 15px;
    box-shadow:0 12px 28px rgba(0,0,0,.18);
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
    font-size:21px;
    font-weight:800;
    margin-top:4px;
    color:var(--text);
}

.metric-icon{
    width:34px;
    height:34px;
    border-radius:10px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(56,189,248,.12);
    border:1px solid rgba(56,189,248,.18);
    color:#bfdbfe;
    flex:0 0 auto;
    font-size:14px;
}

.metric-icon .ui-icon,
.service-icon .ui-icon,
.chip-icon .ui-icon{
    display:block;
    width:100%;
    height:100%;
    stroke:currentColor;
    fill:none;
}

.metric-icon .ui-icon{
    width:17px;
    height:17px;
}

.metric-icon.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.18);color:#bbf7d0}
.metric-icon.bad{background:rgba(220,38,38,.12);border-color:rgba(220,38,38,.18);color:#fecaca}
.metric-icon.time{background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.18);color:#e9d5ff}

.metric-content{
    min-width:0;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

.card{
    background:linear-gradient(180deg, rgba(15,23,42,.92), rgba(11,18,32,.94));
    border:1px solid rgba(36,50,74,.9);
    padding:16px;
    border-radius:14px;
    margin-bottom:0;
    box-shadow:0 10px 28px rgba(0,0,0,.16);
}

.service-top{
    display:grid;
    grid-template-columns:auto 1fr auto;
    gap:12px;
    align-items:start;
}

.service-icon{
    width:42px;
    height:42px;
    border-radius:14px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(56,189,248,.12);
    border:1px solid rgba(56,189,248,.24);
    color:#dbeafe;
    flex:0 0 auto;
}

.service-icon .ui-icon{
    width:19px;
    height:19px;
    stroke-width:2.2;
}

.service-content{
    min-width:0;
}

.service-title{
    margin:0;
    font-size:18px;
    font-weight:800;
    color:#fff;
    line-height:1.1;
    letter-spacing:-0.01em;
}

.service-badge,
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

.service-status-row{
    margin-top:6px;
}

.service-description{
    margin:10px 0 0;
    color:#b8d7f0;
    font-size:13px;
    line-height:1.45;
    min-height:0;
}

.acoes,
.service-actions{
    display:grid;
    gap:7px;
    margin-top:12px;
}

.acoes form{
    margin:0;
}

.action-chip{
    display:inline-flex;
    align-items:center;
    justify-content:flex-start;
    gap:7px;
    width:100%;
    padding:8px 11px;
    border:1px solid rgba(56,189,248,.30);
    border-radius:11px;
    background:rgba(17,26,47,.92);
    color:#fff;
    cursor:pointer;
    font-weight:700;
    font-size:13px;
    line-height:1.2;
    min-height:32px;
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
    width:14px;
    height:14px;
    font-size:12px;
    line-height:1;
    flex:0 0 auto;
}

.alerta{
    background:rgba(124,45,18,.24);
    border:1px solid rgba(234,88,12,.35);
    color:#fed7aa;
    padding:11px 14px;
    line-height:1.45;
}

.warning-banner{
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px 16px;
    margin-bottom:12px;
    border-radius:14px;
    background:linear-gradient(90deg, rgba(127,29,29,.44), rgba(67,20,7,.40));
    border:1px solid rgba(249,115,22,.48);
    color:#fed7aa;
    box-shadow:0 10px 24px rgba(0,0,0,.14);
}

.alert-icon{
    width:36px;
    height:36px;
    border-radius:12px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    background:rgba(249,115,22,.15);
    border:1px solid rgba(249,115,22,.26);
    color:#fdba74;
    flex:0 0 auto;
}

.warning-banner strong{
    color:#ffedd5;
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
    position:relative;
    overflow:hidden;
    height:auto;
    min-height:0;
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
    flex:0 0 auto;
    position:relative;
    z-index:1;
}

.service-footer{
    margin-top:12px;
    position:relative;
    z-index:1;
}

.section-head{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    margin-bottom:10px;
}

.link-chip{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 11px;
    border:1px solid var(--border);
    border-radius:10px;
    color:#dbe7f5;
    background:rgba(15,23,42,.92);
    font-size:13px;
}

.status-cell{
    display:inline-flex;
    align-items:center;
    gap:6px;
    font-weight:700;
}

.status-mark{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:16px;
    height:16px;
    border-radius:50%;
    font-size:11px;
}

.status-cell.ok{
    color:#bbf7d0;
}

.status-cell.ok .status-mark{
    background:rgba(34,197,94,.18);
    color:#bbf7d0;
}

.status-cell.bad{
    color:#fecaca;
}

.status-cell.bad .status-mark{
    background:rgba(220,38,38,.16);
    color:#fecaca;
}

@media (max-width: 720px){
    .container{padding:16px 12px 28px}
    .services-header{display:block}
    .services-title{font-size:28px}
    .services-subtitle{font-size:14px}
    .card{padding:14px}
    .metric-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
    .top-actions{justify-content:flex-start;margin-top:12px;padding-top:0}
    .actions,.acoes{display:grid}
    .action-chip{width:100%;justify-content:center}
    .section-head{display:block}
    .section-head .link-chip{margin-top:8px}
    .warning-banner{align-items:flex-start}
}

@media (max-width: 520px){
    .metric-strip{grid-template-columns:1fr}
    .metric-card{min-height:60px}
}

.services-grid{
    grid-template-columns:repeat(4,minmax(0,1fr));
    align-items:start;
}

@media (max-width: 1100px){
    .services-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}

@media (max-width: 700px){
    .services-grid{
        grid-template-columns:1fr;
    }
}

.servico-bind .service-icon{
    background:rgba(59,130,246,.14);
    border-color:rgba(59,130,246,.26);
    color:#bfdbfe;
}

.servico-fail2ban .service-icon{
    background:rgba(34,197,94,.14);
    border-color:rgba(34,197,94,.26);
    color:#bbf7d0;
}

.servico-ssh .service-icon{
    background:rgba(234,179,8,.14);
    border-color:rgba(234,179,8,.26);
    color:#fde68a;
}

.servico-firewall .service-icon{
    background:rgba(168,85,247,.14);
    border-color:rgba(168,85,247,.26);
    color:#e9d5ff;
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

    <div class="topbar services-header">
        <div class="page-header">
            <h1 class="services-title">Serviços</h1>

            <p class="lead services-subtitle">
                Central de gerenciamento dos serviços do servidor DNS.
            </p>
        </div>

        <div class="top-actions">
            <a class="action-chip back-button" href="dashboard.php"><span class="chip-icon" aria-hidden="true"><?= ui_icon_svg('arrow-left', 14) ?></span>Voltar ao painel</a>
        </div>
    </div>

    <div class="card alerta warning-banner">
        <span class="alert-icon" aria-hidden="true"><?= ui_icon_svg('alert-triangle', 16) ?></span>
        <div><strong>Atenção:</strong>
        ações como reiniciar SSH ou parar BIND podem impactar o acesso ao servidor e a resolução DNS.</div>
    </div>

    <section class="metric-strip metrics-grid" aria-label="Resumo dos serviços">
        <div class="metric-card">
            <span class="metric-icon" aria-hidden="true"><?= service_metric_icon('monitorados') ?></span>
            <div class="metric-content">
                <span class="metric-label">Monitorados</span>
                <span class="metric-value"><?= (int) $servicosMonitorados ?></span>
            </div>
        </div>
        <div class="metric-card">
            <span class="metric-icon ok" aria-hidden="true"><?= service_metric_icon('ativos') ?></span>
            <div class="metric-content">
                <span class="metric-label">Ativos</span>
                <span class="metric-value"><?= (int) $servicosAtivos ?></span>
            </div>
        </div>
        <div class="metric-card">
            <span class="metric-icon bad" aria-hidden="true"><?= service_metric_icon('inativos') ?></span>
            <div class="metric-content">
                <span class="metric-label">Inativos</span>
                <span class="metric-value"><?= (int) $servicosInativos ?></span>
            </div>
        </div>
        <div class="metric-card">
            <span class="metric-icon time" aria-hidden="true"><?= service_metric_icon('ultima') ?></span>
            <div class="metric-content">
                <span class="metric-label">Última verificação</span>
                <span class="metric-value" style="font-size:15px"><?= htmlspecialchars($ultimaVerificacao) ?></span>
            </div>
        </div>
    </section>

    <div class="grid services-grid">

        <div class="card servico-bind service-card service-card-bind">
            <div class="service-body">
                <div class="service-top">
                    <span class="service-icon" aria-hidden="true"><?= service_service_icon('bind') ?></span>
                    <div class="service-content">
                        <h2 class="service-title">BIND9</h2>
                        <div class="service-status-row"><?= badge_status($statusBind) ?></div>
                    </div>
                    <span class="service-badge">DNS</span>
                </div>
                <p class="service-description">Verificação, reload e controle do serviço DNS.</p>
            </div>

            <div class="acoes service-actions service-footer">
                <?php botoes_servico($acoes, ['CHECK_BIND', 'RELOAD_BIND', 'RESTART_BIND', 'START_BIND', 'STOP_BIND']); ?>
            </div>
        </div>

        <div class="card servico-fail2ban service-card service-card-fail2ban">
            <div class="service-body">
                <div class="service-top">
                    <span class="service-icon" aria-hidden="true"><?= service_service_icon('fail2ban') ?></span>
                    <div class="service-content">
                        <h2 class="service-title">Fail2Ban</h2>
                        <div class="service-status-row"><?= badge_status($statusFail2ban) ?></div>
                    </div>
                    <span class="service-badge">Segurança</span>
                </div>
                <p class="service-description">Verificação e reinicialização do serviço de proteção.</p>
            </div>

            <div class="acoes service-actions service-footer">
                <?php botoes_servico($acoes, ['CHECK_FAIL2BAN', 'RESTART_FAIL2BAN']); ?>
            </div>
        </div>

        <div class="card servico-ssh service-card service-card-ssh">
            <div class="service-body">
                <div class="service-top">
                    <span class="service-icon" aria-hidden="true"><?= service_service_icon('ssh') ?></span>
                    <div class="service-content">
                        <h2 class="service-title">SSH</h2>
                        <div class="service-status-row"><?= badge_status($statusSsh) ?></div>
                    </div>
                    <span class="service-badge">Acesso remoto</span>
                </div>
                <p class="service-description">Verificação e reinicialização do serviço SSH.</p>
            </div>

            <div class="acoes service-actions service-footer">
                <?php botoes_servico($acoes, ['CHECK_SSH', 'RESTART_SSH']); ?>
            </div>
        </div>

        <div class="card servico-firewall service-card service-card-firewall">
            <div class="service-body">
                <div class="service-top">
                    <span class="service-icon" aria-hidden="true"><?= service_service_icon('firewall') ?></span>
                    <div class="service-content">
                        <h2 class="service-title">Firewall</h2>
                        <div class="service-status-row"><?= badge_status($statusFirewall) ?></div>
                    </div>
                    <span class="service-badge">Firewall</span>
                </div>
                <p class="service-description">Verificação e recarregamento das regras nftables.</p>
            </div>

            <div class="acoes service-actions service-footer">
                <?php botoes_servico($acoes, ['CHECK_FIREWALL', 'RELOAD_FIREWALL']); ?>
            </div>
        </div>

    </div>

    <div class="card">
        <div class="section-head">
            <h2 class="section-title">Últimas ações de serviços</h2>
            <?php if ($historicoLink): ?>
                <a class="link-chip" href="<?= htmlspecialchars($historicoLink) ?>">Ver histórico completo</a>
            <?php endif; ?>
        </div>

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
                                <td>
                                    <?php $statusLog = strtoupper((string) ($log['status'] ?? '-')); ?>
                                    <span class="status-cell <?= $statusLog === 'OK' ? 'ok' : ($statusLog === 'ERRO' ? 'bad' : '') ?>">
                                        <span class="status-mark" aria-hidden="true"><?= $statusLog === 'OK' ? '✓' : ($statusLog === 'ERRO' ? '!' : '•') ?></span>
                                        <?= htmlspecialchars($statusLog) ?>
                                    </span>
                                </td>
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
