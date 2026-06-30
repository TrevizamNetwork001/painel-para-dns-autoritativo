# Fase 8.2 - Revalidacao completa de public

## Objetivo

Revalidar `public/` depois da criacao das entradas auxiliares, antes de qualquer troca de document root.

## Escopo

Esta fase foi apenas documental e de validacao local.

Nao houve:

- alteracao de Nginx/Apache;
- mudanca de document root;
- alteracao de logica DNS;
- reload/restart Bind.

## Metodo

Foi usado PHP built-in server local com document root em `public/`:

```sh
php -S 127.0.0.1:18083 -t public
```

As chamadas foram feitas por `curl` local para as rotas pedidas.

## Resultados HTTP

| Rota | Status | Redirect |
| --- | --- | --- |
| `/index.php` | `302` | `login.php` |
| `/login.php` | `200` | nenhum |
| `/dashboard.php` | `302` | `login.php` |
| `/auditoria.php` | `302` | `login.php` |
| `/logs.php` | `302` | `login.php` |
| `/zones.php` | `302` | `login.php` |
| `/dns-servers.php` | `302` | `login.php` |

Os redirects para `login.php` sao esperados sem sessao autenticada, pois as paginas carregam o fluxo legado de autenticacao.

## Verificacao de links internos

Foi feita uma comparacao entre referencias internas `.php` em `href`, `src` e `action` e as entradas existentes em `public/`.

Resultado:

- nenhuma referencia interna `.php` foi encontrada sem entrypoint correspondente em `public/`.

## Entradas public consideradas presentes

As entradas principais da Fase 7 e as auxiliares da Fase 8.1 estavam presentes, incluindo:

- `auditoria.php`
- `delete-record.php`
- `delete-ptr.php`
- `logs.php`
- `security.php`
- `fail2ban-bind.php`

## Pendencias antes do document root

Mesmo com as entradas presentes, ainda permanecem pontos que devem ser tratados antes da troca real:

- revisar `config.php` carregado diretamente por paginas legadas;
- revisar `includes/footer.php`;
- revisar `includes/session-timeout.php`;
- decidir estrategia definitiva para assets em `public/assets/`;
- testar fluxo autenticado em janela controlada antes de alterar servidor web.

## Conclusao

`public/` esta consistente como camada de entrada compativel para as paginas testadas e para as referencias internas `.php` encontradas.

Ainda nao houve mudanca operacional. A troca de document root deve continuar em fase separada, com plano de rollback.
