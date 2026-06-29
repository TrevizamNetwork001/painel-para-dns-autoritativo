# Fase 6.4 - Migracao do pacote Auth/Dashboard para wrappers app

## Objetivo

Mudar `logout.php`, `index.php` e `dashboard.php` para usar wrappers em `app/` sem alterar comportamento, layout, POSTs, redirects, sessao ou mensagens.

## Alteracao aplicada

### `logout.php`

A pagina passou a carregar:

- `app/Audit/audit.php`

### `index.php`

A pagina passou a carregar:

- `app/Auth/auth.php`

### `dashboard.php`

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/db.php`
- `app/Dns/zones.php`
- `app/DnsServers/servers.php`

O carregamento de `config.php`, `includes/footer.php` e `includes/session-timeout.php` permaneceu igual porque nao faz parte dos wrappers equivalentes desta fase.

## O que permaneceu igual

- Nomes de funcoes.
- Sessao e encerramento de sessao.
- Redirects.
- Mensagens.
- Layout e markup.
- Consultas e calculos do dashboard.
- Leitura de metricas locais.
- Leitura de inventario e servidores DNS pelo dashboard.
- Auditoria de logout.
- Rodape e script de timeout de sessao.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/db.php` continua definindo a conexao com banco;
- `includes/audit.php` continua definindo a auditoria;
- `includes/dns_zones.php` continua definindo o inventario de zonas DNS;
- `includes/dns_servers.php` continua definindo a listagem/status de servidores DNS.

## Escopo operacional

Esta fase nao executa reload/restart Bind, nao altera DNS operacional, nao altera banco, nao altera secrets e nao muda comandos administrativos.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- aplicar a mesma estrategia em outros pacotes pequenos apenas depois de validar login, logout, index e dashboard;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS operacional nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
