# Seguranca do Release

## Arquivos Proibidos no Git e no Release

- `*.sqlite`
- `*.db`
- `*.secret`
- `.env`
- `*.pem`
- `*.key`
- `*.bak`
- `*.bkp`
- `*.backup`
- `*.save`
- `*.log`
- `storage/database/*`
- `storage/secrets/*`
- `storage/logs/*`
- `storage/backups/*`
- `storage/cache/*`

A unica excecao aceita nesses diretorios e `.gitkeep`.

## Politica de Secrets

- Secrets devem ser gerados no ambiente instalado.
- Secrets nao devem ser exibidos no terminal.
- Secrets nao devem ser gravados em logs.
- Secrets nao devem entrar no Git, release ou tickets.
- Secrets comprometidos devem ser rotacionados.

## Permissoes Recomendadas

- `storage`: gravavel pelo usuario do Apache.
- Diretorios sensiveis: `750`.
- Arquivos sensiveis: `640` ou mais restrito.
- Backups de banco e secrets: acesso restrito a root ou operadores autorizados.

## Banco SQLite Fora do Git

O banco real fica em:

```text
storage/database/painel_dns.sqlite
```

Ele deve ser criado no destino a partir de `db/schema.sql`. Nunca inclua banco real no Git ou no release.

## Secret Fora do Git

O secret real fica em:

```text
storage/secrets/dns_servers.secret
```

Ele deve ser gerado no destino durante a instalacao. Nunca inclua secret real no Git ou no release.

## Release Oficial

Gere o release somente com:

```bash
git archive --format=tar.gz -o /tmp/painel-bind-clean.tar.gz HEAD
```

Nunca empacote manualmente a arvore real do projeto com `tar`, porque arquivos locais ignorados podem ser incluidos por engano.

## Validar Pacote Antes de Distribuir

```bash
tar -tzf /tmp/painel-bind-clean.tar.gz | grep -Ei '(^|/)(\.env$|.*\.sqlite$|.*\.db$|.*\.secret$|.*\.pem$|.*\.key$|.*\.bak$|.*\.bkp$|.*\.backup$|.*\.save$|.*\.log$|.*\.tar\.gz$|.*\.zip$|storage/database/.*|storage/secrets/.*|storage/logs/.*|storage/backups/.*|storage/cache/.*)' | grep -Ev '(^|/)storage/(database|secrets|logs|backups|cache)/\.gitkeep$' || true
```

O resultado esperado e vazio.

## Dados Reais

Nao inclua dados reais de:

- Dominios.
- Usuarios.
- Servidores DNS.
- Auditoria.
- Firewall.
- Logs operacionais.
- Backups.
