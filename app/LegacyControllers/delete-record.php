<?php
require_once dirname(__DIR__, 2) . "/config.php";
require_once dirname(__DIR__, 2) . "/includes/auth.php";
require_once dirname(__DIR__, 2) . "/includes/security.php";
require_once dirname(__DIR__, 2) . "/includes/audit.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

require_csrf();

$zone = strtolower(trim($_POST['zone'] ?? ''));
$host = trim($_POST['host'] ?? '');
$type = strtoupper(trim($_POST['type'] ?? ''));
$value = trim($_POST['value'] ?? '');

if (!valid_domain($zone) || !valid_hostname($host) ||
    !in_array($type, ['A', 'AAAA', 'CNAME', 'MX', 'TXT'], true) ||
    $value === '') {
    http_response_code(400);
    exit('Registro inválido.');
}

$file = "/var/cache/bind/master-aut/{$zone}.hosts";

if (!is_file($file)) {
    http_response_code(404);
    exit('Zona não encontrada.');
}

$target = preg_replace('/\s+/', ' ', "{$host} IN {$type} {$value}");
$new = [];
$removed = false;

foreach (file($file) as $line) {
    $clean = preg_replace('/\s+/', ' ', trim($line));
    if (!$removed && $clean === $target) {
        $removed = true;
        continue;
    }
    $new[] = $line;
}

if (!$removed) {
    http_response_code(404);
    exit('Registro não encontrado.');
}

$content = format_forward_zone_content(implode('', $new));
$content = increment_zone_serial($content);

if (!validate_zone_content($zone, $content, $error)) {
    http_response_code(422);
    exit("A zona ficaria inválida:\n" . $error);
}

if (!write_file_safely($file, $content)) {
    http_response_code(500);
    exit('Não foi possível gravar a zona.');
}

registrar_auditoria([
    'acao'          => 'REMOVER_REGISTRO',
    'dominio'       => $zone,
    'tipo_registro' => $type,
    'nome_registro' => $host,
    'valor_antigo'  => "{$host} IN {$type} {$value}",
    'valor_novo'    => 'removido',
    'status'        => 'OK',
    'mensagem'      => 'Registro DNS removido'
]);

reload_dns();

header(


    "Location: edit-zone.php?zone=" . urlencode($zone) .
    "&success=" . urlencode("Registro removido com sucesso")
);
exit;
