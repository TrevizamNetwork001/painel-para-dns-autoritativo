# Handoff — Portas Públicas do Firewall

Data: 2026-06-22

## Alterações

- Card Portas Públicas alinhado à referência `esse.png`.
- Cabeçalho simplificado para título e subtítulo.
- Botão duplicado Adicionar porta removido; a ação permanece em Ações rápidas.
- Tabela compacta e sem moldura interna pesada.
- Badge de protocolo roxo.
- Botões Editar e Remover com ícones lineares.
- Rodapé com total de portas e paginação visual.

## Funcionalidade preservada

- Cadastro, edição e remoção de portas públicas continuam funcionais.
- Nenhuma regra real de firewall foi aplicada.
- Banco de dados, validação, aplicação, rollback, auditoria e CSRF não foram
  alterados.

## Validação

- `php -l firewall.php`
- `git diff --check`
