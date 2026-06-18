<?php

require_once __DIR__ . '/db.php';

function usuarios_garantir_esquema(): void
{
    static $pronto = false;

    if ($pronto) {
        return;
    }

    db()->exec("
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
        )
    ");

    $pronto = true;
}

function usuario_por_login(string $usuario): ?array
{
    usuarios_garantir_esquema();
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE usuario = :usuario COLLATE NOCASE LIMIT 1');
    $stmt->execute([':usuario' => trim($usuario)]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    return $registro ?: null;
}

function usuario_por_id(int $id): ?array
{
    usuarios_garantir_esquema();
    $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    return $registro ?: null;
}

function usuario_nome_valido(string $usuario): bool
{
    return preg_match('/^[a-zA-Z0-9._-]{3,32}$/', $usuario) === 1;
}

function usuario_senha_valida(string $senha): bool
{
    return strlen($senha) >= 8 && strlen($senha) <= 128;
}

function usuario_perfil_valido(string $perfil): bool
{
    return in_array($perfil, ['administrador', 'moderador'], true);
}

function usuario_perfil_legivel(string $perfil): string
{
    return $perfil === 'administrador' ? 'Administrador' : 'Moderador';
}

function usuario_eh_administrador(): bool
{
    return ($_SESSION['perfil'] ?? '') === 'administrador';
}

function exigir_administrador(): void
{
    if (!usuario_eh_administrador()) {
        http_response_code(403);
        exit('Acesso restrito a administradores.');
    }
}

function total_administradores_ativos(?int $ignorarId = null): int
{
    $sql = "SELECT COUNT(*) FROM usuarios WHERE perfil = 'administrador' AND ativo = 1";
    $params = [];

    if ($ignorarId !== null) {
        $sql .= ' AND id <> :id';
        $params[':id'] = $ignorarId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}
