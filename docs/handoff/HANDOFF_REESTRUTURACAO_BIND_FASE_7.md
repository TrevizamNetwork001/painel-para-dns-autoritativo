# Handoff - Reestruturacao Bind - Fase 7

## Objetivo

Registrar a criacao de `public/` como camada de entrada compativel para preparar uma futura troca de document root.

## Escopo concluido

Foram criados entrypoints em `public/` para as paginas principais. Cada entrypoint apenas executa `require_once` do arquivo legado correspondente na raiz.

Nenhum arquivo antigo da raiz foi removido.

Nenhuma configuracao Nginx/Apache foi alterada.

O document root nao foi alterado.

## Entradas public criadas

- `public/login.php`
- `public/logout.php`
- `public/index.php`
- `public/dashboard.php`
- `public/usuarios.php`
- `public/alterar-senha.php`
- `public/zones.php`
- `public/dns-zones.php`
- `public/reverse-zones.php`
- `public/domains.php`
- `public/edit-zone.php`
- `public/edit-record.php`
- `public/edit-reverse-zone.php`
- `public/edit-ptr.php`
- `public/dns-servers.php`
- `public/services.php`
- `public/bind.php`
- `public/firewall.php`
- `public/fail2ban.php`
- `public/ssh.php`
- `public/acl.php`
- `public/acl6.php`

Tambem foi criado:

- `public/assets/.gitkeep`

## Estado arquitetural

`public/` agora existe como camada fina de entrada.

As paginas reais continuam na raiz e ainda sao carregadas diretamente pelos entrypoints.

Os wrappers em `app/` continuam sendo a fachada usada pelas paginas migradas na Fase 6.

Os includes legados em `includes/` continuam sendo a fonte da verdade da logica compartilhada.

## O que nao foi feito

- Nao houve mudanca de document root.
- Nao houve alteracao em Nginx/Apache.
- Nao houve remocao de arquivos antigos da raiz.
- Nao houve mudanca de logica DNS.
- Nao houve reload/restart Bind.
- Nao houve comando remoto.
- Nao houve alteracao de banco ou secrets.
- Nao houve movimentacao de assets.

## Riscos pendentes

- Links relativos e redirects ainda podem apontar para caminhos da raiz quando o document root mudar.
- `config.php` ainda e carregado diretamente por varias paginas.
- `includes/footer.php` e `includes/session-timeout.php` ainda sao carregados diretamente em varias paginas.
- Algumas paginas auxiliares ainda nao entraram no pacote `public/`, como `auditoria.php`, `delete-record.php`, `delete-ptr.php`, `logs.php`, `security.php` e `fail2ban-bind.php`.
- A troca real de document root precisa de teste em ambiente controlado e plano de rollback.

## Validacoes desta fase

Validacoes esperadas:

- `find public -name "*.php" -print0 | xargs -0 -n1 php -l`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `git diff --check`
- `git diff --cached --check`

## Proximos passos

- testar os entrypoints `public/` sem alterar o servidor web;
- revisar redirects e links internos para compatibilidade com futuro document root;
- definir solucao para `config.php`, footer, timeout e assets;
- completar ou excluir conscientemente paginas auxiliares antes da troca;
- preparar alteracao de document root em fase propria, com rollback documentado.
