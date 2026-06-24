# Handoff — Modelo visual DNS Manager em Servidores DNS

Data: 2026-06-24

## Objetivo

Aplicar em `dns-servers.php` o modelo visual aprovado para “DNS Manager /
Servidores DNS”, mantendo integralmente a lógica operacional existente da
página.

## Arquivo funcional alterado

- `/var/www/html/painel/dns-servers.php`

## Arquivos de documentação

- `/var/www/html/painel/CHANGELOG.md`
- `/var/www/html/painel/HANDOFF_DNS_SERVERS_UI_DNS_MANAGER_2026-06-24.md`

## Backup criado

- `/var/www/html/painel/dns-servers.php.bak-before-ui-dns-manager-model`

## Resultado entregue

- Topbar com título `Servidores DNS`, subtítulo de gestão BIND9 e botões:
  `Atualizar`, `Ajuda` e `+ Adicionar servidor`.
- Sidebar escura com marca `DNS Manager`, busca de servidor, lista compacta,
  borda azul no servidor ativo, badges de status e resumo lateral.
- Card principal do servidor selecionado com ícone, nome, ID, função, IP,
  hostname, última verificação, botão de teste, atalho visual de sincronização
  e menu `Ações`.
- Cards rápidos para:
  - SSH;
  - Agente remoto;
  - BIND / named;
  - named-checkconf;
  - AXFR;
  - Última verificação.
- Navegação visual por abas:
  - Resumo;
  - Credenciais;
  - Agente remoto;
  - Zonas;
  - Auditoria;
  - Avançado.
- Cards principais para saúde do servidor, eventos recentes, informações
  gerais, zonas, credenciais, agente remoto e zona de risco.
- Card vermelho `Avançado / Zona de risco` com as ações existentes de migrar
  layout e remover servidor.
- Modal simples de ajuda, sem ação destrutiva ou novo fluxo de backend.

## Lógica preservada

Não foram alterados:

- includes;
- scripts;
- comandos SSH, BIND, named-checkconf, AXFR, bootstrap, migração ou remoção;
- `require_csrf()`;
- `csrf_field()`;
- `dns_servers_redirecionar()`;
- `dns_servers_auditar()`;
- `dns_servers_render_modal_resultado()`;
- `dns_servers_formatar_timestamp_local()`;
- endpoint JSON `visualizar_credencial`;
- auditoria;
- salvamento e leitura de credenciais;
- transação de remoção do servidor;
- limpeza das tabelas de inventário DNS;
- lógica de bootstrap/provisionamento;
- lógica de agentes;
- lógica de inventário DNS;
- JavaScript de seleção via `localStorage`, busca, abertura de dialogs,
  retorno de modal e visualização de senha.

## Ações POST preservadas

Foram mantidas as ações:

- `visualizar_credencial`;
- `cadastrar`;
- `atualizar`;
- `remover`;
- `atualizar_agente_admin`;
- `remover_agente_admin`;
- `instalar_agente`;
- `testar_transferencia`;
- `migrar_layout_slave`;
- `testar_ssh`;
- `testar_bind`.

## Confirmações preservadas

- `confirm('Remover este servidor do painel?')`
- `confirm('Migrar blocos slave legados para named.conf.local neste servidor? Nenhum arquivo legado sera apagado.')`

## Validações executadas

```text
php -l /var/www/html/painel/dns-servers.php
No syntax errors detected in /var/www/html/painel/dns-servers.php
```

```text
git -C /var/www/html/painel diff --check -- dns-servers.php
```

Sem erros.

Também foram conferidos por `grep`:

- nomes de ações POST exigidas;
- atributos e IDs usados pelo JavaScript;
- `quick-menu`;
- modais `tools-server-*`, `edit-server-*`, `agent-server-*` e
  `add-server-modal`;
- uso de `localStorage`;
- confirmações perigosas.

## Observações de git

Antes do commit havia alterações não relacionadas no repositório:

- `db/dns_servers.secret`;
- `db/painel_dns.sqlite`;
- `domains.php`;
- `zones.php`;
- `ultima_validacao_resumo.png`;
- `zones.php.bak-v3-visual-dashboard`.

Esses itens não fazem parte deste handoff e não devem ser incluídos no commit
da UI de servidores DNS.

## Estado final esperado

A página `dns-servers.php` está publicada no diretório ativo com o novo visual,
mantendo os fluxos operacionais existentes. O próximo passo recomendado é a
validação manual autenticada em navegador, sem executar ações destrutivas em
produção sem autorização explícita.
