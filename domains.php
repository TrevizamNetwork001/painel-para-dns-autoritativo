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

    $createReverseIpv4 = isset($_POST['create_reverse_v4']);
    $createReverseIpv6 = isset($_POST['create_reverse_v6']);
    $rev6 = $createReverseIpv6 && $ipv6_prefix !== '' ? $ipv6_prefix : '';
    $rev_list = [];
    $existingRev4 = [];
    $missingRev4 = [];
    $globalIssues = [];
    $singleIssueGlobalMessage = null;

    if ($createReverseIpv4 && $rev_cidr !== '') {
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
    $missingRev4 = $rev_list;

    if ($dom === '') {
        $fieldErrors['domain'] = "Informe o domínio DNS.";
    } elseif (!valid_domain($dom)) {
        $fieldErrors['domain'] = "Domínio inválido.";
    }

    if ($ip4 === '') {
        $fieldErrors['ns1_ipv4'] = "Informe o IPv4 do NS1.";
    } elseif (!filter_var($ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $fieldErrors['ns1_ipv4'] = "IPv4 inválido.";
    }

    if ($ip6 !== '' && !filter_var($ip6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $fieldErrors['ns1_ipv6'] = "IPv6 inválido.";
    }

    if ($ip4_ns2 !== '' && !filter_var($ip4_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $fieldErrors['ns2_ipv4'] = "IPv4 inválido.";
    }

    if ($ip6_ns2 !== '' && !filter_var($ip6_ns2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $fieldErrors['ns2_ipv6'] = "IPv6 inválido.";
    }

    if ($createReverseIpv4 && $rev_cidr !== '') {
        if (!str_contains($rev_cidr, '/') ||
            !valid_cidr($rev_cidr, FILTER_FLAG_IPV4)) {
            $fieldErrors['reverse_ipv4'] = "Informe uma rede IPv4 válida, entre /16 e /24.";
        } else {
            [$address, $prefix] = explode('/', $rev_cidr, 2);
            $prefix = (int) $prefix;

            if ($prefix < 16 || $prefix > 24) {
                $fieldErrors['reverse_ipv4'] = "Máscara IPv4 permitida: /16 até /24.";
            } else {
                $octets = array_map('intval', explode('.', $address));
                $blocks = 2 ** (24 - $prefix);

                if ($octets[3] !== 0 || $octets[2] % $blocks !== 0) {
                    $octets[2] = intdiv($octets[2], $blocks) * $blocks;
                    $octets[3] = 0;
                    $correctNetwork = implode('.', $octets) . '/' . $prefix;

                    $fieldErrors['reverse_ipv4'] = "Rede IPv4 inválida.\n\n"
                        . "Você informou:\n"
                        . $rev_cidr . "\n\n"
                        . "Para uma rede /" . $prefix . ", utilize o início do bloco:\n\n"
                        . $correctNetwork;
                }
            }
        }
    }

    if ($rev6 !== '' &&
        (!str_contains($rev6, '/') ||
        !valid_cidr($rev6, FILTER_FLAG_IPV6) ||
        (int) explode('/', $rev6, 2)[1] % 4 !== 0)) {
        $fieldErrors['reverse_ipv6'] = "Prefixo reverso IPv6 inválido; use máscara múltipla de 4.";
    }

    if (!isset($fieldErrors['reverse_ipv4']) && $rev_list !== []) {
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
            $fieldErrors['reverse_ipv4'] = "Todas as zonas reversas desta rede já existem.";
        } elseif ($existingRev4 !== []) {
            $aviso = "Algumas zonas reversas já existem. Serão criadas apenas as ausentes.";
        }
    }

    if (!isset($fieldErrors['domain']) && file_exists("/var/cache/bind/master-aut/$dom.hosts")) {
        $fieldErrors['domain'] = "Domínio já existe.";
    }

    if (!isset($fieldErrors['reverse_ipv6']) && $rev6 !== '') {
        [$ipv6Address, $ipv6PrefixLength] = explode('/', $rev6, 2);
        $packedIpv6 = inet_pton($ipv6Address);
        $reverseZone = '';

        if ($packedIpv6 !== false) {
            $nibbles = substr(bin2hex($packedIpv6), 0, (int) $ipv6PrefixLength / 4);
            $reverseZone = implode('.', array_reverse(str_split($nibbles))) . '.ip6.arpa';
        }

        $associatedDomain = null;
        $reverseZoneExists = false;
        $namedConf = file_get_contents('/etc/bind/named.conf.local');

        if ($namedConf !== false && $reverseZone !== '') {
            $zonePattern = '/^\s*zone\s+"' . preg_quote($reverseZone, '/') .
                '"\s*\{(?:(?!^\s*\};).)*^\s*\};/msi';

            if (preg_match($zonePattern, $namedConf, $zoneBlock)) {
                $reverseZoneExists = true;

                if (preg_match('/\bfile\s+(?:"([^"]+)"|([^\s;]+))\s*;/i', $zoneBlock[0], $fileMatch)) {
                    $reverseFile = $fileMatch[1] !== '' ? $fileMatch[1] : $fileMatch[2];
                    $basename = basename($reverseFile);

                    if (preg_match('/^(.+)\.rev6$/i', $basename, $domainMatch) &&
                        valid_domain($domainMatch[1])) {
                        $associatedDomain = $domainMatch[1];
                    }
                }
            }
        }

        if (valid_domain($dom) && is_file("/var/cache/bind/master-rev/{$dom}.rev6")) {
            $reverseZoneExists = true;
            $associatedDomain ??= $dom;
        }

        if ($reverseZoneExists) {
            $fieldErrors['reverse_ipv6'] = "Prefixo IPv6 já em uso.\n\n"
                . ($associatedDomain !== null
                    ? "Este prefixo já está cadastrado para {$associatedDomain}."
                    : "Este prefixo já está cadastrado.")
                . "\nA zona reversa IPv6 correspondente já existe no servidor.\n\n"
                . "Use outro prefixo IPv6 ou revise o cadastro existente.";

            $singleIssueGlobalMessage = "Não foi possível criar o domínio.\n\n"
                . ($associatedDomain !== null
                    ? "O prefixo IPv6 informado já está em uso por {$associatedDomain}."
                    : "O prefixo IPv6 informado já está em uso.")
                . "\n\nNenhuma alteração foi aplicada.\n\n"
                . "Veja os detalhes na seção “Criar zona reversa IPv6” abaixo.";
        }
    }

    $domainErrorFields = ['domain', 'ns1_ipv4', 'ns1_ipv6', 'ns2_ipv4', 'ns2_ipv6'];
    if (array_intersect($domainErrorFields, array_keys($fieldErrors)) !== []) {
        $globalIssues[] = 'no domínio DNS';
    }
    if (isset($fieldErrors['reverse_ipv4'])) {
        $globalIssues[] = 'na reversa IPv4';
    }
    if (isset($fieldErrors['reverse_ipv6'])) {
        $globalIssues[] = 'na reversa IPv6';
    }

    if ($fieldErrors !== []) {
        if (count($globalIssues) > 1) {
            $lastIssue = array_pop($globalIssues);
            $issueList = implode(', ', $globalIssues) . ' e ' . $lastIssue;
            $erro = "Não foi possível criar o domínio.\n\n"
                . "Encontramos pendências {$issueList}.\n"
                . "Nenhuma alteração foi aplicada.\n\n"
                . "Veja os detalhes nas seções destacadas abaixo.";
        } else {
            $erro = $singleIssueGlobalMessage ?? reset($fieldErrors);
        }
    }

    if ($fieldErrors === []) {
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
                    . "Veja os detalhes na seção “Criar zona reversa IPv6” abaixo.";

                $fieldErrors['reverse_ipv6'] = "Prefixo IPv6 já em uso.\n\n"
                    . ($associatedDomain !== null
                        ? "Este prefixo já está cadastrado para {$associatedDomain}."
                        : "Este prefixo já está cadastrado.")
                    . "\nA zona reversa IPv6 correspondente já existe no servidor.\n\n"
                    . "Use outro prefixo IPv6 ou revise o cadastro existente.";
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
            $_SESSION['domain_created_success'] = true;
            if ($aviso !== null) {
                $_SESSION['flash_warning'] = "Algumas zonas reversas já existiam. Foram criadas apenas as ausentes.";
            }
            header("Location: domains.php");
            exit;
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

$showDomainCreatedSuccess = !empty($_SESSION['domain_created_success']);

if ($showDomainCreatedSuccess) {
    unset($_SESSION['domain_created_success'], $_SESSION['flash_ok']);
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
.success-redirect-overlay{
    position:fixed;
    inset:0;
    z-index:1000;
    display:grid;
    place-items:center;
    padding:20px;
    background:rgba(2,6,23,.88);
    backdrop-filter:blur(5px);
}
.success-redirect-card{
    width:min(520px, 100%);
    padding:30px 26px;
    border:1px solid rgba(34,197,94,.3);
    border-radius:16px;
    background:rgba(2,6,23,.98);
    box-shadow:0 24px 60px rgba(0,0,0,.35);
    text-align:center;
}
.success-redirect-card h2{
    margin:0 0 10px;
    color:#bbf7d0;
    font-size:24px;
}
.success-redirect-card p{
    margin:0;
    color:#cbd5e1;
    line-height:1.55;
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
[data-error-section]{
    scroll-margin-top:80px;
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
@media (max-width: 740px){
    .page{width:min(100% - 20px, 1100px);margin:20px auto 30px}
    .field-grid.two{grid-template-columns:1fr}
}
</style>
</head>
<body>
<?php if ($showDomainCreatedSuccess): ?>
    <div class="success-redirect-overlay" role="status" aria-live="polite">
        <div class="success-redirect-card">
            <h2>Domínio criado com sucesso.</h2>
            <p>Tudo certo por aqui. Você será redirecionado para o painel principal.</p>
        </div>
    </div>
<?php endif; ?>

<main class="page">
    <header class="header">
        <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
        <h1>Domínios DNS</h1>
        <p>Criar zonas forward e reversas associadas.</p>
    </header>

    <div class="alerts">
        <?php if ($erro): ?>
            <div id="alertaErro" class="alert error global-alert-error"><?= nl2br(htmlspecialchars($erro)) ?></div>
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

                <div class="group" data-error-section="domain">
                    <div class="field primary-field">
                        <label>Domínio DNS</label>
                        <input
                            name="new_domain"
                            placeholder="exemplo.com.br"
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
                                placeholder="192.0.2.253"
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
                                placeholder="2001:db8:2000::242"
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
                                placeholder="192.0.2.254"
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
                                placeholder="2001:db8:2000::243"
                                value="<?= htmlspecialchars((string) $formData['ipv6_ns2'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['ns2_ipv6']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['ns2_ipv6']) ? 'aria-invalid="true" aria-describedby="ns2-ipv6-error"' : '' ?>>
                            <?php if (isset($fieldErrors['ns2_ipv6'])): ?>
                                <div id="ns2-ipv6-error" class="field-error"><?= htmlspecialchars($fieldErrors['ns2_ipv6']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="section <?= isset($fieldErrors['reverse_ipv4']) ? 'section-error' : '' ?>" data-error-section="reverse_ipv4">
                    <div class="section-head">
                        <label class="toggle-line">
                            <input type="checkbox" name="create_reverse_v4" <?= $formData['create_reverse_v4'] ? 'checked' : '' ?> data-toggle-collapse="reverse-v4-panel">
                            <span>Criar zona reversa IPv4</span>
                        </label>
                    </div>

                    <div class="collapse-panel" id="reverse-v4-panel">
                        <div class="field">
                            <label>Rede IPv4</label>
                            <input
                                name="rev_cidr"
                                data-error-field="reverse_ipv4"
                                placeholder="192.0.2.0/24"
                                value="<?= htmlspecialchars((string) $formData['rev_cidr'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['reverse_ipv4']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['reverse_ipv4']) ? 'aria-invalid="true" aria-describedby="reverse-ipv4-error"' : '' ?>>
                            <?php if (isset($fieldErrors['reverse_ipv4'])): ?>
                                <div id="reverse-ipv4-error" class="field-error" data-error-message="reverse_ipv4"><?= nl2br(htmlspecialchars($fieldErrors['reverse_ipv4'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="ipv4-info">
                            <strong>ℹ Zona reversa IPv4 automática</strong>
                            <p>Informe uma rede IPv4 com prefixo entre /16 e /24. O painel divide a rede em blocos /24 e cria as zonas reversas automaticamente.</p>
                            <p>Exemplo: uma rede /22 é dividida em 4 blocos /24 antes da criação das zonas reversas.</p>
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
                                placeholder="ns1.exemplo.com.br"
                                value="<?= $formData['ptr4_mode'] === 'custom' ? htmlspecialchars((string) $formData['ptr4_template'], ENT_QUOTES, 'UTF-8') : '' ?>"
                                style="display:none;margin-top:9px;">
                        </div>

                        <div id="preview-reverse" class="preview" style="display:none;"></div>
                    </div>
                </div>

                <div class="section <?= isset($fieldErrors['reverse_ipv6']) ? 'section-error' : '' ?>" data-error-section="reverse_ipv6">
                    <div class="section-head">
                        <label class="toggle-line">
                            <input type="checkbox" name="create_reverse_v6" <?= $formData['create_reverse_v6'] ? 'checked' : '' ?> data-toggle-collapse="reverse-v6-panel">
                            <span>Criar zona reversa IPv6</span>
                        </label>
                    </div>

                    <div class="collapse-panel" id="reverse-v6-panel">
                        <div class="field">
                            <label>Prefixo IPv6</label>
                            <input
                                name="ipv6_prefix"
                                data-error-field="reverse_ipv6"
                                placeholder="2001:db8::/32"
                                value="<?= htmlspecialchars((string) $formData['ipv6_prefix'], ENT_QUOTES, 'UTF-8') ?>"
                                class="<?= isset($fieldErrors['reverse_ipv6']) ? 'input-error' : '' ?>"
                                <?= isset($fieldErrors['reverse_ipv6']) ? 'aria-invalid="true" aria-describedby="reverse-ipv6-error"' : '' ?>>
                            <?php if (isset($fieldErrors['reverse_ipv6'])): ?>
                                <div id="reverse-ipv6-error" class="field-error" data-error-message="reverse_ipv6"><?= nl2br(htmlspecialchars($fieldErrors['reverse_ipv6'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="ipv6-info">
                            <strong>ℹ Zona reversa IPv6 manual</strong>
                            <p>Informe um prefixo IPv6 exclusivo para este domínio. Se o prefixo já estiver cadastrado, a criação será bloqueada para evitar mistura de registros entre domínios.</p>
                        </div>
                    </div>
                </div>

                <p class="help">
                    “Gerar exemplo” apenas preenche o formulário com dados fictícios de documentação.
                    Nenhum domínio ou PTR é criado até você clicar em “Criar domínio”.
                </p>

                <div class="actions">
                    <button type="button" id="generate-example">Gerar exemplo</button>
                    <button type="submit" name="create_domain">Criar domínio</button>
                </div>
            </form>
        </div>
    </section>

</main>

<script>
function closeAlert(element, onClosed) {
    if (!element || !element.isConnected) return;

    element.style.transition = "opacity 0.5s";
    element.style.opacity = "0";

    setTimeout(function() {
        element.remove();
        if (typeof onClosed === "function") onClosed();
    }, 500);
}

setTimeout(function() {
    closeAlert(document.getElementById("alertaSucesso"));
    closeAlert(document.getElementById("alertaAviso"));
}, 3000);

const globalErrorAlert = document.querySelector(".global-alert-error");

if (globalErrorAlert) {
    let userIsInteracting = false;
    const form = document.querySelector("form.form-grid");

    ["input", "change", "focusin", "pointerdown", "keydown"].forEach(function(eventName) {
        form?.addEventListener(eventName, function() {
            userIsInteracting = true;
        }, { once: true });
    });

    setTimeout(function() {
        closeAlert(globalErrorAlert, function() {
            if (userIsInteracting) return;

            let target = null;
            const domainError = document.querySelector(
                "#domain-error, #ns1-ipv4-error, #ns1-ipv6-error, #ns2-ipv4-error, #ns2-ipv6-error"
            );

            if (domainError) {
                target = domainError.closest(".group")
                    || document.querySelector('[data-error-section="domain"]');
            } else if (document.getElementById("reverse-ipv4-error")) {
                target = document.querySelector('[data-error-section="reverse_ipv4"]');
            } else if (document.getElementById("reverse-ipv6-error")) {
                target = document.querySelector('[data-error-section="reverse_ipv6"]');
            }

            target?.scrollIntoView({
                behavior: "smooth",
                block: "center"
            });
        });
    }, 5000);
}
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
const generateExampleButton = document.getElementById('generate-example');
let clearGeneratedExampleTimer = null;
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

document.querySelectorAll('[data-error-field]').forEach(input => {
    input.addEventListener('input', () => {
        const key = input.dataset.errorField;
        if (!key) return;

        input.classList.remove('input-error');
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');

        document.querySelector(`[data-error-section="${key}"]`)
            ?.classList.remove('section-error');
        document.querySelector(`[data-error-message="${key}"]`)?.remove();
    });
});

revInput?.addEventListener('input', () => {
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

generateExampleButton?.addEventListener('click', () => {
    const exampleValues = {
        new_domain: 'exemplo.com.br',
        ipv4: '192.0.2.253',
        ipv4_ns2: '192.0.2.254',
        ipv6: '2001:db8:2000::242',
        ipv6_ns2: '2001:db8:2000::243',
        rev_cidr: '192.0.2.0/24',
        ipv6_prefix: '2001:db8::/32',
    };

    Object.entries(exampleValues).forEach(([name, value]) => {
        const field = document.querySelector(`[name="${name}"]`);
        if (field) field.value = value;
    });

    updateReversePanels();
    window.scrollTo({
        top: 0,
        behavior: 'smooth'
    });

    if (clearGeneratedExampleTimer !== null) {
        clearTimeout(clearGeneratedExampleTimer);
    }

    clearGeneratedExampleTimer = setTimeout(() => {
        Object.entries(exampleValues).forEach(([name, value]) => {
            const field = document.querySelector(`[name="${name}"]`);
            if (field && field.value === value) {
                field.value = '';
            }
        });

        updateReversePanels();
        clearGeneratedExampleTimer = null;
    }, 4000);
});

document.querySelector('form[method="POST"]')?.addEventListener('submit', () => {
    updatePtrTemplate();
});

updateReversePanels();
</script>
<?php if ($showDomainCreatedSuccess): ?>
<script>
setTimeout(function() {
    window.location.href = 'dashboard.php';
}, 3000);
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
