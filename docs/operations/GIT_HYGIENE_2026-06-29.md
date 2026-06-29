# Git Hygiene - Bind DNS Panel - 2026-06-29

## Objetivo

Executar a Fase 1 de higiene Git sem alterar logica PHP, sem reorganizar diretorios, sem apagar arquivos do disco e sem executar reload/restart do Bind.

## Branch

`chore/git-hygiene-bind`

## Arquivos removidos apenas do rastreamento

Os arquivos abaixo foram removidos do indice Git com `git rm --cached`, permanecendo no disco local:

- `db/painel_dns.sqlite`
- `db/painel_dns.sqlite.bak-20260615-110015`
- `db/dns_servers.secret`
- `db/painel.sqlite`

## Arquivos sensiveis bloqueados

O `.gitignore` passa a bloquear bancos reais, secrets, credenciais, chaves, logs, backups e a imagem de validacao local:

- `*.sqlite`
- `*.sqlite.*`
- `*.secret`
- `.env`
- `*.pem`
- `*.key`
- `id_rsa`
- `id_ed25519`
- `*.log`
- `*.bak`
- `*.backup`
- `db/*.sqlite`
- `db/*.sqlite.*`
- `db/*.secret`
- `ultima_validacao_resumo.png`

## Schema sanitizado

Foi gerado `db/schema.sql` com somente estrutura SQL obtida via:

```sh
sqlite3 db/painel_dns.sqlite .schema > db/schema.sql
```

O arquivo nao contem dados reais, inserts, hashes, credenciais ou registros de auditoria.

## Documentacao adicionada

- `docs/handoff/AUDITORIA_DNS_PANEL_2026-06-29.txt`
- `docs/operations/GIT_HYGIENE_2026-06-29.md`

## Validacoes previstas

- `git status --short`
- `git diff --check`
- `sqlite3 db/painel_dns.sqlite ".schema"`

Como nao houve alteracao de arquivos PHP nesta fase, `php -l` nao e necessario para arquivos modificados.

## Observacoes

- Nenhum arquivo sensivel foi apagado do disco.
- Nenhum reload/restart do Bind foi executado.
- Nenhuma logica PHP foi alterada.
- A reorganizacao de diretorios fica para fases posteriores.
