# Instalacao do Painel DNS Bind

## Requisitos

- Linux com Apache 2.
- PHP CLI e extensoes `sqlite3` ou `pdo_sqlite`.
- `sqlite3`.
- `apache2`, `apachectl`, `a2ensite` e `systemctl`.
- `bind9` ou `named-checkconf` instalado para validacoes manuais posteriores.
- `openssl`, `curl` e `tar`.
- Opcional: `nft` para ambientes que usam nftables.
- Execucao do instalador como `root`.

## Gerar Release Limpo

O release oficial deve ser gerado somente a partir do Git:

```bash
git archive --format=tar.gz -o /tmp/painel-bind-clean.tar.gz HEAD
```

Nao empacote manualmente a arvore real do projeto com `tar`, porque ela pode conter banco SQLite, secrets, logs, backups ou arquivos locais fora do Git.

## Validar Release Limpo

```bash
tar -tzf /tmp/painel-bind-clean.tar.gz | grep -Ei '(^|/)(\.env$|.*\.sqlite$|.*\.db$|.*\.secret$|.*\.pem$|.*\.key$|.*\.bak$|.*\.bkp$|.*\.backup$|.*\.save$|.*\.log$|.*\.tar\.gz$|.*\.zip$|storage/database/.*|storage/secrets/.*|storage/logs/.*|storage/backups/.*|storage/cache/.*)' | grep -Ev '(^|/)storage/(database|secrets|logs|backups|cache)/\.gitkeep$' || true
```

O comando nao deve listar nenhum arquivo proibido.

Confirme tambem os arquivos essenciais:

```bash
tar -tzf /tmp/painel-bind-clean.tar.gz | grep '^db/schema.sql$'
tar -tzf /tmp/painel-bind-clean.tar.gz | grep '^scripts/install.sh$'
tar -tzf /tmp/painel-bind-clean.tar.gz | grep '^public/index.php$'
tar -tzf /tmp/painel-bind-clean.tar.gz | grep '^public/login.php$'
```

## Executar Instalacao

```bash
sudo bash scripts/install.sh --package /tmp/painel-bind-clean.tar.gz --target /var/www/html/painel
```

Com validacao obrigatoria de nftables:

```bash
sudo bash scripts/install.sh --package /tmp/painel-bind-clean.tar.gz --target /var/www/html/painel --with-firewall
```

Parametros uteis:

- `--server-name nome.example`: define o `ServerName` do VirtualHost.
- `--apache-site painel-bind.conf`: define o nome do arquivo em `/etc/apache2/sites-available`.

Se o diretorio alvo existir e nao estiver vazio, o instalador exige a confirmacao literal `INSTALAR_ZERADO`.

## O Que o Instalador Faz

- Valida dependencias.
- Valida se o pacote nao contem arquivos proibidos.
- Faz backup da configuracao Apache existente do site.
- Extrai somente o release limpo para o alvo.
- Cria `storage/database`, `storage/secrets`, `storage/logs`, `storage/backups` e `storage/cache`.
- Cria um banco SQLite novo a partir de `db/schema.sql`.
- Cria um usuario administrador inicial.
- Gera um secret novo em `storage/secrets/dns_servers.secret`.
- Ajusta permissoes do `storage` para o Apache.
- Configura Apache com `DocumentRoot` em `/var/www/html/painel/public`.
- Executa `apachectl configtest`.
- Recarrega Apache somente se o `configtest` passar.
- Valida HTTP em `/` e `/login.php`.

## O Que o Instalador Nao Faz

- Nao reaproveita banco, secrets, logs ou backups antigos.
- Nao mostra senha no terminal.
- Nao grava senha ou secret no log.
- Nao executa `rndc reload`.
- Nao executa `rndc reconfig`.
- Nao executa `systemctl restart bind9`.
- Nao executa `systemctl reload bind9`.
- Nao executa `systemctl restart named`.
- Nao executa `systemctl reload named`.
- Nao aplica regras nftables automaticamente.

O instalador nao reinicia nem recarrega o Bind automaticamente.

## Testar HTTP

```bash
curl -I http://127.0.0.1/
curl -I http://127.0.0.1/login.php
```

Respostas `200` ou redirecionamentos esperados sao aceitaveis. Erro `500` deve ser tratado antes de liberar o acesso.

## Acessar o Painel

Acesse o host configurado no Apache, por exemplo:

```text
http://127.0.0.1/login.php
```

Use o usuario administrador criado durante a instalacao.

## Troubleshooting Apache

Valide a configuracao:

```bash
apachectl configtest
```

Se falhar, corrija o VirtualHost antes de recarregar:

```bash
systemctl reload apache2
```

Use o reload somente apos `apachectl configtest` retornar sucesso.

## Troubleshooting SQLite

Confirme extensoes PHP:

```bash
php -m | grep -Ei '^sqlite3$|^pdo_sqlite$'
```

Valide o banco:

```bash
sqlite3 /var/www/html/painel/storage/database/painel_dns.sqlite 'PRAGMA integrity_check;'
```

O resultado esperado e `ok`.
