# Fase 6.8 - Migracao das telas de servicos e firewall para wrappers app

## Objetivo

Mudar `services.php`, `bind.php`, `firewall.php`, `fail2ban.php`, `ssh.php`, `acl.php` e `acl6.php` para usar wrappers em `app/` sem alterar logica operacional, POSTs, CSRF, confirmacoes, comandos, sudo, nft, systemctl, rndc ou redirects.

## Alteracao aplicada

### `services.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Support/db.php`

### `firewall.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

### `bind.php`, `fail2ban.php` e `ssh.php`

As paginas passaram a carregar:

- `app/Auth/auth.php`

### `acl.php` e `acl6.php`

As paginas passaram a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`

O carregamento de `config.php` e `includes/footer.php` permaneceu igual porque nao faz parte dos wrappers equivalentes desta fase.

## O que permaneceu igual

- Nomes de funcoes.
- Fluxos `POST`.
- CSRF.
- Confirmacoes.
- Redirects.
- Layout e markup.
- Comandos existentes de servicos.
- Comandos `sudo`, `nft`, `systemctl` e `rndc`.
- Leitura de logs.
- Aplicacao e rollback de firewall.
- Escrita segura de ACLs.
- Auditoria.
- Uso de banco em `services.php`.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/security.php` continua definindo CSRF, validadores, escrita segura e helpers de seguranca;
- `includes/audit.php` continua definindo a auditoria;
- `includes/db.php` continua definindo a conexao com banco.

## Escopo operacional

Esta fase nao executa comandos, nao executa reload/restart Bind, nao aplica firewall, nao reinicia servicos, nao altera banco, nao altera secrets e nao muda comandos `sudo`, `nft`, `systemctl` ou `rndc`.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- validar manualmente as telas em janela controlada antes de acionar comandos operacionais;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS operacional nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
