# Fase 5.1 - Wrapper de banco em app

## Objetivo

Criar um wrapper compativel em `app/Support/db.php` para preparar a migracao futura da camada de banco, sem substituir o runtime atual.

## Arquivo criado

- `app/Support/db.php`

O wrapper carrega:

1. `app/Support/bootstrap.php`
2. `includes/db.php`

Ambos sao carregados com `require_once`.

## Fonte da verdade atual

`includes/db.php` continua sendo a fonte da verdade para conexao SQLite.

As funcoes atuais seguem definidas somente no include legado:

- `db_conectar()`
- `db()`
- `db_leitura()`

O wrapper nao redefine nenhuma dessas funcoes.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include existente foi alterado.
- Nenhuma logica DNS foi alterada.
- Nenhum comando Bind foi executado.
- Nenhum banco real foi movido ou alterado.
- Nenhum secret foi lido, movido ou alterado.

## Como isso prepara a migracao

Codigo novo podera depender de `app/Support/db.php` enquanto paginas e includes antigos continuam usando `includes/db.php`.

Em fase futura, esse wrapper pode virar fachada para uma camada de conexao em `app/Support/` ou para configuracao centralizada de caminhos em `storage/database/`, mantendo compatibilidade com as funcoes atuais ate a migracao completa.

## Cuidados para proximas fases

- Nao alterar o caminho real do banco sem plano de migracao e rollback.
- Nao mover `db/painel_dns.sqlite` nesta etapa.
- Nao alterar schema durante a criacao de wrappers.
- Nao trocar chamadas existentes de `db()` sem testes das telas de usuarios, auditoria, DNS servers, DNS zones, firewall e servicos.
