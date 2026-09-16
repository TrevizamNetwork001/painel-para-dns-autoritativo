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

Executado em produção em 2026-09-16, a partir do HEAD `e9f09d0` (tag
`v1.3.19`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.19
```

- imagens `dns-center-app:1.3.19`/`dns-center-web:1.3.19` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- durante o build, o disco do servidor ficou 100% cheio (acúmulo de
  imagens Docker de versões antigas) e a suíte de testes falhou em
  massa por "No space left on device" — não era regressão de código;
  limpas as imagens anteriores à 1.3.17 (`docker rmi` + `docker
  builder prune`), liberando ~7,6GB, e a suíte completa voltou a
  passar (281 testes, 1413 assertions);
- backup do PostgreSQL criado antes do corte de tráfego:
  `dns-center-20260916T193513Z.dump`, 2474654 bytes, SHA-256
  `0f9294c66ab2aa13810a3aae91e931818b724b2a7a327232911a684d8fe49c6b`;
- nenhuma migration nova nesta versão;
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.19`; disco em 57% de
uso (7,6GB livres).
