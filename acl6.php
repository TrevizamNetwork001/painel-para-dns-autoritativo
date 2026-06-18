<?php

require_once __DIR__ . "/includes/auth.php";
require "includes/security.php";

$file = "/etc/nftables.d/acl6.conf";

$content = file_get_contents($file);

preg_match_all('/([a-fA-F0-9:]+:+[a-fA-F0-9:\/]+)/', $content, $matches);

$ips = $matches[1];

$msg = "";
$error = "";

if (isset($_POST['delete_acl'])) {
    require_csrf();

    $del = trim($_POST['delete_acl'] ?? '');

    $ips = array_filter($ips, function($ip) use ($del) {

         return trim($ip) != trim($del);
    });

    $config = "define ACL6 = {\n";

    $ips = array_values($ips);

    foreach ($ips as $k => $ip) {

        $comma = ($k < count($ips)-1) ? "," : "";

        $config .= "    $ip$comma\n";
    }

    $config .= "}\n";

    $oldConfig = file_get_contents($file);
    write_file_safely($file, $config);
    exec("sudo /usr/sbin/nft -f /etc/nftables.conf 2>&1", $output, $status);

    if ($status !== 0) {
        write_file_safely($file, $oldConfig);
        header("Location: acl6.php?error=apply");
        exit;
    }

    header("Location: acl6.php");

    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();

    $newip = trim($_POST['ip'] ?? '');

if ($newip != "" && valid_cidr($newip, FILTER_FLAG_IPV6)) {

    $ips[] = $newip;

    $ips = array_unique($ips);

    $config = "define ACL6 = {\n";

    foreach ($ips as $k => $ip) {

        $comma = ($k < count($ips)-1) ? "," : "";

        $config .= "    $ip$comma\n";
    }

    $config .= "}\n";

    $oldConfig = file_get_contents($file);
    write_file_safely($file, $config);
    exec("sudo /usr/sbin/nft -f /etc/nftables.conf 2>&1", $output, $status);

    if ($status !== 0) {
        write_file_safely($file, $oldConfig);
        $error = "A regra foi rejeitada pelo NFTables.";
    } else {

    $msg = "ACL atualizada com sucesso.";
    }
} else {
    $error = "Informe uma ACL IPv6 válida.";
}

}

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>ACL IPv6</title>

<style>

body{

    margin:0;

    font-family:Arial;

    background:#0f172a;

    color:#e2e8f0;
}

.container{

    padding:30px;
}

.card{

    background:#071226;

    border:1px solid #1e293b;

    border-radius:14px;

    padding:25px;

    margin-bottom:20px;
}

input{

    width:100%;

    padding:12px;

    border:none;

    border-radius:10px;

    background:#0f172a;

    color:white;

    margin-top:10px;
}

button{

    margin-top:15px;

    padding:12px 20px;

    border:none;

    border-radius:10px;

    background:#2563eb;

    color:white;

    cursor:pointer;
}

.ip{

    padding:10px;

    border-bottom:1px solid #1e293b;
}

.success{

    background:#052e16;

    padding:15px;

    border-radius:10px;

    margin-bottom:20px;
}

a{

    color:#38bdf8;

    text-decoration:none;
}

.error{

    background:#3f0d12;

    padding:15px;

    border-radius:10px;

    margin-bottom:20px;

    color:#fecaca;
}

</style>
</head>

<body>

<div class="container">

<h2>🌐 ACL IPv6</h2>

<p>
<a href="dashboard.php">← Voltar</a>
</p>

<?php if($error): ?>

<div class="error">
<?= $error ?>
</div>

<?php endif; ?>

<?php if($msg): ?>

<div class="success">
<?= $msg ?>
</div>

<?php endif; ?>

<div class="card">

<h3>Adicionar IP/Rede</h3>

<form method="POST">
<?= csrf_field() ?>

<input type="text" name="ip" placeholder="Ex: 2001:db8::1000/44 ou 2001:db9::2000/128">

<button type="submit">
Adicionar ACL
</button>

</form>

</div>

<div class="card">

<h3>ACL Atual</h3>

<?php foreach($ips as $ip): ?>
<div class="ip">

<div style="display:flex;justify-content:space-between;align-items:center;">

<span>
<?= htmlspecialchars($ip) ?>
</span>

<form method="POST" style="margin:0"
onsubmit="return confirm('Remover ACL <?= htmlspecialchars($ip, ENT_QUOTES) ?> ?')">
<?= csrf_field() ?>
<button type="submit" name="delete_acl" value="<?= htmlspecialchars($ip) ?>" style="
background:#dc2626;
padding:6px 12px;
border-radius:8px;
color:white;
border:0;
cursor:pointer;
text-decoration:none;
">

Remover

</button>
</form>

</div>

</div>

<?php endforeach; ?>

</div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
