<?php
require_once dirname(__DIR__, 2) . "/app/Auth/auth.php";

$logs = shell_exec(
    "sudo /usr/bin/fail2ban-client status sshd"
);

preg_match('/Currently failed:\s+(\d+)/', $logs, $failed);
preg_match('/Currently banned:\s+(\d+)/', $logs, $banned);
preg_match('/Total banned:\s+(\d+)/', $logs, $total);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fail2Ban</title>
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
    --bad:#dc2626;
    --warn:#d97706;
    --info:#3b82f6;
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
.badges{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:12px;
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
}
.badge-bad{background:rgba(220,38,38,.14);border-color:rgba(220,38,38,.32);color:#fecaca}
.badge-warn{background:rgba(217,119,6,.14);border-color:rgba(217,119,6,.32);color:#fde68a}
.badge-info{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.32);color:#bfdbfe}
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
.stats{
    display:grid;
    grid-template-columns:repeat(3, minmax(0, 1fr));
    gap:12px;
    margin-top:12px;
}
.stat{
    padding:14px;
    border-radius:12px;
    border:1px solid var(--border);
    background:rgba(17,28,51,.72);
}
.stat h3{
    margin:0;
    font-size:24px;
}
.stat p{
    margin-top:8px;
    color:var(--muted);
    font-size:13px;
}
.stat.bad{background:rgba(63,13,18,.45)}
.stat.warn{background:rgba(63,50,13,.42)}
.stat.info{background:rgba(12,45,72,.42)}
@media (max-width: 720px){
    .page{width:calc(100% - 18px);padding-top:14px}
    h1{font-size:24px}
    .stats{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="page">
    <a class="back" href="dashboard.php">← Voltar</a>
    <h1>Fail2Ban SSH</h1>
    <p class="subtitle">Consulta somente leitura do estado atual do Fail2Ban para SSH.</p>

    <section class="card">
        <h2>Status</h2>
        <p>Visão compacta do serviço e dos contadores atuais.</p>

        <div class="badges">
            <span class="badge badge-bad">Banidos: <?= (int) ($banned[1] ?? 0) ?></span>
            <span class="badge badge-warn">Falhas: <?= (int) ($failed[1] ?? 0) ?></span>
            <span class="badge badge-info">Total: <?= (int) ($total[1] ?? 0) ?></span>
        </div>

        <div class="actions">
            <a href="#diagnostico">Mostrar diagnóstico</a>
            <a href="#dados">Mostrar dados</a>
        </div>

        <div class="stats">
            <div class="stat bad">
                <h3><?= (int) ($banned[1] ?? 0) ?></h3>
                <p>Banidos</p>
            </div>
            <div class="stat warn">
                <h3><?= (int) ($failed[1] ?? 0) ?></h3>
                <p>Falhas</p>
            </div>
            <div class="stat info">
                <h3><?= (int) ($total[1] ?? 0) ?></h3>
                <p>Total</p>
            </div>
        </div>

        <div class="panel" id="diagnostico">
            <h3>Diagnóstico</h3>
            <div class="badges">
                <span class="badge <?= (int) ($banned[1] ?? 0) > 0 ? 'badge-bad' : 'badge-info' ?>"><?= (int) ($banned[1] ?? 0) > 0 ? 'Bloqueios ativos' : 'Sem bloqueios ativos' ?></span>
                <span class="badge <?= (int) ($failed[1] ?? 0) > 0 ? 'badge-warn' : 'badge-info' ?>"><?= (int) ($failed[1] ?? 0) > 0 ? 'Tentativas suspeitas' : 'Sem tentativas suspeitas' ?></span>
            </div>
        </div>

        <div class="panel" id="dados">
            <h3>Dados brutos</h3>
            <pre><?= htmlspecialchars($logs ?: 'Sem dados') ?></pre>
        </div>
    </section>
</div>

<script>
setTimeout(() => {
    window.location.reload();
}, 5000);
</script>

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
</body>
</html>
