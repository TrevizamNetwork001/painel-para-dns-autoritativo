<?php

require_once __DIR__ . "/includes/auth.php";

function fw_exec(string $command): array
{
    $lines = [];
    $exit = 0;
    exec($command . ' 2>&1', $lines, $exit);
    return [trim(implode("
", $lines)), $exit];
}

function fw_clean(string $text): string
{
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function fw_parse_ruleset(string $raw): array
{
    $tables = [];
    $tableIndex = -1;
    $chainIndex = -1;

    foreach (preg_split('/\R/u', $raw) ?: [] as $lineRaw) {
        $line = fw_clean($lineRaw);
        if ($line === '') {
            continue;
        }

        if (preg_match('/^table\s+(\S+)\s+(\S+)$/i', $line, $m)) {
            $tables[] = [
                'family' => $m[1],
                'name' => $m[2],
                'chains' => [],
            ];
            $tableIndex = array_key_last($tables);
            $chainIndex = -1;
            continue;
        }

        if ($tableIndex < 0) {
            continue;
        }

        if (preg_match('/^chain\s+([^\s{]+)\s*\{/i', $line, $m)) {
            $tables[$tableIndex]['chains'][] = [
                'name' => $m[1],
                'meta' => $line,
                'rules' => [],
            ];
            $chainIndex = array_key_last($tables[$tableIndex]['chains']);
            continue;
        }

        if ($chainIndex < 0) {
            continue;
        }

        if (str_starts_with($line, 'type ') || str_contains($line, ' hook ') || str_contains($line, ' policy ')) {
            $tables[$tableIndex]['chains'][$chainIndex]['meta'] = fw_clean(
                $tables[$tableIndex]['chains'][$chainIndex]['meta'] . ' ' . $line
            );
            continue;
        }

        if ($line === '{' || $line === '}') {
            continue;
        }

        $tables[$tableIndex]['chains'][$chainIndex]['rules'][] = $line;
    }

    return $tables;
}

function fw_count_chains(array $tables): int
{
    $total = 0;
    foreach ($tables as $table) {
        $total += count($table['chains']);
    }
    return $total;
}

function fw_count_rules(array $tables): int
{
    $total = 0;
    foreach ($tables as $table) {
        foreach ($table['chains'] as $chain) {
            $total += count($chain['rules']);
        }
    }
    return $total;
}

function fw_count_drop_packets(string $rulesetRaw): int
{
    if ($rulesetRaw === '') {
        return 0;
    }

    preg_match_all('/counter packets (\d+) bytes .* drop/i', $rulesetRaw, $matches);

    return array_sum(array_map('intval', $matches[1] ?? []));
}

function fw_count_drop_packets_by_family(string $rulesetRaw): array
{
    $ipv4 = 0;
    $ipv6 = 0;

    foreach (preg_split('/\R/u', $rulesetRaw) ?: [] as $lineRaw) {
        $line = fw_clean($lineRaw);
        if ($line === '' || !preg_match('/counter packets (\d+) bytes .* drop/i', $line, $match)) {
            continue;
        }

        $packets = (int) $match[1];
        $isIpv6 = preg_match('/\b(ip6|ipv6|meta nfproto ipv6)\b/i', $line) === 1;
        $isIpv4 = preg_match('/\b(ip\b|ipv4|meta nfproto ipv4)\b/i', $line) === 1 && !$isIpv6;

        if ($isIpv6) {
            $ipv6 += $packets;
        } elseif ($isIpv4) {
            $ipv4 += $packets;
        } else {
            $ipv4 += $packets;
        }
    }

    return [
        'ipv4' => $ipv4,
        'ipv6' => $ipv6,
        'total' => $ipv4 + $ipv6,
    ];
}

function fw_chain_policy(string $meta): ?string
{
    return preg_match('/\bpolicy\s+([a-z]+)\b/i', $meta, $m) ? strtolower($m[1]) : null;
}

function fw_chain_hook(string $meta): ?string
{
    return preg_match('/\bhook\s+([a-z]+)\b/i', $meta, $m) ? strtolower($m[1]) : null;
}

function fw_format_time(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return date('d/m/Y H:i');
    }
}

[$serviceRaw, $serviceExit] = fw_exec('systemctl is-active nftables');
[$rulesetRaw, $rulesetExit] = fw_exec('sudo -n /usr/sbin/nft list ruleset');

$service = trim($serviceRaw);
$serviceActive = $service === 'active';
$serviceKnown = $service !== '';
$rulesetOk = trim($rulesetRaw) !== '' && $rulesetExit === 0;
$tables = $rulesetOk ? fw_parse_ruleset($rulesetRaw) : [];
$tablesCount = count($tables);
$chainsCount = fw_count_chains($tables);
$rulesCount = fw_count_rules($tables);
$dropPackets = fw_count_drop_packets_by_family($rulesetRaw);
$checkTime = fw_format_time();

$diagnostic = [];
$diagnostic[] = $serviceActive ? ['ok', 'Serviço nftables ativo'] : ($serviceKnown ? ['warn', 'Serviço nftables inativo'] : ['warn', 'Serviço nftables não identificado']);
$diagnostic[] = $rulesetOk ? ['ok', 'Ruleset carregado'] : ['warn', 'Não foi possível consultar nftables'];
$diagnostic[] = $tablesCount > 0 ? ['ok', $tablesCount . ' ' . ($tablesCount === 1 ? 'tabela encontrada' : 'tabelas encontradas')] : ['warn', 'Nenhuma tabela encontrada'];
$diagnostic[] = $chainsCount > 0 ? ['ok', $chainsCount . ' ' . ($chainsCount === 1 ? 'chain encontrada' : 'chains encontradas')] : ['warn', 'Nenhuma chain encontrada'];
$diagnostic[] = $rulesCount > 0 ? ['ok', $rulesCount . ' ' . ($rulesCount === 1 ? 'regra encontrada' : 'regras encontradas')] : ['warn', 'Nenhuma regra encontrada'];
$diagnostic[] = $rulesetOk && array_filter($tables, fn($t) => true) ? ['ok', 'Ruleset disponível para leitura'] : ['warn', 'Ruleset indisponível'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firewall</title>
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
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:radial-gradient(circle at top,#101b33 0,var(--bg) 44%,#070b14 100%);
    color:var(--text);
}
a{color:inherit;text-decoration:none}
.page{
    width:min(980px, calc(100% - 28px));
    margin:0 auto;
    padding:22px 0 34px;
}
.back{
    color:var(--accent);
    font-size:14px;
}
h1{
    margin:8px 0 6px;
    font-size:28px;
}
.subtitle{
    margin:0;
    color:var(--muted);
    line-height:1.5;
}
.card{
    margin-top:18px;
    padding:16px;
    border:1px solid var(--border);
    border-radius:14px;
    background:linear-gradient(180deg, rgba(15,23,42,.96), rgba(11,18,32,.96));
    box-shadow:0 16px 44px rgba(0,0,0,.18);
}
.card h2{
    margin:0 0 6px;
    font-size:18px;
}
.card p{
    margin:0;
    color:var(--muted);
}
.stats{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:12px;
    margin-top:14px;
}
.stat{
    padding:14px;
    border-radius:12px;
    border:1px solid var(--border);
    background:rgba(17,28,51,.72);
}
.stat h3{
    margin:0;
    font-size:24px;
}
.stat p{
    margin-top:8px;
    color:var(--muted);
    font-size:13px;
}
.stat.bad{background:rgba(63,13,18,.45)}
.stat.warn{background:rgba(63,50,13,.42)}
.stat.info{background:rgba(12,45,72,.42)}
.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.18);
    background:rgba(148,163,184,.10);
    color:#dbe7f5;
    font-size:12px;
    white-space:nowrap;
}
.badges{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.badge-ok{background:rgba(22,163,74,.14);border-color:rgba(22,163,74,.3);color:#bbf7d0}
.badge-warn{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.32);color:#fde68a}
.badge-info{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.32);color:#bfdbfe}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.actions a{
    border:1px solid var(--border);
    background:transparent;
    color:var(--muted);
    border-radius:10px;
    padding:9px 12px;
    font-size:13px;
}
.panel{
    margin-top:12px;
    border:1px solid var(--border);
    border-radius:12px;
    background:rgba(17,28,51,.72);
    padding:14px;
}
.panel h3{
    margin:0 0 10px;
    font-size:15px;
}
pre{
    margin:0;
    padding:14px;
    background:#091123;
    border:1px solid var(--border);
    border-radius:12px;
    color:#cbd5e1;
    font-size:12px;
    line-height:1.55;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:auto;
}
.table{
    margin-top:12px;
    padding-top:12px;
    border-top:1px solid rgba(148,163,184,.12);
}
.table:first-child{
    margin-top:0;
    padding-top:0;
    border-top:0;
}
.table h4{
    margin:0 0 6px;
    font-size:14px;
}
.chain{
    margin-top:10px;
    padding:10px 12px;
    border:1px solid var(--border);
    border-radius:12px;
    background:#091123;
}
.chain-title{
    margin:0 0 8px;
    color:#dbe7f5;
    font-size:13px;
}
.rule{
    margin-top:8px;
    padding:9px 12px;
    border:1px solid rgba(148,163,184,.16);
    border-radius:10px;
    background:rgba(17,28,51,.55);
    color:#cbd5e1;
    font-size:12px;
    line-height:1.5;
    white-space:pre-wrap;
    word-break:break-word;
}
@media (max-width: 720px){
    .page{width:calc(100% - 18px);padding-top:14px}
    h1{font-size:24px}
}
</style>
</head>
<body>
<div class="page">
    <a class="back" href="dashboard.php">← Voltar</a>
    <h1>Firewall</h1>
    <p class="subtitle">Consulta somente leitura do nftables.</p>

    <section class="card">
        <h2>nftables</h2>
        <p>Estado atual do firewall e regras carregadas no servidor.</p>

        <div class="badges">
            <span class="badge <?= $serviceActive ? 'badge-ok' : ($serviceKnown ? 'badge-warn' : 'badge-warn') ?>"><?= $serviceActive ? 'Ativo' : ($serviceKnown ? 'Inativo' : 'Não identificado') ?></span>
            <span class="badge badge-info"><?= $tablesCount ?> <?= $tablesCount === 1 ? 'tabela' : 'tabelas' ?></span>
            <span class="badge badge-info"><?= $chainsCount ?> <?= $chainsCount === 1 ? 'chain' : 'chains' ?></span>
            <span class="badge badge-info"><?= $rulesCount ?> <?= $rulesCount === 1 ? 'regra' : 'regras' ?></span>
            <span class="badge">Verificado em <?= htmlspecialchars($checkTime) ?></span>
        </div>

        <div class="stats">
            <div class="stat <?= $dropPackets['ipv4'] > 0 ? 'warn' : 'info' ?>">
                <h3><?= $dropPackets['ipv4'] ?></h3>
                <p>Drops IPv4</p>
            </div>
            <div class="stat <?= $dropPackets['ipv6'] > 0 ? 'warn' : 'info' ?>">
                <h3><?= $dropPackets['ipv6'] ?></h3>
                <p>Drops IPv6</p>
            </div>
            <div class="stat info">
                <h3><?= $dropPackets['total'] ?></h3>
                <p>Total de drops</p>
            </div>
        </div>

        <div class="actions">
            <a href="#diagnostico">Mostrar diagnóstico</a>
            <a href="#dados">Mostrar regras</a>
        </div>

        <div class="panel" id="diagnostico">
            <h3>Diagnóstico</h3>
            <div class="badges">
                <?php foreach ($diagnostic as [$level, $message]): ?>
                    <span class="badge <?= $level === 'ok' ? 'badge-ok' : 'badge-warn' ?>"><?= htmlspecialchars($message) ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel" id="dados">
            <h3>Regras</h3>
            <?php if ($rulesetOk && $tables): ?>
                <?php foreach ($tables as $table): ?>
                    <div class="table">
                        <h4>table <?= htmlspecialchars($table['family']) ?> <?= htmlspecialchars($table['name']) ?></h4>
                        <p><?= count($table['chains']) ?> chain(s)</p>
                        <?php foreach ($table['chains'] as $chain): ?>
                            <?php $meta = $chain['meta'] ?? ''; ?>
                            <div class="chain">
                                <div class="chain-title">
                                    chain <?= htmlspecialchars($chain['name']) ?>
                                    <?php
                                        $parts = [];
                                        if (($hook = fw_chain_hook($meta))) {
                                            $parts[] = 'hook ' . $hook;
                                        }
                                        if (($policy = fw_chain_policy($meta))) {
                                            $parts[] = 'policy ' . $policy;
                                        }
                                    ?>
                                    <?= $parts ? '— ' . htmlspecialchars(implode(', ', $parts)) : '' ?>
                                </div>
                                <?php if ($chain['rules']): ?>
                                    <?php foreach ($chain['rules'] as $rule): ?>
                                        <div class="rule"><?= htmlspecialchars($rule) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="rule">Nenhuma regra direta encontrada nesta chain.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>Não foi possível consultar o nftables no momento.</p>
                <pre><?= htmlspecialchars($rulesetRaw !== '' ? $rulesetRaw : 'Sem saída disponível.') ?></pre>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
setTimeout(() => {
    window.location.reload();
}, 8000);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
