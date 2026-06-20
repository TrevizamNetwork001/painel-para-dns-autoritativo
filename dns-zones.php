<?php

require "config.php";
require "includes/auth.php";

$zoneFiles = glob('/var/cache/bind/master-aut/*.hosts') ?: [];
$domains = [];

foreach ($zoneFiles as $file) {
    if (!is_file($file)) {
        continue;
    }

    $domain = basename($file, '.hosts');

    if ($domain !== '') {
        $domains[] = $domain;
    }
}

sort($domains, SORT_NATURAL | SORT_FLAG_CASE);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zonas DNS</title>
<style>
:root{
    --bg:#0f172a;
    --panel:#020617;
    --panel-2:#071226;
    --line:#1e293b;
    --line-soft:#334155;
    --text:#e2e8f0;
    --muted:#94a3b8;
    --accent:#38bdf8;
}
*{box-sizing:border-box}
body{
    margin:0;
    font-family:Arial,Helvetica,sans-serif;
    background:
        radial-gradient(circle at top left, rgba(56,189,248,.08), transparent 30%),
        radial-gradient(circle at top right, rgba(59,130,246,.08), transparent 26%),
        var(--bg);
    color:var(--text);
}
a{color:var(--accent);text-decoration:none}
a:hover{text-decoration:underline}
.page{
    width:min(1100px, calc(100% - 28px));
    margin:28px auto 40px;
}
.header{margin-bottom:16px}
.back-link{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#cbd5e1;
    font-size:14px;
}
.header h1{
    margin:8px 0 6px;
    color:#fff;
    font-size:30px;
    line-height:1.15;
    letter-spacing:-.02em;
}
.header p{
    margin:0;
    color:var(--muted);
    line-height:1.45;
}
.card{
    background:rgba(2,6,23,.96);
    border:1px solid rgba(30,41,59,.95);
    border-radius:16px;
    box-shadow:0 16px 40px rgba(0,0,0,.16);
}
.card-inner{padding:20px 22px}
.card-head{margin-bottom:14px}
.card-head h2{
    margin:0 0 4px;
    color:#fff;
    font-size:20px;
}
.card-head p{
    margin:0;
    color:var(--muted);
    font-size:14px;
    line-height:1.45;
}
.search{max-width:440px;margin-bottom:12px}
.search input{
    width:100%;
    min-height:38px;
    padding:9px 11px;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:rgba(7,18,38,.72);
    color:#fff;
    font:inherit;
    outline:none;
}
.search input:focus{
    border-color:rgba(56,189,248,.7);
    box-shadow:0 0 0 3px rgba(56,189,248,.12);
}
.domain-list{display:grid;gap:6px}
.zone-row{
    display:grid;
    grid-template-columns:minmax(0, 1fr) auto;
    align-items:center;
    gap:12px;
    min-height:46px;
    padding:6px 10px;
    border:1px solid var(--line);
    border-radius:12px;
    background:rgba(7,18,38,.68);
}
.zone-name{
    color:#fff;
    font-weight:700;
    line-height:1.35;
    word-break:break-word;
}
.edit-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:30px;
    padding:6px 10px;
    border:1px solid var(--line-soft);
    border-radius:8px;
    background:rgba(30,41,59,.9);
    color:#dbeafe;
    font-size:13px;
    white-space:nowrap;
}
.edit-link:hover{
    border-color:rgba(56,189,248,.45);
    text-decoration:none;
}
.empty{
    padding:18px 16px;
    border:1px dashed var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.66);
    color:var(--muted);
}
.hidden{display:none}
@media(max-width:560px){
    .page{width:min(100% - 20px, 1100px);margin:20px auto 30px}
    .card-inner{padding:18px}
    .zone-row{grid-template-columns:1fr}
    .edit-link{justify-self:start}
}
</style>
</head>
<body>
<main class="page">
    <header class="header">
        <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
        <h1>Zonas DNS</h1>
        <p>Consultar e editar os domínios cadastrados no servidor.</p>
    </header>

    <section class="card">
        <div class="card-inner">
            <div class="card-head">
                <h2>Domínios cadastrados</h2>
                <p>Selecione um domínio para gerenciar sua zona forward.</p>
            </div>

            <div class="search">
                <input type="search" id="filtro" placeholder="Pesquisar domínio..." autocomplete="off">
            </div>

            <?php if (!$domains): ?>
                <div class="empty">Nenhum domínio DNS encontrado.</div>
            <?php else: ?>
                <div id="zone-list" class="domain-list">
                    <?php foreach ($domains as $domain): ?>
                        <div class="zone-row" data-domain="<?= htmlspecialchars(strtolower($domain), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="zone-name"><?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?></span>
                            <a class="edit-link" href="edit-zone.php?zone=<?= rawurlencode($domain) ?>">Editar Zona</a>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="empty-filter" class="empty hidden">Nenhum domínio encontrado.</div>
            <?php endif; ?>
        </div>
    </section>
</main>
<script>
const filtro = document.getElementById('filtro');
const linhas = Array.from(document.querySelectorAll('.zone-row'));
const vazio = document.getElementById('empty-filter');

if (filtro) {
    filtro.addEventListener('input', function() {
        const termo = this.value.trim().toLowerCase();
        let visiveis = 0;

        linhas.forEach(function(linha) {
            const mostrar = linha.dataset.domain.includes(termo);
            linha.classList.toggle('hidden', !mostrar);
            if (mostrar) {
                visiveis++;
            }
        });

        if (vazio) {
            vazio.classList.toggle('hidden', visiveis > 0);
        }
    });
}
</script>
</body>
</html>
