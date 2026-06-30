# Fase 9.1 - Validacao do storage ativo

## Objetivo

Validar que o painel passa a usar os arquivos em `storage/` sem remover ainda o legado em `db/`.

## Confirmacao do caminho do banco

O include `includes/db.php` continua preferindo:

```text
storage/database/painel_dns.sqlite
```

com fallback temporario para:

```text
db/painel_dns.sqlite
```

### Teste executado

O arquivo legado foi renomeado temporariamente para:

```text
db/painel_dns.sqlite.legacy-test
```

Depois foram testadas rotas sem sessao, com resultado:

- `/index.php` -> `302` para `/login.php`
- `/login.php` -> `200`
- `/dashboard.php` -> `302` para `/login.php`
- `/dns-servers.php` -> `302` para `/login.php`
- `/zones.php` -> `302` para `/login.php`

Ao final, o nome original do banco em `db/` foi restaurado.

## Confirmacao do secret

O include `includes/dns_servers.php` usa:

```text
storage/secrets/dns_servers.secret
```

com fallback temporario para:

```text
db/dns_servers.secret
```

O conteudo do secret nao foi exibido nem registrado.

## Ignorados no Git

Foi verificado que os arquivos seguem ignorados pelo Git:

- `db/*.sqlite`
- `storage/database/*.sqlite`
- `db/*.secret`
- `storage/secrets/*.secret`

## Validacoes executadas

- `php -l includes/db.php`
- `php -l includes/dns_servers.php`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `sqlite3 storage/database/painel_dns.sqlite "PRAGMA integrity_check;"`
- `curl` sem sessao para `/index.php`, `/login.php`, `/dashboard.php`, `/dns-servers.php` e `/zones.php`
- `git diff --check`

## Status

A validacao confirma que o painel esta operando com o banco em `storage/database/` e que o fallback em `db/` segue ativo apenas como compatibilidade provisoria.

## Proximo passo

Remover o fallback para `db/` em uma fase posterior, depois de consolidar o uso de `storage/` em producao.
