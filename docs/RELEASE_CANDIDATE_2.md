# DNS Center v1.0.0-rc2

Data do fechamento: 2026-08-29

## Decisão e escopo

Esta release candidate encerra a fase de implementação da 1.0 sob **feature
freeze**. Entraram apenas correções objetivas de gates, segurança, testes e
documentação. Não foram adicionados SMTP, Telegram, WAF interno, dashboards ou
automações novas.

O BIND legado de `ns1.legacy.example` permaneceu sob gerenciamento
externo/CLI. DISCOVER continua homologado; IMPORT não foi executado; ADOPT não
foi executado e não integra a homologação da 1.0. Não houve mudança de zonas,
TSIG, delegação pública ou topologia desse servidor.

Baseline inicial desta missão: `3c308c1b694c9c882270c45b977ef481ed6f2acb`.
Durante a verificação do agente, alterações concorrentes autorizadas foram
encerradas nos commits `6f65433` e `001ece5`; o fechamento prosseguiu somente
depois do worktree voltar a ficar limpo.

## Agente e onboarding reais

O plano original citava o salto histórico 0.6.0 para 0.7.0. No momento da
execução, contudo, esse estado já não existia: o artefato factual e os dois
agentes reais estavam em 0.7.4. Não foi feito downgrade nem repetido um upgrade
obsoleto apenas para reproduzir a numeração antiga.

- artefato público: 0.7.4;
- SHA-256 local, público e sidecar:
  `922d11c46a3ef20cd02937569a6365558b5bda16d7322417467ea8bf57f33719`;
- `ns1.legacy.example`: online, heartbeat em 0.7.4, UUID
  `d57d1528-44ae-5612-8342-c2df921ab2cb`, inventory, readiness e discovery
  posteriores ao upgrade disponíveis;
- operação 33: sucesso factual partindo de 0.7.1, com substituição de binário e
  confirmação posterior por heartbeat em 0.7.4;
- operação 35: reaplicação idempotente de 0.7.4, `changed=false`, sem troca do
  binário ou das units;
- BIND 9.20.26 permaneceu ativo. Nenhuma operação BIND foi autorizada durante
  o upgrade; não se alega comparação de hash/mtime anterior por ela não ter
  sido coletada antes da ação.

Em `ns1.customer.example`, o request
`b415e3eb-b3ed-475a-a8b1-4e9cca9a513e` nasceu pré-vinculado ao servidor 5 e à
organização correta. O agente reportou somente o hostname curto `ns1` e houve
warnings factuais de hostname/IP, mas o vínculo por código prevaleceu sem
matching ambíguo ou associação manual. A aprovação teve administrador com 2FA;
credencial, heartbeat 0.7.4, inventory e readiness estão presentes. Os demais
servidores `ns1` não apareceram como candidatos.

## Regressão DNS autoritativa

A homologação descartável integral da RC1, registrada em
[`RC_HOMOLOGATION_1.md`](RC_HOMOLOGATION_1.md), foi reutilizada porque não houve
evidência de regressão que justificasse tocar produção ou reabrir esse escopo.
Ela comprovou com dois BINDs reais:

- primary/secondary, SOA, NS, A, AAAA, CNAME, MX e TXT;
- validação, aplicação atômica, serial, UDP/TCP, AA e ausência de RA;
- AXFR inicial e fallback AXFR autenticados por TSIG, além de NOTIFY;
- continuidade autoritativa do secondary durante queda do primary;
- TSIG incorreta recusada, rotação, recuperação e rollback;
- rejeição de zonefile/include inválidos com preservação da versão ativa;
- backup, restore, upgrade, rollback de imagens e restart completo.

IXFR incremental continua **não comprovado** e não é alegado. PTR e todos os
casos internos suportados permanecem cobertos pela suíte automatizada; a
homologação DNS real da RC1 não registrou uma consulta PTR separada.

## Instalação, deploy e recuperação

Nesta execução, um projeto Compose isolado criou PostgreSQL e Redis novos e
executou as 31 migrations desde banco vazio. A suíte completa foi executada
contra `dns_center_testing`. As imagens production-only da aplicação e do web
foram reconstruídas localmente, sem push; a imagem da aplicação roda como
`www-data`, recebe uma versão explícita e contém assets compilados. O Compose de
produção mantém `.env` externo, não monta o código e define app, web, queue,
scheduler, health checks e volumes persistentes.

O bootstrap ponta a ponta, dois agentes/BINDs, publicação DNS real, backup,
restore e rollback foram comprovados no laboratório descartável da RC1. A RC2
revalidou os componentes alterados e os gates, sem repetir artificialmente a
homologação já aprovada.

## Segurança

`php artisan dns-center:security-check` confirmou produção, debug desativado,
HTTPS, cookies seguros, headers, CSP/HSTS aplicáveis, chave, rate limits,
obrigatoriedade de 2FA administrativo, logs e credenciais de banco fora dos
defaults conhecidos. O único warning foi `TRUSTED_PROXIES` vazio, esperado no
laboratório sem proxy confiável. X-Forwarded-For arbitrário não é confiado.

Tokens e segredos não foram encontrados no diff, nos arquivos rastreados ou
nesta documentação. O agente mantém credencial fora de argv/logs/auditoria,
arquivo 0600, fingerprint, HTTPS, rate limiting e validação SHA-256. Firewall,
VPN, WAF, backup offsite e cofre externos são responsabilidades operacionais do
ambiente, não bugs internos desta release.

## Auditoria de feature freeze

- BLOCKER 1.0 corrigido: respostas assíncronas `postJson` em rotas web podiam
  renderizar HTML em conflitos; a negociação JSON agora também respeita
  `expectsJson()`;
- BLOCKER 1.0 corrigido: script Composer apontava para um comando Artisan
  ausente; passou a executar o PHPUnit instalado;
- ajustes de homologação: views compiladas dos testes isoladas em `/tmp` e
  expectativas antigas atualizadas para o enrollment pré-vinculado e timer do
  agente;
- formatação Pint aplicada mecanicamente aos arquivos apontados pelo gate;
- POST-1.0: placeholder de comentários na tela de zona;
- FALSO POSITIVO: `welcome.blade.php` padrão não possui rota pública;
- nenhum dump, `.env`, credencial de laboratório, `__pycache__` ou `.pyc`
  versionado; nenhum endpoint de debug identificado.

## Gates do candidato

- Laravel/PHPUnit: 213 testes, 1175 assertions, zero failures/errors/deadlocks;
- Python agent: 96 testes; `py_compile` aprovado;
- Pint: 156 arquivos aprovados;
- PHP lint: aprovado em app, bootstrap, config, database, routes e tests;
- `view:cache`: aprovado; `route:list --except-vendor`: 83 rotas;
- frontend: build Vite aprovado dentro da imagem production-only web;
- shell: `bash -n` aprovado nos scripts shell rastreados;
- banco: 31 migrations em banco PostgreSQL vazio, zero pendentes;
- imagens production-only app/web: build aprovado; app non-root;
- `git diff --check`: aprovado;
- inspeção de segredos e artefatos acidentais: aprovada.

## Limitações e pós-1.0

Ficam explicitamente fora da 1.0: IMPORT-PILOT e ADOPT de BIND legado, SMTP,
Telegram, WAF integrado, automações adicionais, failover automático de
delegação, recursos visuais não críticos e qualquer funcionalidade nova que
não seja necessária ao DNS autoritativo. Também permanecem não comprovados
IXFR incremental, expiração completa do secondary e integração com controles
externos do ambiente.

## Promoção

A tag `v1.0.0-rc2` congela o candidato local. Não houve push nem publicação
externa. `v1.0.0-rc1` permanece imutável. A versão `v1.0.0` somente poderá ser
criada depois de uma homologação operacional curta desta RC2.
