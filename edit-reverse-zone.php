<?php

require "config.php";
require "app/Auth/auth.php";
require "app/Support/security.php";
require "app/Audit/audit.php";

function ipv6_to_ptr_full($ipv6) {
    $bin = inet_pton($ipv6);

    if ($bin === false)
        return false;

    $hex = unpack("H*", $bin)[1];

    return implode(
        '.',
        array_reverse(
            str_split($hex)
        )
    );
}

function ptr_ipv6_exists($file, $ipv6) {
    $zoneName = bind_zone_name_for_file($file);
    $ptr = $zoneName ? ipv6_ptr_owner($ipv6, $zoneName) : null;
    if ($ptr === null) {
        return false;
    }

    foreach (file($file) as $line) {

        $line = trim($line);

        if (
            stripos(
                $line,
                $ptr . " IN PTR"
            ) === 0
        ) {
            return true;
        }
    }

    return false;
}

function atualizarSerial($file)
{
    $content = file_get_contents($file);

    if (preg_match('/^\s*(\d{10})\s*$/m', $content, $match)) {

        $serialAntigo = $match[1];
        $serialNovo = $serialAntigo + 1;

        $content = preg_replace(
            '/^\s*' . preg_quote($serialAntigo, '/') . '\s*$/m',
            $serialNovo,
            $content,
            1
        );

        file_put_contents($file, $content);
    }
}


if (!isset($_GET['zone'])) {

    die("Zona inválida");
}

$zone = basename($_GET['zone']);

if (!preg_match('/^[a-zA-Z0-9.-]+\.(?:rev6|rev)$/', $zone)) {
    die("Zona inválida");
}

$file = "/var/cache/bind/master-rev/$zone";
$erro = "";
$sucesso = "";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sucesso = $_GET['success'] ?? '';
}

if (!file_exists($file)) {

    die("Arquivo da zona não encontrado");
}

$bindZone = bind_zone_name_for_file($file);
$isIpv6Zone = str_ends_with($zone, '.rev6');

if ($bindZone === null) {
    die("Zona não encontrada no named.conf.local");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();

    $address = trim($_POST['host'] ?? '');
    $hostname = trim($_POST['value'] ?? '');

    if ($address && $hostname) {
        $erro = "";

        $flag = $isIpv6Zone ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;
        $label = $isIpv6Zone ? 'IPv6' : 'IPv4';

        if (!filter_var($address, FILTER_VALIDATE_IP, $flag)) {
            $erro = "{$label} inválido";
        } elseif (!valid_hostname($hostname)) {
            $erro = "Hostname inválido";
        }

        $ptr = $isIpv6Zone
            ? ipv6_ptr_owner($address, $bindZone)
            : ipv4_ptr_owner($address, $bindZone);

        if (!$erro && $ptr === null) {
            $erro = "O endereço não pertence ao prefixo desta zona.";
        }

        if (!$erro && !str_contains($hostname, '.')) {
            $zoneContent = file_get_contents($file);
            if (preg_match('/SOA\s+ns1\.([^\s.]+(?:\.[^\s.]+)+)\./i', $zoneContent, $match)) {
                $hostname .= '.' . $match[1];
            }
        }

        $host = rtrim($hostname, '.') . '.';
        $newrecord = $ptr . " IN PTR " . $host;

        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $clean = preg_replace('/\s+/', ' ', trim($line));
            if (str_starts_with(strtolower($clean), strtolower($ptr . " IN PTR "))) {
                $erro = "Já existe PTR para esse endereço";
                break;
            }
        }

        if (!$erro) {
            $newContent = increment_zone_serial(
                rtrim(file_get_contents($file)) . "\n" . $newrecord . "\n"
            );

            if (!validate_zone_content($bindZone, $newContent, $validationError)) {
                $erro = "Erro na zona DNS: " . $validationError;
            } elseif (!write_file_safely($file, $newContent)) {
                $erro = "Não foi possível gravar a zona.";

} else {

    registrar_auditoria([
        'acao'          => $isIpv6Zone
                            ? 'ADICIONAR_PTR_IPV6'
                            : 'ADICIONAR_PTR_IPV4_GENERATE',
        'dominio'       => $zone,
        'tipo_registro' => 'PTR',
        'nome_registro' => $isIpv6Zone ? 'PTR IPv6' : 'PTR IPv4',
        'valor_antigo'  => 'inexistente',
        'valor_novo'    => $isIpv6Zone
                            ? audit_valor_ptr($address, $host)
                            : $host,
        'status'        => 'OK',
        'mensagem'      => 'PTR criado'
    ]);

    reload_dns();

    header(

                    "Location: edit-reverse-zone.php?zone=" .
                    urlencode($zone) .
                    "&success=" .
                    urlencode("Registro adicionado com sucesso")
                );
                exit;
            }
        }
    }
}

function ptr6ParaIpv6Visual($ptr, $zoneName = null)
{
    $ptr = strtolower(trim($ptr, ". \t\n\r\0\x0B"));

    if (!preg_match('/^[0-9a-f](\.[0-9a-f])+$/', $ptr)) {
        return $ptr;
    }

    $full = $ptr;

    if ($zoneName && str_ends_with($zoneName, '.ip6.arpa')) {
        $zonePrefix = substr($zoneName, 0, -strlen('.ip6.arpa'));
        $zonePrefix = trim($zonePrefix, '.');

        if ($zonePrefix !== '') {
            $full .= '.' . $zonePrefix;
        }
    }

    $nibbles = array_reverse(explode('.', $full));
    $hex = implode('', $nibbles);

    if (strlen($hex) > 32) {
        return $ptr;
    }

    $hex = str_pad($hex, 32, '0', STR_PAD_LEFT);

    $bin = @hex2bin($hex);

    if ($bin === false || strlen($bin) !== 16) {
        return $ptr;
    }

    $ipv6 = @inet_ntop($bin);

    return $ipv6 ?: $ptr;
}

$content = file($file);
$records = [];
$generates = [];

foreach ($content as $line) {

    $line = trim($line);

if (
    $line == "" ||
    str_starts_with($line, ";")
) {
    continue;
}

if (str_starts_with($line, '$GENERATE')) {

    $parts = preg_split('/\s+/', $line);

    if (count($parts) >= 5 && strtoupper($parts[3]) === 'PTR') {
        $generates[] = [
            'range' => $parts[1],
            'ptr'   => $parts[2],
            'host'  => $parts[4]
        ];
    }

    continue;
}

if (str_starts_with($line, "$")) {
    continue;
}

    if (
        preg_match(
            '/^(.+?)\s+IN\s+PTR\s+(.+)$/',
            $line,
            $m
        )
    ) {

$records[] = [
    'ptr'         => $m[1],
    'ptr_visual'  => $isIpv6Zone ? ptr6ParaIpv6Visual($m[1], $bindZone) : $m[1],
    'host'        => $m[2]
];

    }
}

usort($records, function($a, $b) {
    return strnatcasecmp($a['ptr_visual'], $b['ptr_visual']);
});

?>

<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>Editar Zona</title>

<style>

body{

    margin:0;
    font-family:Arial;
    background:#0f172a;
    color:#e2e8f0;
}

.container{

    width:1000px;
    margin:40px auto;
}

.card{

    background:#020617;
    padding:25px;
    border-radius:12px;
}

table{

    width:100%;
    border-collapse:collapse;
}

td{

    padding:10px;
    border-bottom:1px solid #1e293b;
    font-family:monospace;
}

a{

    color:#38bdf8;
    text-decoration:none;
}

#toast-success {

    position: fixed;
    top: 20px;
    right: 20px;

    background: #166534;
    color: white;

    padding: 15px;

    border-radius: 8px;

    z-index: 9999;

    box-shadow: 0 0 10px rgba(0,0,0,0.3);
}
</style>

</head>

<body>

<div class="container">

<div class="card">

<h2>

📄 Zona:
<?= htmlspecialchars(str_replace('.rev6', '', $zone)) ?>
</h2>
<p>
<a href="auditoria.php?dominio=<?= urlencode(audit_dominio_base($zone) ?? $zone) ?>">📋 Histórico</a>
</p>
<?php if(!empty($sucesso)): ?>

<div id="toast-success">

<?= htmlspecialchars($sucesso) ?>

</div>

<?php endif; ?>
<?php if(!empty($erro)): ?>

<div id="toast-error" style="
background:#7f1d1d;
padding:12px;
border-radius:8px;
margin-bottom:20px;
color:#fecaca;
">

<?= htmlspecialchars($erro) ?>

</div>

<?php endif; ?>
<form method="POST">
<?= csrf_field() ?>

<div style="margin-bottom:20px;display:flex;gap:10px;">

<input type="text"
name="host"
placeholder="<?= $isIpv6Zone ? 'IPv6' : 'IPv4' ?>"
required
style="
flex:1;
padding:10px;
background:#0f172a;
border:none;
border-radius:8px;
color:white;
">


<input type="text"
name="value"
placeholder="Hostname"
required
style="
flex:2;
padding:10px;
background:#0f172a;
border:none;
border-radius:8px;
color:white;
">

<button
type="submit"
style="
padding:10px 20px;
background:#2563eb;
border:none;
border-radius:8px;
color:white;
cursor:pointer;
">

Adicionar

</button>

</div>

</form>

<?php if (!empty($generates)): ?>

<div style="
background:#0f172a;
border:1px solid #1e293b;
padding:12px;
border-radius:8px;
margin-bottom:15px;
">

<b>PTR automático IPv4 ativo:</b><br><br>

<?php foreach ($generates as $g): ?>

Intervalo: <?= htmlspecialchars($g['range']) ?><br>
PTR: <?= htmlspecialchars($g['ptr']) ?><br>
Hostname: <?= htmlspecialchars($g['host']) ?><br><br>

<?php endforeach; ?>

</div>

<?php endif; ?>

<table>

<tr>

<td><b>PTR</b></td>
<td><b>Hostname</b></td>
<td><b>Ação</b></td>

</tr>

<?php foreach($records as $r): ?>

<tr>

<td><?= htmlspecialchars($r['ptr_visual']) ?></td>

<td><?= htmlspecialchars($r['host']) ?></td>

<td style="white-space:nowrap;">

<a
href="edit-ptr.php?zone=<?= urlencode($zone) ?>&ptr=<?= urlencode($r['ptr']) ?>&host=<?= urlencode($r['host']) ?>"
style="
display:inline-block;
color:#38bdf8;
background:#0f172a;
border:1px solid #1e293b;
padding:5px 8px;
font-size:12px;
border-radius:6px;
text-decoration:none;
margin-right:8px;
">
✏️ Editar
</a>

<form
method="POST"
action="delete-ptr.php"
style="display:inline-block;margin:0;"
onsubmit="return confirm('Remover PTR?')">
<?= csrf_field() ?>
<input type="hidden" name="zone" value="<?= htmlspecialchars($zone) ?>">
<input type="hidden" name="ptr" value="<?= htmlspecialchars($r['ptr']) ?>">
<input type="hidden" name="host" value="<?= htmlspecialchars($r['host']) ?>">

<button
type="submit"
style="
color:#fecaca;
background:#7f1d1d;
border:1px solid #991b1b;
padding:5px 8px;
font-size:12px;
border-radius:6px;
cursor:pointer;
">
🗑 Remover
</button>

</form>

</td>
</tr>

<?php endforeach; ?>

</table>

<br>

<a href="reverse-zones.php">

← Voltar

</a>

</div>

</div>
<script>

setTimeout(function() {

    let ok =
        document.getElementById(
            'toast-success'
        );

    let erro =
        document.getElementById(
            'toast-error'
        );

    if (ok) {

        ok.remove();
    }

    if (erro) {

        erro.remove();
    }

}, 4000);

setTimeout(function() {

    let url = new URL(window.location);

    url.searchParams.delete('success');

    window.history.replaceState(
        {},
        document.title,
        url.pathname + url.search
    );

}, 100);

</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>

</html>
