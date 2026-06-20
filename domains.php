<?php
require "config.php";
require "includes/auth.php";
require "includes/security.php";
require "includes/audit.php";


$erro = null;
$sucesso = null;
$aviso = null;
$technicalError = null;
$fieldErrors = [];
$formData = [
    'new_domain' => '',
    'ipv4' => '',
    'ipv6' => '',
    'ipv4_ns2' => '',
    'ipv6_ns2' => '',
    'rev_cidr' => '',
    'ipv6_prefix' => '',
    'ptr4_mode' => 'host',
    'ptr4_template' => 'host-$',
    'create_reverse_v4' => true,
    'create_reverse_v6' => true,
];

if (isset($_POST['create_domain'])) {
    $formData = array_merge($formData, $_POST);
    $formData['create_reverse_v4'] = isset($_POST['create_reverse_v4']);
    $formData['create_reverse_v6'] = isset($_POST['create_reverse_v6']);
}

if (isset($_POST['delete_domain'])) {
    require_csrf();

    $dom = strtolower(trim($_POST['delete_domain'] ?? ''));

    if (!valid_domain($dom) || !isset($domains[$dom])) {
        $erro = "Domínio inválido.";
    } else {
        $zonefile = $domains[$dom];
        $relatedFiles = [$zonefile];

        foreach (glob("/var/cache/bind/master-rev/*") ?: [] as $reverseFile) {
            $content = file_get_contents($reverseFile);
            if ($content !== false && preg_match('/\b' . preg_quote($dom, '/') . '\.?\b/i', $content)) {
                $relatedFiles[] = $reverseFile;
            }
        }

        $confFile = "/etc/bind/named.conf.local";
        $conf = file_get_contents($confFile);

        if ($conf === false) {
            $erro = "Não foi possível ler named.conf.local.";
        } else {
            $originalConf = $conf;
            foreach ($relatedFiles as $relatedFile) {
                $pattern = '/^\s*zone\s+"[^"]+"\s*\{(?:(?!^\s*\};).)*\bfile\s+"' .
                    preg_quote($relatedFile, '/') . '"\s*;(?:(?!^\s*\};).)*^\s*\};\s*/ms';
                $conf = preg_replace($pattern, '', $conf);
            }

            if (!write_file_safely($confFile, $conf)) {
                $erro = "Não foi possível atualizar named.conf.local.";
            } else {
                exec('/usr/bin/named-checkconf 2>&1', $checkOutput, $checkStatus);
                if ($checkStatus !== 0) {
                    write_file_safely($confFile, $originalConf);
                    $erro = "A configuração resultante do BIND é inválida.";
                } else {
                    foreach ($relatedFiles as $relatedFile) {
                        if (is_file($relatedFile)) {
                            unlink($relatedFile);
                        }
                    }

                    reload_dns();

                    foreach ($relatedFiles as $relatedFile) {
                        $basename = basename($relatedFile);
                        $acaoZona = 'REMOVER_ZONA_FORWARD';
                        $tipoZona = 'Forward';

                        if (str_ends_with($basename, '.rev6')) {
                            $acaoZona = 'REMOVER_ZONA_REVERSA_IPV6';
                            $tipoZona = 'Reversa IPv6';
                        } elseif (str_ends_with($basename, '.rev')) {
                            $acaoZona = 'REMOVER_ZONA_REVERSA_IPV4';
                            $tipoZona = 'Reversa IPv4';
                        }

                        registrar_auditoria([
                            'acao' => $acaoZona,
                            'dominio' => $dom,
                            'tipo_registro' => 'ZONA',
                            'nome_registro' => $tipoZona,
                            'valor_antigo' => $basename,
                            'valor_novo' => 'removido',
                            'status' => 'OK',
                            'mensagem' => "Zona {$tipoZona} removida",
                        ]);
                    }

                    registrar_auditoria([
                        'acao' => 'REMOVER_DOMINIO',
                        'dominio' => $dom,
                        'tipo_registro' => 'DOMINIO',
                        'nome_registro' => 'domínio',
                        'valor_antigo' => 'Domínio existente com zonas DNS configuradas',
                        'valor_novo' => 'removido',
                        'status' => 'OK',
                        'mensagem' => 'Domínio removido com sucesso',
                    ]);

                    $_SESSION['flash_ok'] = "Domínio removido!";
                    header("Location: domains.php");
                    exit;
                }
            }
        }
    }

    if ($erro !== null) {
        registrar_auditoria([
            'acao' => 'ERRO_REMOVER_DOMINIO',
            'dominio' => $dom !== '' ? $dom : null,
            'tipo_registro' => 'DOMINIO',
            'nome_registro' => 'domínio',
            'status' => 'ERRO',
            'mensagem' => strip_tags($erro),
        ]);
    }
}

if (isset($_POST['create_domain'])) {
    require_csrf();

    $dom = strtolower(trim($_POST['new_domain'] ?? ''));
    $ip4 = trim($_POST['ipv4'] ?? '');
    $ip6 = trim($_POST['ipv6'] ?? '');

    $ip4_ns2 = trim($_POST['ipv4_ns2'] ?? '');
    $ip6_ns2 = trim($_POST['ipv6_ns2'] ?? '');
    $rev_cidr = trim($_POST['rev_cidr'] ?? '');
    $ipv6_prefix = trim($_POST['ipv6_prefix'] ?? '');

    $ptr4_template = trim($_POST['ptr4_template'] ?? 'host-$');

    if ($ptr4_template === '') {
    $ptr4_template = 'host-$';
}

$rev6 = '';


if (
    isset($_POST['create_reverse_v6']) &&
    !empty($ipv6_prefix)
) {

    $rev6 = $ipv6_prefix;
}

$rev_list = [];

if (isset($_POST['create_reverse_v4']) && !empty($rev_cidr)) {

    $cidr = explode('/', $rev_cidr);

    $network = $cidr[0] ?? '';
    $mask = intval($cidr[1] ?? 0);

    if (
        filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        && $mask <= 24
        && $mask >= 16
    ) {

        $base = ip2long($network);

        $numBlocks = pow(2, 24 - $mask);

        for ($i = 0; $i < $numBlocks; $i++) {

            $ip = long2ip($base + ($i * 256));

            $parts = explode('.', $ip);

            $rev_list[] =
                $parts[0] . "." .
                $parts[1] . "." .
                $parts[2];
        }
    }
}

$rev = implode(';', $rev_list);
$existingRev4 = [];
$missingRev4 = $rev_list;


if (empty($dom) || empty($ip4)) {

    if (empty($dom)) {
        $erro = "Informe o domínio DNS.";
        $fieldErrors['domain'] = $erro;
    } else {
        $erro = "Informe o IPv4 do NS1.";
        $fieldErrors['ns1_ipv4'] = $erro;
    }

} elseif (!valid_domain($dom)) {

    $erro = "Domínio inválido.";
    $fieldErrors['domain'] = $erro;

} elseif (!filter_var($ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

    $erro = "IPv4 inválido.";
    $fieldErrors['ns1_ipv4'] = $erro;

} elseif (!empty($ip6) &&
    !filter_var($ip6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {

    $erro = "IPv6 inválido.";
    $fieldErrors['ns1_ipv6'] = $erro;

} elseif (!empty($ip4_ns2) &&
    !filter_var($ip4_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {

    $erro = "IPv4 inválido.";
    $fieldErrors['ns2_ipv4'] = $erro;

} elseif (!empty($ip6_ns2) &&
    !filter_var($ip6_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {

    $erro = "IPv6 inválido.";
    $fieldErrors['ns2_ipv6'] = $erro;

} elseif (!empty($rev_cidr) &&
    (!str_contains($rev_cidr, '/') ||
    !valid_cidr($rev_cidr, FILTER_FLAG_IPV4))) {

    $erro = "Informe uma rede IPv4 válida, entre /16 e /24.";
    $fieldErrors['reverse_ipv4'] = $erro;

} elseif (!empty($rev_cidr) &&
    ((int) explode('/', $rev_cidr, 2)[1] < 16 ||
    (int) explode('/', $rev_cidr, 2)[1] > 24)) {

    $erro = "Máscara IPv4 permitida: /16 até /24.";
    $fieldErrors['reverse_ipv4'] = $erro;

} elseif (!empty($rev_cidr) && (function (string $cidr): bool {
    [$address, $prefix] = explode('/', $cidr, 2);
    $octets = array_map('intval', explode('.', $address));
    $blocks = 2 ** (24 - (int) $prefix);
    return $octets[3] !== 0 || $octets[2] % $blocks !== 0;
})($rev_cidr)) {

    [$address, $prefix] = explode('/', $rev_cidr, 2);
    $octets = array_map('intval', explode('.', $address));
    $blocks = 2 ** (24 - (int) $prefix);
    $octets[2] = intdiv($octets[2], $blocks) * $blocks;
    $octets[3] = 0;
    $correctNetwork = implode('.', $octets) . '/' . $prefix;

    $erro = "Rede IPv4 inválida.\n\n"
        . "Você informou:\n"
        . $rev_cidr . "\n\n"
        . "Para uma rede /" . $prefix . ", utilize o início do bloco:\n\n"
        . $correctNetwork;
    $fieldErrors['reverse_ipv4'] = $erro;

} elseif (!empty($rev6) &&
    (!str_contains($rev6, '/') ||
    !valid_cidr($rev6, FILTER_FLAG_IPV6) ||
    (int) explode('/', $rev6, 2)[1] % 4 !== 0)) {

    $erro = "Prefixo reverso IPv6 inválido; use máscara múltipla de 4.";
    $fieldErrors['reverse_ipv6'] = $erro;

} else {
    if ($rev_list !== []) {
        $existingRev4 = [];
        $missingRev4 = [];

        foreach ($rev_list as $reverseBlock) {
            $reverseFile = "/var/cache/bind/master-rev/{$reverseBlock}.rev";

            if (is_file($reverseFile)) {
                $existingRev4[] = $reverseBlock;
            } else {
                $missingRev4[] = $reverseBlock;
            }
        }

        $rev = implode(';', $missingRev4);

        if ($missingRev4 === []) {
            $erro = "Todas as zonas reversas desta rede já existem.";
            $fieldErrors['reverse_ipv4'] = $erro;
        } elseif ($existingRev4 !== []) {
            $aviso = "Algumas zonas reversas já existem. Serão criadas apenas as ausentes.";
        }
    }

    if ($erro === null && file_exists("/var/cache/bind/master-aut/$dom.hosts")) {
        $erro = "Domínio já existe.";
        $fieldErrors['domain'] = $erro;
    }

    if ($erro === null) {
        exec(
            "sudo /usr/local/bin/add-domain-full.sh "
            . escapeshellarg($dom) . " "
            . escapeshellarg($ip4) . " "
            . escapeshellarg($ip6) . " "
            . escapeshellarg($ip4_ns2) . " "
            . escapeshellarg($ip6_ns2) . " "
            . escapeshellarg($rev) . " "
            . escapeshellarg($rev6) . " "
            . escapeshellarg($ptr4_template)
            . " 2>&1",
            $out,
            $ret
        );

        if ($ret !== 0) {

            $scriptError = implode("\n", $out);
            $technicalError = $scriptError;
            $erro = $scriptError;

            if (preg_match(
                '/^\[ERRO\]\s+A zona reversa\s+([0-9a-f](?:\.[0-9a-f])+\.ip6\.arpa)\s+já existe\.\s*$/mi',
                $scriptError,
                $zoneMatch
            )) {
                $reverseZone = $zoneMatch[1];
                $reverseFile = null;
                $associatedDomain = null;
                $namedConf = file_get_contents('/etc/bind/named.conf.local');

                if ($namedConf !== false) {
                    $zonePattern = '/^\s*zone\s+"' . preg_quote($reverseZone, '/') .
                        '"\s*\{(?:(?!^\s*\};).)*^\s*\};/msi';

                    if (preg_match($zonePattern, $namedConf, $zoneBlock) &&
                        preg_match('/\bfile\s+(?:"([^"]+)"|([^\s;]+))\s*;/i', $zoneBlock[0], $fileMatch)) {
                        $reverseFile = $fileMatch[1] !== '' ? $fileMatch[1] : $fileMatch[2];
                        $basename = basename($reverseFile);

                        if (preg_match('/^(.+)\.rev6$/i', $basename, $domainMatch) &&
                            valid_domain($domainMatch[1])) {
                            $associatedDomain = $domainMatch[1];
                        }
                    }
                }

                $erro = "Não foi possível criar o domínio.\n\n"
                    . ($associatedDomain !== null
                        ? "O prefixo IPv6 informado já está em uso por {$associatedDomain}."
                        : "O prefixo IPv6 informado já está em uso.")
                    . "\n\nNenhuma alteração foi aplicada.\n\n"
                    . "Veja os detalhes na seção “Criar reversa IPv6” abaixo.";

                $fieldErrors['reverse_ipv6'] = $associatedDomain !== null
                    ? "Prefixo já cadastrado em {$associatedDomain}."
                    : "O prefixo IPv6 informado já está cadastrado.";
                $fieldErrors['reverse_ipv6'] .= "\n\nZona reversa:\n{$reverseZone}";

                if ($reverseFile !== null) {
                    $fieldErrors['reverse_ipv6'] .= "\n\nArquivo usado:\n{$reverseFile}";
                }
            } elseif (stripos($scriptError, 'zona reversa') !== false) {
                if (stripos($scriptError, 'in-addr.arpa') !== false) {
                    $existingRev4AfterFailure = array_filter(
                        $rev_list,
                        static fn (string $reverseBlock): bool =>
                            is_file("/var/cache/bind/master-rev/{$reverseBlock}.rev")
                    );

                    if ($rev_list !== [] && count($existingRev4AfterFailure) === count($rev_list)) {
                        $erro = "Todas as zonas reversas desta rede já existem.";
                    } elseif ($existingRev4AfterFailure !== []) {
                        $erro = "Algumas zonas reversas já existem. Tente novamente para criar apenas as ausentes.";
                    }

                    $fieldErrors['reverse_ipv4'] = $erro;
                }
            } elseif (stripos($scriptError, 'zona já existe') !== false) {
                $erro = "Domínio já existe.";
                $fieldErrors['domain'] = $erro;
            }

        } else {

            $zonasCriadas = ['Forward'];

            registrar_auditoria([
                'acao' => 'CRIAR_ZONA_FORWARD',
                'dominio' => $dom,
                'tipo_registro' => 'ZONA',
                'nome_registro' => 'Forward',
                'valor_novo' => "{$dom}.hosts",
                'status' => 'OK',
                'mensagem' => 'Zona forward criada',
            ]);

            if ($rev !== '') {
                $zonasCriadas[] = 'Rev4';
                registrar_auditoria([
                    'acao' => 'CRIAR_ZONA_REVERSA_IPV4',
                    'dominio' => $dom,
                    'tipo_registro' => 'ZONA',
                    'nome_registro' => 'Reversa IPv4',
                    'valor_novo' => $rev_cidr,
                    'status' => 'OK',
                    'mensagem' => 'Zona reversa IPv4 criada',
                ]);
            }

            if ($rev6 !== '') {
                $zonasCriadas[] = 'Rev6';
                registrar_auditoria([
                    'acao' => 'CRIAR_ZONA_REVERSA_IPV6',
                    'dominio' => $dom,
                    'tipo_registro' => 'ZONA',
                    'nome_registro' => 'Reversa IPv6',
                    'valor_novo' => $rev6,
                    'status' => 'OK',
                    'mensagem' => 'Zona reversa IPv6 criada',
                ]);
            }

            registrar_auditoria([
                'acao' => 'CRIAR_DOMINIO',
                'dominio' => $dom,
                'tipo_registro' => 'DOMINIO',
                'nome_registro' => 'domínio',
                'valor_novo' => implode(' + ', $zonasCriadas),
                'status' => 'OK',
                'mensagem' => 'Domínio criado com sucesso',
            ]);

            $_SESSION['flash_ok'] = "Domínio criado com sucesso!";
            if ($aviso !== null) {
                $_SESSION['flash_warning'] = "Algumas zonas reversas já existiam. Foram criadas apenas as ausentes.";
            }
            header("Location: domains.php");
            exit;
        }
    }
}

    if ($erro !== null) {
        registrar_auditoria([
            'acao' => 'ERRO_CRIAR_DOMINIO',
            'dominio' => $dom !== '' ? $dom : null,
            'tipo_registro' => 'DOMINIO',
            'nome_registro' => 'domínio',
            'status' => 'ERRO',
            'mensagem' => $technicalError !== null
                ? $technicalError
                : strip_tags(str_replace('<br>', "\n", $erro)),
        ]);
    }
}

?>



<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Domínios DNS</title>
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
    --accent-2:#3b82f6;
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
.header{
    margin-bottom:16px;
}
.back-link{
    display:inline-flex;
    align-items:center;
    gap:6px;
    color:#cbd5e1;
    font-size:14px;
}
.header h1{
    margin:8px 0 6px;
    font-size:30px;
    line-height:1.15;
    color:#fff;
    letter-spacing:-.02em;
}
.header p{
    margin:0;
    color:var(--muted);
    line-height:1.45;
}
.alerts{
    display:grid;
    gap:10px;
    margin-bottom:16px;
}
.alert{
    padding:12px 14px;
    border-radius:12px;
    border:1px solid transparent;
}
.alert.error{
    background:rgba(127,29,29,.9);
    border-color:rgba(239,68,68,.22);
    color:#fecaca;
}
.alert.ok{
    background:rgba(20,83,45,.92);
    border-color:rgba(34,197,94,.22);
    color:#bbf7d0;
}
.alert.warning{
    background:rgba(120,53,15,.92);
    border-color:rgba(245,158,11,.25);
    color:#fde68a;
}
.card{
    background:rgba(2,6,23,.96);
    border:1px solid rgba(30,41,59,.95);
    border-radius:16px;
    box-shadow:0 16px 40px rgba(0,0,0,.16);
}
.card-inner{padding:20px 22px}
.card-head{
    margin-bottom:14px;
}
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
.form-grid{
    display:grid;
    gap:12px;
}
.group{
    display:grid;
    gap:9px;
}
.group-title{
    margin:0;
    padding-bottom:6px;
    border-bottom:1px solid rgba(51,65,85,.48);
    color:#fff;
    font-size:15px;
    font-weight:700;
}
.field-grid{
    display:grid;
    gap:12px;
}
.field-grid.two{
    grid-template-columns:repeat(2, minmax(0, 1fr));
}
.field label{
    display:block;
    margin-bottom:5px;
    color:#cbd5e1;
    font-size:14px;
}
input,
select{
    width:100%;
    min-height:34px;
    padding:8px 11px;
    border:1px solid var(--line-soft);
    border-radius:10px;
    background:var(--panel);
    color:#fff;
    font:inherit;
    outline:none;
}
input:focus,
select:focus{
    border-color:rgba(56,189,248,.7);
    box-shadow:0 0 0 3px rgba(56,189,248,.12);
}
.input-error{
    border-color:var(--danger);
    box-shadow:0 0 0 3px rgba(239,68,68,.12);
}
.input-error:focus{
    border-color:var(--danger);
    box-shadow:0 0 0 3px rgba(239,68,68,.18);
}
.field-error{
    margin-top:5px;
    color:#fca5a5;
    font-size:12px;
    line-height:1.4;
}
.primary-field label{
    color:#f8fafc;
    font-size:15px;
    font-weight:700;
}
.primary-field input{
    min-height:40px;
    padding:10px 13px;
    border-color:rgba(56,189,248,.48);
    background:rgba(7,18,38,.92);
    font-size:16px;
    box-shadow:inset 0 0 0 1px rgba(56,189,248,.05);
}
.section{
    border:1px solid var(--line);
    border-radius:14px;
    background:rgba(7,18,38,.7);
    padding:13px 14px;
}
.section-error{
    border-color:var(--danger);
    box-shadow:0 0 0 3px rgba(239,68,68,.1);
}
.section-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.toggle-line{
    display:flex;
    align-items:center;
    gap:10px;
    color:#dbeafe;
    font-size:14px;
    line-height:1.4;
}
.toggle-line input{
    width:auto;
    min-height:auto;
    margin:0;
    accent-color:var(--accent-2);
}
.collapse-panel{
    margin-top:10px;
}
.collapse-panel[hidden]{
    display:none;
}
.help{
    color:var(--muted);
    font-size:13px;
    line-height:1.45;
    margin-top:7px;
}
.ipv4-info,
.ipv6-info{
    margin-top:10px;
    padding:11px 12px;
    border:1px solid rgba(56,189,248,.28);
    border-radius:12px;
    background:rgba(14,116,144,.1);
    color:#cbd5e1;
    font-size:13px;
    line-height:1.5;
}
.ipv4-info strong,
.ipv6-info strong{
    display:block;
    margin-bottom:4px;
    color:#bae6fd;
}
.ipv4-info p,
.ipv6-info p{
    margin:0;
}
.ipv4-info p + p{
    margin-top:5px;
}
.ptr-box{
    margin-top:9px;
    padding:11px 12px;
    border:1px solid var(--line-soft);
    border-radius:12px;
    background:rgba(2,6,23,.82);
}
.ptr-example,
.preview{
    margin-top:8px;
    color:var(--muted);
    font-size:13px;
    line-height:1.5;
}
.preview{
    color:#86efac;
    font-weight:600;
}
.preview.blocked{
    color:#fcd34d;
    white-space:pre-line;
}
.actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:10px;
    flex-wrap:wrap;
    margin-top:2px;
    padding-top:12px;
    border-top:1px solid rgba(51,65,85,.48);
}
button{
    min-height:40px;
    padding:10px 14px;
    border:0;
    border-radius:10px;
    background:var(--accent-2);
    color:#fff;
    font:inherit;
    cursor:pointer;
}
button:hover{opacity:.95}
.domains-block{
    margin-top:18px;
}
.search{
    max-width:440px;
    margin-bottom:10px;
}
.search input{
    min-height:34px;
    padding:8px 11px;
    background:rgba(7,18,38,.72);
}
.domain-list{
    display:grid;
    gap:6px;
}
.domain-row{
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
.domain-name{
    font-weight:700;
    color:#fff;
    word-break:break-word;
}
.domain-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-wrap:wrap;
    justify-content:flex-end;
}
.action-link,
.danger-button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:30px;
    padding:6px 10px;
    border-radius:8px;
    border:1px solid var(--line-soft);
    font-size:13px;
    white-space:nowrap;
}
.action-link{
    background:rgba(30,41,59,.9);
    color:#dbeafe;
}
.action-link:hover{text-decoration:none;border-color:rgba(56,189,248,.45)}
.danger-button{
    background:rgba(127,29,29,.95);
    color:#fecaca;
    border-color:rgba(239,68,68,.24);
    cursor:pointer;
}
.empty{
    padding:18px 16px;
    border:1px dashed var(--line-soft);
    border-radius:12px;
    color:var(--muted);
    background:rgba(2,6,23,.66);
}
@media (max-width: 740px){
    .page{width:min(100% - 20px, 1100px);margin:20px auto 30px}
    .field-grid.two,
    .domain-row{grid-template-columns:1fr}
    .domain-actions{justify-content:flex-start}
}
</style>
</head>
<body>
<main class="page">
    <header class="header">
        <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
        <h1>Domínios DNS</h1>
        <p>Criar zonas forward e reversas associadas.</p>
    </header>

    <div class="alerts">
        <?php if ($erro): ?>
            <div id="alertaErro" class="alert error"><?= nl2br(htmlspecialchars($erro)) ?></div>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_ok'])): ?>
            <div id="alertaSucesso" class="alert ok"><?= htmlspecialchars($_SESSION['flash_ok']) ?></div>
            <?php unset($_SESSION['flash_ok']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['flash_warning'])): ?>
            <div id="alertaAviso" class="alert warning"><?= htmlspecialchars($_SESSION['flash_warning']) ?></div>
            <?php unset($_SESSION['flash_warning']); ?>
        <?php endif; ?>
    </div>

    <section class="card">
        <div class="card-inner">
            <div class="card-head">
                <h2>Criar novo domínio</h2>
            </div>

            <form method="POST" class="form-grid">
                <?= csrf_field() ?>

                <div class="group">
                    <div class="field primary-field">
                        <label>Domínio DNS</label>
                        <input
                            name="new_domain"
                            placeholder="empresa.com.br"
                            value="<?= htmlspecialchars((string) $formData['new_domain'], ENT_QUOTES, 'UTF-8') ?>"
                            class="<?= isset($fieldErrors['domain']) ? 'input-error' : '' ?>"
                            <?= isset($fieldErrors['domain']) ? 'aria-invalid="true" aria-describedby="domain-error"' : '' ?>
                            required>
                        <?php if (isset($fieldErrors['domain'])): ?>
                            <div id="domain-error" class="field-error"><?= htmlspecialchars($fieldErrors['domain']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="group">
                    <p class="group-title">NS1</p>
                    <div class="field-grid two">
                        <div class="field">
                            <label>IPv4 NS1</label>
                            <input
                                name="ipv4"
                                placeholder="198.50.0.242"
                                value="<?= htmlspecialchars((string) $formData['ipv4'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['ns1_ipv4']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['ns1_ipv4']) ? 'aria-invalid="true" aria-describedby="ns1-ipv4-error"' : '' ?>
                                required>
                            <?php if (isset($fieldErrors['ns1_ipv4'])): ?>
                                <div id="ns1-ipv4-error" class="field-error"><?= htmlspecialchars($fieldErrors['ns1_ipv4']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label>IPv6 NS1</label>
                            <input
                                name="ipv6"
                                placeholder="2001:abcd:5000::242"
                                value="<?= htmlspecialchars((string) $formData['ipv6'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['ns1_ipv6']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['ns1_ipv6']) ? 'aria-invalid="true" aria-describedby="ns1-ipv6-error"' : '' ?>>
                            <?php if (isset($fieldErrors['ns1_ipv6'])): ?>
                                <div id="ns1-ipv6-error" class="field-error"><?= htmlspecialchars($fieldErrors['ns1_ipv6']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="group">
                    <p class="group-title">NS2</p>
                    <div class="field-grid two">
                        <div class="field">
                            <label>IPv4 NS2</label>
                            <input
                                name="ipv4_ns2"
                                placeholder="198.50.0.243"
                                value="<?= htmlspecialchars((string) $formData['ipv4_ns2'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['ns2_ipv4']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['ns2_ipv4']) ? 'aria-invalid="true" aria-describedby="ns2-ipv4-error"' : '' ?>>
                            <?php if (isset($fieldErrors['ns2_ipv4'])): ?>
                                <div id="ns2-ipv4-error" class="field-error"><?= htmlspecialchars($fieldErrors['ns2_ipv4']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="field">
                            <label>IPv6 NS2</label>
                            <input
                                name="ipv6_ns2"
                                placeholder="2001:abcd:5000::243"
                                value="<?= htmlspecialchars((string) $formData['ipv6_ns2'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['ns2_ipv6']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['ns2_ipv6']) ? 'aria-invalid="true" aria-describedby="ns2-ipv6-error"' : '' ?>>
                            <?php if (isset($fieldErrors['ns2_ipv6'])): ?>
                                <div id="ns2-ipv6-error" class="field-error"><?= htmlspecialchars($fieldErrors['ns2_ipv6']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="section <?= isset($fieldErrors['reverse_ipv4']) ? 'section-error' : '' ?>">
                    <div class="section-head">
                        <label class="toggle-line">
                            <input type="checkbox" name="create_reverse_v4" <?= $formData['create_reverse_v4'] ? 'checked' : '' ?> data-toggle-collapse="reverse-v4-panel">
                            <span>Criar reversa IPv4</span>
                        </label>
                    </div>

                    <div class="collapse-panel" id="reverse-v4-panel">
                        <div class="field">
                            <label>Rede IPv4</label>
                            <input
                                name="rev_cidr"
                                placeholder="192.168.0.0/22"
                                value="<?= htmlspecialchars((string) $formData['rev_cidr'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['reverse_ipv4']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['reverse_ipv4']) ? 'aria-invalid="true" aria-describedby="reverse-ipv4-error"' : '' ?>>
                            <?php if (isset($fieldErrors['reverse_ipv4'])): ?>
                                <div id="reverse-ipv4-error" class="field-error"><?= nl2br(htmlspecialchars($fieldErrors['reverse_ipv4'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ipv4-info">
                            <strong>ℹ Reversa IPv4 automática</strong>
                            <p>Informe uma rede IPv4 entre /16 e /24. O painel divide a rede em blocos /24 e cria as zonas reversas automaticamente.</p>
                            <p>Exemplo: 192.168.28.0/22 gera 4 zonas reversas: 192.168.28.0/24, 192.168.29.0/24, 192.168.30.0/24 e 192.168.31.0/24.</p>
                        </div>

                        <div id="ptr4_format_box" class="ptr-box" style="display:none;">
                            <div class="field">
                                <label>PTR IPv4</label>
                                <select id="ptr4_mode" name="ptr4_mode">
                                    <option value="host" <?= $formData['ptr4_mode'] === 'host' ? 'selected' : '' ?>>host-10.dominio.com.br</option>
                                    <option value="iprede" <?= $formData['ptr4_mode'] === 'iprede' ? 'selected' : '' ?>>ip-192-168-0-10.dominio.com.br</option>
                                    <option value="rede" <?= $formData['ptr4_mode'] === 'rede' ? 'selected' : '' ?>>192-168-0-10.dominio.com.br</option>
                                    <option value="custom" <?= $formData['ptr4_mode'] === 'custom' ? 'selected' : '' ?>>Personalizado</option>
                                </select>
                            </div>

                            <div id="ptr4_example" class="ptr-example">
                                Exemplo para o IP .10:<br>
                                <strong>host-10.dominio.com.br</strong>
                            </div>

                            <input type="hidden" id="ptr4_template" name="ptr4_template" value="<?= htmlspecialchars((string) $formData['ptr4_template'], ENT_QUOTES, 'UTF-8') ?>">

                            <input
                                id="ptr4_custom"
                                aria-label="Template PTR personalizado"
                                placeholder="cliente-$"
                                value="<?= $formData['ptr4_mode'] === 'custom' ? htmlspecialchars((string) $formData['ptr4_template'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                style="display:none;margin-top:9px;">
                        </div>

                        <div id="preview-reverse" class="preview" style="display:none;"></div>
                    </div>
                </div>

                <div class="section <?= isset($fieldErrors['reverse_ipv6']) ? 'section-error' : '' ?>">
                    <div class="section-head">
                        <label class="toggle-line">
                            <input type="checkbox" name="create_reverse_v6" <?= $formData['create_reverse_v6'] ? 'checked' : '' ?> data-toggle-collapse="reverse-v6-panel">
                            <span>Criar reversa IPv6</span>
                        </label>
                    </div>

                    <div class="collapse-panel" id="reverse-v6-panel">
                        <div class="field">
                            <label>Prefixo IPv6</label>
                            <input
                                name="ipv6_prefix"
                                placeholder="2001:db8::/32"
                                value="<?= htmlspecialchars((string) $formData['ipv6_prefix'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['reverse_ipv6']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['reverse_ipv6']) ? 'aria-invalid="true" aria-describedby="reverse-ipv6-error"' : '' ?>>
                            <?php if (isset($fieldErrors['reverse_ipv6'])): ?>
                                <div id="reverse-ipv6-error" class="field-error"><?= nl2br(htmlspecialchars($fieldErrors['reverse_ipv6'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="ipv6-info">
                            <strong>ℹ Reversa IPv6 manual</strong>
                            <p>O prefixo IPv6 informado será usado para criar uma zona reversa exclusiva deste domínio. Se o prefixo já estiver cadastrado, a criação será bloqueada e o painel mostrará qual domínio já está utilizando.</p>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" name="create_domain">Criar domínio</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card domains-block">
        <div class="card-inner">
            <div class="card-head">
                <h2>Domínios cadastrados</h2>
            </div>

            <div class="search">
                <input type="search" placeholder="Pesquisar domínio..." data-domain-search>
            </div>

            <div class="domain-list">
                <?php if (empty($domains)): ?>
                    <div class="empty">Nenhum domínio cadastrado ainda.</div>
                <?php else: ?>
                    <?php foreach ($domains as $d => $path): ?>
                        <div class="domain-row" data-domain-item data-domain-name="<?= htmlspecialchars(strtolower($d), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="domain-name"><?= htmlspecialchars($d) ?></div>
                            <div class="domain-actions">
                                <a class="action-link" href="edit-zone.php?zone=<?= rawurlencode($d) ?>">Editar zona</a>
                                <a class="action-link" href="historico-zona.php?zone=<?= rawurlencode($d) ?>">Histórico</a>
                                <form method="POST" onsubmit="return confirm('Deseja remover este domínio?');" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_domain" value="<?= htmlspecialchars($d) ?>">
                                    <button class="danger-button" type="submit">Excluir</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </section>
</main>

<script>
setTimeout(function() {
    let erro = document.getElementById("alertaErro");
    let sucesso = document.getElementById("alertaSucesso");
    let aviso = document.getElementById("alertaAviso");

    [erro, sucesso, aviso].forEach(function(el) {
        if (el) {
            el.style.transition = "opacity 0.5s";
            el.style.opacity = "0";

            setTimeout(function() {
                el.remove();
            }, 500);
        }
    });
}, 3000);
</script>

<script>
const revInput = document.querySelector('[name="rev_cidr"]');
const preview = document.getElementById('preview-reverse');
const domainInput = document.querySelector('[name="new_domain"]');
const rev4Check = document.querySelector('[name="create_reverse_v4"]');
const rev6Check = document.querySelector('[name="create_reverse_v6"]');
const ptrBox = document.getElementById('ptr4_format_box');
const ptrMode = document.getElementById('ptr4_mode');
const ptrTemplate = document.getElementById('ptr4_template');
const ptrCustom = document.getElementById('ptr4_custom');
const ptrExample = document.getElementById('ptr4_example');
const searchInput = document.querySelector('[data-domain-search]');
const domainItems = [...document.querySelectorAll('[data-domain-item]')];
const collapsiblePanels = [
    { checkbox: rev4Check, panelId: 'reverse-v4-panel' },
    { checkbox: rev6Check, panelId: 'reverse-v6-panel' },
];

function setPanelState(checkbox, panelId) {
    const panel = document.getElementById(panelId);
    if (!panel || !checkbox) return;

    panel.hidden = !checkbox.checked;
    panel.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el !== checkbox) {
            el.disabled = !checkbox.checked;
        }
    });
}

function renderReversePreview() {
    if (!preview || !rev4Check || !rev4Check.checked) {
        if (preview) preview.style.display = 'none';
        return;
    }

    if (document.getElementById('reverse-ipv4-error')) {
        preview.style.display = 'none';
        return;
    }

    const cidr = (revInput?.value || '').trim();
    preview.style.display = 'none';

    if (!cidr.includes('/')) {
        return;
    }

    const parts = cidr.split('/');
    const network = parts[0];
    const mask = parseInt(parts[1], 10);
    const octets = network.split('.');

    if (octets.length !== 4 || Number.isNaN(mask) || mask < 16 || mask > 24) {
        return;
    }

    const total = Math.pow(2, 24 - mask);
    const formHasBlockingError = document.querySelector('.field-error') !== null;
    let texto;

    if (formHasBlockingError) {
        texto = total === 1
            ? '✓ IPv4 validado: 1 zona reversa seria criada.\n⚠ Nenhuma alteração será aplicada enquanto houver erro na reversa IPv6.'
            : '✓ IPv4 validado: ' + total + ' zonas reversas seriam criadas.\n⚠ Nenhuma alteração será aplicada enquanto houver erro na reversa IPv6.';
    } else {
        texto = total === 1
            ? '✓ Será criada 1 zona reversa'
            : '✓ Serão criadas ' + total + ' zonas reversas';
    }

    preview.textContent = texto;
    preview.classList.toggle('blocked', formHasBlockingError);
    preview.style.display = 'block';
}

function updatePtrTemplate() {
    if (!ptrMode || !ptrTemplate || !revInput) return;

    const domain = domainInput ? domainInput.value.trim() : '';
    const displayDomain = domain || 'dominio.com.br';
    const rev4Enabled = rev4Check ? rev4Check.checked : false;

    if (!domain || !rev4Enabled || !revInput.value.includes('/')) {
        if (ptrBox) ptrBox.style.display = 'none';
        if (preview) preview.style.display = 'none';
        return;
    }

    if (ptrBox) ptrBox.style.display = 'block';

    const cidr = revInput.value.trim();
    const network = cidr.split('/')[0] || '';
    const octets = network.split('.');

    let prefix = '';
    if (octets.length === 4) {
        prefix = octets[0] + '-' + octets[1] + '-' + octets[2];
    }

    let exampleHost = 'host-10.' + displayDomain;

    if (ptrMode.value === 'iprede') {
        exampleHost = 'ip-' + prefix + '-10.' + displayDomain;
    }

    if (ptrMode.value === 'rede') {
        exampleHost = prefix + '-10.' + displayDomain;
    }

    if (ptrMode.value === 'custom') {
        let custom = ptrCustom.value.trim() || 'host-$';
        exampleHost = custom.replace('$', '10') + '.' + displayDomain;
    }

    if (ptrExample) {
        ptrExample.innerHTML = 'Exemplo para o IP .10:<br><strong>' + exampleHost + '</strong>';
    }

    if (ptrMode && ptrMode.options.length >= 3) {
        ptrMode.options[0].text = 'host-10.' + displayDomain;
        ptrMode.options[1].text = prefix
            ? 'ip-' + prefix + '-10.' + displayDomain
            : 'ip-192-168-0-10.' + displayDomain;
        ptrMode.options[2].text = prefix
            ? prefix + '-10.' + displayDomain
            : '192-168-0-10.' + displayDomain;
    }

    if (ptrMode.value === 'host') {
        ptrTemplate.value = 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'iprede') {
        ptrTemplate.value = prefix ? 'ip-' + prefix + '-$' : 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'rede') {
        ptrTemplate.value = prefix ? prefix + '-$' : 'host-$';
        ptrCustom.style.display = 'none';
    }

    if (ptrMode.value === 'custom') {
        ptrCustom.style.display = 'block';
        ptrTemplate.value = ptrCustom.value.trim() || 'host-$';
    }
}

function updateReversePanels() {
    collapsiblePanels.forEach(({ checkbox, panelId }) => setPanelState(checkbox, panelId));
    renderReversePreview();
    updatePtrTemplate();
}

function clearReverseIpv4Error() {
    const fieldError = document.getElementById('reverse-ipv4-error');
    if (!fieldError || !revInput) return;

    fieldError.remove();
    revInput.classList.remove('input-error');
    revInput.removeAttribute('aria-invalid');
    revInput.removeAttribute('aria-describedby');
    document.getElementById('alertaErro')?.remove();
}

searchInput?.addEventListener('input', event => {
    const term = String(event.target.value || '').trim().toLowerCase();
    domainItems.forEach(item => {
        const name = String(item.dataset.domainName || '');
        item.hidden = term !== '' && !name.includes(term);
    });
});

revInput?.addEventListener('input', () => {
    clearReverseIpv4Error();
    renderReversePreview();
    updatePtrTemplate();
});

domainInput?.addEventListener('input', updatePtrTemplate);

rev4Check?.addEventListener('change', updateReversePanels);
rev6Check?.addEventListener('change', updateReversePanels);

if (ptrMode) {
    ptrMode.addEventListener('change', updatePtrTemplate);
}

if (ptrCustom) {
    ptrCustom.addEventListener('input', updatePtrTemplate);
}

document.querySelector('form[method="POST"]')?.addEventListener('submit', () => {
    updatePtrTemplate();
});

updateReversePanels();
</script>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
