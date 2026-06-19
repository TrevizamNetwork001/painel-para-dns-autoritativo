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

        if ($acao === 'salvar_governanca') {
            $zona = (string) ($_POST['zona'] ?? '');
            $serverKey = (string) ($_POST['server_key'] ?? '');
            $classificacao = (string) ($_POST['classificacao'] ?? 'revisar');
            $observacaoZona = (string) ($_POST['observacao_zona'] ?? '');
            $observacaoServidor = (string) ($_POST['observacao_servidor'] ?? '');
            $mensagemGovernanca = match ($classificacao) {
                'legitima' => '✓ Divergência marcada como Legítima.',
                'ignorada' => '✓ Divergência marcada como Ignorada.',
                'revisar' => '✓ Divergência enviada para Revisão.',
                default => '✓ Governança da divergência atualizada.',
            };
            $linha = dns_zones_salvar_governanca(
                $zona,
                $serverKey,
                $classificacao,
                $observacaoZona,
                $observacaoServidor,
                $_SESSION['usuario'] ?? null
            );

            registrar_auditoria([
                'acao' => 'DNS_ZONE_GOVERNANCE_UPDATE',
                'dominio' => $linha['zone_name'],
                'tipo_registro' => 'DNS_ZONE',
                'nome_registro' => $linha['server_nome'],
                'valor_antigo' => strtoupper((string) ($linha['governance_classification'] ?? 'revisar')),
                'valor_novo' => strtoupper($classificacao),
                'status' => 'OK',
                'mensagem' => substr(trim(implode(' | ', array_filter([
                    $observacaoZona !== '' ? 'Zona: ' . $observacaoZona : '',
                    $observacaoServidor !== '' ? 'Servidor: ' . $observacaoServidor : '',
                ]))) ?: 'Classificacao de governanca atualizada.', 0, 1000),
            ]);

            if (
                strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
                || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            ) {
                $linhaAtualizada = dns_zones_comparacao_por_zona_servidor($zona, $serverKey);
                $resumoAtualizado = dns_zones_resumo();
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'ok' => true,
                    'mensagem' => $mensagemGovernanca,
                    'linha' => $linhaAtualizada,
                    'resumo' => [
                        'divergencias' => (int) $resumoAtualizado['divergencias'],
                        'excecoes_aprovadas' => (int) $resumoAtualizado['excecoes_aprovadas'],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            zones_redirect($mensagemGovernanca);
        }

        if ($acao === 'salvar_observacao_servidor') {
            $serverKey = (string) ($_POST['server_key'] ?? '');
            $serverNome = (string) ($_POST['server_nome'] ?? '');
            $observacaoServidor = (string) ($_POST['observacao_servidor'] ?? '');
            dns_zones_salvar_observacao_servidor(
                $serverKey,
                $serverNome,
                $observacaoServidor,
                $_SESSION['usuario'] ?? null
            );
            registrar_auditoria([
                'acao' => 'DNS_SERVER_GOVERNANCE_NOTE',
                'tipo_registro' => 'DNS_SERVER',
                'nome_registro' => $serverNome,
                'valor_novo' => 'OBSERVACAO_OPERACIONAL',
                'status' => 'OK',
                'mensagem' => substr($observacaoServidor !== '' ? $observacaoServidor : 'Observacao operacional removida.', 0, 1000),
            ]);
            zones_redirect('Observacao do servidor atualizada.');
        }

        throw new RuntimeException('Acao invalida.');
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        registrar_auditoria([
            'acao' => match ($acao) {
                'salvar_governanca' => 'DNS_ZONE_GOVERNANCE_UPDATE',
                'salvar_observacao_servidor' => 'DNS_SERVER_GOVERNANCE_NOTE',
                default => 'DNS_ZONE_INVENTORY_REFRESH',
            },
            'tipo_registro' => $acao === 'salvar_observacao_servidor' ? 'DNS_SERVER' : 'DNS_ZONE',
            'nome_registro' => $acao === 'salvar_observacao_servidor'
                ? (string) ($_POST['server_nome'] ?? 'servidor')
                : 'inventario',
            'status' => 'ERRO',
            'mensagem' => substr($erro, 0, 1000),
        ]);
        if (
            strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        ) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode(['ok' => false, 'erro' => $erro], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }
}

$statusServidores = dns_zones_status_servidores();
$comparacao = dns_zones_comparar();
$inventario = dns_zones_inventario();
$divergencias = array_values(array_filter(
    $comparacao,
    static fn(array $linha): bool => ($linha['estado_base'] ?? $linha['estado']) !== 'ok'
        && empty($linha['governance_approved'])
));
$excecoesAprovadas = array_values(array_filter(
    $comparacao,
    static fn(array $linha): bool => !empty($linha['governance_approved'])
));
$zonasAusentes = array_values(array_filter($comparacao, static fn(array $linha): bool => $linha['estado'] === 'missing_on_slave'));
$resumoClassificacao = dns_zones_resumo_classificacao($comparacao);
$extrasPorServidor = dns_zones_extras_por_servidor($comparacao);
$extrasIgnoradasPorServidor = dns_zones_extras_por_servidor($comparacao, true);
$falhasColeta = dns_zones_status_falha_coleta();
$eventosAuditoriaDns = dns_zones_eventos_auditoria(25);
$observacoesServidores = dns_zones_server_governance_map();
$statusFiltro = trim((string) ($_GET['status'] ?? ''));
$statusFiltro = in_array($statusFiltro, ['ok', 'divergencias', 'extras', 'excecoes'], true) ? $statusFiltro : '';
$statusFiltroLabels = [
    'ok' => 'zonas sincronizadas',
    'divergencias' => 'divergencias',
    'extras' => 'zonas extras',
    'excecoes' => 'excecoes aprovadas',
];
$comparacaoExibida = array_values(array_filter(
    $comparacao,
    static function (array $linha) use ($statusFiltro): bool {
        if ($statusFiltro === 'ok') {
            return $linha['estado'] === 'ok';
        }
        if ($statusFiltro === 'divergencias') {
            return ($linha['estado_base'] ?? $linha['estado']) !== 'ok'
                && empty($linha['governance_approved']);
        }
        if ($statusFiltro === 'extras') {
            return $linha['estado'] === 'extra_on_slave';
        }
        if ($statusFiltro === 'excecoes') {
            return !empty($linha['governance_approved']);
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

function zone_estado_class(string $estado, bool $excecaoAprovada = false): string
{
    return $excecaoAprovada || in_array($estado, ['ok', 'extra_on_slave_ignored'], true) ? 'ok' : 'warn';
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
.dialog select{width:100%;padding:10px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff}.governance-fields{display:grid;gap:12px}.governance-fields label{display:block;color:#94a3b8;font-size:12px;margin-bottom:5px}.server-note-form{margin-top:12px}.server-note-form textarea{width:100%;min-height:58px;padding:9px;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#fff;resize:vertical}.server-note-form button{margin-top:7px}.toast-message{position:fixed;top:18px;right:18px;z-index:100;width:min(420px,calc(100vw - 36px));margin:0;box-shadow:0 18px 40px #0008;transition:opacity .25s ease,transform .25s ease}.toast-message.is-hiding{opacity:0;transform:translateY(-10px)}.governance-badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:800;border:1px solid #475569;background:#1e293b;color:#cbd5e1}.governance-badge.legitima{border-color:#166534;background:#052e16;color:#86efac}.governance-badge.ignorada{border-color:#1d4ed8;background:#172554;color:#93c5fd}.governance-badge.revisar{border-color:#a16207;background:#422006;color:#fde68a}.governance-history{display:block;margin-top:5px;color:#64748b;font-size:10px;line-height:1.35}.governance-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}.governance-option{position:relative}.governance-option input{position:absolute;opacity:0;pointer-events:none}.governance-option span{display:block;border:1px solid #334155;border-radius:8px;background:#0f172a;padding:10px;text-align:center;font-weight:800;cursor:pointer}.governance-option input:checked+span{border-color:#38bdf8;box-shadow:0 0 0 2px #0ea5e944 inset}.decision-history{background:#071226;border:1px solid #1e293b;border-radius:8px;padding:11px}.decision-history strong{display:block;margin-bottom:4px}.decision-history span{color:#94a3b8;font-size:12px}.governance-feedback{min-height:18px;margin-top:8px;color:#94a3b8;font-size:12px}.governance-feedback.ok{color:#4ade80}.governance-feedback.error{color:#f87171}@media(max-width:600px){.governance-options{grid-template-columns:1fr}}
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
<input name="master_ip" value="<?= htmlspecialchars($masterIpPadrao) ?>" placeholder="45.162.196.242" required>
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
<span class="badge <?= $total > 0 && !in_array($codigo, ['OK', 'EXTRA_IGNORADA', 'EXCECOES_APROVADAS'], true) ? 'warn' : 'ok' ?>"><?= htmlspecialchars($codigo) ?></span>
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
<h2>Excecoes aprovadas entre extras</h2>
<span class="badge ok">Governanca</span>
</div>
<p class="lead">Estas zonas extras foram classificadas como legitimas ou ignoradas e ficam separadas dos alertas acionaveis.</p>
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

<?php if ($erro): ?><div class="message error toast-message" role="alert" data-toast><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="message success toast-message" role="status" data-toast><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
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
<form method="POST" class="server-note-form">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="salvar_observacao_servidor">
<input type="hidden" name="server_key" value="<?= htmlspecialchars((string) $server['server_key']) ?>">
<input type="hidden" name="server_nome" value="<?= htmlspecialchars((string) $server['server_nome']) ?>">
<label class="meta">Observacao operacional</label>
<textarea name="observacao_servidor" maxlength="1000" placeholder="Ex.: Slave temporario."><?= htmlspecialchars((string) ($observacoesServidores[$server['server_key']]['note'] ?? '')) ?></textarea>
<button type="submit" class="small-button muted-button">Salvar observacao</button>
</form>
</article>
<?php endforeach; ?>
<article class="card stat">
<span class="badge <?= $divergencias ? 'warn' : 'ok' ?>">Comparacao</span>
<h2>Divergencias reais</h2>
<div class="value" id="counter-real-divergences"><?= count($divergencias) ?></div>
<p class="meta">Presenca de zona e serial SOA master/slave.</p>
</article>
<article class="card stat">
<span class="badge ok">Governanca</span>
<h2>Excecoes aprovadas</h2>
<div class="value" id="counter-approved-exceptions"><?= count($excecoesAprovadas) ?></div>
<p class="meta">Divergencias legitimas ou ignoradas.</p>
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
<thead><tr><th>Zona</th><th>Slave</th><th>Serial NS1</th><th>Serial slave</th><th>Estado</th><th>Classificacao</th><th>Detalhe</th><th>Acoes</th></tr></thead>
<tbody>
<?php if (!$comparacaoExibida): ?>
<tr><td colspan="8" class="muted"><?= $statusFiltro !== '' ? 'Nenhuma zona encontrada para o filtro selecionado.' : 'Sem dados de comparacao. Atualize o inventario.' ?></td></tr>
<?php endif; ?>
<?php foreach ($comparacaoExibida as $linha): ?>
<tr data-governance-row="<?= htmlspecialchars($linha['server_key'] . '|' . $linha['zone_name']) ?>">
<td><?= htmlspecialchars($linha['zone_name']) ?></td>
<td><?= htmlspecialchars($linha['server_nome']) ?></td>
<td><?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?></td>
<td><?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?></td>
<td class="<?= zone_estado_class($linha['estado'], !empty($linha['governance_approved'])) ?>" data-governance-state><strong><?= htmlspecialchars(zone_estado_label($linha['estado'])) ?></strong></td>
<td data-governance-classification><?php if (($linha['estado_base'] ?? $linha['estado']) === 'ok'): ?><span class="muted">Nao aplicavel</span><?php else: ?><span class="governance-badge <?= htmlspecialchars((string) ($linha['governance_classification'] ?? 'revisar')) ?>"><?= htmlspecialchars(ucfirst((string) ($linha['governance_classification'] ?? 'revisar'))) ?></span><small class="governance-history" style="display:<?= !empty($linha['classified_at']) || !empty($linha['classified_by']) ? 'block' : 'none' ?>"><?= htmlspecialchars(trim(((string) ($linha['classified_by'] ?? '')) . (!empty($linha['classified_at']) ? ' · ' . zones_data_sao_paulo((string) $linha['classified_at']) : ''))) ?></small><?php endif; ?></td>
<td data-governance-detail><span><?= htmlspecialchars($linha['detalhe']) ?></span><small class="muted" style="display:<?= !empty($linha['zone_note']) ? 'block' : 'none' ?>"><?= htmlspecialchars((string) ($linha['zone_note'] ?? '')) ?></small></td>
<td>
<div class="action-row">
<button type="button" class="small-button muted-button inspect-zone"
    data-zone="<?= htmlspecialchars($linha['zone_name']) ?>"
    data-server="<?= htmlspecialchars($linha['server_nome']) ?>"
    data-server-key="<?= htmlspecialchars($linha['server_key']) ?>"
    data-state="<?= htmlspecialchars($linha['estado']) ?>"
    data-state-base="<?= htmlspecialchars((string) ($linha['estado_base'] ?? $linha['estado'])) ?>"
    data-state-label="<?= htmlspecialchars(zone_estado_label($linha['estado'])) ?>"
    data-detail="<?= htmlspecialchars($linha['detalhe']) ?>"
    data-explanation="<?= htmlspecialchars(zone_estado_explicacao($linha['estado'])) ?>"
    data-master="<?= htmlspecialchars((string) ($linha['master_serial'] ?? 'indisponivel')) ?>"
    data-slave="<?= htmlspecialchars((string) ($linha['slave_serial'] ?? 'indisponivel')) ?>"
    data-classification="<?= htmlspecialchars((string) ($linha['governance_classification'] ?? 'revisar')) ?>"
    data-zone-note="<?= htmlspecialchars((string) ($linha['zone_note'] ?? '')) ?>"
    data-server-note="<?= htmlspecialchars((string) ($linha['server_note'] ?? '')) ?>"
    data-classified-by="<?= htmlspecialchars((string) ($linha['classified_by'] ?? '')) ?>"
    data-classified-at="<?= htmlspecialchars((string) ($linha['classified_at'] ?? '')) ?>">Inspecionar</button>
<?php if (($linha['estado_base'] ?? $linha['estado']) === 'missing_on_slave'): ?>
<form method="POST" class="inline-form" style="display:<?= empty($linha['governance_approved']) ? 'inline' : 'none' ?>" onsubmit="return confirm('Criar zona slave <?= htmlspecialchars($linha['zone_name']) ?> em <?= htmlspecialchars($linha['server_nome']) ?>?');">
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
<div class="dialog-field"><span>Classificacao atual</span><strong id="dialog-classification"></strong></div>
<div class="dialog-field"><span>Impacto no alerta</span><strong id="dialog-alert-impact"></strong></div>
</div>
<p class="lead" id="dialog-explanation"></p>
<div class="decision-history"><strong>Ultima decisao</strong><span id="dialog-decision-history">Nenhuma decisao registrada.</span></div>
<form method="POST" id="governance-form">
<?= csrf_field() ?>
<input type="hidden" name="acao" value="salvar_governanca">
<input type="hidden" name="zona" id="governance-zone">
<input type="hidden" name="server_key" id="governance-server-key">
<div class="governance-fields">
<div><label>Classificacao manual</label><div class="governance-options"><label class="governance-option"><input type="radio" name="classificacao" value="legitima"><span>Legitima</span></label><label class="governance-option"><input type="radio" name="classificacao" value="ignorada"><span>Ignorada</span></label><label class="governance-option"><input type="radio" name="classificacao" value="revisar"><span>Revisar</span></label></div></div>
<div><label for="governance-zone-note">Observacao da zona</label><textarea name="observacao_zona" id="governance-zone-note" maxlength="1000" placeholder="Ex.: Zona herdada de migracao."></textarea></div>
<div><label for="governance-server-note">Observacao do servidor</label><textarea name="observacao_servidor" id="governance-server-note" maxlength="1000" placeholder="Ex.: Slave temporario."></textarea></div>
</div>
<div class="governance-feedback" id="governance-feedback" aria-live="polite"></div>
<div class="dialog-actions"><button type="submit" id="governance-submit">Salvar governanca</button></div>
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
document.querySelectorAll('[data-toast]').forEach(toast=>{
    setTimeout(()=>{
        toast.classList.add('is-hiding');
        setTimeout(()=>toast.remove(),250);
    },5000);
});
function showToast(message,type='success'){
    const toast=document.createElement('div');
    toast.className=`message ${type} toast-message`;
    toast.setAttribute(type==='error'?'role':'aria-live',type==='error'?'alert':'polite');
    toast.textContent=message;
    document.body.append(toast);
    setTimeout(()=>{
        toast.classList.add('is-hiding');
        setTimeout(()=>toast.remove(),250);
    },5000);
}

const zoneDialog=document.getElementById('zone-dialog');
const governanceForm=document.getElementById('governance-form');
const governanceFeedback=document.getElementById('governance-feedback');
const governanceSubmit=document.getElementById('governance-submit');
let activeGovernanceButton=null;
function setText(id,value){const el=document.getElementById(id);if(el)el.textContent=value||'-';}
function classificationLabel(value){return value==='legitima'?'Legitima':value==='ignorada'?'Ignorada':'Revisar';}
function decisionHistory(classification,by,at){
    if(!by&&!at)return'Nenhuma decisao registrada.';
    let date=at||'';
    if(at){
        const parsed=new Date(at.replace(' ','T')+'Z');
        if(!Number.isNaN(parsed.getTime()))date=parsed.toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'});
    }
    return`${classificationLabel(classification)} por ${by||'usuario nao informado'}${date?' em '+date:''}.`;
}
function setClassificationRadio(value){
    const radio=governanceForm.querySelector(`input[name="classificacao"][value="${value}"]`);
    if(radio)radio.checked=true;
}
function updateModalClassification(value,approved,by,at){
    setText('dialog-classification',classificationLabel(value));
    setText('dialog-alert-impact',approved?'Excecao aprovada; nao conta como divergencia real.':'Permanece como divergencia real.');
    setText('dialog-decision-history',decisionHistory(value,by,at));
}
document.querySelectorAll('.inspect-zone').forEach(button=>{
    button.addEventListener('click',()=>{
        activeGovernanceButton=button;
        const d=button.dataset;
        setText('dialog-zone',d.zone);
        setText('dialog-server',d.server);
        setText('dialog-master',d.master);
        setText('dialog-slave',d.slave);
        setText('dialog-state',d.stateLabel);
        setText('dialog-detail',d.detail);
        setText('dialog-explanation',d.explanation);
        document.getElementById('governance-zone').value=d.zone;
        document.getElementById('governance-server-key').value=d.serverKey;
        setClassificationRadio(d.classification||'revisar');
        document.getElementById('governance-zone-note').value=d.zoneNote||'';
        document.getElementById('governance-server-note').value=d.serverNote||'';
        if(d.stateBase==='ok'){
            setText('dialog-classification','Nao aplicavel');
            setText('dialog-alert-impact','Zona sincronizada; nenhuma decisao de governanca necessaria.');
            setText('dialog-decision-history','Nenhuma decisao necessaria.');
        }else{
            updateModalClassification(d.classification||'revisar',['legitima','ignorada'].includes(d.classification),d.classifiedBy,d.classifiedAt);
        }
        governanceFeedback.textContent='';
        governanceFeedback.className='governance-feedback';
        governanceForm.style.display=d.stateBase==='ok'?'none':'';
        if(typeof zoneDialog.showModal==='function')zoneDialog.showModal();
    });
});
governanceForm.addEventListener('change',event=>{
    if(event.target.name!=='classificacao')return;
    const value=event.target.value;
    setText('dialog-classification',`${classificationLabel(value)} (alteracao nao salva)`);
    setText('dialog-alert-impact',['legitima','ignorada'].includes(value)?'Apos salvar, deixara de contar como divergencia real.':'Apos salvar, permanecera como divergencia real.');
});
governanceForm.addEventListener('submit',async event=>{
    event.preventDefault();
    if(!activeGovernanceButton)return;
    governanceSubmit.disabled=true;
    governanceFeedback.textContent='Salvando...';
    governanceFeedback.className='governance-feedback';
    try{
        const response=await fetch('zones.php',{method:'POST',body:new FormData(governanceForm),headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        const data=await response.json();
        if(!response.ok||!data.ok)throw new Error(data.erro||'Nao foi possivel salvar a governanca.');
        const line=data.linha||{};
        const classification=line.governance_classification||'revisar';
        const approved=Boolean(line.governance_approved);
        activeGovernanceButton.dataset.classification=classification;
        activeGovernanceButton.dataset.zoneNote=line.zone_note||'';
        activeGovernanceButton.dataset.serverNote=line.server_note||'';
        activeGovernanceButton.dataset.classifiedBy=line.classified_by||'';
        activeGovernanceButton.dataset.classifiedAt=line.classified_at||'';
        activeGovernanceButton.dataset.state=line.estado||activeGovernanceButton.dataset.state;
        const stateLabels={ok:'OK',missing_on_slave:'AUSENTE_NO_SLAVE',serial_mismatch:'SERIAL_DIFERENTE',extra_on_slave:'EXTRA_NO_SLAVE',extra_on_slave_ignored:'EXTRA_IGNORADA',master_serial_unknown:'SOA_MASTER_INDISPONIVEL',slave_serial_unknown:'SOA_SLAVE_INDISPONIVEL'};
        activeGovernanceButton.dataset.stateLabel=stateLabels[line.estado]||line.estado||activeGovernanceButton.dataset.stateLabel;
        const row=activeGovernanceButton.closest('tr');
        const badge=row?.querySelector('[data-governance-classification] .governance-badge');
        if(badge){
            badge.className=`governance-badge ${classification}`;
            badge.textContent=classificationLabel(classification);
        }
        const history=row?.querySelector('[data-governance-classification] .governance-history');
        if(history){
            let date=line.classified_at||'';
            if(date){
                const parsed=new Date(date.replace(' ','T')+'Z');
                if(!Number.isNaN(parsed.getTime()))date=parsed.toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'});
            }
            history.textContent=[line.classified_by||'',date].filter(Boolean).join(' · ');
            history.style.display=history.textContent?'block':'none';
        }
        const stateCell=row?.querySelector('[data-governance-state]');
        if(stateCell){
            stateCell.className=approved?'ok':'warn';
            stateCell.querySelector('strong').textContent=activeGovernanceButton.dataset.stateLabel;
        }
        const syncForm=row?.querySelector('.inline-form');
        if(syncForm)syncForm.style.display=approved?'none':'inline';
        const detailCell=row?.querySelector('[data-governance-detail]');
        if(detailCell){
            detailCell.querySelector('span').textContent=line.detalhe||'';
            const note=detailCell.querySelector('small');
            note.textContent=line.zone_note||'';
            note.style.display=line.zone_note?'block':'none';
        }
        document.getElementById('counter-real-divergences').textContent=String(data.resumo.divergencias);
        document.getElementById('counter-approved-exceptions').textContent=String(data.resumo.excecoes_aprovadas);
        updateModalClassification(classification,approved,line.classified_by||'',line.classified_at||'');
        const currentFilter=new URLSearchParams(window.location.search).get('status')||'';
        if(
            (currentFilter==='divergencias'&&approved)
            ||(currentFilter==='excecoes'&&!approved)
            ||(currentFilter==='extras'&&line.estado!=='extra_on_slave')
        ){
            row?.remove();
        }
        governanceFeedback.textContent=data.mensagem;
        governanceFeedback.className='governance-feedback ok';
        showToast(data.mensagem,'success');
        setTimeout(()=>{
            if(zoneDialog.open)zoneDialog.close();
        },400);
    }catch(error){
        governanceFeedback.textContent=error.message||'Nao foi possivel salvar a governanca.';
        governanceFeedback.className='governance-feedback error';
        showToast('✗ Falha ao atualizar governança da divergência.','error');
    }finally{
        governanceSubmit.disabled=false;
    }
});
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
