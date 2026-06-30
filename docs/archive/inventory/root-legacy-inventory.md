# Inventario de residuos da raiz

Data: 2026-06-29

Esta limpeza consolidou a raiz do projeto para a estrutura reestruturada, sem alterar logica operacional de DNS, autenticacao ou firewall.

## Mantidos na raiz

- `.gitattributes`
- `.gitignore`
- `CHANGELOG.md`
- `FUNCIONALIDADES.md`
- `ROADMAP.md`
- `README.md`
- `config.php`

`config.php` foi mantido porque os controllers legados em `app/LegacyControllers/` ainda carregam esse wrapper.

## Removidos da raiz

- `servidores-dns.php`

O arquivo era um wrapper legado para `dns-servers.php`, mas nao havia referencia de codigo para ele e o entrypoint publico atual e `public/dns-servers.php`.

## Movidos para arquivo historico

- `HANDOFF_*`
- `HOMOLOG_*`
- `RELEASE_*`
- `docs/handoff/*`
- `docs/migrations/*`

## Movidos para notas antigas

- `dashbord_principal.txt`
- `handoff_melhorias_dash.txt`
- `handoff_respostas_dashbord.txt`
