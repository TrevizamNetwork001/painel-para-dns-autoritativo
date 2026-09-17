# DNS Center v1.3.29

Data: 2026-09-17

## Escopo

Hotfix pego ao vivo logo depois do primeiro uso real da Fase 3
(v1.3.28) em produção: a remoção do bloco legado em `dns-primary` e
`dns-secondary` funcionou de verdade (confirmado via
`DnsBindConfigConflicts` — zero conflitos restantes nos dois
servidores), mas o resultado da operação ficou salvo como um array
vazio, em vez de conter `removed`, `zone_name`, `source_file` etc.

### Causa raiz

`DnsBindRuntimeController::sanitizeResult()` mantinha uma lista
branca de chaves aceitas no `result` de qualquer operação, herdada
das ações mais antigas (`install_bind`, `apply_zones`, ...). A v1.3.28
adicionou a ação `remove_legacy_zone_block`, com um formato de
resultado totalmente novo (`removed`, `zone_name`, `source_file`,
`start_line`, `end_line`, `backup_dir`, `zonefile_preserved`) — mas eu
esqueci de estender essa lista branca. Toda chave do resultado real
era descartada silenciosamente, sobrando `[]`.

Mesmo bug de fundo do que a v1.3.24/v1.3.25 corrigiram pro campo
`error` (sanitização genérica apagando informação legítima), só que
desta vez batendo no `result` de uma ação nova.

### Mudança

- `sanitizeResult()` agora aceita as 7 chaves novas da ação
  `remove_legacy_zone_block`.
- `source_file`/`backup_dir` passam por um sanitizador que preserva
  caminho de arquivo (mesma lógica já usada em `sanitizeDiagnostics()`,
  agora extraída pra um método compartilhado `sanitizePreservingPath()`
  — evita repetir a mesma regra de redação de segredo em dois lugares).
- Valores inteiros (`start_line`, `end_line`) agora sobrevivem no
  `result` de qualquer ação — antes, qualquer valor não-booleano e
  não-string virava `null` incondicionalmente.

## Testes e gates

- Novo teste (`DnsBindLegacyZoneBlockTest`) reproduz o cenário exato
  via HTTP real: reporta uma remoção bem-sucedida com o payload
  completo mais uma chave desconhecida, confirma que os 7 campos
  esperados sobrevivem (incluindo os dois caminhos de arquivo e os
  dois inteiros) e que a chave desconhecida é descartada.
- `php artisan test`: 312 testes, 1575 assertions — sem regressão
  (311 → 312).
- Pint: 180 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.
- Sem mudança no agente — `AGENT_VERSION` continua 0.10.0.

## Deploy

Pendente de execução pelo operador. Sem ação nova exigida nos
agentes.
