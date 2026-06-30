# Fase 6.2 - Migracao da pagina de alterar senha para wrappers app

## Objetivo

Mudar `alterar-senha.php` para usar wrappers em `app/` sem alterar comportamento, layout, POSTs, CSRF, redirects, sessao ou logica de senha.

## Alteracao aplicada

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

Todos via `require_once`.

## O que permaneceu igual

- Nomes de funcoes.
- Fluxo `POST`.
- Validacao da senha atual.
- Validacao da nova senha.
- Confirmacao de senha.
- Atualizacao de `auth_version` e `trocar_senha`.
- CSRF.
- Redirects.
- Sessao e encerramento de sessao apos troca.
- Layout e markup.
- Escrita no banco.
- Auditoria.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/users.php` continua definindo a logica de usuarios e validacao de senha;
- `includes/security.php` continua definindo CSRF e helpers de seguranca;
- `includes/audit.php` continua definindo a auditoria.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outras paginas autenticadas apenas depois de validar `alterar-senha.php`;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
