# Plano operacional - Document root para public

## Objetivo

Preparar um plano seguro para mudar o document root do painel para:

```text
/var/www/html/painel/public
```

Este documento e apenas planejamento. Nao altera Nginx/Apache, nao recarrega servico web, nao muda logica PHP/DNS e nao executa operacao operacional.

## Configuracao atual detectada

Leitura feita em 2026-06-29, sem alterar arquivos de configuracao.

### Processo web

Nao foi identificado processo real de `nginx`, `apache`, `httpd` ou `php-fpm` em execucao dentro do ambiente de sandbox da validacao.

### Apache

Arquivos detectados:

- `/etc/apache2/sites-enabled/000-default.conf -> ../sites-available/000-default.conf`
- `/etc/apache2/sites-available/000-default.conf`
- `/etc/apache2/sites-available/default-ssl.conf`

Configuracao habilitada detectada:

```apache
<VirtualHost *:80>
    DocumentRoot /var/www/html
</VirtualHost>
```

Configuracao SSL disponivel, mas nao detectada como habilitada em `sites-enabled`:

```apache
<VirtualHost *:443>
    DocumentRoot /var/www/html
</VirtualHost>
```

### Nginx

Nao foi encontrada configuracao Nginx habilitada relevante na leitura local. A mudanca planejada abaixo considera Apache como webserver detectado neste host.

## Novo document root proposto

```text
/var/www/html/painel/public
```

Com essa troca, os entrypoints criados em `public/` passam a ser a superficie publica do painel.

Arquivos e diretorios que devem ficar inacessiveis diretamente pela web apos a troca:

- `app/`
- `includes/`
- `db/`
- `storage/`
- `docs/`
- `scripts/`
- `config/`

## Checklist pre-mudanca

- Confirmar janela operacional e responsavel pela execucao.
- Confirmar acesso administrativo ao servidor web.
- Confirmar backup ou snapshot da configuracao Apache antes da edicao.
- Confirmar que `git status --short` esta limpo.
- Confirmar que `public/` contem todos os entrypoints necessarios.
- Confirmar que a validacao da Fase 8.2 permanece valida.
- Confirmar que nao ha links internos `.php` sem entrada correspondente em `public/`.
- Confirmar como a URL externa deve ficar apos a troca:
  - se hoje o painel e acessado como `/painel`, mudar o vhost inteiro para `/var/www/html/painel/public` pode mudar a URL para `/`;
  - se a URL `/painel` deve ser preservada, avaliar `Alias` ou vhost dedicado antes da troca.
- Confirmar que `config.php`, `includes/footer.php` e `includes/session-timeout.php` continuam resolvendo corretamente via entrypoints legados.
- Confirmar que banco e secrets ficam fora do novo document root.
- Confirmar que nenhum reload/restart Bind sera executado nessa janela.

## Comandos de validacao antes da mudanca

Executar no repositorio:

```sh
git status --short
find public -name "*.php" -print0 | xargs -0 -n1 php -l
find . -name "*.php" -print0 | xargs -0 -n1 php -l
git diff --check
```

Validar public localmente sem trocar o servidor web:

```sh
php -S 127.0.0.1:18083 -t public
curl -i http://127.0.0.1:18083/login.php
curl -i http://127.0.0.1:18083/index.php
curl -i http://127.0.0.1:18083/dashboard.php
curl -i http://127.0.0.1:18083/auditoria.php
curl -i http://127.0.0.1:18083/zones.php
curl -i http://127.0.0.1:18083/dns-servers.php
```

Validar configuracao Apache antes de recarregar:

```sh
apache2ctl configtest
apache2ctl -S
```

## Mudanca planejada

Para Apache, a mudanca esperada e alterar o `DocumentRoot` do vhost correto para:

```apache
DocumentRoot /var/www/html/painel/public
```

Tambem deve existir bloco de permissao compativel para o novo diretorio, por exemplo:

```apache
<Directory /var/www/html/painel/public>
    Options FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
```

Observacao: este documento nao aplica a mudanca. A edicao do vhost e o reload do Apache devem ocorrer em fase operacional separada.

## Comandos de validacao apos a mudanca

Antes de reload:

```sh
apache2ctl configtest
apache2ctl -S
```

Apos reload planejado em fase propria:

```sh
curl -I http://127.0.0.1/login.php
curl -I http://127.0.0.1/index.php
curl -I http://127.0.0.1/dashboard.php
curl -I http://127.0.0.1/auditoria.php
curl -I http://127.0.0.1/zones.php
curl -I http://127.0.0.1/dns-servers.php
```

Validacoes esperadas sem sessao:

- `login.php`: `200 OK`
- paginas autenticadas: `302` para `login.php`

Validacoes de exposicao negativa:

```sh
curl -I http://127.0.0.1/app/
curl -I http://127.0.0.1/includes/
curl -I http://127.0.0.1/db/
curl -I http://127.0.0.1/storage/
curl -I http://127.0.0.1/docs/
curl -I http://127.0.0.1/scripts/
curl -I http://127.0.0.1/config/
```

Resultado esperado: `404`, `403` ou inexistente. Nunca `200` listando ou servindo conteudo desses diretorios.

## Plano de rollback

Antes da mudanca, salvar copia do arquivo de vhost:

```sh
cp -a /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/000-default.conf.bak-$(date +%Y%m%d-%H%M%S)
```

Se a troca falhar:

1. Restaurar o `DocumentRoot` anterior:

```apache
DocumentRoot /var/www/html
```

2. Restaurar/remover o bloco `<Directory /var/www/html/painel/public>` se ele tiver sido adicionado apenas para a troca.
3. Validar configuracao:

```sh
apache2ctl configtest
```

4. Recarregar Apache somente na fase operacional autorizada:

```sh
systemctl reload apache2
```

5. Validar URL antiga do painel.
6. Registrar incidente e manter o commit de codigo intacto, pois os arquivos antigos da raiz nao foram removidos.

## Riscos conhecidos

- Mudanca de URL base: sair de `/painel/...` para `/...`, dependendo de como o vhost atual e acessado.
- Redirects relativos como `login.php`, `dashboard.php` e outros devem ser testados no caminho final.
- `config.php`, `includes/footer.php` e `includes/session-timeout.php` ainda sao carregados por arquivos legados fora de `public/`.
- Se o vhost errado for alterado, o painel pode continuar exposto pela raiz antiga.
- Se a troca for parcial, `app/`, `includes/`, `db/`, `storage/`, `docs/`, `scripts/` ou `config/` podem continuar acessiveis diretamente.
- Permissoes de filesystem podem bloquear `public/` ou permitir leitura indevida.
- Regras `.htaccess` nao devem ser assumidas, pois o plano usa `AllowOverride None`.
- Cache de navegador ou proxy pode mascarar resultado de validacao.

## Criterio de sucesso

- `DocumentRoot` aponta para `/var/www/html/painel/public`.
- `login.php` responde `200`.
- Paginas autenticadas redirecionam para `login.php` sem sessao.
- Fluxo autenticado basico funciona.
- Diretorios sensiveis fora de `public/` nao sao servidos pela web.
- Nenhuma operacao DNS, Bind, banco ou secrets foi alterada durante a troca.
