# DNS Center v1.1.0

Data: 2026-09-03

## Escopo

Feature nova, sem alteração de schema: tela `/empresas`, restrita a
`is_platform_admin`, para criar uma organização (tenant) nova e o
primeiro usuário administrador dela (login/senha definidos na hora),
substituindo a necessidade de shell + `dns-center:create-admin` para
esse fluxo. Ver commit `fecd3db` ("feat: tela de onboarding de empresa
(tenant) pra platform admin") para o detalhamento técnico completo, e o
commit `1285e27` (melhoria de texto explicativo na tela `/nameservers`,
sem relação funcional com o onboarding — incluído nesta mesma versão
por conveniência).

Nenhuma tabela nova, nenhuma migration — `organizations` já tinha todas
as colunas necessárias. Nenhum Gate/Policy novo — segue o padrão
`abort_unless` já usado em todo o app.

## Testes e gates

- `OrganizationManagementTest`: 5 testes novos (criação com sucesso,
  bloqueio de usuário não-platform-admin, nome/slug duplicado, e-mail
  duplicado, login real do usuário criado + isolamento de tenant
  confirmado via 404 cross-tenant).
- Suíte completa: 221/222 — a única falha
  (`DnsAgentPrelinkedEnrollmentTest`, rate limit vazando entre testes
  dentro do mesmo processo PHPUnit) é pré-existente, confirmada também
  rodando a suíte na baseline sem estas mudanças (`git stash -u` antes
  de qualquer alteração). Não relacionada a esta versão; acompanhada à
  parte.
- Pint: 158 arquivos aprovados.
- `view:cache`: compilação aprovada.
- `route:list`: `empresas` registrado corretamente
  (`organizations.index`, `organizations.store`).
- `git diff --check`: aprovado.

## Homologação visual

Não foi possível verificar a tela renderizada num navegador real durante
o desenvolvimento (sem credencial de login no painel de produção). A
verificação nessa fase ficou limitada a: compilação Blade sem erro, e
conferência manual de que todas as classes CSS usadas (`.panel`,
`.form-field`, `.password-field`, `.data-table`, etc.) já existem em
`resources/css/app.css` com o formato de marcação esperado — um
desalinhamento (wrapper `<span>` extra numa célula de tabela) foi
encontrado e corrigido antes do deploy, comparando a marcação
diretamente com as regras CSS.

**Homologada em produção em 2026-09-03, pelo operador da plataforma**:
criada a organização real "Cliente Legado Telecom LTDA" (`id=2`,
slug `conecta-network-telecom-ltda`) com o primeiro usuário
`contato@onixnetwork.com.br` (papel `organization_admin`,
`is_platform_admin=false`, `must_change_password=true`, confirmado via
consulta direta em `organizations`/`users`/`organization_user`).
Login com a senha definida na tela funcionou. Fluxo completo (criar
empresa → criar usuário → logar como esse usuário) validado ponta a
ponta pela primeira vez, em uso real, não só por teste automatizado.

## Deploy

Executado em produção em 2026-09-03, a partir do HEAD `f6f1aca` (tag
`v1.1.0`), via:

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.1.0
```

- imagens `dns-center-app:1.1.0`/`dns-center-web:1.1.0` construídas
  localmente (`compose.build.yaml`) e conferidas via `composer audit`
  ("No security vulnerability advisories found.") e
  `route:list --except-vendor` (rotas `organizations.index`/
  `organizations.store` presentes) antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260903T133412Z.dump`, 777340 bytes, SHA-256
  `73cb0fbcd16bb8deb81a6c4f0184baffce76fa1bb067948e8ab5604a734bf0a9`;
- `migrate:status`/`migrate --force`: nenhuma migration nova, as 31 já
  existentes seguem `Ran` — confirma que esta versão não altera schema;
- `security-check` rodou antes (preflight) e depois do corte, ambas
  vezes apenas com o warning já conhecido de `TRUSTED_PROXIES` vazio;
- corte de tráfego com `compose up --wait` bem-sucedido: os 6 serviços
  (`app`, `queue`, `scheduler`, `web`, `postgres`, `redis`) saudáveis na
  imagem `:1.1.0`;
- `queue:restart` disparado com sucesso;
- estado registrado: `current-version=1.1.0`,
  `previous-version=1.0.1` — rollback disponível via
  `./deploy/dns-center-deploy rollback`, sem reversão de banco
  necessária (nenhuma migration nova).

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; a
rota `GET|HEAD empresas` apareceu em `route:list` executado dentro do
container `app-1` já em produção; imagem ativa confirmada como
`dns-center-app:1.1.0` via `docker inspect`.

A tela `/empresas` em si (visual, clique a clique) foi homologada em
produção logo em seguida — ver seção "Homologação visual" acima.
