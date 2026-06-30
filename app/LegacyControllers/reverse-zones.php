<?php
require_once dirname(__DIR__, 2) . "/config.php";
require_once dirname(__DIR__, 2) . "/app/Auth/auth.php";
require_once dirname(__DIR__, 2) . "/app/Support/security.php";
require_once dirname(__DIR__, 2) . "/app/Audit/audit.php";


$reverseDirectory = "/var/cache/bind/master-rev";
$namedConfFile = "/etc/bind/named.conf.local";
$domains = glob($reverseDirectory . "/*") ?: [];
$namedConf = @file_get_contents($namedConfFile);
$erro = $_SESSION['reverse_zones_error'] ?? null;
$sucesso = $_SESSION['reverse_zones_success'] ?? null;

unset($_SESSION['reverse_zones_error'], $_SESSION['reverse_zones_success']);

$ipv4zones = [];
$ipv6zones = [];

function dominioRelacionadoReverse(string $file): string
{
    $content = @file_get_contents($file);

    if ($content === false) {
        return '-';
    }

    if (preg_match('/SOA\s+ns1\.([^\s]+?)\.\s+hostmaster\./i', $content, $m)) {
        return $m[1];
    }

    if (preg_match('/SOA\s+\S+\.([a-z0-9.-]+)\.\s+/i', $content, $m)) {
        return $m[1];
    }

    return '-';
}

function zonaBindIpv4PorArquivo(string $fileName): ?string
{
    if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.rev$/', $fileName, $match)) {
        return null;
    }

    foreach (array_slice($match, 1) as $octet) {
        if ((int) $octet > 255) {
            return null;
        }
    }

    return $match[3] . '.' . $match[2] . '.' . $match[1] . '.in-addr.arpa';
}

function zonasBindPorArquivo(string $config): array
{
    $zonesByFile = [];

    if (!preg_match_all(
        '/\bzone\s+"([^"]+)"\s*\{(?:(?!\};).)*?\bfile\s+(?:"([^"]+)"|([^\s;]+))\s*;(?:(?!\};).)*?\};/si',
        $config,
        $blocks,
        PREG_SET_ORDER
    )) {
        return $zonesByFile;
    }

    foreach ($blocks as $block) {
        $configuredFile = $block[2] !== '' ? $block[2] : $block[3];
        $zonesByFile[$configuredFile] = $block[1];
    }

    return $zonesByFile;
}

function arquivoReverseEhIpv6(string $file): bool
{
    if (str_ends_with(strtolower($file), '.rev6')) {
        return true;
    }

    $content = @file_get_contents($file);

    return is_string($content) &&
        preg_match('/^\s*\$ORIGIN\s+[0-9a-f.]+\.ip6\.arpa\.?\s*$/mi', $content) === 1;
}

$bindZonesByFile = is_string($namedConf) ? zonasBindPorArquivo($namedConf) : [];
$bindZonesByBasename = [];

foreach ($bindZonesByFile as $configuredFile => $configuredZone) {
    $bindZonesByBasename[basename($configuredFile)] = $configuredZone;
}

foreach ($domains as $file) {

    $dom = basename($file);
    $isIpv6 = arquivoReverseEhIpv6($file);
    $bindZone = $bindZonesByFile[$file] ??
        $bindZonesByFile[$reverseDirectory . '/' . $dom] ??
        $bindZonesByBasename[$dom] ??
        null;
    $expectedZone = $isIpv6 ? null : zonaBindIpv4PorArquivo($dom);

    $item = [
        'file' => $dom,
        'domain' => dominioRelacionadoReverse($file),
        'bind_zone' => $bindZone,
        'expected_zone' => $expectedZone,
        'orphan' => $bindZone === null,
        'legacy_ipv6' => $isIpv6 && !str_ends_with(strtolower($dom), '.rev6')
    ];

    if ($isIpv6) {
        $ipv6zones[] = $item;
    } else {
        $ipv4zones[] = $item;
    }
}

if (isset($_POST['delete_orphan_reverse_file'])) {
    require_csrf();

    $fileName = trim((string) ($_POST['delete_orphan_reverse_file'] ?? ''));
    $confirmation = (string) ($_POST['orphan_delete_confirmation'] ?? '');
    $backupFile = null;
    $removed = false;
    $originalMode = null;
    $targetFile = null;
    $domain = null;
    $isIpv6Orphan = false;

    try {
        if ($fileName === '' ||
            $confirmation !== $fileName ||
            basename($fileName) !== $fileName ||
            !preg_match('/^[a-zA-Z0-9.-]+\.(?:rev6|rev)$/', $fileName)) {
            throw new RuntimeException('Arquivo ou confirmação inválida.');
        }

        $realReverseDirectory = realpath($reverseDirectory);
        $candidateFile = $reverseDirectory . '/' . $fileName;
        $targetFile = realpath($candidateFile);

        if ($realReverseDirectory === false ||
            $targetFile === false ||
            dirname($targetFile) !== $realReverseDirectory ||
            basename($targetFile) !== $fileName ||
            !is_file($targetFile)) {
            throw new RuntimeException('Arquivo de zona reversa inválido.');
        }

        $currentConfig = file_get_contents($namedConfFile);
        if ($currentConfig === false) {
            throw new RuntimeException('Não foi possível verificar a configuração do BIND.');
        }

        $currentZonesByFile = zonasBindPorArquivo($currentConfig);
        $isReferenced = isset($currentZonesByFile[$targetFile]) ||
            isset($currentZonesByFile[$candidateFile]);

        foreach (array_keys($currentZonesByFile) as $configuredFile) {
            if (basename($configuredFile) === $fileName) {
                $isReferenced = true;
                break;
            }
        }

        if ($isReferenced) {
            throw new RuntimeException('O arquivo não é órfão e não pode ser removido nesta tela.');
        }

        $domain = dominioRelacionadoReverse($targetFile);
        $isIpv6Orphan = arquivoReverseEhIpv6($targetFile);
        $originalMode = fileperms($targetFile);
        $backupFile = tempnam(sys_get_temp_dir(), 'orphan-reverse-backup-');

        if ($backupFile === false || !copy($targetFile, $backupFile)) {
            throw new RuntimeException('Não foi possível criar o backup antes da remoção.');
        }

        if (!unlink($targetFile)) {
            throw new RuntimeException('Não foi possível remover o arquivo órfão.');
        }
        $removed = true;

        registrar_auditoria([
            'acao' => 'REMOVER_ARQUIVO_REVERSO_ORFAO',
            'dominio' => $domain !== '-' ? $domain : $fileName,
            'tipo_registro' => 'ZONA',
            'nome_registro' => $isIpv6Orphan
                ? 'Arquivo reverso IPv6 órfão'
                : 'Arquivo reverso IPv4 órfão',
            'valor_antigo' => $targetFile,
            'valor_novo' => 'removido',
            'status' => 'SUCCESS',
            'mensagem' => 'Arquivo reverso sem referência no named.conf.local removido. applied=true; rollback=false.',
        ]);

        $_SESSION['reverse_zones_success'] = 'Arquivo reverso órfão removido com sucesso.';
    } catch (Throwable $exception) {
        $rollbackApplied = false;

        if ($removed &&
            is_string($backupFile) &&
            is_file($backupFile) &&
            is_string($targetFile)) {
            $rollbackApplied = copy($backupFile, $targetFile);

            if ($rollbackApplied && is_int($originalMode)) {
                chmod($targetFile, $originalMode & 0777);
            }
        }

        try {
            registrar_auditoria([
                'acao' => 'REMOVER_ARQUIVO_REVERSO_ORFAO',
                'dominio' => $domain !== null && $domain !== '-' ? $domain : $fileName,
                'tipo_registro' => 'ZONA',
                'nome_registro' => 'Arquivo reverso órfão',
                'valor_antigo' => $targetFile,
                'status' => 'ERROR',
                'mensagem' => $exception->getMessage()
                    . '; applied=false; rollback=' . ($rollbackApplied ? 'true' : 'false') . '.',
            ]);
        } catch (Throwable $auditException) {
            // A falha de auditoria não deve ocultar o resultado da operação principal.
        }

        $_SESSION['reverse_zones_error'] = $removed && !$rollbackApplied
            ? 'A remoção falhou e o arquivo não pôde ser restaurado. Verifique o servidor.'
            : 'Não foi possível remover o arquivo órfão. Nenhuma alteração foi aplicada.';
    } finally {
        if (is_string($backupFile) && is_file($backupFile)) {
            unlink($backupFile);
        }
    }

    header('Location: reverse-zones.php');
    exit;
}

usort($ipv4zones, function($a, $b) {
    return strcmp($a['file'], $b['file']);
});

usort($ipv6zones, function($a, $b) {
    return strcmp($a['file'], $b['file']);
});

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Zonas Reversas</title>
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
.search{max-width:440px}
.search label{
    display:block;
    margin-bottom:7px;
    color:#cbd5e1;
    font-size:14px;
}
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
.search input::placeholder{color:#64748b}
.zones-grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    align-items:start;
    gap:18px;
    margin-top:18px;
}
.zone-section{
    min-width:0;
    padding:16px;
    border:1px solid var(--line);
    border-radius:14px;
    background:rgba(7,18,38,.42);
}
.section-head{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
}
.section-head h2{
    margin:0 0 4px;
    color:#fff;
    font-size:20px;
}
.section-head p{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.4;
}
.zone-count{
    flex:0 0 auto;
    min-width:30px;
    padding:4px 8px;
    border:1px solid rgba(56,189,248,.24);
    border-radius:999px;
    background:rgba(14,116,144,.16);
    color:#bae6fd;
    font-size:12px;
    font-weight:700;
    text-align:center;
}
.zone-list{display:grid;gap:6px}
.zone-row{
    display:grid;
    grid-template-columns:minmax(0, 1fr) auto;
    align-items:center;
    gap:12px;
    min-height:58px;
    padding:8px 10px;
    border:1px solid var(--line);
    border-radius:12px;
    background:rgba(2,6,23,.7);
}
.zone-info{min-width:0}
.zone-name{
    display:block;
    color:#fff;
    font-weight:700;
    line-height:1.35;
    overflow-wrap:anywhere;
}
.zone-domain{
    display:block;
    margin-top:3px;
    color:var(--muted);
    font-size:12px;
    line-height:1.35;
    overflow-wrap:anywhere;
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
.delete-button:hover{border-color:#ef4444}
.orphan-label{
    display:inline-block;
    margin-top:5px;
    padding:2px 7px;
    border:1px solid rgba(245,158,11,.28);
    border-radius:999px;
    background:rgba(120,53,15,.24);
    color:#fde68a;
    font-size:11px;
}
.zone-unavailable{
    color:var(--muted);
    font-size:12px;
    white-space:nowrap;
}
.empty{
    padding:18px 16px;
    border:1px dashed var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.66);
    color:var(--muted);
}
.empty-filter{margin-top:18px}
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
.modal-file{
    margin:0 0 15px;
    padding:10px 12px;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:var(--panel);
    color:#fff;
    font-weight:700;
    overflow-wrap:anywhere;
}
.modal-body label{
    display:block;
    margin-bottom:6px;
    color:#cbd5e1;
    font-size:14px;
}
.modal-body input[type="text"]{
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
@media(max-width:800px){
    .zones-grid{grid-template-columns:1fr}
}
@media(max-width:560px){
    .page{width:min(100% - 20px, 1100px);margin:20px auto 30px}
    .card-inner{padding:18px}
    .zone-section{padding:14px}
    .zone-row{grid-template-columns:1fr}
    .zone-actions{justify-content:flex-start}
}
</style>
</head>
<body>
<main class="page">
    <header class="header">
        <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
        <h1>Zonas Reversas</h1>
        <p>Consulte e edite as zonas reversas IPv4 e IPv6 cadastradas no servidor.</p>
    </header>

    <?php if ($sucesso): ?>
        <div class="alert success" id="success-toast"><?= htmlspecialchars($sucesso, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alert error"><?= htmlspecialchars($erro, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="card">
        <div class="card-inner">
            <div class="search">
                <label for="filtro">Pesquisar zonas reversas</label>
                <input
                    type="search"
                    id="filtro"
                    placeholder="Pesquisar zona ou domínio..."
                    autocomplete="off">
            </div>

            <div class="zones-grid">
                <section class="zone-section" data-zone-section>
                    <div class="section-head">
                        <div>
                            <h2>Zona reversa IPv4</h2>
                            <p>Zonas baseadas em endereços IPv4.</p>
                        </div>
                        <span class="zone-count"><?= count($ipv4zones) ?></span>
                    </div>

                    <?php if (!$ipv4zones): ?>
                        <div class="empty">Nenhuma zona reversa IPv4 encontrada.</div>
                    <?php else: ?>
                        <div class="zone-list">
                            <?php foreach ($ipv4zones as $z): ?>
                                <div
                                    class="zone-row"
                                    data-search="<?= htmlspecialchars(
                                        strtolower($z['file'] . ' ' . $z['domain'] . ' ' . ($z['bind_zone'] ?? '')),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>">
                                    <div class="zone-info">
                                        <span class="zone-name"><?= htmlspecialchars($z['file'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="zone-domain">Domínio: <?= htmlspecialchars($z['domain'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($z['orphan']): ?>
                                            <span class="orphan-label">Arquivo órfão</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="zone-actions">
                                        <?php if ($z['bind_zone'] !== null): ?>
                                            <a
                                                class="edit-link"
                                                href="edit-reverse-zone.php?zone=<?= rawurlencode($z['bind_zone']) ?>">
                                                Editar Zona
                                            </a>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="delete-button"
                                                data-delete-orphan="<?= htmlspecialchars($z['file'], ENT_QUOTES, 'UTF-8') ?>">
                                                Remover órfão
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="zone-section" data-zone-section>
                    <div class="section-head">
                        <div>
                            <h2>Zona reversa IPv6</h2>
                            <p>Zonas baseadas em prefixos IPv6.</p>
                        </div>
                        <span class="zone-count"><?= count($ipv6zones) ?></span>
                    </div>

                    <?php if (!$ipv6zones): ?>
                        <div class="empty">Nenhuma zona reversa IPv6 encontrada.</div>
                    <?php else: ?>
                        <div class="zone-list">
                            <?php foreach ($ipv6zones as $z): ?>
                                <?php $display = str_replace('.rev6', '', $z['file']); ?>
                                <div
                                    class="zone-row"
                                    data-search="<?= htmlspecialchars(
                                        strtolower(
                                            $display . ' ' . $z['file'] . ' ' . $z['domain'] . ' ' .
                                            ($z['bind_zone'] ?? '')
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>">
                                    <div class="zone-info">
                                        <span class="zone-name"><?= htmlspecialchars($display, ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="zone-domain">Domínio: <?= htmlspecialchars($z['domain'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($z['orphan']): ?>
                                            <span class="orphan-label">
                                                <?= $z['legacy_ipv6'] ? 'Arquivo IPv6 legado órfão' : 'Arquivo órfão' ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="zone-actions">
                                        <?php if ($z['bind_zone'] !== null): ?>
                                            <a
                                                class="edit-link"
                                                href="edit-reverse-zone.php?zone=<?= rawurlencode($z['bind_zone']) ?>">
                                                Editar Zona
                                            </a>
                                        <?php else: ?>
                                            <button
                                                type="button"
                                                class="delete-button"
                                                data-delete-orphan="<?= htmlspecialchars($z['file'], ENT_QUOTES, 'UTF-8') ?>">
                                                Remover órfão
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <div id="empty-filter" class="empty empty-filter hidden">
                Nenhuma zona reversa corresponde à pesquisa.
            </div>
        </div>
    </section>
</main>

<dialog id="delete-orphan-modal" class="delete-modal">
    <div class="modal-head">
        <h2>Remover arquivo reverso órfão</h2>
        <p>A remoção só será permitida se o arquivo ainda não estiver referenciado no BIND.</p>
    </div>
    <form method="POST" class="modal-body">
        <?= csrf_field() ?>
        <input type="hidden" name="delete_orphan_reverse_file" id="delete-orphan-file">
        <div class="modal-file" id="delete-orphan-display"></div>

        <label for="delete-orphan-confirmation">
            Para confirmar, digite exatamente:
        </label>
        <input
            type="text"
            name="orphan_delete_confirmation"
            id="delete-orphan-confirmation"
            autocomplete="off"
            required>

        <p class="modal-warning">
            A ação será registrada na auditoria com usuário, IP, data, hora e resultado.
        </p>

        <div class="modal-actions">
            <button type="button" class="cancel-button" id="cancel-delete-orphan">Cancelar</button>
            <button type="submit" class="confirm-delete-button" id="confirm-delete-orphan" disabled>
                Remover arquivo órfão
            </button>
        </div>
    </form>
</dialog>

<?php require_once dirname(__DIR__, 2) . '/includes/session-timeout.php'; ?>

<script>
const filtro = document.getElementById('filtro');
const linhas = Array.from(document.querySelectorAll('.zone-row[data-search]'));
const vazioGeral = document.getElementById('empty-filter');
const successToast = document.getElementById('success-toast');
const deleteModal = document.getElementById('delete-orphan-modal');
const deleteFile = document.getElementById('delete-orphan-file');
const deleteDisplay = document.getElementById('delete-orphan-display');
const deleteConfirmation = document.getElementById('delete-orphan-confirmation');
const confirmDelete = document.getElementById('confirm-delete-orphan');
const cancelDelete = document.getElementById('cancel-delete-orphan');

if (successToast) {
    setTimeout(function() {
        successToast.remove();
    }, 3000);
}

filtro?.addEventListener('input', function() {
    const termo = this.value.trim().toLowerCase();
    let totalVisivel = 0;

    linhas.forEach(function(linha) {
        const mostrar = linha.dataset.search.includes(termo);
        linha.classList.toggle('hidden', !mostrar);

        if (mostrar) {
            totalVisivel++;
        }
    });

    vazioGeral?.classList.toggle('hidden', totalVisivel > 0 || termo === '');
});

document.querySelectorAll('[data-delete-orphan]').forEach(function(button) {
    button.addEventListener('click', function() {
        const fileName = this.dataset.deleteOrphan || '';

        deleteFile.value = fileName;
        deleteDisplay.textContent = fileName;
        deleteConfirmation.value = '';
        confirmDelete.disabled = true;
        deleteModal.showModal();
        deleteConfirmation.focus();
    });
});

deleteConfirmation?.addEventListener('input', function() {
    confirmDelete.disabled = this.value !== deleteFile.value;
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

<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
</body>
</html>
