<?php

function db(): PDO
{
    $arquivo = __DIR__ . '/../db/painel_dns.sqlite';

    $pdo = new PDO('sqlite:' . $arquivo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 2);
    $pdo->exec('PRAGMA busy_timeout = 2000');

    return $pdo;
}
