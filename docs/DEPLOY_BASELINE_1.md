# DEPLOY-BASELINE-1 — instalação e atualização formal

## Objetivo

Esta baseline define um caminho manual e auditável para instalar ou atualizar o
DNS Center com imagens imutáveis. Ela não publica release, não executa deploy
na instância ativa e não habilita automação de produção.

Escopo desta fase:

- imagens PHP e nginx identificadas por tag imutável;
- assets Vite construídos dentro das imagens;
- operação sem bind mount do workspace;
- configuração e segredos fora das imagens;
- backup PostgreSQL obrigatório antes de migrations;
- migrations explícitas e sem rollback automático;
- health checks de PostgreSQL, Redis, aplicação e HTTPS;
- rollback somente da aplicação;
- instalador verificável do agente;
- instalação do painel local ou centralizado;
- descrição do onboarding atualmente suportado.

Fases posteriores: onboarding completo, restore homologado, publicação de
release versionada e failover controlado.

## Artefatos e imagens

| Artefato | Função |
|---|---|
| `docker/php/Dockerfile.production` | PHP-FPM, Composer e assets |
| `docker/nginx/Dockerfile.production` | nginx e cópia própria dos assets |
| `compose.build.yaml` | build manual com a mesma tag |
| `compose.production.yaml` | operação sem código montado |
| `deploy/dns-center-deploy` | install, update, backup, rollback e status |
| `deploy/deployment.env.example` | imagens, versão, paths e portas |
| `deploy/app.env.example` | ambiente Laravel/PostgreSQL externo |

O `compose.yaml` original continua exclusivamente de desenvolvimento.

O build PHP executa `npm ci`, `npm run build`, instala exatamente o
`composer.lock` sem dependências de desenvolvimento e copia código, `vendor` e
`public/build` para a imagem final. A imagem roda como `www-data`. O build nginx
reconstrói os assets pelo mesmo lock e copia somente `public`.

Nenhuma imagem recebe `.env`, `.git`, certificados, backups, storage
operacional, `vendor` ou `node_modules` do host. A `.dockerignore` impõe essa
barreira e também exclui caches Laravel locais, evitando providers de
desenvolvimento na imagem. A versão fica em `APP_RELEASE` e na label OCI
`org.opencontainers.image.version`.

### Build manual

```bash
export DNS_CENTER_VERSION=2026.07.30-1
export DNS_CENTER_APP_IMAGE=registry.example.com/dns-center/app
export DNS_CENTER_WEB_IMAGE=registry.example.com/dns-center/web

docker compose --env-file /dev/null -f compose.build.yaml build
docker compose --env-file /dev/null -f compose.build.yaml push
```

Esses comandos não alteram produção. A tag deve ser imutável no registry.

## Configuração externa e secrets

```bash
sudo install -d -m 0700 /etc/dns-center
sudo install -m 0600 deploy/deployment.env.example \
  /etc/dns-center/deployment.env
sudo install -m 0600 deploy/app.env.example /etc/dns-center/app.env
```

`deployment.env` contém paths e referências de imagem. `app.env` contém
`APP_KEY`, senha do banco e demais valores do runtime. O comando recusa
permissões diferentes de `0600`.

Gere `APP_KEY` fora da imagem e preencha o arquivo sem imprimir a chave. Use
senha PostgreSQL exclusiva e longa. O exemplo usa Redis para cache, sessão e
fila. O Docker socket nunca é montado nos serviços.

O arquivo externo é injetado com `env_file`; não é copiado para a imagem. Um
cofre pode materializá-lo antes do comando, respeitando modo `0600` e a política
local de descarte.

## Instalação do painel

Pré-requisitos: Docker Engine/Compose v2, imagens disponíveis, DNS apontado,
certificado em `DNS_CENTER_CERTBOT_CONF/live/<NGINX_HOST>/`, diretório ACME e
espaço protegido para backups.

```bash
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy install 2026.07.30-1
```

Fluxo manual:

1. valida tag, arquivos e permissões;
2. baixa imagens sem iniciar app/web;
3. executa preflight e `security-check`;
4. inicia somente PostgreSQL e Redis;
5. cria dump custom, valida com `pg_restore --list` e registra tamanho/hash;
6. inicializa permissões dos volumes;
7. mostra `migrate:status` e executa somente `migrate --force`;
8. inicia serviços e aguarda health checks;
9. reinicia workers graciosamente e registra a versão.

Não são usados `migrate:fresh`, `db:wipe` ou rollback de migration. Depois da
primeira instalação, crie o administrador explicitamente:

```bash
docker compose --env-file /etc/dns-center/deployment.env \
  -f compose.production.yaml \
  exec app php artisan dns-center:create-admin \
    --organization=empresa \
    --organization-name="Empresa"
```

Se o slug ainda não existir, o comando cria a organização e o administrador na
mesma transação. Não use `DatabaseSeeder` em produção.

## Atualização e health checks

Toda migration precisa ser compatível com a versão anterior da aplicação.

```bash
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 2026.08.01-1
```

O backup é uma barreira: dump vazio, inválido ou falho impede migrations. O
sucesso registra `current-version`, `previous-version` e histórico em
`DNS_CENTER_STATE_DIR`.

Health checks:

- PostgreSQL: `pg_isready`;
- Redis: `redis-cli ping`;
- app: `migrate:status`, comprovando bootstrap e banco;
- web: HTTPS `/up` pelo nginx.

Fila e scheduler dependem do app saudável. O deploy usa `compose up --wait`.

```bash
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy status
curl --fail --silent --show-error https://dns.example.com/up
```

Valide ainda login, dashboard, servidores, zonas, agentes, fila, scheduler e
logs com conta autorizada.

## Rollback da aplicação

```bash
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy rollback
```

O rollback troca app, queue, scheduler e web pela tag anterior e aguarda health
checks. Ele não restaura banco, não executa `migrate:rollback` e não altera
volumes. Schema e releases precisam ser backward-compatible. Restore será
homologado em fase própria.

## Instalação local do agente

“Local” significa painel e BIND no mesmo host lógico. O agente roda no host,
fora dos containers; `/etc/bind` nunca é montado no painel. Cadastre primeiro o
servidor com hostname/IP real.

```bash
curl --proto '=https' --tlsv1.2 -fsSLO \
  https://dns.example.com/install/agent_install.sh
curl --proto '=https' --tlsv1.2 -fsSLO \
  https://dns.example.com/install/agent_install.sh.sha256
sha256sum --check agent_install.sh.sha256
sudo env DNS_CENTER_PANEL_URL=https://dns.example.com bash agent_install.sh
```

HTTP é aceito somente para localhost e deve ficar restrito a laboratório.

## Instalação centralizada e onboarding suportado

No modelo centralizado, cada servidor autoritativo executa seu agente e acessa
o painel por HTTPS; não existe SSH operacional do painel.

1. cadastre hostname/IP com correspondência única;
2. execute o instalador verificado com a URL central;
3. o agente cria solicitação com credencial efêmera local;
4. administrador com 2FA aprova no servidor correto;
5. o token operacional é entregue uma única vez;
6. confirme timers, heartbeat, inventory e readiness.

O instalador baixa o Python e cinco units systemd canônicas, valida SHA-256 de
cada artefato, recusa HTTP remoto e compila o Python antes de habilitar timers.
Se ativação falhar, restaura os artefatos anteriores e não apaga configuração
ou credencial existentes. Automação integral, upgrade/rotação guiados e
homologação multi-host pertencem à próxima fase de onboarding completo.

## Backup e limites

```bash
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy backup
```

O backup cria dump custom, valida o catálogo e grava `.sha256`. Não há restore
automático nesta fase.

Limites:

- nenhum deploy automático ou CI de produção;
- nenhum bind mount do checkout no Compose operacional;
- nenhuma credencial na imagem;
- nenhuma migration revertida automaticamente;
- nenhum restart/configuração de BIND pelo deploy do painel;
- nenhum failover ou promoção;
- TLS, firewall, registry, cofre e retenção de backups são controles externos.

Próximas fases: onboarding completo de agentes; backup e restore testados;
release versionada e publicada; failover controlado.

## Validação desta implementação

Sem iniciar o Compose operacional ou alterar produção, foram validados:

- parsing de `compose.build.yaml` e `compose.production.yaml`;
- ausência de bind mount do workspace no Compose operacional;
- build completo de app e web com tag local `deploy-baseline-test`;
- Composer production-only, package discovery e cache de views na imagem;
- dois builds Vite com manifest idêntico;
- ausência de `.env`, `.git` e Laravel Pail na imagem PHP;
- execução do app como UID não-root;
- labels de versão e health checks nas duas imagens;
- rotas e checksums públicos do instalador;
- `bash -n` nos scripts e recusa da tag `latest`;
- Pint nos arquivos PHP alterados;
- 144 testes Laravel, com 795 asserções;
- 44 testes Python e `py_compile`;
- `git diff --check`.

As imagens são somente artefatos locais de teste. Não houve push, release,
migration, backup do banco ativo, troca de container ou deploy.
