# DNS Center v1.3.11

Data: 2026-09-16

## Escopo

Melhoria de UX no assistente de DNS reverso, sem alteração de schema.
Pedido do operador logo após testar o assistente ao vivo (v1.3.9/
v1.3.10) na organização Cliente Legado: o nome `in-addr.arpa`/`ip6.arpa`
(ex.: `1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa`) é difícil de verificar de
cabeça — pediu pra mostrar o bloco CIDR "na forma que a gente
cadastra".

- `ReverseZoneNameCalculator::toIpv4Cidr()`/`toIpv6Prefix()`: inverso
  das funções `fromIpv4Cidr()`/`fromIpv6Prefix()` já existentes desde a
  v1.3.9. Funciona pra qualquer zona reversa, não só as criadas pelo
  assistente — inclusive as 5 zonas reais da Cliente Legado que já existiam
  antes desse assistente existir.
- O bloco calculado aparece no cabeçalho de uma zona reversa aberta
  (junto com registros/versão/serial) e em cada linha da lista da aba
  "DNS reverso".
- Confirmado com dado real: `2001:db8:1::/48` →
  `1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa` (zona de teste criada ao vivo
  pelo operador) e `2001:db8::/32` → `8.b.d.0.1.0.0.2.ip6.arpa`
  (zona real da Cliente Legado).

## Testes e gates

- `tests/Unit/ReverseZoneNameCalculatorTest.php`: 14 testes (5 novos)
  — inverso de IPv4 `/24`, inverso de IPv6 batendo com o dado real da
  Cliente Legado e com o bloco de teste do operador, retorno nulo pra nome
  que não é zona reversa.
- `php artisan test`: 265 testes, 1371 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- Build do frontend (`vite build`): sem erro.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `812a668` (tag
`v1.3.11`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.11
```

- imagens `dns-center-app:1.3.11`/`dns-center-web:1.3.11` construídas
  localmente e conferidas via `composer audit` antes do corte de
  tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T153800Z.dump`, 2336339 bytes, SHA-256
  `738221f82461c5aae40b2a9f86316f348c8d4a19781527fc5270348cc2897577`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.11`, `previous-version=1.3.10` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.11` e saudáveis.
