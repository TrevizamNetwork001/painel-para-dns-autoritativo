# DNS Center v1.3.27

Data: 2026-09-17

## Escopo

Fase 2 do plano de "nunca mais precisar de SSH": exibição no painel
dos blocos de zona legados que a Fase 1 (v1.3.26) já detecta no
agente.

### Novo

- `App\Services\DnsBindConfigConflicts`: cruza `bind_readiness.legacy_zone_blocks.blocks`
  (relatado pelo agente) com as zonas que o DNS Center realmente
  gerencia naquele servidor — um bloco cujo nome não bate com nenhuma
  zona gerenciada é ignorado (fora do escopo do v1, por design).
- `DnsZoneValidator`: nova regra de erro (bloqueia publicação), ao
  lado do aviso já existente de include não lido — nomeia servidor,
  arquivo e linha exatos do bloco conflitante.
- Painel: cartão "Conflito de configuração" na tela do servidor,
  listando cada zona conflitante com arquivo:linha, tipo declarado e
  o trecho da declaração antiga. Aparece só quando há conflito — sem
  ruído pros servidores sem problema.

### Fora do escopo desta versão

Remoção com um clique ("Remover declaração antiga") — ainda Fase 3,
pendente. Por enquanto o cartão só informa; a remoção continua manual
no servidor, mas agora sem precisar de SSH+journalctl pra descobrir
qual arquivo e qual linha mexer.

## Testes e gates

- `DnsBindConfigConflicts` coberto indiretamente pelos dois testes
  novos abaixo (validador e página do servidor), que exercitam o
  cruzamento de ponta a ponta, inclusive o caso de um bloco cujo nome
  não corresponde a nenhuma zona gerenciada (deve ser ignorado).
- Novo teste em `DnsZoneNameserverProfileTest` cobrindo o bloqueio de
  publicação.
- Novo teste em `DnsBindReadinessTest` cobrindo o cartão na tela do
  servidor, incluindo que um bloco de nome não gerenciado não aparece.
- `php artisan test`: 304 testes, 1543 assertions — sem regressão
  (302 → 304).
- Pint: 177 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.
- Sem mudança no agente nesta versão — `AGENT_VERSION` continua 0.9.0.

## Deploy

Pendente de execução pelo operador. Sem ação nova exigida nos
agentes — o campo `legacy_zone_blocks` já é reportado desde a v1.3.26.
