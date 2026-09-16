# DNS Center v1.3.14

Data: 2026-09-16

## Escopo

Fechamento da rodada de feedback ao vivo sobre a legibilidade do
assistente de DNS reverso (v1.3.9-v1.3.13), sem alteração de schema.

### 1. Remove o prefixo "bloco" redundante

As listas (Domínios geral e aba "DNS reverso") mostravam "bloco
X/NN" — o prefixo ficava repetitivo já que o contexto (coluna
DOMÍNIO, ícone de reverso) já deixa claro que é um bloco. Agora
mostra só o valor (`2001:db8::/32`, `198.51.100.0/24`), igual o `<h1>`
da zona aberta já fazia desde a v1.3.13.

### 2. Aceita IP curto ao editar/criar registro PTR

O modal de editar um registro PTR mostrava o nome reverso de 32
nibbles no campo "Nome", exigindo o operador entender/digitar esse
formato pra fazer qualquer ajuste manual.

- O botão de editar agora preenche o campo "Nome" com o IP calculado
  (ex. `2001:db8::243`) em vez do nome reverso completo.
- Ao salvar (criar ou editar) um registro PTR numa zona reversa, se o
  campo "Nome" for um IP válido, o controller converte automaticamente
  pro nome reverso completo antes de gravar — **o registro salvo no
  banco/BIND continua exatamente no mesmo formato de sempre**, só a
  digitação fica mais fácil. Nomes já digitados no formato completo
  continuam funcionando sem mudança nenhuma (retrocompatível).

### Novo código

- `ReverseZoneNameCalculator::ipv4ToPtrName()`/`ipv6ToPtrName()`:
  inverso de `ptrNameToIpv4()`/`ptrNameToIpv6()` da v1.3.12 — vai de
  IP pro nome de registro PTR completo.
- `DnsZoneController::normalizeRecordName()`: usado por `storeRecord()`
  e `updateRecord()`. A validação de formato de nome
  (`validateRecord()`) passa a rodar sobre o nome já normalizado, não
  sobre o IP digitado — que não bateria com o regex de hostname (por
  causa dos dois-pontos do IPv6).

## Testes e gates

- `tests/Unit/ReverseZoneNameCalculatorTest.php`: 24 testes (6 novos)
  — IP→nome PTR pra IPv4 e IPv6, ida e volta (round-trip) confirmando
  que as duas direções são inversas exatas, retorno nulo pra IP
  inválido.
- `tests/Feature/DnsReverseZoneWizardTest.php`: 9 testes (2 novos) —
  criar um PTR com IP curto numa zona IPv4 e numa zona IPv6, conferindo
  que o nome gravado no banco é o formato completo esperado.
- `php artisan test`: 277 testes, 1387 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- Build do frontend (`vite build`): sem erro.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `ecf201c` (tag
`v1.3.14`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.14
```

- imagens `dns-center-app:1.3.14`/`dns-center-web:1.3.14` construídas
  localmente, suíte completa reconferida na imagem final (277 testes)
  e `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T162406Z.dump`, 2339940 bytes, SHA-256
  `c4b4b6ba9efb2bc2c909f2a440f68f6dda4f47a4faa0773071e9d58c1a79c2df`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.14`, `previous-version=1.3.13` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.14` e saudáveis.
