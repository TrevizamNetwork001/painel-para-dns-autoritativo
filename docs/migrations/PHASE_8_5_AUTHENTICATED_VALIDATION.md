# Fase 8.5 - Validacao autenticada apos document root

## Objetivo

Registrar a validacao do painel apos a troca do `DocumentRoot` para `public/`, com foco em navegacao autenticada e sem executar acoes destrutivas.

## Escopo e restricoes

Nao houve:

- alteracao de logica PHP;
- alteracao DNS/Bind;
- reload/restart Bind;
- acao destrutiva;
- leitura ou alteracao de banco/secrets.

## Resultado da tentativa

A validacao autenticada em navegador nao foi concluida neste ambiente.

Motivos:

- nao ha Chromium, Chrome, Playwright ou navegador equivalente instalado no ambiente;
- nao foram fornecidas credenciais de usuario;
- as regras desta fase impedem tocar em banco/secrets para descobrir ou alterar credenciais.

Por esses motivos, nao foi feito bypass de autenticacao, nao foi criada sessao manual e nao foi alterado usuario.

## Validacao HTTP sem sessao

Foi feita validacao local por `curl` em `http://127.0.0.1` para confirmar que os entrypoints via novo `DocumentRoot` respondem corretamente sem sessao.

| Rota | Status | Redirect |
| --- | --- | --- |
| `/login.php` | `200` | nenhum |
| `/dashboard.php` | `302` | `login.php` |
| `/usuarios.php` | `302` | `login.php` |
| `/alterar-senha.php` | `302` | `login.php` |
| `/zones.php` | `302` | `login.php` |
| `/dns-zones.php` | `302` | `login.php` |
| `/reverse-zones.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |
| `/auditoria.php` | `302` | `login.php` |
| `/logs.php` | `302` | `login.php` |
| `/services.php` | `302` | `login.php` |
| `/firewall.php` | `302` | `login.php` |

Os redirects para `login.php` sao esperados sem sessao autenticada.

## Checklist pendente para navegador autenticado

Executar manualmente em navegador real, com credenciais autorizadas:

- acessar `/login.php`;
- realizar login;
- abrir `/dashboard.php`;
- abrir `/usuarios.php`;
- abrir `/alterar-senha.php` sem trocar senha;
- abrir `/zones.php`;
- abrir `/dns-zones.php`;
- abrir `/reverse-zones.php`;
- abrir `/dns-servers.php`;
- abrir `/auditoria.php`;
- abrir `/logs.php`;
- abrir `/services.php`;
- abrir `/firewall.php`.

## Acoes proibidas durante a validacao manual

Nao clicar em:

- remover zona;
- remover registro;
- reload/restart;
- sync NS2;
- firewall apply;
- qualquer acao destrutiva.

## Criterio de aceite manual

- Login conclui sem erro.
- Dashboard carrega.
- Paginas listadas carregam com layout esperado.
- Nenhuma pagina retorna erro 404/500.
- Nenhuma acao destrutiva e executada.
- Logout continua funcionando.

## Conclusao

O `DocumentRoot` em `public/` continua respondendo corretamente para login e protecao de rotas sem sessao.

A validacao autenticada completa permanece pendente de navegador real e credenciais autorizadas.
