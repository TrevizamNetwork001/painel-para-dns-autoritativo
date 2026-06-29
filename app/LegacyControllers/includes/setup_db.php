<?php

require_once __DIR__ . '/db.php';

$pdo = db();

$pdo->exec("
CREATE TABLE IF NOT EXISTS audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT NOT NULL,
    ip TEXT NOT NULL,
    acao TEXT NOT NULL,
    dominio TEXT,
    tipo_registro TEXT,
    nome_registro TEXT,
    valor_antigo TEXT,
    valor_novo TEXT,
    status TEXT NOT NULL DEFAULT 'OK',
    mensagem TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT NOT NULL COLLATE NOCASE UNIQUE,
    senha_hash TEXT NOT NULL,
    perfil TEXT NOT NULL DEFAULT 'moderador'
        CHECK (perfil IN ('administrador', 'moderador')),
    ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    trocar_senha INTEGER NOT NULL DEFAULT 0 CHECK (trocar_senha IN (0, 1)),
    auth_version INTEGER NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS dns_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL COLLATE NOCASE UNIQUE,
    hostname TEXT NOT NULL COLLATE NOCASE UNIQUE,
    ip4 TEXT,
    ip6 TEXT,
    tipo TEXT NOT NULL DEFAULT 'slave'
        CHECK (tipo IN ('master', 'slave')),
    ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
    ssh_user TEXT NOT NULL DEFAULT 'dns-sync',
    ssh_port INTEGER NOT NULL DEFAULT 22
        CHECK (ssh_port BETWEEN 1 AND 65535),
    descricao TEXT,
    modo_instalacao TEXT NOT NULL DEFAULT 'manual',
    agente_status TEXT NOT NULL DEFAULT 'desconhecido',
    bind_status TEXT NOT NULL DEFAULT 'desconhecido',
    zonas_slave INTEGER,
    ultimo_status TEXT NOT NULL DEFAULT 'desconhecido'
        CHECK (ultimo_status IN ('desconhecido', 'online', 'offline')),
    ultima_verificacao DATETIME,
    ultima_mensagem TEXT,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
");

echo "Banco SQLite criado com sucesso.\n";
