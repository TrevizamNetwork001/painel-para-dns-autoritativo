# deploy/

Tudo que é preciso para instalar, atualizar, reverter e manter o DNS Center num servidor
Docker. Os arquivos `*.example` são modelos: **copie, preencha e nunca versione os reais**.

> Procedimentos do dia a dia e de incidente: [`RUNBOOK.md`](../RUNBOOK.md).

## Conteúdo

| Arquivo | Para quê |
| --- | --- |
| `dns-center-deploy` | Comando de deploy: `install`, `update <versão>`, `rollback`, `status`, `backup`, `backup-verify`. Sempre manual; usa `flock` para não rodar dois ao mesmo tempo |
| `deployment.env.example` | Modelo de `/etc/dns-center/deployment.env` (versão, imagens, diretórios, retenção de backup). Modo 0600 |
| `app.env.example` | Modelo de `/etc/dns-center/app.env` (segredos Laravel/PostgreSQL). Modo 0600 |
| `disk-guard.sh` | Limpeza diária de imagens/cache Docker e aviso de disco. Protege a versão atual, a anterior (alvo do rollback) e as 3 mais recentes |
| `cron.d/dns-center-backup` | Backup diário às 02:30 e teste de restauração aos domingos 02:45 |
| `cron.d/dns-center-disk-guard` | `disk-guard.sh` às 03:15 |
| `git-hooks/pre-commit` | Hook opcional: sintaxe dos arquivos staged + bloqueio de segredos |
| `systemd/`, `dns-center-agent-firewall-sync` | Sincronização do firewall do host (inventário/exportação de IPs bloqueados) |

O agente que roda nos servidores BIND fica em [`../agent/`](../agent/) (com as units `systemd` dele).
Certificados: `../docker/scripts/renew-certificates.sh`, agendado em `/etc/cron.d/dns-center-certbot`.

## Instalação dos agendamentos

```bash
sudo install -m 0644 -o root -g root deploy/cron.d/dns-center-backup /etc/cron.d/dns-center-backup
sudo install -m 0644 -o root -g root deploy/cron.d/dns-center-disk-guard /etc/cron.d/dns-center-disk-guard
```

Se você alterar o arquivo real em `/etc/cron.d/`, copie a mudança de volta para cá.

## Hook de pre-commit

O Git não versiona hooks. Para ativar no clone local:

```bash
ln -sf ../../deploy/git-hooks/pre-commit .git/hooks/pre-commit
```

A suíte completa de testes roda no CI (`.github/workflows/ci.yml`) e antes de cada release; o
hook cobre só o que é rápido e evita o erro mais caro num repositório público: vazar segredo.

## Variáveis úteis

| Variável | Padrão | Efeito |
| --- | --- | --- |
| `DNS_CENTER_BACKUP_RETENTION_DAYS` | 14 | Dias de retenção local dos dumps (sempre mantém os 10 mais recentes) |
| `DNS_CENTER_BACKUP_REMOTE_ENV` | — | Arquivo (0600) com credenciais do R2, alternativa à tela Configurações → Backup |
| `DNS_CENTER_BACKUP_PASSPHRASE_FILE` | — | Senha de criptografia dos dumps enviados ao R2 (sem ela, nada é enviado) |
| `DNS_CENTER_DISK_WARN_PERCENT` | 80 | Limite de aviso do `disk-guard.sh` |
| `DNS_CENTER_KEEP_IMAGE_VERSIONS` | 3 | Quantas versões recentes de imagem o `disk-guard.sh` poupa |
