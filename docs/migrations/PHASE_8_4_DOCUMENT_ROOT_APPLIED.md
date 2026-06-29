# Fase 8.4 - Document root public aplicado

## Objetivo

Registrar a aplicacao do `DocumentRoot` para `public/` com backup, validacao de configuracao Apache, reload controlado e plano de rollback pronto.

## Escopo

Foi alterada somente a configuracao Apache do vhost padrao.

Nao houve:

- alteracao de logica PHP;
- alteracao DNS/Bind;
- reload/restart Bind;
- alteracao de banco;
- alteracao de secrets.

## Backup criado

Arquivo original:

```text
/etc/apache2/sites-available/000-default.conf
```

Backup criado antes da alteracao:

```text
/etc/apache2/sites-available/000-default.conf.bak-public-20260629
```

Comando executado:

```sh
cp /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/000-default.conf.bak-public-20260629
```

## Alteracao aplicada

`DocumentRoot` alterado de:

```apache
DocumentRoot /var/www/html
```

para:

```apache
DocumentRoot /var/www/html/painel/public
```

Bloco `Directory` adicionado:

```apache
<Directory /var/www/html/painel/public>
    Options FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
```

O diff do vhost ficou limitado ao `DocumentRoot` e ao bloco `Directory`.

## Validacao Apache

Comando executado antes do reload:

```sh
apachectl configtest
```

Resultado:

```text
AH00558: apache2: Could not reliably determine the server's fully qualified domain name, using 45.162.196.242. Set the 'ServerName' directive globally to suppress this message
Syntax OK
```

O aviso de `ServerName` ja era esperado para Apache sem `ServerName` global. A sintaxe retornou `OK`.

## Reload Apache

Como `apachectl configtest` passou, foi executado:

```sh
systemctl reload apache2
```

Resultado: comando concluido com codigo `0`.

## Validacao HTTP apos reload

Validacao local em `http://127.0.0.1`:

| Rota | Status | Redirect |
| --- | --- | --- |
| `/` | `302` | `login.php` |
| `/login.php` | `200` | nenhum |
| `/dashboard.php` | `302` | `login.php` |
| `/zones.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |

Os redirects para `login.php` sao esperados sem sessao autenticada.

## Validacao de bloqueio externo

Validacao local em `http://127.0.0.1`:

| Rota | Status |
| --- | --- |
| `/app/` | `404` |
| `/includes/` | `404` |
| `/db/` | `404` |
| `/docs/` | `404` |
| `/scripts/` | `404` |
| `/config/` | `404` |

Resultado esperado: diretorios fora de `public/` nao sao servidos pelo novo document root.

## Rollback pronto

Para reverter, restaurar o backup:

```sh
cp /etc/apache2/sites-available/000-default.conf.bak-public-20260629 /etc/apache2/sites-available/000-default.conf
apachectl configtest
systemctl reload apache2
```

Validar depois do rollback:

```sh
curl -I http://127.0.0.1/painel/login.php
curl -I http://127.0.0.1/painel/index.php
```

Se a URL antiga nao era `/painel`, validar conforme o caminho usado antes da troca.

## Riscos remanescentes

- Testes foram feitos sem sessao autenticada; fluxo autenticado completo ainda deve ser validado manualmente.
- URLs externas podem mudar conforme o vhost real usado pelo cliente.
- Links absolutos externos, caches ou proxies podem mascarar resultados.
- O vhost SSL `default-ssl.conf` ainda possui `DocumentRoot /var/www/html` e nao foi alterado nesta fase porque nao estava habilitado em `sites-enabled`.

## Conclusao

O `DocumentRoot` HTTP padrao foi aplicado para `/var/www/html/painel/public`, o Apache foi recarregado apos `configtest` bem-sucedido, as rotas principais responderam como esperado e diretorios sensiveis fora de `public/` retornaram `404`.
