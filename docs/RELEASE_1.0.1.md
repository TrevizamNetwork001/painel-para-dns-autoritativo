# DNS Center v1.0.1

Data: 2026-09-03

## Escopo

Patch de segurança, sem novas funcionalidades e sem alteração de schema.
Corrige duas dependências transitivas (trazidas pelo `laravel/framework`,
não usadas diretamente pelo código da aplicação) com vulnerabilidades
conhecidas publicadas:

- `league/commonmark` `2.8.3` → `2.10.0` (CVE-2026-71488, negação de
  serviço por Markdown malicioso; CVE-2026-71478, bypass de filtro de link
  inseguro).
- `guzzlehttp/guzzle` `7.15.1` → `7.15.2` (CVE-2026-69246, bypass de
  verificação por host não canônico; CVE-2026-69245, escopo de subdomínio
  em cookie não canônico).

Ambas dentro das faixas de versão já aceitas pelo `laravel/framework`
(`^2.8.1` e `^7.8.2`, respectivamente) — só atualização de
`composer.lock`, nenhuma linha de código da aplicação foi alterada.
`composer audit` confirma zero advisories restantes após a correção
(commit `b4150dc`).

## Avaliação de exploração real

Nenhum grep em `app/`, `config/` ou `resources/` encontrou uso direto de
`League\CommonMark` ou `GuzzleHttp`/`Illuminate\Support\Facades\Http` no
código da aplicação. O único caminho real de execução do CommonMark são
os templates de e-mail Markdown padrão do Laravel usados por
`ResetPasswordNotification` e `PasswordChangedNotification` — texto fixo
no código com, no máximo, um nome de usuário curto interpolado; não há
tela que aceite Markdown livre de um usuário. Avaliação: risco de
exploração real baixo para este produto especificamente, mas a correção é
gratuita (bump dentro da faixa já aceita) e foi aplicada de qualquer
forma, a pedido do operador.

## Testes e gates

Rodados pelo próprio `deploy/dns-center-deploy update` como parte do
pipeline formal: `preflight` (`php artisan about`, `security-check`),
`migrate:status`, health checks dos 6 serviços, `security-check`
pós-corte de tráfego — todos aprovados, ver seção "Deploy" abaixo.

## Deploy

Executado em produção em 2026-09-03, a partir do HEAD `95203cc` (tag
`v1.0.1`), via:

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.0.1
```

- imagens `dns-center-app:1.0.1`/`dns-center-web:1.0.1` construídas
  localmente (`compose.build.yaml`) e conferidas via `composer audit`
  ("No security vulnerability advisories found.") antes do corte de
  tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260903T115457Z.dump`, 772165 bytes, SHA-256
  `470550d403ac2f77652b68f3f96c1713af2fea5e6a9e65be555dc75c084f517c`;
- `migrate:status`/`migrate --force`: nenhuma migration nova, as 31
  já existentes seguem `Ran` — confirma que este patch não altera schema;
- `security-check` rodou antes (preflight) e depois do corte, ambas vezes
  apenas com o warning já conhecido de `TRUSTED_PROXIES` vazio;
- corte de tráfego com `compose up --wait` bem-sucedido: os 6 serviços
  (`app`, `queue`, `scheduler`, `web`, `postgres`, `redis`) saudáveis na
  imagem `:1.0.1`;
- `queue:restart` disparado com sucesso;
- estado registrado: `current-version=1.0.1`,
  `previous-version=1.0.0` — rollback disponível via
  `./deploy/dns-center-deploy rollback`, sem reversão de banco necessária
  (nenhuma migration nova).

Conferido de forma independente após o deploy: `https://dnscenter.trevizamnetwork.com.br/up`
respondeu HTTP 200; `composer audit` dentro do container `app-1` já em
produção confirmou "No security vulnerability advisories found."; imagem
ativa confirmada como `dns-center-app:1.0.1` via `docker inspect`.
