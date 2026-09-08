# DNS Center v1.3.2

Data: 2026-09-08

## Escopo

Ajuste de UX, sem alteração de schema. Ao publicar uma zona com
sucesso, a tela agora volta pra lista de domínios (`/zonas`) em vez de
ficar presa na própria zona — pedido do operador enquanto trabalhava
zona por zona na importação da Conecta Network, pra facilitar escolher
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

_(seção a completar após a execução)_
