# Painel DNS Bind Autoritativo

Painel web para administracao de DNS autoritativo com BIND9, auditoria, servidores DNS, zonas, seguranca e instalador seguro.

## Estrutura

- `app/` - codigo interno e controllers.
- `public/` - entrypoints publicos; Apache deve apontar DocumentRoot para esta pasta.
- `includes/` - compatibilidade e funcoes compartilhadas ainda usadas pelo painel.
- `config/` - configuracao sanitizada/versionavel.
- `db/` - schema sanitizado do SQLite.
- `scripts/` - scripts de instalacao, validacao e apoio operacional.
- `docs/` - documentacao atual.
- `storage/` - dados locais nao versionados, incluindo banco, secrets, logs, backups e cache.
- `tests/` - testes e marcadores de teste.

## Avisos

O DocumentRoot deve apontar para `public/`.

`storage/database`, `storage/secrets`, `storage/logs`, `storage/backups` e `storage/cache` nao devem conter dados versionados.

Banco real e secrets reais nunca devem ir para o GitHub.

## Release limpo

```bash
git archive --format=tar.gz -o /tmp/painel-bind-clean.tar.gz HEAD
```

## Instalacao

```bash
sudo bash scripts/install.sh --package /tmp/painel-bind-clean.tar.gz --target /var/www/html/painel
```

## Links

- [docs/INSTALL.md](docs/INSTALL.md)
- [docs/OPERATIONS.md](docs/OPERATIONS.md)
- [docs/SECURITY.md](docs/SECURITY.md)
- [docs/CHECKLIST_INSTALL.md](docs/CHECKLIST_INSTALL.md)
- [docs/CHECKLIST_UPDATE.md](docs/CHECKLIST_UPDATE.md)
