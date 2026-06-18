<?php

require "config.php";
require "includes/auth.php";
require "includes/security.php";
require "includes/audit.php";

$zone = basename(trim($_GET['zone'] ?? $_POST['zone'] ?? ''));
$ptr = trim($_GET['ptr'] ?? $_POST['ptr'] ?? '');
$oldHost = trim($_GET['host'] ?? $_POST['old_host'] ?? '');

$erro = "";

if (!preg_match('/^[a-zA-Z0-9.-]+\.(?:rev6|rev)$/', $zone)) {
    die("Zona inválida.");
}

if ($ptr === '' || !valid_hostname($oldHost)) {
    die("PTR inválido.");
}

$file = "/var/cache/bind/master-rev/{$zone}";

if (!is_file($file)) {
    die("Zona reversa não encontrada.");
}

$bindZone = bind_zone_name_for_file($file);

if (!$bindZone) {
    die("Zona não encontrada no named.conf.local.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newHost = trim($_POST['host'] ?? '');

    if (!valid_hostname($newHost)) {
        $erro = "Hostname inválido.";
    }

    if (!$erro) {
        $newHost = rtrim($newHost, '.') . '.';

        $oldRecord = preg_replace('/\s+/', ' ', "{$ptr} IN PTR {$oldHost}");
        $newRecord = "{$ptr} IN PTR {$newHost}";

        $lines = file($file);
        $changed = false;
        $newLines = [];

        foreach ($lines as $line) {
            $clean = preg_replace('/\s+/', ' ', trim($line));

            if (!$changed && $clean === $oldRecord) {
                $newLines[] = $newRecord . "\n";
                $changed = true;
            } else {
                $newLines[] = $line;
            }
        }

        if (!$changed) {
            $erro = "PTR original não encontrado.";
        } else {
            $content = increment_zone_serial(implode('', $newLines));

            if (!validate_zone_content($bindZone, $content, $validationError)) {
                $erro = "Erro na zona reversa: " . $validationError;
            } elseif (!write_file_safely($file, $content)) {
                $erro = "Não foi possível gravar a zona.";

} else {

    $isIpv6 = str_ends_with($zone, '.rev6');
    $enderecoIpv6 = $isIpv6
        ? audit_ptr_ipv6_para_endereco($ptr, $bindZone)
        : null;

    registrar_auditoria([
        'acao'          => $isIpv6 ? 'EDITAR_PTR_IPV6' : 'EDITAR_PTR_IPV4',
        'dominio'       => $zone,
        'tipo_registro' => 'PTR',
        'nome_registro' => $isIpv6 ? 'PTR IPv6' : 'PTR IPv4',
        'valor_antigo'  => $enderecoIpv6
                            ? audit_valor_ptr($enderecoIpv6, $oldHost)
                            : $oldHost,
        'valor_novo'    => $enderecoIpv6
                            ? audit_valor_ptr($enderecoIpv6, $newHost)
                            : $newHost,
        'status'        => 'OK',
        'mensagem'      => 'PTR editado'
    ]);

    reload_dns();

    header(
                    "Location: edit-reverse-zone.php?zone=" .
                    urlencode($zone) .
                    "&success=" .
                    urlencode("PTR editado com sucesso")
                );
                exit;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Editar PTR</title>
<style>
body{margin:0;font-family:Arial;background:#0f172a;color:#e2e8f0;}
.container{width:700px;margin:50px auto;}
.card{background:#020617;padding:25px;border-radius:12px;}
input{width:100%;padding:12px;margin-top:10px;border-radius:6px;border:1px solid #334155;background:#020617;color:white;box-sizing:border-box;}
button{margin-top:15px;padding:12px 20px;background:#3b82f6;border:none;border-radius:6px;color:white;cursor:pointer;}
a{color:#38bdf8;text-decoration:none;}
.error{background:#7f1d1d;color:#fecaca;padding:12px;border-radius:8px;margin-bottom:15px;}
.info{background:#0f172a;border:1px solid #1e293b;padding:12px;border-radius:8px;margin-bottom:15px;color:#cbd5e1;}
</style>
</head>
<body>

<div class="container">
<div class="card">

<h2>✏️ Editar PTR</h2>

<?php if ($erro): ?>
<div class="error"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<div class="info">
PTR: <?= htmlspecialchars($ptr) ?><br>
Zona: <?= htmlspecialchars($zone) ?>
</div>

<form method="POST">
<?= csrf_field() ?>

<input type="hidden" name="zone" value="<?= htmlspecialchars($zone) ?>">
<input type="hidden" name="ptr" value="<?= htmlspecialchars($ptr) ?>">
<input type="hidden" name="old_host" value="<?= htmlspecialchars($oldHost) ?>">

<label>Hostname</label>
<input name="host" value="<?= htmlspecialchars($oldHost) ?>" required>

<button type="submit">Salvar alteração</button>

</form>

<br>

<a href="edit-reverse-zone.php?zone=<?= urlencode($zone) ?>">← Voltar</a>

</div>
</div>

<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
