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
pipeline formal (ver seção "Deploy" abaixo, preenchida após a execução):
`preflight` (`php artisan about`, `security-check`), `migrate:status`
(nenhuma migration nova esperada), health checks dos 6 serviços,
`security-check` pós-corte de tráfego.
