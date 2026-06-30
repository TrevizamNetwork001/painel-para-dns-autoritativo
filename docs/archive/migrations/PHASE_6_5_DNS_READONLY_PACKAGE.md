# Fase 6.5 - Migracao do pacote DNS visual/leitura para wrappers app

## Objetivo

Mudar `zones.php`, `dns-zones.php` e `reverse-zones.php` para usar wrappers em `app/` sem alterar logica DNS, POSTs destrutivos, comandos `named`/`rndc`, redirects, sessao, layout ou mensagens.

## Alteracao aplicada

### `zones.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Dns/zones.php`

### `dns-zones.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

### `reverse-zones.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

O carregamento de `config.php`, `includes/footer.php` e `includes/session-timeout.php` permaneceu igual porque nao faz parte dos wrappers equivalentes desta fase.

## O que permaneceu igual

- Nomes de funcoes.
- Fluxos `POST`.
- Validacao de CSRF.
- Redirects e mensagens.
- Sessao.
- Layout e markup.
- Leitura de arquivos de zona.
- Inventario visual de zonas.
- Remocao controlada ja existente nas telas.
- Chamadas existentes a `named-checkconf`.
- Chamadas existentes a `reload_dns()`.
- Auditoria.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/security.php` continua definindo CSRF, validadores e `reload_dns()`;
- `includes/audit.php` continua definindo a auditoria;
- `includes/dns_zones.php` continua definindo o inventario e sincronizacao de zonas DNS usados por `zones.php`.

## Escopo operacional

Esta fase nao executa reload/restart Bind, nao altera DNS operacional, nao altera banco, nao altera secrets e nao muda comandos `named`/`rndc`.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outras telas DNS apenas depois de validar `zones.php`, `dns-zones.php` e `reverse-zones.php`;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS operacional nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
