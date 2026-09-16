# DNS Center v1.3.19

Data: 2026-09-16

## Escopo

Redução de cliques no fluxo de publicação, pedida pelo operador: hoje
publicar uma zona e aplicá-la nos servidores são duas ações
manuais separadas, em telas diferentes — editar registro, salvar,
voltar pra aba de Publicação, clicar em "Publicar zona", depois
clicar em "Aplicar agora" em cada servidor um por um. Junto com isso,
foi corrigido um bug real: depois de publicar, o painel te jogava de
volta pra lista geral de domínios em vez de manter você na página da
zona.

### Mudanças

- **Botão "Publicar e sincronizar"**: novo, na aba Publicação de cada
  zona. Publica a versão salva e, na sequência, já cria a operação de
  aplicação (`apply_zones`) pra todos os servidores vinculados — uma
  única confirmação, com um modal mostrando o progresso por servidor
  ("Aguardando agente…" → "Aplicando…" → "Aplicado"). Servidores que
  já estavam com a última publicação aplicada são marcados como "já
  sincronizado" sem criar operação nova.
- Os botões antigos continuam disponíveis, renomeados: "Só publicar"
  (era "Publicar zona") pra quem quiser revisar entre publicar e
  aplicar, e "Aplicar agora" por servidor continua existindo pra
  reaplicar um servidor específico.
- **Fix de navegação**: `POST /zonas/{zone}/publicar` (o "Só
  publicar" de hoje) agora redireciona de volta pra
  `zones.show#publication`, igual o caminho de erro já fazia — antes
  ia pra lista geral de domínios, forçando reabrir a zona e navegar
  até a aba de Publicação de novo pra ver o resultado.
- Nova rota `POST /zonas/{zone}/publicar-e-sincronizar`
  (`zones.publish-and-sync`), JSON, reaproveitando a mesma lógica de
  publicação (`performPublish()`, extraído de `publish()`) e a mesma
  lógica de criação de operação `apply_zones` já usada pelo botão
  "Aplicar agora" individual (`DnsBindApplyController::store()`).

### Fora de escopo (por enquanto)

O operador perguntou se os botões antigos ("Só publicar" / "Aplicar
agora" por servidor) deveriam sumir da tela, deixando só o botão
combinado. Mantidos os dois lado a lado nesta versão — dá pra remover
depois, se o fluxo combinado se mostrar suficiente no uso real.

## Testes e gates

- Novos testes em `tests/Feature/DnsZoneWorkflowTest.php`:
  `test_publish_and_sync_publishes_and_creates_apply_operations_for_all_servers`,
  `test_publish_and_sync_skips_server_already_synchronized`,
  `test_publish_and_sync_returns_422_for_invalid_zone`,
  `test_publish_and_sync_requires_write_permission`.
- Regressão pega durante o desenvolvimento: mover o `abort_if` (zona
  importada do BIND, HTTP 409) pra dentro do método extraído
  `performPublish()` fazia esse erro ser engolido pelo `catch
  (Throwable)` genérico de `publish()`, virando um redirect 302 em vez
  de manter o 409 — `test_import_then_publish_returns_409_before_adoption`
  (`DnsBindDiscoveryTest`) pegou isso. Corrigido mantendo o `abort_if`
  em cada método público (`publish()` e `publishAndSync()`), fora do
  `try/catch`.
- `php artisan test`: 281 testes, 1413 assertions — sem regressão.
- Pint: 170 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Pendente de execução pelo operador.
