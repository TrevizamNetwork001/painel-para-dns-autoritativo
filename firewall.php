<?php

require_once __DIR__ . '/includes/auth.php';

function firewall_exec_command(string $command): array
{
    $lines = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $lines, $exitCode);
    return [trim(implode("\n", $lines)), $exitCode];
}

function firewall_clean_line(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function firewall_count_words(string $text, string $pattern): int
{
    if ($text === '') {
        return 0;
    }

    return preg_match_all($pattern, $text) ?: 0;
}

function firewall_parse_tables_list(string $raw): array
{
    $tables = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = firewall_clean_line($line);
        if ($line === '' || !preg_match('/^table\s+(\S+)\s+(\S+)$/u', $line, $match)) {
            continue;
        }

        $tables[] = [
            'family' => $match[1],
            'name' => $match[2],
        ];
    }

    return $tables;
}

function firewall_parse_rule_line(string $line): array
{
    $raw = firewall_clean_line($line);
    $lower = strtolower($raw);
    $action = 'não identificado';
    foreach (['accept', 'drop', 'reject', 'counter'] as $candidate) {
        if (str_contains($lower, $candidate)) {
            $action = $candidate;
            break;
        }
    }

    $protocol = 'não identificado';
    foreach (['tcp', 'udp', 'icmpv6', 'icmp', 'ip6', 'ip'] as $candidate) {
        if (preg_match('/(^|[^a-z0-9])' . preg_quote($candidate, '/') . '([^a-z0-9]|$)/i', $raw)) {
            $protocol = $candidate;
            break;
        }
    }

    $port = '';
    if (preg_match('/\b(?:th\s+)?(?:dport|sport)\s+(\d{1,5})\b/i', $raw, $match)) {
        $port = $match[1];
    }

    $source = '';
    foreach ([
        '/\bip6?\s+saddr\s+([^;]+)/i',
        '/\bip6?\s+daddr\s+([^;]+)/i',
        '/\bsaddr\s+([^;]+)/i',
    ] as $pattern) {
        if (preg_match($pattern, $raw, $match)) {
            $source = firewall_clean_line($match[1]);
            break;
        }
    }

    return [
        'raw' => $raw,
        'search' => strtolower(implode(' ', array_filter([
            $raw,
            $action,
            $protocol,
            $port,
            $source,
        ]))),
        'action' => $action,
        'protocol' => $protocol,
        'port' => $port,
        'source' => $source,
    ];
}

function firewall_parse_ruleset(string $raw): array
{
    $tables = [];
    $currentTableKey = null;
    $currentChainKey = null;

    foreach (preg_split('/\R/u', $raw) ?: [] as $lineRaw) {
        $line = firewall_clean_line($lineRaw);
        if ($line === '') {
            continue;
        }

        if ($currentChainKey !== null && $line === '}') {
            $currentChainKey = null;
            continue;
        }

        if ($currentTableKey !== null && $currentChainKey === null && $line === '}') {
            $currentTableKey = null;
            continue;
        }

        if (preg_match('/^table\s+(\S+)\s+(\S+)/u', $line, $match)) {
            $currentTableKey = $match[1] . ' ' . $match[2];
            $tables[$currentTableKey] = [
                'family' => $match[1],
                'name' => $match[2],
                'chains' => [],
            ];
            continue;
        }

        if ($currentTableKey === null) {
            continue;
        }

        if (preg_match('/^chain\s+([^\s{]+)\s*\{/u', $line, $match)) {
            $currentChainKey = $match[1];
            $tables[$currentTableKey]['chains'][$currentChainKey] = [
                'name' => $match[1],
                'meta' => '',
                'rules' => [],
            ];
            if (str_contains($line, 'policy') || str_contains($line, 'hook')) {
                $tables[$currentTableKey]['chains'][$currentChainKey]['meta'] = $line;
            }
            continue;
        }

        if ($currentChainKey !== null) {
            if (str_starts_with($line, 'type ') || str_contains($line, ' hook ') || str_contains($line, ' policy ')) {
                $tables[$currentTableKey]['chains'][$currentChainKey]['meta'] = trim(
                    trim($tables[$currentTableKey]['chains'][$currentChainKey]['meta'] . ' ' . $line)
                );
                continue;
            }

            if ($line === '{') {
                continue;
            }

            if ($line !== '}') {
                $tables[$currentTableKey]['chains'][$currentChainKey]['rules'][] = firewall_parse_rule_line($line);
            }
        }
    }

    return array_values($tables);
}

function firewall_chain_policy(string $meta): ?string
{
    if (preg_match('/\bpolicy\s+([a-z]+)\b/i', $meta, $match)) {
        return strtolower($match[1]);
    }

    return null;
}

function firewall_chain_hook(string $meta): ?string
{
    if (preg_match('/\bhook\s+([a-z]+)\b/i', $meta, $match)) {
        return strtolower($match[1]);
    }

    return null;
}

function firewall_badge_class(string $label): string
{
    return match ($label) {
        'accept', 'ativo', 'ok' => 'badge badge-ok',
        'drop', 'reject', 'inativo', 'erro' => 'badge badge-bad',
        'warn', 'atenção' => 'badge badge-warn',
        'info', 'nftables', 'chain', 'table', 'tcp', 'udp', 'ip', 'ip6' => 'badge badge-info',
        default => 'badge',
    };
}

function firewall_value_badge(string $label, string $value): string
{
    $escaped = htmlspecialchars($value);
    return '<span class="' . firewall_badge_class($label) . '">' . $escaped . '</span>';
}

function firewall_count_chains(array $tables): int
{
    $total = 0;
    foreach ($tables as $table) {
        $total += count($table['chains']);
    }

    return $total;
}

function firewall_count_rules(array $tables): int
{
    $total = 0;
    foreach ($tables as $table) {
        foreach ($table['chains'] as $chain) {
            $total += count($chain['rules']);
        }
    }

    return $total;
}

function firewall_find_rule(array $tables, callable $filter): bool
{
    foreach ($tables as $table) {
        foreach ($table['chains'] as $chain) {
            foreach ($chain['rules'] as $rule) {
                if ($filter($table, $chain, $rule)) {
                    return true;
                }
            }
        }
    }

    return false;
}

function firewall_has_input_policy_accept(array $tables): bool
{
    foreach ($tables as $table) {
        foreach ($table['chains'] as $chain) {
            $meta = $chain['meta'] ?? '';
            if (firewall_chain_hook($meta) === 'input' && firewall_chain_policy($meta) === 'accept') {
                return true;
            }
        }
    }

    return false;
}

function firewall_format_check_time(): string
{
    try {
        $local = new DateTimeZone('America/Sao_Paulo');
        return (new DateTimeImmutable('now', $local))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return date('d/m/Y H:i');
    }
}

[$serviceRaw, $serviceExit] = firewall_exec_command('systemctl is-active nftables');
[$rulesetRaw, $rulesetExit] = firewall_exec_command('sudo -n /usr/sbin/nft list ruleset');
[$tablesRaw, $tablesExit] = firewall_exec_command('sudo -n /usr/sbin/nft list tables');

$serviceState = trim($serviceRaw);
$serviceKnown = $serviceState !== '';
$serviceActive = $serviceState === 'active';
$rulesetAvailable = trim($rulesetRaw) !== '' && $rulesetExit === 0;
$tablesFromList = firewall_parse_tables_list($tablesRaw);
$parsedTables = $rulesetAvailable ? firewall_parse_ruleset($rulesetRaw) : [];

if (!$parsedTables && $tablesFromList) {
    foreach ($tablesFromList as $table) {
        $parsedTables[] = [
            'family' => $table['family'],
            'name' => $table['name'],
            'chains' => [],
        ];
    }
}

$tablesCount = count($parsedTables);
$chainsCount = firewall_count_chains($parsedTables);
$rulesCount = firewall_count_rules($parsedTables);
$lastCheck = firewall_format_check_time();
$auditoriaUrl = is_file(__DIR__ . '/auditoria.php') ? 'auditoria.php' : null;
$selfUrl = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'firewall.php'));
$showRulesDefault = false;

$diagnostics = [];
$diagnostics[] = [
    'level' => $serviceActive ? 'ok' : ($serviceKnown ? 'bad' : 'warn'),
    'text' => $serviceActive ? 'Serviço nftables ativo.' : ($serviceKnown ? 'Serviço nftables inativo.' : 'Serviço nftables não identificado.'),
];
$diagnostics[] = [
    'level' => $rulesetAvailable ? 'ok' : 'warn',
    'text' => $rulesetAvailable ? 'Ruleset carregado com sucesso.' : 'Não foi possível carregar o ruleset completo.',
];
$diagnostics[] = [
    'level' => $tablesCount > 0 ? 'ok' : 'warn',
    'text' => $tablesCount > 0 ? $tablesCount . ' tabela(s) encontrada(s).' : 'Nenhuma tabela encontrada.',
];
$diagnostics[] = [
    'level' => $chainsCount > 0 ? 'ok' : 'warn',
    'text' => $chainsCount > 0 ? $chainsCount . ' chain(s) encontrada(s).' : 'Nenhuma chain encontrada.',
];
$diagnostics[] = [
    'level' => $rulesCount > 0 ? 'ok' : 'warn',
    'text' => $rulesCount > 0 ? $rulesCount . ' regra(s) encontrada(s).' : 'Nenhuma regra encontrada.',
];
$diagnostics[] = [
    'level' => firewall_has_input_policy_accept($parsedTables) ? 'warn' : 'ok',
    'text' => firewall_has_input_policy_accept($parsedTables) ? 'Chain input com policy accept.' : 'Policy padrão do input não identificada como accept.',
];
$sshFound = firewall_find_rule($parsedTables, function (array $table, array $chain, array $rule): bool {
    return preg_match('/\b(?:dport|sport)\s+22\b/', $rule['raw']) === 1
        || preg_match('/\bservice\s+ssh\b/i', $rule['raw']) === 1;
});
$webOpen = firewall_find_rule($parsedTables, function (array $table, array $chain, array $rule): bool {
    $openPort = preg_match('/\b(?:dport|sport)\s+(80|443)\b/', $rule['raw']) === 1;
    $allowAny = preg_match('/\baccept\b/i', $rule['raw']) === 1
        && preg_match('/\b(?:0\.0\.0\.0\/0|::\/0|any)\b/i', $rule['raw']) === 1;
    return $openPort && $allowAny;
});
$diagnostics[] = [
    'level' => $sshFound ? 'ok' : 'warn',
    'text' => $sshFound ? 'Porta SSH identificada nas regras.' : 'Porta SSH não identificada nas regras.',
];
$diagnostics[] = [
    'level' => $webOpen ? 'warn' : 'ok',
    'text' => $webOpen ? 'Porta do painel web parece exposta publicamente.' : 'Porta do painel web não foi identificada como exposta publicamente.',
];

$showRulesMessage = !$rulesetAvailable ? 'Não foi possível consultar o nftables no momento.' : '';
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
            --panel-2: #111c33;
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
            width: min(1220px, calc(100% - 32px));
            margin: 0 auto;
            padding: 24px 0 36px;
        }
        .topline {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 18px;
        }
        .back-link {
            color: var(--accent);
            font-size: 14px;
        }
        .title {
            margin: 8px 0 6px;
            font-size: 30px;
            font-weight: 700;
        }
        .subtitle {
            margin: 0;
            color: var(--muted);
            line-height: 1.5;
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
        .toolbar a,
        .toolbar button {
            appearance: none;
            border: 1px solid var(--border);
            background: linear-gradient(180deg, #13213b, #0f1a31);
            color: var(--text);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 13px;
            cursor: pointer;
            transition: border-color .15s ease, transform .15s ease, background .15s ease;
        }
        .toolbar a:hover,
        .toolbar button:hover {
            border-color: #3b82f6;
            transform: translateY(-1px);
        }
        .toolbar .primary { border-color: rgba(56, 189, 248, .45); }
        .toolbar .success { border-color: rgba(34, 197, 94, .45); }
        .toolbar .muted { color: var(--muted); }
        .card {
            background: linear-gradient(180deg, rgba(15, 23, 42, .95), rgba(11, 18, 32, .96));
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px;
            box-shadow: 0 18px 60px rgba(0, 0, 0, .24);
        }
        .summary {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-top: 16px;
        }
        .summary-card {
            background: rgba(17, 28, 51, .9);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px;
            min-height: 96px;
        }
        .summary-value {
            font-size: 24px;
            font-weight: 700;
            margin: 0;
        }
        .summary-label {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 13px;
        }
        .badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 5px 10px;
            border: 1px solid rgba(148, 163, 184, .18);
            background: rgba(148, 163, 184, .1);
            color: #dbe7f5;
            font-size: 12px;
            line-height: 1;
            white-space: nowrap;
        }
        .badge-ok { background: rgba(22, 163, 74, .14); border-color: rgba(22, 163, 74, .3); color: #bbf7d0; }
        .badge-warn { background: rgba(217, 119, 6, .14); border-color: rgba(217, 119, 6, .32); color: #fde68a; }
        .badge-bad { background: rgba(220, 38, 38, .14); border-color: rgba(220, 38, 38, .32); color: #fecaca; }
        .badge-info { background: rgba(56, 189, 248, .14); border-color: rgba(56, 189, 248, .3); color: #bae6fd; }
        .section {
            margin-top: 16px;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
        }
        .section-title {
            margin: 0;
            font-size: 18px;
        }
        .section-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }
        .searchbar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin: 14px 0 12px;
        }
        .searchbar input {
            width: min(100%, 460px);
            border: 1px solid var(--border);
            background: #091123;
            color: var(--text);
            border-radius: 10px;
            padding: 11px 12px;
            outline: none;
        }
        .searchbar input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .12);
        }
        .rules-list {
            display: grid;
            gap: 12px;
        }
        .table-card, .chain-card, .rule-item, .diag-item, .raw-box {
            background: rgba(17, 28, 51, .86);
            border: 1px solid var(--border);
            border-radius: 14px;
        }
        .table-card {
            padding: 14px;
        }
        .table-head, .chain-head, .rule-top, .diag-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .table-head {
            margin-bottom: 12px;
        }
        .chain-card {
            padding: 12px;
            margin-top: 12px;
        }
        .chain-body {
            margin-top: 10px;
            display: grid;
            gap: 10px;
        }
        .rule-item {
            padding: 12px;
        }
        .rule-text {
            margin: 10px 0 0;
            color: #cbd5e1;
            line-height: 1.55;
            font-size: 13px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .muted {
            color: var(--muted);
        }
        .diag-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .diag-item {
            padding: 12px 14px;
        }
        .diag-icon {
            width: 24px;
            flex: 0 0 24px;
            text-align: center;
            font-size: 14px;
        }
        .diag-text {
            flex: 1;
            font-size: 13px;
            line-height: 1.5;
            color: #dbe7f5;
        }
        .diag-item.ok .diag-icon { color: #86efac; }
        .diag-item.warn .diag-icon { color: #fde68a; }
        .diag-item.bad .diag-icon { color: #fca5a5; }
        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .meta-box {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .meta-line {
            margin-top: 6px;
            color: var(--muted);
            font-size: 13px;
        }
        details {
            border: 0;
        }
        summary {
            list-style: none;
            cursor: pointer;
        }
        summary::-webkit-details-marker {
            display: none;
        }
        .toggle-label {
            color: var(--accent);
            font-size: 13px;
        }
        .raw-box {
            margin-top: 12px;
            padding: 14px;
        }
        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            color: #cbd5e1;
            font-size: 12px;
            line-height: 1.55;
        }
        .empty-state {
            padding: 18px;
            border-radius: 14px;
            border: 1px dashed var(--border);
            color: var(--muted);
            background: rgba(17, 28, 51, .5);
        }
        .toolbar .ghost {
            background: transparent;
        }
        .toolbar .danger {
            border-color: rgba(220, 38, 38, .36);
        }
        @media (max-width: 1080px) {
            .summary {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }
        @media (max-width: 720px) {
            .page { width: min(100% - 18px, 100%); padding-top: 14px; }
            .topline { flex-direction: column; }
            .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .section-head, .table-head, .chain-head, .rule-top { flex-direction: column; }
            .searchbar { flex-direction: column; align-items: stretch; }
            .searchbar input { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topline">
            <div>
                <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
                <h1 class="title">Firewall</h1>
                <p class="subtitle">Consulte o estado atual do nftables e visualize as regras de acesso do servidor.</p>
            </div>
        </div>

        <section class="card">
            <div class="section-head" style="margin-bottom:0;">
                <div>
                    <h2 class="section-title">Firewall nftables</h2>
                    <p class="section-subtitle">Visualize tabelas, chains, políticas e regras carregadas no servidor.</p>
                </div>
                <div class="meta-box">
                    <span class="badge badge-info">nftables</span>
                    <span class="badge <?= $serviceActive ? 'badge-ok' : ($serviceKnown ? 'badge-bad' : 'badge-warn') ?>"><?= $serviceActive ? 'Ativo' : ($serviceKnown ? htmlspecialchars($serviceState) : 'Não identificado') ?></span>
                    <span class="badge"><?= $tablesCount > 0 ? $tablesCount . ' tabela(s)' : '0 tabelas' ?></span>
                    <span class="badge"><?= $chainsCount > 0 ? $chainsCount . ' chains' : '0 chains' ?></span>
                    <span class="badge"><?= $rulesCount > 0 ? $rulesCount . ' regras' : '0 regras' ?></span>
                    <span class="badge">Última verificação: <?= htmlspecialchars($lastCheck) ?></span>
                </div>
            </div>

            <div class="badges">
                <?php foreach ([
                    $serviceActive ? 'Ativo' : ($serviceKnown ? $serviceState : 'Não identificado'),
                    $tablesCount > 0 ? $tablesCount . ' tabelas' : '0 tabelas',
                    $chainsCount > 0 ? $chainsCount . ' chains' : '0 chains',
                    $rulesCount > 0 ? $rulesCount . ' regras' : '0 regras',
                ] as $label): ?>
                    <span class="badge"><?= htmlspecialchars($label) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="summary">
                <div class="summary-card">
                    <p class="summary-value">nftables</p>
                    <p class="summary-label">Stack monitorada</p>
                </div>
                <div class="summary-card">
                    <p class="summary-value"><?= $serviceActive ? 'Ativo' : ($serviceKnown ? htmlspecialchars($serviceState) : 'N/D') ?></p>
                    <p class="summary-label">Estado do serviço</p>
                </div>
                <div class="summary-card">
                    <p class="summary-value"><?= $tablesCount ?></p>
                    <p class="summary-label">Tabelas</p>
                </div>
                <div class="summary-card">
                    <p class="summary-value"><?= $chainsCount ?></p>
                    <p class="summary-label">Chains</p>
                </div>
                <div class="summary-card">
                    <p class="summary-value"><?= $rulesCount ?></p>
                    <p class="summary-label">Regras</p>
                </div>
                <div class="summary-card">
                    <p class="summary-value"><?= htmlspecialchars($lastCheck) ?></p>
                    <p class="summary-label">Última verificação</p>
                </div>
            </div>

            <div class="toolbar">
                <button type="button" class="primary" data-toggle-target="rules-panel">Mostrar regras</button>
                <button type="button" class="success" data-toggle-target="diagnostics-panel">Abrir diagnóstico</button>
                <button type="button" class="ghost" id="refresh-status">Atualizar status</button>
                <?php if ($auditoriaUrl): ?>
                    <a class="muted" href="<?= htmlspecialchars($auditoriaUrl) ?>">Ver auditoria</a>
                <?php endif; ?>
            </div>
        </section>

        <section class="section">
            <div class="card" id="diagnostics-panel" hidden>
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Diagnóstico do nftables</h2>
                        <p class="section-subtitle">Alertas informativos baseados apenas no estado atual carregado.</p>
                    </div>
                    <button type="button" class="ghost" data-toggle-target="diagnostics-panel">Ocultar diagnóstico</button>
                </div>
                <div class="diag-list">
                    <?php foreach ($diagnostics as $item): ?>
                        <?php
                            $icon = match ($item['level']) {
                                'ok' => '✓',
                                'bad' => '✕',
                                default => '⚠',
                            };
                        ?>
                        <div class="diag-item <?= htmlspecialchars($item['level']) ?>">
                            <div class="diag-icon" aria-hidden="true"><?= $icon ?></div>
                            <div class="diag-text"><?= htmlspecialchars($item['text']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="section">
            <div class="card" id="rules-panel" <?= $showRulesDefault ? '' : 'hidden' ?>>
                <div class="section-head">
                    <div>
                        <h2 class="section-title">Regras nftables</h2>
                        <p class="section-subtitle">Lista somente leitura das tabelas, chains, políticas e regras.</p>
                    </div>
                    <button type="button" class="ghost" data-toggle-target="rules-panel"><?= $showRulesDefault ? 'Ocultar regras' : 'Mostrar regras' ?></button>
                </div>

                <div class="searchbar">
                    <input type="search" id="rule-search" placeholder="Pesquisar regra, porta, chain ou ação...">
                    <span class="muted" id="search-count"><?= $rulesCount ?> regra(s)</span>
                </div>

                <?php if ($showRulesMessage): ?>
                    <div class="empty-state"><?= htmlspecialchars($showRulesMessage) ?> Verifique se o serviço nftables está instalado e ativo.</div>
                <?php endif; ?>

                <div class="rules-list" id="rules-list">
                    <?php if ($parsedTables): ?>
                        <?php foreach ($parsedTables as $table): ?>
                            <?php
                                $tableSearch = strtolower($table['family'] . ' ' . $table['name']);
                                $chainCount = count($table['chains']);
                                $tableRuleCount = 0;
                                foreach ($table['chains'] as $chain) {
                                    $tableRuleCount += count($chain['rules']);
                                }
                            ?>
                            <div class="table-card" data-table-block data-search="<?= htmlspecialchars($tableSearch) ?>">
                                <div class="table-head">
                                    <div>
                                        <div class="pill-row">
                                            <?= firewall_value_badge('table', 'table ' . $table['family'] . ' ' . $table['name']) ?>
                                            <span class="badge"><?= $chainCount ?> chain(s)</span>
                                            <span class="badge"><?= $tableRuleCount ?> regra(s)</span>
                                        </div>
                                        <div class="meta-line">table <?= htmlspecialchars($table['family']) ?> <?= htmlspecialchars($table['name']) ?></div>
                                    </div>
                                </div>

                                <?php if ($table['chains']): ?>
                                    <?php foreach ($table['chains'] as $chain): ?>
                                        <?php
                                            $meta = $chain['meta'] ?? '';
                                            $hook = firewall_chain_hook($meta);
                                            $policy = firewall_chain_policy($meta);
                                            $chainSearch = strtolower($table['family'] . ' ' . $table['name'] . ' ' . $chain['name'] . ' ' . $meta);
                                        ?>
                                        <div class="chain-card" data-chain-block data-search="<?= htmlspecialchars($chainSearch) ?>">
                                            <div class="chain-head">
                                                <div>
                                                    <div class="pill-row">
                                                        <?= firewall_value_badge('chain', 'chain ' . $chain['name']) ?>
                                                        <?php if ($hook): ?>
                                                            <?= firewall_value_badge('info', 'hook ' . $hook) ?>
                                                        <?php endif; ?>
                                                        <?php if ($policy): ?>
                                                            <span class="badge <?= firewall_badge_class($policy) ?>"><?= htmlspecialchars('policy ' . $policy) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($meta !== ''): ?>
                                                        <div class="meta-line"><?= htmlspecialchars($meta) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="muted"><?= count($chain['rules']) ?> regra(s)</div>
                                            </div>

                                            <div class="chain-body">
                                                <?php if ($chain['rules']): ?>
                                                    <?php foreach ($chain['rules'] as $rule): ?>
                                                        <?php
                                                            $source = $rule['source'] !== '' ? $rule['source'] : 'any';
                                                            $port = $rule['port'] !== '' ? 'porta ' . $rule['port'] : 'porta não identificada';
                                                        ?>
                                                        <article class="rule-item" data-rule-item data-search="<?= htmlspecialchars($rule['search']) ?>">
                                                            <div class="rule-top">
                                                                <div class="pill-row">
                                                                    <?= firewall_value_badge($rule['protocol'], $rule['protocol']) ?>
                                                                    <span class="badge <?= firewall_badge_class($rule['action']) ?>"><?= htmlspecialchars($rule['action']) ?></span>
                                                                    <span class="badge"><?= htmlspecialchars($port) ?></span>
                                                                    <span class="badge"><?= htmlspecialchars($source) ?></span>
                                                                </div>
                                                                <div class="muted">regra</div>
                                                            </div>
                                                            <div class="rule-text"><?= htmlspecialchars($rule['raw']) ?></div>
                                                        </article>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="empty-state">Nenhuma regra direta encontrada nesta chain.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="empty-state">Nenhuma chain disponível nesta tabela.</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">Nenhuma tabela foi identificada no ruleset atual.</div>
                    <?php endif; ?>
                </div>

                <details style="margin-top:12px;">
                    <summary><span class="toggle-label">Ver ruleset bruto</span></summary>
                    <div class="raw-box">
                        <pre><?= htmlspecialchars($rulesetAvailable ? $rulesetRaw : 'Sem saída disponível.') ?></pre>
                    </div>
                </details>
            </div>
        </section>
    </div>

    <script>
        (function () {
            const toggleButtons = document.querySelectorAll('[data-toggle-target]');
            toggleButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const targetId = button.getAttribute('data-toggle-target');
                    const panel = document.getElementById(targetId);
                    if (!panel) {
                        return;
                    }

                    const hidden = panel.hasAttribute('hidden');
                    if (hidden) {
                        panel.removeAttribute('hidden');
                        if (targetId === 'rules-panel') {
                            button.textContent = 'Ocultar regras';
                        }
                        if (targetId === 'diagnostics-panel') {
                            button.textContent = 'Ocultar diagnóstico';
                        }
                    } else {
                        panel.setAttribute('hidden', '');
                        if (targetId === 'rules-panel') {
                            button.textContent = 'Mostrar regras';
                        }
                        if (targetId === 'diagnostics-panel') {
                            button.textContent = 'Abrir diagnóstico';
                        }
                    }
                });
            });

            const refresh = document.getElementById('refresh-status');
            if (refresh) {
                refresh.addEventListener('click', () => {
                    window.location.reload();
                });
            }

            const search = document.getElementById('rule-search');
            const searchCount = document.getElementById('search-count');
            const ruleItems = Array.from(document.querySelectorAll('[data-rule-item]'));
            const chainBlocks = Array.from(document.querySelectorAll('[data-chain-block]'));
            const tableBlocks = Array.from(document.querySelectorAll('[data-table-block]'));

            function applyFilter() {
                const term = (search ? search.value : '').trim().toLowerCase();
                let visibleRules = 0;

                ruleItems.forEach((item) => {
                    const match = term === '' || (item.getAttribute('data-search') || '').includes(term);
                    item.hidden = !match;
                    if (match) {
                        visibleRules += 1;
                    }
                });

                chainBlocks.forEach((chain) => {
                    const visible = chain.querySelector('[data-rule-item]:not([hidden])') !== null;
                    chain.hidden = term !== '' && !visible;
                });

                tableBlocks.forEach((table) => {
                    const visible = table.querySelector('[data-chain-block]:not([hidden])') !== null;
                    table.hidden = term !== '' && !visible;
                });

                if (searchCount) {
                    searchCount.textContent = visibleRules + ' regra(s)';
                }
            }

            if (search) {
                search.addEventListener('input', applyFilter);
            }

            applyFilter();
        })();
    </script>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
