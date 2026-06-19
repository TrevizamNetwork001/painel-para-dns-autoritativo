<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/audit.php';

$dominio = audit_dominio_base(trim((string) ($_GET['dominio'] ?? ''))) ?? '';
$pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
$porPagina = 30;
$eventos = [];
$total = 0;

if ($dominio !== '') {
    $count = db()->prepare('SELECT COUNT(*) FROM audit_logs WHERE dominio = :dominio');
    $count->execute([':dominio' => $dominio]);
    $total = (int) $count->fetchColumn();
    $totalPaginas = max(1, (int) ceil($total / $porPagina));
    $pagina = min($pagina, $totalPaginas);

    $stmt = db()->prepare(
        'SELECT * FROM audit_logs WHERE dominio = :dominio ORDER BY id DESC LIMIT :limite OFFSET :offset'
    );
    $stmt->bindValue(':dominio', $dominio);
    $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
    $stmt->execute();
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $totalPaginas = 1;
}

function historico_zona_acao(string $acao): string
{
    return match ($acao) {
        'CRIAR_DOMINIO', 'CRIAR_ZONA_FORWARD' => 'Zona criada',
        'REMOVER_DOMINIO', 'REMOVER_ZONA_FORWARD' => 'Zona removida',
        'ADICIONAR_REGISTRO' => 'Registro adicionado',
        'EDITAR_REGISTRO' => 'Registro alterado',
        'REMOVER_REGISTRO' => 'Registro removido',
        'DNS_ZONE_SYNC' => 'Sincronização executada',
        'DNS_ZONE_INVENTORY' => 'Inventário executado',
        'DNS_ZONE_EXTRA_IGNORE' => 'Divergência ignorada',
        'DNS_ZONE_EXTRA_UNIGNORE' => 'Divergência reaberta',
        default => ucwords(strtolower(str_replace('_', ' ', $acao))),
    };
}

function historico_zona_data(string $data): string
{
    try {
        return (new DateTimeImmutable($data, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
            ->format('d/m/Y H:i:s');
    } catch (Throwable) {
        return $data;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Histórico da zona<?= $dominio !== '' ? ' - ' . htmlspecialchars($dominio) : '' ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#0f172a;color:#e2e8f0;font-family:Arial,sans-serif}.page{max-width:1050px;margin:auto;padding:24px}a{color:#38bdf8;text-decoration:none}.head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}.head h1{margin:6px 0 4px;font-size:26px}.muted{color:#94a3b8}.filter,.timeline-card{background:#020617;border:1px solid #1e293b;border-radius:12px}.filter{display:flex;gap:8px;padding:12px;margin-bottom:16px}.filter input{flex:1}.filter input,.filter button{border:1px solid #334155;border-radius:7px;background:#0b1120;color:#fff;padding:9px 11px}.filter button{background:#2563eb;font-weight:700;cursor:pointer}.timeline{display:grid;gap:10px}.event{position:relative;padding:14px 16px 14px 42px}.event:before{content:"";position:absolute;left:18px;top:19px;width:10px;height:10px;border-radius:50%;background:#22c55e}.event.error:before{background:#ef4444}.event h2{font-size:15px;margin:0 0 5px}.event-meta{display:flex;gap:10px;flex-wrap:wrap;color:#94a3b8;font-size:12px}.event-detail{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}.value{background:#0b1120;border:1px solid #1e293b;border-radius:7px;padding:8px;font:12px Consolas,monospace;white-space:pre-wrap;overflow-wrap:anywhere}.message{margin:9px 0 0;color:#cbd5e1;font-size:13px}.empty{padding:28px;text-align:center}.pagination{display:flex;justify-content:space-between;margin-top:16px}.disabled{pointer-events:none;color:#64748b}@media(max-width:700px){.page{padding:14px}.head,.filter{display:block}.filter input,.filter button{width:100%;margin-top:7px}.event-detail{grid-template-columns:1fr}}
</style>
</head>
<body><main class="page">
<header class="head"><div><a href="dashboard.php">← Voltar ao painel</a><h1><?= $dominio !== '' ? htmlspecialchars($dominio) : 'Histórico por zona' ?></h1><p class="muted"><?= $total ?> eventos registrados</p></div><?php if ($dominio !== ''): ?><a href="auditoria.php?dominio=<?= urlencode($dominio) ?>">Abrir auditoria completa</a><?php endif; ?></header>
<form class="filter" method="GET"><input name="dominio" value="<?= htmlspecialchars($dominio) ?>" placeholder="Digite o domínio" required><button type="submit">Consultar histórico</button></form>
<section class="timeline">
<?php if ($dominio === ''): ?><div class="timeline-card empty muted">Informe uma zona para consultar sua linha do tempo.</div>
<?php elseif (!$eventos): ?><div class="timeline-card empty muted">Nenhum evento encontrado para esta zona.</div>
<?php else: foreach ($eventos as $evento): ?>
<article class="timeline-card event <?= $evento['status'] === 'OK' ? '' : 'error' ?>">
<h2><?= htmlspecialchars(historico_zona_acao((string) $evento['acao'])) ?></h2>
<div class="event-meta"><span><?= htmlspecialchars(historico_zona_data((string) $evento['criado_em'])) ?></span><span><?= htmlspecialchars((string) $evento['usuario']) ?></span><span><?= htmlspecialchars((string) $evento['status']) ?></span><?php if (!empty($evento['nome_registro'])): ?><span><?= htmlspecialchars((string) $evento['nome_registro']) ?><?= !empty($evento['tipo_registro']) ? ' · ' . htmlspecialchars((string) $evento['tipo_registro']) : '' ?></span><?php endif; ?></div>
<?php if (!empty($evento['valor_antigo']) || !empty($evento['valor_novo'])): ?><div class="event-detail"><div class="value"><?= htmlspecialchars((string) ($evento['valor_antigo'] ?: '—')) ?></div><div class="value"><?= htmlspecialchars((string) ($evento['valor_novo'] ?: '—')) ?></div></div><?php endif; ?>
<?php if (!empty($evento['mensagem'])): ?><p class="message"><?= nl2br(htmlspecialchars((string) $evento['mensagem'])) ?></p><?php endif; ?>
</article>
<?php endforeach; endif; ?>
</section>
<?php if ($dominio !== '' && $totalPaginas > 1): ?><nav class="pagination"><a class="<?= $pagina <= 1 ? 'disabled' : '' ?>" href="?dominio=<?= urlencode($dominio) ?>&pagina=<?= max(1, $pagina - 1) ?>">← Anterior</a><span>Página <?= $pagina ?> de <?= $totalPaginas ?></span><a class="<?= $pagina >= $totalPaginas ? 'disabled' : '' ?>" href="?dominio=<?= urlencode($dominio) ?>&pagina=<?= min($totalPaginas, $pagina + 1) ?>">Próxima →</a></nav><?php endif; ?>
</main></body></html>
