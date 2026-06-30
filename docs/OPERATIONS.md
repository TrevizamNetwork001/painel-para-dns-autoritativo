# Operacao do Painel DNS Bind

## Rotina Operacional

- Validar saude HTTP do painel.
- Revisar logs do Apache e logs internos do painel.
- Confirmar espaco em disco antes de backups e atualizacoes.
- Manter `storage`, secrets e backups fora do Git e fora do DocumentRoot.
- Revisar alteracoes de DNS antes de qualquer reload manual do BIND.

## Backup do Banco

Backup recomendado:

```bash
sqlite3 /var/www/html/painel/storage/database/painel_dns.sqlite ".backup '/root/painel_dns.sqlite-$(date +%Y%m%d-%H%M%S).bak'"
```

Proteja o backup com permissoes restritas e nao publique o arquivo.

## Backup de Secrets

```bash
install -m 600 -o root -g root /var/www/html/painel/storage/secrets/dns_servers.secret /root/dns_servers.secret-$(date +%Y%m%d-%H%M%S).bak
```

Secrets devem ficar acessiveis somente a operadores autorizados.

## Backup do Apache

```bash
cp -a /etc/apache2/sites-available/painel-bind.conf /root/painel-bind.conf-$(date +%Y%m%d-%H%M%S).bak
```

## Rotacao e Limpeza de Logs

- Use `logrotate` para logs do Apache.
- Remova logs antigos somente apos confirmar que nao sao necessarios para auditoria.
- Nunca mova logs para dentro do release.
- Nunca versione arquivos `*.log`.

## Atualizacao Futura Segura

- Fazer backup de `storage/database`.
- Fazer backup de `storage/secrets`.
- Fazer backup da configuracao Apache.
- Gerar release com `git archive`.
- Validar o pacote antes de aplicar.
- Testar em staging ou diretorio temporario.
- Revisar migrations antes de executar em producao.
- Validar permissoes apos a atualizacao.

## Rollback de Release

- Restaurar o release anterior da aplicacao.
- Restaurar backup do banco se houve migration.
- Restaurar backup de secrets se necessario.
- Restaurar VirtualHost Apache se foi alterado.
- Executar `apachectl configtest`.
- Recarregar Apache somente se a validacao passar.

## Validacao Apache

```bash
apachectl configtest
```

Somente se passar:

```bash
systemctl reload apache2
```

## Validacao BIND Manual

Use validacoes manuais antes de qualquer reload:

```bash
named-checkconf
named-checkconf -z
```

Execute `rndc reload` somente se o operador confirmar manualmente que a configuracao esta correta e que a janela operacional permite a acao.

## Firewall e nftables

- Revise regras antes de aplicar.
- Evite `nft -f` automatico em producao.
- Mantenha backup das regras atuais.
- Tenha acesso administrativo alternativo antes de alterar firewall remoto.

## Dados Que Nao Devem Ser Publicados

Nao publique:

- `storage`.
- Secrets.
- Bancos SQLite.
- Backups.
- Logs.
- Dados reais de dominios.
- Dados reais de usuarios.
- Dados reais de servidores.
- Dados de auditoria ou firewall.
