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

$zone = basename(trim($_POST['zone'] ?? ''));
$ptr = trim($_POST['ptr'] ?? '');
$host = trim($_POST['host'] ?? '');

if (!preg_match('/^[a-zA-Z0-9.-]+\.(?:rev6|rev)$/', $zone) ||
    !preg_match('/^[0-9a-f.]+$/i', $ptr) ||
    !valid_hostname($host)) {
    http_response_code(400);
    exit('PTR inválido.');
}

$file = "/var/cache/bind/master-rev/{$zone}";

if (!is_file($file)) {
    http_response_code(404);
    exit('Zona não encontrada.');
}

$target = preg_replace('/\s+/', ' ', "{$ptr} IN PTR {$host}");
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
    exit('PTR não encontrado.');
}

$content = increment_zone_serial(implode('', $new));
$bindZone = bind_zone_name_for_file($file);

if (!$bindZone || !validate_zone_content($bindZone, $content, $error)) {
    http_response_code(422);
    exit("A zona ficaria inválida:\n" . ($error ?? 'Zona não localizada.'));
}

if (!write_file_safely($file, $content)) {
    http_response_code(500);
    exit('Não foi possível gravar a zona.');
}

$isIpv6 = str_ends_with($zone, '.rev6');
$enderecoIpv6 = $isIpv6
    ? audit_ptr_ipv6_para_endereco($ptr, $bindZone)
    : null;

registrar_auditoria([
    'acao'          => $isIpv6 ? 'REMOVER_PTR_IPV6' : 'REMOVER_PTR_IPV4',
    'dominio'       => $zone,
    'tipo_registro' => 'PTR',
    'nome_registro' => $isIpv6 ? 'PTR IPv6' : 'PTR IPv4',
    'valor_antigo'  => $enderecoIpv6
                        ? audit_valor_ptr($enderecoIpv6, $host)
                        : $host,
    'valor_novo'    => 'removido',
    'status'        => 'OK',
    'mensagem'      => 'PTR removido'
]);

reload_dns();

header(

    "Location: edit-reverse-zone.php?zone=" . urlencode($zone) .
    "&success=" . urlencode("PTR removido com sucesso")
);
exit;
