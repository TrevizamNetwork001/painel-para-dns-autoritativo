<?php
function dns_log($action, $type, $name, $value, $domain) {

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $user = $_SESSION['usuario'] ?? 'unknown';

    $line = sprintf(
        "[%s] | %s | %s | %s | %s | %s | %s | %s\n",
        date('Y-m-d H:i:s'),
        $user,
        $ip,
        $action,
        $type,
        $name,
        $value,
        $domain
    );

    file_put_contents(
        "/var/log/dns-panel.log",
        $line,
        FILE_APPEND
    );
}

