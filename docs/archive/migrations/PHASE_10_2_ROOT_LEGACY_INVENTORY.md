# Fase 10.2 - Inventario dos arquivos legados da raiz

## Objetivo

Mapear os PHPs da raiz do projeto antes de qualquer remocao, sem alterar o comportamento do painel.

## Como o inventario foi montado

- listagem dos PHPs na raiz com `rg --files -g '*.php'`
- cruzamento com entradas em `public/*.php`
- verificacao de quais arquivos ainda sao carregados por wrappers de `public/`

## Arquivos PHP na raiz

| Arquivo | Entrada em `public/` | Carregado por `public/*.php` | Classificacao |
| --- | --- | --- | --- |
| `acl.php` | sim | sim | pode ser removido depois |
| `acl6.php` | sim | sim | pode ser removido depois |
| `alterar-senha.php` | sim | sim | pode ser removido depois |
| `auditoria.php` | sim | sim | pode ser removido depois |
| `bind.php` | sim | sim | pode ser removido depois |
| `config.php` | nao | nao | pode virar app/ |
| `dashboard.php` | sim | sim | pode ser removido depois |
| `delete-ptr.php` | sim | sim | pode ser removido depois |
| `delete-record.php` | sim | sim | pode ser removido depois |
| `dns-servers.php` | sim | sim | pode ser removido depois |
| `dns-zones.php` | sim | sim | pode ser removido depois |
| `domains.php` | sim | sim | pode ser removido depois |
| `edit-ptr.php` | sim | sim | pode ser removido depois |
| `edit-record.php` | sim | sim | pode ser removido depois |
| `edit-reverse-zone.php` | sim | sim | pode ser removido depois |
| `edit-zone.php` | sim | sim | pode ser removido depois |
| `fail2ban-bind.php` | sim | sim | pode ser removido depois |
| `fail2ban.php` | sim | sim | pode ser removido depois |
| `firewall.php` | sim | sim | pode ser removido depois |
| `index.php` | sim | sim | pode ser removido depois |
| `login.php` | sim | sim | pode ser removido depois |
| `logs.php` | sim | sim | pode ser removido depois |
| `logout.php` | sim | sim | pode ser removido depois |
| `reverse-zones.php` | sim | sim | pode ser removido depois |
| `security.php` | sim | sim | pode ser removido depois |
| `services.php` | sim | sim | pode ser removido depois |
| `servidores-dns.php` | nao | nao | manter temporariamente |
| `ssh.php` | sim | sim | pode ser removido depois |
| `usuarios.php` | sim | sim | pode ser removido depois |
| `zones.php` | sim | sim | pode ser removido depois |

## Resumo por categoria

### Manter temporariamente

- `servidores-dns.php`

Motivo: e um alias legado direto para `dns-servers.php` e ainda pode existir dependencia externa ou bookmark antigo.

### Pode virar app/

- `config.php`

Motivo: e um arquivo de suporte, nao uma pagina, e tem perfil de utilitario interno para migracao futura.

### Pode ser removido depois

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
- `logs.php`
- `logout.php`
- `reverse-zones.php`
- `security.php`
- `services.php`
- `ssh.php`
- `usuarios.php`
- `zones.php`

Motivo: ja possuem entrada compatível em `public/`, mas continuam como fonte atual dos wrappers.

### Sensivel/backup

- nenhum PHP da raiz foi classificado aqui nesta fase

Os artefatos sensiveis do ambiente permanecem fora desse inventario, principalmente em `storage/`, `db/` e paths operacionais fora da raiz.

## Verificacao dos wrappers public

As entradas em `public/` carregam os arquivos legados da raiz com `require_once`, por exemplo:

- `public/login.php` -> `login.php`
- `public/dashboard.php` -> `dashboard.php`
- `public/dns-servers.php` -> `dns-servers.php`
- `public/zones.php` -> `zones.php`

## Leitura pratica

O corte seguro daqui e:

1. manter `servidores-dns.php` ate confirmar que nada externo depende dele;
2. mover `config.php` para um suporte em `app/` quando houver substituto equivalente;
3. retirar os controllers da raiz apenas depois que os wrappers `public/` deixarem de depender deles.

## Risco principal

Se algum arquivo da raiz for removido antes de os wrappers `public/` migrarem para um alvo novo, o painel continua abrindo, mas os includes da camada de entrada quebram.
