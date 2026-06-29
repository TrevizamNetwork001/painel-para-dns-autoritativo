# Handoff final - Reestruturacao do painel Bind

## Estrutura final

- `public/` e a camada de entrada publica
- os controllers legados foram consolidados em `app/LegacyControllers/`
- o banco SQLite passou para `storage/database/painel_dns.sqlite`
- o secret de servidores DNS passou para `storage/secrets/dns_servers.secret`
- a configuracao legada foi separada em `config/app.php`
- `config.php` da raiz segue como wrapper temporario

## Estado do Git

O repositório ficou saneado ao final da reestruturacao, com as fases documentadas e os cortes principais commitados.

## Arquivos sensiveis fora do Git

- bancos SQLite em `storage/database/`
- secrets em `storage/secrets/`
- backups legados em `db/`
- logs e chaves operacionais
- `.env` e arquivos similares de configuracao local

## Commits principais

- `dc9f94a` - move caminhos de banco e secrets para storage
- `30a8645` - valida storage ativo do painel bind
- `f9c4b9d` - remove fallback legado de banco e secrets
- `edf4194` - move configuracao legada para config
- `755b33a` - move controllers legados para app
- `1830878` - remove controllers legados da raiz

## Validacoes executadas

- `php -l` nos arquivos de configuracao, controllers e wrappers
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `curl` sem sessao para as rotas principais
- `sqlite3 storage/database/painel_dns.sqlite "PRAGMA integrity_check;"`
- `git diff --check`
- `git diff --cached --check`
- validacao de rotas publicas com `php -S` local em `public/`

## Rollback Apache

Se for necessario voltar o DocumentRoot, existe backup da configuracao em:

```text
/etc/apache2/sites-available/000-default.conf.bak-public-20260629
```

O rollback operacional esperado e restaurar essa configuracao e recarregar o Apache depois do `configtest`.

## Pendencias futuras

- remover o wrapper temporario `config.php` da raiz em um corte posterior
- limpar artefatos legados que ainda existam fora da area publica
- revisar se algum backup ou arquivo historico ainda precisa permanecer acessivel internamente
- consolidar a remocao definitiva de referencias antigas a `db/`

## Fechamento

A reestruturacao Bind foi finalizada com a superficie publica isolada em `public/`, a logica legada preservada em `app/LegacyControllers/` e os dados sensiveis fora do versionamento.
