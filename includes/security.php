<?php

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Requisição inválida.');
    }
}

function valid_domain(string $domain): bool
{
    if (strlen($domain) > 253 || !str_contains($domain, '.')) {
        return false;
    }

    return preg_match(
        '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
        $domain
    ) === 1;
}

function valid_hostname(string $hostname): bool
{
    $hostname = rtrim($hostname, '.');

    return $hostname === '@' ||
        preg_match('/^(?:[a-z0-9_*](?:[a-z0-9_*-]{0,61}[a-z0-9_*])?\.)*[a-z0-9_*](?:[a-z0-9_*-]{0,61}[a-z0-9_*])?$/i', $hostname) === 1;
}

function valid_cidr(string $cidr, int $ipFlag): bool
{
    $parts = explode('/', $cidr, 2);

    if (!filter_var($parts[0], FILTER_VALIDATE_IP, $ipFlag)) {
        return false;
    }

    if (count($parts) === 1) {
        return true;
    }

    if (!ctype_digit($parts[1])) {
        return false;
    }

    $max = $ipFlag === FILTER_FLAG_IPV4 ? 32 : 128;
    $prefix = (int) $parts[1];

    return $prefix >= 0 && $prefix <= $max;
}

function increment_zone_serial(string $content): string
{
    if (!preg_match('/^(\s*)(\d{10})(\s*(?:;\s*serial)?\s*)$/mi', $content, $match)) {
        return $content;
    }

    $current = $match[2];
    $today = date('Ymd');
    $sequence = substr($current, 0, 8) === $today
        ? min(99, ((int) substr($current, 8, 2)) + 1)
        : 1;
    $serial = $today . str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);

    return preg_replace(
        '/^(\s*)' . preg_quote($current, '/') . '(\s*(?:;\s*serial)?\s*)$/mi',
        '${1}' . $serial . '${2}',
        $content,
        1
    );
}

function validate_zone_content(string $zone, string $content, ?string &$error = null): bool
{
    $tmp = tempnam(sys_get_temp_dir(), 'dns-zone-');

    if ($tmp === false || file_put_contents($tmp, $content, LOCK_EX) === false) {
        $error = 'Não foi possível criar o arquivo temporário da zona.';
        return false;
    }

    exec(
        '/usr/bin/named-checkzone ' . escapeshellarg($zone) . ' ' . escapeshellarg($tmp) . ' 2>&1',
        $output,
        $status
    );
    @unlink($tmp);

    if ($status !== 0) {
        $error = implode("\n", $output);
        return false;
    }

    return true;
}

function write_file_safely(string $file, string $content): bool
{
    $mode = file_exists($file) ? (fileperms($file) & 0777) : 0644;
    $tmp = @tempnam(dirname($file), '.dns-panel-');

    if ($tmp === false) {
        return file_put_contents($file, $content, LOCK_EX) !== false;
    }

    if (file_put_contents($tmp, $content, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }

    chmod($tmp, $mode);

    if (!rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

function reload_dns(): bool
{
    exec('sudo /usr/sbin/rndc reload 2>&1', $output, $status);
    return $status === 0;
}

function bind_zone_name_for_file(string $file): ?string
{
    $config = file_get_contents('/etc/bind/named.conf.local');
    if ($config === false) {
        return null;
    }

    $pattern = '/zone\s+"([^"]+)"\s*\{(?:(?!\};).)*\bfile\s+"' .
        preg_quote($file, '/') . '"\s*;(?:(?!\};).)*\};/s';

    return preg_match($pattern, $config, $match) ? $match[1] : null;
}

function ipv6_ptr_owner(string $ipv6, string $zoneName): ?string
{
    $binary = inet_pton($ipv6);
    if ($binary === false || !str_ends_with($zoneName, '.ip6.arpa')) {
        return null;
    }

    $full = implode('.', array_reverse(str_split(bin2hex($binary))));
    $zonePrefix = substr($zoneName, 0, -strlen('.ip6.arpa'));

    if ($full === $zonePrefix) {
        return '@';
    }

    $suffix = '.' . $zonePrefix;
    if (!str_ends_with($full, $suffix)) {
        return null;
    }

    return substr($full, 0, -strlen($suffix));
}

function ipv4_ptr_owner(string $ipv4, string $zoneName): ?string
{
    if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ||
        !str_ends_with($zoneName, '.in-addr.arpa')) {
        return null;
    }

    $full = implode('.', array_reverse(explode('.', $ipv4)));
    $zonePrefix = substr($zoneName, 0, -strlen('.in-addr.arpa'));

    if ($full === $zonePrefix) {
        return '@';
    }

    $suffix = '.' . $zonePrefix;
    if (!str_ends_with($full, $suffix)) {
        return null;
    }

    return substr($full, 0, -strlen($suffix));
}

function format_forward_zone_content(string $content): string
{
    $lines = preg_split('/\R/', trim($content));
    $header = [];
    $records = [];

    foreach ($lines as $line) {
        $trim = trim($line);

        if ($trim === '') {
            continue;
        }

        if (preg_match('/^(\S+)\s+IN\s+(A|AAAA|CNAME|MX|TXT)\s+(.+)$/i', $trim, $m)) {
            $records[] = [
                'host'  => $m[1],
                'type'  => strtoupper($m[2]),
                'value' => $m[3],
            ];
        } else {
            $header[] = $line;
        }
    }

    usort($records, function ($a, $b) {
        $typeOrder = [
            'MX'    => 1,
            'A'     => 2,
            'AAAA'  => 3,
            'CNAME' => 4,
            'TXT'   => 5,
        ];

        $hostCompare = strcmp($a['host'], $b['host']);

        if ($hostCompare !== 0) {
            return $hostCompare;
        }

        return ($typeOrder[$a['type']] ?? 99) <=> ($typeOrder[$b['type']] ?? 99);
    });

    $output = rtrim(implode("\n", $header)) . "\n\n";

    $lastHost = null;

    foreach ($records as $record) {
        if ($lastHost !== null && $lastHost !== $record['host']) {
            $output .= "\n";
        }

        $output .= sprintf(
            "%-20s IN %-5s %s\n",
            $record['host'],
            $record['type'],
            $record['value']
        );

        $lastHost = $record['host'];
    }

    return rtrim($output) . "\n";
}

function normalize_dns_value(string $type, string $value): string
{
    $type = strtoupper(trim($type));
    $value = trim($value);

    if ($type === 'CNAME') {
        return str_ends_with($value, '.') ? $value : $value . '.';
    }

    if ($type === 'MX') {
        $parts = preg_split('/\s+/', $value, 2);

        if (count($parts) === 2) {
            $target = rtrim($parts[1], '.');

            return $parts[0] . ' ' . $target . '.';
        }
    }

    return $value;
}
