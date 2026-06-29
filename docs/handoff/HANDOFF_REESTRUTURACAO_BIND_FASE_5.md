# Handoff - Reestruturacao Bind - Fase 5

Data: 2026-06-29
Branch atual: `master`

## Objetivo do checkpoint

Registrar o estado consolidado da Fase 1 ate a Fase 5.5 da reestruturacao do painel Bind, com foco em wrappers compativeis e documentacao de migracao. Esta fase ainda nao moveu as paginas publicas nem trocou os includes atuais no runtime.

## Linha do tempo de commits

- Fase 1.0 - higiene Git: `7f6e9e1` `chore: remove dados sensiveis do versionamento`
- Fase 1.1 - ajustes atuais preservados: `fd7004f` `refactor: preserva ajustes atuais do painel dns`
- Fase 1.2 - inventario externo privado: `a577972` `docs: adiciona inventario privado de backups externos`
- Fase 2 - estrutura base: `6b950fb` `chore: adiciona estrutura base para reorganizacao do painel bind`
- Fase 3 - mapa de dependencias: `701a132` `docs: adiciona mapa de dependencias do painel bind`
- Fase 4 - bootstrap compativel: `4d090e2` `refactor: adiciona bootstrap compativel para futura migracao`
- Fase 5.0 - wrapper de auditoria: `677d023` `refactor: adiciona wrapper de auditoria em app`
- Fase 5.1 - wrapper de banco: `a1ce286` `refactor: adiciona wrapper de banco em app`
- Fase 5.2 - wrapper de autenticacao: `0bc82ee` `refactor: adiciona wrapper de autenticacao em app`
- Fase 5.3 - wrapper de zonas DNS: `c647ac1` `refactor: adiciona wrapper de zonas dns em app`
- Fase 5.4 - wrapper de servidores DNS: `893a38b` `refactor: adiciona wrapper de servidores dns em app`
- Fase 5.5 - wrappers de usuarios e seguranca: `aedf6bd` `refactor: adiciona wrappers de usuarios e seguranca em app`

## Estado atual da estrutura

Estrutura base ja existe para a migracao futura:

- `app/`
  - `Auth/`
  - `Dns/`
  - `DnsServers/`
  - `Firewall/`
  - `Audit/`
  - `Services/`
  - `Support/`
- `config/examples/`
- `storage/`
  - `database/`
  - `logs/`
  - `cache/`
  - `secrets/`
  - `backups/`
- `public/`
- `docs/`
  - `architecture/`
  - `operations/`
  - `handoff/`
  - `migrations/`
- `tests/`

Essa estrutura ainda e preparatoria. O runtime atual continua no document root legado.

## Wrappers criados

- `app/Support/bootstrap.php`
- `app/Support/paths.php`
- `app/Support/legacy.php`
- `app/Support/db.php`
- `app/Support/security.php`
- `app/Audit/audit.php`
- `app/Auth/auth.php`
- `app/Auth/users.php`
- `app/Dns/zones.php`
- `app/DnsServers/servers.php`

Caracteristicas comuns:

- usam `require_once`;
- carregam o bootstrap compativel;
- fazem ponte para includes legados;
- nao redefinem funcoes existentes;
- nao foram conectados ao runtime das paginas publicas.

## Includes antigos que continuam fonte da verdade

- `includes/audit.php`
- `includes/auth.php`
- `includes/db.php`
- `includes/dns_servers.php`
- `includes/dns_zones.php`
- `includes/security.php`
- `includes/users.php`

Esses arquivos continuam sendo o contrato atual do sistema. Os wrappers em `app/` existem apenas como camada de preparacao para migração gradual.

## Riscos pendentes

- `includes/security.php` ainda mistura CSRF, validacao, escrita segura e `reload_dns()`.
- `includes/dns_servers.php` ainda concentra criptografia de credenciais, SSH/SCP, bootstrap, instalacao e remocao de agente.
- `includes/dns_zones.php` ainda mistura inventario, comparacao, governanca e sincronizacao remota.
- O document root ainda abriga paginas publicas diretamente, sem separacao em `public/`.
- Backups e artefatos historicos continuam existindo fora do repo, como fonte privada de comparacao.
- A migracao de wrappers para uso real ainda nao foi feita nas paginas publicas.

## Proximos passos recomendados

1. Separar helpers puros de funcoes com efeito colateral.
2. Migrar validadores e formatadores primeiro.
3. Encapsular acesso a banco e secretos antes de trocar includes nas paginas.
4. Reduzir a superficie de `includes/security.php` em etapas pequenas.
5. Isolar operacoes de SSH, `rndc`, `named-checkconf` e firewall em adaptadores bem definidos.
6. Criar testes para os wrappers antes de qualquer troca no runtime.
7. Somente depois disso mover entrada publica para `public/` e ajustar rotas.

## Aviso de Fase 6

A Fase 6 ja entra em alteracao de paginas reais e do caminho de execucao do sistema. Ela nao deve ser tratada como documentacao ou compatibilidade passiva: vai alterar arquivos operacionais e exige validacao completa antes de qualquer deploy.

## Observacao final

O checkpoint da Fase 5 fecha a camada de compatibilidade e documentacao. A partir daqui, a migracao precisa ser feita com troca controlada de includes, nao com novas estruturas soltas.
