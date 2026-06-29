<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/dns_zones.php';

$erro = '';
$sucesso = $_SESSION['dns_zones_sucesso'] ?? '';
$resultados = $_SESSION['dns_zones_resultados'] ?? null;
$resultadoSync = $_SESSION['dns_zones_sync_resultado'] ?? null;
unset($_SESSION['dns_zones_sucesso'], $_SESSION['dns_zones_resultados'], $_SESSION['dns_zones_sync_resultado']);

dns_zones_garantir_esquema();

function zones_redirect(string $mensagem, ?array $resultados = null, ?array $syncResultado = null): never
{
    $_SESSION['dns_zones_sucesso'] = $mensagem;
    if ($resultados !== null) {
        $_SESSION['dns_zones_resultados'] = $resultados;
    }
    if ($syncResultado !== null) {
        $_SESSION['dns_zones_sync_resultado'] = $syncResultado;
    }
    header('Location: zones.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = (string) ($_POST['acao'] ?? '');

    try {
        if ($acao === 'atualizar_inventario') {
            $resultados = dns_zones_atualizar_todos();
            $falhas = 0;
            if (empty($resultados['local']['ok'])) {
                $falhas++;
            }
            foreach ($resultados['remotos'] as $resultado) {
                if (empty($resultado['ok'])) {
                    $falhas++;
                }
            }

            registrar_auditoria([
                'acao' => 'DNS_ZONE_INVENTORY_REFRESH',
                'tipo_registro' => 'DNS_ZONE',
                'nome_registro' => 'inventario',
                'status' => $falhas === 0 ? 'OK' : 'ERRO',
                'mensagem' => $falhas === 0
                    ? 'Inventario de zonas atualizado.'
                    : 'Inventario de zonas atualizado com falhas em ' . $falhas . ' servidor(es).',
            ]);

            zones_redirect(
                $falhas === 0 ? 'Inventario atualizado.' : 'Inventario atualizado com falhas. Verifique os detalhes.',
                $resultados
            );
        }

        if ($acao === 'sync_zona_ausente') {
            $zona = (string) ($_POST['zona'] ?? '');
            $serverKey = (string) ($_POST['server_key'] ?? '');
            $masterIp = trim((string) ($_POST['master_ip'] ?? '')) ?: null;
            $resultado = dns_zones_sync_zona_ausente($zona, $serverKey, $masterIp);

            registrar_auditoria([
                'acao' => 'DNS_ZONE_SLAVE_SYNC_ONE',
                'dominio' => $resultado['zona'],
                'tipo_registro' => 'DNS_ZONE',
                'nome_registro' => $resultado['servidor'],
                'valor_antigo' => 'ausente',
                'valor_novo' => 'slave master ' . $resultado['master_ip'],
                'status' => $resultado['ok'] ? 'OK' : 'ERRO',
                'mensagem' => substr($resultado['saida'], 0, 1000),
            ]);

            zones_redirect(
                $resultado['ok'] ? 'Zona slave criada e inventario atualizado.' : 'Falha ao criar zona slave.',
                null,
                ['tipo' => 'single', 'resultados' => [$resultado]]
            );
        }

        if ($acao === 'sync_todas_ausentes') {
            $masterIp = trim((string) ($_POST['master_ip'] ?? '')) ?: null;
            $serverKey = trim((string) ($_POST['server_key'] ?? '')) ?: null;
            $syncResultados = dns_zones_sync_todas_ausentes($masterIp, $serverKey);
            $falhasSync = array_values(array_filter($syncResultados, static fn(array $r): bool => empty($r['ok'])));

            foreach ($syncResultados as $resultado) {
                registrar_auditoria([
                    'acao' => 'DNS_ZONE_SLAVE_SYNC_MISSING',
                    'dominio' => $resultado['zona'],
                    'tipo_registro' => 'DNS_ZONE',
                    'nome_registro' => $resultado['servidor'],
                    'valor_antigo' => 'ausente',
                    'valor_novo' => 'slave master ' . $resultado['master_ip'],
                    'status' => $resultado['ok'] ? 'OK' : 'ERRO',
                    'mensagem' => substr($resultado['saida'], 0, 1000),
                ]);
            }

            zones_redirect(
                $falhasSync ? 'Sincronizacao interrompida com falha.' : 'Zonas ausentes sincronizadas e inventario atualizado.',
                null,
                ['tipo' => 'bulk', 'resultados' => $syncResultados]
            );
        }

        if ($acao === 'ignorar_extra') {
            $zona = (string) ($_POST['zona'] ?? '');
            $serverKey = (string) ($_POST['server_key'] ?? '');
            $nota = (string) ($_POST['nota'] ?? '');
            $linha = dns_zones_marcar_extra_ignorada($zona, $serverKey, $nota, $_SESSION['usuario'] ?? null);

            registrar_auditoria([
                'acao' => 'DNS_ZONE_EXTRA_IGNORE',
                'dominio' => $linha['zone_name'],
                'tipo_registro' => 'DNS_ZONE',
                'nome_registro' => $linha['server_nome'],
                'valor_antigo' => 'EXTRA_NO_SLAVE',
                'valor_novo' => 'EXTRA_IGNORADA',
                'status' => 'OK',
                'mensagem' => substr($nota !== '' ? $nota : 'Zona extra marcada como legitima/ignorada.', 0, 1000),
            ]);

            zones_redirect('Zona extra marcada como legitima/ignorada.');
        }

        if ($acao === 'restaurar_extra') {
            $zona = (string) ($_POST['zona'] ?? '');
            $serverKey = (string) ($_POST['server_key'] ?? '');
            $linha = dns_zones_comparacao_por_zona_servidor($zona, $serverKey);
            dns_zones_remover_extra_ignorada($zona, $serverKey);

            registrar_auditoria([
                'acao' => 'DNS_ZONE_EXTRA_UNIGNORE',
                'dominio' => strtolower(rtrim(trim($zona), '.')),
                'tipo_registro' => 'DNS_ZONE',
                'nome_registro' => $linha['server_nome'] ?? $serverKey,
                'valor_antigo' => 'EXTRA_IGNORADA',
                'valor_novo' => 'EXTRA_NO_SLAVE',
                'status' => 'OK',
                'mensagem' => 'Zona extra voltou para a lista de alertas.',
            ]);

            zones_redirect('Zona extra restaurada para alertas.');
        }

        throw new RuntimeException('Acao invalida.');
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        registrar_auditoria([
            'acao' => 'DNS_ZONE_INVENTORY_REFRESH',
            'tipo_registro' => 'DNS_ZONE',
            'nome_registro' => 'inventario',
            'status' => 'ERRO',
            'mensagem' => substr($erro, 0, 1000),
        ]);
    }
}

$statusServidores = dns_zones_status_servidores();
$comparacao = dns_zones_comparar();
$inventario = dns_zones_inventario();
$divergencias = array_values(array_filter(
    $comparacao,
    static fn(array $linha): bool => !in_array($linha['estado'], ['ok', 'extra_on_slave_ignored'], true)
));
$zonasAusentes = array_values(array_filter($comparacao, static fn(array $linha): bool => $linha['estado'] === 'missing_on_slave'));
$resumoClassificacao = dns_zones_resumo_classificacao($comparacao);
$extrasPorServidor = dns_zones_extras_por_servidor($comparacao);
$extrasIgnoradasPorServidor = dns_zones_extras_por_servidor($comparacao, true);
$falhasColeta = dns_zones_status_falha_coleta();
$eventosAuditoriaDns = dns_zones_eventos_auditoria(25);
try {
    $masterIpPadrao = dns_zones_master_ip_padrao();
} catch (Throwable $e) {
    $masterIpPadrao = '';
}


function zones_data_sao_paulo(?string $utc): string
{
    if ($utc === null || trim($utc) === '') {
        return 'Indisponivel';
    }

    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return 'Indisponivel';
    }
}

function zone_estado_label(string $estado): string
{
    return match ($estado) {
        'ok' => 'OK',
        'missing_on_slave' => 'AUSENTE_NO_SLAVE',
        'serial_mismatch' => 'SERIAL_DIFERENTE',
        'extra_on_slave' => 'EXTRA_NO_SLAVE',
        'extra_on_slave_ignored' => 'EXTRA_IGNORADA',
        'master_serial_unknown' => 'SOA_MASTER_INDISPONIVEL',
        'slave_serial_unknown' => 'SOA_SLAVE_INDISPONIVEL',
        default => $estado,
    };
}

function zone_estado_class(string $estado): string
{
    return in_array($estado, ['ok', 'extra_on_slave_ignored'], true) ? 'ok' : 'warn';
}

function zone_estado_explicacao(string $estado): string
{
    return match ($estado) {
        'ok' => 'O serial SOA do slave esta igual ao master para esta zona.',
        'missing_on_slave' => 'A zona existe no NS1, mas nao aparece no inventario deste slave.',
        'serial_mismatch' => 'A zona existe nos dois lados, mas o serial SOA do slave difere do NS1.',
        'extra_on_slave' => 'A zona existe no slave e nao existe no NS1; pode ser legado ou zona legitima fora do master.',
        'extra_on_slave_ignored' => 'A zona extra foi marcada como legitima/ignorada e nao entra no total de divergencias acionaveis.',
        'master_serial_unknown' => 'O inventario encontrou a zona no NS1, mas nao conseguiu ler o serial SOA do master.',
        'slave_serial_unknown' => 'O inventario encontrou a zona no slave, mas nao conseguiu ler o serial SOA do slave.',
        default => 'Estado sem explicacao cadastrada.',
    };
}

function zone_estado_tone(string $estado): string
{
    return match ($estado) {
        'ok' => 'state-ok',
        'missing_on_slave' => 'state-missing',
        'serial_mismatch' => 'state-serial',
        'extra_on_slave' => 'state-extra',
        'extra_on_slave_ignored' => 'state-ignored',
        'master_serial_unknown', 'slave_serial_unknown' => 'state-soa',
        'collection_failed' => 'state-fail',
        default => 'state-neutral',
    };
}

$kpiOk = (int) ($resumoClassificacao['OK'] ?? 0);
$kpiAusentes = (int) ($resumoClassificacao['AUSENTE_NO_SLAVE'] ?? 0);
$kpiSerialDiferente = (int) ($resumoClassificacao['SERIAL_DIFERENTE'] ?? 0);
$kpiExtras = (int) ($resumoClassificacao['EXTRA_NO_SLAVE'] ?? 0);
$kpiFalhasColeta = (int) ($resumoClassificacao['FALHA_COLETA'] ?? 0);
$kpiSoaIndisponivel = (int) ($resumoClassificacao['SOA_MASTER_INDISPONIVEL'] ?? 0) + (int) ($resumoClassificacao['SOA_SLAVE_INDISPONIVEL'] ?? 0);

$ultimaColetaTs = null;
foreach ($statusServidores as $server) {
    $checkedAt = trim((string) ($server['checked_at'] ?? ''));
    if ($checkedAt === '') {
        continue;
    }

    try {
        $dt = new DateTimeImmutable($checkedAt, new DateTimeZone('UTC'));
        $ts = $dt->getTimestamp();
        if ($ultimaColetaTs === null || $ts > $ultimaColetaTs) {
            $ultimaColetaTs = $ts;
        }
    } catch (Throwable $e) {
        continue;
    }
}

$ultimaColetaTexto = $ultimaColetaTs !== null
    ? (new DateTimeImmutable('@' . $ultimaColetaTs))
        ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
        ->format('d/m/Y H:i:s')
    : 'agora';

$inventarioBrutoTotal = count($inventario);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventario DNS</title>
<style>
*{box-sizing:border-box}
:root{
    --bg:#07111f;
    --panel:#0f1c2c;
    --panel-2:#111d2d;
    --panel-3:#0a1624;
    --line:#22344f;
    --line-2:#2d4365;
    --text:#e5eefb;
    --muted:#9fb1c9;
    --subtle:#7f91a6;
    --blue:#2f81f7;
    --blue-soft:#2f81f72a;
    --green:#22c55e;
    --green-soft:#22c55e24;
    --amber:#f59e0b;
    --amber-soft:#f59e0b24;
    --purple:#8b5cf6;
    --purple-soft:#8b5cf624;
    --red:#ef4444;
    --red-soft:#ef444424;
}
body{
    margin:0;
    background:
        radial-gradient(circle at top left, rgba(47,129,247,.14), transparent 24%),
        radial-gradient(circle at top right, rgba(139,92,246,.10), transparent 22%),
        linear-gradient(180deg, #07111f 0%, #050b14 100%);
    color:var(--text);
    font-family:Arial,sans-serif;
}
a{color:#7cc7ff;text-decoration:none}
button,input,textarea,select{font:inherit}
.muted{color:var(--subtle)}
.zones-page{width:100%;max-width:1440px;margin:0 auto;padding:24px 22px 32px}
.zones-hero{
    display:flex;
    justify-content:space-between;
    gap:18px;
    align-items:flex-start;
    margin-bottom:16px;
    padding:18px 20px;
    border:1px solid var(--line);
    border-radius:18px;
    background:linear-gradient(180deg, rgba(15,28,44,.98), rgba(9,18,28,.98));
    box-shadow:0 22px 50px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.03);
}
.zones-hero-copy{min-width:0}
.zones-kicker{
    margin:0 0 5px;
    color:#9cc4ff;
    font-size:11px;
    font-weight:800;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.zones-hero h1{
    margin:0;
    color:#f4f9ff;
    font-size:31px;
    line-height:1.05;
}
.zones-lead{
    margin:7px 0 0;
    color:var(--muted);
    font-size:13px;
    line-height:1.45;
}
.zones-badges{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.zone-badge{
    display:inline-flex;
    align-items:center;
    gap:7px;
    min-height:32px;
    padding:7px 11px;
    border:1px solid var(--line-2);
    border-radius:999px;
    background:rgba(8,17,30,.78);
    color:#dbeafe;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
}
.zone-badge strong{color:#fff}
.zone-badge.ok{border-color:rgba(34,197,94,.28);background:rgba(8,30,16,.72);color:#c8f7d6}
.zone-badge.warn{border-color:rgba(245,158,11,.28);background:rgba(34,23,6,.74);color:#f9e4a1}
.zone-badge.info{border-color:rgba(47,129,247,.34);background:rgba(8,20,39,.78);color:#d9ecff}
.zone-badge.danger{border-color:rgba(239,68,68,.34);background:rgba(42,10,15,.78);color:#ffb0b0}
.zones-hero-actions{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:10px;
    flex:0 0 auto;
}
.zones-action-row{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
.zones-button,
.button,
.small-button,
.muted-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:36px;
    padding:8px 12px;
    border:1px solid var(--line-2);
    border-radius:10px;
    background:#172236;
    color:#e5edf7;
    cursor:pointer;
    transition:border-color .16s ease, background .16s ease, transform .16s ease, box-shadow .16s ease;
}
.zones-button:hover,
.zones-button:focus,
.button:hover,
.button:focus,
.small-button:hover,
.small-button:focus,
.muted-button:hover,
.muted-button:focus{
    outline:none;
    border-color:#4ea1ff;
    background:#102033;
    box-shadow:0 0 0 3px rgba(47,129,247,.12);
}
.zones-button.primary,
.button.primary{border-color:#2f81f7;background:linear-gradient(180deg,#2f81f7,#1d4ed8);color:#fff}
.zones-button.primary:hover,
.zones-button.primary:focus,
.button.primary:hover,
.button.primary:focus{background:linear-gradient(180deg,#4c93ff,#2f81f7)}
.zones-button.ghost,
.muted-button{background:#0f172a;color:#dbeafe}
.zones-button.ghost:disabled,
.zones-button:disabled,
.small-button:disabled,
.muted-button:disabled{opacity:.45;cursor:not-allowed;box-shadow:none}
.zones-message{
    padding:12px 14px;
    border-radius:12px;
    margin:0 0 14px;
    border:1px solid var(--line);
    background:rgba(15,28,44,.78);
    color:#dbeafe;
}
.zones-message.success{border-color:rgba(34,197,94,.28);background:rgba(8,30,16,.72);color:#c8f7d6}
.zones-message.error{border-color:rgba(239,68,68,.28);background:rgba(42,10,15,.72);color:#ffb0b0}
.zones-toast-wrap{
    position:fixed;
    top:18px;
    right:18px;
    z-index:9999;
    width:min(420px,calc(100vw - 28px));
    pointer-events:none;
}
.zones-toast{
    pointer-events:auto;
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    padding:12px 13px;
    border-radius:14px;
    border:1px solid rgba(47,129,247,.24);
    background:linear-gradient(180deg, rgba(15,28,44,.98), rgba(8,17,30,.98));
    box-shadow:0 18px 50px rgba(0,0,0,.35);
}
.zones-toast.success{border-color:rgba(34,197,94,.28)}
.zones-toast.error{
    border-color:rgba(245,158,11,.30);
    background:linear-gradient(180deg, rgba(34,23,6,.98), rgba(8,17,30,.98));
}
.zones-toast.error strong{color:#fde68a}
.zones-toast.error p{color:#f3d08a}
.zones-toast strong{
    display:block;
    margin-bottom:3px;
    color:#f4fbff;
    font-size:13px;
    font-weight:900;
}
.zones-toast p{
    margin:0;
    color:var(--muted);
    font-size:12px;
    line-height:1.4;
}
.zones-toast-close{
    width:28px;
    height:28px;
    border:1px solid rgba(148,163,184,.22);
    border-radius:9px;
    background:#0f172a;
    color:#dbeafe;
    cursor:pointer;
    flex:0 0 auto;
}
.zones-toast-close:hover,.zones-toast-close:focus{outline:none;border-color:#4ea1ff;background:#102033}
.zones-sync{
    margin:0 0 16px;
    padding:16px 18px;
    border:1px solid rgba(245,158,11,.24);
    border-radius:16px;
    background:linear-gradient(180deg, rgba(34,23,6,.82), rgba(12,18,27,.92));
}
.zones-sync .zones-card-head{margin-bottom:12px}
.zones-sync .zones-card-title{color:#fdf2c5}
.zones-sync .zones-card-subtitle{color:#d3b87a}
.zones-sync-form{display:flex;align-items:end;gap:10px;flex-wrap:wrap}
.zones-sync-form label{display:block;margin-bottom:5px;color:#cbd5e1;font-size:12px;font-weight:700}
.zones-sync-form input{
    min-width:220px;
    min-height:40px;
    padding:10px 11px;
    border:1px solid var(--line-2);
    border-radius:10px;
    background:#08111e;
    color:#fff;
}
.zones-kpis{
    display:grid;
    grid-template-columns:repeat(5,minmax(0,1fr));
    gap:12px;
    margin:0 0 14px;
}
.zones-kpi{
    position:relative;
    min-height:114px;
    padding:15px 15px 14px;
    border:1px solid var(--line);
    border-radius:16px;
    background:linear-gradient(180deg, rgba(15,28,44,.96), rgba(10,18,28,.98));
    box-shadow:inset 0 1px 0 rgba(255,255,255,.03);
}
.zones-kpi-title{
    display:block;
    color:#a8bfd8;
    font-size:11px;
    font-weight:800;
    letter-spacing:.06em;
    text-transform:uppercase;
}
.zones-kpi-value{
    display:block;
    margin-top:6px;
    color:#f4fbff;
    font-size:30px;
    font-weight:900;
    line-height:1.05;
}
.zones-kpi-desc{
    display:block;
    margin-top:6px;
    color:var(--subtle);
    font-size:12px;
    font-weight:500;
    line-height:1.35;
}
.zones-kpi.ok{box-shadow:0 0 0 1px rgba(34,197,94,.08) inset}
.zones-kpi.ok .zones-kpi-value{color:#8ef0b5}
.zones-kpi.missing .zones-kpi-value{color:#f8d66b}
.zones-kpi.serial .zones-kpi-value{color:#c4b5fd}
.zones-kpi.extra .zones-kpi-value{color:#c084fc}
.zones-kpi.fail .zones-kpi-value{color:#ff9494}
.zones-soa-note{
    margin:0 0 14px;
    padding:11px 13px;
    border:1px solid rgba(47,129,247,.22);
    border-radius:12px;
    background:rgba(8,20,39,.72);
    color:#cfe3ff;
    font-size:12px;
    line-height:1.45;
}
.zones-warning-note{
    margin:0 0 14px;
    padding:12px 13px;
    border:1px solid rgba(245,158,11,.28);
    border-radius:12px;
    background:rgba(34,23,6,.78);
    color:#fde68a;
    font-size:12px;
    line-height:1.45;
}
.zones-warning-note strong{color:#fff1b8}
.zones-card{
    padding:16px;
    border:1px solid var(--line);
    border-radius:18px;
    background:linear-gradient(180deg, rgba(15,28,44,.96), rgba(9,18,28,.98));
    box-shadow:0 18px 40px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.03);
}
.zones-card + .zones-card{margin-top:14px}
.zones-card-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:14px;
    margin-bottom:14px;
}
.zones-card-title{
    margin:0;
    color:#f4fbff;
    font-size:18px;
    font-weight:900;
    line-height:1.15;
}
.zones-card-subtitle{
    margin:5px 0 0;
    color:var(--muted);
    font-size:12px;
    line-height:1.4;
}
.zones-highlight{
    border-color:rgba(139,92,246,.24);
    background:
        radial-gradient(circle at 15% 0%, rgba(139,92,246,.12), transparent 24%),
        linear-gradient(180deg, rgba(15,28,44,.98), rgba(8,16,26,.98));
    margin-bottom:14px;
}
.zones-highlight-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
}
.zones-extra-group{
    padding:14px;
    border:1px solid rgba(139,92,246,.18);
    border-radius:14px;
    background:rgba(7,14,24,.72);
}
.zones-extra-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
}
.zones-extra-name{
    color:#f6f3ff;
    font-size:14px;
    font-weight:900;
}
.zones-extra-count{
    display:inline-flex;
    align-items:center;
    gap:6px;
    padding:5px 9px;
    border-radius:999px;
    border:1px solid rgba(139,92,246,.24);
    background:rgba(139,92,246,.12);
    color:#e9ddff;
    font-size:11px;
    font-weight:800;
    white-space:nowrap;
}
.zones-extra-list{display:grid;gap:10px}
.zones-extra-item{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    padding:10px 11px;
    border:1px solid rgba(148,163,184,.12);
    border-radius:12px;
    background:rgba(2,10,18,.55);
}
.zones-extra-main{min-width:0}
.zones-extra-zone{display:block;color:#f4fbff;font-size:13px;font-weight:800}
.zones-extra-meta{display:block;margin-top:4px;color:var(--subtle);font-size:11px;line-height:1.35}
.zones-extra-actions{display:flex;align-items:center;gap:8px;flex:0 0 auto;flex-wrap:wrap;justify-content:flex-end}
.zones-mini-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:30px;
    padding:6px 10px;
    border:1px solid var(--line-2);
    border-radius:9px;
    background:#0f172a;
    color:#dbeafe;
    font-size:11px;
    font-weight:700;
    cursor:pointer;
}
.zones-mini-button:hover,
.zones-mini-button:focus{outline:none;border-color:#4ea1ff;background:#102033}
.zones-mini-button.ghost{color:#97aac4}
.zones-mini-button:disabled{opacity:.45;cursor:not-allowed}
.zones-layout{
    display:grid;
    grid-template-columns:minmax(300px,.86fr) minmax(0,1.4fr);
    gap:14px;
    align-items:start;
    margin-bottom:14px;
}
.zones-filters .zones-card-head{margin-bottom:12px}
.zones-filter-search{
    width:100%;
    min-height:42px;
    padding:10px 12px;
    border:1px solid var(--line-2);
    border-radius:12px;
    background:#08111e;
    color:#fff;
    margin:0 0 12px;
}
.zones-filter-search::placeholder{color:#6b7d95}
.zones-filter-list{display:flex;flex-wrap:wrap;gap:8px}
.zones-filter-chip{
    border:1px solid var(--line-2);
    background:#0f172a;
    color:#dbeafe;
    border-radius:999px;
    min-height:32px;
    padding:7px 11px;
    cursor:pointer;
    font-size:12px;
    font-weight:700;
}
.zones-filter-chip.is-active{
    border-color:#4ea1ff;
    background:rgba(47,129,247,.18);
    color:#f4fbff;
    box-shadow:0 0 0 3px rgba(47,129,247,.10);
}
.zones-filter-note{margin-top:12px;color:var(--subtle);font-size:11px;line-height:1.45}
.zones-table-wrap{
    border:1px solid rgba(148,163,184,.14);
    border-radius:14px;
    overflow:auto;
}
table{
    width:100%;
    border-collapse:collapse;
    min-width:980px;
}
th,td{
    padding:11px 12px;
    border-bottom:1px solid rgba(148,163,184,.12);
    text-align:left;
    font-size:12px;
    vertical-align:top;
}
th{
    background:#08111e;
    color:#a8bfd8;
    font-size:10px;
    letter-spacing:.05em;
    text-transform:uppercase;
    white-space:nowrap;
}
tr:nth-child(odd) td{background:rgba(2,10,18,.28)}
tr:hover td{background:rgba(47,129,247,.06)}
.zones-row-hidden{display:none}
.zones-status{
    display:inline-flex;
    align-items:center;
    gap:6px;
    min-height:26px;
    padding:5px 9px;
    border-radius:999px;
    border:1px solid transparent;
    font-size:11px;
    font-weight:800;
    white-space:nowrap;
    transition:border-color .16s ease, background .16s ease, color .16s ease, box-shadow .16s ease, transform .16s ease;
}
.zones-status:hover{
    transform:translateY(-1px);
    box-shadow:0 0 0 3px rgba(47,129,247,.10);
}
.state-ok{border-color:rgba(34,197,94,.28);background:rgba(8,30,16,.72);color:#bbf7d0}
.state-ok:hover{border-color:rgba(34,197,94,.55);background:rgba(10,46,24,.92);color:#dcfce7;box-shadow:0 0 0 3px rgba(34,197,94,.12),0 0 18px rgba(34,197,94,.18)}
.state-missing{border-color:rgba(245,158,11,.30);background:rgba(34,23,6,.72);color:#fde68a}
.state-serial{border-color:rgba(139,92,246,.30);background:rgba(29,12,52,.72);color:#ddd6fe}
.state-extra{border-color:rgba(139,92,246,.30);background:rgba(30,10,48,.72);color:#e9d5ff}
.state-extra:hover{border-color:rgba(139,92,246,.60);background:rgba(46,16,74,.92);color:#f5e9ff;box-shadow:0 0 0 3px rgba(139,92,246,.12),0 0 18px rgba(139,92,246,.18)}
.state-soa{border-color:rgba(245,158,11,.30);background:rgba(34,23,6,.78);color:#fde68a}
.state-soa:hover{border-color:rgba(245,158,11,.55);background:rgba(53,33,8,.92);color:#fff3bf;box-shadow:0 0 0 3px rgba(245,158,11,.12),0 0 18px rgba(245,158,11,.18)}
.state-fail{border-color:rgba(239,68,68,.34);background:rgba(59,13,19,.78);color:#fecaca}
.state-ignored{border-color:rgba(96,165,250,.26);background:rgba(8,20,39,.72);color:#dbeafe}
.state-neutral{border-color:rgba(148,163,184,.24);background:rgba(15,23,42,.78);color:#dbeafe}
.action-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap;white-space:nowrap}
.inline-form{display:inline;margin:0}
.small-button{padding:7px 10px;font-size:12px}
.table-note{margin-top:12px;color:var(--subtle);font-size:11px;line-height:1.45}
.zones-audit .zones-card-head{margin-bottom:12px}
.zones-audit-table .zones-table-wrap{min-width:0}
.zones-empty{padding:16px 14px;color:var(--subtle);text-align:center}
.zones-raw details{border:1px solid rgba(148,163,184,.14);border-radius:14px;background:rgba(8,17,30,.65);overflow:hidden}
.zones-raw summary{
    list-style:none;
    padding:14px 16px;
    cursor:pointer;
    color:#f4fbff;
    font-weight:800;
}
.zones-raw summary::-webkit-details-marker{display:none}
.zones-raw-content{padding:0 16px 16px}
.result{
    white-space:pre-wrap;
    overflow-wrap:anywhere;
    background:#071226;
    border:1px solid rgba(148,163,184,.14);
    border-radius:12px;
    padding:12px;
    color:#cbd5e1;
    margin-top:10px;
    max-height:220px;
    overflow:auto;
}
.dialog{
    width:min(720px,calc(100vw - 28px));
    border:1px solid var(--line-2);
    border-radius:18px;
    background:
        radial-gradient(circle at top left, rgba(47,129,247,.10), transparent 20%),
        linear-gradient(180deg, rgba(15,28,44,.99), rgba(7,14,22,.99));
    color:var(--text);
    padding:0;
    box-shadow:0 30px 90px rgba(0,0,0,.55);
}
.dialog::backdrop{background:rgba(2,8,18,.82);backdrop-filter:blur(4px)}
.dialog-shell{padding:20px}
.dialog-header{
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:flex-start;
    padding:20px 20px 16px;
    border-bottom:1px solid rgba(148,163,184,.14);
}
.dialog-title{margin:0;color:#f4fbff;font-size:20px;font-weight:900}
.dialog-subtitle{margin:5px 0 0;color:var(--muted);font-size:12px;line-height:1.45}
.dialog-close{
    width:36px;
    height:36px;
    border:1px solid rgba(148,163,184,.24);
    border-radius:10px;
    background:#0f172a;
    color:#dbeafe;
    cursor:pointer;
    font-size:20px;
    line-height:1;
}
.dialog-close:hover,.dialog-close:focus{outline:none;border-color:#4ea1ff;background:#102033}
.dialog-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:12px;
    padding:18px 20px 0;
}
.dialog-field{
    background:rgba(8,17,30,.78);
    border:1px solid rgba(148,163,184,.12);
    border-radius:12px;
    padding:11px 12px;
}
.dialog-field span{
    display:block;
    color:#8ea6c2;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.05em;
    margin-bottom:6px;
}
.dialog-field strong{display:block;color:#f4fbff;font-size:13px;word-break:break-word}
.dialog-body{padding:16px 20px 20px}
.dialog-body p{margin:0;color:#d7e6f8;line-height:1.5}
.dialog-body .meta{color:var(--muted)}
.dialog-body .dialog-note{margin-top:10px;color:#8ea6c2;font-size:12px}
.dialog-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px}
.dialog-form{display:grid;gap:12px}
.dialog textarea{
    width:100%;
    min-height:82px;
    resize:vertical;
    padding:10px;
    border:1px solid var(--line-2);
    border-radius:10px;
    background:#08111e;
    color:#fff;
}
.dialog .zones-mini-button{min-height:36px}
.dialog-foot{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    padding:0 20px 20px;
}
@media(max-width:1180px){
    .zones-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
    .zones-layout{grid-template-columns:1fr}
    .zones-highlight-grid{grid-template-columns:1fr}
}
@media(max-width:820px){
    .zones-page{padding:18px 14px 24px}
    .zones-hero{flex-direction:column}
    .zones-hero-actions{align-items:stretch;width:100%}
    .zones-action-row{justify-content:flex-start}
    .zones-kpis{grid-template-columns:1fr}
    .zones-card-head{display:block}
    .zones-table-wrap{overflow-x:auto}
    table{min-width:820px}
    .dialog-grid{grid-template-columns:1fr}
}
</style>
</head>
<body class="zones-dashboard">
<main class="zones-page">
<header class="zones-hero">
    <div class="zones-hero-copy">
        <p class="zones-kicker">Inventário DNS</p>
        <h1>Inventário DNS</h1>
        <p class="zones-lead">Comparação entre NS1, NS2 e NS03</p>
        <div class="zones-badges">
            <span class="zone-badge ok">Produção</span>
            <span class="zone-badge danger">Remoção automática: <strong>BLOQUEADA</strong></span>
            <span class="zone-badge info">Última coleta: <strong><?= htmlspecialchars($ultimaColetaTexto) ?></strong></span>
        </div>
    </div>
    <div class="zones-hero-actions">
        <div class="zones-action-row">
            <a class="zones-button ghost" href="auditoria.php">Auditoria completa</a>
            <form method="POST" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="atualizar_inventario">
                <button type="submit" class="zones-button primary">Atualizar inventário</button>
            </form>
        </div>
    </div>
</header>

<?php if ($erro || $sucesso): ?>
    <div class="zones-toast-wrap" id="zones-toast-wrap">
        <?php if ($erro): ?>
            <div class="zones-toast error" role="status" aria-live="polite">
                <div>
                    <strong>Falha</strong>
                    <p><?= htmlspecialchars($erro) ?></p>
                </div>
                <button type="button" class="zones-toast-close" aria-label="Fechar aviso">×</button>
            </div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="zones-toast success" role="status" aria-live="polite">
                <div>
                    <strong>Inventário atualizado</strong>
                    <p><?= htmlspecialchars($sucesso) ?></p>
                </div>
                <button type="button" class="zones-toast-close" aria-label="Fechar aviso">×</button>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if ($resultadoSync): ?>
    <section class="zones-card">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Resultado da sincronização</h2>
                <p class="zones-card-subtitle">Execução recente dos comandos de criação de zonas ausentes.</p>
            </div>
        </div>
        <?php foreach (($resultadoSync['resultados'] ?? []) as $resultado): ?>
            <p class="zones-card-subtitle"><?= htmlspecialchars((string) $resultado['servidor']) ?> · <?= htmlspecialchars((string) $resultado['zona']) ?> · <?= !empty($resultado['ok']) ? 'OK' : 'Falha' ?> · <?= (int) ($resultado['duracao_ms'] ?? 0) ?> ms</p>
            <div class="result"><?= htmlspecialchars((string) ($resultado['saida'] ?? '')) ?></div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<?php if ($zonasAusentes): ?>
    <section class="zones-sync">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Sincronizar zonas ausentes</h2>
                <p class="zones-card-subtitle"><?= count($zonasAusentes) ?> zonas ausentes em slaves. Esta ação cria blocos slave e executa o reinventário. Remoção continua bloqueada.</p>
            </div>
        </div>
        <form method="POST" class="zones-sync-form" onsubmit="return confirm('Criar todas as zonas ausentes nos slaves?');">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="sync_todas_ausentes">
            <div>
                <label for="master-ip">IP master NS1</label>
                <input id="master-ip" name="master_ip" value="<?= htmlspecialchars($masterIpPadrao) ?>" placeholder="45.162.196.242" required>
            </div>
            <button type="submit" class="zones-button primary">Sincronizar todas ausentes</button>
        </form>
    </section>
<?php endif; ?>

<section class="zones-kpis">
    <article class="zones-kpi ok">
        <span class="zones-kpi-title">Zonas OK</span>
        <span class="zones-kpi-value"><?= (int) $kpiOk ?></span>
        <span class="zones-kpi-desc">Zonas sincronizadas entre master e slaves.</span>
    </article>
    <article class="zones-kpi missing">
        <span class="zones-kpi-title">Ausentes</span>
        <span class="zones-kpi-value"><?= (int) $kpiAusentes ?></span>
        <span class="zones-kpi-desc">Presentes no NS1, ausentes no slave.</span>
    </article>
    <article class="zones-kpi serial">
        <span class="zones-kpi-title">Serial diferente</span>
        <span class="zones-kpi-value"><?= (int) $kpiSerialDiferente ?></span>
        <span class="zones-kpi-desc">Domínios com serial SOA divergente.</span>
    </article>
    <article class="zones-kpi extra">
        <span class="zones-kpi-title">Zonas extras</span>
        <span class="zones-kpi-value"><?= (int) $kpiExtras ?></span>
        <span class="zones-kpi-desc">Encontradas em slaves e fora do master.</span>
    </article>
    <article class="zones-kpi fail">
        <span class="zones-kpi-title">Falhas de coleta</span>
        <span class="zones-kpi-value"><?= (int) $kpiFalhasColeta ?></span>
        <span class="zones-kpi-desc">Servidores com coleta indisponível ou erro.</span>
    </article>
</section>

<?php if ($kpiSoaIndisponivel > 0): ?>
    <div class="zones-soa-note">
        SOA indisponível: <strong><?= (int) $kpiSoaIndisponivel ?></strong>
        <span class="muted">(
            master <?= (int) ($resumoClassificacao['SOA_MASTER_INDISPONIVEL'] ?? 0) ?> /
            slave <?= (int) ($resumoClassificacao['SOA_SLAVE_INDISPONIVEL'] ?? 0) ?>
        )</span>
    </div>
<?php endif; ?>
<?php if ($kpiAusentes > 0 || $kpiSerialDiferente > 0 || $kpiFalhasColeta > 0): ?>
    <div class="zones-warning-note">
        Atenção no relatório: há <strong><?= (int) $kpiAusentes ?></strong> zona(s) ausente(s),
        <strong><?= (int) $kpiSerialDiferente ?></strong> com serial diferente e
        <strong><?= (int) $kpiFalhasColeta ?></strong> falha(s) de coleta. Esses itens ficam em amarelo/atenção para facilitar a leitura.
    </div>
<?php endif; ?>

<?php if ($extrasPorServidor): ?>
    <section class="zones-card zones-highlight">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Zonas extras detectadas nos slaves</h2>
                <p class="zones-card-subtitle">As zonas listadas existem em um slave, mas não foram encontradas no master. Podem ser legítimas, legadas ou resíduos antigos. Nenhuma remoção automática será realizada.</p>
            </div>
            <span class="zone-badge warn">Remoção bloqueada</span>
        </div>
        <div class="zones-highlight-grid">
            <?php foreach ($extrasPorServidor as $grupo): ?>
                <article class="zones-extra-group">
                    <div class="zones-extra-head">
                        <div class="zones-extra-name"><?= htmlspecialchars($grupo['server_nome']) ?></div>
                        <div class="zones-extra-count"><?= count($grupo['zonas']) ?> zonas</div>
                    </div>
                    <div class="zones-extra-list">
                        <?php foreach ($grupo['zonas'] as $extra): ?>
                            <div class="zones-extra-item">
                                <div class="zones-extra-main">
                                    <span class="zones-extra-zone"><?= htmlspecialchars($extra['zone_name']) ?></span>
                                    <span class="zones-extra-meta">Servidor: <?= htmlspecialchars($grupo['server_nome']) ?> · SOA <?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?></span>
                                </div>
                                <div class="zones-extra-actions">
                                    <button
                                        type="button"
                                        class="zones-mini-button inspect-zone"
                                        data-zone="<?= htmlspecialchars($extra['zone_name']) ?>"
                                        data-server="<?= htmlspecialchars($grupo['server_nome']) ?>"
                                        data-server-key="<?= htmlspecialchars($grupo['server_key']) ?>"
                                        data-state="<?= htmlspecialchars('extra_on_slave') ?>"
                                        data-state-label="<?= htmlspecialchars(zone_estado_label('extra_on_slave')) ?>"
                                        data-detail="<?= htmlspecialchars((string) ($extra['detalhe'] ?? 'Zona extra detectada')) ?>"
                                        data-explanation="<?= htmlspecialchars(zone_estado_explicacao('extra_on_slave')) ?>"
                                        data-master="<?= htmlspecialchars((string) ($extra['master_serial'] ?? 'indisponivel')) ?>"
                                        data-slave="<?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?>"
                                        data-note="<?= htmlspecialchars((string) ($extra['ignore_note'] ?? '')) ?>">
                                        Inspecionar
                                    </button>
                                    <button type="button" class="zones-mini-button ghost" disabled>Marcar legítima</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($extrasIgnoradasPorServidor): ?>
    <section class="zones-card">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Extras ignoradas</h2>
                <p class="zones-card-subtitle">Itens separados dos alertas acionáveis, mantidos apenas para referência visual e auditoria.</p>
            </div>
        </div>
        <div class="zones-highlight-grid">
            <?php foreach ($extrasIgnoradasPorServidor as $grupo): ?>
                <article class="zones-extra-group" style="border-color:rgba(96,165,250,.16);">
                    <div class="zones-extra-head">
                        <div class="zones-extra-name"><?= htmlspecialchars($grupo['server_nome']) ?></div>
                        <div class="zones-extra-count"><?= count($grupo['zonas']) ?> zonas</div>
                    </div>
                    <div class="zones-extra-list">
                        <?php foreach ($grupo['zonas'] as $extra): ?>
                            <div class="zones-extra-item">
                                <div class="zones-extra-main">
                                    <span class="zones-extra-zone"><?= htmlspecialchars($extra['zone_name']) ?></span>
                                    <span class="zones-extra-meta">
                                        SOA <?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?>
                                        <?php if (!empty($extra['ignore_note'])): ?> · <?= htmlspecialchars((string) $extra['ignore_note']) ?><?php endif; ?>
                                    </span>
                                </div>
                                <div class="zones-extra-actions">
                                    <button
                                        type="button"
                                        class="zones-mini-button inspect-zone"
                                        data-zone="<?= htmlspecialchars($extra['zone_name']) ?>"
                                        data-server="<?= htmlspecialchars($grupo['server_nome']) ?>"
                                        data-server-key="<?= htmlspecialchars($grupo['server_key']) ?>"
                                        data-state="<?= htmlspecialchars('extra_on_slave_ignored') ?>"
                                        data-state-label="<?= htmlspecialchars(zone_estado_label('extra_on_slave_ignored')) ?>"
                                        data-detail="<?= htmlspecialchars((string) ($extra['detalhe'] ?? 'Zona extra ignorada')) ?>"
                                        data-explanation="<?= htmlspecialchars(zone_estado_explicacao('extra_on_slave_ignored')) ?>"
                                        data-master="<?= htmlspecialchars((string) ($extra['master_serial'] ?? 'indisponivel')) ?>"
                                        data-slave="<?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?>"
                                        data-note="<?= htmlspecialchars((string) ($extra['ignore_note'] ?? '')) ?>">
                                        Inspecionar
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<section class="zones-layout">
    <aside class="zones-card zones-filters">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Filtros</h2>
                <p class="zones-card-subtitle">Busca client-side por zona, servidor e estado.</p>
            </div>
        </div>
        <input class="zones-filter-search" id="filtro-comparacao" type="text" placeholder="Pesquisar zona...">
        <div class="zones-filter-list" id="zones-filter-list">
            <button type="button" class="zones-filter-chip is-active" data-zone-filter="all">Todos</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="ok">OK</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="missing_on_slave">Ausentes</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="serial_mismatch">Serial diferente</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="extras">Extras</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="soa">SOA indisponível</button>
            <button type="button" class="zones-filter-chip" data-zone-filter="collection_failed">Falha de coleta</button>
        </div>
        <p class="zones-filter-note">A tabela ao lado responde ao texto e ao status sem alterar nenhuma operação do DNS.</p>
    </aside>

    <section class="zones-card zones-map">
        <div class="zones-card-head">
            <div>
                <h2 class="zones-card-title">Mapa de zonas</h2>
                <p class="zones-card-subtitle">Comparação entre master e slaves com o estado operacional de cada linha.</p>
            </div>
            <span class="zone-badge info"><?= count($comparacao) ?> verificações</span>
        </div>
        <div class="zones-table-wrap">
            <table id="tabela-comparacao">
                <thead>
                    <tr>
                        <th>Zona</th>
                        <th>Servidor</th>
                        <th>Serial NS1</th>
                        <th>Serial slave</th>
                        <th>Status</th>
                        <th>Ação</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$comparacao): ?>
                        <tr>
                            <td colspan="6" class="zones-empty">Sem dados de comparação. Atualize o inventário.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($comparacao as $linha): ?>
                        <?php
                            $estado = (string) $linha['estado'];
                            $statusLabel = zone_estado_label($estado);
                            $statusTone = zone_estado_tone($estado);
                            $rowSearch = strtolower(trim(
                                $linha['zone_name'] . ' ' .
                                $linha['server_nome'] . ' ' .
                                $statusLabel . ' ' .
                                $linha['detalhe'] . ' ' .
                                (string) ($linha['master_serial'] ?? '') . ' ' .
                                (string) ($linha['slave_serial'] ?? '')
                            ));
                        ?>
                        <tr data-zone-status="<?= htmlspecialchars($estado) ?>" data-zone-search="<?= htmlspecialchars($rowSearch) ?>">
                            <td><?= htmlspecialchars($linha['zone_name']) ?></td>
                            <td><?= htmlspecialchars($linha['server_nome']) ?></td>
                            <td><?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?></td>
                            <td><?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?></td>
                            <td><span class="zones-status <?= htmlspecialchars($statusTone) ?>"><?= htmlspecialchars($statusLabel) ?></span></td>
                            <td>
                                <div class="action-row">
                                    <button
                                        type="button"
                                        class="small-button muted-button inspect-zone"
                                        data-zone="<?= htmlspecialchars($linha['zone_name']) ?>"
                                        data-server="<?= htmlspecialchars($linha['server_nome']) ?>"
                                        data-server-key="<?= htmlspecialchars($linha['server_key']) ?>"
                                        data-state="<?= htmlspecialchars($estado) ?>"
                                        data-state-label="<?= htmlspecialchars($statusLabel) ?>"
                                        data-detail="<?= htmlspecialchars($linha['detalhe']) ?>"
                                        data-explanation="<?= htmlspecialchars(zone_estado_explicacao($estado)) ?>"
                                        data-master="<?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?>"
                                        data-slave="<?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?>"
                                        data-note="<?= htmlspecialchars((string) ($linha['ignore_note'] ?? '')) ?>">
                                        Detalhes
                                    </button>
                                    <?php if ($estado === 'missing_on_slave'): ?>
                                        <form method="POST" class="inline-form" onsubmit="return confirm('Criar zona slave <?= htmlspecialchars($linha['zone_name']) ?> em <?= htmlspecialchars($linha['server_nome']) ?>?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="acao" value="sync_zona_ausente">
                                            <input type="hidden" name="zona" value="<?= htmlspecialchars($linha['zone_name']) ?>">
                                            <input type="hidden" name="server_key" value="<?= htmlspecialchars($linha['server_key']) ?>">
                                            <input type="hidden" name="master_ip" value="<?= htmlspecialchars($masterIpPadrao) ?>">
                                            <button type="submit" class="small-button">Sincronizar</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="table-note">Ação de sincronização permanece somente para zonas ausentes. Nenhum botão de remoção foi adicionado.</p>
    </section>
</section>

<section class="zones-card zones-audit">
    <div class="zones-card-head">
        <div>
            <h2 class="zones-card-title">Auditoria DNS recente</h2>
            <p class="zones-card-subtitle">Eventos recentes gerados pelo inventário e pelas operações de comparação.</p>
        </div>
        <a class="zones-button ghost" href="auditoria.php">Ver todas</a>
    </div>
    <div class="zones-table-wrap zones-audit-table">
        <table>
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>Usuário</th>
                    <th>Evento</th>
                    <th>Detalhes/Resultado</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$eventosAuditoriaDns): ?>
                    <tr>
                        <td colspan="4" class="zones-empty">Nenhum evento DNS auditado.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($eventosAuditoriaDns as $evento): ?>
                    <tr>
                        <td><?= htmlspecialchars(zones_data_sao_paulo($evento['criado_em'] ?? null)) ?></td>
                        <td><?= htmlspecialchars((string) ($evento['usuario'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) $evento['acao']) ?></td>
                        <td><?= htmlspecialchars(trim((string) ($evento['mensagem'] ?? '-')) !== '' ? (string) $evento['mensagem'] : '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="zones-card zones-raw">
    <details open>
        <summary>Inventário bruto</summary>
        <div class="zones-raw-content">
            <p class="zones-card-subtitle"><?= (int) $inventarioBrutoTotal ?> entradas reais carregadas do inventário atual.</p>
            <input class="zones-filter-search" id="filtro-inventario" type="text" placeholder="Pesquisar zona, servidor, arquivo ou master...">
            <div class="zones-table-wrap">
                <table id="tabela-inventario">
                    <thead>
                        <tr>
                            <th>Servidor</th>
                            <th>Papel</th>
                            <th>Zona</th>
                            <th>Tipo</th>
                            <th>Serial SOA</th>
                            <th>Arquivo</th>
                            <th>Masters</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$inventario): ?>
                            <tr>
                                <td colspan="8" class="zones-empty">Inventário vazio.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($inventario as $zona): ?>
                            <tr>
                                <td><?= htmlspecialchars($zona['server_nome']) ?></td>
                                <td><?= htmlspecialchars(strtoupper($zona['server_role'])) ?></td>
                                <td><?= htmlspecialchars($zona['zone_name']) ?></td>
                                <td><?= htmlspecialchars($zona['zone_type']) ?></td>
                                <td><?= htmlspecialchars((string) ($zona['serial'] ?? 'indisponivel')) ?></td>
                                <td><?= htmlspecialchars((string) ($zona['file_path'] ?? '-')) ?></td>
                                <td><?= htmlspecialchars((string) ($zona['masters'] ?? '-')) ?></td>
                                <td><span class="zones-status <?= $zona['status'] === 'ok' ? 'state-ok' : ($zona['status'] === 'sem_soa' ? 'state-soa' : 'state-neutral') ?>"><?= htmlspecialchars($zona['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </details>
</section>

<dialog class="dialog" id="zone-dialog">
    <div class="dialog-header">
        <div>
            <h2 class="dialog-title" id="dialog-title">Inspeção da zona</h2>
            <p class="dialog-subtitle">Detalhes operacionais da comparação sem executar nenhuma mudança.</p>
        </div>
        <button type="button" class="dialog-close" id="dialog-close" aria-label="Fechar">×</button>
    </div>
    <div class="dialog-grid">
        <div class="dialog-field"><span>Zona</span><strong id="dialog-zone"></strong></div>
        <div class="dialog-field"><span>Servidor</span><strong id="dialog-server"></strong></div>
        <div class="dialog-field"><span>Serial NS1</span><strong id="dialog-master"></strong></div>
        <div class="dialog-field"><span>Serial slave</span><strong id="dialog-slave"></strong></div>
        <div class="dialog-field"><span>Status</span><strong id="dialog-state"></strong></div>
        <div class="dialog-field"><span>Detalhe</span><strong id="dialog-detail"></strong></div>
    </div>
    <div class="dialog-body">
        <p id="dialog-explanation"></p>
        <p class="dialog-note" id="dialog-note-wrap">Nota: <span id="dialog-note"></span></p>
    </div>
    <div class="dialog-shell">
        <form method="POST" id="ignore-form" class="dialog-form">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="ignorar_extra">
            <input type="hidden" name="zona" id="ignore-zone">
            <input type="hidden" name="server_key" id="ignore-server-key">
            <label class="zones-card-subtitle" for="ignore-note">Nota opcional</label>
            <textarea name="nota" id="ignore-note" maxlength="500" placeholder="Ex.: zona legada mantida somente neste slave"></textarea>
            <div class="dialog-actions">
                <button type="submit" class="zones-button primary">Marcar como legítima</button>
            </div>
        </form>
        <form method="POST" id="restore-form" class="dialog-form" style="margin-top:12px;">
            <?= csrf_field() ?>
            <input type="hidden" name="acao" value="restaurar_extra">
            <input type="hidden" name="zona" id="restore-zone">
            <input type="hidden" name="server_key" id="restore-server-key">
            <div class="dialog-actions">
                <button type="submit" class="zones-button ghost">Restaurar alerta</button>
            </div>
        </form>
    </div>
</dialog>
</main>
<script>
function bindFilter(inputId, tableId){
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if(!input || !table) return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase().trim();
        table.querySelectorAll('tbody tr').forEach(row => {
            if (row.querySelector('.zones-empty')) return;
            const text = (row.dataset.zoneSearch || row.innerText).toLowerCase();
            row.style.display = text.includes(q) ? '' : 'none';
        });
    });
}

bindFilter('filtro-inventario','tabela-inventario');

const comparisonTable = document.getElementById('tabela-comparacao');
const comparisonSearch = document.getElementById('filtro-comparacao');
const filterButtons = document.querySelectorAll('[data-zone-filter]');
let activeFilter = 'all';

function matchesZoneFilter(status) {
    if (activeFilter === 'all') return true;
    if (activeFilter === 'extras') return status === 'extra_on_slave' || status === 'extra_on_slave_ignored';
    if (activeFilter === 'soa') return status === 'master_serial_unknown' || status === 'slave_serial_unknown';
    if (activeFilter === 'collection_failed') return status === 'collection_failed';
    return status === activeFilter;
}

function applyComparisonFilter() {
    if (!comparisonTable || !comparisonSearch) return;
    const q = comparisonSearch.value.toLowerCase().trim();
    comparisonTable.querySelectorAll('tbody tr').forEach(row => {
        if (row.querySelector('.zones-empty')) return;
        const status = row.dataset.zoneStatus || '';
        const text = (row.dataset.zoneSearch || row.innerText).toLowerCase();
        row.style.display = matchesZoneFilter(status) && text.includes(q) ? '' : 'none';
    });
}

if (comparisonSearch) {
    comparisonSearch.addEventListener('input', applyComparisonFilter);
}

filterButtons.forEach(button => {
    button.addEventListener('click', () => {
        activeFilter = button.dataset.zoneFilter || 'all';
        filterButtons.forEach(item => item.classList.toggle('is-active', item === button));
        applyComparisonFilter();
    });
});

applyComparisonFilter();

const zoneDialog = document.getElementById('zone-dialog');
const ignoreForm = document.getElementById('ignore-form');
const restoreForm = document.getElementById('restore-form');
const dialogClose = document.getElementById('dialog-close');

function setText(id, value){
    const el = document.getElementById(id);
    if(el) el.textContent = value || '-';
}

document.querySelectorAll('.inspect-zone').forEach(button => {
    button.addEventListener('click', () => {
        const d = button.dataset;
        setText('dialog-zone', d.zone);
        setText('dialog-server', d.server);
        setText('dialog-master', d.master);
        setText('dialog-slave', d.slave);
        setText('dialog-state', d.stateLabel);
        setText('dialog-detail', d.detail);
        setText('dialog-explanation', d.explanation);
        setText('dialog-note', d.note);
        const noteWrap = document.getElementById('dialog-note-wrap');
        if (noteWrap) {
            noteWrap.style.display = d.note ? '' : 'none';
        }
        document.getElementById('ignore-zone').value = d.zone || '';
        document.getElementById('ignore-server-key').value = d.serverKey || '';
        document.getElementById('ignore-note').value = d.note || '';
        document.getElementById('restore-zone').value = d.zone || '';
        document.getElementById('restore-server-key').value = d.serverKey || '';
        ignoreForm.style.display = d.state === 'extra_on_slave' ? '' : 'none';
        restoreForm.style.display = d.state === 'extra_on_slave_ignored' ? '' : 'none';
        if (typeof zoneDialog.showModal === 'function') {
            zoneDialog.showModal();
        }
    });
});

function closeDialog(){
    if (zoneDialog.open) {
        zoneDialog.close();
    }
}

if (dialogClose) {
    dialogClose.addEventListener('click', closeDialog);
}

zoneDialog?.addEventListener('click', event => {
    const rect = zoneDialog.getBoundingClientRect();
    const outside = event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom;
    if (outside) closeDialog();
});

document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && zoneDialog.open) {
        closeDialog();
    }
});

const toastWrap = document.getElementById('zones-toast-wrap');
document.querySelectorAll('.zones-toast-close').forEach(button => {
    button.addEventListener('click', () => {
        const toast = button.closest('.zones-toast');
        if (toast) {
            toast.remove();
        }
        if (toastWrap && toastWrap.querySelectorAll('.zones-toast').length === 0) {
            toastWrap.remove();
        }
    });
});

document.querySelectorAll('.zones-toast.success').forEach(toast => {
    setTimeout(() => {
        if (toast.isConnected) {
            toast.remove();
        }

        if (toastWrap && toastWrap.querySelectorAll('.zones-toast').length === 0) {
            toastWrap.remove();
        }
    }, 4000);
});
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
