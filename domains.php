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
<html>
<head>
<meta charset="UTF-8">
<title>Gerenciar Domínios</title>

<style>

body{
    margin:0;
    font-family:Arial;
    background:#0f172a;
    color:#e2e8f0;
}

.container{
    width:700px;
    margin:50px auto;
}

.card{
    background:#020617;
    padding:25px;
    border-radius:12px;
}

h2{
    color:#38bdf8;
}

input{
    width:100%;
    padding:12px;
    margin-top:10px;
    border-radius:6px;
    border:1px solid #334155;
    background:#020617;
    color:white;
    box-sizing:border-box;
}

button{
    margin-top:15px;
    padding:12px 20px;
    background:#3b82f6;
    border:none;
    border-radius:6px;
    color:white;
    cursor:pointer;
}

button:hover{
    opacity:0.9;
}

.alert{
    padding:12px;
    border-radius:6px;
    margin-bottom:15px;
}

.error{
    background:#dc2626;
}

.ok{
    background:#16a34a;
}

a{
    color:#38bdf8;
    text-decoration:none;
}

</style>
</head>

<body>

<div class="container">

<div class="card">

<h2>🌐 Criar novo domínio</h2>

<?php if ($erro): ?>
<div id="alertaErro" class="alert error">
<?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['flash_ok'])): ?>

<div id="alertaSucesso" class="alert ok">
<?= htmlspecialchars($_SESSION['flash_ok']) ?>
</div>

<?php unset($_SESSION['flash_ok']); ?>

<?php endif; ?>

<form method="POST">
<?= csrf_field() ?>

<input name="new_domain"
placeholder="Domínio (ex: ajustefino.com.br)"
required>

<input name="ipv4"
placeholder="IPv4 do NS1 (ex: 198.50.0.242)"
required>

<input name="ipv6"
placeholder="IPv6 do NS1 (ex: 2001:abcd:5000::242)">

<input name="ipv4_ns2"
placeholder="IPv4 do NS2 (ex: 198.50.0.243)">

<input name="ipv6_ns2"
placeholder="IPv6 do NS2 (ex: 2001:abcd:5000::243)">

<h3>🌐 Reverse IPv4</h3>

<label>
<input type="checkbox"
name="create_reverse_v4"
checked
style="width:auto;">
 Criar Reverse IPv4
</label>

<input
name="rev_cidr"
placeholder="Prefixo IPv4 (ex: 198.50.0.0/22)">

<div id="ptr4_format_box" style="display:none;">
<label style="display:block;margin-top:15px;margin-bottom:6px;">
Modelo PTR IPv4
</label>

<select
id="ptr4_mode"
name="ptr4_mode"
style="
width:100%;
padding:12px;
margin-top:10px;
border-radius:6px;
border:1px solid #334155;
background:#020617;
color:white;
box-sizing:border-box;
">
<option value="host">host-10.dominio.com.br</option>
<option value="iprede">ip-192-168-0-10.dominio.com.br</option>
<option value="rede">192-168-0-10.dominio.com.br</option>
<option value="custom">Personalizado</option>
</select>

<div id="ptr4_example"
style="
margin-top:8px;
color:#94a3b8;
font-size:13px;
line-height:1.5;
">
Exemplo para o IP .10:<br>
<strong>host-10.dominio.com.br</strong>
</div>

<input
type="hidden"
id="ptr4_template"
name="ptr4_template"
value="host-$">

<input
id="ptr4_custom"
placeholder="Template personalizado (ex: cliente-$)"
style="display:none;">
</div>

<div id="preview-reverse"
style="
margin-top:10px;
padding:10px;
background:#071226;
border-radius:6px;
border:1px solid #1e293b;
display:none;
">
</div>

<h3>🌐 Reverse IPv6</h3>

<label>
<input type="checkbox"
name="create_reverse_v6"
checked
style="width:auto;">
 Criar Reverse IPv6
</label>

<input
name="ipv6_prefix"
placeholder="Prefixo IPv6 (ex: 2001:db8::/32)">

<button name="create_domain">
Criar domínio
</button>

</form>

<br>

<a href="dashboard.php">← Voltar ao painel DNS</a>
<hr style="margin:30px 0;border-color:#1e293b;">

<h3>🌐 Domínios cadastrados</h3>

<table style="width:100%;margin-top:15px;">

<?php foreach ($domains as $d => $path): ?>

<tr>

<td><?= htmlspecialchars($d) ?></td>

<td style="text-align:right;">

<form method="POST"
onsubmit="return confirm('Deseja remover este domínio?');">
<?= csrf_field() ?>

<input type="hidden"
name="delete_domain"
value="<?= htmlspecialchars($d) ?>">

<button style="
background:#ef4444;
border:none;
padding:8px 12px;
border-radius:6px;
color:white;
">
Excluir
</button>

</form>

</td>

</tr>
<?php endforeach; ?>

</table>
</div>

</div>
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

const revInput = document.querySelector('[name="rev_cidr"]');
const preview = document.getElementById('preview-reverse');

if (revInput) {

    revInput.addEventListener('input', function() {

        let cidr = this.value.trim();

        preview.style.display = 'none';

        if (!cidr.includes('/')) {
            return;
        }

        let parts = cidr.split('/');

        let network = parts[0];
        let mask = parseInt(parts[1]);

        let octets = network.split('.');

        if (octets.length !== 4) {
            return;
        }

        if (mask < 16 || mask > 24) {
            return;
        }

        let base1 = octets[0];
        let base2 = octets[1];
        let base3 = parseInt(octets[2]);

        let total = Math.pow(2, 24 - mask);

let html =
    '<b>Quantidade de zonas reversas a serem criadas:</b> ' +
    total;

        preview.innerHTML = html;
        preview.style.display = 'block';

    });
}

const domainInput = document.querySelector('[name="new_domain"]');
const rev4Check = document.querySelector('[name="create_reverse_v4"]');
const ptrBox = document.getElementById('ptr4_format_box');

const ptrMode = document.getElementById('ptr4_mode');
const ptrTemplate = document.getElementById('ptr4_template');
const ptrCustom = document.getElementById('ptr4_custom');
const ptrExample = document.getElementById('ptr4_example');

function updatePtrTemplate() {
    if (!ptrMode || !ptrTemplate || !revInput) return;

    const domain = domainInput ? domainInput.value.trim() : '';
    const displayDomain = domain || 'dominio.com.br';
    const rev4Enabled = rev4Check ? rev4Check.checked : false;

if (!domain || !rev4Enabled || !revInput.value.includes('/')) {
    if (ptrBox) ptrBox.style.display = 'none';
    if (preview) preview.style.display = 'none';
    return;
}

if (ptrBox) ptrBox.style.display = 'block';

    const cidr = revInput.value.trim();
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
    ptrExample.innerHTML =
        'Exemplo para o IP .10:<br><strong>' +
        exampleHost +
        '</strong>';
}

if (ptrMode && ptrMode.options.length >= 3) {

    ptrMode.options[0].text =
        'host-10.' + displayDomain;

    ptrMode.options[1].text =
        prefix
        ? 'ip-' + prefix + '-10.' + displayDomain
        : 'ip-192-168-0-10.' + displayDomain;

    ptrMode.options[2].text =
        prefix
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

if (ptrMode) {
    ptrMode.addEventListener('change', updatePtrTemplate);
}

if (ptrCustom) {
    ptrCustom.addEventListener('input', updatePtrTemplate);
}

if (revInput) {
    revInput.addEventListener('input', updatePtrTemplate);
}

if (domainInput) {
    domainInput.addEventListener('input', updatePtrTemplate);
}

if (rev4Check) {
    rev4Check.addEventListener('change', updatePtrTemplate);
}
updatePtrTemplate();
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
