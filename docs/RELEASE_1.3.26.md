# DNS Center v1.3.26

Data: 2026-09-17

## Escopo

Fase 1 do plano de "nunca mais precisar de SSH": detecção automática
de blocos de zona legados (declarações estáticas antigas em
`named.conf.local`/`named.conf` que colidem com uma zona que o DNS
Center já gerencia) — a mesma classe de problema do incidente real do
cliente-exemplo (`customer.example`, depois `0.2.0.192.in-addr.arpa`),
agora detectada sem precisar de SSH+`journalctl` pra descobrir.

Isto é só **detecção**. A remoção com um clique (Fase 3) e o cartão
dedicado no painel (Fase 2) ainda não existem — o que esta versão já
entrega:

### Agente (0.9.0)

- `iter_config_chain()`: generaliza o percurso de includes que já
  existia (hardcoded dentro de `include_wired_report()`), agora
  reutilizável, e corrige de passagem um bug real — só resolvia
  `include` com caminho absoluto; caminho relativo (que named também
  aceita, resolvido contra o diretório do arquivo que faz o include)
  era ignorado silenciosamente.
- `find_zone_blocks()`: localiza blocos `zone "nome" { ... };` por
  contagem de chaves (não regex gulosa), então um bloco aninhado
  dentro da zona (`allow-transfer { ... };`) não corta o match antes
  da hora.
- `legacy_zone_blocks_report()`: percorre a cadeia de includes de
  verdade (não o `named-checkconf -p`, que normaliza e perde a origem
  de cada bloco), pula o include gerenciado do DNS Center, e reporta
  cada bloco encontrado fora dele — nome, arquivo de origem, linha
  inicial/final, tipo declarado, hash do texto exato, trecho limitado.
- Emitido dentro do `readiness_report()` de sempre (aviso preventivo,
  ~5 min de atraso, novo campo `legacy_zone_blocks` no payload).
- **Checado de novo no início do próprio `apply_zones`**, depois da
  confirmação e antes de tocar em qualquer arquivo: se algum bloco
  legado colide por nome com uma zona que este apply está prestes a
  escrever, o apply é bloqueado com uma `AgentOperationError` nomeando
  arquivo e linha exatos — reaproveita o mesmo canal de `diagnostics`
  estruturado da v1.3.24/v1.3.25, então já aparece no modal "Aplicar
  agora"/"Publicar e sincronizar" sem mudança nenhuma de painel.

### Laravel

- `DnsBindRuntimeController::readiness()`: aceita e valida o novo
  campo `legacy_zone_blocks` (lista limitada a 50 blocos, cada um com
  tipos e tamanhos checados; hash precisa ser um SHA-256 hex válido).
  Guardado em `bind_readiness` como o resto do payload de prontidão —
  ainda sem exibição própria no painel (isso é Fase 2).

## Fora do escopo desta versão

Cartão "Conflito de configuração" na tela do servidor, cruzamento
formal com `DnsZoneValidator`, e a operação autorizada
`remove_legacy_zone_block` pra resolver com um clique — ainda Fases 2
e 3 do plano, pendentes.

## Testes e gates

- 5 testes novos no agente (`iter_config_chain` com include relativo,
  `find_zone_blocks` com chaves aninhadas, `legacy_zone_blocks_report`
  contra um cenário de conflito real, `legacy_zone_conflicts` filtrando
  só zonas gerenciadas, e `sync_zones` bloqueando o apply com
  diagnóstico correto antes de chamar `apply_staging`).
- `python3 -m unittest discover -s tests/Agent`: 115 testes — sem
  regressão (110 → 115).
- Novo teste de feature (`DnsBindReadinessTest`) cobre o payload novo
  sendo aceito e persistido, e rejeitando bloco malformado.
- `php artisan test`: 302 testes, 1536 assertions — sem regressão.
- Pint: 176 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Pendente de execução pelo operador. Depois do deploy, atualizar o
agente de cada servidor pra essa detecção valer.
