# Fase 5.2 - Wrapper de autenticacao em app

## Objetivo

Criar um wrapper compativel em `app/Auth/auth.php` para preparar a migracao futura da autenticacao, sem substituir o runtime atual.

## Arquivo criado

- `app/Auth/auth.php`

O wrapper carrega:

1. `app/Support/bootstrap.php`
2. `includes/auth.php`

Ambos sao carregados com `require_once`.

## Fonte da verdade atual

`includes/auth.php` continua sendo a fonte da verdade para autenticacao, sessao, timeout, validacao de usuario ativo e redirecionamento de troca obrigatoria de senha.

O wrapper nao redefine funcoes, nao altera sessao e nao muda headers por conta propria. Qualquer efeito atual continua vindo exclusivamente de `includes/auth.php`.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include existente foi alterado.
- Nenhuma logica DNS foi alterada.
- Nenhum comando Bind foi executado.
- Nenhum banco real foi movido ou alterado.
- Nenhum secret foi lido, movido ou alterado.

## Como isso prepara a migracao

Codigo novo podera depender de `app/Auth/auth.php` enquanto paginas e includes antigos continuam usando `includes/auth.php`.

Em fases futuras, esse wrapper pode virar fachada para uma camada de autenticacao em `app/Auth/`, mantendo compatibilidade com o fluxo atual ate que as paginas publicas sejam migradas e testadas.

## Cuidados para proximas fases

- Nao substituir `includes/auth.php` nas paginas publicas sem teste de login/logout.
- Preservar `auth_version`, `trocar_senha`, `usuario_id`, `usuario` e `perfil`.
- Validar timeout de sessao e redirecionamento para `alterar-senha.php`.
- Validar comportamento quando SQLite estiver bloqueado ou indisponivel.
- Nao misturar a migracao de autenticacao com mudancas DNS operacionais.
