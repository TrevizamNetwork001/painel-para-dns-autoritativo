# DNS Center v1.3.4

Data: 2026-09-08

## Escopo

Botão "Aplicar agora" na aba de Publicação da zona, pra aplicar zonas
publicadas de verdade no BIND sem precisar de SSH manual. Até aqui,
depois de publicar uma zona, era necessário logar no servidor e rodar:

```
sudo DNS_CENTER_AGENT_ALLOW_APPLY=1 dns-center-agent --sync-zones --apply --confirm "APLICAR ZONAS <servidor>"
```

Isso foi feito manualmente hoje mesmo pra zona
`192.0.2.in-addr.arpa` da Cliente Legado (ficou presa em
`downloaded`/`pending` até o comando ser rodado nos dois servidores).

### Como funciona

Reaproveita o mecanismo de **operação autorizada** que
`install_bind`/`upgrade_agent` já usam — sem endpoint novo de push,
sem SSH:

- Clique em "Aplicar agora em `<servidor>`" abre um modal de
  confirmação (não dispara nada ainda).
- Um segundo clique em "Confirmar aplicação" cria uma
  `DnsBindOperation` com `action = apply_zones`, `status =
  authorized` — mesmo padrão de `discover_bind_zones`.
- O agente já em polling de ~30s
  (`dns-center-agent-operation.timer`) pega a operação e executa
  `sync_zones(config, apply=True, confirmation="APLICAR ZONAS
  <servidor>")` — a frase de confirmação é suprida internamente pelo
  agente (a autorização humana já aconteceu no clique de confirmação
  do modal, com RBAC de `organization_admin`/`platform_admin`).
- O painel faz polling do status até `succeeded`/`failed`/`expired` e
  recarrega a página quando aplicado.
- `DnsAgentPublication` é atualizado do mesmo jeito que já era (via
  `report_publication()` dentro de `sync_zones`), então o resto do
  painel (contadores de aplicação confirmada, serial confirmado etc)
  não precisou de mudança.

Aplica **todas** as zonas pendentes daquele servidor, não só a zona
aberta no momento — porque o agente sincroniza por servidor, não por
zona. O texto do botão deixa isso explícito.

### Opt-in obrigatório por servidor

`DNS_CENTER_AGENT_ALLOW_APPLY=1` continua sendo exigido e não vem do
painel — é a decisão local do sysadmin do servidor. Pra habilitar o
botão sem precisar rodar nada manual a cada aplicação:

```
sudo systemctl edit dns-center-agent-operation.service
```

```
[Service]
Environment=DNS_CENTER_AGENT_ALLOW_APPLY=1
```

```
sudo systemctl daemon-reload
```

Sem isso, o botão cria a operação normalmente mas ela falha com "Apply
bloqueado: defina DNS_CENTER_AGENT_ALLOW_APPLY=1." — reportado de
volta como `failed`, visível no modal, não silencioso.

### Agente (v0.7.6)

- Allow-list de `run_authorized_operation` passa a aceitar
  `apply_zones`.
- Novo branch de dispatch chama `sync_zones(config, apply=True,
  confirmation=f"APLICAR ZONAS {server_name}")`.

### Painel

- `DnsBindOperation::ACTIONS` inclui `apply_zones`.
- Novo `DnsBindApplyController` (`store`/`status`), rotas
  `servers.bind.apply` (`POST
  /servidores/{server}/bind/aplicar-zonas`) e
  `servers.bind.apply.status`.
- `store()` bloqueia se não há nada pendente
  (`DnsAgentPublication` sem status `pending`/`downloaded`/
  `applying`/`failed` naquele servidor) e se já existe uma aplicação
  em andamento — mesmos guards de `discover_bind_zones`.
- UI nova em `zones/show.blade.php`, aba Publicação: botão por
  servidor pendente + modal de confirmação (reaproveita o CSS do
  modal de registro, `.record-modal-backdrop`/`.record-modal`).

## Testes e gates

- `tests/Agent/test_dns_center_agent.py`: 87 testes (1 novo,
  `test_run_authorized_operation_dispatches_apply_zones`). OK.
- `php artisan test`: 243 testes, 1323 assertions (6 novos em
  `DnsBindApplyTest`). Sem regressão.
- Pint: sem violações de estilo.
- `route:list`: `servers.bind.apply`/`servers.bind.apply.status`
  presentes.
- `view:cache`: templates Blade compilam sem erro.

## Rollout

Servidores já cadastrados (ns1/ns2 da Cliente Legado) precisam do agente
atualizado pra 0.7.6 (botão "Atualizar agente" no painel) e do opt-in
`DNS_CENTER_AGENT_ALLOW_APPLY=1` no `dns-center-agent-operation.service`
(passo manual único, documentado acima e em `docs/AGENT_API.md`) antes
do botão "Aplicar agora" funcionar neles.
