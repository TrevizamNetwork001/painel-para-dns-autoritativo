# Fase 6.3 - Migracao da pagina de login para wrappers app

## Objetivo

Mudar `login.php` para usar wrappers em `app/` sem alterar comportamento, layout, POSTs, sessao, redirects, throttle, mensagens ou logica de login.

## Alteracao aplicada

A pagina passou a carregar:

- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

Todos via `require_once`.

`app/Auth/auth.php` nao foi adicionado porque `login.php` nao carregava `includes/auth.php` antes desta fase. Incluir a autenticacao obrigatoria na tela de login mudaria redirects e fluxo de sessao.

## O que permaneceu igual

- `session_start()`.
- Nomes de funcoes.
- Fluxo `POST`.
- Validacao de CSRF.
- Busca de usuario por login.
- Verificacao de senha.
- Rehash de senha quando necessario.
- Escrita das variaveis de sessao.
- Redirect para `alterar-senha.php` ou `dashboard.php`.
- Mensagens de timeout e falha.
- Auditoria de login com sucesso e falha.
- Layout e markup.
- Scripts da tela.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/users.php` continua definindo a logica de usuarios e busca de login;
- `includes/security.php` continua definindo CSRF e helpers de seguranca;
- `includes/audit.php` continua definindo a auditoria.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outras paginas publicas apenas depois de validar `login.php`;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
