# Descoberta e importação segura de BIND existente

## Discover / Import / Adopt

O DNS Center separa três conceitos que nunca podem ser confundidos:

- **Descobrir**: inventário somente leitura de um BIND já existente — zonas,
  seriais, metadados de arquivo. Nada é escrito, nada é publicado.
- **Importar**: copiar a representação lógica de uma zona descoberta para o
  banco do DNS Center, marcada como importada e **não gerenciada**. Ainda não
  é publicação.
- **Adotar**: fase futura onde o DNS Center passa a gerar e aplicar a
  configuração ativa daquela zona. Não implementada nesta fase.

Esta fase implementa somente Descobrir e Importar.

## Garantias read-only da descoberta

Durante a descoberta o agente executa exclusivamente:

- `rndc status`;
- `named-checkconf -p` sobre o `named.conf` detectado;
- `rndc zonestatus <zona>` por zona declarada;
- `named-checkzone -D <zona> <arquivo>` (somente zonas `primary` dentro do
  limite de tamanho) — ferramenta de validação do próprio BIND, nunca escreve
  no arquivo;
- leitura de metadados de arquivo (`stat`, hash SHA-256 opcional).

Nunca executa `rndc reload/reconfig/freeze/thaw/addzone/delzone`, nunca
reinicia ou recarrega serviço, nunca escreve em `/etc/bind` ou
`/var/cache/bind`, nunca altera zonefile, include, TSIG, listener ou
topologia primary/secondary. Todos os subprocessos usam argumentos fixos,
`shell=False`, timeout e saída limitada, seguindo o mesmo padrão de
`--readiness`/`--observe-bind`.

## Por que `named-checkzone -D`

Em vez de escrever um parser de zonefile bruto (sintaxe legada de nomes
relativos, `$ORIGIN`/`$TTL`, parênteses multi-linha, comentários), a
descoberta usa a própria ferramenta de validação do BIND para produzir uma
representação canônica (um registro lógico por linha, nomes já resolvidos).
O agente só precisa entender esse formato normalizado — muito mais simples e
robusto que reimplementar o parser de entrada do BIND. Owners omitidos
("mesma linha anterior") são resolvidos pela indentação da linha, não por
heurística de conteúdo — um rótulo puramente numérico (comum em zonas
reversas) é um nome de owner válido, não um TTL.

## Segredos TSIG

`named-checkconf -p` ecoa a configuração efetiva, incluindo blocos
`key { secret "..."; }` em texto puro. Antes de qualquer outro processamento,
o agente remove todo bloco `secret "...";` do texto bruto — essa etapa roda
antes do `sanitize_message` genérico, que não reconhece essa sintaxe. Nome e
algoritmo da chave podem aparecer na descoberta; o segredo nunca.

## Tipos de registro

Suportados: `A`, `AAAA`, `CNAME`, `MX`, `TXT`, `CAA`, `NS`, `PTR`. `SOA` é
extraída separadamente (preenche `soa_*` na importação, nunca vira um
registro comum). Tipos não suportados (ex. `SRV`) são preservados como
metadado (`unsupported_record_types`) sem derrubar o parse do restante da
zona — mas bloqueiam a importação daquela zona até tratamento manual.

## Zonas secondary

Nunca têm conteúdo capturado (`named-checkzone -D` não roda para elas).
Descoberta reporta apenas metadados (masters/primaries configurados,
allow-notify, referência de chave TSIG por nome). Uma zona secondary nunca
vira zona primary editável por importação — o estado de comparação é sempre
`secondary_external`.

## Estados de comparação

Cada zona descoberta é comparada com o banco do tenant (por nome e, quando já
existe, por serial e contagem de registros — não só pelo nome):

- `new` — existe no BIND, não existe no painel;
- `exists` — já existe no painel com serial/contagem compatíveis;
- `conflict` — já existe no painel, mas diverge;
- `secondary_external` — zona secondary, nunca importável nesta fase;
- `not_supported` — contém registro não suportado ou falhou validação;
- `imported` — já foi importada anteriormente.

Somente zonas `new` podem ser selecionadas para importação pela UI.

## Importação

Cria `DnsZone` (`origin=bind_import`, `status=draft`) e `DnsRecord` a partir
do conteúdo já capturado na descoberta, preservando serial, TTL e SOA
originais. Nunca roda o sincronizador de nameservers (que reescreveria
NS/glue), nunca cria `DnsAgentPublication`, nunca publica. Importação em lote
é transacional por zona — falha em uma não corrompe as demais.

## Zona importada não pode ser publicada

`DnsZoneController::publish()` rejeita explicitamente qualquer zona com
`origin=bind_import` (409), independente do estado do validador. A UI mostra
"Conclua a adoção do gerenciamento antes de publicar esta zona." Isso evita
sobrescrever acidentalmente um servidor já em produção.

## Idempotência e mudança externa

Descoberta é disparada da UI ("Executar nova descoberta"), reaproveitando o
mecanismo de operação autorizada já existente (`DnsBindOperation`, nonce,
dedup por `event_id`+`payload_hash`) — sem exigir a frase de confirmação forte
usada para instalar/configurar BIND, já que é leitura pura. Execuções
repetidas com o mesmo evento não duplicam nada. Como o BIND continua
administrado por CLI em paralelo, uma nova descoberta pode revelar serial ou
hash diferentes do que foi importado — o painel nunca sobrescreve ou corrige
automaticamente; apenas expõe a divergência para revisão manual.

## Limites

Tamanho máximo de zonefile lido (2 MiB, mesmo padrão de artefato de zona),
máximo de registros por zona (20000) e de zonas por descoberta (200), timeout
por comando, saída de comando truncada. Excedentes viram aviso
(`validation_status=warning`), nunca crash do agente ou do painel.

## O que esta fase não faz

- Não aplica, publica ou envia zona para o agente.
- Não recarrega/reinicia BIND.
- Não altera `named.conf`, include, TSIG ou topologia.
- Não adota gerenciamento automaticamente — isso é uma fase futura separada,
  autorizada explicitamente por zona.

## Homologação em servidor real — DISCOVER

**Status: DISCOVER homologado em servidor real. IMPORT não executado. ADOPT
fora de escopo.**

Servidor: `ns1.legacy.example` (BIND `9.20.26-1~deb13u1-Debian`,
gerenciamento atual externo/CLI, agente `0.6.0`). Descoberta real executada e
concluída em 2026-08-27, via operação assíncrona (`discover_bind_zones`),
usando `named-checkconf -p`, `rndc zonestatus` e `named-checkzone -D` reais
contra a configuração e as zonas de produção do servidor.

### Resultado da descoberta

6 zonas encontradas, todas `primary`, 0 `secondary`:

| Zona | Serial | Nodes (BIND) | Registros parseados |
|---|---|---|---|
| `legacy.example` | 2026082701 | 1030 | 1038 |
| `192.0.2.in-addr.arpa` | 2026082701 | 257 | 258 |
| `197.162.45.in-addr.arpa` | 2026082701 | 257 | 258 |
| `198.162.45.in-addr.arpa` | 2026082701 | 257 | 258 |
| `199.162.45.in-addr.arpa` | 2026082701 | 257 | 258 |
| `8.b.d.0.1.0.0.2.ip6.arpa` | 2026082701 | 4 | 5 |

Todas as 6 zonas ficaram com `comparison_state=new` (não existem no painel) e
`validation_status=ok`. A diferença entre "nodes" (contagem que o próprio
`rndc zonestatus` reporta, por nome de owner) e "registros parseados"
(contagem de RRs individuais extraídos do dump canônico) é esperada e
intencional — um mesmo owner pode ter múltiplos RRs (ex. múltiplos `NS` ou
`A`/`AAAA` na mesma zona) — e a tela distingue as duas contagens
explicitamente para não confundir o operador.

A preview real confirmou preservação correta de SOA (MNAME, RNAME, serial,
refresh, retry, expire, minimum), TTL, NS, PTR e conteúdo/RDATA de cada
registro, na zona forward com mais de 1000 registros e nas quatro reversas
IPv4 e na reversa IPv6.

### Bugs corrigidos antes desta homologação

- **Conteúdo "—" na preview**: a view lia a chave `rdata` do payload, mas o
  formato persistido usa `content`. A zona `legacy.example` real foi o
  caso que expôs o bug (registros NS/PTR apareciam sem conteúdo). Corrigido
  lendo a chave certa; regressão coberta em teste.
- **Truncamento silencioso do dump do BIND**: `run_command()` no agente tinha
  um limite fixo de 8000 caracteres, cortando o dump de `named-checkzone -D`
  no meio de uma zona grande sem avisar — a mesma zona `legacy.example`
  chegou a ser descoberta com ~110 registros (fragmento truncado) antes da
  correção, em vez dos 1038 reais. Corrigido com detecção explícita de
  truncamento (`stdout_truncated`) e recusa a parsear um dump incompleto, em
  vez de silenciosamente processar um fragmento.

Depois das duas correções, a mesma zona real passou a ser descoberta por
completo (1038 registros), confirmando o fim do truncamento.

### Critério de fechamento do DISCOVER

Comprovado em servidor real, não apenas em teste automatizado: agente real,
BIND real, `named-checkconf`/`rndc`/`named-checkzone -D` reais, zona forward
com mais de 1000 registros, quatro reversas IPv4 reais, uma reversa IPv6
real, SOA/NS/PTR/serial reais, paths reais, preview real, operação
assíncrona real ponta a ponta — sem nenhuma alteração no BIND.

### IMPORT e ADOPT — pendentes, por decisão explícita

**IMPORT não foi executado nesta rodada.** Nenhuma zona (nem as reversas, nem
`legacy.example`) foi importada. Isso é proposital: o servidor
continua administrado por CLI, e importar uma zona real apenas para "fechar
cobertura de teste" criaria uma zona `bind_import` sem necessidade
operacional real. O próximo IMPORT — quando decidido explicitamente — deve
começar por uma zona piloto pequena (candidata natural: a reversa IPv6,
5 registros), autorizada separadamente desta homologação.

**ADOPT continua fora de escopo** — não há fluxo implementado para o DNS
Center assumir gerenciamento ativo de uma zona.

Estado atual do servidor, sem ambiguidade:

- Descoberto: concluído.
- Importado: não iniciado.
- Gerenciado: não iniciado.
- Gerenciamento: Externo / CLI (inalterado).

### Confirmações

Nenhuma zona foi importada. Nenhuma publicação foi criada. Nenhuma escrita,
reload, reconfig ou restart do BIND ocorreu — a fase DISCOVER só executa os
comandos de leitura listados em "Garantias read-only da descoberta" acima;
nenhum comando adicional foi executado no servidor real só para gerar
evidência desta homologação.

### Riscos restantes

- O BIND continua administrado por CLI em paralelo — uma alteração externa
  (novo serial, zona nova, registro alterado) só aparece no painel na próxima
  descoberta manual; não há detecção automática de drift nesta fase.
- IMPORT ainda não foi exercitado em servidor real (só em teste automatizado)
  — o primeiro IMPORT real deve ser tratado como piloto, com uma zona
  pequena, não com a zona forward de 1038 registros.
- ADOPT não existe; qualquer expectativa de gerenciamento ativo continua
  dependendo 100% do CLI até essa fase futura ser implementada e homologada.

## Roadmap (futuro, não iniciado)

1. **IMPORT-PILOT** — importar uma zona pequena (candidata: a reversa IPv6)
   como `bind_import`/não gerenciada.
2. Validação de round-trip do conteúdo importado no banco.
3. Confirmação do bloqueio de publicação em zona importada, em servidor real.
4. Comparação de alteração externa (drift) após um import.
5. **ADOPT** — fluxo explícito e protegido para o DNS Center assumir
   gerenciamento de uma zona, autorizado separadamente por zona.
