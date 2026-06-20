<?php

require "config.php";
require "includes/auth.php";
require "includes/security.php";
require "includes/audit.php";

$zoneFiles = glob('/var/cache/bind/master-aut/*.hosts') ?: [];
$domains = [];
$domainFiles = [];
$erro = $_SESSION['dns_zones_error'] ?? null;
$sucesso = $_SESSION['dns_zones_success'] ?? null;

unset($_SESSION['dns_zones_error'], $_SESSION['dns_zones_success']);

foreach ($zoneFiles as $file) {
    if (!is_file($file)) {
        continue;
    }

    $domain = basename($file, '.hosts');

    if ($domain !== '') {
        $domains[] = $domain;
        $domainFiles[$domain] = $file;
    }
}

sort($domains, SORT_NATURAL | SORT_FLAG_CASE);

if (isset($_POST['delete_forward_zone'])) {
    require_csrf();

    $domain = trim((string) ($_POST['delete_forward_zone'] ?? ''));
    $confirmation = (string) ($_POST['delete_confirmation'] ?? '');
    $zoneFile = $domainFiles[$domain] ?? null;
    $confFile = '/etc/bind/named.conf.local';
    $backupConf = null;
    $backupZone = null;
    $technicalMessage = null;
    $confChanged = false;
    $zoneRemoved = false;
    $zoneMode = null;

    try {
        if (!valid_domain($domain) ||
            preg_match('/[\/\\\\:\s]/', $domain) ||
            $confirmation !== $domain ||
            !is_string($zoneFile) ||
            !is_file($zoneFile)) {
            throw new RuntimeException('Domínio ou confirmação inválida.');
        }

        $baseDirectory = realpath('/var/cache/bind/master-aut');
        $realZoneFile = realpath($zoneFile);
        $zoneMode = fileperms($zoneFile);

        if ($baseDirectory === false ||
            $realZoneFile === false ||
            dirname($realZoneFile) !== $baseDirectory ||
            basename($realZoneFile) !== $domain . '.hosts') {
            throw new RuntimeException('Arquivo de zona inválido.');
        }

        $originalConf = file_get_contents($confFile);
        if ($originalConf === false) {
            throw new RuntimeException('Não foi possível ler a configuração do BIND.');
        }

        $zonePattern = '/^\s*zone\s+"' . preg_quote($domain, '/') .
            '"\s*\{(?:(?!^\s*\};).)*^\s*\};\s*/msi';

        if (!preg_match($zonePattern, $originalConf, $zoneBlock)) {
            throw new RuntimeException('A declaração da zona não foi encontrada na configuração do BIND.');
        }

        if (!preg_match('/\bfile\s+(?:"([^"]+)"|([^\s;]+))\s*;/i', $zoneBlock[0], $fileMatch)) {
            throw new RuntimeException('O arquivo da zona não foi identificado na configuração do BIND.');
        }

        $configuredFile = $fileMatch[1] !== '' ? $fileMatch[1] : $fileMatch[2];
        if ($configuredFile !== $realZoneFile) {
            throw new RuntimeException('A declaração da zona não corresponde ao arquivo forward esperado.');
        }

        $backupConf = tempnam(sys_get_temp_dir(), 'named-conf-backup-');
        $backupZone = tempnam(sys_get_temp_dir(), 'forward-zone-backup-');

        if ($backupConf === false ||
            $backupZone === false ||
            !copy($confFile, $backupConf) ||
            !copy($realZoneFile, $backupZone)) {
            throw new RuntimeException('Não foi possível criar o backup antes da exclusão.');
        }

        $updatedConf = preg_replace($zonePattern, '', $originalConf, 1, $removedBlocks);
        if ($updatedConf === null || $removedBlocks !== 1) {
            throw new RuntimeException('Não foi possível preparar a remoção da declaração da zona.');
        }

        if (!write_file_safely($confFile, $updatedConf)) {
            throw new RuntimeException('Não foi possível atualizar a configuração do BIND.');
        }
        $confChanged = true;

        if (!unlink($realZoneFile)) {
            throw new RuntimeException('Não foi possível remover o arquivo da zona forward.');
        }
        $zoneRemoved = true;

        exec('/usr/bin/named-checkconf -z 2>&1', $checkOutput, $checkStatus);
        if ($checkStatus !== 0) {
            $technicalMessage = implode("\n", $checkOutput);
            throw new RuntimeException('A configuração resultante do BIND é inválida. A exclusão foi desfeita.');
        }

        if (!reload_dns()) {
            throw new RuntimeException('Não foi possível recarregar o BIND. A exclusão foi desfeita.');
        }

        registrar_auditoria([
            'acao' => 'DELETE_FORWARD_ZONE',
            'dominio' => $domain,
            'tipo_registro' => 'ZONA',
            'nome_registro' => 'Forward',
            'valor_antigo' => $realZoneFile,
            'valor_novo' => 'removido',
            'status' => 'SUCCESS',
            'mensagem' => 'Zona forward removida com sucesso.',
        ]);

        $_SESSION['dns_zones_success'] = 'Zona removida com sucesso.';
    } catch (Throwable $exception) {
        if ($confChanged && is_string($backupConf) && is_file($backupConf)) {
            $backupConfContent = file_get_contents($backupConf);
            if ($backupConfContent !== false) {
                write_file_safely($confFile, $backupConfContent);
            }
        }
        if ($zoneRemoved &&
            is_string($backupZone) &&
            is_file($backupZone) &&
            is_string($zoneFile)) {
            copy($backupZone, $zoneFile);
            if (is_int($zoneMode)) {
                chmod($zoneFile, $zoneMode & 0777);
            }
        }
        if ($confChanged || $zoneRemoved) {
            reload_dns();
        }

        $erro = $exception->getMessage();

        registrar_auditoria([
            'acao' => 'DELETE_FORWARD_ZONE',
            'dominio' => valid_domain($domain) ? $domain : null,
            'tipo_registro' => 'ZONA',
            'nome_registro' => 'Forward',
            'valor_antigo' => is_string($zoneFile) ? $zoneFile : null,
            'status' => 'ERROR',
            'mensagem' => $technicalMessage !== null
                ? $erro . "\n" . $technicalMessage
                : $erro,
        ]);

        $_SESSION['dns_zones_error'] = $erro;
    } finally {
        if (is_string($backupConf) && is_file($backupConf)) {
            unlink($backupConf);
        }
        if (is_string($backupZone) && is_file($backupZone)) {
            unlink($backupZone);
        }
    }

    header('Location: dns-zones.php');
    exit;
}

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
    --danger:#ef4444;
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
.alert{
    margin-bottom:16px;
    padding:12px 14px;
    border:1px solid transparent;
    border-radius:12px;
}
.alert.success{
    border-color:rgba(34,197,94,.25);
    background:rgba(20,83,45,.92);
    color:#bbf7d0;
}
.alert.error{
    border-color:rgba(239,68,68,.24);
    background:rgba(127,29,29,.92);
    color:#fecaca;
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
.zone-actions{
    display:flex;
    align-items:center;
    gap:8px;
}
.edit-link,
.delete-button{
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
.delete-button{
    cursor:pointer;
    border-color:rgba(239,68,68,.28);
    background:rgba(127,29,29,.92);
    color:#fecaca;
    font:inherit;
    font-size:13px;
}
.delete-button:hover{border-color:var(--danger)}
.empty{
    padding:18px 16px;
    border:1px dashed var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.66);
    color:var(--muted);
}
.delete-modal{
    width:min(500px, calc(100% - 28px));
    padding:0;
    border:1px solid rgba(239,68,68,.35);
    border-radius:16px;
    background:#071226;
    color:var(--text);
    box-shadow:0 24px 70px rgba(0,0,0,.5);
}
.delete-modal::backdrop{
    background:rgba(2,6,23,.78);
    backdrop-filter:blur(3px);
}
.modal-head{
    padding:18px 20px 14px;
    border-bottom:1px solid var(--line);
}
.modal-head h2{margin:0 0 6px;color:#fecaca;font-size:21px}
.modal-head p{margin:0;color:var(--muted);line-height:1.45}
.modal-body{padding:18px 20px 20px}
.modal-domain{
    margin:0 0 15px;
    padding:10px 12px;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:var(--panel);
    color:#fff;
    font-weight:700;
    word-break:break-word;
}
.modal-body label{
    display:block;
    margin-bottom:6px;
    color:#cbd5e1;
    font-size:14px;
}
.modal-body input{
    width:100%;
    min-height:40px;
    padding:9px 11px;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:var(--panel);
    color:#fff;
    font:inherit;
    outline:none;
}
.modal-body input:focus{
    border-color:rgba(239,68,68,.7);
    box-shadow:0 0 0 3px rgba(239,68,68,.12);
}
.modal-warning{
    margin:12px 0 0;
    color:#fca5a5;
    font-size:13px;
    line-height:1.45;
}
.modal-actions{
    display:flex;
    justify-content:flex-end;
    gap:8px;
    margin-top:18px;
}
.modal-actions button{
    min-height:38px;
    padding:8px 12px;
    border:1px solid var(--line-soft);
    border-radius:9px;
    color:#fff;
    font:inherit;
    cursor:pointer;
}
.cancel-button{background:#1e293b}
.confirm-delete-button{
    border-color:rgba(239,68,68,.35) !important;
    background:#991b1b;
}
.confirm-delete-button:disabled{
    cursor:not-allowed;
    opacity:.45;
}
.hidden{display:none}
@media(max-width:560px){
    .page{width:min(100% - 20px, 1100px);margin:20px auto 30px}
    .card-inner{padding:18px}
    .zone-row{grid-template-columns:1fr}
    .zone-actions{justify-content:flex-start;flex-wrap:wrap}
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

    <?php if ($sucesso): ?>
        <div class="alert success" id="success-toast"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert error"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

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
                            <div class="zone-actions">
                                <a class="edit-link" href="edit-zone.php?zone=<?= rawurlencode($domain) ?>">Editar Zona</a>
                                <button
                                    type="button"
                                    class="delete-button"
                                    data-delete-domain="<?= htmlspecialchars($domain, ENT_QUOTES, 'UTF-8') ?>">
                                    Excluir
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="empty-filter" class="empty hidden">Nenhum domínio encontrado.</div>
            <?php endif; ?>
        </div>
    </section>
</main>

<dialog id="delete-zone-modal" class="delete-modal">
    <div class="modal-head">
        <h2>Excluir zona forward</h2>
        <p>Esta ação remove somente a zona forward. As zonas reversas não serão alteradas.</p>
    </div>
    <form method="POST" class="modal-body" id="delete-zone-form">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_forward_zone" id="delete-forward-zone">

        <div class="modal-domain" id="delete-domain-display"></div>

        <label for="delete-confirmation">
            Digite exatamente o domínio acima para confirmar:
        </label>
        <input
            type="text"
            name="delete_confirmation"
            id="delete-confirmation"
            autocomplete="off"
            required>

        <p class="modal-warning">
            O arquivo da zona e sua declaração no BIND serão removidos após validação da configuração.
        </p>

        <div class="modal-actions">
            <button type="button" class="cancel-button" id="cancel-delete">Cancelar</button>
            <button type="submit" class="confirm-delete-button" id="confirm-delete" disabled>
                Excluir zona
            </button>
        </div>
    </form>
</dialog>

<script>
const filtro = document.getElementById('filtro');
const linhas = Array.from(document.querySelectorAll('.zone-row'));
const vazio = document.getElementById('empty-filter');
const deleteModal = document.getElementById('delete-zone-modal');
const deleteDomainInput = document.getElementById('delete-forward-zone');
const deleteDomainDisplay = document.getElementById('delete-domain-display');
const deleteConfirmation = document.getElementById('delete-confirmation');
const confirmDelete = document.getElementById('confirm-delete');
const cancelDelete = document.getElementById('cancel-delete');
const successToast = document.getElementById('success-toast');

if (successToast) {
    setTimeout(function() {
        successToast.style.transition = 'opacity .4s, transform .4s';
        successToast.style.opacity = '0';
        successToast.style.transform = 'translateY(-8px)';

        setTimeout(function() {
            successToast.remove();
        }, 400);
    }, 3000);
}

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

document.querySelectorAll('[data-delete-domain]').forEach(function(button) {
    button.addEventListener('click', function() {
        const domain = this.dataset.deleteDomain || '';

        deleteDomainInput.value = domain;
        deleteDomainDisplay.textContent = domain;
        deleteConfirmation.value = '';
        confirmDelete.disabled = true;
        deleteModal.showModal();
        deleteConfirmation.focus();
    });
});

deleteConfirmation?.addEventListener('input', function() {
    confirmDelete.disabled = this.value !== deleteDomainInput.value;
});

cancelDelete?.addEventListener('click', function() {
    deleteModal.close();
});

deleteModal?.addEventListener('click', function(event) {
    if (event.target === deleteModal) {
        deleteModal.close();
    }
});
</script>
</body>
</html>
