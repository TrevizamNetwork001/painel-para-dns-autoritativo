<?php
require "config.php";
require "includes/auth.php";
require "includes/security.php";
require "includes/audit.php";


$erro = null;
$sucesso = null;

if (isset($_POST['delete_domain'])) {
    require_csrf();

    $dom = strtolower(trim($_POST['delete_domain'] ?? ''));

    if (!valid_domain($dom) || !isset($domains[$dom])) {
        $erro = "Domínio inválido.";
    } else {
        $zonefile = $domains[$dom];
        $relatedFiles = [$zonefile];

        foreach (glob("/var/cache/bind/master-rev/*") ?: [] as $reverseFile) {
            $content = file_get_contents($reverseFile);
            if ($content !== false && preg_match('/\b' . preg_quote($dom, '/') . '\.?\b/i', $content)) {
                $relatedFiles[] = $reverseFile;
            }
        }

        $confFile = "/etc/bind/named.conf.local";
        $conf = file_get_contents($confFile);

        if ($conf === false) {
            $erro = "Não foi possível ler named.conf.local.";
        } else {
            $originalConf = $conf;
            foreach ($relatedFiles as $relatedFile) {
                $pattern = '/^\s*zone\s+"[^"]+"\s*\{(?:(?!^\s*\};).)*\bfile\s+"' .
                    preg_quote($relatedFile, '/') . '"\s*;(?:(?!^\s*\};).)*^\s*\};\s*/ms';
                $conf = preg_replace($pattern, '', $conf);
            }

            if (!write_file_safely($confFile, $conf)) {
                $erro = "Não foi possível atualizar named.conf.local.";
            } else {
                exec('/usr/bin/named-checkconf 2>&1', $checkOutput, $checkStatus);
                if ($checkStatus !== 0) {
                    write_file_safely($confFile, $originalConf);
                    $erro = "A configuração resultante do BIND é inválida.";
                } else {
                    foreach ($relatedFiles as $relatedFile) {
                        if (is_file($relatedFile)) {
                            unlink($relatedFile);
                        }
                    }

                    reload_dns();

                    foreach ($relatedFiles as $relatedFile) {
                        $basename = basename($relatedFile);
                        $acaoZona = 'REMOVER_ZONA_FORWARD';
                        $tipoZona = 'Forward';

                        if (str_ends_with($basename, '.rev6')) {
                            $acaoZona = 'REMOVER_ZONA_REVERSA_IPV6';
                            $tipoZona = 'Reversa IPv6';
                        } elseif (str_ends_with($basename, '.rev')) {
                            $acaoZona = 'REMOVER_ZONA_REVERSA_IPV4';
                            $tipoZona = 'Reversa IPv4';
                        }

                        registrar_auditoria([
                            'acao' => $acaoZona,
                            'dominio' => $dom,
                            'tipo_registro' => 'ZONA',
                            'nome_registro' => $tipoZona,
                            'valor_antigo' => $basename,
                            'valor_novo' => 'removido',
                            'status' => 'OK',
                            'mensagem' => "Zona {$tipoZona} removida",
                        ]);
                    }

                    registrar_auditoria([
                        'acao' => 'REMOVER_DOMINIO',
                        'dominio' => $dom,
                        'tipo_registro' => 'DOMINIO',
                        'nome_registro' => 'domínio',
                        'valor_antigo' => 'Domínio existente com zonas DNS configuradas',
                        'valor_novo' => 'removido',
                        'status' => 'OK',
                        'mensagem' => 'Domínio removido com sucesso',
                    ]);

                    $_SESSION['flash_ok'] = "Domínio removido!";
                    header("Location: domains.php");
                    exit;
                }
            }
        }
    }

    if ($erro !== null) {
        registrar_auditoria([
            'acao' => 'ERRO_REMOVER_DOMINIO',
            'dominio' => $dom !== '' ? $dom : null,
            'tipo_registro' => 'DOMINIO',
            'nome_registro' => 'domínio',
            'status' => 'ERRO',
            'mensagem' => strip_tags($erro),
        ]);
    }
}

if (isset($_POST['create_domain'])) {
    require_csrf();

    $dom = strtolower(trim($_POST['new_domain'] ?? ''));
    $ip4 = trim($_POST['ipv4'] ?? '');
    $ip6 = trim($_POST['ipv6'] ?? '');

    $ip4_ns2 = trim($_POST['ipv4_ns2'] ?? '');
    $ip6_ns2 = trim($_POST['ipv6_ns2'] ?? '');
    $rev_cidr = trim($_POST['rev_cidr'] ?? '');
    $ipv6_prefix = trim($_POST['ipv6_prefix'] ?? '');

    $ptr4_template = trim($_POST['ptr4_template'] ?? 'host-$');

    if ($ptr4_template === '') {
    $ptr4_template = 'host-$';
}

$rev6 = '';


if (
    isset($_POST['create_reverse_v6']) &&
    !empty($ipv6_prefix)
) {

    $rev6 = $ipv6_prefix;
}

$rev_list = [];

if (isset($_POST['create_reverse_v4']) && !empty($rev_cidr)) {

    $cidr = explode('/', $rev_cidr);

    $network = $cidr[0] ?? '';
    $mask = intval($cidr[1] ?? 0);

    if (
        filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && $mask <= 24
        && $mask >= 16
    ) {

        $base = ip2long($network);

        $numBlocks = pow(2, 24 - $mask);

        for ($i = 0; $i < $numBlocks; $i++) {

            $ip = long2ip($base + ($i * 256));

            $parts = explode('.', $ip);

            $rev_list[] =
                $parts[0] . "." .
                $parts[1] . "." .
                $parts[2];
        }
    }
}

$rev = implode(';', $rev_list);


if (empty($dom) || empty($ip4)) {

    $erro = "Preencha domínio e IPv4!";

} elseif (!valid_domain($dom)) {

    $erro = "Domínio inválido!";

} elseif (!filter_var($ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

    $erro = "IPv4 do NS1 inválido!";

} elseif (!empty($ip6) &&
    !filter_var($ip6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {

    $erro = "IPv6 do NS1 inválido!";

} elseif (!empty($ip4_ns2) &&
    !filter_var($ip4_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

    $erro = "IPv4 do NS2 inválido!";

} elseif (!empty($ip6_ns2) &&
    !filter_var($ip6_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {

    $erro = "IPv6 do NS2 inválido!";

} elseif (!empty($rev_cidr) &&
    (!str_contains($rev_cidr, '/') ||
    !valid_cidr($rev_cidr, FILTER_FLAG_IPV4) ||
    (int) explode('/', $rev_cidr, 2)[1] < 16 ||
    (int) explode('/', $rev_cidr, 2)[1] > 24)) {

    $erro = "Prefixo reverso IPv4 inválido!";

} elseif (!empty($rev_cidr) && (function (string $cidr): bool {
    [$address, $prefix] = explode('/', $cidr, 2);
    $octets = array_map('intval', explode('.', $address));
    $blocks = 2 ** (24 - (int) $prefix);
    return $octets[3] !== 0 || $octets[2] % $blocks !== 0;
})($rev_cidr)) {

    $erro = "O IPv4 deve ser o endereço inicial da rede informada.";

} elseif (!empty($rev6) &&
    (!str_contains($rev6, '/') ||
    !valid_cidr($rev6, FILTER_FLAG_IPV6) ||
    (int) explode('/', $rev6, 2)[1] % 4 !== 0)) {

    $erro = "Prefixo reverso IPv6 inválido; use máscara múltipla de 4.";

} else {
if (file_exists("/var/cache/bind/master-aut/$dom.hosts")) {

    $erro = "Domínio já cadastrado.";

}
else {

        exec(
            "sudo /usr/local/bin/add-domain-full.sh "
            . escapeshellarg($dom) . " "
            . escapeshellarg($ip4) . " "
            . escapeshellarg($ip6) . " "
            . escapeshellarg($ip4_ns2) . " "
            . escapeshellarg($ip6_ns2) . " "
            . escapeshellarg($rev) . " "
            . escapeshellarg($rev6) . " "
            . escapeshellarg($ptr4_template)
            . " 2>&1",
            $out,
            $ret
        );

        if ($ret !== 0) {

            $erro = implode("<br>", $out);

        } else {

            $zonasCriadas = ['Forward'];

            registrar_auditoria([
                'acao' => 'CRIAR_ZONA_FORWARD',
                'dominio' => $dom,
                'tipo_registro' => 'ZONA',
                'nome_registro' => 'Forward',
                'valor_novo' => "{$dom}.hosts",
                'status' => 'OK',
                'mensagem' => 'Zona forward criada',
            ]);

            if ($rev !== '') {
                $zonasCriadas[] = 'Rev4';
                registrar_auditoria([
                    'acao' => 'CRIAR_ZONA_REVERSA_IPV4',
                    'dominio' => $dom,
                    'tipo_registro' => 'ZONA',
                    'nome_registro' => 'Reversa IPv4',
                    'valor_novo' => $rev_cidr,
                    'status' => 'OK',
                    'mensagem' => 'Zona reversa IPv4 criada',
                ]);
            }

            if ($rev6 !== '') {
                $zonasCriadas[] = 'Rev6';
                registrar_auditoria([
                    'acao' => 'CRIAR_ZONA_REVERSA_IPV6',
                    'dominio' => $dom,
                    'tipo_registro' => 'ZONA',
                    'nome_registro' => 'Reversa IPv6',
                    'valor_novo' => $rev6,
                    'status' => 'OK',
                    'mensagem' => 'Zona reversa IPv6 criada',
                ]);
            }

            registrar_auditoria([
                'acao' => 'CRIAR_DOMINIO',
                'dominio' => $dom,
                'tipo_registro' => 'DOMINIO',
                'nome_registro' => 'domínio',
                'valor_novo' => implode(' + ', $zonasCriadas),
                'status' => 'OK',
                'mensagem' => 'Domínio criado com sucesso',
            ]);

            $_SESSION['flash_ok'] = "Domínio criado com sucesso!";
            header("Location: domains.php");
            exit;
        }
    }
}

    if ($erro !== null) {
        registrar_auditoria([
            'acao' => 'ERRO_CRIAR_DOMINIO',
            'dominio' => $dom !== '' ? $dom : null,
            'tipo_registro' => 'DOMINIO',
            'nome_registro' => 'domínio',
            'status' => 'ERRO',
            'mensagem' => strip_tags(str_replace('<br>', "\n", $erro)),
        ]);
    }
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Domínios DNS</title>
<style>
:root{
    --bg:#0f172a;
    --panel:#020617;
    --panel-2:#071226;
    --line:#1e293b;
    --line-soft:#334155;
    --text:#e2e8f0;
    --muted:#94a3b8;
    --accent:#38bdf8;
    --accent-2:#3b82f6;
    --success:#16a34a;
    --danger:#ef4444;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:
        radial-gradient(circle at top left, rgba(56,189,248,.08), transparent 30%),
        radial-gradient(circle at top right, rgba(59,130,246,.08), transparent 25%),
        var(--bg);
    color:var(--text);
}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.page-shell{
    width:min(1180px, calc(100% - 32px));
    margin:32px auto 44px;
}
.page-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:16px;
    margin-bottom:18px;
}
.page-header h1{
    margin:8px 0 6px;
    font-size:32px;
    color:#fff;
    letter-spacing:-.02em;
}
.page-header p{
    margin:0;
    color:var(--muted);
    line-height:1.5;
}
.back-link{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#cbd5e1;
    font-size:14px;
}
.layout-grid{
    display:grid;
    grid-template-columns:minmax(0, 1.3fr) minmax(300px, .9fr);
    gap:18px;
}
.card{
    background:rgba(2,6,23,.96);
    border:1px solid rgba(30,41,59,.95);
    border-radius:16px;
    box-shadow:0 16px 40px rgba(0,0,0,.18);
}
.card-inner{padding:22px}
.card-head{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:12px;
    margin-bottom:18px;
}
.card-head h2{
    margin:0 0 4px;
    font-size:20px;
    color:#fff;
}
.card-head p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    line-height:1.45;
}
.pill-row{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    justify-content:flex-end;
}
.pill{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border:1px solid var(--line-soft);
    border-radius:999px;
    background:rgba(15,23,42,.9);
    color:#cbd5e1;
    font-size:12px;
    letter-spacing:.02em;
}
.pill.accent{
    border-color:rgba(56,189,248,.35);
    color:#bae6fd;
}
.pill.muted{
    color:#9ca3af;
}
.stack{
    display:grid;
    gap:18px;
}
.form-grid{
    display:grid;
    gap:16px;
}
.field-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
}
.field-grid .wide{grid-column:1 / -1}
.field label,
.option-title{
    display:block;
    margin-bottom:7px;
    color:#cbd5e1;
    font-size:14px;
}
input,
select{
    width:100%;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:#020617;
    color:#fff;
    padding:12px 13px;
    font:inherit;
    outline:none;
}
input:focus,
select:focus{
    border-color:rgba(56,189,248,.7);
    box-shadow:0 0 0 3px rgba(56,189,248,.12);
}
.section{
    border:1px solid var(--line);
    border-radius:14px;
    background:rgba(7,18,38,.66);
    padding:16px;
}
.section + .section{margin-top:0}
.section-title{
    margin:0 0 12px;
    color:#fff;
    font-size:15px;
    font-weight:700;
}
.option-grid{
    display:grid;
    gap:12px;
    grid-template-columns:repeat(2, minmax(0, 1fr));
}
.toggle{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:12px 12px;
    border:1px solid var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.8);
    color:#dbeafe;
    font-size:14px;
    line-height:1.4;
}
.toggle input{
    width:auto;
    margin-top:2px;
    accent-color:var(--accent-2);
}
.helper{
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
.compact-row{
    display:grid;
    grid-template-columns:1fr 120px;
    gap:12px;
}
.mini-note{
    margin-top:10px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
.ptr-box{
    margin-top:12px;
    padding:14px;
    border:1px solid var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.85);
}
.ptr-example{
    margin-top:10px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
.preview{
    margin-top:10px;
    padding:10px 12px;
    border:1px solid rgba(30,41,59,.9);
    border-radius:10px;
    background:#071226;
    color:#bfdbfe;
    font-size:13px;
    line-height:1.45;
}
.form-actions{
    display:flex;
    justify-content:flex-start;
    gap:10px;
    margin-top:4px;
}
button,
.action-link,
.danger-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:10px 14px;
    border:0;
    border-radius:10px;
    font:inherit;
    cursor:pointer;
}
button{
    background:var(--accent-2);
    color:#fff;
}
button:hover{opacity:.95}
.action-link{
    background:rgba(30,41,59,.9);
    color:#dbeafe;
    text-decoration:none;
    border:1px solid var(--line-soft);
}
.action-link:hover{text-decoration:none;border-color:rgba(56,189,248,.45)}
.danger-button{
    background:rgba(127,29,29,.95);
    color:#fecaca;
    border:1px solid rgba(239,68,68,.25);
    min-height:36px;
    padding:8px 12px;
    font-size:13px;
}
.danger-button:hover{opacity:.95}
.alerts{
    display:grid;
    gap:10px;
    margin-bottom:16px;
}
.alert{
    padding:12px 14px;
    border-radius:12px;
    border:1px solid transparent;
}
.alert.error{
    background:rgba(127,29,29,.9);
    border-color:rgba(239,68,68,.25);
    color:#fecaca;
}
.alert.ok{
    background:rgba(20,83,45,.9);
    border-color:rgba(34,197,94,.25);
    color:#bbf7d0;
}
.search-wrap{margin-bottom:14px}
.search-wrap input{
    padding:11px 13px;
}
.domain-list{
    display:grid;
    gap:10px;
}
.domain-item{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:14px 15px;
    border:1px solid var(--line);
    border-radius:12px;
    background:rgba(7,18,38,.66);
}
.domain-name{
    font-weight:700;
    color:#fff;
    word-break:break-word;
}
.domain-sub{
    margin-top:3px;
    color:var(--muted);
    font-size:12px;
}
.domain-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.domain-actions .action-link,
.domain-actions .danger-button{
    min-height:34px;
    padding:8px 12px;
    font-size:13px;
}
.empty-state{
    padding:18px 16px;
    border:1px dashed var(--line-soft);
    border-radius:12px;
    color:var(--muted);
    background:rgba(2,6,23,.65);
}
@media (max-width: 960px){
    .layout-grid{grid-template-columns:1fr}
}
@media (max-width: 680px){
    .page-shell{width:min(100% - 20px, 1180px);margin:20px auto 30px}
    .page-header{flex-direction:column}
    .field-grid,
    .option-grid,
    .compact-row{grid-template-columns:1fr}
    .domain-item{flex-direction:column;align-items:flex-start}
    .domain-actions{justify-content:flex-start}
}
</style>
</head>
<body>
<main class="page-shell">
    <header class="page-header">
        <div>
            <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
            <h1>Domínios DNS</h1>
            <p>Criar domínio DNS forward e zonas reversas associadas.</p>
        </div>
    </header>

    <div class="alerts">
        <?php if ($erro): ?>
            <div id="alertaErro" class="alert error"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_ok'])): ?>
            <div id="alertaSucesso" class="alert ok"><?= htmlspecialchars($_SESSION['flash_ok']) ?></div>
            <?php unset($_SESSION['flash_ok']); ?>
        <?php endif; ?>
    </div>

    <div class="layout-grid">
        <section class="card">
            <div class="card-inner">
                <div class="card-head">
                    <div>
                        <h2>Criar novo domínio</h2>
                        <p>Preencha o domínio e os dados de rede para gerar a zona forward e as reversas associadas.</p>
                    </div>
                    <div class="pill-row">
                        <span class="pill accent">Forward</span>
                        <span class="pill">NS automáticos</span>
                        <span class="pill muted">Reversas opcionais</span>
                    </div>
                </div>

                <form method="POST" class="form-grid">
                    <?= csrf_field() ?>

                    <div class="field-grid">
                        <div class="field wide">
                            <label>Domínio</label>
                            <input name="new_domain" placeholder="ajustefino.com.br" required>
                        </div>

                        <div class="field">
                            <label>IPv4 do NS1</label>
                            <input name="ipv4" placeholder="198.50.0.242" required>
                        </div>

                        <div class="field">
                            <label>IPv6 do NS1</label>
                            <input name="ipv6" placeholder="2001:abcd:5000::242">
                        </div>

                        <div class="field">
                            <label>IPv4 do NS2</label>
                            <input name="ipv4_ns2" placeholder="198.50.0.243">
                        </div>

                        <div class="field">
                            <label>IPv6 do NS2</label>
                            <input name="ipv6_ns2" placeholder="2001:abcd:5000::243">
                        </div>
                    </div>

                    <div class="section">
                        <div class="section-title">Opções</div>
                        <div class="option-grid">
                            <label class="toggle">
                                <input type="checkbox" name="create_reverse_v4" checked>
                                <span>Criar reversa IPv4</span>
                            </label>

                            <label class="toggle">
                                <input type="checkbox" name="create_reverse_v6" checked>
                                <span>Criar reversa IPv6</span>
                            </label>
                        </div>
                        <div class="mini-note">
                            A zona forward e os registros NS continuam sendo gerados pela lógica atual do script.
                        </div>
                    </div>

                    <div class="section" id="reverse-v4-section">
                        <div class="section-title">Reversa IPv4</div>
                        <div class="compact-row">
                            <div class="field">
                                <label>Rede IPv4</label>
                                <input name="rev_ipv4_network" placeholder="198.50.0.0">
                            </div>
                            <div class="field">
                                <label>Máscara IPv4</label>
                                <input name="rev_ipv4_mask" type="number" min="16" max="24" step="1" placeholder="22">
                            </div>
                        </div>
                        <input type="hidden" name="rev_cidr">

                        <div id="ptr4_format_box" class="ptr-box" style="display:none;">
                            <div class="field">
                                <label>Modelo PTR IPv4</label>
                                <select id="ptr4_mode" name="ptr4_mode">
                                    <option value="host">host-10.dominio.com.br</option>
                                    <option value="iprede">ip-192-168-0-10.dominio.com.br</option>
                                    <option value="rede">192-168-0-10.dominio.com.br</option>
                                    <option value="custom">Personalizado</option>
                                </select>
                            </div>

                            <div id="ptr4_example" class="ptr-example">
                                Exemplo para o IP .10:<br>
                                <strong>host-10.dominio.com.br</strong>
                            </div>

                            <input type="hidden" id="ptr4_template" name="ptr4_template" value="host-$">

                            <div class="field" style="margin-top:12px;">
                                <label>Template personalizado</label>
                                <input id="ptr4_custom" placeholder="cliente-$" style="display:none;">
                            </div>
                        </div>

                        <div id="preview-reverse" class="preview" style="display:none;"></div>
                    </div>

                    <div class="section" id="reverse-v6-section">
                        <div class="section-title">Reversa IPv6</div>
                        <div class="compact-row">
                            <div class="field">
                                <label>Rede IPv6</label>
                                <input name="rev_ipv6_network" placeholder="2001:db8::">
                            </div>
                            <div class="field">
                                <label>Prefixo IPv6</label>
                                <input name="rev_ipv6_mask" type="number" min="0" max="128" step="4" placeholder="32">
                            </div>
                        </div>
                        <input type="hidden" name="ipv6_prefix">
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="create_domain">Criar domínio</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-inner">
                <div class="card-head">
                    <div>
                        <h2>Domínios existentes</h2>
                        <p>Lista compacta com acesso rápido para edição e histórico.</p>
                    </div>
                </div>

                <div class="search-wrap">
                    <input type="search" placeholder="Pesquisar domínio..." data-domain-search>
                </div>

                <div class="domain-list" data-domain-list>
                    <?php if (empty($domains)): ?>
                        <div class="empty-state">Nenhum domínio cadastrado ainda.</div>
                    <?php else: ?>
                        <?php foreach ($domains as $d => $path): ?>
                            <article class="domain-item" data-domain-item data-domain-name="<?= htmlspecialchars(strtolower($d), ENT_QUOTES, 'UTF-8') ?>">
                                <div>
                                    <div class="domain-name"><?= htmlspecialchars($d) ?></div>
                                    <div class="domain-sub">Zona forward</div>
                                </div>
                                <div class="domain-actions">
                                    <a class="action-link" href="edit-zone.php?zone=<?= rawurlencode($d) ?>">Editar zona</a>
                                    <a class="action-link" href="historico-zona.php?zone=<?= rawurlencode($d) ?>">Histórico</a>
                                    <form method="POST" onsubmit="return confirm('Deseja remover este domínio?');" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_domain" value="<?= htmlspecialchars($d) ?>">
                                        <button class="danger-button" type="submit">Excluir</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</main>

<script>
setTimeout(function() {
    let erro = document.getElementById("alertaErro");
    let sucesso = document.getElementById("alertaSucesso");

    [erro, sucesso].forEach(function(el) {
        if (el) {
            el.style.transition = "opacity 0.5s";
            el.style.opacity = "0";
            setTimeout(function() {
                el.remove();
            }, 500);
        }
    });
}, 3000);
</script>

<script>
const domainSearch = document.querySelector('[data-domain-search]');
const domainItems = [...document.querySelectorAll('[data-domain-item]')];

domainSearch?.addEventListener('input', event => {
    const term = String(event.target.value || '').trim().toLowerCase();
    domainItems.forEach(item => {
        const name = String(item.dataset.domainName || '');
        item.hidden = term !== '' && !name.includes(term);
    });
});

const revV4Section = document.getElementById('reverse-v4-section');
const revV6Section = document.getElementById('reverse-v6-section');
const rev4Check = document.querySelector('[name="create_reverse_v4"]');
const rev6Check = document.querySelector('[name="create_reverse_v6"]');

const rev4Network = document.querySelector('[name="rev_ipv4_network"]');
const rev4Mask = document.querySelector('[name="rev_ipv4_mask"]');
const rev6Network = document.querySelector('[name="rev_ipv6_network"]');
const rev6Mask = document.querySelector('[name="rev_ipv6_mask"]');

const revInput = document.querySelector('[name="rev_cidr"]');
const rev6Input = document.querySelector('[name="ipv6_prefix"]');
const preview = document.getElementById('preview-reverse');

const domainInput = document.querySelector('[name="new_domain"]');
const ptrBox = document.getElementById('ptr4_format_box');
const ptrMode = document.getElementById('ptr4_mode');
const ptrTemplate = document.getElementById('ptr4_template');
const ptrCustom = document.getElementById('ptr4_custom');
const ptrExample = document.getElementById('ptr4_example');

function syncHiddenReverses() {
    const network4 = (rev4Network?.value || '').trim();
    const mask4 = (rev4Mask?.value || '').trim();
    const network6 = (rev6Network?.value || '').trim();
    const mask6 = (rev6Mask?.value || '').trim();

    if (revInput) {
        revInput.value = network4 && mask4 ? `${network4}/${mask4}` : '';
    }

    if (rev6Input) {
        rev6Input.value = network6 && mask6 ? `${network6}/${mask6}` : '';
    }
}

function renderReversePreview() {
    syncHiddenReverses();

    if (!preview || !rev4Check || !rev4Check.checked) {
        if (preview) preview.style.display = 'none';
        return;
    }

    const cidr = (revInput?.value || '').trim();
    if (!cidr.includes('/')) {
        preview.style.display = 'none';
        return;
    }

    const parts = cidr.split('/');
    const network = parts[0];
    const mask = parseInt(parts[1], 10);
    const octets = network.split('.');

    if (octets.length !== 4 || Number.isNaN(mask) || mask < 16 || mask > 24) {
        preview.style.display = 'none';
        return;
    }

    const total = Math.pow(2, 24 - mask);
    preview.innerHTML = '<b>Quantidade de zonas reversas a serem criadas:</b> ' + total;
    preview.style.display = 'block';
}

function updatePtrTemplate() {
    if (!ptrMode || !ptrTemplate || !revInput || !rev4Check) return;

    const domain = domainInput ? domainInput.value.trim() : '';
    const displayDomain = domain || 'dominio.com.br';
    const rev4Enabled = rev4Check.checked;
    const cidr = revInput.value.trim();

    if (!domain || !rev4Enabled || !cidr.includes('/')) {
        if (ptrBox) ptrBox.style.display = 'none';
        if (preview) preview.style.display = 'none';
        return;
    }

    if (ptrBox) ptrBox.style.display = 'block';

    const network = cidr.split('/')[0] || '';
    const octets = network.split('.');
    let prefix = '';

    if (octets.length === 4) {
        prefix = octets[0] + '-' + octets[1] + '-' + octets[2];
    }

    let exampleHost = 'host-10.' + displayDomain;

    if (ptrMode.value === 'iprede') {
        exampleHost = 'ip-' + prefix + '-10.' + displayDomain;
    }

    if (ptrMode.value === 'rede') {
        exampleHost = prefix + '-10.' + displayDomain;
    }

    if (ptrMode.value === 'custom') {
        let custom = ptrCustom.value.trim() || 'host-$';
        exampleHost = custom.replace('$', '10') + '.' + displayDomain;
    }

    if (ptrExample) {
        ptrExample.innerHTML = 'Exemplo para o IP .10:<br><strong>' + exampleHost + '</strong>';
    }

    if (ptrMode && ptrMode.options.length >= 3) {
        ptrMode.options[0].text = 'host-10.' + displayDomain;
        ptrMode.options[1].text = prefix
            ? 'ip-' + prefix + '-10.' + displayDomain
            : 'ip-192-168-0-10.' + displayDomain;
        ptrMode.options[2].text = prefix
            ? prefix + '-10.' + displayDomain
            : '192-168-0-10.' + displayDomain;
    }

    if (ptrMode.value === 'host') {
        ptrTemplate.value = 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'iprede') {
        ptrTemplate.value = prefix ? 'ip-' + prefix + '-$' : 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'rede') {
        ptrTemplate.value = prefix ? prefix + '-$' : 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'custom') {
        ptrCustom.style.display = 'block';
        ptrTemplate.value = ptrCustom.value.trim() || 'host-$';
    }
}

function updateSectionsVisibility() {
    const v4Enabled = !rev4Check || rev4Check.checked;
    const v6Enabled = !rev6Check || rev6Check.checked;

    if (revV4Section) {
        revV4Section.querySelectorAll('input, select').forEach(el => {
            if (el !== rev4Check) {
                el.disabled = !v4Enabled;
            }
        });
        revV4Section.style.opacity = v4Enabled ? '1' : '.58';
    }

    if (revV6Section) {
        revV6Section.querySelectorAll('input, select').forEach(el => {
            if (el !== rev6Check) {
                el.disabled = !v6Enabled;
            }
        });
        revV6Section.style.opacity = v6Enabled ? '1' : '.58';
    }

    if (ptrBox) {
        ptrBox.style.display = v4Enabled ? '' : 'none';
    }

    renderReversePreview();
    updatePtrTemplate();
}

[
    rev4Network,
    rev4Mask,
    rev6Network,
    rev6Mask,
    domainInput
].forEach(input => {
    input?.addEventListener('input', () => {
        syncHiddenReverses();
        renderReversePreview();
        updatePtrTemplate();
    });
});

rev4Check?.addEventListener('change', () => {
    syncHiddenReverses();
    updateSectionsVisibility();
});

rev6Check?.addEventListener('change', () => {
    updateSectionsVisibility();
});

if (ptrMode) {
    ptrMode.addEventListener('change', updatePtrTemplate);
}

if (ptrCustom) {
    ptrCustom.addEventListener('input', updatePtrTemplate);
}

syncHiddenReverses();
updateSectionsVisibility();
updatePtrTemplate();

const form = document.querySelector('form[method="POST"]');
form?.addEventListener('submit', () => {
    syncHiddenReverses();
    updatePtrTemplate();
});
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
