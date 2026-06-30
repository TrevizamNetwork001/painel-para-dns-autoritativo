# Fase 10.3 - Move da configuracao legada para `config/`

## Objetivo

Separar a configuracao real em `config/app.php` e manter `config.php` da raiz apenas como wrapper temporario.

## Alteracoes realizadas

### Novo arquivo real

`config/app.php` recebeu o conteudo antes mantido em `config.php`:

- inicializacao da sessao
- `session_timeout`
- leitura das zonas em `/var/cache/bind/master-aut/*.hosts`
- ordenacao do vetor `$domains`

### Wrapper temporario

`config.php` na raiz passou a conter apenas:

```php
require_once __DIR__ . '/config/app.php';
```

## Uso atual de `config.php`

Foi verificado com:

```text
grep -R "config.php" -n . --exclude-dir=.git
```

Os usos efetivos e relevantes continuam em:

- `dashboard.php`
- `domains.php`
- `dns-zones.php`
- `edit-zone.php`
- `edit-record.php`
- `edit-ptr.php`
- `edit-reverse-zone.php`
- `delete-record.php`
- `delete-ptr.php`
- `reverse-zones.php`
- `services.php`
- arquivos legados de compatibilidade ou backup, como `*.bak*` e `*.bkp*`

## Validacoes executadas

- `php -l config.php`
- `php -l config/app.php`
- `find . -name "*.php" -print0 | xargs -0 -n1 php -l`
- `curl` sem sessao para `/login.php` e `/dashboard.php`
- `git diff --check`

## Observacoes

O wrapper da raiz foi mantido para nao quebrar includes existentes enquanto a base ainda depende de `config.php`.

## Proximo passo

Quando as telas legadas deixarem de depender do wrapper da raiz, a referencia pode ser removida em uma fase posterior.
