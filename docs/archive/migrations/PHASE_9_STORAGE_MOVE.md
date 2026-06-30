# Fase 9 - Move de banco e secrets para storage

## Objetivo

Mover os caminhos de SQLite e secrets para `storage/` com compatibilidade provisoria, sem apagar o banco/secret original antes da validacao.

## Caminhos antigos e novos

### Banco SQLite

Anterior:

```text
db/painel_dns.sqlite
```

Novo:

```text
storage/database/painel_dns.sqlite
```

Fallback temporario mantido:

```text
db/painel_dns.sqlite
```

### Secret de servidores DNS

Anterior:

```text
db/dns_servers.secret
```

Novo:

```text
storage/secrets/dns_servers.secret
```

Fallback temporario mantido:

```text
db/dns_servers.secret
```

## Backup

Antes da migracao foi mantido o backup do banco usado nas fases anteriores:

```text
db/painel_dns.sqlite.bak-before-schema-fix-20260629
```

O banco e o backup nao devem ser commitados.

## Alteracoes de codigo

### `includes/db.php`

O acesso ao SQLite passou a preferir:

```text
storage/database/painel_dns.sqlite
```

Se `storage/` nao existir, o include continua caindo temporariamente para:

```text
db/painel_dns.sqlite
```

### `includes/dns_servers.php`

O segredo usado para criptografia local de credenciais passou a preferir:

```text
storage/secrets/dns_servers.secret
```

Se `storage/` nao existir, o include continua caindo temporariamente para:

```text
db/dns_servers.secret
```

## Compatibilidade provisoria

A compatibilidade com `db/` foi mantida apenas como medida temporaria.

Essa compatibilidade deve ser removida em fase posterior, depois de validar o ambiente em producao com o caminho novo.

## Validacoes realizadas

- `sqlite3 storage/database/painel_dns.sqlite "PRAGMA integrity_check;"`
- `php -l includes/db.php`
- `php -l includes/dns_servers.php`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `curl` sem sessao para:
  - `/index.php` -> `302` para `/login.php`
  - `/login.php` -> `200`
  - `/dashboard.php` -> `302` para `/login.php`
  - `/dns-servers.php` -> `302` para `/login.php`
- `git diff --check`

## Observacoes de permissao

O acesso dos arquivos em `storage/` foi verificado com o usuario `www-data`.

O banco copiado e o secret gerado em `storage/` precisam permanecer acessiveis ao Apache, mas fora do Git.

## Riscos conhecidos

- Se a compatibilidade temporaria com `db/` for removida cedo demais, o ambiente legado pode quebrar antes da troca completa.
- Se houver um secret antigo fora de `storage/`, ele precisa ser preservado ate o corte final.
- A secret de servidores DNS continua sendo arquivo local; nao deve entrar em versionamento.
- O banco em `storage/database/` deve ser o unico caminho efetivo depois da migracao ser consolidada.

## Proximo passo

Validar o ambiente com o caminho novo em uso, depois remover o fallback para `db/` em fase controlada e documentada.
