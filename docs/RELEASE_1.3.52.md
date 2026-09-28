# Release 1.3.52

## Corrige DISC-001: falso conflito de descoberta por grafia de nome DNS

Ver `docs/KNOWN_ISSUES.md` (DISC-001) para o sintoma e a causa observados em
produção em 20/09/2026 (painel 1.3.51): 14 registros descobertos no BIND
contra 18 linhas cadastradas no painel, sendo 4 extras que só divergiam por
maiúsculas/ponto final (A/AAAA de ns1/ns2) — o painel acusava conflito com o
serial já batendo.

### Canonização única, na gravação do registro

`App\Support\DnsRecordCanonicalizer` define a forma canônica de um nome DNS
(minúsculas, sem ponto final) e uma chave de comparação por tipo. `DnsRecord`
passa a aplicá-la em todo `save()` (`static::saving`, ponto único — cobre
criação manual, importação de zona descoberta e sincronização de
nameservers, sem depender de cada chamador lembrar de normalizar):

- `name` (owner) é sempre canonizado, exceto para registros **PTR** — nesses,
  tanto o nome (derivado do IP, ex. `10.2.0.192.in-addr.arpa.`) quanto o
  conteúdo já são gravados com ponto final pelo assistente de reversos e pela
  sincronização de PTR desde antes desta release; é a forma canônica
  **estabelecida** para esse tipo e não deveria ser alterada.
- `content` é canonizado apenas quando ele mesmo é um nome DNS (`NS`, `CNAME`,
  `MX`); `A`/`AAAA`/`TXT`/`CAA` nunca são tocados (não são nomes).

### Descoberta compara conjunto canônico, não contagem bruta

`DnsBindRuntimeController::computeComparisonState()`: com serial igual,
compara os dois lados (banco e dump do BIND) pelo **conjunto** de chaves
canônicas (sem repetição), não mais pela contagem de linhas. Uma linha
duplicada só por grafia deixa de derrubar a comparação para `conflict` — ela
continua sinalizada para limpeza pelo comando de reconciliação, só não bloqueia
mais o fluxo normal de descoberta.

### Comando de reconciliação (dado já existente no banco)

A canonização na gravação não corrige retroativamente linhas já duplicadas
antes desta release. Para isso:

```
php artisan dns-center:reconcile-duplicate-records [--organization=ID] [--zone=ID] [--apply] [--force]
```

Por padrão só lista (somente leitura); `--apply` exige confirmação (ou
`--force` em uso não interativo). Por grupo duplicado, mantém uma linha
(recanonizada) e apaga o resto, com auditoria (`dns.record_duplicate_reconciled`).
**Não publica nenhuma zona** — a limpeza fica só no banco; publicar continua
manual e separado, como já era o contorno operacional documentado.

### Testes

- `tests/Unit/DnsRecordCanonicalizerTest.php` — forma canônica, split de MX,
  chave de comparação (inclusive o caso PTR: conteúdo não é tocado quando o
  tipo não está na lista de tipos "nome").
- `tests/Feature/DnsRecordCanonicalizationTest.php` — cobertura de modelo por
  tipo, inclusive a exceção do PTR e a preservação do marcador `@`.
- `tests/Feature/DnsBindDiscoveryTest.php` — reprodução do caso real (linhas
  duplicadas só por grafia não geram mais `conflict`) e a garantia de que uma
  divergência real de conteúdo, com serial igual, continua gerando `conflict`.
- `tests/Feature/ReconcileDuplicateDnsRecordsTest.php` — modo leitura,
  aplicação com confirmação/`--force`, auditoria, idempotência, isolamento por
  organização.
- Suíte completa (395 testes) e `DnsZoneNameserverProfileTest`/
  `DnsZonePtrSyncTest`/`DnsReverseZoneWizardTest` conferidos manualmente após a
  mudança, para garantir que a convenção de PTR com ponto final continua
  intacta.

Sem migration; sem mudança de comportamento do agente.
