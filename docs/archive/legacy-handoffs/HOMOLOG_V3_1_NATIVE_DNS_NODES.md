# HOMOLOG V3.1_NATIVE_DNS_NODES_HOMOLOG

Data: 2026-06-16
Ambiente: `/var/www/html/painel`
Status: homologacao parcial, bloqueada para fluxos destrutivos/provisionamento por falta de alvos e credenciais administrativas.

## Base local validada

- `php -l dns-servers.php`: OK
- `php -l includes/dns_servers.php`: OK
- `php -l includes/setup_db.php`: OK
- Banco SQLite contem: `audit_logs`, `dns_servers`, `usuarios`.
- `dns_servers` contem colunas V3: `agente_status`, `bind_status`, `zonas_slave`, `modo_instalacao`.

## Servidor real encontrado no painel

Registro atual:

- id: `3`
- nome: `ns2`
- hostname/IP: `45.162.196.243`
- IPv6: `2804:52bc::243`
- usuario gerenciado: `dns-sync`
- porta SSH: `22`
- status no painel: `online`
- agente: `instalado`
- BIND: `ok`
- zonas slave: `6`

## Testes reais executados contra NS2 existente

### SSH pelo mesmo usuario/chave do painel

Comando equivalente executado como `www-data`:

```sh
ssh -F /dev/null   -i /var/www/.ssh/id_ed25519_dns_sync   -o BatchMode=yes   -o IdentitiesOnly=yes   -o StrictHostKeyChecking=yes   -o UserKnownHostsFile=/var/www/.ssh/known_hosts   -o ConnectTimeout=6   -p 22 dns-sync@45.162.196.243   'sudo -n /usr/local/bin/dns-slave-status.sh'
```

Resultado: OK.

Dados retornados:

```text
hostname=ns2.conectanetwork.net.br
debian_version=13.5
bind_version=9.20.23-1~deb13u1-Debian (Stable Release) <id:>
bind_active=active
bind_enabled=alias
named_checkconf=ok
slave_zones=6
directories=/var/cache/bind/slave-aut,/var/cache/bind/slave-rev
```

### Funcao PHP `dns_server_executar_teste(..., connection)`

Resultado: OK.

```text
SSH_OK
```

### Funcao PHP `dns_server_executar_teste(..., status)`

Resultado: OK.

- agente derivado: `instalado`
- BIND derivado: `ok`
- zonas slave derivadas: `6`

### Funcao PHP `dns_server_executar_teste(..., transfer, conectanetwork.net.br)`

Resultado: OK.

```text
zone=conectanetwork.net.br
soa=ns1.conectanetwork.net.br. hostmaster.conectanetwork.net.br. 2026051906 900 3600 2419200 300
authoritative=yes
serial=2026051906
```

## Auditoria

Consulta por eventos `DNS_SERVER_%` retornou vazia neste momento.

Isso significa que a auditoria V3 ainda precisa ser validada pelo fluxo web real em `dns-servers.php`, porque os testes acima foram executados diretamente por CLI/PHP para validar transporte remoto e nao passaram por POST autenticado da tela.

Eventos a validar no fluxo web:

- `DNS_SERVER_ADD`
- `DNS_SERVER_REMOVE`
- `DNS_SERVER_TEST`
- `DNS_SERVER_AGENT_INSTALL`
- `DNS_SERVER_AGENT_UPDATE`
- `DNS_SERVER_AGENT_REMOVE`

## Perfis

Usuarios ativos encontrados:

- `administrador`: 2
- `moderador`: 0

Resultado:

- Administrador: estrutura de permissao existe e `dns-servers.php` chama `exigir_administrador()`.
- Moderador: nao homologado por ausencia de usuario moderador ativo para teste de acesso 403.

## Rollback/falhas

Ainda nao homologado em ambiente real:

- falha de SSH durante bootstrap;
- falha de sudo;
- falha de instalacao do BIND;
- falha de bootstrap apos copia parcial;
- confirmacao de que credenciais administrativas nao sao persistidas apos falha.

Esses cenarios exigem servidores de teste ou credenciais administrativas controladas para provocar falhas sem risco operacional.

## Itens bloqueados para aprovacao completa

Para concluir V3.1, faltam dados de homologacao:

1. Servidor Debian 13 com BIND ja instalado, ainda nao adotado pelo V3, com SSH administrativo temporario.
2. Servidor Debian 13 limpo, com SSH ativo e IPv4 configurado, para provisionamento completo.
3. Credenciais administrativas temporarias para ambos os servidores, por senha ou chave.
4. Usuario moderador ativo, ou autorizacao para criar um usuario moderador de teste.
5. Autorizacao explicita para executar testes destrutivos/controlados:
   - remover agente remoto;
   - remover servidor do painel;
   - provocar falhas de sudo/bootstrap;
   - provisionar pacote BIND em servidor limpo.

## Status por criterio

- Adoção real validada: parcial. O NS2 real existente responde com agente/BIND, mas a adocao via web de um servidor ainda nao adotado nao foi executada.
- Provisionamento real validado: nao executado.
- Atualizacao validada: nao executado em ambiente real.
- Remocao validada: nao executado em ambiente real.
- Auditoria validada: nao executado via POST web.
- Sem intervencao manual no servidor: ainda nao aprovado, depende dos fluxos web reais acima.

## Decisao

V3.1 nao deve ser marcado como aprovado ainda. O V3 esta funcional em validacoes nao destrutivas contra o NS2 real, mas a homologacao completa depende de alvos e credenciais para executar o ciclo de vida inteiro pela web.
