# Fase 7 - Entradas public compativeis

## Objetivo

Preparar `public/` como camada de entrada compativel, sem alterar Nginx/Apache, sem mudar document root e sem remover arquivos antigos da raiz.

## Alteracao aplicada

Foram criados entrypoints em `public/` para as paginas principais do painel.

Cada arquivo `public/*.php` apenas carrega o arquivo legado correspondente da raiz com `require_once`.

## Entradas criadas

- `public/login.php`
- `public/logout.php`
- `public/index.php`
- `public/dashboard.php`
- `public/usuarios.php`
- `public/alterar-senha.php`
- `public/zones.php`
- `public/dns-zones.php`
- `public/reverse-zones.php`
- `public/domains.php`
- `public/edit-zone.php`
- `public/edit-record.php`
- `public/edit-reverse-zone.php`
- `public/edit-ptr.php`
- `public/dns-servers.php`
- `public/services.php`
- `public/bind.php`
- `public/firewall.php`
- `public/fail2ban.php`
- `public/ssh.php`
- `public/acl.php`
- `public/acl6.php`

Tambem foi criado `public/assets/.gitkeep` para reservar o diretorio de assets sem mover arquivos nesta fase.

## O que permaneceu igual

- Arquivos antigos continuam na raiz.
- Document root atual permanece inalterado.
- Configuracao Nginx/Apache permanece inalterada.
- Logica DNS permanece inalterada.
- Comandos operacionais permanecem inalterados.
- Banco e secrets permanecem inalterados.
- Wrappers `app/` continuam apontando para os includes legados.

## Fonte da verdade

As paginas da raiz continuam sendo a implementacao real carregada pelos entrypoints `public/`.

A logica compartilhada continua em `includes/`, via wrappers `app/` criados nas fases anteriores.

## Validacao esperada

Esta fase deve ser uma preparacao estrutural. Qualquer divergencia funcional deve ser tratada como problema de caminho ou document root, nao como mudanca de regra de negocio.

## Proximos passos

- revisar links relativos e redirects antes de trocar document root;
- decidir estrategia para `config.php`, `includes/footer.php` e `includes/session-timeout.php`;
- testar `public/` em ambiente controlado ou alias antes de alterar servidor web;
- preparar rollback de vhost/site antes da troca real de document root.
