<?php

require_once __DIR__ . "/includes/auth.php";

$rules = shell_exec(
    "sudo /usr/sbin/nft list ruleset"
);

$status = shell_exec(
    "systemctl is-active nftables"
);

preg_match('/counter packets (\d+) bytes .* drop/', $rules, $drop4);

preg_match_all('/counter packets (\d+) bytes .* drop/', $rules, $drops);

$ipv4_drop = $drops[1][0] ?? 0;

$ipv6_drop = $drops[1][1] ?? 0;

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>Firewall</title>

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

pre{

    white-space:pre-wrap;

    font-size:13px;

    line-height:1.6;

    color:#cbd5e1;
}

a{

    color:#38bdf8;

    text-decoration:none;
}

.stats{

    display:flex;

    gap:20px;

    margin-bottom:20px;
}

.stat{

    flex:1;

    padding:20px;

    border-radius:14px;

    text-align:center;

    border:1px solid #1e293b;
}

.stat h3{

    margin:0;

    font-size:28px;
}

.stat p{

    margin-top:10px;

    color:#cbd5e1;
}

.green{

    background:#052e16;
}

.blue{

    background:#0c2d48;
}

.red{

    background:#3f0d12;
}

</style>
</head>

<body>

<div class="container">

<h2>🔥 Firewall NFTables</h2>

<p>
<a href="dashboard.php">
← Voltar
</a>
</p>

<div class="stats">

<div class="stat green">

<h3>
🟢 <?= trim($status) ?>
</h3>

<p>NFTables</p>

</div>

<div class="stat blue">

<h3>
🌐 <?= $ipv6_drop ?>
</h3>

<p>IPv6 Drops</p>
</div>

<div class="stat red">

<h3>
📦 <?= $ipv4_drop ?>
</h3>

<p>IPv4 Drops</p>

</div>

</div>

<div class="card">

<pre><?= htmlspecialchars($rules ?: 'Sem regras') ?></pre>

</div>

</div>

<script>

setTimeout(() => {

    location.reload();

}, 5000);

</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
