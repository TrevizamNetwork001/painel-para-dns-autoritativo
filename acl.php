<?php include 'includes/auth.php'; ?>
<?php
require "includes/security.php";

$file = "/etc/nftables.d/acl4.conf";

$content = file_get_contents($file);

preg_match_all('/([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+\/?[0-9]*)/', $content, $matches);

$ips = $matches[1];

$msg = "";

$error = "";

if(isset($_GET['success'])){

    $msg = "ACL atualizada com sucesso.";
}

if(isset($_GET['error'])){

    if($_GET['error'] == 'empty'){

        $error = "Informe uma ACL válida.";
    }

    if($_GET['error'] == 'invalid'){

        $error = "Formato de ACL inválido.";
    }

    if($_GET['error'] == 'duplicate'){

        $error = "ACL já cadastrada.";
    }

    if($_GET['error'] == 'apply'){

        $error = "A regra foi rejeitada pelo NFTables.";
    }
}

if (isset($_POST['delete_acl'])) {
    require_csrf();

    $del = trim($_POST['delete_acl'] ?? '');

    $ips = array_filter($ips, function($ip) use ($del) {

         return trim($ip) != trim($del);
    });

    $config = "define ACL4 = {\n";

    $ips = array_values($ips);

    foreach ($ips as $k => $ip) {

        $comma = ($k < count($ips)-1) ? "," : "";

        $config .= "    $ip$comma\n";
    }

    $config .= "}\n";

    $oldConfig = file_get_contents($file);
    write_file_safely($file, $config);
    exec("sudo /usr/sbin/nft -f /etc/nftables.conf 2>&1", $output, $status);

    if ($status !== 0) {
        write_file_safely($file, $oldConfig);
        header("Location: acl.php?error=apply");
        exit;
    }

    header("Location: acl.php");

    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
require_csrf();
$newip = trim($_POST['ip']);

if ($newip == "") {

    header("Location: acl.php?error=empty");

    exit;
}

if (!valid_cidr($newip, FILTER_FLAG_IPV4)) {

    header("Location: acl.php?error=invalid");

    exit;
}

if (in_array($newip, $ips)) {

    header("Location: acl.php?error=duplicate");

    exit;
}
$ips[] = $newip;

$ips = array_unique($ips);

$config = "define ACL4 = {\n";

foreach ($ips as $k => $ip) {

    $comma = ($k < count($ips)-1) ? "," : "";

    $config .= "    $ip$comma\n";
}

$config .= "}\n";

$oldConfig = file_get_contents($file);
write_file_safely($file, $config);
exec("sudo /usr/sbin/nft -f /etc/nftables.conf 2>&1", $output, $status);

if ($status !== 0) {
    write_file_safely($file, $oldConfig);
    header("Location: acl.php?error=apply");
    exit;
}

header("Location: acl.php?success=1");

exit;
}

?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ACL IPv4</title>
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
    --ok:#16a34a;
    --bad:#dc2626;
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
.notice{
    margin-top:14px;
    padding:12px 14px;
    border-radius:12px;
    font-size:13px;
    line-height:1.5;
}
.notice.success{
    background:rgba(22,163,74,.12);
    border:1px solid rgba(22,163,74,.28);
    color:#bbf7d0;
}
.notice.error{
    background:rgba(220,38,38,.12);
    border:1px solid rgba(220,38,38,.28);
    color:#fecaca;
}
.form-grid{
    display:grid;
    grid-template-columns:minmax(0, 1fr) auto;
    gap:12px;
    align-items:end;
    margin-top:12px;
}
.field input{
    width:100%;
    padding:12px 14px;
    border:1px solid var(--border);
    border-radius:10px;
    background:var(--panel-2);
    color:var(--text);
    outline:none;
}
.field input:focus{
    border-color:#3b82f6;
    box-shadow:0 0 0 3px rgba(59,130,246,.12);
}
.btn{
    border:1px solid rgba(56,189,248,.4);
    background:linear-gradient(180deg,#2563eb,#1d4ed8);
    color:#fff;
    padding:12px 18px;
    border-radius:10px;
    cursor:pointer;
    font-size:14px;
    white-space:nowrap;
}
.acl-list{
    display:grid;
    gap:10px;
    margin-top:12px;
}
.acl-item{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:12px;
    padding:12px 14px;
    border:1px solid var(--border);
    border-radius:12px;
    background:rgba(17,28,51,.72);
}
.acl-value{
    font-size:14px;
    color:#dbe7f5;
    word-break:break-word;
}
.remove-btn{
    border:1px solid rgba(220,38,38,.35);
    background:rgba(220,38,38,.12);
    color:#fecaca;
    padding:8px 12px;
    border-radius:10px;
    cursor:pointer;
    white-space:nowrap;
}
.remove-btn:hover{
    background:rgba(220,38,38,.18);
}
@media (max-width: 720px){
    .page{width:calc(100% - 18px);padding-top:14px}
    h1{font-size:24px}
    .form-grid{grid-template-columns:1fr}
    .acl-item{flex-direction:column;align-items:flex-start}
    .remove-btn{width:100%}
}
</style>
</head>
<body>
<div class="page">
    <a class="back" href="dashboard.php">← Voltar</a>
    <h1>ACL IPv4</h1>
    <p class="subtitle">Cadastro e remoção de ACL IPv4 para nftables.</p>

    <?php if($error): ?>
        <div class="notice error"><?= $error ?></div>
    <?php endif; ?>

    <?php if($msg): ?>
        <div class="notice success"><?= $msg ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Adicionar IP/Rede</h2>
        <p>Informe um IP ou rede em CIDR para liberar acesso no firewall.</p>

        <form method="POST">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <input type="text" name="ip" placeholder="Ex: 198.50.0.10/32 ou 198.50.0.0/24">
                </div>
                <button class="btn" type="submit">Adicionar ACL</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>ACL Atual</h2>
        <p>IPs e redes atualmente permitidos.</p>

        <div class="acl-list">
            <?php foreach($ips as $ip): ?>
                <div class="acl-item">
                    <div class="acl-value"><?= htmlspecialchars($ip) ?></div>
                    <form method="POST" style="margin:0" onsubmit="return confirm('Remover ACL <?= htmlspecialchars($ip, ENT_QUOTES) ?> ?')">
                        <?= csrf_field() ?>
                        <button class="remove-btn" type="submit" name="delete_acl" value="<?= htmlspecialchars($ip) ?>">Remover</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>

<script>
setTimeout(() => {
    const success = document.querySelector('.notice.success');
    const error = document.querySelector('.notice.error');
    if (success) success.style.display = 'none';
    if (error) error.style.display = 'none';
}, 3000);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
