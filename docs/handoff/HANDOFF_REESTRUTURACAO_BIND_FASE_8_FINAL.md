# Handoff - Reestruturacao Bind - Fase 8 final

## Objetivo

Registrar o fechamento da Fase 8: preparacao, validacao e aplicacao do `DocumentRoot` para `public/`, alem da correcao de schema SQLite apos restauracao do banco.

## Commits da Fase 8

- `5b71ad0` `refactor: adiciona entradas public compativeis`
- `fdcb7ae` `docs: adiciona validacao inicial do public`
- `45c5f8d` `refactor: adiciona entradas public faltantes`
- `cecb668` `docs: revalida public completo antes do document root`
- `8aee36c` `docs: adiciona plano de document root public`
- `1830f83` `docs: registra aplicacao do document root public`
- `abc0e77` `docs: registra validacao autenticada apos document root`
- `12b2812` `fix: documenta correcao de schema sqlite restaurado`
- `0831207` `docs: registra validacao final apos document root`

## DocumentRoot aplicado

O `DocumentRoot` HTTP padrao do Apache foi aplicado para:

```text
/var/www/html/painel/public
```

Arquivo alterado fora do Git:

```text
/etc/apache2/sites-available/000-default.conf
```

Alteracao aplicada:

```apache
DocumentRoot /var/www/html/painel/public

<Directory /var/www/html/painel/public>
    Options FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
```

## Backup Apache

Backup criado antes da alteracao:

```text
/etc/apache2/sites-available/000-default.conf.bak-public-20260629
```

Rollback pronto:

```sh
cp /etc/apache2/sites-available/000-default.conf.bak-public-20260629 /etc/apache2/sites-available/000-default.conf
apachectl configtest
systemctl reload apache2
```

## Entradas public completas

Entradas presentes em `public/`:

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

Cada entrypoint carrega o legado correspondente da raiz com `require_once`.

## Validacoes HTTP

### Antes da aplicacao do DocumentRoot

Foi usado PHP built-in server com `-t public` para validar `public/` sem mudar Apache.

Rotas testadas incluiram:

- `/index.php`
- `/login.php`
- `/dashboard.php`
- `/auditoria.php`
- `/logs.php`
- `/zones.php`
- `/dns-servers.php`

Resultado esperado sem sessao:

- `login.php`: `200`
- paginas autenticadas: `302` para `login.php`

### Depois da aplicacao do DocumentRoot

Validacao local em `http://127.0.0.1`:

| Rota | Status | Redirect |
| --- | --- | --- |
| `/` | `302` | `login.php` |
| `/login.php` | `200` | nenhum |
| `/dashboard.php` | `302` | `login.php` |
| `/zones.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |

Validacao de bloqueio externo:

| Rota | Status |
| --- | --- |
| `/app/` | `404` |
| `/includes/` | `404` |
| `/db/` | `404` |
| `/docs/` | `404` |
| `/scripts/` | `404` |
| `/config/` | `404` |

## Rotas sem sessao apos SQLite corrigido

Depois da correcao do SQLite, as rotas protegidas foram chamadas sem sessao:

| Rota | Status | Redirect |
| --- | --- | --- |
| `/dashboard.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |
| `/zones.php` | `302` | `login.php` |
| `/dns-zones.php` | `302` | `login.php` |
| `/reverse-zones.php` | `302` | `login.php` |
| `/domains.php` | `302` | `login.php` |
| `/firewall.php` | `302` | `login.php` |
| `/services.php` | `302` | `login.php` |
| `/auditoria.php` | `302` | `login.php` |
| `/logs.php` | `302` | `login.php` |

Resultado:

- nenhuma rota retornou HTTP `500`;
- todas redirecionaram para `login.php`, comportamento esperado sem sessao.

## SQLite restaurado e schema corrigido

Backup criado antes da correcao:

```text
db/painel_dns.sqlite.bak-before-schema-fix-20260629
```

Banco corrigido:

```text
db/painel_dns.sqlite
```

Ambos continuam fora do Git:

```text
!! db/painel_dns.sqlite
!! db/painel_dns.sqlite.bak-before-schema-fix-20260629
```

Tabelas faltantes criadas com `CREATE TABLE IF NOT EXISTS`:

- `dns_zone_governance`
- `dns_server_governance`
- `firewall_admin_access`
- `firewall_ports`
- `firewall_meta`

Validacao SQLite:

```text
PRAGMA integrity_check; -> ok
```

`db/schema.sql` ja continha as tabelas necessarias, portanto nao precisou ser alterado.

## Logs Apache

Foi verificado `/var/log/apache2/error.log` antes e depois das validacoes HTTP finais.

Havia erros antigos anteriores a correcao, incluindo:

- falha antiga de abertura do SQLite;
- aviso antigo de tabela `firewall_admin_access` ausente.

Apos a sequencia final de rotas sem sessao, nao foi observada entrada nova no final do log.

## Ressalva de validacao autenticada

A validacao autenticada real em navegador nao foi concluida nesta fase.

Motivos:

- nao havia Chromium, Chrome, Playwright ou navegador equivalente instalado no ambiente;
- nao foram fornecidas credenciais autorizadas;
- nao foi feito bypass de autenticacao;
- nao foi criada sessao manual;
- banco/secrets nao foram lidos para obter credenciais.

Ainda e necessario validar manualmente com navegador real e credenciais autorizadas:

- login;
- dashboard;
- DNS visual e operacional;
- servidores DNS;
- firewall;
- servicos;
- auditoria;
- logs.

Sem clicar em acoes destrutivas.

## Riscos pendentes para Fase 9

- Validacao autenticada real ainda pendente.
- Testar fluxo completo de login/logout e navegacao com usuario autorizado.
- Testar `firewall.php` autenticado apos criacao das tabelas `firewall_*`, sem aplicar firewall.
- Testar `dns-servers.php` autenticado sem executar SSH, sync NS2, agente remoto ou comandos destrutivos.
- Confirmar comportamento externo pelo hostname/IP real, nao apenas `127.0.0.1`.
- Avaliar se o vhost SSL deve receber o mesmo `DocumentRoot`, caso HTTPS seja habilitado.
- Revisar permissao do SQLite e diretorios de `storage/` apos a troca para `public/`.
- Avaliar se arquivos legados na raiz devem permanecer acessiveis apenas por filesystem e nunca por web.
- Criar procedimento de validacao autenticada com screenshots/logs se houver ferramenta de navegador disponivel.
- Manter rollback Apache documentado ate a validacao autenticada ser concluida.

## Estado final esperado

- Apache HTTP padrao servindo `/var/www/html/painel/public`.
- Diretorios sensiveis fora de `public/` nao servidos diretamente pela web.
- Entradas `public/*.php` completas para as paginas conhecidas.
- SQLite restaurado com schema minimo compativel com o codigo atual.
- Banco e backup fora do Git.
- Nenhuma alteracao de logica PHP/DNS/Bind nesta fase.
