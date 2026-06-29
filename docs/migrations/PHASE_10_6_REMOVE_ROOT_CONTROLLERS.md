# Fase 10.6 - Remocao dos controllers legados da raiz

## Objetivo

Finalizar a migracao dos controllers legados para `app/LegacyControllers/` e remover os controllers soltos da raiz.

## O que mudou nesta fase

- os arquivos em `app/LegacyControllers/*.php` deixaram de ser symlinks
- os controllers foram copiados como arquivos reais
- os controllers legados da raiz, que ja tinham entrada em `public/`, foram removidos

## Estrutura mantida na raiz

Continuam na raiz apenas os arquivos que ainda sao necessarios:

- `config.php`
- `servidores-dns.php`
- arquivos de documentacao, backups e outros artefatos nao-controller

## Controllers removidos da raiz

- `acl.php`
- `acl6.php`
- `alterar-senha.php`
- `auditoria.php`
- `bind.php`
- `dashboard.php`
- `delete-ptr.php`
- `delete-record.php`
- `dns-servers.php`
- `dns-zones.php`
- `domains.php`
- `edit-ptr.php`
- `edit-record.php`
- `edit-reverse-zone.php`
- `edit-zone.php`
- `fail2ban-bind.php`
- `fail2ban.php`
- `firewall.php`
- `index.php`
- `login.php`
- `logout.php`
- `logs.php`
- `reverse-zones.php`
- `security.php`
- `services.php`
- `ssh.php`
- `usuarios.php`
- `zones.php`

## Validacao estrutural

- `find app/LegacyControllers -type l` nao retornou symlinks
- `public/*.php` segue apontando para `app/LegacyControllers/*.php`

## Validacoes executadas

- `find app/LegacyControllers -name "*.php" -print0 | xargs -0 -n1 php -l`
- `find public -name "*.php" -print0 | xargs -0 -n1 php -l`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `curl` sem sessao para:
  - `/login.php`
  - `/dashboard.php`
  - `/dns-servers.php`
  - `/zones.php`
  - `/firewall.php`
- `git diff --check`

## Observacoes

O `config.php` da raiz foi mantido por compatibilidade. O conteudo efetivo da configuracao segue em `config/app.php`, e a arvore em `app/LegacyControllers/` guarda uma copia local para os controllers legados continuarem funcionando sem alterar a logica interna.

## Rollback

Rollback simples via Git:

1. restaurar os controllers removidos da raiz
2. reverter a arvore em `app/LegacyControllers/`
3. manter `config.php` e `public/` como estao ate a validacao final da nova estrutura
