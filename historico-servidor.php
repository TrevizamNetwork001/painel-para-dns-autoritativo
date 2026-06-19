<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/dns_servers.php';

exigir_administrador();

$servidoresRemotos = dns_servers_listar();
$servidores = $servidoresRemotos;
$servidorLocal = [
    'id' => -1,
    'nome' => 'NS1',
    'hostname' => gethostname() ?: 'NS1',
    'ip4' => filter_var($_SERVER['SERVER_ADDR'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ?: '',
    'ip6' => filter_var($_SERVER['SERVER_ADDR'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ?: '',
];
array_unshift($servidores, $servidorLocal);
$servidorId = filter_input(INPUT_GET, 'servidor', FILTER_VALIDATE_INT) ?: 0;
$servidorSolicitado = strtolower(trim((string) ($_GET['server'] ?? '')));
$servidor = null;
foreach ($servidores as $item) {
    $identificadoresItem = array_filter([
        strtolower(trim((string) $item['nome'])),
        strtolower(trim((string) $item['hostname'])),
        strtolower(trim((string) ($item['ip4'] ?? ''))),
        strtolower(trim((string) ($item['ip6'] ?? ''))),
    ]);
    if (
        (int) $item['id'] === $servidorId
        || ($servidorSolicitado !== '' && in_array($servidorSolicitado, $identificadoresItem, true))
    ) {
        $servidor = $item;
        $servidorId = (int) $item['id'];
        break;
    }
}
if (!$servidor && $servidores) {
    $servidor = $servidoresRemotos[0] ?? $servidores[0];
    $servidorId = (int) $servidor['id'];
}

$tiposPermitidos = [
    'cadastro' => 'Cadastro',
    'edicao' => 'Alterações',
    'ssh' => 'Teste SSH',
    'bind' => 'Teste BIND',
    'axfr' => 'Teste AXFR',
    'agente' => 'Agente',
    'layout' => 'Migração de layout',
    'inventario' => 'Inventário',
    'sincronizacao' => 'Sincronização',
];
$tipo = (string) ($_GET['tipo'] ?? '');
if (!isset($tiposPermitidos[$tipo])) {
    $tipo = '';
}
$pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
$porPagina = 30;
$eventos = [];
$total = 0;
$totalPaginas = 1;

function historico_servidor_condicao_tipo(string $tipo): string
{
    return match ($tipo) {
        'cadastro' => "acao IN ('DNS_SERVER_ADD','DNS_SERVER_REMOVE')",
        'edicao' => "acao = 'DNS_SERVER_UPDATE'",
        'ssh' => "(acao IN ('TESTE_SSH_OK','TESTE_SSH_FALHA') OR (acao = 'DNS_SERVER_TEST' AND mensagem LIKE 'Conexão SSH:%'))",
        'bind' => "(acao IN ('TESTE_BIND_OK','TESTE_BIND_FALHA') OR (acao = 'DNS_SERVER_TEST' AND mensagem LIKE 'Status BIND:%'))",
        'axfr' => "(acao IN ('TESTE_AXFR_OK','TESTE_AXFR_FALHA') OR (acao = 'DNS_SERVER_TEST' AND mensagem LIKE 'Transferencia de zona%'))",
        'agente' => "acao IN ('AGENTE_OK','AGENTE_FALHA','DNS_SERVER_AGENT_INSTALL','DNS_SERVER_AGENT_UPDATE','DNS_SERVER_AGENT_REMOVE')",
        'layout' => "acao = 'DNS_SERVER_SLAVE_LAYOUT_MIGRATE'",
        'inventario' => "acao = 'DNS_SERVER_INVENTORY'",
        'sincronizacao' => "acao IN ('DNS_ZONE_SLAVE_SYNC_ONE','DNS_ZONE_SLAVE_SYNC_MISSING')",
        default => '1=1',
    };
}

function historico_servidor_rotulo(array $evento): string
{
    $acao = (string) $evento['acao'];
    $mensagem = (string) ($evento['mensagem'] ?? '');

    if ($acao === 'DNS_SERVER_TEST') {
        return match (true) {
            str_starts_with($mensagem, 'Conexão SSH:') => 'Teste SSH executado',
            str_starts_with($mensagem, 'Status BIND:') => 'Teste BIND executado',
            str_starts_with($mensagem, 'Transferencia de zona') => 'Teste AXFR executado',
            default => 'Diagnóstico executado',
        };
    }

    return match ($acao) {
        'TESTE_SSH_OK' => 'Teste SSH OK',
        'TESTE_SSH_FALHA' => 'Teste SSH com falha',
        'TESTE_BIND_OK' => 'Teste BIND OK',
        'TESTE_BIND_FALHA' => 'Teste BIND com falha',
        'TESTE_AXFR_OK' => 'Teste AXFR OK',
        'TESTE_AXFR_FALHA' => 'Teste AXFR com falha',
        'AGENTE_OK' => 'Agente OK',
        'AGENTE_FALHA' => 'Agente com falha',
        'DNS_SERVER_ADD' => 'Servidor cadastrado',
        'DNS_SERVER_UPDATE' => 'Cadastro do servidor alterado',
        'DNS_SERVER_REMOVE' => 'Servidor removido',
        'DNS_SERVER_AGENT_INSTALL' => 'Agente instalado',
        'DNS_SERVER_AGENT_UPDATE' => 'Agente atualizado',
        'DNS_SERVER_AGENT_REMOVE' => 'Agente removido',
        'DNS_SERVER_SLAVE_LAYOUT_MIGRATE' => 'Migração de layout executada',
        'DNS_SERVER_INVENTORY' => 'Inventário executado',
        'DNS_ZONE_SLAVE_SYNC_ONE', 'DNS_ZONE_SLAVE_SYNC_MISSING' => 'Sincronização de zona executada',
        default => ucwords(strtolower(str_replace('_', ' ', $acao))),
    };
}

function historico_servidor_data(string $data): string
{
    try {
        return (new DateTimeImmutable($data, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y H:i:s');
    } catch (Throwable) {
        return $data;
    }
}

if ($servidor) {
    $identificadores = array_values(array_unique(array_filter([
        strtolower(trim((string) $servidor['nome'])),
        strtolower(trim((string) $servidor['hostname'])),
        strtolower(trim((string) ($servidor['ip4'] ?? ''))),
        strtolower(trim((string) ($servidor['ip6'] ?? ''))),
    ])));
    $params = [];
    $placeholders = [];
    foreach ($identificadores as $indice => $identificador) {
        $chave = ':identificador_' . $indice;
        $placeholders[] = $chave;
        $params[$chave] = $identificador;
    }

    $whereServidor = "(tipo_registro = 'DNS_SERVER' AND LOWER(nome_registro) IN (" . implode(',', $placeholders) . '))'
        . " OR (tipo_registro = 'DNS_ZONE' AND LOWER(nome_registro) = :nome_servidor)";
    if ((int) $servidor['id'] === -1) {
        $whereServidor .= " OR acao = 'DNS_ZONE_INVENTORY_REFRESH'";
    }
    $params[':nome_servidor'] = strtolower((string) $servidor['nome']);
    $where = '(' . $whereServidor . ') AND (' . historico_servidor_condicao_tipo($tipo) . ')';

    $count = db()->prepare('SELECT COUNT(*) FROM audit_logs WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);

    $stmt = db()->prepare('SELECT * FROM audit_logs WHERE ' . $where . ' ORDER BY id DESC LIMIT :limite OFFSET :offset');
    foreach ($params as $chave => $valor) {
        $stmt->bindValue($chave, $valor);
    }
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function historico_servidor_query(array $substituicoes = []): string
{
    $query = array_merge($_GET, $substituicoes);
    foreach ($query as $chave => $valor) {
        if ($valor === '' || $valor === null) {
            unset($query[$chave]);
        }
    }
    return http_build_query($query);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Histórico operacional do servidor</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0f172a;color:#e2e8f0;font-family:Arial,sans-serif}.page{max-width:1080px;margin:auto;padding:24px}a{color:#38bdf8;text-decoration:none}.head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.head h1{margin:6px 0 4px;font-size:26px}.muted{color:#94a3b8}.filters,.event{background:#020617;border:1px solid #1e293b;border-radius:12px}.filters{display:flex;gap:8px;align-items:end;padding:12px;margin-bottom:16px}.filters label{display:block;color:#94a3b8;font-size:11px;margin-bottom:4px}.filters select,.filters button{min-width:190px;border:1px solid #334155;border-radius:7px;background:#0b1120;color:#fff;padding:9px 11px}.filters button{min-width:auto;background:#2563eb;font-weight:700;cursor:pointer}.timeline{display:grid;gap:10px}.event{position:relative;padding:14px 16px 14px 42px}.event:before{content:"";position:absolute;left:18px;top:19px;width:10px;height:10px;border-radius:50%;background:#22c55e}.event.error:before{background:#ef4444}.event h2{font-size:15px;margin:0 0 5px}.event-meta{display:flex;gap:10px;flex-wrap:wrap;color:#94a3b8;font-size:12px}.event-message{margin:9px 0 0;color:#cbd5e1;font-size:13px;line-height:1.4}.technical{margin-top:9px}.technical summary{cursor:pointer;color:#93c5fd;font-size:12px;font-weight:700}.technical pre{max-height:230px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;background:#0b1120;border:1px solid #1e293b;border-radius:7px;padding:9px;color:#cbd5e1;font-size:11px}.empty{padding:28px;text-align:center;background:#020617;border:1px solid #1e293b;border-radius:12px}.pagination{display:flex;justify-content:space-between;gap:12px;margin-top:16px}.disabled{pointer-events:none;color:#64748b}@media(max-width:720px){.page{padding:14px}.head,.filters{display:block}.filters>div,.filters select,.filters button{width:100%;margin-top:7px}.filters select{min-width:0}}
</style>
</head>
<body><main class="page">
<header class="head"><div><a href="dns-servers.php">← Voltar aos servidores DNS</a><h1><?= $servidor ? htmlspecialchars((string) $servidor['nome']) : 'Histórico operacional' ?></h1><p class="muted"><?= $servidor ? htmlspecialchars((string) ($servidor['ip4'] ?: $servidor['hostname'])) . ' • ' : '' ?><?= $total ?> eventos</p></div><a href="auditoria.php">Abrir auditoria</a></header>
<form class="filters" method="GET">
<div><label>Servidor</label><select name="servidor"><?php foreach ($servidores as $opcao): ?><option value="<?= (int) $opcao['id'] ?>" <?= (int) $opcao['id'] === $servidorId ? 'selected' : '' ?>><?= htmlspecialchars((string) $opcao['nome']) ?> • <?= htmlspecialchars((string) ($opcao['ip4'] ?: $opcao['hostname'])) ?></option><?php endforeach; ?></select></div>
<div><label>Tipo de evento</label><select name="tipo"><option value="">Todos os eventos</option><?php foreach ($tiposPermitidos as $valor => $rotulo): ?><option value="<?= htmlspecialchars($valor) ?>" <?= $tipo === $valor ? 'selected' : '' ?>><?= htmlspecialchars($rotulo) ?></option><?php endforeach; ?></select></div>
<button type="submit">Filtrar</button><?php if ($tipo !== ''): ?><a href="?servidor=<?= $servidorId ?>">Limpar filtro</a><?php endif; ?>
</form>
<section class="timeline">
<?php if (!$servidor): ?><div class="empty muted">Nenhum servidor DNS cadastrado.</div>
<?php elseif (!$eventos): ?><div class="empty muted">Nenhum evento encontrado para este filtro.</div>
<?php else: foreach ($eventos as $evento): ?>
<article class="event <?= $evento['status'] === 'OK' ? '' : 'error' ?>">
<h2><?= htmlspecialchars(historico_servidor_rotulo($evento)) ?></h2>
<div class="event-meta"><span><?= htmlspecialchars(historico_servidor_data((string) $evento['criado_em'])) ?></span><span><?= htmlspecialchars((string) $evento['usuario']) ?></span><span><?= htmlspecialchars((string) $evento['status']) ?></span><?php if (!empty($evento['dominio'])): ?><span>Zona: <?= htmlspecialchars((string) $evento['dominio']) ?></span><?php endif; ?></div>
<?php if (!empty($evento['mensagem'])): ?><p class="event-message"><?= htmlspecialchars(strtok((string) $evento['mensagem'], "\n") ?: (string) $evento['mensagem']) ?></p><?php endif; ?>
<?php if (str_contains((string) ($evento['mensagem'] ?? ''), "\n") || !empty($evento['valor_antigo']) || !empty($evento['valor_novo'])): ?><details class="technical"><summary>Ver detalhes técnicos</summary><pre><?= htmlspecialchars(trim(implode("\n\n", array_filter([(string) ($evento['valor_antigo'] ?? ''), (string) ($evento['valor_novo'] ?? ''), (string) ($evento['mensagem'] ?? '')])))) ?></pre></details><?php endif; ?>
</article>
<?php endforeach; endif; ?>
</section>
<?php if ($totalPaginas > 1): ?><nav class="pagination"><a class="<?= $pagina <= 1 ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(historico_servidor_query(['pagina' => max(1, $pagina - 1)])) ?>">← Anterior</a><span>Página <?= $pagina ?> de <?= $totalPaginas ?></span><a class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>" href="?<?= htmlspecialchars(historico_servidor_query(['pagina' => min($totalPaginas, $pagina + 1)])) ?>">Próxima →</a></nav><?php endif; ?>
</main></body></html>
