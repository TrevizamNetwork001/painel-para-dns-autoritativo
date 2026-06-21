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
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Logs do Sistema</title>
<style>
:root{
    color-scheme: dark;
    --bg:#0b1220;
    --panel:#0f172a;
    --panel-2:#111c33;
    --border:#24324a;
    --text:#e2e8f0;
    --muted:#94a3b8;
    --accent:#38bdf8;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,sans-serif;
    background:radial-gradient(circle at top,#101b33 0,var(--bg) 44%,#070b14 100%);
    color:var(--text);
}
a{color:inherit;text-decoration:none}
.page{
    width:min(980px, calc(100% - 28px));
    margin:0 auto;
    padding:22px 0 34px;
}
.back{
    color:var(--accent);
    font-size:14px;
}
h1{
    margin:8px 0 6px;
    font-size:28px;
}
.subtitle{
    margin:0;
    color:var(--muted);
    line-height:1.5;
}
.card{
    margin-top:18px;
    padding:16px;
    border:1px solid var(--border);
    border-radius:14px;
    background:linear-gradient(180deg, rgba(15,23,42,.96), rgba(11,18,32,.96));
    box-shadow:0 16px 44px rgba(0,0,0,.18);
}
.card h2{
    margin:0 0 6px;
    font-size:18px;
}
.card p{
    margin:0;
    color:var(--muted);
}
.badge{
    display:inline-flex;
    align-items:center;
    padding:5px 10px;
    border-radius:999px;
    border:1px solid rgba(148,163,184,.18);
    background:rgba(148,163,184,.10);
    color:#dbe7f5;
    font-size:12px;
    white-space:nowrap;
    margin-top:12px;
}
.actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
}
.actions a{
    border:1px solid var(--border);
    background:transparent;
    color:var(--muted);
    border-radius:10px;
    padding:9px 12px;
    font-size:13px;
}
.panel{
    margin-top:12px;
    border:1px solid var(--border);
    border-radius:12px;
    background:rgba(17,28,51,.72);
    padding:14px;
}
.panel h3{
    margin:0 0 10px;
    font-size:15px;
}
pre{
    margin:0;
    padding:14px;
    background:#091123;
    border:1px solid var(--border);
    border-radius:12px;
    color:#cbd5e1;
    font-size:12px;
    line-height:1.55;
    white-space:pre-wrap;
    word-break:break-word;
    overflow:auto;
}
@media (max-width: 720px){
    .page{width:calc(100% - 18px);padding-top:14px}
    h1{font-size:24px}
}
</style>
</head>
<body>
<div class="page">
    <a class="back" href="dashboard.php">← Voltar</a>
    <h1>Logs do Sistema</h1>
    <p class="subtitle">Consulta somente leitura dos logs do sistema.</p>

    <section class="card">
        <h2>Logs recentes</h2>
        <p>Últimas 50 linhas dos logs do sistema.</p>

        <div class="actions">
            <a href="#info">Informações</a>
            <a href="#dados">Ver dados</a>
        </div>

        <div class="badge">Fonte: <?= htmlspecialchars($resultado['fonte']) ?></div>

        <div class="panel" id="info">
            <h3>Informação</h3>
            <?php if (!empty($resultado['fallback'])): ?>
                <p>O arquivo syslog não estava disponível; exibindo saída do journal.</p>
            <?php else: ?>
                <p>Os logs exibidos abaixo são somente leitura e atualizam ao recarregar a página.</p>
            <?php endif; ?>
        </div>

        <div class="panel" id="dados">
            <h3>Dados brutos</h3>
            <pre><?= htmlspecialchars($resultado['logs']) ?></pre>
        </div>
    </section>
</div>

<script>
setTimeout(() => {
    window.location.reload();
}, 8000);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
