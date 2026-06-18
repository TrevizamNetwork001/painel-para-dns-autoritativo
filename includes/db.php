<?php

function db(): PDO
{
    $arquivo = __DIR__ . '/../db/painel_dns.sqlite';

    $pdo = new PDO('sqlite:' . $arquivo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}
