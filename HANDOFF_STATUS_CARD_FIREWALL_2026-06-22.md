# Handoff — Status do card Firewall

Data: 2026-06-22

## Alteração

O card superior Firewall deixou de exibir informações de backup e agora mostra
o estado operacional:

- `Ativo`: última aplicação registrada com status `APLICADO`.
- `Inativo`: nenhum estado aplicado ou último estado diferente de `APLICADO`.

O texto auxiliar passa a ser `Configuração em uso` ou `Firewall inativo`.
Quando estiver Inativo, o ícone utiliza destaque vermelho.

## Escopo preservado

- O botão Backup/Rollback continua disponível em Ações rápidas.
- Nenhuma regra real de firewall foi alterada.
- Aplicação, rollback, validação, auditoria, CSRF e banco de dados permanecem
  inalterados.

## Validação

- Sintaxe verificada com `php -l firewall.php`.
- Alterações verificadas com `git diff --check`.
