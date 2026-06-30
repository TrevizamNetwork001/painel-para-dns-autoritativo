# Handoff Alinhamento ao Mockup do Firewall

Data: 2026-06-21

## Objetivo

Reorganizar exclusivamente a apresentação de `firewall.php` para aproximá-la
do mockup operacional original, preservando integralmente a V1.6.

## Arquivos alterados

- `firewall.php`
- `CHANGELOG.md`
- `HANDOFF_ALINHAMENTO_MOCKUP_FIREWALL.md`

## Backup

Antes da alteração foi criada a cópia:

- `/tmp/firewall.php.before-mockup-alignment`

O backup é temporário e não faz parte do commit.

## Estrutura visual implementada

### Sidebar fixa

- Adicionada navegação lateral fixa no desktop.
- Firewall aparece como item ativo.
- Links diretos para dashboard, DNS, segurança, serviços, auditoria e conta.
- Em telas menores, a sidebar vira menu lateral acionado por botão.
- Overlay e fechamento ao selecionar um link foram adicionados somente como
  comportamento de navegação.

### Cabeçalho

- Mantidos Voltar ao painel, título, subtítulo e botão Atualizar.
- Cabeçalho separado do conteúdo por linha discreta.
- Dimensões e espaçamento seguem o padrão administrativo do painel.

### Cards superiores

- Mantidos cinco indicadores em uma linha:
  - IPv4 liberados
  - IPv6 liberados
  - portas administrativas
  - portas públicas
  - aplicação
- Cards permanecem compactos e com pouco peso visual.

### Ações rápidas

- Mantidas em linha, com botões compactos.
- Rollback foi retirado visualmente da faixa principal.
- Rollback continua disponível contextualmente no card Última Aplicação.
- Nenhuma ação ou formulário foi removido.

### Acesso Administrativo

- ACL IPv4 e ACL IPv6 foram agrupadas visualmente em um único bloco.
- A separação funcional por família permanece intacta.
- Cada família mantém busca, botão de inclusão, tabela e ações próprias.
- Adicionados rodapés discretos com contagem por família.
- Estado IPv6 vazio apresenta apenas uma mensagem.

### Portas

- Portas Administrativas e Portas Públicas ficaram na coluna operacional.
- Tabelas, badges e ações permanecem compactos.
- Adicionadas contagens discretas no rodapé.

### Validação, aplicação e auditoria

- Última Aplicação, Última Validação e Auditoria Recente formam uma linha de
  cards compactos em telas largas.
- Em telas menores, os cards quebram para duas ou uma coluna.
- Saídas técnicas continuam recolhidas.
- Rollback permanece contextual na aplicação.

### Prévia

- A prévia continua recolhida por padrão em `details/summary`.
- O conteúdo e o botão de validação continuam disponíveis.
- A prévia não domina o layout principal.

## Preservação funcional

O conteúdo PHP anterior ao início do HTML permaneceu idêntico por hash
SHA-256.

Foram preservados:

- 10 campos de ação POST
- 10 chamadas de `csrf_field()`
- 8 dialogs
- CRUD de ACLs e portas
- geração nftables
- validação
- aplicação
- backup
- rollback
- auditoria
- SQLite
- confirmações fortes
- comandos nft
- toast

## Verificações

- `php -l firewall.php`
- `git diff --check -- firewall.php`
- comparação SHA-256 do backend antes e depois
- comparação da quantidade de ações, CSRF e modais
- busca no diff por funções, SQL, nft e handlers sensíveis
- renderização GET em cópia isolada do painel e SQLite
- confirmação dos blocos:
  - Acesso Administrativo
  - Portas Administrativas
  - Portas Públicas
  - Última Aplicação
  - Última Validação
  - Auditoria Recente
  - prévia recolhida
- confirmação de uma única mensagem no estado IPv6 vazio

## Limitação da validação visual

O ambiente não possui navegador gráfico ou renderizador de screenshot. A
validação foi feita pela estrutura HTML renderizada, CSS responsivo e
comparação com o padrão de sidebar já existente no dashboard.

## Segurança

- Nenhuma ação POST foi executada.
- Nenhum comando nft foi executado.
- Nenhuma regra real foi aplicada.
- Nenhuma escrita intencional foi feita no SQLite produtivo.
- Nenhuma trava da V1.6 foi alterada.

## Arquivos fora do commit

- `db/dns_servers.secret`
- `db/painel_dns.sqlite`
