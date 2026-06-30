# Fase 8 - Validacao inicial de public

## Objetivo

Validar `public/` antes de qualquer mudanca de document root, mantendo Nginx/Apache inalterado e sem executar operacoes DNS, Bind, firewall ou servicos.

## Escopo

Esta fase foi apenas documental e de validacao local.

Nao houve:

- alteracao de Nginx/Apache;
- mudanca de document root;
- remocao de arquivos antigos da raiz;
- alteracao de logica DNS;
- reload/restart Bind;
- comando remoto;
- alteracao de banco ou secrets.

## Metodo de teste

Foi usado PHP built-in server local com document root em `public/`:

```sh
php -S 127.0.0.1:18082 -t public
```

Observacao: no sandbox, a abertura da porta e os acessos via `curl` precisaram de permissao elevada. O teste continuou limitado a `127.0.0.1` e nao alterou servidor web real.

As chamadas foram feitas com `curl` local para:

- `http://127.0.0.1:18082/index.php`
- `http://127.0.0.1:18082/login.php`
- `http://127.0.0.1:18082/dashboard.php`

## Resultados

### `public/index.php`

Resultado:

- HTTP `302 Found`
- `Location: login.php`
- comportamento esperado sem sessao autenticada

### `public/login.php`

Resultado:

- HTTP `200 OK`
- HTML renderizado com `<title>Login</title>`
- formulario `POST` carregado
- CSRF renderizado pelo fluxo legado
- script externo atual preservado: `https://cdn.jsdelivr.net/npm/tsparticles@2/tsparticles.bundle.min.js`

### `public/dashboard.php`

Resultado:

- HTTP `302 Found`
- `Location: login.php`
- comportamento esperado sem sessao autenticada

## Links e assets observados

Foi feita uma varredura estatica em links, actions e scripts das paginas principais migradas para `public/`.

Os links internos principais usam caminhos relativos como:

- `dashboard.php`
- `domains.php`
- `dns-zones.php`
- `reverse-zones.php`
- `zones.php`
- `dns-servers.php`
- `services.php`
- `firewall.php`
- `usuarios.php`
- `alterar-senha.php`
- `logout.php`

Esses caminhos tendem a funcionar quando o document root for `public/`, desde que exista entrypoint correspondente em `public/`.

## Pendencias encontradas

Alguns links/forms ainda apontam para paginas auxiliares que nao foram criadas em `public/` na Fase 7:

- `auditoria.php`
- `delete-record.php`
- `delete-ptr.php`
- `logs.php`
- `security.php`
- `fail2ban-bind.php`

Antes de mudar o document root, essas paginas precisam ser tratadas de uma das formas:

- criar entrypoints compativeis em `public/`;
- remover ou substituir os links, se a tela for descontinuada;
- documentar conscientemente que nao farao parte do novo document root.

Tambem permanecem pontos a resolver antes da troca real:

- `config.php` ainda e carregado diretamente por algumas paginas legadas;
- `includes/footer.php` ainda e carregado diretamente;
- `includes/session-timeout.php` ainda e carregado diretamente;
- assets ainda nao foram movidos para `public/assets/`.

## Validacoes executadas

Durante esta fase foram executadas validacoes de carregamento HTTP local para `public/index.php`, `public/login.php` e `public/dashboard.php`.

A validacao final desta etapa deve incluir:

- `git diff --check`
- `git status --short`

## Conclusao

`public/` carrega os entrypoints principais testados em ambiente local.

Ainda nao e recomendado alterar o document root antes de resolver as paginas auxiliares pendentes e os includes diretos de configuracao, footer e timeout.
