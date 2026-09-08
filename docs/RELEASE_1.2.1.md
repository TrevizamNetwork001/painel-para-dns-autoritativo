# DNS Center v1.2.1

Data: 2026-09-08

## Escopo

Patch de texto, sem alteração de schema nem de lógica: a tela de
descoberta BIND (`/servidores/{id}/bind/descoberta`) não deixava claro
por que nenhuma zona aparecia como "pronta pra importar" quando o
servidor é secundário — as zonas chegam por réplica (AXFR) do
primário e são marcadas "Secondary externo" de propósito, mas não
havia explicação na tela (achado real, reportado pelo operador ao usar
a tela no servidor `ns2.legacy.example`).

Commit `9325988` adiciona uma nota condicional
(`resources/views/servers/bind-discovery.blade.php`, visível só quando
`$server->role === 'secondary'`) explicando que a importação é feita
uma única vez, no servidor primário.

## Testes e gates

- `php -l` e `view:cache`: aprovados.
- Nenhum teste automatizado dedicado (mudança é só texto condicional
  numa view já coberta indiretamente pelos testes de zona/descoberta
  existentes, que não fazem assertion sobre esse texto).

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `9325988` (tag
`v1.2.1`), via:

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.2.1
```

_(seção a completar após a execução)_
