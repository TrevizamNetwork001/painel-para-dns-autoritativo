<?php
require_once dirname(__DIR__, 2) . "/config.php";
require_once dirname(__DIR__, 2) . "/app/Auth/auth.php";
require_once dirname(__DIR__, 2) . "/app/Support/security.php";
require_once dirname(__DIR__, 2) . "/app/Audit/audit.php";

function atualizarSerial($file)
{
    $content = file_get_contents($file);

    if (preg_match('/^\s*(\d{10})\s*$/m', $content, $match)) {

        $serialAntigo = $match[1];
        $serialNovo = $serialAntigo + 1;

        $content = preg_replace(
            '/^\s*' . preg_quote($serialAntigo, '/') . '\s*$/m',
            $serialNovo,
            $content,
            1
        );

        file_put_contents($file, $content);
    }
}


if (!isset($_GET['zone'])) {

    die("Zona inválida");
}

$zone = strtolower(trim($_GET['zone']));

if (!valid_domain($zone)) {
    die("Zona inválida");
}

$file = "/var/cache/bind/master-aut/$zone.hosts";
$erro = "";
$sucesso = "";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $sucesso = $_GET['success'] ?? '';
}

if (!file_exists($file)) {

    die("Arquivo da zona não encontrado");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    require_csrf();

    $host  = trim($_POST['host'] ?? '');
    $type  = strtoupper(trim($_POST['type'] ?? ''));
    $value = trim($_POST['value'] ?? '');
    $value = normalize_dns_value($type, $value);
    if ($host && $type && $value) {

        $erro = "";

        if (
            !valid_hostname($host)
        ) {

            $erro = "Host inválido";
        }

        if ($type == "A") {

            if (
                !filter_var(
                    $value,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                )
            ) {

                $erro = "IPv4 inválido";
            }
        }

        if ($type == "AAAA") {

            if (
                !filter_var(
                    $value,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV6
                )
            ) {

                $erro = "IPv6 inválido";
            }
        }

        if ($type == "CNAME") {

            if (!valid_hostname($value)) {

                $erro = "CNAME inválido";
            }
        }

        if ($type == "MX") {

            $parts = preg_split('/\s+/', $value, 2);

            if (
                count($parts) != 2 ||
                !ctype_digit($parts[0]) ||
                (int) $parts[0] > 65535 ||
                !valid_hostname($parts[1])
            ) {

                $erro = "MX inválido";
            }
        }

        if ($type == "TXT") {

            if (
                !preg_match(
                    '/^".*"$/',
                    $value
                )
            ) {

                $erro = "TXT deve usar aspas";
            }
        }

        if (!in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true)) {
            $erro = "Tipo de registro inválido";
        }

if (!$erro) {

    $newrecord =
        $host . " IN " .
        $type . " " .
        $value;

    $zonecontent = file_get_contents($file);

$linhas = file($file, FILE_IGNORE_NEW_LINES);

foreach ($linhas as $linha) {

    $linha = preg_replace('/\s+/', ' ', trim($linha));

    if ($linha == $newrecord) {

        $erro = "Registro já existe";
        break;
    }
}

    if (!$erro) {

           $newContent = rtrim(file_get_contents($file)) . "\n" . $newrecord . "\n";
           $newContent = format_forward_zone_content($newContent);
           $newContent = increment_zone_serial($newContent);

        if (!validate_zone_content($zone, $newContent, $validationError)) {
            $erro = "Erro na zona DNS: " . $validationError;
        } elseif (!write_file_safely($file, $newContent)) {
            $erro = "Não foi possível gravar a zona.";

} else {

    registrar_auditoria([
        'acao'          => 'ADICIONAR_REGISTRO',
        'dominio'       => $zone,
        'tipo_registro' => $type,
        'nome_registro' => $host,
        'valor_novo'    => $newrecord,
        'status'        => 'OK',
        'mensagem'      => 'Registro DNS adicionado'
    ]);

    reload_dns();

    header(
        "Location: edit-zone.php?zone=" .
        urlencode($zone) .
        "&success=" .
        urlencode("Registro adicionado com sucesso")
    );
    exit;
}

}
}

    }
}

$content = file($file);
$records = [];

foreach ($content as $line) {

    $line = trim($line);

    if (
        $line == "" ||
        str_starts_with($line, ";") ||
        str_starts_with($line, "$") ||
        str_contains($line, "SOA") ||
        $line == ")" ||
        is_numeric($line)
    ) {

        continue;
    }

    if (preg_match('/^(\S+)\s+IN\s+(A|AAAA|CNAME|MX|TXT)\s+(.+)$/', $line, $parts)) {

        $records[] = [
            'host'  => $parts[1],
            'type'  => $parts[2],
            'value' => $parts[3]
        ];
    }
}

?>

<!DOCTYPE html>
<html>

<head>

<meta charset="UTF-8">

<title>Editar Zona</title>

<style>

body{

    margin:0;
    font-family:Arial;
    background:#0f172a;
    color:#e2e8f0;
}

.container{

    width:1000px;
    margin:40px auto;
}

.card{

    background:#020617;
    padding:25px;
    border-radius:12px;
}

table{

    width:100%;
    border-collapse:collapse;
}

td{

    padding:10px;
    border-bottom:1px solid #1e293b;
    font-family:monospace;
}

a{

    color:#38bdf8;
    text-decoration:none;
}

#toast-success {

    position: fixed;
    top: 20px;
    right: 20px;

    background: #166534;
    color: white;

    padding: 15px;

    border-radius: 8px;

    z-index: 9999;

    box-shadow: 0 0 10px rgba(0,0,0,0.3);
}
</style>

</head>

<body>

<div class="container">

<div class="card">

<h2>

📄 Zona:
<?= htmlspecialchars($zone) ?>

</h2>
<p>
<a href="auditoria.php?dominio=<?= urlencode($zone) ?>">📋 Histórico</a>
</p>
<?php if(!empty($sucesso)): ?>

<div id="toast-success">

<?= htmlspecialchars($sucesso) ?>

</div>

<?php endif; ?>
<?php if(!empty($erro)): ?>

<div id="toast-error" style="
background:#7f1d1d;
padding:12px;
border-radius:8px;
margin-bottom:20px;
color:#fecaca;
">

<?= htmlspecialchars($erro) ?>

</div>

<?php endif; ?>
<form method="POST">
<?= csrf_field() ?>

<div style="margin-bottom:20px;display:flex;gap:10px;">

<input type="text"
name="host"
placeholder="Host"
required
style="
flex:1;
padding:10px;
background:#0f172a;
border:none;
border-radius:8px;
color:white;
">

<select
name="type"
style="
padding:10px;
background:#0f172a;
border:none;
border-radius:8px;
color:white;
">

<option>A</option>
<option>AAAA</option>
<option>CNAME</option>
<option>MX</option>
<option>TXT</option>

</select>

<input type="text"
name="value"
placeholder="Valor"
required
style="
flex:2;
padding:10px;
background:#0f172a;
border:none;
border-radius:8px;
color:white;
">

<button
type="submit"
style="
padding:10px 20px;
background:#2563eb;
border:none;
border-radius:8px;
color:white;
cursor:pointer;
">

Adicionar

</button>

</div>

</form>

<table>

<tr>

<td><b>Host</b></td>
<td><b>Tipo</b></td>
<td><b>Valor</b></td>
<td><b>Ação</b></td>

</tr>

<?php foreach($records as $r): ?>

<tr>

<td><?= htmlspecialchars($r['host']) ?></td>

<td><?= htmlspecialchars($r['type']) ?></td>

<td><?= htmlspecialchars($r['value']) ?></td>

<td style="white-space:nowrap;">

<a
href="edit-record.php?zone=<?= urlencode($zone) ?>&host=<?= urlencode($r['host']) ?>&type=<?= urlencode($r['type']) ?>&value=<?= urlencode($r['value']) ?>"
style="
display:inline-block;
color:#38bdf8;
background:#0f172a;
border:1px solid #1e293b;
padding:5px 8px;
font-size:12px;
border-radius:6px;
text-decoration:none;
margin-right:8px;
">
✏️ Editar
</a>

<form
method="POST"
action="delete-record.php"
style="display:inline-block;margin:0;"
onsubmit="return confirm('Remover registro?')">
<?= csrf_field() ?>
<input type="hidden" name="zone" value="<?= htmlspecialchars($zone) ?>">
<input type="hidden" name="host" value="<?= htmlspecialchars($r['host']) ?>">
<input type="hidden" name="type" value="<?= htmlspecialchars($r['type']) ?>">
<input type="hidden" name="value" value="<?= htmlspecialchars($r['value']) ?>">

<button
type="submit"
style="
color:#fecaca;
background:#7f1d1d;
border:1px solid #991b1b;
padding:5px 8px;
font-size:12px;
border-radius:6px;
cursor:pointer;
">
🗑 Remover
</button>

</form>

</td>
</td>

</tr>

<?php endforeach; ?>

</table>

<br>

<a href="dns-zones.php">

← Voltar

</a>

</div>

</div>
<script>

setTimeout(function() {

    let toastSuccess = document.getElementById('toast-success');
    let toastError = document.getElementById('toast-error');

    if (toastSuccess) {
        toastSuccess.remove();
    }

    if (toastError) {
        toastError.remove();
    }

}, 4000);

if (window.history.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.delete('success');
    window.history.replaceState(
        {},
        document.title,
        url.pathname + '?' + url.searchParams.toString()
    );
}

</script>
<?php require_once dirname(__DIR__, 2) . '/includes/session-timeout.php'; ?>
<?php require_once dirname(__DIR__, 2) . '/includes/footer.php'; ?>
</body>
</html>
