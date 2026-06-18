<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$session_timeout = 900; // 900 segundos = 15 minutos
$domains = [];

foreach (glob("/var/cache/bind/master-aut/*.hosts") as $f) {

    if (basename($f) == ".hosts") {
        continue;
    }

    $domain = basename($f, ".hosts");
    $domains[$domain] = $f;
}


ksort($domains);
?>
