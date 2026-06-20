<?php

require_once __DIR__ . '/includes/auth.php';

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

function fw_format_time(): string
{
    try {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return date('d/m/Y H:i');
    }
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

function fw_chain_policy(string $meta): ?string
{
    return preg_match('/\bpolicy\s+([a-z]+)\b/i', $meta, $m) ? strtolower($m[1]) : null;
}

function fw_chain_hook(string $meta): ?string
{
    return preg_match('/\bhook\s+([a-z]+)\b/i', $meta, $m) ? strtolower($m[1]) : null;
}

function fw_rule_summary(string $line): string
{
    $text = fw_clean($line);
    $protocol = preg_match('/\b(tcp|udp|icmpv6|icmp|ip6|ip)\b/i', $text, $m) ? strtolower($m[1]) : '';
    $port = preg_match('/\b(?:dport|sport)\s+(\d{1,5})\b/i', $text, $m) ? $m[1] : '';
    $action = preg_match('/\b(accept|drop|reject)\b/i', $text, $m) ? strtolower($m[1]) : '';

    $parts = [];
    if ($protocol !== '') {
        $parts[] = '[' . $protocol . ']';
    }
    if ($port !== '') {
        $parts[] = '[porta ' . $port . ']';
    }
    if ($action !== '') {
        $parts[] = '[' . $action . ']';
    }

    $prefix = $parts ? implode(' ', $parts) . ' — ' : '';
    return $prefix . $text;
}

function fw_find_input_policy_accept(array $tables): bool
{
    foreach ($tables as $table) {
        foreach ($table['chains'] as $chain) {
            $meta = $chain['meta'] ?? '';
            if (fw_chain_hook($meta) === 'input' && fw_chain_policy($meta) === 'accept') {
                return true;
            }
        }
    }
    return false;
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
$checkTime = fw_format_time();
$auditoriaUrl = is_file(__DIR__ . '/auditoria.php') ? 'auditoria.php' : null;

$diagnostic = [];
$diagnostic[] = $serviceActive ? ['ok', 'Serviço nftables ativo'] : ($serviceKnown ? ['warn', 'Serviço nftables inativo'] : ['warn', 'Serviço nftables não identificado']);
$diagnostic[] = $rulesetOk ? ['ok', 'Ruleset carregado'] : ['warn', 'Não foi possível consultar nftables'];
$diagnostic[] = $tablesCount > 0 ? ['ok', $tablesCount . ' ' . ($tablesCount === 1 ? 'tabela encontrada' : 'tabelas encontradas')] : ['warn', 'Nenhuma tabela encontrada'];
$diagnostic[] = $chainsCount > 0 ? ['ok', $chainsCount . ' ' . ($chainsCount === 1 ? 'chain encontrada' : 'chains encontradas')] : ['warn', 'Nenhuma chain encontrada'];
$diagnostic[] = $rulesCount > 0 ? ['ok', $rulesCount . ' ' . ($rulesCount === 1 ? 'regra encontrada' : 'regras encontradas')] : ['warn', 'Nenhuma regra encontrada'];
$diagnostic[] = fw_find_input_policy_accept($tables) ? ['warn', 'Política input permissiva'] : ['ok', 'Política input não identificada como accept'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Firewall</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0b1220;
            --panel: #0f172a;
            --border: #24324a;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --ok: #16a34a;
            --warn: #d97706;
            --bad: #dc2626;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: radial-gradient(circle at top, #101b33 0, var(--bg) 44%, #070b14 100%);
            color: var(--text);
        }
        a { color: inherit; text-decoration: none; }
        .page {
            width: min(900px, calc(100% - 24px));
            margin: 0 auto;
            padding: 22px 0 34px;
        }
        .back {
            color: var(--accent);
            font-size: 14px;
        }
        h1 {
            margin: 8px 0 6px;
            font-size: 28px;
        }
        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
        .card {
            margin-top: 18px;
            padding: 16px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(11, 18, 32, .96));
            box-shadow: 0 16px 44px rgba(0, 0, 0, .18);
        }
        h2 {
            margin: 0 0 6px;
            font-size: 18px;
        }
        .card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
        .badges, .actions, .diag-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .badges { margin-top: 12px; }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .18);
            background: rgba(148, 163, 184, .10);
            color: #dbe7f5;
            font-size: 12px;
            white-space: nowrap;
        }
        .badge-ok { background: rgba(22, 163, 74, .14); border-color: rgba(22, 163, 74, .3); color: #bbf7d0; }
        .badge-warn { background: rgba(217, 119, 6, .14); border-color: rgba(217, 119, 6, .32); color: #fde68a; }
        .badge-bad { background: rgba(220, 38, 38, .14); border-color: rgba(220, 38, 38, .32); color: #fecaca; }
        .actions { margin-top: 12px; }
        .actions button, .actions a {
            border: 1px solid var(--border);
            background: #111a2f;
            color: var(--text);
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }
        .actions a {
            background: transparent;
            color: var(--muted);
        }
        .panel {
            margin-top: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(17, 28, 51, .72);
            padding: 14px;
        }
        .panel h3 {
            margin: 0 0 10px;
            font-size: 15px;
        }
        .diag-item {
            width: 100%;
            display: flex;
            gap: 10px;
            align-items: flex-start;
            font-size: 13px;
            line-height: 1.5;
            color: #dbe7f5;
        }
        .diag-ico {
            width: 18px;
            flex: 0 0 18px;
            text-align: center;
        }
        .diag-item.ok .diag-ico { color: #86efac; }
        .diag-item.warn .diag-ico { color: #fde68a; }
        .table {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(148, 163, 184, .12);
        }
        .table:first-child {
            margin-top: 0;
            padding-top: 0;
            border-top: 0;
        }
        .table-head {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        .table-title {
            margin: 0 0 3px;
            font-size: 14px;
            color: #dbe7f5;
        }
        .meta {
            color: var(--muted);
            font-size: 12px;
        }
        .chain {
            margin-top: 10px;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #091123;
        }
        .chain-title {
            margin: 0 0 8px;
            color: #dbe7f5;
            font-size: 13px;
        }
        .rule {
            margin-top: 8px;
            padding: 10px 12px;
            border: 1px solid rgba(148, 163, 184, .16);
            border-radius: 10px;
            background: rgba(17, 28, 51, .55);
        }
        .rule-line {
            margin-top: 6px;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.5;
            white-space: pre-wrap;
            word-break: break-word;
        }
        pre {
            margin: 0;
            padding: 14px;
            background: #091123;
            border: 1px solid var(--border);
            border-radius: 12px;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.55;
            white-space: pre;
            overflow: auto;
        }
        summary {
            cursor: pointer;
            color: var(--accent);
            font-size: 13px;
            list-style: none;
        }
        summary::-webkit-details-marker {
            display: none;
        }
        .empty {
            margin-top: 12px;
            padding: 14px;
            border-radius: 12px;
            border: 1px dashed var(--border);
            color: var(--muted);
            background: rgba(17, 28, 51, .45);
        }
        @media (max-width: 720px) {
            .page { width: calc(100% - 18px); padding-top: 14px; }
            h1 { font-size: 24px; }
            .table-head { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="page">
        <a class="back" href="dashboard.php">← Voltar ao painel</a>
        <h1>Firewall</h1>
        <p class="subtitle">Consulta somente leitura do nftables.</p>

        <section class="card">
            <h2>nftables</h2>
            <p>Estado atual do firewall e regras carregadas no servidor.</p>

            <div class="badges">
                <span class="badge <?= $serviceActive ? 'badge-ok' : ($serviceKnown ? 'badge-bad' : 'badge-warn') ?>"><?= $serviceActive ? 'Ativo' : ($serviceKnown ? 'Inativo' : 'Não identificado') ?></span>
                <span class="badge"><?= $tablesCount ?> <?= $tablesCount === 1 ? 'tabela' : 'tabelas' ?></span>
                <span class="badge"><?= $chainsCount ?> <?= $chainsCount === 1 ? 'chain' : 'chains' ?></span>
                <span class="badge"><?= $rulesCount ?> <?= $rulesCount === 1 ? 'regra' : 'regras' ?></span>
                <span class="badge">Verificado em <?= htmlspecialchars($checkTime) ?></span>
            </div>

            <div class="actions">
                <button type="button" data-toggle="diagnostic">Mostrar diagnóstico</button>
                <button type="button" data-toggle="rules">Mostrar regras</button>
                <?php if ($auditoriaUrl): ?><a href="<?= htmlspecialchars($auditoriaUrl) ?>">Ver auditoria</a><?php endif; ?>
            </div>

            <div class="panel" id="diagnostic-panel" hidden>
                <h3>Diagnóstico</h3>
                <div class="diag-list">
                    <?php foreach ($diagnostic as [$level, $message]): ?>
                        <div class="diag-item <?= htmlspecialchars($level) ?>">
                            <span class="diag-ico" aria-hidden="true"><?= $level === 'ok' ? '✓' : '⚠' ?></span>
                            <span><?= htmlspecialchars($message) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="panel" id="rules-panel" hidden>
                <h3>Regras</h3>
                <?php if ($rulesetOk && $tables): ?>
                    <?php foreach ($tables as $table): ?>
                        <div class="table">
                            <div class="table-head">
                                <div>
                                    <div class="table-title">table <?= htmlspecialchars($table['family']) ?> <?= htmlspecialchars($table['name']) ?></div>
                                    <div class="meta"><?= count($table['chains']) ?> chain(s)</div>
                                </div>
                            </div>
                            <?php foreach ($table['chains'] as $chain): ?>
                                <?php $meta = $chain['meta'] ?? ''; ?>
                                <div class="chain">
                                    <div class="chain-title">
                                        chain <?= htmlspecialchars($chain['name']) ?>
                                        <?php
                                            $details = [];
                                            if (($hook = fw_chain_hook($meta))) {
                                                $details[] = 'hook ' . $hook;
                                            }
                                            if (($policy = fw_chain_policy($meta))) {
                                                $details[] = 'policy ' . $policy;
                                            }
                                        ?>
                                        <?= $details ? '— ' . htmlspecialchars(implode(', ', $details)) : '' ?>
                                    </div>
                                    <?php if ($chain['rules']): ?>
                                        <?php foreach ($chain['rules'] as $rule): ?>
                                            <div class="rule"><?= htmlspecialchars(fw_rule_summary($rule)) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="empty">Nenhuma regra direta encontrada nesta chain.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty">Não foi possível consultar o nftables no momento.</div>
                    <details style="margin-top:12px;">
                        <summary>Ver ruleset bruto</summary>
                        <pre><?= htmlspecialchars($rulesetRaw !== '' ? $rulesetRaw : 'Sem saída disponível.') ?></pre>
                    </details>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const buttons = document.querySelectorAll('[data-toggle]');
            buttons.forEach((button) => {
                button.addEventListener('click', () => {
                    const key = button.getAttribute('data-toggle');
                    const panel = document.getElementById(key + '-panel');
                    if (!panel) return;
                    const hidden = panel.hasAttribute('hidden');
                    panel.toggleAttribute('hidden');
                    button.textContent = hidden
                        ? (key === 'diagnostic' ? 'Ocultar diagnóstico' : 'Ocultar regras')
                        : (key === 'diagnostic' ? 'Mostrar diagnóstico' : 'Mostrar regras');
                });
            });
        })();
    </script>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
