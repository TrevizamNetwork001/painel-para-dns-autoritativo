# Handoff — Acesso Administrativo do Firewall

Data: 2026-06-22

## Objetivo

Alinhar o card Acesso Administrativo do `firewall.php` à imagem de referência
`esse.png`.

## Alterações visuais

- Cabeçalho com título, subtítulo e busca com ícone.
- Remoção do botão duplicado Adicionar IP do cabeçalho.
- Tabela sem moldura interna pesada e com linhas mais compactas.
- Badge IPv4/IPv6 azul.
- Botões Editar e Remover com ícones lineares azul e vermelho.
- Rodapé no formato `Mostrando 1 a N de N registros`.
- Paginação visual com página 1 selecionada.

## Funcionalidade preservada

- Adicionar IP permanece disponível em Ações rápidas.
- Busca, edição e remoção continuam funcionais.
- Nenhuma regra de firewall ou registro do banco foi alterado.

## Validação

- `php -l firewall.php`
- `git diff --check`
