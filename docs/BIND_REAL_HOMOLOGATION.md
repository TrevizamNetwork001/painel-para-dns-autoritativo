# Homologação real do agente e do BIND

Data: 2026-07-29
Escopo: agente DNS Center `0.2.0`, aplicação Laravel em `testing` e BIND
autoritativo em ambiente descartável.

## Resultado

A homologação ponta a ponta foi concluída sem deploy em produção. O agente
instalou e configurou BIND por uma operação allowlisted, recebeu uma publicação
explícita, validou o snapshot, aplicou a zona, executou `rndc reconfig`, confirmou
a versão instalada no painel e respondeu consultas autoritativas por UDP e TCP.

Um defeito necessário ao fluxo básico foi encontrado e corrigido: no Debian 13,
`bind9.service` é alias de `named.service`, e
`systemctl enable --now bind9` falha com recusa a operar em unit vinculada. O
agente passou a selecionar a unit canônica `named` nas famílias Debian e RHEL.

## Pré-condições

- HEAD inicial: `a56f94e22880ab5762bb4aad1758e0e8802edf36`.
- Ordem confirmada em `git log --oneline -5`:
  - `a56f94e feat: integra agente ao ciclo de publicacoes`
  - `2d4d057 feat: adiciona prontidao e instalacao controlada do bind`
  - `a92b3b1 feat: registra aplicacao de publicacoes por agente`
  - `dbe3134 feat: completa fluxo de edicao e publicacao de zonas`
  - `9068801 fix: melhora icone do tema claro`
- O status foi confirmado limpo no container da aplicação, cujo UID é o owner
  real do volume. A leitura inicial pelo host apresentou um falso `M` porque o
  sandbox não podia ler o arquivo `0750`; SHA-256, `git diff --quiet` e status no
  container confirmaram conteúdo idêntico ao HEAD.
- `scripts/check-testing-database.sh` confirmou:
  `APP_ENV=testing`, `DB_CONNECTION=pgsql` e
  `DB_DATABASE=dns_center_testing`.
- Foi executado somente `php artisan migrate --force`, com resultado
  `Nothing to migrate`. `migrate:fresh` não foi usado.

## Topologia descartável

- Host do Docker: Debian 13 (trixie), Docker 29.6.2, `overlayfs`, cgroup v2.
- Painel: processo HTTP auxiliar isolado no container da aplicação, ligado
  exclusivamente a `dns_center_testing`.
- Agente/BIND: container privilegiado dedicado
  `dns-center-bind-homolog`, Debian 13.6, sem publicação da porta 53 no host.
- Rede: bridge Docker privada. O agente acessou o painel por relay em
  `127.0.0.1`, pois o próprio agente rejeitou corretamente HTTP fora de
  localhost.
- Init: systemd 257 como PID 1. O estado global ficou `degraded` somente porque
  `systemd-modules-load.service` não pode carregar módulos no kernel
  compartilhado do container. As units `named` e do agente foram validadas
  normalmente.
- A zona usou `homolog.invalid`, domínio reservado, sem delegação pública e sem
  dados de cliente.

## Fluxo executado

1. O container iniciou sem BIND; `command -v named` e `dpkg-query` não
   encontraram o pacote.
2. Foram criados organização, servidor standalone/staging e código de ativação
   exclusivos na base testing.
3. O agente foi ativado e gravou a configuração em
   `/etc/dns-center-agent/agent.json`.
4. Inventário e prontidão foram enviados; o painel registrou BIND ausente.
5. Um plano `install_bind` foi criado como `planned` e, em etapa separada,
   autorizado por confirmação forte.
6. A operação allowlisted executou `apt-get update` e instalou apenas `bind9` e
   `bind9-utils`.
7. A primeira execução revelou o defeito da unit `bind9`. O rollback restaurou
   `named.conf`, removeu o include incompleto e manteve o serviço inativo.
8. Após a correção e teste de regressão, os pacotes foram removidos somente do
   container descartável para repetir uma instalação limpa.
9. A nova operação autorizada instalou BIND 9.20.26, criou o include gerenciado,
   validou a configuração, habilitou/iniciou `named` e reportou `succeeded`.
10. A zona foi salva como `ready`, versão 1, com zero publicações. Uma transação
    separada fez a publicação explícita, gerando snapshot imutável, versão 2,
    serial `2026072902` e destino `pending`.
11. O sync sem `--apply` baixou e validou o artefato no staging. Um restart real
    da unit do agente repetiu o dry-run sem criar zona ativa.
12. A aplicação exigiu simultaneamente
    `DNS_CENTER_AGENT_ALLOW_APPLY=1` e a frase forte vinculada ao servidor.
13. O arquivo foi gravado atomicamente, a configuração final passou,
    `rndc reconfig` foi executado e o agente reportou `applied`.
14. O painel registrou versão instalada 2, sem erro de aplicação.

## Caminhos e permissões

| Caminho | Owner:group | Modo |
|---|---:|---:|
| `/etc/dns-center-agent` | `root:root` | `0750` |
| `/etc/dns-center-agent/agent.json` | `root:root` | `0600` |
| `/var/lib/dns-center-agent/state.json` | `root:root` | `0600` |
| `/etc/bind` | `root:bind` | `2755` |
| `/etc/bind/dns-center-zones` | `root:bind` | `2755` |
| `/etc/bind/dns-center-zones/homolog.invalid.zone` | `root:bind` | `0640` |
| `/etc/bind/dns-center-managed.conf` | `root:bind` | `0640` |
| `/var/backups/dns-center-agent` | `root:root` | `0755` |

Uma tentativa real de `touch` como `nobody` no diretório de zonas foi negada.

## Validações BIND

- `named-checkzone homolog.invalid ...`: `loaded serial 2026072902`, `OK`.
- `named-checkconf /etc/bind/named.conf`: sucesso.
- `rndc status`: servidor ativo, BIND 9.20.26, configuração final carregada.
- `rndc reconfig`: sucesso.
- `systemctl is-active named`: `active`.
- Processo: `/usr/sbin/named -f -u bind`.
- Sockets: listeners UDP e TCP em 53, tanto no loopback quanto na interface
  privada do container.

## Respostas DNS

Todas as respostas abaixo apresentaram flag `aa`:

| Consulta | Transporte | Resultado |
|---|---|---|
| `homolog.invalid SOA` | UDP | serial `2026072902`, TTL 3600 |
| `homolog.invalid NS` | UDP | dois NS |
| `mail.homolog.invalid A` | UDP | endereço de documentação, TTL 300 |
| `www.homolog.invalid AAAA` | UDP | endereço de documentação, TTL 600 |
| `alias.homolog.invalid CNAME` | UDP | alvo `www`, TTL 600 |
| `homolog.invalid MX` | UDP | prioridade 10, TTL 3600 |
| `homolog.invalid TXT` | UDP | texto de homologação, TTL 3600 |
| nome inexistente | UDP | `NXDOMAIN` e SOA na authority |
| `mail.homolog.invalid A` | TCP | mesma resposta autoritativa |

## Falhas controladas

- **Zona inválida:** artefato real corrompido no staging foi bloqueado por
  `named-checkzone`. Os hashes ativos permaneceram iguais e a zona anterior
  continuou respondendo com `aa`.
- **Configuração inválida:** include sintaticamente incompleto no staging foi
  bloqueado por `named-checkconf`; nenhum arquivo ativo mudou.
- **Falha após escrita:** uma exceção controlada foi injetada imediatamente
  após a escrita atômica do arquivo ativo. O backup real restaurou zona e
  include aos hashes anteriores, e BIND permaneceu autoritativo.
- **Falha de `rndc`:** o caminho de `rndc` foi apontado controladamente para
  `/usr/bin/false`. A aplicação falhou, executou rollback e não alterou a versão
  confirmada como `applied`.
- **Reexecução:** a mesma publicação retornou `up_to_date`, com zero updates.
  SHA-256, mtimes, quantidade de backups e uma única declaração de zona no
  include permaneceram inalterados.
- **Restart:** após download, a unit executou somente dry-run e não aplicou.
  Após aplicação, o restart não reaplicou nem criou backup adicional.
- **Symlink/traversal:** symlink em caminho gerenciado e nome
  `../homolog.invalid` foram rejeitados usando o filesystem real.

## Segurança

- HTTP fora de localhost foi rejeitado antes do envio de credencial.
- A credencial de ativação foi de uso único e todas as cópias transitórias foram
  apagadas.
- O token operacional existe somente no arquivo de configuração `0600`; não
  apareceu no state local, logs examinados ou saída dos testes.
- Os eventos persistem hashes e estados, não headers HTTP nem assinaturas.
- O painel entrega somente ações enumeradas (`install_bind` e
  `configure_bind`); não há comando shell livre.
- `subprocess.run` usa lista de argumentos, `shell=False`, `capture_output`,
  timeouts por operação e truncamento de stdout/stderr em 8000 bytes.
- Mensagens e resultados enviados ao painel passam por sanitização e limite.

## Diferenças entre mocks e ambiente real

- Os mocks não detectaram que Debian 13 expõe `bind9.service` apenas como alias
  e recusa `enable` nesse nome. A unit canônica `named` foi necessária.
- O systemd real em container funciona para as units em escopo, mas não valida
  carregamento de módulos do kernel; uma VM continua sendo superior para esse
  aspecto fora do escopo do BIND.
- `bind9-utils` não forneceu `dig` no Debian 13; `dnsutils` foi instalado apenas
  como cliente de homologação, não pela operação do agente.
- O isolamento de `/tmp` pelo systemd exigiu transferir a ativação por caminho
  privado controlado. Nenhum segredo foi incluído neste documento.

## Limitações restantes

- AXFR/IXFR, NOTIFY, TSIG e failover não foram implementados nem testados.
- Não houve delegação pública nem teste a partir de rede externa.
- A carga de módulos do systemd não é representativa em container; as units
  `named` e do agente, processo e sockets foram validados.
- SMTP não foi alterado.
- Nenhum deploy em produção foi realizado.
