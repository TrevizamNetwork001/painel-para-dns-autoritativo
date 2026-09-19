# DNS Center v1.3.0

Data: 2026-09-08

## Escopo

Feature nova, sem alteração de schema: botão "Vincular PTR das
identidades de nameserver" em zonas reversas (`*.in-addr.arpa`).

Motivação real: ao importar a zona reversa `192.0.2.in-addr.arpa`
de cliente legado, o PTR de cada IP do bloco veio com o conteúdo
genérico do BIND (`ip-45-162-196-242.legacy.example.`), não com
o hostname real do nameserver — corrigir isso exigia abrir a zona e
editar registro por registro em meio a ~250 outras entradas.

Agora, na tela de uma zona reversa, um botão localiza — entre as
identidades de nameserver da organização — quais têm um IPv4 dentro da
rede daquela zona, e ajusta (ou cria, se faltar) o PTR correspondente
pro hostname real. Só os registros que batem são tocados; o resto da
zona fica intacto. A ação bumpa serial/versão da zona como qualquer
edição manual de registro, ficando pendente de publicação até alguém
publicar explicitamente.

Só IPv4 — PTR de IPv6 continua manual, por decisão explícita do
operador (não há matemática de nibble automática nesta versão).

Ver commit `3184d45` para o detalhamento técnico completo.

## Testes e gates

- 5 testes novos (`DnsZonePtrSyncTest`): caso real de cliente legado
  (só os PTR de ns1/ns2 mudam, resto intocado, zona bumpa), criação de
  PTR ausente, identidade fora da rede da zona (ignorada, sem bump),
  zona forward/papel sem permissão (404/403), idempotência (rodar duas
  vezes não duplica nem bumpa de novo).
- Suíte completa: 236/236.
- Pint: 164 arquivos aprovados.
- `route:list`: `zones.ptr-sync` (`POST zonas/{zone}/vincular-ptr`)
  confirmado.
- `git diff --check`: aprovado.

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `3184d45` (tag
`v1.3.0`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.0
```

- imagens `dns-center-app:1.3.0`/`dns-center-web:1.3.0` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") e `route:list` (`zones.ptr-sync`
  presente) antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260908T161028Z.dump`, 1382995 bytes, SHA-256
  `1d9acb25e651dee1b0cc7bc4a051ffbdb41ef8d214779b76cfc05e996ed4b77b`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.0`, `previous-version=1.2.2` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200;
imagem ativa confirmada como `dns-center-app:1.3.0`; rota
`POST zonas/{zone}/vincular-ptr` (`zones.ptr-sync`) confirmada dentro
do container `app-1` já em produção; os 6 serviços saudáveis.
