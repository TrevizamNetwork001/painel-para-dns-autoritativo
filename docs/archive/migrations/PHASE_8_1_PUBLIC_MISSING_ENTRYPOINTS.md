# Fase 8.1 - Entradas public faltantes

## Objetivo

Adicionar entrypoints faltantes em `public/` antes de qualquer mudanca de document root, mantendo compatibilidade com os arquivos legados da raiz.

## Alteracao aplicada

Foram criados wrappers publicos para:

- `public/auditoria.php`
- `public/delete-record.php`
- `public/delete-ptr.php`
- `public/logs.php`
- `public/security.php`
- `public/fail2ban-bind.php`

Cada arquivo apenas carrega o legado correspondente da raiz com `require_once`.

## O que permaneceu igual

- Nginx/Apache nao foi alterado.
- Document root nao foi alterado.
- Arquivos antigos da raiz nao foram removidos.
- Logica DNS nao foi alterada.
- Reload/restart Bind nao foi executado.
- Banco e secrets nao foram alterados.

## Fonte da verdade

Os arquivos da raiz continuam sendo a implementacao real.

`public/` permanece como camada fina de entrada compativel.

## Validacao esperada

Esta fase deve validar sintaxe dos novos entrypoints e do conjunto PHP, alem de `git diff --check` e `git diff --cached --check`.

## Proximos passos

- repetir a validacao HTTP local de `public/`;
- revisar links e redirects com os entrypoints auxiliares agora presentes;
- manter a troca real de document root para uma fase separada com rollback documentado.
