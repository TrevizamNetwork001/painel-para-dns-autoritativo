# Handoff — Layout do botão Backup

Data: 2026-06-22

## Alteração

- Ajustado exclusivamente o botão Backup em Ações rápidas.
- Ícone posicionado na coluna esquerda.
- Título e descrição alinhados na coluna direita.
- Removido o deslocamento vertical anterior do ícone.

## Escopo

- Os demais botões de Ações rápidas não foram alterados.
- A função de backup/rollback permanece inalterada.
- Nenhuma regra de firewall foi aplicada.

## Validação

- `php -l firewall.php`
- `git diff --check`
