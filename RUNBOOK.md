# Runbook de incidentes — DNS Center

Procedimentos para quando algo dá errado em produção. Cada seção diz **como
reconhecer**, **o que fazer** e **como confirmar**. Os comandos foram todos
usados neste servidor; onde algo não foi comprovado, está escrito.

Convenções: o projeto fica em `/opt/dns-center`; o `deploy/dns-center-deploy`
precisa de `DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env` e de `sudo`.

```bash
export DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env
```

## Primeiro passo, sempre

```bash
curl -sk -o /dev/null -w "up: %{http_code}\n" https://localhost/up   # 200 = app respondendo
docker ps --format '{{.Names}}\t{{.Status}}' | grep dns-center        # app, web, queue, scheduler, postgres, redis
df -h /                                                                # disco
sudo ./deploy/dns-center-deploy status                                 # versão ativa, containers, migrations
cat /var/lib/dns-center-deploy/current-version /var/lib/dns-center-deploy/previous-version
```

Depois, no painel: **Dashboard** (itens pendentes) e **Auditoria** (o que mudou).

## Onde tudo mora

| O quê | Onde |
| --- | --- |
| Código e compose | `/opt/dns-center` |
| Segredos da aplicação | `/etc/dns-center/app.env` (modo 0600; **guarde uma cópia fora do servidor**: sem a `APP_KEY`, os segredos cifrados no banco não abrem) |
| Configuração do deploy | `/etc/dns-center/deployment.env` (modo 0600) |
| Estado do deploy | `/var/lib/dns-center-deploy/` (`current-version`, `previous-version`, `history`) |
| Backups do banco | `/var/backups/dns-center/*.dump` (+ `.sha256`) |
| Logs | `/var/log/dns-center-backup.log`, `/var/log/dns-center-disk-guard.log`, `/var/log/dns-center-certbot.log` |
| Agendamentos | `/etc/cron.d/dns-center-backup`, `dns-center-disk-guard`, `dns-center-certbot` (modelos em `deploy/cron.d/`) |

## Site fora do ar (sem resposta, 502, 500)

1. `docker ps` — algum container `dns-center-*` ausente ou `unhealthy`?
2. `docker logs --tail 100 dns-center-app-1` (e `dns-center-web-1`) para o erro real.
3. Disco cheio costuma ser a causa — ver **Disco cheio**.
4. Se o problema começou logo após um deploy: **Reverter um deploy**.
5. Reiniciar um container sem mudar versão: `docker restart dns-center-app-1`.

Confirmar: `/up` → 200 e `/login` → 200.

## Disco cheio / quase cheio

Aconteceu em 28/09/2026: disco em 100% e o build falhou no `apt-get`.

- O `deploy/disk-guard.sh` roda todo dia às 03:15 e registra em
  `/var/log/dns-center-disk-guard.log`; avisa acima de 80%. A tela
  **Configurações → Backup do banco** mostra o uso.
- Limpeza manual segura (não mexe em imagem de container em uso):

```bash
docker image prune -f              # imagens sem tag
docker builder prune -f            # cache de build
docker images | grep dns-center    # releases antigas: remova só as que não são a atual nem a anterior
docker rmi dns-center-app:X.Y.Z dns-center-web:X.Y.Z
```

- **Não apague a imagem da versão anterior** (`previous-version`): é o alvo do rollback.
  O `disk-guard.sh` já protege a atual, a anterior e as 3 mais recentes.

## Fazer um deploy

```bash
# 1. código testado e commitado; tag da release
git tag -a vX.Y.Z -m "..." HEAD
# 2. imagens
DNS_CENTER_VERSION=X.Y.Z docker compose --env-file /dev/null -f compose.build.yaml build
# 3. deploy (faz backup do banco, migrations, healthcheck e security-check)
sudo ./deploy/dns-center-deploy update X.Y.Z
# 4. conferir
curl -sk -o /dev/null -w "%{http_code}\n" https://localhost/up
```

Todo deploy tem uma nota em `docs/RELEASE_X.Y.Z.md` e uma linha no `README.md`.
O resultado `WARNING` por `TRUSTED_PROXIES` vazio é esperado enquanto não há proxy na frente.

## Reverter um deploy

```bash
sudo ./deploy/dns-center-deploy rollback
```

- Volta os containers para `previous-version`. **As migrations NÃO são revertidas**:
  confira se o schema novo é compatível com o código antigo antes de reverter.
- A imagem anterior precisa existir no disco (`docker images | grep dns-center`); o
  servidor não tem registry, então não há de onde baixá-la.
- Depois de reverter, o agente instalado nos servidores **não** volta sozinho: ele só muda
  por uma operação de atualização feita no painel.

## Banco de dados com problema / restaurar backup

- Backup local diário às 02:30 (`/var/backups/dns-center`), com limpeza (mantém os 10 mais
  recentes e remove o que passar de 14 dias) e **teste de restauração** todo domingo às 02:45
  num banco descartável. Resultado em `/var/log/dns-center-backup.log`.
- Testar um dump à mão: `sudo ./deploy/dns-center-deploy backup-verify [arquivo.dump]`.
- Restaurar de verdade: passo a passo em [`docs/BACKUP_RESTORE.md`](docs/BACKUP_RESTORE.md)
  (sempre num banco **novo**; só depois decida trocar o de produção).
- **Cópia fora do servidor (R2): ainda não configurada.** Se o disco do servidor for
  perdido, hoje os backups vão junto. Ver pendências em
  [`docs/STATUS_PROJETO_2026_10_02.md`](docs/STATUS_PROJETO_2026_10_02.md).

## O backup parou de rodar

1. `sudo tail -20 /var/log/dns-center-backup.log` — erro? disco cheio?
2. `cat /etc/cron.d/dns-center-backup` — o arquivo existe e está com modo 0644?
3. Rodar à mão: `sudo ./deploy/dns-center-deploy backup`.

## Agente de um servidor offline ou travado

Reconhecer: Dashboard mostra "Agente offline" / "Saúde autoritativa não confirmada".

No servidor autoritativo:

```bash
systemctl status dns-center-agent.timer dns-center-agent-operation.timer
journalctl -u dns-center-agent-operation.service -n 100 --no-pager
/usr/local/sbin/dns-center-agent --version
```

- O agente consulta o painel a cada **30 s** (operações) e faz heartbeat a cada **5 min**:
  a confirmação de versão após um upgrade pode levar até 5 min, mesmo com o upgrade
  tendo terminado em segundos.
- Operação presa em "Aguardando o agente": expira sozinha (por padrão 20 min; upgrade 10 min).
- Atualizar o agente: **Servidores → Agente → Atualizar** (exige 2FA). Um servidor por vez.
- Agente revogado ou órfão é bloqueado pela API (visível no painel); reinstale pelo fluxo de
  vínculo da própria tela do servidor.

## Publicação de zona falhando

| Mensagem / sintoma | Causa | O que fazer |
| --- | --- | --- |
| "…já tem `<zona>` declarada fora do include gerenciado" | Bloco antigo no `named.conf.local` | Botão **Remover declarações antigas e publicar** na tela da zona (remove com backup e só publica se remover) |
| "BIND não confirmou o serial SOA esperado" | Secundário ainda não transferiu a zona | O painel aplica primeiro no primário. Tente de novo ou use **Forçar transferência** na tela da zona |
| "Sem alterações desde a última publicação" | Nada novo para publicar | Se editou servidores/perfil/SOA, salve na aba **Configuração**; se editou registros, publique de novo |
| Alerta "NS repetido" | Herança de importação (corrigida na 1.3.37) | Aba **Configuração → Salvar configuração** |
| "secondary server id deve ser diferente de primary server id" | Mesmo servidor nos dois campos | Escolha servidores diferentes |
| Descoberta marca "Conflito" com serial igual | Registros duplicados só por grafia (DISC-001, corrigido na 1.3.52) | `docker exec dns-center-app-1 php artisan dns-center:reconcile-duplicate-records` (só lista); `--apply` limpa. **Não publica** a zona |

Para ver o que o BIND disse, na página do agente use **Ver últimas linhas do log do BIND**
(somente leitura; caminhos e segredos são removidos do texto).

## Usuário sem acesso / senha esquecida

```bash
docker exec -it dns-center-app-1 php artisan dns-center:reset-password --email=usuario@exemplo.com
# primeiro administrador (instalação nova):
docker exec -it dns-center-app-1 php artisan dns-center:create-admin
```

Recuperação de 2FA não tem comando dedicado documentado nesta rodada; trate como incidente
de segurança e confirme a identidade antes de qualquer reset.

## Certificado TLS expirando

O renovador roda todo dia às 03:17 (`docker/scripts/renew-certificates.sh`, log em
`/var/log/dns-center-certbot.log`). Se o certificado estiver perto de vencer, rode o script à
mão e confira o log. Não foi validado neste levantamento o que reinicia o `web` depois da
renovação; se o site continuar servindo o certificado antigo, `docker restart dns-center-web-1`.

## Cancelamento de cliente (empresa)

**Empresas → Desativar** corta o acesso sem apagar nada (reversível). **Excluir** apaga
servidores, zonas, registros e usuários só daquela empresa, exige digitar o nome exato e não
envia nada aos servidores BIND; a trilha de auditoria é preservada. A empresa padrão da
plataforma não pode ser desativada nem excluída.

## Antes de enviar ao GitHub (repositório público)

- Nenhum `.env`, chave, token, IP ou domínio de cliente real em arquivo rastreado.
- Instale o hook: `ln -sf ../../deploy/git-hooks/pre-commit .git/hooks/pre-commit`
  (checa sintaxe e procura segredos nas linhas adicionadas).
- O que já foi publicado no histórico não se apaga com um novo commit; reescrever a `main`
  pública exige `push --force` e só deve ser feito de propósito.
