# DNS Center v1.3.24

Data: 2026-09-16

## Escopo

Fase 0 do plano de "nunca mais precisar de SSH": corrige um bug real
encontrado ao vivo em produção durante o incidente do cliente cliente-exemplo
— quando uma zona não conseguia ser aplicada por um erro do
`named-checkconf`/`named-checkzone`/`rndc`, o painel mostrava só
"Validação final falhou:" **sem nenhum detalhe**, forçando o operador
a entrar via SSH e rodar `journalctl` + o comando manualmente pra
descobrir a causa real.

### Causa raiz (duas, somadas)

1. `agent/dns-center-agent.py`: várias chamadas a `named-checkconf`,
   `named-checkzone` e `rndc` assumiam que o erro sempre vinha em
   `stderr`, sem nenhum fallback — quando a saída real vinha vazia ou
   em `stdout`, a mensagem reportada ficava em branco.
2. Mesmo quando havia detalhe, `sanitize_message()` — a função que
   protege segredos/tokens antes de mandar qualquer erro pro painel —
   também apaga qualquer trecho com "cara de caminho de arquivo"
   (`/etc/bind/...`), que é exatamente o formato de toda mensagem do
   `named-checkconf`. Isso apagava o erro inteiro, não só a parte
   sensível.

### Mudança

- Captura `stdout` + `stderr` + código de saída em todos os pontos de
  validação (`validate_staging()`, `apply_staging()`), com fallback
  em cascata (`stderr → stdout → "saiu com código N sem saída"`).
- Cada falha agora carrega um `diagnostics` estruturado (comando,
  código, stdout, stderr) que viaja separado do campo `error` livre —
  esse novo campo passa por uma sanitização própria no lado Laravel
  (`DnsBindRuntimeController::sanitizeDiagnostics()`) que **preserva
  caminhos de arquivo** (o dado que a gente precisa pra diagnosticar)
  mas continua removendo qualquer coisa com cara de token/senha, e
  aceita só as 4 chaves esperadas — nenhum campo livre novo passa sem
  filtro.
- Corrigido de brinde: as falhas dentro de `apply_staging()` (que
  disparam rollback automático) não marcavam `rolled_back: true`
  corretamente no registro da operação — agora marcam.
- Painel: o modal "Aplicar agora" e o card por servidor de "Publicar
  e sincronizar" agora mostram o comando e a saída de erro reais
  quando uma aplicação falha, sem precisar abrir o servidor.
- `AGENT_VERSION` bump pra `0.8.0`.

## Testes e gates

- Agente: 7 testes novos/reescritos em `tests/Agent/test_dns_center_agent.py`
  cobrindo fallback stdout/stderr, diagnósticos estruturados e
  `rolled_back` correto em `apply_staging()`/`validate_staging()`.
- `python3 -m unittest discover -s tests/Agent`: 109 testes — sem
  regressão (106 → 109).
- Novo teste de feature (`DnsAgentUpgradeTest`) confirma, via HTTP
  real, que o campo `diagnostics` preserva caminho de arquivo mas
  redige token, e que chaves fora da lista esperada são descartadas.
- `php artisan test`: 297 testes, 1485 assertions — sem regressão.
- Pint: 176 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Pendente de execução pelo operador. Depois do deploy, atualizar o
agente de cada servidor (botão "Atualizar agente") pra essa correção
valer nos apply futuros.
