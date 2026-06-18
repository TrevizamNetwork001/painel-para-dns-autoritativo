<?php

require_once __DIR__ . "/includes/auth.php";

$logs = shell_exec(
    "sudo /usr/bin/fail2ban-client status sshd"
);
preg_match('/Currently failed:\s+(\d+)/', $logs, $failed);

preg_match('/Currently banned:\s+(\d+)/', $logs, $banned);

preg_match('/Total banned:\s+(\d+)/', $logs, $total);

?>

<!DOCTYPE html>
<html>
<head>

<meta charset="UTF-8">

<title>Fail2Ban</title>

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
}

pre{

    white-space:pre-wrap;

    font-size:14px;

    line-height:1.7;

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

    font-size:32px;
}

.stat p{

    margin-top:10px;

    color:#cbd5e1;
}

.red{

    background:#3f0d12;
}

.yellow{

    background:#3f320d;
}

.blue{

    background:#0c2d48;
}

</style>
</head>

<body>

<div class="container">

<h2>🚫 Fail2Ban SSH</h2>

<p>
<a href="dashboard.php">
← Voltar
</a>
</p>

<div class="stats">

<div class="stat red">

<h3>
🚫 <?= $banned[1] ?? 0 ?>
</h3>

<p>Banidos</p>

</div>

<div class="stat yellow">

<h3>
⚠️ <?= $failed[1] ?? 0 ?>
</h3>

<p>Falhas</p>

</div>

<div class="stat blue">

<h3>
📊 <?= $total[1] ?? 0 ?>
</h3>

<p>Total</p>

</div>

</div>
<div class="card">

<pre><?= htmlspecialchars($logs ?: 'Sem dados') ?></pre>

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
