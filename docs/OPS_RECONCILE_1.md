# OPS-RECONCILE-1 — Reconciliação do ambiente ativo

Data da reconciliação: 2026-07-30
Classificação final: **ALINHADO**

## Escopo e premissas

Esta fase reconciliou a instância ativa após OBSERVABILITY-1 sem release ou
deploy formal. O workspace já estava montado nos containers e a migration da
fase anterior já havia sido aplicada operacionalmente para eliminar o erro
HTTP 500 em `/servidores`.

Não foram implementados failover, promoção de secondary, instalador, SMTP,
Telegram, alterações de topologia, novas migrations ou refatorações.
Nenhuma zona, configuração BIND ou agente foi alterado.

Todos os valores sensíveis foram omitidos. Este documento não contém
credenciais, `APP_KEY`, tokens ou chaves TSIG.

## Diagnóstico inicial

- Hostname: `dnscenter`.
- Horário da coleta inicial: `2026-07-30T17:12:15-03:00`.
- Branch: `main`.
- HEAD do host: `874badbe601dfaf2b737d66d235a98556db4516d`.
- HEAD visto em `/var/www/html` pelo container `app`:
  `874badbe601dfaf2b737d66d235a98556db4516d`.
- Worktree: limpo, sem arquivos modificados ou não rastreados.
- Histórico curto:
  - `874badb feat: adiciona observabilidade autoritativa do bind`
  - `a315cff fix: melhora configuracao visual do totp`
  - `a92d139 fix: ajusta comando de instalacao do agente`
  - `cb41397 fix: evita erro antes da migration do agente`
  - `8bb02ce feat: aprova instalacao do agente pelo painel`
- Ambiente Laravel: `production`.
- Debug: desativado.
- Laravel: 13.22.0; PHP: 8.4.23; Composer: 2.10.2.

O Git do usuário de inspeção sinalizou propriedade divergente do repositório.
As leituras foram feitas com `git -c safe.directory=/opt/dns-center`, sem
alterar configuração global.

## Topologia ativa, imagens e mounts

| Serviço | Imagem | ID da imagem | Mount relevante |
|---|---|---|---|
| `app` | `dns-center-app:latest` | `ffc97cc5b341` | `/opt/dns-center:/var/www/html:rw` |
| `queue` | `dns-center-queue:latest` | `4cebda18a6ff` | `/opt/dns-center:/var/www/html:rw` |
| `scheduler` | `dns-center-scheduler:latest` | `ab880f94c0c6` | `/opt/dns-center:/var/www/html:rw` |
| `web` | `nginx:1.28-alpine` | `a8b39bd9cf0f` | workspace `ro`, template nginx `ro`, volumes Certbot `ro` |
| `postgres` | `postgres:17-alpine` | `742f40ea20b9` | volume `dns-center_postgres_data` |
| `redis` | `redis:8-alpine` | `8096655e4377` | volume `dns-center_redis_data` |

PostgreSQL e Redis estavam `healthy`; os seis serviços estavam ativos. Somente
as portas HTTP/HTTPS do nginx estavam publicadas. PostgreSQL, Redis e PHP-FPM
não tinham porta publicada no host.

O workspace é um bind mount real. PHP-FPM, fila e scheduler executam
diretamente os arquivos do host. O Dockerfile PHP não copia o código da
aplicação para a imagem; copia apenas a configuração PHP. Assim, não existe
uma cópia antiga de código dentro dessas imagens que possa ser executada no
lugar do bind mount.

Os containers foram criados antes do commit, mas seus processos foram
iniciados novamente depois dele. `RestartCount` estava em zero. O campo
`StartedAt` do Docker apresentou diferença incompatível com a hora local e
com os horários dos healthchecks; a sincronização do relógio deve ser
verificada externamente.

## Código e dependências

Os hashes de `composer.lock` e `package-lock.json` foram idênticos no host e no
container:

- `composer.lock`: `3d8ff72172e5cfce356c5c4b8c57f133e45c3a3a0edbfe8e9475ac5ac1cb5402`;
- `package-lock.json`: `b3cd7be0b8521a9805e28d411902dc6040b13dbc4113c9c5443d436bda6b8483`.

`composer validate`, `composer check-platform-reqs` e `composer install
--dry-run` foram aprovados; não havia pacote a instalar, atualizar ou remover.
`vendor` e `node_modules` estavam presentes. Node/npm não estavam disponíveis
no host nem na imagem PHP, portanto uma validação npm adicional não pôde ser
executada. Nenhum `composer update`, `npm update`, install ou rebuild de imagem
foi realizado.

## Banco ativo e migration operacional

- Conexão Laravel: PostgreSQL (`pgsql`).
- Banco: `dns_center`.
- Schema: `public`.
- Servidor: PostgreSQL 17.10.
- Migration observada:
  `2026_07_30_200000_add_authoritative_observability`.
- Batch: 6.
- Migrations registradas: 28.
- Migrations pendentes: nenhuma.

A tabela `migrations` não registra horário de execução. O horário exato não
pode ser recuperado com segurança. A evidência disponível limita a aplicação
ao período posterior ao erro registrado às `2026-07-30 16:06:36 -03:00` e
anterior à validação final/commit de OBSERVABILITY-1 às
`2026-07-30 17:01:04 -03:00`.

A migration criou:

- `dns_authoritative_observations`, com estado atual por servidor/zona,
  seriais, estado de transferência, origem, evento, sequência e timestamps;
- `dns_authoritative_observation_events`, com agente, evento, sequência,
  hash do payload e timestamps;
- em `dns_servers`, as colunas nullable `authoritative_runtime` (JSON),
  `authoritative_observed_at` (timestamp com fuso) e
  `authoritative_sequence` (bigint).

O código de `up()` contém somente alteração de schema e não contém backfill ou
update de dados. Na reconciliação, ambas as tabelas novas tinham zero registros
e nenhum servidor possuía valor nas três colunas novas. Portanto não houve
backfill nem evidência de alteração de dados por essa migration.

O `down()` remove as duas tabelas e as três colunas, sendo estruturalmente
compatível com rollback. Um rollback futuro destruiria observações existentes
e, por isso, exige backup e autorização explícita.

### Registro da aplicação operacional anterior

- Motivo: corrigir HTTP 500 em `/servidores` após o código de OBSERVABILITY-1
  tornar-se visível pelo bind mount.
- Migration: `2026_07_30_200000_add_authoritative_observability`.
- Banco: `dns_center`, schema `public`.
- Resultado: migration registrada no batch 6; schema corresponde ao commit.
- Risco: DDL aplicado fora de um deploy formal; rollback futuro passa a ser
  destrutivo quando houver observações.
- Validações nesta fase: `migrate:status`, catálogo PostgreSQL, contagens
  read-only, `/servidores` sem autenticação retornando redirect e suíte de
  qualidade descrita abaixo.
- Backup anterior: não foi localizado artefato no workspace; a existência de
  backup externo não pôde ser comprovada e permanece **WARNING**.
- Estado posterior: schema reconciliado, sem migration pendente e sem
  reaplicação ou edição manual da tabela `migrations`.

Nenhum backup novo foi criado porque esta fase não escreveu no banco nem
aplicou migration. Logo, não há hash ou tamanho de backup a registrar.

## Caches, assets e processos

Estado inicial dos caches:

- config: não cached;
- events: não cached;
- routes: não cached;
- views: cached.

Configuração e rotas não cached são um estado válido e não mostraram
staleness. Nenhum cache foi limpo. O cache de views, que já estava válido, foi
recompilado por `view:cache` como parte da barreira de qualidade.

Hash agregado dos arquivos publicados em `public/build`:
`3cfadcf93acc406142e10a98822b21ec3b830abcc217323cada3bdaf6e82a300`.
O manifest foi gerado em `2026-07-30 16:52:58 -03:00`, depois das alterações
da fase. OBSERVABILITY-1 alterou views Blade, mas não fontes Vite; não houve
indicação para reconstruir assets.

OPcache estava carregado e ativo, com `opcache.validate_timestamps=1` e
`opcache.revalidate_freq=0`. PHP-FPM tinha master e dois workers. A fila
executava `php artisan queue:work --sleep=2 --tries=3 --timeout=120`; o
scheduler executava `php artisan schedule:work`. Esses processos foram
iniciados depois do commit e usam o bind mount atual. Nenhum processo precisava
ser reiniciado, e nenhum serviço foi reiniciado.

## Segurança

Confirmado:

- `APP_ENV=production`;
- `APP_DEBUG=false`;
- URL e cookie de sessão exigem HTTPS;
- cookie HttpOnly e SameSite restritivo;
- PostgreSQL não está publicado no host;
- Docker socket não está montado no container web;
- volumes de certificados são read-only no nginx;
- `.env` foi corrigido de `0644` para `0600`;
- `dns-center:security-check` aprovou os controles internos e terminou em
  `WARNING` somente por `TRUSTED_PROXIES` vazio.

`TRUSTED_PROXIES` vazio é coerente com a topologia observada, na qual o nginx é
o endpoint publicado e fala diretamente com o app. Se houver proxy externo não
visível nesta inspeção, seus CIDRs devem ser configurados explicitamente.

Firewall, VPN/allowlist, exposição pública além do host, WAF, backup externo,
sincronização NTP e varredura integral de segredos em logs não puderam ser
comprovados e permanecem **WARNING**. Não se declara segurança desses itens sem
evidência externa.

## Correções executadas

Única correção operacional:

```text
chmod 600 /opt/dns-center/.env
```

Não houve alteração no conteúdo do `.env`. O cache de views foi recompilado
pela barreira de qualidade. Não houve escrita no banco, migration, rebuild de
assets ou imagem, limpeza de cache, criação de cache de config/rotas, restart
de PHP, worker, scheduler, nginx, Redis, PostgreSQL ou BIND.

## Validações

Pela rede interna do Compose:

- `/login`: HTTP 200;
- `/up`: HTTP 200;
- `/`: HTTP 302 para login;
- `/servidores`: HTTP 302 para login, sem resposta 500;
- `/zonas`: HTTP 302 para login, sem resposta 500.

Dashboard, conteúdo autenticado de servidores/zonas e login com credenciais não
foram exercitados porque nenhuma credencial foi usada nesta reconciliação. Não
há agentes cadastrados no banco ativo; por isso, continuidade de autenticação
de agentes não é aplicável e não foi declarada como validada.

Barreira de qualidade:

- Laravel: 142 testes aprovados, 755 asserções;
- Python: 44 testes aprovados;
- `py_compile`: aprovado com cache temporário fora do workspace;
- `view:cache`: aprovado;
- `route:list`: aprovado, 85 rotas;
- `git diff --check`: aprovado;
- Pint: não aplicável, pois nenhum PHP foi alterado;
- Vite: não executado, pois não houve mudança em fonte frontend nem indicação
  de asset divergente;
- nenhuma migration nova foi criada;
- `migrate:fresh`, `db:wipe`, rollback e edição da tabela `migrations` não
  foram usados.

## Classificação

**ALINHADO**:

- código ativo igual ao commit conhecido;
- schema igual às migrations e nenhuma pendente;
- assets compatíveis com as fontes alteradas;
- caches válidos;
- PHP-FPM, worker e scheduler usando a versão atual.

A correção de permissão do `.env` tratou um achado de segurança, sem mudar a
classificação de alinhamento de código/schema/assets/processos.

## Procedimento formal de deploy recomendado

1. Registrar commit, janela, responsável, banco-alvo e plano de rollback.
2. Criar e verificar backup do banco antes de qualquer DDL.
3. Preparar dependências a partir dos locks (`composer install`, nunca update;
   `npm ci` e build em ambiente com Node quando houver mudança frontend).
4. Publicar um artefato versionado ou checkout imutável; evitar que edição do
   workspace altere produção instantaneamente.
5. Ativar manutenção somente se a migration exigir.
6. Aplicar apenas `php artisan migrate --force` após `migrate:status`.
7. Criar caches explicitamente definidos pela política de produção.
8. Reiniciar somente PHP-FPM e processos de longa duração que precisem carregar
   o novo código.
9. Validar `/up`, login, dashboard, servidores, zonas, APIs de agentes,
   filas/scheduler e logs.
10. Registrar hashes, comandos, resultados, backup e decisão de rollback.

## Riscos restantes

- aplicação anterior da migration sem evidência local de backup;
- bind mount permite que mudanças no workspace fiquem imediatamente visíveis;
- ausência de Node/npm impede rebuild local reprodutível de assets;
- `TRUSTED_PROXIES` depende da confirmação da topologia externa;
- relógio reportado pelo Docker diverge dos demais timestamps;
- não existem agentes cadastrados para validar autenticação real;
- validações autenticadas exigiriam credencial e não foram executadas.
