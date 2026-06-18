<?php

require_once __DIR__ . '/audit.php';

registrar_auditoria([
    'acao'          => 'TESTE_AUDITORIA',
    'dominio'       => 'teste.local',
    'tipo_registro' => 'A',
    'nome_registro' => 'www',
    'valor_antigo'  => null,
    'valor_novo'    => '192.168.0.10',
    'status'        => 'OK',
    'mensagem'      => 'Teste manual de auditoria'
]);

echo "Auditoria gravada com sucesso.\n";
