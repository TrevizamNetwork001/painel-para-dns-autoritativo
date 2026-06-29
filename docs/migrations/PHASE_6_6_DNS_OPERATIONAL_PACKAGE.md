# Fase 6.6 - Migracao do pacote DNS operacional para wrappers app

## Objetivo

Mudar `domains.php`, `edit-zone.php`, `edit-record.php`, `edit-reverse-zone.php` e `edit-ptr.php` para usar wrappers em `app/` sem alterar logica DNS, POSTs, CSRF, confirmacoes, redirects, comandos, backups ou rollback.

## Alteracao aplicada

As paginas passaram a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

Todos substituem os includes diretos equivalentes:

- `includes/auth.php`
- `includes/security.php`
- `includes/audit.php`

O carregamento de `config.php`, `includes/footer.php` e `includes/session-timeout.php` permaneceu igual porque nao faz parte dos wrappers equivalentes desta fase.

## Arquivos migrados

- `domains.php`
- `edit-zone.php`
- `edit-record.php`
- `edit-reverse-zone.php`
- `edit-ptr.php`

## O que permaneceu igual

- Nomes de funcoes.
- Fluxos `POST`.
- CSRF.
- Confirmacoes.
- Redirects.
- Layout e markup.
- Validacoes de zona, registro e PTR.
- Escrita segura de arquivos.
- Chamadas existentes a `named-checkconf`.
- Chamadas existentes a `reload_dns()`.
- Backups e rollback.
- Auditoria.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/security.php` continua definindo CSRF, validadores, escrita segura, validacao de zona e `reload_dns()`;
- `includes/audit.php` continua definindo a auditoria.

## Escopo operacional

Esta fase nao executa reload/restart Bind, nao altera DNS operacional, nao altera banco, nao altera secrets e nao muda comandos `named`/`rndc`.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outras telas operacionais apenas depois de validar o pacote DNS operacional;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS operacional nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
