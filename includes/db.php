<?php

function db_conectar(int $flags = 0): PDO
{
    $arquivo = __DIR__ . '/../db/painel_dns.sqlite';

    $pdo = new PDO('sqlite:' . $arquivo);
    if ($flags !== 0 && defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
        $pdo->setAttribute(PDO::SQLITE_ATTR_OPEN_FLAGS, $flags);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 2);
    $pdo->exec('PRAGMA busy_timeout = 2000');

    return $pdo;
}

function db(): PDO
{
    return db_conectar();
}

function db_leitura(): PDO
{
    return db_conectar(PDO::SQLITE_OPEN_READONLY);
}
