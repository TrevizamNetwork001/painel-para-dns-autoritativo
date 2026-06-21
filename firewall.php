<?php
require_once __DIR__ . '/includes/auth.php';

$summary = [
    ['◉', 'IPv4 Liberados', '2', 'Redes e endereços', false],
    ['⬡', 'IPv6 Liberados', '0', 'Nenhum cadastrado', false],
    ['⌁', 'Portas Admin', '3', 'Acesso restrito', false],
    ['⇄', 'Portas Públicas', '5', 'Acesso externo', false],
    ['✓', 'Firewall Ativo', 'Ativo', 'Configuração em uso', true],
];
$quickActions = [
    ['+', 'Adicionar IP', 'Autorizar endereço'],
    ['🔒', 'Porta Admin', 'Adicionar restrição'],
    ['🌐', 'Porta Pública', 'Liberar serviço'],
    ['▣', 'Backup', 'Salvar uma cópia'],
    ['✓', 'Validar', 'Verificar configuração'],
    ['↻', 'Aplicar', 'Publicar alterações'],
    ['≡', 'Ver Logs', 'Consultar eventos'],
];
$adminAccess = [
    ['IPv4', '45.182.96.0/24', 'Rede principal', '18/06/2026'],
    ['IPv4', '168.194.14.101', 'Acesso externo', '20/06/2026'],
];
$adminPorts = [
    ['22', 'TCP', 'SSH', 'Acesso remoto'],
    ['80', 'TCP', 'HTTP', 'Painel'],
    ['443', 'TCP', 'HTTPS', 'Painel seguro'],
];
$publicPorts = [
    ['53', 'TCP/UDP', 'DNS', 'Resolução de nomes'],
    ['80', 'TCP', 'HTTP', 'Serviço web'],
    ['443', 'TCP', 'HTTPS', 'Serviço web seguro'],
];
$recentAudit = [
    ['admin', 'Adicionou IP IPv4', 'Hoje, 09:28'],
    ['admin', 'Removeu porta pública', 'Hoje, 09:14'],
    ['admin', 'Validou configuração', 'Hoje, 09:05'],
    ['admin', 'Aplicou novas alterações', 'Ontem, 18:42'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firewall</title>
<style>
:root{color-scheme:dark;--bg:#080d18;--panel:#0b1424;--deep:#050a13;--raised:#101b2e;--line:#1e2c42;--line2:#33465f;--text:#e5edf7;--muted:#91a0b5;--subtle:#64748b;--accent:#38bdf8;--ok:#4ade80;--danger:#f87171}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 50% -15%,#15243e 0,var(--bg) 42%);color:var(--text);font-family:Arial,sans-serif;min-height:100vh}
button,input,a{font:inherit}button{color:inherit}a{color:inherit;text-decoration:none}
.page{width:min(1440px,calc(100% - 40px));margin:26px auto 38px}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.back-link{display:inline-flex;color:#a8b7ca;font-size:13px}.back-link:hover,.back-link:focus{color:var(--accent);outline:none}
.page-header h1{margin:10px 0 5px;color:#fff;font-size:31px;letter-spacing:-.025em}.page-header p{margin:0;color:var(--muted)}
.refresh-button{display:inline-flex;align-items:center;gap:8px;min-height:39px;padding:9px 13px;border:1px solid var(--line2);border-radius:9px;background:var(--raised);color:#dce8f5;font-weight:700;cursor:pointer}
.refresh-button:hover,.refresh-button:focus{border-color:var(--accent);outline:none}
.summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:22px}
.summary-card{position:relative;min-height:112px;padding:16px 16px 15px 58px;border:1px solid var(--line);border-radius:13px;background:linear-gradient(145deg,var(--raised),var(--deep));box-shadow:0 12px 30px #0003}
.summary-icon{position:absolute;left:16px;top:17px;display:grid;place-items:center;width:30px;height:30px;border:1px solid #38bdf838;border-radius:9px;background:#0c4a6e33;color:#7dd3fc;font-size:15px;font-weight:800}
.summary-icon.status{border-color:#22c55e42;background:#14532d52;color:#86efac}
.summary-label{display:block;color:var(--muted);font-size:11px;letter-spacing:.04em;text-transform:uppercase}
.summary-value{display:block;margin-top:8px;color:var(--accent);font-size:25px;font-weight:800}.summary-value.status{color:var(--ok);font-size:22px}
.summary-detail{display:block;margin-top:7px;color:var(--subtle);font-size:11px}
.panel{padding:20px;border:1px solid var(--line);border-radius:14px;background:linear-gradient(160deg,#0d182a,var(--panel));box-shadow:0 14px 36px #0003;min-width:0}
.quick-panel{margin-bottom:22px}
.panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:17px}.panel-header h2{margin:0;color:#fff;font-size:18px}
.panel-header p{margin:5px 0 0;color:var(--muted);font-size:13px;line-height:1.45}
.quick-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px}
.quick-action{min-height:82px;padding:13px;border:1px solid var(--line);border-radius:11px;background:var(--deep);text-align:left;cursor:pointer;transition:.15s}
.quick-action:hover,.quick-action:focus{border-color:var(--accent);outline:none;transform:translateY(-2px)}
.quick-icon{display:block;height:19px;color:var(--accent);font-size:17px;font-weight:800}.quick-action strong{display:block;margin-top:8px;font-size:13px}
.quick-action small{display:block;margin-top:5px;color:var(--subtle);font-size:11px}
.content-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
.stack{display:grid;gap:18px}
.search{width:min(280px,100%);min-height:38px;padding:8px 11px;border:1px solid var(--line2);border-radius:10px;background:var(--deep);color:#fff;outline:none}
.search::placeholder{color:var(--subtle)}.search:focus{border-color:var(--accent);box-shadow:0 0 0 3px #38bdf81a}
.table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}table{width:100%;border-collapse:collapse;min-width:610px}
th,td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:left;font-size:12px}th{background:var(--deep);color:var(--muted);font-size:9px;letter-spacing:.05em;text-transform:uppercase}
tr:last-child td{border-bottom:0}tbody tr{background:#02061766}tbody tr:hover{background:#0f172ab8}
.type-badge{display:inline-flex;padding:4px 8px;border:1px solid #38bdf838;border-radius:999px;background:#0e74901f;color:#bae6fd;font-size:11px;font-weight:700}
.strong{color:#fff;font-weight:700}.row-actions{display:flex;align-items:center;gap:7px;white-space:nowrap}
.text-action{padding:5px 8px;border:1px solid var(--line2);border-radius:7px;background:#101827;color:#cfeeff;cursor:pointer;font-size:11px;font-weight:700}.text-action:hover,.text-action:focus{border-color:var(--accent);outline:none}.text-action.remove{color:#fecaca}.text-action.remove:hover,.text-action.remove:focus{border-color:var(--danger)}
.validation{display:flex;gap:13px;min-height:128px;padding:17px;border:1px solid #22c55e4d;border-radius:11px;background:linear-gradient(135deg,#14532d38,#07170e)}
.validation-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#14532d;color:#bbf7d0;font-weight:800}
.validation strong{display:block;margin:2px 0 13px;color:#bbf7d0;font-size:15px}.validation span{display:block;color:var(--muted);font-size:12px;line-height:1.65}.validation time,.validation em{color:var(--text);font-style:normal;font-weight:700}
.audit-item{display:grid;grid-template-columns:minmax(80px,.35fr) minmax(160px,1fr) auto;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid var(--line);font-size:13px}
.audit-item:first-child{padding-top:0}.audit-item:last-child{border-bottom:0}.audit-user{color:#fff;font-weight:700}.audit-action{color:#cbd5e1}.audit-time{color:var(--subtle);font-size:11px;white-space:nowrap}
.secondary-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;margin-top:14px;padding:8px 12px;border:1px solid var(--line2);border-radius:9px;background:#0f172a;color:#dbeafe;font-size:12px;font-weight:700}
.secondary-button:hover,.secondary-button:focus{border-color:var(--accent);outline:none}
.ui-toast{position:fixed;right:20px;bottom:20px;z-index:20;max-width:min(380px,calc(100vw - 40px));padding:12px 14px;border:1px solid var(--line2);border-radius:11px;background:#111827;color:#cbd5e1;box-shadow:0 18px 45px #0006;font-size:13px}
.ui-toast[hidden],.empty-row[hidden]{display:none}.empty-row td{padding:22px 14px;color:var(--muted);text-align:center}
@media(max-width:1200px){.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.content-grid{grid-template-columns:1fr}}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.page{width:min(100% - 20px,1180px);margin:20px auto 30px}.page-header{align-items:flex-start;flex-direction:column}.page-header h1{font-size:27px}.summary-grid,.quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.panel{padding:16px}.panel-header{display:block}.search{width:100%;margin-top:13px}.audit-item{grid-template-columns:80px 1fr}.audit-time{grid-column:2}}
@media(max-width:460px){.summary-grid,.quick-grid{grid-template-columns:1fr}.summary-card{min-height:96px}}
</style>
</head>
<body>
<main class="page">
    <header class="page-header">
        <div>
            <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
            <h1>Firewall</h1>
            <p>Controle de acesso e portas públicas.</p>
        </div>
        <button class="refresh-button" type="button" data-refresh-page><span aria-hidden="true">↻</span> Atualizar</button>
    </header>

    <section class="summary-grid" aria-label="Resumo do Firewall">
        <?php foreach ($summary as [$icon, $label, $value, $detail, $status]): ?>
            <article class="summary-card">
                <span class="summary-icon<?= $status ? ' status' : '' ?>" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
                <span class="summary-label"><?= htmlspecialchars($label) ?></span>
                <span class="summary-value<?= $status ? ' status' : '' ?>"><?= htmlspecialchars($value) ?></span>
                <span class="summary-detail"><?= htmlspecialchars($detail) ?></span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel quick-panel">
        <header class="panel-header"><div><h2>Ações rápidas</h2><p>Atalhos para as operações mais utilizadas.</p></div></header>
        <div class="quick-grid">
            <?php foreach ($quickActions as [$icon, $label, $detail]): ?>
                <button class="quick-action" type="button" data-future-action="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="quick-icon" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <small><?= htmlspecialchars($detail) ?></small>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="content-grid">
        <section class="panel">
            <header class="panel-header">
                <div><h2>Acesso Administrativo</h2><p>IPs autorizados para acesso administrativo.</p></div>
                <input class="search" id="admin-access-search" type="search" placeholder="Pesquisar IP ou rede..." aria-label="Pesquisar IP ou rede">
            </header>
            <div class="table-wrap"><table>
                <thead><tr><th>Tipo</th><th>IP/Rede</th><th>Descrição</th><th>Data de criação</th><th>Ações</th></tr></thead>
                <tbody id="admin-access-rows">
                    <?php foreach ($adminAccess as [$type, $network, $description, $createdAt]): ?>
                        <tr data-search-text="<?= htmlspecialchars(strtolower("$type $network $description $createdAt"), ENT_QUOTES, 'UTF-8') ?>">
                            <td><span class="type-badge"><?= htmlspecialchars($type) ?></span></td>
                            <td class="strong"><?= htmlspecialchars($network) ?></td>
                            <td><?= htmlspecialchars($description) ?></td>
                            <td><?= htmlspecialchars($createdAt) ?></td>
                            <td><div class="row-actions"><button class="text-action" type="button" data-future-action="Editar IP">Editar</button><button class="text-action remove" type="button" data-future-action="Remover IP">Remover</button></div></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="empty-row" id="admin-access-empty" hidden><td colspan="5">Nenhum IP ou rede encontrado.</td></tr>
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <header class="panel-header"><div><h2>Portas Administrativas</h2><p>Portas restritas aos IPs autorizados.</p></div></header>
            <div class="table-wrap"><table>
                <thead><tr><th>Porta</th><th>Protocolo</th><th>Serviço</th><th>Descrição</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($adminPorts as [$port, $protocol, $service, $description]): ?>
                        <tr><td class="strong"><?= htmlspecialchars($port) ?></td><td><span class="type-badge"><?= htmlspecialchars($protocol) ?></span></td><td><?= htmlspecialchars($service) ?></td><td><?= htmlspecialchars($description) ?></td><td><div class="row-actions"><button class="text-action" type="button" data-future-action="Editar porta administrativa">Editar</button><button class="text-action remove" type="button" data-future-action="Remover porta administrativa">Remover</button></div></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <section class="panel">
            <header class="panel-header"><div><h2>Portas Públicas</h2><p>Disponíveis para acesso externo.</p></div></header>
            <div class="table-wrap"><table>
                <thead><tr><th>Porta</th><th>Protocolo</th><th>Serviço</th><th>Descrição</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($publicPorts as [$port, $protocol, $service, $description]): ?>
                        <tr><td class="strong"><?= htmlspecialchars($port) ?></td><td><span class="type-badge"><?= htmlspecialchars($protocol) ?></span></td><td><?= htmlspecialchars($service) ?></td><td><?= htmlspecialchars($description) ?></td><td><div class="row-actions"><button class="text-action" type="button" data-future-action="Editar porta pública">Editar</button><button class="text-action remove" type="button" data-future-action="Remover porta pública">Remover</button></div></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </section>

        <div class="stack">
            <section class="panel">
                <header class="panel-header"><div><h2>Última Validação</h2><p>Resultado da verificação mais recente.</p></div></header>
                <div class="validation"><span class="validation-icon" aria-hidden="true">✓</span><div><strong>Configuração válida</strong><span>Última validação: <time datetime="2026-06-21T09:35:00-03:00">21/06/2026 09:35</time></span><span>Tempo desde validação: <em>12 minutos atrás</em></span></div></div>
            </section>
            <section class="panel">
                <header class="panel-header"><div><h2>Auditoria Recente</h2><p>Últimas alterações.</p></div></header>
                <div class="audit-list">
                    <?php foreach ($recentAudit as [$user, $action, $time]): ?>
                        <div class="audit-item"><span class="audit-user"><?= htmlspecialchars($user) ?></span><span class="audit-action"><?= htmlspecialchars($action) ?></span><span class="audit-time"><?= htmlspecialchars($time) ?></span></div>
                    <?php endforeach; ?>
                </div>
                <a class="secondary-button" href="auditoria.php">Ver histórico completo</a>
            </section>
        </div>
    </div>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</main>
<div class="ui-toast" id="ui-toast" role="status" aria-live="polite" hidden></div>
<script>
const searchInput=document.getElementById('admin-access-search');
const accessRows=[...document.querySelectorAll('#admin-access-rows tr[data-search-text]')];
const emptyRow=document.getElementById('admin-access-empty');
searchInput.addEventListener('input',()=>{
    const term=searchInput.value.trim().toLocaleLowerCase('pt-BR');
    let visible=0;
    accessRows.forEach(row=>{const show=row.dataset.searchText.includes(term);row.hidden=!show;if(show)visible++});
    emptyRow.hidden=visible!==0;
});
const toast=document.getElementById('ui-toast');let toastTimer;
document.querySelectorAll('[data-future-action]').forEach(button=>button.addEventListener('click',()=>{
    clearTimeout(toastTimer);
    toast.textContent=button.dataset.futureAction+': ação disponível em uma próxima etapa.';
    toast.hidden=false;
    toastTimer=setTimeout(()=>{toast.hidden=true},3200);
}));
document.querySelector('[data-refresh-page]').addEventListener('click',()=>window.location.reload());
</script>
</body>
</html>
