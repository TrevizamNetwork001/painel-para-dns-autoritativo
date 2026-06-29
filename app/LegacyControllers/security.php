<?php

require_once __DIR__ . "/includes/auth.php";

$logs = shell_exec(
    "tail -n 100 /var/log/named/security.log"
);

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Logs BIND</title>

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
    font-size:13px;
    line-height:1.6;
    color:#cbd5e1;
}

a{
    color:#38bdf8;
    text-decoration:none;
}

</style>
</head>

<body>

<div class="container">

<h2>🛡 Security DNS</h2>
<p>
<a href="dashboard.php">← Voltar</a>
</p>

<div class="card">

<pre><?= htmlspecialchars($logs ?: 'Sem logs disponíveis') ?></pre>

</div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
