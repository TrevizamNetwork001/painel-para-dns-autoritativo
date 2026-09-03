# DNS Center v1.1.0

Data: 2026-09-03

## Escopo

Feature nova, sem alteração de schema: tela `/empresas`, restrita a
`is_platform_admin`, para criar uma organização (tenant) nova e o
primeiro usuário administrador dela (login/senha definidos na hora),
substituindo a necessidade de shell + `dns-center:create-admin` para
esse fluxo. Detalhes técnicos completos em
[`docs/RELEASE_CANDIDATE_2.md`](RELEASE_CANDIDATE_2.md)-style: ver
commit `fecd3db` ("feat: tela de onboarding de empresa (tenant) pra
platform admin") e o commit `1285e27` (melhoria de texto explicativo na
tela `/nameservers`, sem relação funcional com o onboarding — incluído
nesta mesma versão por conveniência).

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

Não foi possível verificar a tela renderizada num navegador real (sem
credencial de login no painel de produção). A verificação ficou
limitada a: compilação Blade sem erro, e conferência manual de que
todas as classes CSS usadas (`.panel`, `.form-field`, `.password-field`,
`.data-table`, etc.) já existem em `resources/css/app.css` com o
formato de marcação esperado — um desalinhamento (wrapper `<span>`
extra numa célula de tabela) foi encontrado e corrigido antes do
deploy, comparando a marcação diretamente com as regras CSS.
