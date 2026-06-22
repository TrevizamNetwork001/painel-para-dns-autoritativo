# Handoff — Correção dos botões cortados no Firewall

Data: 2026-06-22

## Correção visual

- Coluna Ações fixada em 126 px nas três tabelas.
- Botões Editar e Remover impedidos de encolher.
- `row-actions` configurado com largura baseada no conteúdo.
- Padding, gap, fonte e ícones compactados somente nas ações das tabelas.
- Colunas auxiliares receberam larguras fixas menores.
- Descrição continua flexível e truncada com ellipsis.
- Overflow permanece contido dentro dos cards.

## Escopo preservado

- Nenhuma lógica PHP ou handler POST foi alterado.
- CRUD, validação, aplicação, rollback, auditoria, SQLite, CSRF e confirmações
  fortes permanecem intactos.
- Nenhuma regra nftables foi aplicada.

## Validação

- `php -l firewall.php`
- `git diff --check`
