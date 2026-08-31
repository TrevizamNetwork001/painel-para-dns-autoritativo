# RC-HOMOLOGATION-2

Data: 2026-08-31
Escopo: homologação operacional curta da `v1.0.0-rc3`, exigida por
[`RELEASE_CANDIDATE_2.md`](RELEASE_CANDIDATE_2.md) ("A versão `v1.0.0`
somente poderá ser criada depois de uma homologação operacional curta desta
RC2") e ainda pendente após o fechamento de
[`RELEASE_CANDIDATE_3.md`](RELEASE_CANDIDATE_3.md).

## Resultado

**Homologação operacional aprovada.** Os 3 comportamentos de idempotência
corrigidos pelo commit `6aedb40` foram confirmados ao vivo, via HTTP real
contra uma instância da aplicação rodando o código atual — não apenas pela
suíte automatizada (PHPUnit com `travel()` simulando o tempo). Isto fecha a
lacuna que o próprio `RELEASE_CANDIDATE_3.md` reconhecia: "nenhum replay
artificial foi inserido em produção. A suíte automatizada é a evidência do
retry do mesmo evento."

## Escopo

Exclusivamente o comportamento de
`DnsBindRuntimeController::report()`/`operationEventFingerprintPayload()`
corrigido pela rc3. Sem BIND, zonas, TSIG, delegações, IMPORT ou ADOPT — a
rc3 não alterou nada nessas áreas, então não havia necessidade de repetir a
homologação completa de 37 itens de [`RC_HOMOLOGATION_1.md`](RC_HOMOLOGATION_1.md).

## Ambiente

- HEAD homologado: `bacf3bd` (contém o fix `6aedb40`).
- Banco: `dns_center_testing`, isolado do banco de produção `dns_center`,
  com gate obrigatório via `app/Support/TestingDatabaseGuard.php` e
  `scripts/check-testing-database.sh` (recusa qualquer banco que não termine
  em `_testing` ou que seja exatamente `dns_center`).
- HTTP: `php artisan serve` efêmero, ligado somente a `127.0.0.1`, rodando
  dentro do container `app` já em execução — mesmo padrão descrito em
  [`BIND_REAL_HOMOLOGATION.md`](BIND_REAL_HOMOLOGATION.md). Nunca exposto ao
  host nem à rede externa.
- Dados: uma organização, admin, servidor e agente sintéticos (org #23,
  servidor #23, agente #22, operação #15), criados diretamente via Eloquent
  `::query()->create()`, replicando os mesmos valores usados pelos helpers
  privados de `tests/Feature/DnsBindDiscoveryTest.php`. Zona e host usam o
  TLD reservado `.invalid`. Nenhuma referência a
  `ns1.legacy.example` ou `ns1.customer.example`.

**Nota técnica**: `Organization::factory()` e `DnsServer::factory()` falham
fora do bootstrap do PHPUnit (`Error: Call to a member function unique() on
null` — `$this->faker` não é resolvido pelo container nesta imagem quando
invocado via `php artisan tinker`). Contornado criando os registros
diretamente, sem depender de factory. Não é um bug de produção; nenhum
código foi alterado por causa disso.

## Os 5 requests HTTP e o estado do banco após cada um

| # | Cenário | HTTP esperado | HTTP obtido | Banco |
|---|---|---|---|---|
| 0 | `authorized` → `running` | 200 | `200 idempotent:false` | 1 evento, 0 zonas |
| 1 | descoberta inicial (1 zona fake) | 200 | `200 idempotent:false` | 2 eventos, 1 zona, `discovered_at=2026-08-31T15:26:13-03:00` |
| 2 | **mesmo `event_id`, mesmo corpo, após `sleep 2`** | 200 idempotente | `200 idempotent:true` | idêntico ao passo 1, byte a byte |
| 3 | mesmo `event_id`, serial divergente | 409 `event_replay` | `409 event_replay` | idêntico ao passo 1 |
| 4 | `event_id` novo, corpo original, operação já `succeeded` | 200 idempotente, no-op | `200 idempotent:true` | idêntico ao passo 1, nenhuma linha nova |

O passo 2 é a prova direta do bug corrigido pela rc3: antes do fix, o mesmo
`event_id` repetido depois do relógio avançar produzia um hash de
fingerprint diferente (por causa de `discovered_at` vazando na identidade
semântica) e era recusado como `event_replay` (HTTP 409) em vez de retornar
200 idempotente. O passo 4 confirma, por leitura direta do código
(`report()`, `DnsBindRuntimeController.php:240-298`), que um relatório
tardio legítimo com operação já em estado terminal é aceito via HTTP mas não
gera nenhuma escrita nova — comportamento correto e intencional.

## Limpeza e isolamento

Todos os registros sintéticos (organização, servidor, agente, operação, 2
eventos, 1 zona descoberta, 1 usuário admin) foram apagados ao final;
confirmado por contagem — zero linhas remanescentes nas 7 tabelas
envolvidas. O servidor HTTP efêmero foi confirmado parado (o processo
`php -S` filho sobreviveu à primeira tentativa de encerrar o processo pai do
`artisan serve` e precisou ser encerrado separadamente; confirmado via
varredura de `/proc` e por conexão recusada). A contagem de
`dns_bind_operations`/`dns_bind_operation_events`/`dns_bind_discovered_zones`
do banco de produção `dns_center` foi conferida antes (37/68/84) e depois
(37/68/84) da execução — idêntica, sem nenhum impacto em produção.
`dns_center_testing` não foi apagado (recurso compartilhado, usado pelo
PHPUnit).

## O que isto não cobre

BIND, zonas, TSIG, primary/secondary, IMPORT, ADOPT ou qualquer agente real
— fora de escopo, pois a rc3 não tocou nessas áreas. Nenhuma tag git foi
criada, apagada ou usada por este documento ou pela execução que ele
descreve.

## Observação sobre a tag `v1.0.0`

No repositório já existe uma tag `v1.0.0` local, criada em 2026-08-29
(17 minutos após a `v1.0.0-rc3`), sem nenhuma documentação correspondente e
contradizendo o texto do próprio `RELEASE_CANDIDATE_3.md` ("`v1.0.0` não foi
criada neste fechamento"). Sua origem não foi determinada e ela **não foi
tratada, movida, apagada nem usada como referência** nesta homologação — é
uma decisão em aberto, separada do resultado técnico registrado aqui.

## Conclusão

O requisito de homologação operacional curta citado em
`RELEASE_CANDIDATE_2.md` como pré-condição para `v1.0.0` está atendido para
o escopo da rc3. A decisão final de promoção — e a reconciliação da tag
`v1.0.0` já existente — permanece como próximo passo, não coberto por este
documento.
