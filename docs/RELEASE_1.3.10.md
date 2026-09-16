# DNS Center v1.3.10

Data: 2026-09-16

## Escopo

Ajuste de CSS/UX no assistente de DNS reverso lançado na v1.3.9,
sem alteração de schema. Achado ao vivo pelo operador logo após o
deploy da v1.3.9: o modal "Criar zona reversa" reaproveitava
`.record-modal-main-grid`, cujo `grid-template-columns: 120px
minmax(0, 1fr)` foi desenhado pro formulário de registro DNS (rótulo
curto tipo "Tipo" ao lado do campo) — aplicado aos campos do
assistente isso espremia o select em 120px e quebrava o texto do
rótulo de forma feia.

- Nova classe `.reverse-modal-grid` (duas colunas iguais) substitui o
  grid emprestado.
- A lista de zonas reversas na aba "DNS reverso" ganhou ícone, família
  (IPv4/IPv6) e contagem de registros por zona, em vez de só o nome em
  texto solto — mesmo padrão visual das outras listas do painel.
- `withCount('records')` na query já carregada por `show()`, evitando
  N+1 pra montar a contagem por linha.

## Testes e gates

- `php artisan test`: 260 testes, 1366 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- Build do frontend (`vite build`): sem erro.
- `composer audit`: sem vulnerabilidades.
- Validado ao vivo em produção pelo operador antes deste release:
  mensagens de erro do assistente (bloco sem `/`, endereço que não é
  o de rede) renderizando corretamente.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `94113ac` (tag
`v1.3.10`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.10
```

- imagens `dns-center-app:1.3.10`/`dns-center-web:1.3.10` construídas
  localmente e conferidas via `composer audit` antes do corte de
  tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T152204Z.dump`, 2334602 bytes, SHA-256
  `c49f43c48eb6e8db54ac4a5acbc1a79305f8f1b56e48784e8171d4aeb60413e2`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.10`, `previous-version=1.3.9` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.10` e saudáveis.
