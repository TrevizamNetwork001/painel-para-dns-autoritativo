# Fase 5.4 - Wrapper de servidores DNS em app

## Objetivo

Criar um wrapper compativel em `app/DnsServers/servers.php` para preparar a migracao futura do modulo de servidores DNS, sem substituir o runtime atual.

## Arquivo criado

- `app/DnsServers/servers.php`

O wrapper carrega:

1. `app/Support/bootstrap.php`
2. `includes/dns_servers.php`

Ambos sao carregados com `require_once`.

## Fonte da verdade atual

`includes/dns_servers.php` continua sendo a fonte da verdade para cadastro de servidores DNS, credenciais administrativas, SSH, agente remoto, diagnosticos, status e comandos de zona.

O wrapper nao redefine funcoes existentes e nao executa comandos por conta propria. Qualquer efeito operacional continua vindo exclusivamente das chamadas atuais feitas pelas paginas existentes.

## O que nao mudou

- Nenhuma pagina publica foi alterada.
- Nenhum include existente foi alterado.
- Nenhuma logica DNS foi alterada.
- Nenhum reload/restart do Bind foi executado.
- Nenhum banco real foi movido ou alterado.
- Nenhum secret foi lido, movido ou alterado.
- Nenhum comando SSH, sudo, SCP ou agente remoto foi executado.

## Como isso prepara a migracao

Codigo novo podera depender de `app/DnsServers/servers.php` enquanto paginas e includes antigos continuam usando `includes/dns_servers.php`.

Em fases futuras, esse wrapper pode virar fachada para uma camada em `app/DnsServers/`, mantendo compatibilidade com as funcoes atuais ate que as paginas publicas sejam migradas e testadas.

## Cuidados para proximas fases

- Nao substituir `includes/dns_servers.php` nas paginas publicas sem testes administrativos completos.
- Separar primeiro validadores e formatadores sem efeitos colaterais.
- Migrar criptografia/credenciais apenas com plano para `storage/secrets/`.
- Migrar SSH/SCP/proc_open apenas depois de encapsular execucao de comandos.
- Nao executar bootstrap, instalacao/remocao de agente ou migracao de layout fora de fluxo explicitamente autorizado.
- Manter auditoria obrigatoria para operacoes em servidores DNS.
