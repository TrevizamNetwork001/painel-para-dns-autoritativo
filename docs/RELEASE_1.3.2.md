# DNS Center v1.3.2

Data: 2026-09-08

## Escopo

Ajuste de UX, sem alteração de schema. Ao publicar uma zona com
sucesso, a tela agora volta pra lista de domínios (`/zonas`) em vez de
ficar presa na própria zona — pedido do operador enquanto trabalhava
zona por zona na importação da Cliente Legado, pra facilitar escolher
a próxima zona sem precisar navegar manualmente até a lista.

`adopt()` continua redirecionando pra mesma zona (ainda falta publicar
em seguida); `publish()` com falha também continua na zona, pra ver o
erro e tentar de novo. Só o caminho de sucesso de `publish()` mudou.

Ver commit `1f9a4d2`.

## Testes e gates

- `DnsZoneWorkflowTest`, `DnsZonePtrSyncTest` (14 testes) — sem
  regressão, nenhum teste dependia do redirect exato anterior.
- Pint: 164 arquivos aprovados.

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `1f9a4d2` (tag
`v1.3.2`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.2
```

- imagens `dns-center-app:1.3.2`/`dns-center-web:1.3.2` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260908T170319Z.dump`, 1414779 bytes, SHA-256
  `3275e8a049771bc3e29cb4b3ce0fa223df9a3521b0b572297d399221cd8e7500`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.2`, `previous-version=1.3.1` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200;
imagem ativa confirmada como `dns-center-app:1.3.2`; os 6 serviços
saudáveis.
