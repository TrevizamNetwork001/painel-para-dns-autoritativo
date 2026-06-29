<?php

require_once __DIR__ . '/db.php';

function audit_usuario_atual(): string
{
    $usuario = $_SESSION['username']
        ?? $_SESSION['user']
        ?? $_SESSION['usuario']
        ?? null;

    if (!is_string($usuario) || trim($usuario) === '') {
        return 'desconhecido';
    }

    return trim($usuario);
}

function audit_ip_atual(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'CLI';
}

function audit_dominio_base(?string $dominio): ?string
{
    if ($dominio === null || trim($dominio) === '') {
        return null;
    }

    return preg_replace('/\.(?:rev6|rev)$/i', '', trim($dominio));
}

function audit_ptr_ipv6_para_endereco(string $ptr, string $zonaBind): ?string
{
    $ptr = strtolower(trim($ptr, ". \t\n\r\0\x0B"));
    $zonaBind = strtolower(trim($zonaBind, ". \t\n\r\0\x0B"));

    if (!preg_match('/^[0-9a-f](?:\.[0-9a-f])*$/', $ptr)) {
        return null;
    }

    $sufixo = '.ip6.arpa';
    if (!str_ends_with($zonaBind, $sufixo)) {
        return null;
    }

    $prefixo = trim(substr($zonaBind, 0, -strlen($sufixo)), '.');
    $nibbles = $ptr;

    if ($prefixo !== '') {
        $nibbles .= '.' . $prefixo;
    }

    $hex = implode('', array_reverse(explode('.', $nibbles)));
    if (strlen($hex) > 32) {
        return null;
    }

    $hex = str_pad($hex, 32, '0', STR_PAD_RIGHT);
    $binario = @hex2bin($hex);
    $endereco = $binario === false ? false : @inet_ntop($binario);

    return $endereco ?: null;
}

function audit_valor_ptr(string $endereco, string $hostname): string
{
    return trim($endereco) . ' => ' . trim($hostname);
}

function registrar_auditoria(array $dados): void
{
    $pdo = db();
    $acao = $dados['acao'] ?? 'AÇÃO_DESCONHECIDA';
    $valorAntigo = $dados['valor_antigo'] ?? null;
    $valorNovo = $dados['valor_novo'] ?? null;

    if (
        (str_starts_with($acao, 'ADICIONAR_') || str_starts_with($acao, 'CRIAR_'))
        && ($valorAntigo === null || trim((string) $valorAntigo) === '')
    ) {
        $valorAntigo = 'inexistente';
    }

    if (
        str_starts_with($acao, 'REMOVER_')
        && ($valorNovo === null || trim((string) $valorNovo) === '')
    ) {
        $valorNovo = 'removido';
    }

    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            usuario,
            ip,
            acao,
            dominio,
            tipo_registro,
            nome_registro,
            valor_antigo,
            valor_novo,
            status,
            mensagem
        ) VALUES (
            :usuario,
            :ip,
            :acao,
            :dominio,
            :tipo_registro,
            :nome_registro,
            :valor_antigo,
            :valor_novo,
            :status,
            :mensagem
        )
    ");

    $stmt->execute([
        ':usuario'       => $dados['usuario'] ?? audit_usuario_atual(),
        ':ip'            => $dados['ip'] ?? audit_ip_atual(),
        ':acao'          => $acao,
        ':dominio'       => audit_dominio_base($dados['dominio'] ?? null),
        ':tipo_registro' => $dados['tipo_registro'] ?? null,
        ':nome_registro' => $dados['nome_registro'] ?? null,
        ':valor_antigo'  => $valorAntigo,
        ':valor_novo'    => $valorNovo,
        ':status'        => $dados['status'] ?? 'OK',
        ':mensagem'      => $dados['mensagem'] ?? null,
    ]);
}
