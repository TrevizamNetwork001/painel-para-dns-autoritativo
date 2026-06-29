# Fase 5.5 - Wrappers de usuarios e seguranca em app

## Objetivo

Criar wrappers compativeis para usuarios e seguranca, preparando migracao futura sem substituir o runtime atual.

## Arquivos criados

- `app/Auth/users.php`
- `app/Support/security.php`

## Includes carregados

### `app/Auth/users.php`

Carrega:

1. `app/Support/bootstrap.php`
2. `includes/users.php`

### `app/Support/security.php`

Carrega:

1. `app/Support/bootstrap.php`
2. `includes/security.php`

Foi verificado que `includes/csrf.php` nao existe no projeto. O include de seguranca encontrado e usado foi `includes/security.php`.

## Fonte da verdade atual

`includes/users.php` continua sendo a fonte da verdade para usuarios, perfis e validacoes de administrador.

`includes/security.php` continua sendo a fonte da verdade para CSRF, validadores, escrita segura, validacao de zona e helpers DNS/seguranca.

Os wrappers nao redefinem funcoes existentes e nao executam comandos por conta propria.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include existente foi alterado.
- Nenhuma logica DNS foi alterada.
- Nenhum comando Bind foi executado.
- Nenhum banco real foi movido ou alterado.
- Nenhum secret foi lido, movido ou alterado.

## Cuidados para proximas fases

- Nao substituir `includes/users.php` em paginas publicas sem testar login, usuarios e troca de senha.
- Nao substituir `includes/security.php` sem revisar `reload_dns()`, `write_file_safely()` e validacoes de zona.
- Separar futuramente CSRF/validadores puros dos helpers com efeitos operacionais.
- Manter `require_csrf()` e `csrf_field()` compativeis ate migracao completa das telas.
