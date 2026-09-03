# DNS Center

Plataforma centralizada para gerenciamento de DNS autoritativo com BIND9.
O DNS Center mantém o estado desejado das zonas, coordena agentes remotos e
acompanha a aplicação e a operação dos servidores primary e secondary.

O BIND é usado exclusivamente como servidor autoritativo na arquitetura do
projeto. Recursão não é uma função oferecida pelo DNS Center e deve permanecer
desabilitada nos servidores gerenciados.

## Estado atual

O projeto já possui:

- organizações com isolamento multi-tenant;
- usuários, papéis e permissões (RBAC);
- segundo fator administrativo com passkeys ou TOTP e ciclo de senhas;
- cadastro e acompanhamento de servidores DNS;
- enrollment, autenticação e revogação de agentes remotos;
- gerenciamento de zonas e registros DNS;
- descoberta somente leitura e importação de BIND existente, com adoção
  ainda fora do escopo homologado;
- identidades e perfis ordenados de nameservers;
- topologia primary/secondary;
- chaves TSIG cifradas, com associação, rotação e desativação controladas;
- transferências AXFR e NOTIFY autenticados por TSIG;
- publicação explícita e versionada, com artefatos imutáveis;
- confirmação da versão aplicada e do serial SOA carregado pelo BIND;
- observabilidade de serial, transferências, disponibilidade e expiração;
- auditoria de eventos operacionais e de segurança;
- readiness do BIND, ferramentas, serviço, listeners e permissões;
- deploy com imagens versionadas, backup antes de migrations, health checks e
  rollback operacional da aplicação.

O BIND está configurado para solicitar IXFR quando possível, mas a homologação
registrada comprovou uma transferência inicial por AXFR e fallback para AXFR
na atualização. IXFR incremental não é apresentado como evidência concluída.

## Arquitetura

```text
Administrador
    |
DNS Center
    |
    +-- Agent -> BIND Primary
    |
    +-- Agent -> BIND Secondary
                     ^
                     |
              AXFR/NOTIFY + TSIG
```

O painel mantém o estado desejado e autoriza operações enumeradas. Cada agente
executa localmente as ações controladas, valida configurações e reporta o estado
observado. O primary recebe e aplica a publicação do zonefile; em seguida, o
secondary obtém e mantém sua cópia por transferência DNS autenticada. O painel
não distribui o zonefile diretamente ao secondary.

## Fluxo de publicação

```text
Salvar
  -> validar
  -> alterações pendentes
  -> publicar explicitamente
  -> agente primary
  -> validação BIND
  -> aplicação
  -> NOTIFY
  -> transferência para secondary
  -> confirmação de serial
  -> observabilidade
```

Salvar uma zona não a publica. A publicação gera uma versão e um snapshot
imutável para o primary. O agente baixa, valida e aplica o artefato somente sob
as proteções locais previstas; após a transferência, os agentes reportam o
serial realmente servido para que o painel acompanhe a convergência.

## Segurança

- isolamento de dados e recursos por organização;
- RBAC e proteção reforçada das operações administrativas;
- 2FA administrativo por passkey ou TOTP;
- proteção CSRF nas rotas web e rate limiting no login e nas APIs;
- CSP, HSTS em HTTPS/produção e demais headers de segurança;
- credencial individual, revogável e armazenada localmente por agente;
- segredos TSIG cifrados em repouso e entregues apenas aos agentes envolvidos;
- auditoria sanitizada, sem payloads ou credenciais sensíveis;
- configuração e segredos externos às imagens de produção;
- execução não-root das imagens da aplicação e dos processos PHP, exceto pela
  inicialização restrita necessária para preparar permissões dos volumes.

O painel não envia comandos shell livres aos agentes. Operações do sistema usam
um catálogo local allowlisted, validação prévia, escrita atômica, backup e
rollback em caso de falha.

## Deploy

A operação formal usa imagens versionadas e imutáveis, sem bind mount do código.
A topologia de produção é composta por:

- imagem PHP com aplicação e dependências;
- imagem Nginx com os assets web;
- PostgreSQL e Redis;
- workers de fila e scheduler;
- arquivo `.env` externo às imagens;
- volumes persistentes para dados e logs.

O procedimento de atualização valida configuração e imagens, cria e verifica
um backup PostgreSQL antes de qualquer migration, executa migrations de forma
explícita, aguarda os health checks e registra as versões atual e anterior. O
rollback manual troca as imagens da aplicação pela versão anterior; ele não
reverte migrations nem restaura automaticamente o banco.

O processo completo, seus pré-requisitos e limites estão em
[DEPLOY-BASELINE-1](docs/DEPLOY_BASELINE_1.md).

## Agente

O agente Python é instalado em cada servidor autoritativo, fora dos containers
do painel. O instalador verifica os checksums do agente e das units systemd,
exige HTTPS fora de localhost e inicia um enrollment que precisa ser aprovado
por um administrador com 2FA.

Depois do enrollment, a credencial individual do agente autentica:

- heartbeat e inventário;
- readiness do ambiente BIND;
- consulta e aplicação controlada de publicações;
- confirmação de versão e serial;
- observações autoritativas e de transferência.

O painel não usa SSH operacional. Consulte a [API do agente](docs/AGENT_API.md),
a [operação segura do agente BIND](docs/BIND_AGENT_OPERATIONS.md) e a
[instalação e prontidão do BIND](docs/BIND_READINESS_INSTALLATION.md).

## Desenvolvimento e testes

### Ambiente de desenvolvimento

O [`compose.yaml`](compose.yaml) constrói os serviços de desenvolvimento e
monta o workspace nos containers:

```bash
docker compose up -d --build
```

O projeto também define os scripts Composer `setup`, `dev` e `test` e os
scripts npm `dev` e `build`. A execução direta desses scripts requer PHP,
Composer e Node.js disponíveis no ambiente local.

### Banco de testes

Os testes Laravel usam exclusivamente PostgreSQL com o banco
`dns_center_testing`, forçado em [`phpunit.xml`](phpunit.xml). Antes da primeira
execução no Compose, crie e confira esse banco:

```bash
./scripts/create-testing-database.sh
./scripts/check-testing-database.sh
```

Nunca execute testes destrutivos contra o banco operacional `dns_center`.
Nunca use `migrate:fresh`, `db:wipe` ou comandos equivalentes no banco
operacional.

### Testes Laravel

Com o ambiente de desenvolvimento ativo:

```bash
./scripts/check-testing-database.sh
docker compose exec -T app composer test
```

### Testes Python do agente

```bash
python3 -m unittest discover -s tests/Agent -p 'test_*.py'
python3 -m py_compile agent/dns-center-agent.py
```

### Build frontend

```bash
npm run build
```

### Validações adicionais

```bash
docker compose exec -T app php artisan dns-center:security-check
git diff --check
```

## Homologação

As homologações descartáveis documentadas usaram BIND9 real em Debian 13 e
comprovaram:

- aplicação em primary e topologia primary/secondary;
- AXFR autenticado por TSIG e NOTIFY;
- consultas autoritativas UDP e TCP com flag `AA`;
- recursão desabilitada e consultas recursivas externas recusadas;
- continuidade do secondary durante indisponibilidade do primary, dentro da
  validade SOA;
- rotação e rollback de TSIG;
- bloqueio e rollback de configuração ou aplicação inválida;
- observabilidade de seriais, transferências e indisponibilidade.

Os ambientes foram isolados, sem delegação pública, dados de clientes ou deploy
em produção. As evidências e limitações estão em
[Homologação real do agente e do BIND](docs/BIND_REAL_HOMOLOGATION.md),
[DNS autoritativo primary/secondary](docs/BIND_PRIMARY_SECONDARY.md) e
[OBSERVABILITY-1](docs/OBSERVABILITY_1.md).

## Stack tecnológica

- Laravel 13 e PHP 8.3 ou superior;
- PostgreSQL e Redis;
- Nginx e Docker Compose;
- Vite e Tailwind CSS;
- Python no agente remoto;
- BIND9 como servidor DNS autoritativo.

## Documentação

| Documento | Conteúdo |
| --- | --- |
| [API do agente](docs/AGENT_API.md) | Enrollment, autenticação e contrato operacional do agente |
| [Operação segura do agente](docs/BIND_AGENT_OPERATIONS.md) | Download, validação, aplicação e rollback local |
| [Núcleo autoritativo](docs/BIND_AUTHORITATIVE.md) | Modelo de zonas, registros, versões e publicações |
| [Primary/secondary](docs/BIND_PRIMARY_SECONDARY.md) | Topologia, TSIG, AXFR/NOTIFY e continuidade |
| [Readiness e instalação](docs/BIND_READINESS_INSTALLATION.md) | Detecção e operações allowlisted do BIND |
| [Homologação real](docs/BIND_REAL_HOMOLOGATION.md) | Evidências do agente e BIND em ambiente descartável |
| [Descoberta e adoção de BIND](docs/BIND_ADOPTION_1.md) | Discover/Import somente leitura de BIND existente; Adopt fora de escopo |
| [Observabilidade](docs/OBSERVABILITY_1.md) | Seriais, transferências, hardening e alertas internos |
| [Baseline de segurança](docs/SECURITY_BASELINE_1.md) | Controles da superfície administrativa |
| [Baseline de deploy](docs/DEPLOY_BASELINE_1.md) | Build, instalação, atualização, backup e rollback |
| [Reconciliação operacional](docs/OPS_RECONCILE_1.md) | Evidências do ambiente ativo reconciliado |
| [Homologação da RC1](docs/RC_HOMOLOGATION_1.md) | Homologação descartável completa, aprovada com ressalvas |
| [Release candidate 2](docs/RELEASE_CANDIDATE_2.md) | Escopo congelado, gates, homologações e limitações da RC2 |
| [Release candidate 3](docs/RELEASE_CANDIDATE_3.md) | Correção de idempotência do DISCOVER encontrada no gate da RC2 |
| [Homologação da RC2](docs/RC_HOMOLOGATION_2.md) | Homologação operacional curta do fix de idempotência, ao vivo |
| [Release 1.0.0](docs/RELEASE_1.0.0.md) | Promoção da v1.0.0 a partir da RC3 e correção da tag irregular |
| [Histórico da interface](docs/CHANGELOG_UI.md) | Ajustes realizados na interface web |

## Status do projeto

**v1.0.1 em produção desde 2026-09-03** (patch de segurança sobre a
v1.0.0, promovida em 2026-08-31 a partir da v1.0.0-rc3).

As funcionalidades centrais e os fluxos BIND foram implementados e possuem
testes automatizados e homologações descartáveis documentadas. A homologação
operacional curta exigida pela RC2 foi concluída e confirmada ao vivo,
registrada em [Homologação da RC2](docs/RC_HOMOLOGATION_2.md). O deploy
formal da v1.0.0 com imagem imutável foi executado em 2026-08-31
(ver [Release 1.0.0](docs/RELEASE_1.0.0.md)). Em 2026-09-03, um patch de
segurança corrigiu duas dependências transitivas com CVEs conhecidos
(`league/commonmark`, `guzzlehttp/guzzle`) e foi promovido a produção como
v1.0.1 (ver [Release 1.0.1](docs/RELEASE_1.0.1.md)); `composer audit`
confirma zero advisories restantes.
