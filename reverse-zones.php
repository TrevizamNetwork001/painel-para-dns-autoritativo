<?php

require "config.php";
require "includes/auth.php";


$domains = glob("/var/cache/bind/master-rev/*");

$ipv4zones = [];
$ipv6zones = [];

function dominioRelacionadoReverse(string $file): string
{
    $content = @file_get_contents($file);

    if ($content === false) {
        return '-';
    }

    if (preg_match('/SOA\s+ns1\.([^\s]+?)\.\s+hostmaster\./i', $content, $m)) {
        return $m[1];
    }

    if (preg_match('/SOA\s+\S+\.([a-z0-9.-]+)\.\s+/i', $content, $m)) {
        return $m[1];
    }

    return '-';
}

foreach ($domains as $file) {

    $dom = basename($file);

    $item = [
        'file' => $dom,
        'domain' => dominioRelacionadoReverse($file)
    ];

    if (str_ends_with($dom, '.rev6')) {
        $ipv6zones[] = $item;
    } else {
        $ipv4zones[] = $item;
    }
}

usort($ipv4zones, function($a, $b) {
    return strcmp($a['file'], $b['file']);
});

usort($ipv6zones, function($a, $b) {
    return strcmp($a['file'], $b['file']);
});

?>

<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>Zonas Reversas</title>

<style>

body{

    margin:0;
    font-family:Arial;
    background:#0f172a;
    color:#e2e8f0;
}

.container{

    width:800px;
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

    padding:12px;
    border-bottom:1px solid #1e293b;
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

<h2>📄 Zonas Reversas</h2>
<input
type="text"
id="filtro"
placeholder="Pesquisar domínio ou zona..."
style="
width:100%;
padding:10px;
margin-bottom:20px;
background:#0f172a;
border:1px solid #1e293b;
border-radius:8px;
color:white;
box-sizing:border-box;
">
<h3>IPv4</h3>

<table>

<?php foreach($ipv4zones as $z): ?>

<tr class="linha-zona">
<td>
<?= htmlspecialchars($z['file']) ?>
<br>
<small style="color:#94a3b8;">
Domínio: <?= htmlspecialchars($z['domain']) ?>
</small>
</td>

<td align="right">
<a href="edit-reverse-zone.php?zone=<?= urlencode($z['file']) ?>">
Editar Zona
</a>
</td>

</tr>

<?php endforeach; ?>

</table>
<h3>IPv6</h3>

<table>

<?php foreach($ipv6zones as $z):

$display = str_replace('.rev6', '', $z['file']);

?>

<tr class="linha-zona">

<td>
<?= htmlspecialchars($display) ?>
<br>
<small style="color:#94a3b8;">
Domínio: <?= htmlspecialchars($z['domain']) ?>
</small>
</td>

<td align="right">
<a href="edit-reverse-zone.php?zone=<?= urlencode($z['file']) ?>">
Editar Zona
</a>
</td>

</tr>

<?php endforeach; ?>

</table>
<br>

<a href="dashboard.php">

← Voltar

</a>

</div>

</div>

<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>

<script>
document.getElementById('filtro').addEventListener('input', function() {

    let busca = this.value.toLowerCase();

    document.querySelectorAll('.linha-zona').forEach(function(linha) {

        let texto = linha.innerText.toLowerCase();

        linha.style.display =
            texto.includes(busca)
            ? ''
            : 'none';

    });

});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>

</html>
