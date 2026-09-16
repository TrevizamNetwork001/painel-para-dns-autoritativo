# DNS Center v1.3.13

Data: 2026-09-16

## Escopo

Continuação do feedback ao vivo do operador sobre a legibilidade do
assistente de DNS reverso (v1.3.9-v1.3.12), sem alteração de schema.

- **Lista geral "Domínios"** (`zones/index.blade.php`): ainda mostrava
  o nome `in-addr.arpa`/`ip6.arpa` por extenso pra zonas reversas —
  agora mostra "bloco X/NN", igual já feito na lista da aba "DNS
  reverso" na v1.3.11/1.3.12. Nome completo continua disponível no
  `title` do link (hover).
- **Título da zona reversa aberta**: o `<h1>` mostrava o nome arpa em
  vez do bloco — agora mostra o bloco como título principal, com o
  nome arpa completo como subtítulo logo abaixo (não apagado, só
  reposicionado).
- **Registros PTR na aba "Registros DNS"**: a linha secundária
  truncada com o nome reverso de 32 nibbles foi removida quando o IP é
  calculado com sucesso — fica só o IP (ex. `2001:db8::243`), nome
  completo disponível via `title` ao passar o mouse.

Nenhuma mudança de lógica de negócio ou cálculo — só como o que já
existia é exibido.

## Testes e gates

- `php artisan test`: 269 testes, 1375 assertions — sem regressão
  (mudança puramente de view).
- Pint: 169 arquivos aprovados.
- Build do frontend (`vite build`): sem erro.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `3a1afbb` (tag
`v1.3.13`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.13
```

- imagens `dns-center-app:1.3.13`/`dns-center-web:1.3.13` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T160512Z.dump`, 2338493 bytes, SHA-256
  `e7fb00af6781ec775f5d7cb59497aefdf9632ba7d5515f8275c5f9a7cf21a0cb`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.13`, `previous-version=1.3.12` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.13` e saudáveis.
