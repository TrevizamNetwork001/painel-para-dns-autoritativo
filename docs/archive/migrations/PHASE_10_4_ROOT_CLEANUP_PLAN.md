# Fase 10.4 - Plano de limpeza dos controllers legados da raiz

## Objetivo

Preparar a remocao futura dos controllers PHP soltos na raiz, sem apagar nada nesta fase.

## Controllers da raiz que ja possuem `public/*.php`

Os wrappers em `public/` continuam apontando para os controllers legados da raiz com `require_once`:

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

## Confirmacao do comportamento atual

Cada arquivo em `public/*.php` continua sendo uma camada fina de entrada que carrega o controller legado correspondente da raiz.

Exemplo:

- `public/login.php` -> `require_once __DIR__ . '/../login.php';`
- `public/dashboard.php` -> `require_once __DIR__ . '/../dashboard.php';`
- `public/zones.php` -> `require_once __DIR__ . '/../zones.php';`

## Estrategia final proposta

1. mover os controllers reais da raiz para `app/LegacyControllers/`
2. atualizar `public/*.php` para apontar para `app/LegacyControllers/*.php`
3. manter `public/` como camada de entrada compatível
4. remover os controllers soltos da raiz somente depois que a camada nova estiver validada

## Por que essa ordem

O corte em duas etapas evita quebrar as entradas publicas durante a troca:

- primeiro a logica muda de lugar
- depois a raiz fica livre para limpeza

## Riscos

- se algum wrapper em `public/` apontar para um controller removido antes da troca para `app/LegacyControllers/`, a pagina quebra imediatamente
- arquivos antigos fora de `public/` podem continuar sendo chamados por marcadores, favoritos ou scripts externos
- alguns arquivos da raiz ainda servem como referencia historica e backup, entao a remocao precisa ser controlada

## Rollback

Se a migracao futura falhar, o rollback mais simples e restaurar os `require_once` de `public/*.php` para os controllers da raiz.

Enquanto a raiz ainda existir como origem, o rollback nao exige mudanca de Apache, Nginx, DNS ou banco.

## Proximo passo

Antes de remover qualquer controller da raiz, criar a arvore `app/LegacyControllers/` e validar os wrappers novos com lint e navegacao sem sessao.
