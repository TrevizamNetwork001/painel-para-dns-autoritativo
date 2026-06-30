# Fase 10.5 - Move dos controllers legados para `app/LegacyControllers`

## Objetivo

Desacoplar as entradas publicas dos controllers da raiz, mantendo o comportamento interno intacto.

## O que foi feito

- criado `app/LegacyControllers/`
- `public/*.php` passou a carregar `app/LegacyControllers/*.php`
- os arquivos em `app/LegacyControllers/` funcionam como ponte compatível para os controllers legados

## Controllers cobertos

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

## Como a compatibilidade foi preservada

Os controllers originais da raiz permanecem sem alteracao interna.

`app/LegacyControllers/` foi criado como camada de transicao para manter:

- `__DIR__` funcionando como antes
- includes relativos intactos
- rollback simples via Git

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

## Riscos

- se um wrapper em `public/` apontar para um arquivo ausente em `app/LegacyControllers/`, a pagina quebra
- se a ponte for removida antes de a proxima etapa concluir a limpeza da raiz, o acesso publico cai
- arquivos de backup na raiz continuam existindo e podem gerar confusao durante auditorias futuras

## Rollback

Rollback imediato:

1. restaurar os `public/*.php` para apontarem para a raiz
2. remover ou ignorar a ponte em `app/LegacyControllers/`

## Proximo passo

Depois desta transicao, a limpeza da raiz pode seguir para remocao controlada dos controllers soltos quando nao houver mais dependencia direta.
