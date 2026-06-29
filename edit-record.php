<?php

require "config.php";
require "app/Auth/auth.php";
require "app/Support/security.php";
require "app/Audit/audit.php";

$zone = strtolower(trim($_GET['zone'] ?? $_POST['zone'] ?? ''));
$oldHost = trim($_GET['host'] ?? $_POST['old_host'] ?? '');
$oldType = strtoupper(trim($_GET['type'] ?? $_POST['old_type'] ?? ''));
$oldValue = trim($_GET['value'] ?? $_POST['old_value'] ?? '');

$erro = "";

if (!valid_domain($zone)) {
    die("Zona inválida.");
}

if (!valid_hostname($oldHost) ||
    !in_array($oldType, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true) ||
    $oldValue === '') {
    die("Registro inválido.");
}

$file = "/var/cache/bind/master-aut/{$zone}.hosts";

if (!is_file($file)) {
    die("Arquivo da zona não encontrado.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $newHost = trim($_POST['host'] ?? '');
    $newType = strtoupper(trim($_POST['type'] ?? ''));
    $newValue = trim($_POST['value'] ?? '');
    $newValue = normalize_dns_value($newType, $newValue);

    if (!valid_hostname($newHost)) {
        $erro = "Host inválido.";
    } elseif (!in_array($newType, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)) {
        $erro = "Tipo inválido.";
    } elseif ($newValue === '') {
        $erro = "Valor inválido.";
    } elseif ($newType === 'A' &&
        !filter_var($newValue, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $erro = "IPv4 inválido.";
    } elseif ($newType === 'AAAA' &&
        !filter_var($newValue, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $erro = "IPv6 inválido.";
    } elseif ($newType === 'CNAME' &&
        !valid_hostname($newValue)) {
        $erro = "CNAME inválido.";
    } elseif ($newType === 'MX') {
        $parts = preg_split('/\s+/', $newValue, 2);

        if (
            count($parts) !== 2 ||
            !ctype_digit($parts[0]) ||
            (int) $parts[0] > 65535 ||
            !valid_hostname($parts[1])
        ) {
            $erro = "MX inválido.";
        }
    } elseif ($newType === 'TXT' &&
        !preg_match('/^".*"$/', $newValue)) {
        $erro = "TXT deve usar aspas.";
    }

    if (!$erro) {
        $oldRecord = preg_replace('/\s+/', ' ', "{$oldHost} IN {$oldType} {$oldValue}");
        $newRecord = "{$newHost} IN {$newType} {$newValue}";

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
            $erro = "Registro original não encontrado.";
        } else {
            $content = format_forward_zone_content(implode('', $newLines));
            $content = increment_zone_serial($content);

            if (!validate_zone_content($zone, $content, $validationError)) {
                $erro = "Erro na zona DNS: " . $validationError;
            } elseif (!write_file_safely($file, $content)) {
                $erro = "Não foi possível gravar a zona.";

} else {

    registrar_auditoria([
        'acao'          => 'EDITAR_REGISTRO',
        'dominio'       => $zone,
        'tipo_registro' => $newType,
        'nome_registro' => $newHost,
        'valor_antigo'  => "{$oldHost} IN {$oldType} {$oldValue}",
        'valor_novo'    => "{$newHost} IN {$newType} {$newValue}",
        'status'        => 'OK',
        'mensagem'      => 'Registro DNS editado'
    ]);

    reload_dns();

    header(
                    "Location: edit-zone.php?zone=" .
                    urlencode($zone) .
                    "&success=" .
                    urlencode("Registro editado com sucesso")
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
<title>Editar Registro</title>
<style>
body{margin:0;font-family:Arial;background:#0f172a;color:#e2e8f0;}
.container{width:700px;margin:50px auto;}
.card{background:#020617;padding:25px;border-radius:12px;}
input,select{width:100%;padding:12px;margin-top:10px;border-radius:6px;border:1px solid #334155;background:#020617;color:white;box-sizing:border-box;}
button{margin-top:15px;padding:12px 20px;background:#3b82f6;border:none;border-radius:6px;color:white;cursor:pointer;}
a{color:#38bdf8;text-decoration:none;}
.error{background:#7f1d1d;color:#fecaca;padding:12px;border-radius:8px;margin-bottom:15px;}
</style>
</head>
<body>

<div class="container">
<div class="card">

<h2>✏️ Editar Registro</h2>

<?php if ($erro): ?>
<div class="error"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<form method="POST">
<?= csrf_field() ?>

<input type="hidden" name="zone" value="<?= htmlspecialchars($zone) ?>">
<input type="hidden" name="old_host" value="<?= htmlspecialchars($oldHost) ?>">
<input type="hidden" name="old_type" value="<?= htmlspecialchars($oldType) ?>">
<input type="hidden" name="old_value" value="<?= htmlspecialchars($oldValue) ?>">

<label>Host</label>
<input name="host" value="<?= htmlspecialchars($oldHost) ?>" required>

<label>Tipo</label>
<select name="type">
<?php foreach (['A', 'AAAA', 'CNAME', 'MX', 'TXT'] as $type): ?>
<option value="<?= $type ?>" <?= $oldType === $type ? 'selected' : '' ?>>
<?= $type ?>
</option>
<?php endforeach; ?>
</select>

<label>Valor</label>
<input name="value" value="<?= htmlspecialchars($oldValue) ?>" required>

<button type="submit">Salvar alteração</button>

</form>

<br>

<a href="edit-zone.php?zone=<?= urlencode($zone) ?>">← Voltar</a>

</div>
</div>

<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>

</body>
</html>
