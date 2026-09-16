# DNS Center v1.3.25

Data: 2026-09-16

## Escopo

Hotfix pego ao vivo em produção logo depois do deploy da v1.3.24: o
apply de uma zona (`0.2.0.192.in-addr.arpa`, no cliente cliente-exemplo)
continuava mostrando `"rolled_back": false` e `"diagnostics": null` no
painel, mesmo já com os dois lados (agente 0.8.0 e app 1.3.24)
publicados e confirmados.

### Causa raiz

A correção da v1.3.24 adicionou `diagnostics`/`rolled_back` corretos
em `apply_staging()`, mas existe um segundo ponto em
`agent/dns-center-agent.py`, dentro de `sync_zones()`, que intercepta
qualquer falha de `apply_staging()` só pra registrar o evento de
publicação (`report_publication(..., "failed", ...)`) e depois
**re-lança um `AgentError(error)` novo, genérico**, descartando o
objeto original — junto com ele, `rolled_back` e `diagnostics`. Esse
`AgentError` novo é o que chega em `run_authorized_operation()`, que
só preserva esses campos quando a exceção capturada é uma
`AgentOperationError` — e essa checagem falha, porque a exceção já não
é mais a original.

Achado analisando ao vivo a falha real do dns-secondary (operação #95): a
mensagem sanitizada (`error`) veio certa, mas o `result` salvo tinha
`diagnostics: null`, contradizendo o código de `apply_staging()`, que
claramente monta o diagnóstico. A causa só apareceu ao rastrear o
caminho completo da exceção até `sync_zones()`.

### Mudança

- `sync_zones()`: o `raise AgentError(error) from exception` que
  fechava o handler de falha de `apply_staging()` agora relança
  `AgentOperationError`, preservando `rolled_back` e `diagnostics` da
  exceção original (`getattr` com fallback seguro, pra não quebrar se
  algum dia essa função for chamada com uma exceção de outro tipo).
- `AGENT_VERSION` bump pra `0.8.1`.

## Testes e gates

- Novo teste `test_sync_zones_preserves_diagnostics_from_apply_staging_failure`
  em `tests/Agent/test_dns_center_agent.py`, reproduzindo o cenário
  exato do incidente (conflito de zona duplicada) através de
  `sync_zones(apply=True, ...)` de ponta a ponta, não só de
  `apply_staging()` isolado — é exatamente esse pulo que a bateria da
  v1.3.24 não cobria.
- `python3 -m unittest discover -s tests/Agent`: 110 testes — sem
  regressão (109 → 110).

## Deploy

Pendente de execução pelo operador. Só o agente muda nesta versão —
depois do deploy do app (pra servir o script novo), clicar em
"Atualizar agente" em ns1 e ns2 do cliente-exemplo (e qualquer outro servidor)
pra essa correção valer no próximo apply.
