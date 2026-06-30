# Fase 6.1 - Migracao da pagina de usuarios para wrappers app

## Objetivo

Mudar `usuarios.php` para usar wrappers em `app/` sem alterar comportamento, layout, POSTs, CSRF, redirects ou logica de usuarios.

## Alteracao aplicada

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

Todos via `require_once`.

## O que permaneceu igual

- Nomes de funcoes.
- Fluxos `POST`.
- Validacoes de usuario, senha e perfil.
- Regras de administrador.
- CSRF.
- Redirects e flash messages.
- Layout e markup.
- Escrita no banco.
- Auditoria.

## Fonte da verdade

Os wrappers em `app/` ainda são camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/users.php` continua definindo a logica de usuarios;
- `includes/security.php` continua definindo CSRF e helpers de seguranca;
- `includes/audit.php` continua definindo a auditoria.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudança de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outras paginas administrativas apenas depois de validar `usuarios.php`;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
