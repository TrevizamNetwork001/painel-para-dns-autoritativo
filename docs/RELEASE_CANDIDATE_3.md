# DNS Center v1.0.0-rc3

Data: 2026-08-29

## Escopo

Esta release candidate contém exclusivamente a correção do blocker de
idempotência encontrado durante o gate de promoção da `v1.0.0-rc2`. Nenhuma
funcionalidade foi adicionada e não houve alteração em BIND, zonas, TSIG,
delegações, IMPORT ou ADOPT.

## Blocker e causa

`DnsBindRuntimeController::report()` gerava o fingerprint de um relatório
DISCOVER usando o resumo que seria persistido na operação. Esse resumo contém
`discovered_at`, preenchido localmente com `now()`. Assim, uma repetição
legítima do mesmo `event_id` e do mesmo resultado depois da mudança do segundo
produzia outro hash e era recusada como `event_replay` com HTTP 409.

## Correção

O helper `operationEventFingerprintPayload()` passou a construir explicitamente
a identidade semântica estável do report:

- identidade da operação;
- status;
- resumo sem o timestamp local `discovered_at`;
- erro sanitizado;
- lista normalizada de zonas descobertas.

`discovered_at` continua persistido para observabilidade, mas não participa do
fingerprint. A ordem das listas não foi alterada. O mesmo `event_id` com o mesmo
payload semântico retorna HTTP 200 idempotente; o mesmo `event_id` com conteúdo
divergente continua retornando HTTP 409; um novo `event_id` segue o contrato
existente de report tardio legítimo.

## Testes e gates

- DISCOVER focado: 28 testes, 134 assertions;
- reports/replay/operações relacionados: 47 testes, 296 assertions;
- Laravel completo: 217 testes, 1198 assertions, sem falhas, erros ou deadlocks;
- agente Python: 96 testes e `py_compile` aprovado;
- Pint: 156 arquivos aprovados;
- PHP lint, `view:cache` e `route:list`: aprovados;
- build Vite production-only: aprovado;
- `bash -n` e `git diff --check`: aprovados;
- 31 migrations executadas, nenhuma pendente;
- `dns-center:security-check`: controles internos aprovados, com o warning
  factual já conhecido de `TRUSTED_PROXIES` vazio no ambiente sem proxy
  confiável.

As regressões novas cobrem retry no mesmo segundo, retry depois da mudança do
tempo de processamento, preservação do timestamp/proveniência, ausência de
duplicação de zonas, replay semanticamente divergente, novo `event_id` e
isolamento entre agentes, servidores e tenants. Os testes existentes continuam
cobrindo payload grande, 1038 RRs, Primary/Secondary, SOA, tipos não suportados
e rejeição de saída truncada.

## Homologação read-only

Após deploy controlado da imagem local `1.0.0-rc3-candidate`, a operação
DISCOVER 37 foi executada em `ns1.conectanetwork.net.br`:

- status `succeeded`;
- 6 zonas primary e 0 secondary;
- `conectanetwork.net.br`: serial `2026082701`, 1030 nodes, 1038 RRs e
  validação `ok`;
- agente online em 0.7.4, readiness presente e BIND 9.20.26 ativo.

A operação foi somente leitura. Nenhum arquivo ou configuração BIND foi
alterado, nenhuma zona foi importada/adotada e nenhum replay artificial foi
inserido em produção. A suíte automatizada é a evidência do retry do mesmo
evento.

O deploy formal criou e validou backup antes das migrations, confirmou que não
havia migration nova e deixou app, web, queue e scheduler saudáveis. Nenhuma
imagem ou tag foi enviada para serviço externo.

## Promoção

Esta RC deve passar por uma nova tentativa de promoção em execução separada.
`v1.0.0` não foi criada neste fechamento.
