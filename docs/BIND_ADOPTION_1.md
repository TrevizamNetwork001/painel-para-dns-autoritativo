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
