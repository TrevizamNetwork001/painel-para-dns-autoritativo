# Fase 8.7 - Validacao final apos DocumentRoot e SQLite

## Objetivo

Registrar a validacao final apos a troca do `DocumentRoot` para `public/` e a correcao do schema SQLite restaurado.

## Escopo e restricoes

Nao houve:

- alteracao de logica PHP;
- alteracao DNS/Bind;
- reload/restart Bind;
- acao destrutiva;
- alteracao de banco/secrets durante esta fase.

## Limitacao da validacao autenticada

A validacao realmente autenticada em navegador nao foi concluida neste ambiente.

Motivos:

- nao ha Chromium, Chrome, Playwright ou navegador equivalente instalado no ambiente;
- nao foram fornecidas credenciais autorizadas;
- nao foi feito bypass de autenticacao;
- nao foi criada sessao manual;
- nao houve leitura ou alteracao de secrets.

## Validacao HTTP controlada

Sem sessao autenticada, foram chamadas as rotas pedidas em `http://127.0.0.1`.

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
- todas as rotas protegidas redirecionaram para `login.php`, comportamento esperado sem sessao.

## Verificacao de log Apache

Foi verificado `/var/log/apache2/error.log` antes e depois da sequencia HTTP.

O log continha entradas antigas anteriores a esta fase, incluindo:

- erros antigos de abertura do SQLite antes da restauracao/correcao;
- aviso antigo de schema faltante em `firewall_admin_access` antes da Fase 8.6.

Apos a sequencia desta fase, nao foi observada entrada nova no final do log.

## Banco e backup fora do Git

Verificacao:

```sh
git status --short --ignored db
```

Resultado relevante:

```text
!! db/painel_dns.sqlite
!! db/painel_dns.sqlite.bak-before-schema-fix-20260629
```

Conclusao: banco e backup continuam ignorados e fora do Git.

## Checklist pendente para validacao autenticada real

Executar manualmente em navegador real, com credenciais autorizadas:

- login;
- `dashboard.php`;
- `dns-servers.php`;
- `zones.php`;
- `dns-zones.php`;
- `reverse-zones.php`;
- `domains.php`;
- `firewall.php`;
- `services.php`;
- `auditoria.php`;
- `logs.php`.

Durante essa validacao, nao executar:

- remover zona;
- remover registro;
- reload/restart;
- sync NS2;
- firewall apply;
- qualquer acao destrutiva.

## Criterio de aceite autenticado

- Login conclui sem erro.
- Paginas listadas carregam sem HTTP `500`.
- Nenhum erro novo aparece em `/var/log/apache2/error.log`.
- Banco e backup continuam fora do Git.
- Nenhuma acao destrutiva e executada.

## Conclusao

A validacao HTTP sem sessao confirmou que as rotas protegidas nao retornam `500` e continuam redirecionando para `login.php`.

A validacao autenticada final permanece pendente de navegador real e credenciais autorizadas.
