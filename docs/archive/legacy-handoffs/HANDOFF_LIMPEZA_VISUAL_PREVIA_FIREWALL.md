# Handoff Limpeza Visual da Prévia do Firewall

Data: 2026-06-21

## Objetivo

Remover a exibição das regras nftables da tela principal de `firewall.php`,
mantendo a página curta, operacional e voltada para ACLs, portas e estados.

## Arquivos alterados

- `firewall.php`
- `CHANGELOG.md`
- `HANDOFF_LIMPEZA_VISUAL_PREVIA_FIREWALL.md`

## Backup

Criado antes da alteração:

- `/tmp/firewall.php.before-preview-removal`

O backup é temporário e não faz parte do commit.

## Alterações visuais

- Removido o bloco `Prévia das regras`.
- Removidos `Ver prévia`, conteúdo somente leitura e renderização das regras.
- Removido o CSS exclusivo do bloco de prévia.
- Mantida a ação Validar na faixa Ações rápidas.
- Mantidos os detalhes de saída técnica nos cards de validação e aplicação.
- A faixa Aplicação real controlada foi removida do meio da página.
- Adicionada uma nota curta dentro de Última Aplicação:
  - `Aplicar altera regras reais após validação e backup obrigatório.`
- Bloqueios de aplicação continuam visíveis nessa nota contextual.
- Os cards de status agora ficam imediatamente após ACLs e portas.

## Preservação funcional

O backend PHP anterior ao HTML permaneceu idêntico por SHA-256.

Não foram alterados:

- geração interna das regras
- validação `nft -c`
- aplicação `nft -f`
- backup
- rollback
- auditoria
- SQLite
- handlers POST
- CSRF
- confirmações fortes
- sanitização
- regras de segurança

O formulário de validação principal continua disponível em Ações rápidas.

## Verificações

- `php -l firewall.php`
- `git diff --check -- firewall.php`
- comparação SHA-256 do backend
- busca no diff por funções, SQL, nft e handlers sensíveis
- renderização GET em cópia isolada
- confirmação de ausência de:
  - `Prévia das regras`
  - `Ver prévia`
  - `preview-details`
  - `rule-preview`
- confirmação da ação `validar_configuracao`
- confirmação dos cards:
  - Última Aplicação
  - Última Validação
  - Auditoria Recente
- confirmação da nota compacta de aplicação

## Segurança

- Nenhum POST foi executado.
- Nenhum comando nft foi executado.
- Nenhuma regra real foi aplicada.
- Nenhuma escrita intencional ocorreu no SQLite produtivo.

## Arquivos fora do commit

- `db/dns_servers.secret`
- `db/painel_dns.sqlite`
