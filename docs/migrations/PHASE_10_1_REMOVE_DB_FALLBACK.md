# Fase 10.1 - Remocao do fallback legado de `db/`

## Objetivo

Eliminar a compatibilidade provisoria com `db/` para banco e secret, mantendo `storage/` como fonte unica.

## Alteracoes realizadas

### `includes/db.php`

O caminho do SQLite passou a ser fixo em:

```text
storage/database/painel_dns.sqlite
```

### `includes/dns_servers.php`

O secret de servidores DNS passou a ser fixo em:

```text
storage/secrets/dns_servers.secret
```

## Caminhos antigos

- `db/painel_dns.sqlite`
- `db/dns_servers.secret`

Esses arquivos nao foram apagados nesta fase.

## Caminhos atuais

- `storage/database/painel_dns.sqlite`
- `storage/secrets/dns_servers.secret`

## Validacoes executadas

- `sqlite3 storage/database/painel_dns.sqlite "PRAGMA integrity_check;"`
- `php -l includes/db.php`
- `php -l includes/dns_servers.php`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `curl` sem sessao para:
  - `/login.php`
  - `/dashboard.php`
  - `/dns-servers.php`
- `git diff --check`

## Observacoes

O banco legado e o secret legado seguem preservados fora do Git para permitir rollback manual, mas nao sao mais consultados pelo codigo.

## Proximo passo

Depois de estabilizar esta fase, avaliar a limpeza definitiva dos artefatos legados em `db/` em um passo separado e controlado.
