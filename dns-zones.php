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
<title>Dominios DNS</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0}.container{width:800px;max-width:calc(100% - 28px);margin:40px auto}.card{background:#020617;padding:25px;border-radius:12px}h2{margin:0 0 16px}a{color:#38bdf8;text-decoration:none}.back{display:inline-block;margin-bottom:16px}.search{width:100%;padding:10px;margin-bottom:8px;border:1px solid #334155;border-radius:6px;background:#0f172a;color:#e2e8f0;font-size:14px}.zone-row{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:9px 0;border-bottom:1px solid #1e293b}.zone-row:last-child{border-bottom:0}.zone-name{font-size:15px;line-height:1.3;overflow-wrap:anywhere}.edit-link{flex:0 0 auto;color:#38bdf8;font-size:14px}.edit-link:hover{text-decoration:underline}.empty{color:#94a3b8;padding:10px 0}.hidden{display:none}@media(max-width:560px){.container{margin:24px auto}.card{padding:18px}.zone-row{gap:12px}.edit-link{font-size:13px;white-space:nowrap}}
</style>
</head>
<body>
<div class="container">
<a class="back" href="dashboard.php">← Voltar ao painel</a>
<div class="card">
<h2>Domínios DNS</h2>
<input type="text" id="filtro" class="search" placeholder="Pesquisar domínio..." autocomplete="off">

<?php if (!$domains): ?>
<div class="empty">Nenhum domínio DNS encontrado.</div>
<?php else: ?>
<div id="zone-list">
<?php foreach ($domains as $domain): ?>
<div class="zone-row" data-domain="<?= htmlspecialchars(strtolower($domain)) ?>">
<span class="zone-name"><?= htmlspecialchars($domain) ?></span>
<a class="edit-link" href="edit-zone.php?zone=<?= urlencode($domain) ?>">Editar Zona</a>
</div>
<?php endforeach; ?>
</div>
<div id="empty-filter" class="empty hidden">Nenhum domínio encontrado.</div>
<?php endif; ?>
</div>
</div>
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
