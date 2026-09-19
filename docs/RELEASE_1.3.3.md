# DNS Center v1.3.3

Data: 2026-09-08

## Escopo

Nova checagem de prontidão que detecta, antes da publicação, o
problema que causou o incidente da importação de cliente legado: um
BIND que existia antes do DNS Center pode ter um `named.conf.local`
com declarações antigas de zona e nenhum `include
"/etc/bind/dns-center-managed.conf";` em lugar nenhum da cadeia de
includes — nesse caso toda publicação fica presa em
"downloaded"/"applying" pra sempre, porque o BIND nunca lê o conteúdo
gerenciado pelo painel.

Antes disso só era descoberto na prática, depois de adoção, TSIG, PTR
e SOA já resolvidos (~40 minutos de diagnóstico manual via SSH nos
dois servidores de cliente legado).

### Agente (`agent/dns-center-agent.py`, v0.7.5)

- Nova função `include_wired_report(config)`: lê `named.conf` e segue
  recursivamente (limite de profundidade 5, com proteção contra ciclo)
  qualquer `include "...";` referenciado, procurando a linha exata que
  o DNS Center espera (`include "<managed_include>";`) em qualquer
  nível da cadeia — não só no arquivo topo, como a checagem existente
  em `install_bind` já fazia.
- `readiness_report()` agora recebe `config` e inclui o fato
  `include_wired: {expected_include, statement_found}` no relatório de
  prontidão que o agente já envia ao painel.

### Painel

- `DnsBindRuntimeController::readiness()`: allowlist de validação
  passa a aceitar `include_wired.expected_include` e
  `include_wired.statement_found`, persistidos em
  `dns_servers.bind_readiness` como qualquer outro fato.
- `DnsZoneValidator::validate()`: se um servidor primary/secondary da
  zona reportou `include_wired.statement_found = false`, entra como
  erro em "Correções necessárias" (mesmo bloco que já existe hoje pra
  TSIG faltando): "O servidor {nome} não está lendo o include
  gerenciado do DNS Center — esta zona continuará presa depois de
  publicar."

### Rollout

O fato só aparece depois que o agente reportar prontidão na versão
0.7.5+. Servidores já cadastrados (ns1/ns2 de cliente legado, e qualquer
outro) recebem a atualização pelo botão "Atualizar agente" já
existente no painel — não depende de SSH manual.

## Testes e gates

- `tests/Agent/test_dns_center_agent.py`: 86 testes, incluindo 5 novos
  cobrindo `include_wired_report` (statement direto, includes
  aninhados, statement ausente — espelhando o incidente real,
  `named.conf` inexistente) e a integração em `readiness_report`.
  `python3 -m unittest tests.Agent.test_dns_center_agent` → OK.
- `php artisan test`: 237 testes, 1309 assertions — sem regressão.
  Novo teste `DnsZoneNameserverProfileTest::test_validator_flags_server_not_reading_managed_include`.
- Pint: 166 arquivos aprovados.
- `route:list`: sem mudança de rotas (só validação de payload e lógica
  de validator).

Ver commits a partir de `17b220f`.

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `1ea4ddd` (tag
`v1.3.3`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.3
```

- imagens `dns-center-app:1.3.3`/`dns-center-web:1.3.3` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260908T175205Z.dump`, 1442595 bytes, SHA-256
  `0fad3d0d2bdf73685b21fee1482bd55edab75a09581aa3aff8cd7cc7b2146428`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.3`, `previous-version=1.3.2` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200;
os 4 serviços da aplicação (`app`, `web`, `queue`, `scheduler`)
confirmados na imagem `1.3.3` e saudáveis.

Pendente (operacional, não faz parte deste deploy): atualizar o
agente instalado em ns1/ns2 de cliente legado (e outros servidores) da
versão 0.7.4 pra 0.7.5, pelo botão "Atualizar agente" no painel — sem
isso, `include_wired` não aparece na prontidão desses servidores.
