# Handoff - Reestruturacao Bind - Fase 6 final

## Objetivo

Registrar o fechamento da Fase 6, que migrou as principais paginas publicas do painel para wrappers em `app/`, mantendo os includes legados como fonte da verdade.

## Commits da Fase 6

- `9500b0d` `refactor: migra usuarios para wrappers app`
- `83dfdf4` `refactor: migra alterar senha para wrappers app`
- `7b384d2` `refactor: migra login para wrappers app`
- `ef6cf31` `refactor: migra auth e dashboard para wrappers app`
- `8a2ba2a` `refactor: migra telas dns visuais para wrappers app`
- `179a0c9` `refactor: migra telas dns operacionais para wrappers app`
- `c1ca212` `docs: adiciona handoff parcial da fase 6`
- `5798183` `refactor: migra servidores dns para wrappers app`
- `0e9bfcb` `refactor: migra servicos e firewall para wrappers app`

## Paginas migradas para wrappers app

### Usuarios, auth e dashboard

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

### Servidores DNS

- `dns-servers.php`
- `servidores-dns.php` continua delegando para `dns-servers.php`

### Servicos, logs operacionais e firewall

- `services.php`
- `bind.php`
- `firewall.php`
- `fail2ban.php`
- `ssh.php`
- `acl.php`
- `acl6.php`

## Wrappers usados por grupo

### Auth e usuarios

- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

### Dashboard

- `app/Auth/auth.php`
- `app/Support/db.php`
- `app/Dns/zones.php`
- `app/DnsServers/servers.php`

### DNS visual/leitura

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Dns/zones.php`

### DNS operacional

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`

### Servidores DNS

- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/DnsServers/servers.php`
- `app/Dns/zones.php`

### Servicos e firewall

- `app/Auth/auth.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Support/db.php`

## Validacoes executadas na Fase 6

Em cada etapa, foram executadas validacoes de sintaxe e diff conforme o escopo da fase:

- `php -l` nos arquivos migrados da etapa.
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`.
- `git diff --check`.
- `git diff --cached --check` antes dos commits de migracao.
- `git status --short` limpo apos os commits.

No handoff parcial e neste fechamento documental, a validacao solicitada foi:

- `git diff --check`.
- `git status --short`.

## Fonte da verdade atual

Os wrappers em `app/` continuam sendo camadas de compatibilidade.

A logica legada segue em `includes/`:

- `includes/auth.php`
- `includes/users.php`
- `includes/security.php`
- `includes/db.php`
- `includes/audit.php`
- `includes/dns_zones.php`
- `includes/dns_servers.php`

A Fase 6 trocou pontos de entrada nas paginas, mas nao moveu implementacao, nao alterou contratos de funcoes, nao mudou fluxos `POST`, CSRF, redirects, sessoes, comandos operacionais, banco ou secrets.

## Pendencias antes da Fase 7

### Paginas auxiliares ainda com includes legados diretos

- `auditoria.php`
- `delete-record.php`
- `delete-ptr.php`
- `logs.php`
- `security.php`
- `fail2ban-bind.php`

Essas paginas devem ser avaliadas antes de qualquer mudanca de document root, porque ainda apontam diretamente para `includes/`.

### Includes de layout e timeout

Varias paginas migradas ainda carregam:

- `includes/footer.php`
- `includes/session-timeout.php`

Esses arquivos nao fizeram parte dos wrappers da Fase 6. Antes de mover paginas para `public/`, decidir se continuam como includes legados, se ganham wrapper em `app/View/` ou se passam a morar em outro caminho compartilhado.

### `config.php`

Varias telas ainda carregam `config.php` diretamente. A Fase 6 nao criou wrapper para configuracao. Antes da Fase 7, confirmar como esse arquivo sera resolvido quando o document root mudar.

### Validacao funcional

Ainda e recomendado validar manualmente em janela controlada:

- login, logout e troca de senha;
- usuarios;
- dashboard;
- inventario DNS;
- telas DNS operacionais;
- servidores DNS, sem executar comandos remotos fora de janela aprovada;
- servicos e firewall, sem aplicar/reiniciar fora de janela aprovada.

## Proximos passos - Fase 7

### Preparar `public/`

- Definir quais arquivos entram em `public/`.
- Manter `app/`, `includes/`, `docs/`, banco e secrets fora do document root publico.
- Definir caminho compativel para `config.php`, footer e session timeout.
- Validar links relativos, redirects e assets antes de mover o document root.

### Planejar document root

- Criar uma fase de preparacao sem trocar o servidor web primeiro.
- Testar `public/` em ambiente paralelo ou alias controlado.
- Confirmar que `__DIR__`, caminhos relativos e includes continuam resolvendo corretamente.
- Somente depois alterar o document root do servidor web.
- Ter plano de rollback do vhost/site antes da troca.

## Observacoes finais

A Fase 6 nao deve ter executado reload/restart Bind, comandos remotos, aplicacao de firewall, restart de servicos, alteracao de banco, movimentacao de secrets ou troca de document root.

O estado final esperado e uma camada de paginas apontando para wrappers `app/`, com a implementacao real ainda preservada nos includes legados ate as proximas fases.
