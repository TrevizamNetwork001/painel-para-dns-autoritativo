<?php

require_once __DIR__ . "/includes/auth.php";

$logs = shell_exec(
    "journalctl -u ssh --no-pager -n 50"
);

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">

<title>SSH Logs</title>

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

h2{

    margin-bottom:10px;
}

</style>
</head>

<body>

<div class="container">

<h2>🧾 SSH/Auth Logs</h2>

<p>
<a href="dashboard.php">
← Voltar
</a>
</p>

<div class="card">

<pre><?= htmlspecialchars($logs ?: 'Sem logs disponíveis') ?></pre>

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
