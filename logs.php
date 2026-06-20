<?php
require_once __DIR__ . "/includes/auth.php";

function coletar_logs_sistema(): array
{
    $fontes = [
        [
            'rotulo' => '/var/log/syslog',
            'comando' => 'tail -n 50 /var/log/syslog',
        ],
        [
            'rotulo' => 'journalctl',
            'comando' => 'journalctl --no-pager -n 50 -o short',
        ],
    ];

    foreach ($fontes as $fonte) {
        $saida = shell_exec($fonte['comando'] . ' 2>&1');
        $saida = is_string($saida) ? trim($saida) : '';

        if ($saida !== '' && stripos($saida, 'permission denied') === false && stripos($saida, 'no such file') === false) {
            return [
                'fonte' => $fonte['rotulo'],
                'logs' => $saida,
                'fallback' => $fonte['rotulo'] !== '/var/log/syslog',
            ];
        }
    }

    return [
        'fonte' => 'indisponível',
        'logs' => 'Não foi possível consultar os logs do sistema neste momento.',
        'fallback' => false,
    ];
}

$resultado = coletar_logs_sistema();
?>

<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Logs do Sistema</title>

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

<h2>📜 Logs do Sistema</h2>

<p>
<a href="dashboard.php">
← Voltar
</a>
</p>

<div class="card">

<p style="margin:0 0 12px;color:#94a3b8;font-size:12px;">
Fonte: <?= htmlspecialchars($resultado['fonte']) ?>
</p>

<?php if (!empty($resultado['fallback'])): ?>
<p style="margin:0 0 12px;color:#fbbf24;font-size:12px;">
O arquivo syslog não estava disponível; exibindo saída do journal.
</p>
<?php endif; ?>

<pre><?= htmlspecialchars($resultado['logs']) ?></pre>

</div>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
