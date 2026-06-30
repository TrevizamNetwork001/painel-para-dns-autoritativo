# Fase 6.7 - Migracao da pagina de servidores DNS para wrappers app

## Objetivo

Mudar `dns-servers.php` para usar wrappers em `app/` sem alterar logica NS2/SSH, POSTs, CSRF, confirmacoes, criptografia, credenciais, scripts, redirects, sessao, layout ou mensagens.

## Alteracao aplicada

A pagina passou a carregar:

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Auth/users.php`
- `app/DnsServers/servers.php`
- `app/Dns/zones.php`

Todos substituem os includes diretos equivalentes:

- `includes/auth.php`
- `includes/security.php`
- `includes/audit.php`
- `includes/users.php`
- `includes/dns_servers.php`
- `includes/dns_zones.php`

O carregamento de `includes/session-timeout.php` permaneceu igual porque nao faz parte dos wrappers equivalentes desta fase.

## O que permaneceu igual

- Nomes de funcoes.
- Fluxos `POST`.
- CSRF.
- Confirmacoes.
- Redirects.
- Layout e markup.
- Respostas JSON.
- Cadastro, atualizacao e remocao de servidores.
- Logica NS2/SSH.
- Criptografia e tratamento de credenciais.
- Scripts e comandos remotos existentes.
- Diagnosticos e testes existentes.
- Auditoria.

## Fonte da verdade

Os wrappers em `app/` ainda sao camadas de compatibilidade.

Neste passo:

- `includes/auth.php` continua definindo a autenticacao real;
- `includes/security.php` continua definindo CSRF e helpers de seguranca;
- `includes/audit.php` continua definindo a auditoria;
- `includes/users.php` continua definindo usuarios e perfis;
- `includes/dns_servers.php` continua definindo cadastro, credenciais, SSH, agente remoto e comandos de servidores DNS;
- `includes/dns_zones.php` continua definindo inventario e sincronizacao de zonas usados pela tela.

## Escopo operacional

Esta fase nao executa comandos remotos, nao executa reload/restart Bind, nao altera DNS operacional, nao altera banco, nao altera secrets e nao muda criptografia ou armazenamento de credenciais.

## Validacao esperada

Esta migracao deve manter o comportamento identico. Se houver divergencia, o problema deve ser tratado como regressao de carga de includes, e nao como mudanca de regra de negocio.

## Proximos passos

- validar manualmente a tela em janela controlada antes de acionar diagnosticos, agente remoto ou operacoes SSH;
- manter wrappers como fachada ate os includes legados serem substituidos por modulos em `app/`;
- nao tocar em DNS operacional nesta fase;
- nao mover banco/secrets nesta fase;
- nao alterar o document root nesta fase.
