# Handoff - Reestruturacao Bind - Fase 6 parcial

## Objetivo

Registrar o estado parcial da Fase 6 antes de migrar telas de maior risco operacional, especialmente `dns-servers.php`, `firewall.php` e `services.php`.

## Commits da Fase 6 ate agora

- `9500b0d` `refactor: migra usuarios para wrappers app`
- `83dfdf4` `refactor: migra alterar senha para wrappers app`
- `7b384d2` `refactor: migra login para wrappers app`
- `ef6cf31` `refactor: migra auth e dashboard para wrappers app`
- `8a2ba2a` `refactor: migra telas dns visuais para wrappers app`
- `179a0c9` `refactor: migra telas dns operacionais para wrappers app`

## Paginas migradas para wrappers app

### Auth, usuarios e dashboard

- `usuarios.php`
- `alterar-senha.php`
- `login.php`
- `logout.php`
- `index.php`
- `dashboard.php`

### DNS visual/leitura

- `zones.php`
- `dns-zones.php`
- `reverse-zones.php`

### DNS operacional

- `domains.php`
- `edit-zone.php`
- `edit-record.php`
- `edit-reverse-zone.php`
- `edit-ptr.php`

## Wrappers usados

- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Support/db.php`
- `app/Audit/audit.php`
- `app/Dns/zones.php`
- `app/DnsServers/servers.php`

## Fonte da verdade atual

Os wrappers em `app/` continuam sendo camadas de compatibilidade.

Os includes legados continuam sendo a fonte da verdade do runtime:

- `includes/auth.php`
- `includes/users.php`
- `includes/security.php`
- `includes/db.php`
- `includes/audit.php`
- `includes/dns_zones.php`
- `includes/dns_servers.php`

As migracoes da Fase 6 trocaram pontos de entrada nas paginas, mas nao moveram implementacao, nao mudaram contratos de funcoes e nao substituiram a logica interna dos includes legados.

## Paginas ainda pendentes

### Prioridade imediata citada para a proxima etapa

- `dns-servers.php`
- `firewall.php`
- `services.php`

### Outras paginas ainda com includes legados diretos

- `auditoria.php`
- `delete-record.php`
- `delete-ptr.php`
- `acl.php`
- `acl6.php`
- `bind.php`
- `fail2ban.php`
- `fail2ban-bind.php`
- `ssh.php`
- `logs.php`
- `security.php`

`servidores-dns.php` continua delegando para `dns-servers.php`, portanto deve ser revalidado junto com a migracao de `dns-servers.php`.

Includes de apresentacao e timeout, como `includes/footer.php` e `includes/session-timeout.php`, ainda aparecem em paginas ja migradas. Eles nao foram parte do escopo dos wrappers app desta fase.

## Riscos antes de migrar `dns-servers.php`

- A tela concentra cadastro de servidores DNS, credenciais administrativas, chaves SSH, senhas, sudo/root alternativo e comandos remotos.
- O arquivo usa `includes/dns_servers.php` e `includes/dns_zones.php`; qualquer mudanca de ordem de carregamento pode afetar funcoes de inventario, agente remoto e operacoes SSH.
- Ha fluxos `POST` com CSRF, modais, respostas JSON e visualizacao de credenciais salvas.
- A migracao deve trocar apenas includes diretos por wrappers equivalentes e validar que nenhum secret seja exibido, alterado ou regravado fora do fluxo existente.
- Nao executar instalacao, remocao, diagnostico remoto, sincronizacao ou comandos de agente durante a migracao.

## Riscos antes de migrar `firewall.php`

- A tela possui fluxo operacional de aplicacao e rollback de regras de firewall.
- Existem confirmacoes explicitas, validacoes de redes/portas, backups e comandos sensiveis.
- A migracao nao pode alterar POSTs, nomes de campos, mensagens, confirmacoes, comandos, validacoes ou ordem de execucao.
- Validar apenas sintaxe e diff; nao aplicar firewall, nao executar comandos de aplicacao e nao testar rollback real sem janela operacional.

## Riscos antes de migrar `services.php`

- A tela controla status e acoes de servicos do sistema.
- O arquivo inclui `db`, `auth`, `security` e `audit`, e pode executar comandos de servico.
- A migracao deve preservar CSRF, auditoria, redirects, mensagens e todos os comandos existentes.
- Nao executar restart/reload/start/stop de servicos durante a migracao.
- Confirmar se o wrapper `app/Support/db.php` deve substituir o include direto de banco sem alterar a ordem atual de carga.

## Validacao recomendada para a proxima etapa

- Lint individual dos arquivos migrados.
- Lint geral de PHP.
- `git diff --check`.
- `git diff --cached --check`.
- Revisao manual do diff para confirmar que apenas paths de includes e documentacao foram alterados.
- `git status --short` limpo ao final.

## Observacoes

Nenhuma etapa da Fase 6 ate este checkpoint deve ter executado reload/restart Bind, alterado banco, movido secrets ou mudado o document root.
