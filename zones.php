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
                registrar_auditoria([
                    'acao' => 'DNS_SERVER_INVENTORY',
                    'tipo_registro' => 'DNS_SERVER',
                    'nome_registro' => $resultado['servidor'],
                    'valor_novo' => (int) ($resultado['zonas'] ?? 0) . ' zonas',
                    'status' => !empty($resultado['ok']) ? 'OK' : 'ERRO',
                    'mensagem' => !empty($resultado['ok'])
                        ? 'Inventário DNS concluído para o servidor.'
                        : substr((string) ($resultado['saida'] ?? 'Falha no inventário DNS.'), 0, 1000),
                ]);
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
$statusFiltro = trim((string) ($_GET['status'] ?? ''));
$statusFiltro = in_array($statusFiltro, ['ok', 'divergencias', 'extras'], true) ? $statusFiltro : '';
$statusFiltroLabels = [
    'ok' => 'zonas sincronizadas',
    'divergencias' => 'divergencias',
    'extras' => 'zonas extras',
];
$comparacaoExibida = array_values(array_filter(
    $comparacao,
    static function (array $linha) use ($statusFiltro): bool {
        if ($statusFiltro === 'ok') {
            return $linha['estado'] === 'ok';
        }
        if ($statusFiltro === 'divergencias') {
            return !in_array($linha['estado'], ['ok', 'extra_on_slave_ignored'], true);
        }
        if ($statusFiltro === 'extras') {
            return $linha['estado'] === 'extra_on_slave';
        }

        return true;
    }
));
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

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Inventario de Zonas DNS</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0}.container{max-width:1280px;margin:36px auto;padding:0 20px}a{color:#38bdf8;text-decoration:none}h1,h2{margin-top:0}.lead,.meta{color:#94a3b8}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.card{background:#020617;border:1px solid #1e293b;border-radius:8px;padding:20px;margin-bottom:18px}.stat{min-height:132px}.value{font-size:30px;font-weight:bold;color:#38bdf8;margin:10px 0}.badge{display:inline-block;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:bold;background:#1e293b}.ok{color:#4ade80}.warn{color:#facc15}.error{color:#f87171}.message{padding:12px;border-radius:8px;margin-bottom:18px}.message.error{background:#7f1d1d;color:#fecaca}.message.success{background:#14532d;color:#bbf7d0}button{width:auto;padding:10px 14px;border:1px solid #2563eb;border-radius:8px;background:#2563eb;color:#fff;font:inherit;cursor:pointer}.toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.notice{border-color:#854d0e;background:#1c1917}.table-wrap{overflow:auto;border:1px solid #1e293b;border-radius:8px}table{width:100%;border-collapse:collapse;min-width:900px}th,td{padding:11px 12px;border-bottom:1px solid #1e293b;text-align:left;font-size:13px;vertical-align:top}th{color:#94a3b8;text-transform:uppercase;font-size:11px;background:#071226}tr:last-child td{border-bottom:0}.result{white-space:pre-wrap;overflow-wrap:anywhere;background:#071226;border:1px solid #334155;border-radius:8px;padding:12px;color:#cbd5e1;margin-top:10px;max-height:220px;overflow:auto}.tabs{display:flex;gap:8px;margin-bottom:12px}.tab{border:1px solid #334155;background:#0f172a;color:#cbd5e1;border-radius:8px;padding:8px 10px}.search{width:100%;padding:10px;margin:0 0 12px;background:#0f172a;border:1px solid #334155;border-radius:8px;color:#fff}.muted{color:#64748b}.sync-form{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.sync-form label{display:block;color:#94a3b8;font-size:13px;margin-bottom:5px}.sync-form input,.dialog textarea{padding:10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff}.dialog textarea{width:100%;min-height:82px;resize:vertical}.inline-form{display:inline}.action-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.small-button{padding:7px 10px;font-size:12px}.muted-button{background:#334155;border-color:#475569}.class-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.class-item{background:#071226;border:1px solid #1e293b;border-radius:8px;padding:14px}.class-item strong{display:block;font-size:22px;color:#38bdf8;margin-top:6px}.extra-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.zone-list{margin:8px 0 0;padding-left:18px}.zone-list li{margin:5px 0}.blocked{border-color:#7f1d1d;background:#190b0b}.blocked .badge{background:#7f1d1d;color:#fecaca}.ignored{border-color:#166534;background:#06130b}.dialog{width:min(680px,calc(100vw - 28px));border:1px solid #334155;border-radius:8px;background:#020617;color:#e2e8f0;padding:20px}.dialog::backdrop{background:#020617cc}.dialog-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin:14px 0}.dialog-field{background:#071226;border:1px solid #1e293b;border-radius:8px;padding:10px}.dialog-field span{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;margin-bottom:5px}.dialog-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:14px}@media(max-width:900px){.class-grid,.extra-list,.dialog-grid{grid-template-columns:1fr}}@media(max-width:900px){.grid{grid-template-columns:1fr}.toolbar{align-items:flex-start}table{min-width:820px}}
</style>
</head>
<body>
<main class="container">
<p><a href="dashboard.php">← Voltar ao painel</a></p>
<div class="toolbar">
<div>
<h1>Inventario de Zonas DNS</h1>
<p class="lead">V3.5: inspecao por zona e extras legitimas/ignoradas. Remocao automatica continua bloqueada.</p>
</div>
<form method="POST">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="atualizar_inventario">
<button type="submit">Atualizar inventario</button>
</form>
</div>

<?php if ($zonasAusentes): ?>
<section class="card notice">
<h2>Sincronizar zonas ausentes</h2>
<p class="lead"><?= count($zonasAusentes) ?> zonas ausentes em slaves. Esta acao cria blocos slave e executa reinventario; remocao continua bloqueada.</p>
<form method="POST" class="sync-form" onsubmit="return confirm('Criar todas as zonas ausentes nos slaves?');">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="sync_todas_ausentes">
<label>IP master NS1</label>
<input name="master_ip" value="<?= htmlspecialchars($masterIpPadrao) ?>" placeholder="198.51.100.242" required>
<button type="submit">Sincronizar todas ausentes</button>
</form>
</section>
<?php endif; ?>

<section class="card">
<div class="toolbar">
<h2>Classificacao das divergencias</h2>
<span class="meta">Governanca antes de qualquer reconciliacao destrutiva</span>
</div>
<div class="class-grid">
<?php foreach ($resumoClassificacao as $codigo => $total): ?>
<div class="class-item">
<span class="badge <?= $total > 0 && !in_array($codigo, ['OK', 'EXTRA_IGNORADA'], true) ? 'warn' : 'ok' ?>"><?= htmlspecialchars($codigo) ?></span>
<strong><?= (int) $total ?></strong>
</div>
<?php endforeach; ?>
</div>
</section>

<?php if ($extrasPorServidor): ?>
<section class="card blocked">
<div class="toolbar">
<h2>Zonas extras nos slaves</h2>
<span class="badge">Remocao bloqueada</span>
</div>
<p class="lead">Estas zonas existem em slaves e nao existem no NS1. Elas podem ser legitimas; nenhuma remocao automatica sera executada nesta fase.</p>
<div class="extra-list">
<?php foreach ($extrasPorServidor as $grupo): ?>
<article class="class-item">
<span class="badge"><?= htmlspecialchars($grupo['server_nome']) ?></span>
<strong><?= count($grupo['zonas']) ?></strong>
<ul class="zone-list">
<?php foreach ($grupo['zonas'] as $extra): ?>
<li><?= htmlspecialchars($extra['zone_name']) ?> <span class="muted">SOA <?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?></span></li>
<?php endforeach; ?>
</ul>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<?php if ($extrasIgnoradasPorServidor): ?>
<section class="card ignored">
<div class="toolbar">
<h2>Extras ignoradas</h2>
<span class="badge ok">Legitimas</span>
</div>
<p class="lead">Estas zonas extras foram marcadas como legitimas e ficam separadas dos alertas acionaveis.</p>
<div class="extra-list">
<?php foreach ($extrasIgnoradasPorServidor as $grupo): ?>
<article class="class-item">
<span class="badge"><?= htmlspecialchars($grupo['server_nome']) ?></span>
<strong><?= count($grupo['zonas']) ?></strong>
<ul class="zone-list">
<?php foreach ($grupo['zonas'] as $extra): ?>
<li><?= htmlspecialchars($extra['zone_name']) ?> <span class="muted">SOA <?= htmlspecialchars((string) ($extra['slave_serial'] ?? 'indisponivel')) ?></span><?php if (!empty($extra['ignore_note'])): ?><br><span class="muted"><?= htmlspecialchars((string) $extra['ignore_note']) ?></span><?php endif; ?></li>
<?php endforeach; ?>
</ul>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endif; ?>

<?php if ($falhasColeta): ?>
<section class="card notice">
<h2>Falhas de coleta</h2>
<?php foreach ($falhasColeta as $server): ?>
<p class="error"><?= htmlspecialchars($server['server_nome']) ?>: <?= htmlspecialchars((string) $server['last_error']) ?></p>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($erro): ?><div class="message error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="message success"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
<?php if ($resultadoSync): ?>
<section class="card">
<h2>Resultado da sincronizacao</h2>
<?php foreach (($resultadoSync['resultados'] ?? []) as $resultado): ?>
<p class="meta"><?= htmlspecialchars((string) $resultado['servidor']) ?> · <?= htmlspecialchars((string) $resultado['zona']) ?> · <?= !empty($resultado['ok']) ? 'OK' : 'Falha' ?> · <?= (int) ($resultado['duracao_ms'] ?? 0) ?> ms</p>
<div class="result"><?= htmlspecialchars((string) ($resultado['saida'] ?? '')) ?></div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (!$statusServidores): ?>
<section class="card notice">
<h2>Nenhum inventario carregado</h2>
<p class="lead">Clique em atualizar inventario para descobrir NS1 e importar as zonas existentes dos slaves cadastrados.</p>
</section>
<?php endif; ?>

<section class="grid">
<?php foreach ($statusServidores as $server): ?>
<article class="card stat">
<span class="badge <?= $server['last_ok'] ? 'ok' : 'error' ?>"><?= $server['last_ok'] ? 'Online' : 'Falha' ?></span>
<span class="badge"><?= htmlspecialchars(strtoupper($server['server_role'])) ?></span>
<h2><?= htmlspecialchars($server['server_nome']) ?></h2>
<div class="value"><?= (int) $server['total_zones'] ?></div>
<p class="meta">Total de zonas · Atualizado em <?= htmlspecialchars(zones_data_sao_paulo($server['checked_at'] ?? null)) ?></p>
<?php if (!$server['last_ok'] && $server['last_error']): ?><p class="error"><?= htmlspecialchars($server['last_error']) ?></p><?php endif; ?>
</article>
<?php endforeach; ?>
<article class="card stat">
<span class="badge <?= $divergencias ? 'warn' : 'ok' ?>">Comparacao</span>
<h2>Divergencias</h2>
<div class="value"><?= count($divergencias) ?></div>
<p class="meta">Presenca de zona e serial SOA master/slave.</p>
</article>
</section>

<?php if ($resultados): ?>
<section class="card">
<h2>Resultado da ultima atualizacao</h2>
<p class="meta">NS1: <?= htmlspecialchars($resultados['local']['servidor'] ?? 'local') ?> · <?= !empty($resultados['local']['ok']) ? 'OK' : 'Falha' ?> · <?= (int) ($resultados['local']['zonas'] ?? 0) ?> zonas</p>
<?php foreach ($resultados['remotos'] as $resultado): ?>
<p class="meta"><?= htmlspecialchars((string) $resultado['servidor']) ?> · <?= !empty($resultado['ok']) ? 'OK' : 'Falha' ?> · <?= (int) ($resultado['zonas'] ?? 0) ?> zonas · <?= (int) ($resultado['duracao_ms'] ?? 0) ?> ms</p>
<?php if (empty($resultado['ok'])): ?><div class="result"><?= htmlspecialchars((string) $resultado['saida']) ?></div><?php endif; ?>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
<div class="toolbar">
<h2>Comparacao NS1 x slaves</h2>
<span class="meta"><?= count($comparacaoExibida) ?><?= $statusFiltro !== '' ? ' de ' . count($comparacao) : '' ?> verificacoes<?= $statusFiltro !== '' ? ' · filtro: ' . htmlspecialchars($statusFiltroLabels[$statusFiltro]) : '' ?></span>
</div>
<input class="search" id="filtro-comparacao" type="text" placeholder="Pesquisar zona ou servidor...">
<div class="table-wrap">
<table id="tabela-comparacao">
<thead><tr><th>Zona</th><th>Slave</th><th>Serial NS1</th><th>Serial slave</th><th>Estado</th><th>Detalhe</th><th>Acoes</th></tr></thead>
<tbody>
<?php if (!$comparacaoExibida): ?>
<tr><td colspan="7" class="muted"><?= $statusFiltro !== '' ? 'Nenhuma zona encontrada para o filtro selecionado.' : 'Sem dados de comparacao. Atualize o inventario.' ?></td></tr>
<?php endif; ?>
<?php foreach ($comparacaoExibida as $linha): ?>
<tr>
<td><?= htmlspecialchars($linha['zone_name']) ?></td>
<td><?= htmlspecialchars($linha['server_nome']) ?></td>
<td><?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?></td>
<td><?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?></td>
<td class="<?= zone_estado_class($linha['estado']) ?>"><strong><?= htmlspecialchars(zone_estado_label($linha['estado'])) ?></strong></td>
<td><?= htmlspecialchars($linha['detalhe']) ?></td>
<td>
<div class="action-row">
<button type="button" class="small-button muted-button inspect-zone"
    data-zone="<?= htmlspecialchars($linha['zone_name']) ?>"
    data-server="<?= htmlspecialchars($linha['server_nome']) ?>"
    data-server-key="<?= htmlspecialchars($linha['server_key']) ?>"
    data-state="<?= htmlspecialchars($linha['estado']) ?>"
    data-state-label="<?= htmlspecialchars(zone_estado_label($linha['estado'])) ?>"
    data-detail="<?= htmlspecialchars($linha['detalhe']) ?>"
    data-explanation="<?= htmlspecialchars(zone_estado_explicacao($linha['estado'])) ?>"
    data-master="<?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?>"
    data-slave="<?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?>"
    data-note="<?= htmlspecialchars((string) ($linha['ignore_note'] ?? '')) ?>">Inspecionar</button>
<?php if ($linha['estado'] === 'missing_on_slave'): ?>
<form method="POST" class="inline-form" onsubmit="return confirm('Criar zona slave <?= htmlspecialchars($linha['zone_name']) ?> em <?= htmlspecialchars($linha['server_nome']) ?>?');">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="sync_zona_ausente">
<input type="hidden" name="zona" value="<?= htmlspecialchars($linha['zone_name']) ?>">
<input type="hidden" name="server_key" value="<?= htmlspecialchars($linha['server_key']) ?>">
<input type="hidden" name="master_ip" value="<?= htmlspecialchars($masterIpPadrao) ?>">
<button type="submit" class="small-button">Criar slave</button>
</form>
<?php endif; ?>
</div>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card">
<div class="toolbar">
<h2>Auditoria DNS recente</h2>
<span class="meta"><?= count($eventosAuditoriaDns) ?> eventos</span>
</div>
<div class="table-wrap">
<table>
<thead><tr><th>Data</th><th>Acao</th><th>Zona</th><th>Servidor</th><th>Status</th><th>Mensagem</th></tr></thead>
<tbody>
<?php if (!$eventosAuditoriaDns): ?>
<tr><td colspan="6" class="muted">Nenhum evento DNS auditado.</td></tr>
<?php endif; ?>
<?php foreach ($eventosAuditoriaDns as $evento): ?>
<tr>
<td><?= htmlspecialchars(zones_data_sao_paulo($evento['criado_em'] ?? null)) ?></td>
<td><?= htmlspecialchars((string) $evento['acao']) ?></td>
<td><?= htmlspecialchars((string) ($evento['dominio'] ?? '-')) ?></td>
<td><?= htmlspecialchars((string) ($evento['nome_registro'] ?? '-')) ?></td>
<td class="<?= ($evento['status'] ?? '') === 'OK' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $evento['status']) ?></td>
<td><?= htmlspecialchars((string) ($evento['mensagem'] ?? '-')) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<section class="card">
<div class="toolbar">
<h2>Inventario bruto</h2>
<span class="meta"><?= count($inventario) ?> entradas</span>
</div>
<input class="search" id="filtro-inventario" type="text" placeholder="Pesquisar zona, servidor, arquivo ou master...">
<div class="table-wrap">
<table id="tabela-inventario">
<thead><tr><th>Servidor</th><th>Papel</th><th>Zona</th><th>Tipo</th><th>Serial SOA</th><th>Arquivo</th><th>Masters</th><th>Status</th></tr></thead>
<tbody>
<?php if (!$inventario): ?>
<tr><td colspan="8" class="muted">Inventario vazio.</td></tr>
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
<td class="<?= $zona['status'] === 'ok' ? 'ok' : 'warn' ?>"><?= htmlspecialchars($zona['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</section>

<dialog class="dialog" id="zone-dialog">
<form method="dialog">
<div class="toolbar">
<h2 id="dialog-title">Inspecao da zona</h2>
<button type="submit" class="small-button muted-button">Fechar</button>
</div>
</form>
<div class="dialog-grid">
<div class="dialog-field"><span>Zona</span><strong id="dialog-zone"></strong></div>
<div class="dialog-field"><span>Slave</span><strong id="dialog-server"></strong></div>
<div class="dialog-field"><span>Serial NS1</span><strong id="dialog-master"></strong></div>
<div class="dialog-field"><span>Serial slave</span><strong id="dialog-slave"></strong></div>
<div class="dialog-field"><span>Estado</span><strong id="dialog-state"></strong></div>
<div class="dialog-field"><span>Detalhe</span><strong id="dialog-detail"></strong></div>
</div>
<p class="lead" id="dialog-explanation"></p>
<p class="meta" id="dialog-note-wrap">Nota: <span id="dialog-note"></span></p>
<form method="POST" id="ignore-form">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="ignorar_extra">
<input type="hidden" name="zona" id="ignore-zone">
<input type="hidden" name="server_key" id="ignore-server-key">
<label class="meta" for="ignore-note">Nota opcional</label>
<textarea name="nota" id="ignore-note" maxlength="500" placeholder="Ex.: zona legada mantida somente neste slave"></textarea>
<div class="dialog-actions"><button type="submit">Marcar como legitima</button></div>
</form>
<form method="POST" id="restore-form">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="restaurar_extra">
<input type="hidden" name="zona" id="restore-zone">
<input type="hidden" name="server_key" id="restore-server-key">
<div class="dialog-actions"><button type="submit" class="muted-button">Restaurar alerta</button></div>
</form>
</dialog>
</main>
<script>
function bindFilter(inputId, tableId){
    const input=document.getElementById(inputId), table=document.getElementById(tableId);
    if(!input||!table)return;
    input.addEventListener('input',()=>{
        const q=input.value.toLowerCase();
        table.querySelectorAll('tbody tr').forEach(row=>{
            row.style.display=row.innerText.toLowerCase().includes(q)?'':'none';
        });
    });
}
bindFilter('filtro-comparacao','tabela-comparacao');
bindFilter('filtro-inventario','tabela-inventario');

const zoneDialog=document.getElementById('zone-dialog');
const ignoreForm=document.getElementById('ignore-form');
const restoreForm=document.getElementById('restore-form');
function setText(id,value){const el=document.getElementById(id);if(el)el.textContent=value||'-';}
document.querySelectorAll('.inspect-zone').forEach(button=>{
    button.addEventListener('click',()=>{
        const d=button.dataset;
        setText('dialog-zone',d.zone);
        setText('dialog-server',d.server);
        setText('dialog-master',d.master);
        setText('dialog-slave',d.slave);
        setText('dialog-state',d.stateLabel);
        setText('dialog-detail',d.detail);
        setText('dialog-explanation',d.explanation);
        setText('dialog-note',d.note);
        document.getElementById('dialog-note-wrap').style.display=d.note?'':'none';
        document.getElementById('ignore-zone').value=d.zone;
        document.getElementById('ignore-server-key').value=d.serverKey;
        document.getElementById('ignore-note').value=d.note||'';
        document.getElementById('restore-zone').value=d.zone;
        document.getElementById('restore-server-key').value=d.serverKey;
        ignoreForm.style.display=d.state==='extra_on_slave'?'':'none';
        restoreForm.style.display=d.state==='extra_on_slave_ignored'?'':'none';
        if(typeof zoneDialog.showModal==='function')zoneDialog.showModal();
    });
});
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
