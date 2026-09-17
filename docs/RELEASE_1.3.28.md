# DNS Center v1.3.28

Data: 2026-09-17

## Escopo

Fase 3 (final) do plano de "nunca mais precisar de SSH": remoção de
um bloco de zona legado com um clique, direto do cartão "Conflito de
configuração" (v1.3.27).

### Nova operação autorizada: `remove_legacy_zone_block`

Mesmo modelo de confiança já usado por "Aplicar agora" — o agente só
age depois de um clique de confirmação explícito no painel, nunca
silenciosamente.

- **Painel → agente**: nova coluna `params` (jsonb) em
  `dns_bind_operations`, carregando `source_file`, `start_line`,
  `end_line`, `hash` e `zone_name` — a identidade exata do bloco a
  remover, validada e persistida no momento da autorização.
- **Defesa em profundidade**: antes de criar a operação, o controller
  confirma que o bloco submetido ainda corresponde ao último
  inventário de prontidão conhecido do servidor (`DnsBindConfigConflicts`)
  — uma requisição forjada ou desatualizada não consegue mirar um
  arquivo/linha arbitrário.
- **Reconfirmação por hash no agente**: antes de tocar em qualquer
  arquivo, o agente relê o arquivo de origem do zero, localiza o bloco
  pela posição informada e confere se o hash bate com o que foi
  detectado. Se a config mudou desde então (ou o bloco sumiu), recusa
  e pede nova verificação — nunca age em cima de informação velha.
- **Backup genérico novo**: não reaproveita `backup_current()`/`restore_backup()`
  (que são hardcoded pros arquivos gerenciados pelo DNS Center e
  apagariam um caminho não reconhecido como `named.conf.local` num
  rollback). Cada remoção cria seu próprio diretório de backup
  timestampado com uma cópia do arquivo original antes de qualquer
  edição.
- **Papel primary/secondary**: se o bloco declara `type master`, o
  agente também localiza e preserva uma cópia do arquivo de zona
  referenciado (`file "...";`) antes de remover a declaração — desde
  que seja um arquivo real, existente, sem symlink. Secondary (`type
  slave`) não tem nada real pra preservar (conteúdo populado só via
  AXFR) — só a declaração é removida.
- **Validação e rollback**: depois de escrever o arquivo sem o bloco,
  roda `named-checkconf` na cadeia completa; se falhar, restaura o
  arquivo original do backup e reporta o erro com o mesmo canal de
  diagnóstico estruturado da v1.3.24 (comando, código de saída,
  stdout, stderr — preservando caminho, removendo segredo).
- **Não encadeia com aplicar zona** — mesma filosofia já usada nesse
  projeto (publicar e aplicar são passos deliberados separados):
  depois de remover o bloco com sucesso, o operador usa "Aplicar
  agora" na zona como uma ação à parte.

### Painel

- Cartão "Conflito de configuração" (v1.3.27) ganha o botão "Remover
  declaração antiga" por zona, com modal de confirmação mostrando
  exatamente o arquivo, a linha e o trecho que será removido — mesmo
  padrão visual do modal de "Aplicar agora" (v1.3.19), reaproveitando
  o mesmo bloco de diagnóstico em caso de falha.

## Testes e gates

- Agente: 9 testes novos cobrindo remoção de zona secondary (sem
  preservar arquivo), primary (preserva arquivo de zona real),
  hash divergente recusado, bloco não encontrado recusado, rollback
  numa falha de `named-checkconf`, exigência de root, recusa de
  remover o próprio include gerenciado, e o dispatcher de
  `run_authorized_operation` (roteamento + parâmetros ausentes).
- `python3 -m unittest discover -s tests/Agent`: 124 testes — sem
  regressão (115 → 124).
- Laravel: novo `DnsBindLegacyZoneBlockTest` (7 testes) cobrindo
  autorização, papel de organização, bloco desatualizado recusado,
  zona não gerenciada recusada, operação já em andamento, e que os
  parâmetros chegam corretos no endpoint `/operations/next`.
- `php artisan test`: 311 testes, 1565 assertions — sem regressão
  (304 → 311).
- Pint: 180 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.
- `AGENT_VERSION` bump pra `0.10.0`.

## Fechamento do plano

Com esta versão, as quatro fases do plano de "detecção e resolução de
conflito de zona legada sem SSH" (aberto pelo incidente real do
cliente-exemplo, `customer.example`) estão completas:

- Fase 0 (v1.3.24 + hotfix v1.3.25): erro real de apply, sem vir vazio.
- Fase 1 (v1.3.26): detecção do bloco legado no agente.
- Fase 2 (v1.3.27): exibição no painel e bloqueio de publicação.
- Fase 3 (v1.3.28): remoção com um clique, sem SSH.

Fronteiras que continuam fora do escopo do v1 (documentadas desde o
plano original): remoção em lote, duas declarações duplicadas pro
mesmo nome, mexer em `include`/`options`/`acl`/chave, e apagar o
arquivo de zona legado em si (só a declaração) — esses casos ainda
avisam no painel mas recusam consertar sozinhos.

## Deploy

Pendente de execução pelo operador. A migração nova roda
automaticamente no deploy. Depois, "Atualizar agente" em cada
servidor pra essa capacidade valer.
