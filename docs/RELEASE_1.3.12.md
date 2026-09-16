# DNS Center v1.3.12

Data: 2026-09-16

## Escopo

Duas melhorias de legibilidade no assistente de DNS reverso, sem
alteração de schema — pedidas pelo operador ao vivo, olhando a tela
real da zona `8.b.d.0.1.0.0.2.ip6.arpa` da Cliente Legado.

1. **Lista de zonas reversas** (aba "DNS reverso"): mostra só "bloco
   X/NN · N registro(s)" como texto principal, sem repetir a família
   (IPv4/IPv6) nem o nome `in-addr.arpa`/`ip6.arpa` por extenso — esse
   nome continua acessível pelo `title` do link (hover) e por completo
   ao abrir a zona.
2. **Aba "Registros DNS" de uma zona reversa**: registros PTR passam a
   mostrar o endereço IP calculado (ex. `2001:db8::242`) como texto
   principal, em vez do nome reverso de 32 nibbles (ex.
   `2.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa`).
   O nome completo continua na linha secundária e no `title`. **O
   registro gravado no banco/BIND não muda em nada** — só a exibição;
   o campo de edição continua usando o nome original intacto.

### Bug corrigido de passagem

Não introduzido por este assistente, mas achado no caminho: o nome
secundário (linha pequena) de qualquer registro cujo `name` já fosse
um FQDN absoluto (caso de PTR/NS gerados por sincronizadores)
duplicava o sufixo da zona — ex.
`8.b.d.0.1.0.0.2.ip6.arpa.8.b.d.0.1.0.0.2.ip6.arpa` — porque o cálculo
antigo não verificava se o nome já terminava com o nome da zona antes
de concatenar. Agora usa a mesma checagem já usada em
`DnsZoneValidator::absoluteOwner()`/`DnsReversePtrSynchronizer`.

### Novo código

- `ReverseZoneNameCalculator::ptrNameToIpv4()`/`ptrNameToIpv6()`:
  inverso de `DnsReversePtrSynchronizer::recordNameFor()` — calcula o
  IP a partir do nome completo de um registro PTR.

## Testes e gates

- `tests/Unit/ReverseZoneNameCalculatorTest.php`: 18 testes (4 novos)
  — IPv4/IPv6 batendo com dado real (`2001:db8::242`, o IPv6 real do
  ns1 da Cliente Legado), retorno nulo pra nome parcial/incompleto.
- `php artisan test`: 269 testes, 1375 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `acf2866` (tag
`v1.3.12`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.12
```

- imagens `dns-center-app:1.3.12`/`dns-center-web:1.3.12` construídas
  localmente e conferidas via `composer audit` antes do corte de
  tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T155203Z.dump`, 2337351 bytes, SHA-256
  `a4ad2ef6ed47d861f99577468a2673b583c2e7b4587fe64c1716847f46751628`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.12`, `previous-version=1.3.11` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.12` e saudáveis.
