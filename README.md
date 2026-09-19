# DNS Center

Plataforma centralizada para gerenciamento de DNS autoritativo com BIND9.
O DNS Center mantém o estado desejado das zonas, coordena agentes remotos e
acompanha a aplicação e a operação dos servidores primary e secondary.

O BIND é usado exclusivamente como servidor autoritativo na arquitetura do
projeto. Recursão não é uma função oferecida pelo DNS Center e deve permanecer
desabilitada nos servidores gerenciados.

## Estado atual

A avaliação atual do produto e do agente está em
[Revisão do produto](docs/REVISAO_PRODUTO_2026_09_18.md) e
[Revisão do agente](docs/REVISAO_AGENT_2026_09_18.md).
O código do agente neste checkout declara a versão 0.10.2; a instalação
nos servidores requer rollout separado. As avaliações e releases anteriores
permanecem no histórico em `docs/`.

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

### Integração contínua

O workflow [CI](.github/workflows/ci.yml) executa Pint, build frontend e as
suítes Laravel e Python em pushes e pull requests no GitHub. O ambiente
[compose.ci.yaml](compose.ci.yaml) usa PostgreSQL temporário, sem portas
publicadas, com o banco exclusivo `dns_center_testing`. Ele não usa o Compose
de produção. O build frontend deve preceder os testes Laravel, pois as views
precisam do manifest Vite.

Para reproduzir em um checkout de desenvolvimento dedicado, sem `.env` de
produção e sem caches de configuração de produção:

```bash
docker compose --env-file /dev/null -f compose.ci.yaml build app
docker compose --env-file /dev/null -f compose.ci.yaml run --rm app composer install --no-interaction --prefer-dist
docker compose --env-file /dev/null -f compose.ci.yaml run --rm app vendor/bin/pint --test
docker compose --env-file /dev/null -f compose.ci.yaml run --rm assets
docker compose --env-file /dev/null -f compose.ci.yaml run --rm app php vendor/bin/phpunit
python3 -m unittest discover -s tests/Agent
docker compose --env-file /dev/null -f compose.ci.yaml down --volumes --remove-orphans
```

As credenciais presentes nesse Compose são exclusivas do banco temporário de
CI. O workflow precisa ser enviado a um repositório GitHub com Actions
habilitado para executar automaticamente.

### Tempos da fila

Os workers dos Composes de desenvolvimento e produção usam `--timeout=120`.
O `retry_after` padrão de database, Redis e Beanstalkd é 180 segundos, para
que uma tarefa não volte à fila enquanto seu worker ainda pode executá-la.
Ao atualizar instalações existentes, confira os overrides
`DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER` e
`BEANSTALKD_QUEUE_RETRY_AFTER` no ambiente externo: devem ser maiores que o
timeout do worker. Recrie os workers e atualize o cache de configuração pelo
procedimento de deploy. Para SQS, confira separadamente o visibility timeout
na configuração da fila.

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
| [Dashboard Operacional final](docs/dashboard-final-2026-09-17.md) | Auditoria do mapa, composição, temas, testes e publicação |
| [Release 1.3.3](docs/RELEASE_1.3.3.md) | Checagem de prontidão detecta BIND não vinculado ao include gerenciado antes de publicar |
| [Release 1.3.4](docs/RELEASE_1.3.4.md) | Botão "Aplicar agora" aplica zonas pendentes no BIND sem SSH manual |
| [Release 1.3.5](docs/RELEASE_1.3.5.md) | Trava de concorrência na autorização de aplicação, CI e ajuste de fila |
| [Release 1.3.6](docs/RELEASE_1.3.6.md) | Agente 0.7.7 com correções de robustez operacional |
| [Release 1.3.7](docs/RELEASE_1.3.7.md) | Fix de CSS na aba Nameservers e de teste que só passava como root |
| [Release 1.3.8](docs/RELEASE_1.3.8.md) | Continuação do fix de fonte da aba Nameservers (Identidades e Perfis) |
| [Release 1.3.9](docs/RELEASE_1.3.9.md) | Assistente de DNS reverso IPv4/IPv6 |
| [Release 1.3.10](docs/RELEASE_1.3.10.md) | Corrige layout do modal de criar zona reversa |
| [Release 1.3.11](docs/RELEASE_1.3.11.md) | Mostra o bloco CIDR das zonas reversas na forma cadastrada |
| [Release 1.3.12](docs/RELEASE_1.3.12.md) | Registros PTR mostram o IP calculado em vez do nome reverso de 32 nibbles |
| [Release 1.3.13](docs/RELEASE_1.3.13.md) | Bloco CIDR na lista geral de domínios, no título da zona e PTR sem nome truncado |
| [Release 1.3.14](docs/RELEASE_1.3.14.md) | Aceita IP curto ao editar/criar registro PTR, remove prefixo "bloco" |
| [Release 1.3.15](docs/RELEASE_1.3.15.md) | Agente 0.7.8 habilita DNS_CENTER_AGENT_ALLOW_APPLY por padrão |
| [Release 1.3.16](docs/RELEASE_1.3.16.md) | Agente 0.7.9 atualiza a observação autoritativa logo após aplicar zonas |
| [Release 1.3.17](docs/RELEASE_1.3.17.md) | Modal de atualização do agente fecha sozinho e volta pra dashboard ao concluir |
| [Release 1.3.18](docs/RELEASE_1.3.18.md) | TTL padrão de zonas novas reduzido de 3600s para 300s |
| [Release 1.3.19](docs/RELEASE_1.3.19.md) | Botão "Publicar e sincronizar" reduz publicação+aplicação a 1 clique |
| [Release 1.3.20](docs/RELEASE_1.3.20.md) | Histórico de versões mostra qual registro mudou, não só "Registro atualizado." |
| [Release 1.3.21](docs/RELEASE_1.3.21.md) | Tela de Auditoria global por organização, com filtros e exportação CSV |
| [Release 1.3.22](docs/RELEASE_1.3.22.md) | Template de nome pro PTR gerado a partir de registros A |
| [Release 1.3.23](docs/RELEASE_1.3.23.md) | Comandos do agente funcionam sem sudo instalado |
| [Release 1.3.24](docs/RELEASE_1.3.24.md) | Agente 0.8.0 reporta o erro real de named-checkconf/checkzone/rndc, sem mais vir vazio |
| [Release 1.3.25](docs/RELEASE_1.3.25.md) | Hotfix: sync_zones não descarta mais diagnostics/rolled_back de uma falha de apply |
| [Release 1.3.26](docs/RELEASE_1.3.26.md) | Agente 0.9.0 detecta blocos de zona legados fora do include gerenciado (Fase 1) |
| [Release 1.3.27](docs/RELEASE_1.3.27.md) | Cartão "Conflito de configuração" no painel e bloqueio de publicação (Fase 2) |
| [Release 1.3.28](docs/RELEASE_1.3.28.md) | Agente 0.10.0 remove bloco de zona legado com 1 clique, sem SSH (Fase 3 — fecha o plano) |
| [Release 1.3.29](docs/RELEASE_1.3.29.md) | Hotfix: sanitizeResult() não descarta mais o resultado de remove_legacy_zone_block |
| [Release 1.3.30](docs/RELEASE_1.3.30.md) | Página "Reversos": zonas reversas separadas de Domínios, com bloco CIDR |
| [Release 1.3.31](docs/RELEASE_1.3.31.md) | Desativar e excluir empresa (cancelamento de cliente), com cascata e confirmação forte |
| [Release 1.3.32](docs/RELEASE_1.3.32.md) | "Remover declarações antigas e publicar" num só passo |
| [Release 1.3.33](docs/RELEASE_1.3.33.md) | Descoberta: comparação entre servidores, zonas ignoradas, modal com CIDR e barra de progresso |
| [Release 1.3.34](docs/RELEASE_1.3.34.md) | Comparação só entre servidores relacionados |
| [Release 1.3.35](docs/RELEASE_1.3.35.md) | Descoberta com mais de 24h destacada como desatualizada |
| [Release 1.3.36](docs/RELEASE_1.3.36.md) | Modal de remoção espera o relatório de prontidão antes de recarregar |
| [Release 1.3.37](docs/RELEASE_1.3.37.md) | Sincronizador de nameservers não duplica mais os NS do apex |
| [Release 1.3.38](docs/RELEASE_1.3.38.md) | Botão combinado remover+publicar espera o relatório de prontidão |
| [Release 1.3.39](docs/RELEASE_1.3.39.md) | Agente 0.10.1: secundário espera a transferência antes de confirmar o serial |
| [Release 1.3.40](docs/RELEASE_1.3.40.md) | Publicação sem alterações explica o motivo e leva à aba Configuração |
| [Release 1.3.41](docs/RELEASE_1.3.41.md) | Alerta de NS repetido no apex e mensagem específica ao publicar |
| [Release 1.3.42](docs/RELEASE_1.3.42.md) | Publicar e sincronizar: o secundário só aplica depois do primário |
| [Backup e restauração](docs/BACKUP_RESTORE.md) | Backup diário do banco com limpeza, cópia criptografada no Cloudflare R2 e teste de restauração |
| [Release 1.3.43](docs/RELEASE_1.3.43.md) | Configurações → Backup: credenciais do R2 pelo painel, criptografadas, com teste de conexão |
| [Release 1.3.44](docs/RELEASE_1.3.44.md) | Agente 0.10.2 e fluxos de upgrade, publicação e restauração mais robustos |
| [Release 1.3.45](docs/RELEASE_1.3.45.md) | Hotfix: limite da API por agente; evita HTTP 429 compartilhado atrás do Docker |
| [Release 1.3.46](docs/RELEASE_1.3.46.md) | Confirma upgrade pelo próximo poll autenticado do binário novo |
| [Release 1.3.47](docs/RELEASE_1.3.47.md) | Exclusão integral do tenant, inclusive auditorias vinculadas |
| [Release 1.3.48](docs/RELEASE_1.3.48.md) | Bloqueio de agentes órfãos na API e no firewall, visível no painel |
| [Operação Cliente Exemplo — 18/09/2026](docs/OPERACAO_CLIENTE_EXEMPLO_2026_09_18.md) | Adoção das zonas reversas do dns-primary/02, runbook e pendências |

## Status do projeto

**v1.3.48 publicada no painel** (artefato do agente 0.10.2; atualização dos hosts
autoritativos acompanhada separadamente). Desde a v1.3.29: página Reversos
(1.3.30), desativar/excluir empresa (1.3.31), remover bloco antigo + publicar
num passo (1.3.32/1.3.38), descoberta com comparação entre servidores e zonas
ignoradas (1.3.33–1.3.35), correção dos NS duplicados (1.3.37), espera do
secundário pela transferência (agente 0.10.1, 1.3.39) e mensagem clara quando
não há nada a publicar (1.3.40) e alerta de NS repetido (1.3.41). Detalhes e runbook em
[docs/OPERACAO_CLIENTE_EXEMPLO_2026_09_18.md](docs/OPERACAO_CLIENTE_EXEMPLO_2026_09_18.md).

Histórico anterior: **v1.3.29** (hotfix pego ao vivo no primeiro uso
real da Fase 3 em produção: a remoção do bloco legado em dns-primary e
dns-secondary funcionou de verdade, mas `sanitizeResult()` não conhecia as
chaves novas dessa ação e descartava o resultado inteiro, salvando um
array vazio; corrigido); sobre a v1.3.28 (agente 0.10.0 — Fase 3, última do
plano de detecção e resolução de conflito de zona legada sem SSH:
botão "Remover declaração antiga" no cartão de conflito, nova
operação autorizada `remove_legacy_zone_block` com reconfirmação por
hash antes de agir, backup genérico próprio (não reaproveita
backup_current/restore_backup, que apagariam um caminho não
reconhecido num rollback), preserva o arquivo de zona real quando o
bloco é primary, valida com named-checkconf e restaura do backup se
falhar — sem nunca encadear com aplicar zona, que continua um passo
separado e deliberado; com isso, as 4 fases do plano aberto pelo
incidente do cliente-exemplo estão completas); sobre a v1.3.27 (Fase 2 do plano de detecção e
resolução de conflito de zona legada sem SSH: cartão "Conflito de
configuração" na tela do servidor mostrando zona, arquivo e linha
exatos de cada bloco legado que colide com uma zona gerenciada, e
bloqueio de publicação nomeando a mesma informação — cruza o que o
agente já reporta desde a v1.3.26 (Fase 1)); sobre a v1.3.26 (agente 0.9.0 — Fase 1 do plano de
detecção e resolução de conflito de zona legada sem SSH: o agente
percorre a cadeia real de includes do BIND, localiza blocos `zone {
... };` fora do include gerenciado pelo DNS Center, reporta isso na
prontidão de rotina (~5 min) e barra o apply na hora, nomeando arquivo
e linha exatos, se algum desses blocos colidir com uma zona que o
apply está prestes a escrever); sobre a v1.3.25
(agente 0.8.1 — hotfix pego ao vivo
minutos depois do deploy da v1.3.24: `apply_staging` já reportava
`diagnostics`/`rolled_back` certos, mas `sync_zones` relançava um erro
genérico ao registrar o evento de publicação, descartando os dois
campos antes de chegarem no painel; achado no apply real de
`0.2.0.192.in-addr.arpa` no dns-secondary); sobre a v1.3.24 (corrige um bug real
achado ao vivo no incidente do cliente-exemplo: falhas de apply mostravam só
"Validação final falhou:" sem nenhum detalhe, forçando SSH+journalctl
pra diagnosticar; agora o painel mostra o comando e a saída real do
erro, com sanitização que preserva caminho de arquivo mas continua
removendo token/senha; é a Fase 0 do plano maior de detecção e
resolução de conflito de zona legada sem SSH); sobre a v1.3.23 (comandos de instalação/enrollment/
atualização do agente exibidos na tela do servidor usam `sudo` só se
ele existir no servidor, em vez de assumir presença — encontrado ao
vivo tentando instalar no ns2 do cliente-exemplo, logado como root sem sudo);
sobre a v1.3.22 em produção desde 2026-09-16 (zonas reversas IPv4 ganham um campo
opcional "Modelo de nome do PTR", ex. `host-$`, aplicado só aos PTR
gerados a partir de registros A já cadastrados — inspirado no projeto
antigo, mas sem a geração especulativa pro bloco inteiro que existia
lá); sobre a v1.3.21 em produção desde 2026-09-16 (tela nova "Auditoria" — trilha de
todas as ações administrativas (zonas, registros, servidores,
usuários, organizações, login/logout) por organização, com filtro,
paginação e exportação CSV, recriando uma feature que existia no
projeto antigo pré-Laravel; acesso restrito a organization_admin/
platform_admin); sobre a v1.3.20 (entradas da aba Histórico de cada
zona agora mostram o registro afetado e o que mudou, ex. "Registro
atualizado: web AAAA → 2001:db8::40 (era www A → 192.0.2.40)." — antes
era só "Registro atualizado.", sem detalhe algum, embarcado junto no
mesmo deploy da v1.3.21); sobre a v1.3.19 em
produção desde 2026-09-16 (novo botão "Publicar e sincronizar"
publica a zona e já aplica em todos os servidores vinculados numa
única confirmação, com modal de progresso por servidor; corrige
redirect pós-publicação que jogava o operador de volta pra lista
geral de domínios em vez de manter na própria zona); sobre a v1.3.18
em produção desde 2026-09-16 (TTL padrão sugerido em zonas novas
cai de 1 hora pra 5 minutos, reduzindo o tempo que resolvers podem
manter respostas antigas em cache após uma mudança; inclui migration
nova ajustando o DEFAULT da coluna no schema e correção de rótulo
duplicado "Automático" no seletor de TTL de registro); sobre a
v1.3.17 em produção desde 2026-09-16 (ao concluir uma atualização de agente
com sucesso, o modal fecha sozinho e navega pra dashboard automaticamente,
em vez de exigir clique manual em "Fechar" — só front-end, sem mudança
de backend/agente); sobre a v1.3.16 em produção desde 2026-09-16 (agente 0.7.9 — depois de um
`apply_zones` bem-sucedido, o agente atualiza a observação
autoritativa na hora em vez de esperar até 5 minutos pelo próximo
ciclo do `--observe-bind`; corrige o alerta "Serial divergente" que
ficava visível mesmo com o apply confirmado, encontrado ao vivo em
produção logo após o rollout da v1.3.15 no ns1/ns2 da Cliente Legado); sobre
a v1.3.15 em produção desde 2026-09-16 (agente 0.7.8 passa a
habilitar `DNS_CENTER_AGENT_ALLOW_APPLY=1` por padrão — decisão
consciente confirmada com o operador após o primeiro `apply_zones`
real em produção falhar por esse opt-in nunca ter sido feito por SSH
no ns1; remove a necessidade de SSH manual por servidor pra sempre,
tanto em instalações novas quanto via "Atualizar agente" nas já
existentes; a autorização passa a depender só do RBAC do painel;
sobre a v1.3.14, que fez o campo "Nome" ao editar um
registro PTR numa zona reversa agora mostra e aceita o IP curto — ex.
`2001:db8::243` — em vez do nome reverso de 32 nibbles; o controller
converte automaticamente pro formato completo ao salvar, sem mudar o
que fica gravado no banco/BIND; também removido o prefixo "bloco"
redundante das listas de zonas reversas; sobre a v1.3.13, que fez o
bloco CIDR aparecer
também na lista geral de Domínios (não só na aba "DNS reverso") e como
título principal ao abrir uma zona reversa — nome arpa completo vira
subtítulo em vez de sumir; registros PTR não mostram mais a linha
truncada do nome de 32 nibbles quando o IP já é calculado; sobre a
v1.3.12, que fez registros PTR de uma zona
reversa agora mostram o IP calculado — ex. `2001:db8::242` — em vez
do nome reverso de 32 nibbles ilegível; a lista de zonas reversas
ficou mais enxuta, só "bloco X/NN · N registro(s)"; de brinde, corrige
um bug antigo que duplicava o sufixo da zona no nome secundário de
registros com nome já absoluto (PTR/NS gerados por sincronizadores);
sobre a v1.3.11, que fez zona reversa mostrar o bloco
CIDR calculado — ex. `2001:db8::/32` — junto do nome in-addr.arpa/
ip6.arpa, tanto no cabeçalho da zona quanto na lista da aba "DNS
reverso"; pedido do operador ao testar o assistente ao vivo, já que o
nome reverso sozinho é difícil de verificar de cabeça; funciona pra
qualquer zona reversa, não só as criadas pelo assistente; sobre a
v1.3.10, que corrigiu o modal "Criar zona
reversa" lançado na v1.3.9 estava reaproveitando um grid CSS pensado
pra outro formulário, o que espremia os campos e quebrava o rótulo de
forma feia — achado ao vivo pelo operador logo após o deploy. Corrigido
com um grid próprio de duas colunas, e a lista de zonas reversas
ganhou ícone/família/contagem de registros em vez de só texto solto;
sobre a v1.3.9, que deu à aba "DNS reverso" da zona,
que antes era só um placeholder estático, ganhou um assistente de
verdade: botão "Criar zona reversa" calcula o nome in-addr.arpa/
ip6.arpa a partir de um bloco CIDR IPv4 octeto-alinhado ou prefixo
IPv6 nibble-alinhado e cria a zona com NS automático pelo mesmo
caminho de criação de zona normal; nas zonas reversas IPv4 um botão
separado gera/atualiza os PTR a partir dos registros A de um domínio
escolhido da organização — passo deliberado, não automático na
criação; PTR de IPv6 continua 100% manual, restrição já estabelecida
nesta sessão; sobre a v1.3.8, que fez a segunda rodada do fix de
fonte da aba Nameservers — o primeiro fix da v1.3.7 corrigiu parte,
mas comparando print a print com Domínios/Servidores ainda sobravam
vários elementos maiores que o resto do painel, em ambas as sub-abas
Identidades e Perfis: contadores, títulos, número de posição na lista,
cabeçalho de seção e rótulos dos cards de resumo — todos sem
`font-size` definido, então caíam no tamanho padrão do navegador;
sobre a v1.3.7, que fez o primeiro fix de CSS dessa aba e corrigiu um
teste do agente que só passava rodando como root — mascarava o
próprio comportamento sob teste no CI; também nessa janela o
histórico completo do repositório foi publicado pela primeira vez no
GitHub, com CI habilitado e verde; sobre a v1.3.6, que trouxe o agente 0.7.7 com correções de robustez
operacional — não repete operação já expirada pelo painel, trata
timeout de rede como erro recuperável, atualiza units systemd por
cópia atômica, entre outras; sobre a v1.3.5, que colocou o botão
"Aplicar agora" dentro de uma transação com bloqueio do servidor para
impedir aplicações duplicadas por solicitações simultâneas, e
adicionou o workflow de CI; sobre a v1.3.4, que deu à zona um botão
"Aplicar agora" por servidor pendente — clica, confirma
num modal, e o agente aplica de verdade no BIND na próxima janela do
timer, sem precisar de SSH nem digitar a frase de confirmação manual;
reaproveita o mesmo mecanismo de operação autorizada de
`install_bind`/`upgrade_agent`; sobre a v1.3.3, que fez a checagem de
prontidão do agente detectar, antes de publicar, quando um BIND
existente não está lendo o include gerenciado do DNS Center — o
problema real que travou a importação da Cliente Legado agora aparece
como aviso em "Correções necessárias" em vez de só ser descoberto
depois de adoção/TSIG/PTR/SOA já resolvidos; sobre a v1.3.2 que fez
publicar uma zona
voltar pra lista de domínios em vez de ficar presa na zona, facilitando
trabalhar zona por zona; sobre a v1.3.1 que corrigiu a tela de zona
voltar sempre pra aba "Registros DNS" depois de adotar/publicar/
vincular PTR/associar TSIG, sobre a v1.3.0 de vínculo de PTR reverso,
sobre a v1.2.2 de correções de dashboard, sobre a v1.2.0 de troca de
contexto de organização + transferência de servidor, sobre a v1.1.0 de
onboarding de empresa, sobre a v1.0.1 de patch de segurança, sobre a
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
confirma zero advisories restantes. Em seguida, a tela `/empresas`
(restrita a `is_platform_admin`) foi adicionada para criar organizações
(tenants) novas e o primeiro usuário de cada uma pelo painel, sem
precisar de shell — promovida a produção como v1.1.0
(ver [Release 1.1.0](docs/RELEASE_1.1.0.md)) e homologada em produção
no mesmo dia, com uma empresa real cadastrada e login confirmado pelo
usuário criado. Em seguida vieram troca de contexto de organização pelo
platform admin e transferência de servidor entre empresas — código
iterado e testado direto em produção pelo operador (`1.1.1` a `1.1.7`)
antes de ser commitado; consolidado numa release formal como v1.2.0
(ver [Release 1.2.0](docs/RELEASE_1.2.0.md)), com o código conferido
byte a byte contra o que já estava no ar.

## Pendências futuras

Itens deliberadamente fora do escopo até a v1.2.0, sem data prevista —
registrados aqui pra não se perderem, não porque algo ficou pela
metade:

- **IMPORT-PILOT e ADOPT de BIND legado**: importar/adotar zonas de
  servidores BIND existentes que não passaram pelo fluxo padrão de
  criação do DNS Center.
- **Notificações por SMTP e Telegram**: hoje não há envio de e-mail
  transacional nem alertas via Telegram.
- **WAF integrado**: proteção de camada de aplicação é responsabilidade
  operacional externa, não um recurso do próprio DNS Center.
- **Failover automático de delegação**: promoção de secondary a
  primary hoje é manual.
- **IXFR incremental**: apenas AXFR completo é suportado; nunca foi
  comprovado nem alegado suporte a transferência incremental.

Ver [`RELEASE_CANDIDATE_2.md`](docs/RELEASE_CANDIDATE_2.md) para o
levantamento original completo dessas limitações.
