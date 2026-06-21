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
            'RESTART_FAIL2BAN',
            'RESTART_SSH',
        ], true);
        $visualClass = match ($nome) {
            'START_BIND' => 'action-start',
            'RESTART_BIND' => 'action-restart-bind',
            'RESTART_SSH' => 'action-restart-ssh',
            default => '',
        };

        ?>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="<?= htmlspecialchars($nome) ?>">
            <button
                type="submit"
                class="action-chip <?= $danger ? 'danger' : '' ?> <?= $visualClass ?>"
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

function service_metric_icon(string $key): string
{
    return ui_icon_svg(match ($key) {
        'monitorados' => 'server',
        'ativos' => 'activity',
        'inativos' => 'circle-off',
        'ultima' => 'clock',
        default => 'dot',
    }, 20);
}

function service_action_icon(string $acao): string
{
    return ui_icon_svg(match ($acao) {
        'CHECK_BIND', 'CHECK_FAIL2BAN', 'CHECK_SSH', 'CHECK_FIREWALL' => 'search',
        'RELOAD_BIND', 'RELOAD_FIREWALL' => 'refresh',
        'RESTART_BIND', 'RESTART_FAIL2BAN', 'RESTART_SSH' => 'restart',
        'START_BIND' => 'play-box',
        'STOP_BIND' => 'stop',
        default => 'dot',
    }, 17);
}

function service_icon(string $servico): string
{
    return ui_icon_svg(match ($servico) {
        'bind' => 'globe',
        'fail2ban' => 'shield',
        'ssh' => 'terminal',
        'firewall' => 'flame',
        default => 'dot',
    }, 25);
}

function ui_icon_svg(string $name, int $size = 18): string
{
    $paths = match ($name) {
        'server' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5M8 19h.01"/>',
        'activity' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'circle-off' => '<circle cx="12" cy="12" r="9"/><path d="m4 4 16 16"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-4.2-4.2"/>',
        'refresh' => '<path d="M20 7v5h-5"/><path d="M4 17v-5h5"/><path d="M6.1 8a7 7 0 0 1 11.5-2L20 8M4 16l2.4 2a7 7 0 0 0 11.5-2"/>',
        'restart' => '<path d="M4 4v6h6"/><path d="M5.5 15a8 8 0 1 0 1.3-8.7L4 10"/>',
        'play-box' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="m10 8 6 4-6 4z"/>',
        'stop' => '<rect x="6" y="6" width="12" height="12" rx="2"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/>',
        'terminal' => '<path d="m5 7 5 5-5 5M12 17h7"/>',
        'flame' => '<path d="M12 22c4 0 7-3 7-7 0-3-1.5-5.5-4-8-.5 2-1.5 3.2-3 4-1-3-3-5-5-7 0 4-2 6-2 10 0 4.4 3 8 7 8Z"/>',
        'alert' => '<path d="M10.3 3.3 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
        'home' => '<path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10M9 20v-6h6v6"/>',
        'grid' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'chart' => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'history-log' => '<path d="M5 4h11a2 2 0 0 1 2 2v5"/><path d="M5 4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h7"/><path d="M7 8h7M7 12h5M7 16h3"/><circle cx="17" cy="17" r="4"/><path d="M17 15v2l1.5 1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3V2.8h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.3 2.3 0 1 1 3.5 2c-.8.5-1.3 1-1.3 2M12 17h.01"/>',
        default => '<circle cx="12" cy="12" r="1.5"/>',
    };

    return '<svg class="ui-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
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
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
    background:#050b16;
    color:var(--text);
}

.container{
    max-width:1440px;
    margin:0 auto;
    padding:24px 28px 34px;
    margin-left:88px;
}

.side-rail{
    position:fixed;
    inset:0 auto 0 0;
    width:88px;
    display:flex;
    flex-direction:column;
    align-items:center;
    gap:15px;
    padding:22px 14px;
    background:#07111f;
    border-right:1px solid #1b2b42;
    z-index:50;
}

.brand-mark,
.rail-link{
    width:46px;
    height:46px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    color:#8fa1b8;
}

.brand-mark{
    margin-bottom:30px;
    color:#22d3ee;
}

.rail-link{
    text-decoration:none;
    transition:.18s ease;
}

.rail-link:hover,
.rail-link:focus{
    color:#e5f3ff;
    background:#0d2035;
    outline:none;
}

.rail-link.active{
    color:#22d3ee;
    background:#0b2740;
    box-shadow:inset 0 0 0 1px rgba(34,211,238,.08);
}

.rail-spacer{
    flex:1;
}

.topbar{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:16px;
    margin-bottom:14px;
}

.page-header{
    display:flex;
    flex-direction:column;
    gap:2px;
}

.topo h1{
    margin:0 0 4px;
    font-size:34px;
    line-height:1.1;
    color:#f8fafc;
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
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:16px;
    align-items:stretch;
    margin-bottom:16px;
}

.metric-strip{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:16px;
    margin:18px 0 16px;
}

.metric-card{
    display:flex;
    gap:12px;
    align-items:flex-start;
    min-height:82px;
    background:#091426;
    border:1px solid var(--border);
    border-radius:10px;
    padding:16px 18px;
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
    font-size:22px;
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

.metric-icon.ok{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.18);color:#bbf7d0}
.metric-icon.bad{background:rgba(220,38,38,.12);border-color:rgba(220,38,38,.18);color:#fecaca}
.metric-icon.time{background:rgba(168,85,247,.12);border-color:rgba(168,85,247,.18);color:#e9d5ff}

.metric-content{
    min-width:0;
}

.card{
    background:#081323;
    border:1px solid rgba(36,50,74,.9);
    padding:16px;
    border-radius:10px;
    margin-bottom:0;
    box-shadow:0 10px 28px rgba(0,0,0,.16);
}

.card h2{
    margin:0;
    font-size:19px;
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

.service-heading{
    display:flex;
    align-items:center;
    gap:12px;
}

.service-icon{
    width:42px;
    height:42px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:10px;
    flex:0 0 auto;
    background:rgba(59,130,246,.10);
    color:#3b82f6;
}

.servico-fail2ban .service-icon{background:rgba(34,197,94,.10);color:#22c55e}
.servico-ssh .service-icon{background:rgba(234,179,8,.10);color:#eab308}
.servico-firewall .service-icon{background:rgba(168,85,247,.10);color:#a855f7}

.service-name-status{
    display:flex;
    flex-direction:column;
    gap:5px;
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
    display:grid;
    gap:7px;
    margin-top:auto;
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
    padding:7px 10px;
    border:1px solid rgba(56,189,248,.30);
    border-radius:10px;
    background:rgba(17,26,47,.92);
    color:#fff;
    cursor:pointer;
    font-weight:700;
    font-size:12px;
    line-height:1.2;
    min-height:38px;
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

.action-chip.action-start,
.action-chip.action-restart-bind{
    background:rgba(17,26,47,.92);
    border-color:#263851;
    color:#e5edf7;
}

.action-chip.action-start .chip-icon{
    color:#168cff;
}

.action-chip.action-restart-bind .chip-icon{
    color:#16c763;
}

.action-chip.action-start:hover,
.action-chip.action-start:focus{
    background:rgba(10,40,70,.88);
    border-color:#168cff;
}

.action-chip.action-restart-bind:hover,
.action-chip.action-restart-bind:focus{
    background:rgba(8,52,37,.55);
    border-color:#16c763;
}

.action-chip.action-restart-ssh{
    background:rgba(72,10,22,.36);
    border-color:rgba(239,68,68,.48);
    color:#fda4af;
}

.action-chip.action-restart-ssh .chip-icon{
    color:#ff334f;
}

.action-chip.action-restart-ssh:hover,
.action-chip.action-restart-ssh:focus{
    background:rgba(92,12,28,.52);
    border-color:#ff334f;
}

.chip-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:18px;
    height:18px;
    flex:0 0 18px;
    font-size:12px;
    line-height:1;
}

.alerta{
    display:flex;
    align-items:center;
    gap:12px;
    background:rgba(69,23,12,.45);
    border:1px solid rgba(234,88,12,.48);
    color:#fed7aa;
    padding:14px 16px;
    line-height:1.45;
}

.alert-icon{
    display:inline-flex;
    color:#fbbf24;
    flex:0 0 auto;
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
    min-height:345px;
    position:relative;
    overflow:hidden;
    height:auto;
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
    font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}

.tabela th,
.tabela td{
    padding:11px 14px;
    font-size:14px;
    line-height:1.35;
    vertical-align:middle;
    border:0;
    border-bottom:1px solid #1e293b;
}

.tabela th{
    background:#0b1728;
    color:#aebbd0;
    text-align:left;
    font-size:13px;
    font-weight:500;
}

.tabela td{
    background:#07111f;
    color:#d7dfeb;
    font-weight:400;
}

.tabela tr:hover td{
    background:#0b1829;
}

.tabela tbody tr:last-child td{
    border-bottom:0;
}

.table-wrap{
    overflow:auto;
    border:1px solid var(--border);
    border-radius:12px;
    margin-top:8px;
}

.section-title{
    margin:0;
    font-size:17px;
}

.section-title-wrap{
    display:flex;
    align-items:center;
    gap:10px;
}

.section-title-icon{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    color:#18c8e8;
    flex:0 0 auto;
}

.service-body{
    flex:0 0 auto;
    position:relative;
    z-index:1;
}

.service-footer{
    margin-top:auto;
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
    .side-rail{display:none}
    .container{padding:16px 12px 28px;margin-left:auto}
    .topbar{display:block}
    .topo h1{font-size:24px}
    .card{padding:14px}
    .metric-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
    .top-actions{justify-content:flex-start;margin-top:12px;padding-top:0}
    .actions,.acoes{display:grid}
    .action-chip{width:100%;justify-content:center}
    .section-head{display:block}
    .section-head .link-chip{margin-top:8px}
}

@media (max-width: 520px){
    .metric-strip{grid-template-columns:1fr}
}

@media (max-width: 1180px){
    .grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}

@media (max-width: 680px){
    .grid{grid-template-columns:1fr}
    .service-card{min-height:auto}
}
</style>
</head>
<body>

<nav class="side-rail" aria-label="Navegação principal">
    <div class="brand-mark" title="DNS Panel"><?= ui_icon_svg('shield', 34) ?></div>
    <a class="rail-link" href="dashboard.php" title="Dashboard" aria-label="Dashboard"><?= ui_icon_svg('home', 26) ?></a>
    <a class="rail-link active" href="services.php" title="Serviços" aria-label="Serviços" aria-current="page"><?= ui_icon_svg('grid', 26) ?></a>
    <a class="rail-link" href="auditoria.php" title="Auditoria" aria-label="Auditoria"><?= ui_icon_svg('chart', 26) ?></a>
    <a class="rail-link" href="security.php" title="Configurações de segurança" aria-label="Configurações de segurança"><?= ui_icon_svg('settings', 26) ?></a>
    <div class="rail-spacer"></div>
    <a class="rail-link" href="dashboard.php" title="Ajuda e navegação" aria-label="Ajuda e navegação"><?= ui_icon_svg('help', 26) ?></a>
</nav>

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
        <div class="page-header topo">
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
        <span class="alert-icon" aria-hidden="true"><?= ui_icon_svg('alert', 26) ?></span>
        <div><strong>Atenção:</strong>
        ações como reiniciar SSH ou parar BIND podem impactar o acesso ao servidor e a resolução DNS.</div>
    </div>

    <section class="metric-strip" aria-label="Resumo dos serviços">
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

    <div class="grid">

        <div class="card servico-bind service-card">
            <div class="service-body">
                <div class="card-head">
                    <div class="service-heading">
                        <span class="service-icon" aria-hidden="true"><?= service_icon('bind') ?></span>
                        <div class="service-name-status">
                            <h2>BIND9</h2>
                            <?= badge_status($statusBind) ?>
                        </div>
                    </div>
                    <span class="service-tag">DNS</span>
                </div>
                <small>Verificação, reload e controle do serviço DNS.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_BIND', 'RELOAD_BIND', 'RESTART_BIND', 'START_BIND', 'STOP_BIND']); ?>
            </div>
        </div>

        <div class="card servico-fail2ban service-card">
            <div class="service-body">
                <div class="card-head">
                    <div class="service-heading">
                        <span class="service-icon" aria-hidden="true"><?= service_icon('fail2ban') ?></span>
                        <div class="service-name-status">
                            <h2>Fail2Ban</h2>
                            <?= badge_status($statusFail2ban) ?>
                        </div>
                    </div>
                    <span class="service-tag">Segurança</span>
                </div>
                <small>Verificação e reinicialização do serviço de proteção.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_FAIL2BAN', 'RESTART_FAIL2BAN']); ?>
            </div>
        </div>

        <div class="card servico-ssh service-card">
            <div class="service-body">
                <div class="card-head">
                    <div class="service-heading">
                        <span class="service-icon" aria-hidden="true"><?= service_icon('ssh') ?></span>
                        <div class="service-name-status">
                            <h2>SSH</h2>
                            <?= badge_status($statusSsh) ?>
                        </div>
                    </div>
                    <span class="service-tag">Acesso remoto</span>
                </div>
                <small>Verificação e reinicialização do serviço SSH.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_SSH', 'RESTART_SSH']); ?>
            </div>
        </div>

        <div class="card servico-firewall service-card">
            <div class="service-body">
                <div class="card-head">
                    <div class="service-heading">
                        <span class="service-icon" aria-hidden="true"><?= service_icon('firewall') ?></span>
                        <div class="service-name-status">
                            <h2>Firewall</h2>
                            <?= badge_status($statusFirewall) ?>
                        </div>
                    </div>
                    <span class="service-tag">Firewall</span>
                </div>
                <small>Verificação e recarregamento das regras nftables.</small>
            </div>

            <div class="acoes service-footer">
                <?php botoes_servico($acoes, ['CHECK_FIREWALL', 'RELOAD_FIREWALL']); ?>
            </div>
        </div>

    </div>

    <div class="card">
        <div class="section-head">
            <div class="section-title-wrap">
                <span class="section-title-icon" aria-hidden="true"><?= ui_icon_svg('history-log', 27) ?></span>
                <h2 class="section-title">Últimas ações de serviços</h2>
            </div>
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
        'RESTART_BIND': 'Tem certeza que deseja reiniciar o BIND?\n\nA resolução DNS pode ficar indisponível durante a reinicialização.',
        'STOP_BIND': 'Tem certeza que deseja parar o BIND?\n\nIsso pode interromper a resolução DNS do servidor.',
        'RESTART_FAIL2BAN': 'Tem certeza que deseja reiniciar o Fail2Ban?\n\nA proteção contra tentativas de acesso ficará temporariamente indisponível.',
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
