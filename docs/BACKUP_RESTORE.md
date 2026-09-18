# Backup e restauração do banco

O banco PostgreSQL é a fonte da verdade das zonas e registros dos clientes. Até a 1.3.42
o único backup era o `pg_dump` feito antes de cada deploy, no mesmo disco do painel.

## O que existe agora

`dns-center-deploy backup` (roda todo dia às 02:30 pelo cron):
1. `pg_dump` em formato custom, validado com `pg_restore --list`, com `.sha256`, em
   `DNS_CENTER_BACKUP_DIR` (`/var/backups/dns-center`).
2. **Limpeza**: mantém sempre os 10 mais recentes e remove os que passarem de
   `DNS_CENTER_BACKUP_RETENTION_DAYS` (padrão 14 dias).
3. **Cópia fora do servidor (Cloudflare R2)**, se configurada — sempre **criptografada**
   (gpg simétrico AES256) antes de sair. Sem a senha configurada, nada é enviado.
   O envio é conferido pelo tamanho remoto.

`dns-center-deploy backup-verify [arquivo]` (semanal, domingo 02:45): confere o checksum,
**restaura num banco descartável** (`dns_center_restore_test`, nunca o de produção), compara
zonas/registros/usuários com o banco em uso e apaga o banco de teste.

Os comandos usam a versão que está no ar (`/var/lib/dns-center-deploy/current-version`), não a
do `deployment.env`. O `flock` do deploy evita rodar junto com um deploy.

## Configuração da cópia no Cloudflare R2

1. No painel da Cloudflare: R2 → criar um **bucket privado** (ex.: `dns-center-backups`) e um
   **API token** (S3) com permissão de leitura/escrita **só nesse bucket**. Anote Account ID,
   Access Key ID e Secret Access Key.
2. No bucket, crie uma **regra de ciclo de vida** para apagar objetos após 30 dias (o
   servidor só limpa a pasta local; a retenção no R2 é do R2).
3. Na máquina, como root (os segredos ficam só em arquivos 0600, nunca no repositório):

   ```bash
   install -m 600 /dev/null /etc/dns-center/backup.pass
   openssl rand -base64 32 > /etc/dns-center/backup.pass      # senha de criptografia
   install -m 600 /dev/null /etc/dns-center/backup-r2.env
   cat > /etc/dns-center/backup-r2.env <<'ENV'
   R2_ACCOUNT_ID=...
   R2_BUCKET=dns-center-backups
   R2_ACCESS_KEY_ID=...
   R2_SECRET_ACCESS_KEY=...
   R2_PREFIX=dns-center/
   ENV
   ```

4. **Guarde uma cópia da senha (`backup.pass`) fora do servidor** (gerenciador de senhas).
   Sem ela, os backups do R2 **não podem ser descriptografados**.
5. Acrescente ao `/etc/dns-center/deployment.env` (modo 0600):

   ```
   DNS_CENTER_BACKUP_REMOTE_ENV=/etc/dns-center/backup-r2.env
   DNS_CENTER_BACKUP_PASSPHRASE_FILE=/etc/dns-center/backup.pass
   DNS_CENTER_BACKUP_RETENTION_DAYS=14
   ```

6. Teste: `sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env /opt/dns-center/deploy/dns-center-deploy backup`
   deve terminar com "Cópia remota: … (N bytes, criptografada)".

## Agendamento

`deploy/cron.d/dns-center-backup` → copiar para `/etc/cron.d/dns-center-backup`
(root:root, 0644). Acompanhar em `/var/log/dns-center-backup.log`.

## Restaurar (desastre)

```bash
# 1. baixar o objeto do R2 (dashboard da Cloudflare ou qualquer cliente S3) e descriptografar
gpg --batch --pinentry-mode loopback --passphrase-file /etc/dns-center/backup.pass \
    -o dns-center.dump -d dns-center-AAAAMMDDTHHMMSSZ.dump.gpg
# 2. conferir e restaurar num banco NOVO (não sobrescreva o de produção sem decidir isso)
docker compose exec -T postgres pg_restore --list < dns-center.dump | head
docker compose exec -T postgres createdb -U "$POSTGRES_USER" dns_center_recuperado
docker compose exec -T postgres pg_restore -U "$POSTGRES_USER" -d dns_center_recuperado \
    --no-owner --no-privileges --exit-on-error < dns-center.dump
```

Depois, apontar a aplicação para o banco recuperado (ou renomear) e rodar
`php artisan migrate --force` se a versão da aplicação for mais nova que o dump.

## O que este backup NÃO cobre

- `/etc/dns-center/app.env` (APP_KEY e segredos): guarde-o à parte; sem a `APP_KEY` os
  segredos cifrados dentro do banco (ex.: chaves TSIG, 2FA) não abrem.
- Configuração e zonas dos servidores BIND (`dns-primary`, `dns-secondary`): o agente já faz backup local
  antes de cada alteração, mas não há cópia central.
