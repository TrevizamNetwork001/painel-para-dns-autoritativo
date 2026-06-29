# Fase 8.6 - Correcao de schema SQLite restaurado

## Objetivo

Corrigir schema faltante no SQLite apos restauracao do banco, sem apagar dados, sem dropar tabelas, sem alterar logica PHP/DNS e sem executar Bind.

## Backup

Antes de qualquer alteracao, foi criado backup do banco:

```sh
cp db/painel_dns.sqlite db/painel_dns.sqlite.bak-before-schema-fix-20260629
```

Arquivos:

- banco corrigido: `db/painel_dns.sqlite`
- backup: `db/painel_dns.sqlite.bak-before-schema-fix-20260629`

Ambos estao ignorados pelo Git e nao devem ser commitados.

## Comparacao inicial

Tabelas existentes antes da correcao:

```text
audit_logs
dns_servers
dns_zone_extra_ignores
dns_zone_inventory
dns_zone_inventory_status
usuarios
```

Tabelas declaradas em `db/schema.sql`:

```text
audit_logs
sqlite_sequence
usuarios
dns_servers
dns_zone_inventory
dns_zone_inventory_status
dns_zone_extra_ignores
dns_zone_governance
dns_server_governance
firewall_admin_access
firewall_ports
firewall_meta
```

`sqlite_sequence` e tabela interna do SQLite para `AUTOINCREMENT`.

## Tabelas faltantes identificadas

Foram identificadas como ausentes:

- `dns_zone_governance`
- `dns_server_governance`
- `firewall_admin_access`
- `firewall_ports`
- `firewall_meta`

As colunas das tabelas ja existentes foram comparadas com o schema atual e estavam compativeis para esta correcao.

## Migracao aplicada

Foi aplicada migracao segura em transacao, usando apenas `CREATE TABLE IF NOT EXISTS`.

Resumo:

```sql
BEGIN;

CREATE TABLE IF NOT EXISTS dns_zone_governance (...);
CREATE TABLE IF NOT EXISTS dns_server_governance (...);
CREATE TABLE IF NOT EXISTS firewall_admin_access (...);
CREATE TABLE IF NOT EXISTS firewall_ports (...);
CREATE TABLE IF NOT EXISTS firewall_meta (...);

COMMIT;
```

Nao houve:

- `DROP TABLE`;
- `DELETE`;
- alteracao de dados existentes;
- alteracao de logica PHP;
- comando Bind.

## Validacao SQLite

Integridade:

```sh
sqlite3 db/painel_dns.sqlite "PRAGMA integrity_check;"
```

Resultado:

```text
ok
```

Tabelas apos a correcao:

```text
audit_logs
dns_server_governance
dns_servers
dns_zone_extra_ignores
dns_zone_governance
dns_zone_inventory
dns_zone_inventory_status
firewall_admin_access
firewall_meta
firewall_ports
usuarios
```

## Validacao HTTP controlada

Sem sessao autenticada, foram chamadas as rotas:

| Rota | Status | Redirect |
| --- | --- | --- |
| `/dashboard.php` | `302` | `login.php` |
| `/firewall.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |

Os redirects para `login.php` sao esperados sem sessao autenticada.

Validacao autenticada de `/firewall.php` ainda deve ser feita em navegador real para confirmar leitura das tabelas `firewall_*` no fluxo completo da tela.

## Arquivos versionados

Nenhuma alteracao em `db/schema.sql` foi necessaria, pois as tabelas criadas ja estavam declaradas nele.

O commit desta fase deve conter somente esta documentacao.

## Rollback

Se for necessario reverter a correcao de schema, restaurar o backup criado antes da migracao:

```sh
cp db/painel_dns.sqlite.bak-before-schema-fix-20260629 db/painel_dns.sqlite
```

Depois validar:

```sh
sqlite3 db/painel_dns.sqlite "PRAGMA integrity_check;"
sqlite3 db/painel_dns.sqlite ".tables"
```

## Conclusao

O banco restaurado estava sem tabelas usadas pelo firewall e por governanca DNS. As tabelas faltantes foram criadas de forma idempotente com `CREATE TABLE IF NOT EXISTS`, preservando dados existentes e mantendo o banco integro.
