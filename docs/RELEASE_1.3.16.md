# DNS Center v1.3.16

Data: 2026-09-16

## Escopo

Correção de UX encontrada ao vivo em produção logo após o rollout da
v1.3.15: depois de um "Aplicar agora" bem-sucedido (ns1 e ns2 da
Cliente Legado, zona 18 `8.b.d.0.1.0.0.2.ip6.arpa`), o painel continuava
mostrando o alerta "Serial divergente" pros dois servidores mesmo com
o apply confirmado.

### Causa raiz

O alerta vem de `dns_authoritative_observations`, uma tabela separada
da operação de apply — populada só pelo agente rodando
`--observe-bind`, disparado pelo `dns-center-agent.timer` a cada 5
minutos. O `apply_zones` bem-sucedido só chamava `send_readiness()`
depois de aplicar; não recalculava a observação autoritativa. Como a
última leitura tinha sido feita segundos (ou minutos) antes do apply
terminar, o serial antigo continuava exibido até o próximo ciclo do
timer — não era uma falha real, mas um atraso de até 5 minutos na
exibição.

### Mudança

- `agent/dns-center-agent.py`: depois de um `apply_zones`
  bem-sucedido, `run_authorized_operation()` agora também chama
  `send_authoritative_observation(config)` (best-effort, mesmo padrão
  de `send_readiness()` — uma falha de rede aqui não derruba o
  resultado "succeeded" do apply, que já aconteceu de verdade).
  Escopado só a `apply_zones` porque é a única ação que muda o
  conteúdo/serial de zonas já publicadas no BIND.
- `AGENT_VERSION` bump pra `0.7.9`, necessário pro botão "Atualizar
  agente" detectar a atualização.

### Rollout

Mesma mecânica da v1.3.15: depois do deploy, clicar em "Atualizar
agente" em cada servidor (ns1 e ns2 já em 0.7.8, dns-primary ainda em
0.7.4) pra essa correção valer neles.

### Fora de escopo (spin-off já registrado)

Durante a investigação foi encontrado um bug separado e não
relacionado: timestamps enviados pelo agente em UTC (`agent_observed_at`
e outros campos de `DnsAuthoritativeObservationController`) chegam a
ficar com +3h de deslocamento ao serem gravados, por causa da
combinação `APP_TIMEZONE=America/Sao_Paulo` + cast `immutable_datetime`
+ conexão Postgres na mesma timezone. Não é o que causava o "Serial
divergente" (esse é o `status`, não o timestamp de exibição) — vai ser
tratado como uma correção própria, separada desta versão.

## Testes e gates

- Novos testes em `tests/Agent/test_dns_center_agent.py`:
  `test_run_authorized_operation_dispatches_apply_zones` atualizado
  pra confirmar `send_authoritative_observation(config)` é chamado
  uma vez após `apply_zones`; `test_run_authorized_operation_dispatches_upgrade_agent`
  atualizado pra confirmar que **não** é chamado fora de
  `apply_zones`; novo teste
  `test_run_authorized_operation_apply_zones_survives_observation_failure`
  confere que uma falha nessa chamada best-effort não muda o
  resultado "succeeded" do apply.
- `python3 -m unittest discover -s tests/Agent`: 106 testes — sem
  regressão (105 → 106, novo teste).
- `php artisan test`: 277 testes, 1387 assertions — sem regressão
  (nenhuma mudança de código Laravel nesta versão).
- Pint: 169 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `1eb9be3` (tag
`v1.3.16`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.16
```

- imagens `dns-center-app:1.3.16`/`dns-center-web:1.3.16` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado antes de qualquer migration:
  `dns-center-20260916T174303Z.dump`, 2348802 bytes, SHA-256
  `80d2b8a30934b9c6ee5f78df0342895d946cd8c40f2d6757a8e84b2a9697b34a`;
- nenhuma migration nova nesta versão (mudança restrita ao agente
  Python);
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação (`app`, `web`, `queue`, `scheduler`) confirmados
na imagem `1.3.16`.

Pendente (operacional, fora deste deploy): atualizar o agente do
ns1/ns2 pra 0.7.9 via botão "Atualizar agente" — sem isso, a correção
do "Serial divergente" não é aplicada nos servidores já existentes.
