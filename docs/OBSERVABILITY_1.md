# OBSERVABILITY-1 — BIND autoritativo

Data da homologação: 2026-07-30

## Escopo

Esta fase adiciona observabilidade factual da topologia primary/secondary e
endurece o BIND para atuar somente como autoritativo. Ela não promove
secondaries, não altera delegação ou registros NS e não implementa failover
ativo.

## Modelo de observabilidade

Cada agente envia uma sequência monotônica, um `event_id` UUID e o horário da
coleta. O painel mantém o estado atual por servidor e zona, além do hash dos
eventos aceitos.

Eventos repetidos com o mesmo conteúdo são idempotentes. A reutilização do
mesmo `event_id` com outro conteúdo é rejeitada. Sequências antigas recebem
resposta idempotente e não substituem estado novo.

Os dados persistidos incluem:

- serial esperado e observado;
- papel primary ou secondary;
- estado da zona e da transferência;
- último refresh, próxima tentativa e expiração;
- primary observado;
- última transferência bem-sucedida e última falha;
- erro sanitizado e limitado;
- origem, sequência e timestamps do agente e do painel.

Segredos TSIG, arquivos de zona, comandos e logs brutos não fazem parte do
payload nem do modelo persistente.

## Fontes locais

O agente usa somente fontes e argumentos fixos:

- `rndc zonestatus <zona>`;
- `dig @<endereço-autorizado> <zona> SOA +norecurse`;
- estado do serviço;
- listeners TCP e UDP lidos em `/proc/net`;
- `journalctl --unit named --since -10 minutes --lines 200
  --output short-iso`.

Todos os subprocessos usam lista de argumentos, `shell=False`, timeout e
truncamento de saída. O journal enviado ao classificador fica limitado às
linhas recentes da zona e somente uma mensagem sanitizada de até 1000
caracteres pode sair do agente.

## Estados

| Estado | Interpretação |
|---|---|
| `synchronized` | serial local igual ao esperado |
| `awaiting_transfer` | secondary ainda sem serial local |
| `transferring` | transferência em andamento |
| `transfer_failed` | evento local recente de falha de transferência |
| `serial_mismatch` | serial servido diferente do esperado |
| `expired` | zona secondary expirada |
| `primary_unreachable` | falha factual de conexão com o primary |
| `unknown` | fontes locais insuficientes |

O evento mais recente do journal prevalece. Assim, uma transferência
bem-sucedida posterior a uma falha permite convergência imediata, sem esperar
a linha antiga sair da janela do journal.

## Política de transição e auditoria

O painel registra auditoria somente quando o estado muda:

- `dns.transfer_failed`;
- `dns.transfer_recovered`;
- `dns.serial_mismatch`;
- `dns.serial_converged`;
- `dns.zone_expired`;
- `dns.primary_unreachable`;
- `dns.primary_recovered`;
- `dns.recursion_detected`;
- `dns.recursion_disabled`.

Uma primeira observação com recursão habilitada gera detecção. A observação
inicial com recursão já desabilitada não gera uma falsa recuperação.

## Hardening autoritativo

As zonas continuam em `dns-center-managed.conf`. As opções globais ficam em
`dns-center-options.conf`, incluído dentro do bloco `options` já existente.
O agente não substitui o `named.conf`.

Antes de inserir o include, o agente remove somente diretivas globais
conflitantes do bloco existente, preservando opções como diretório e
validação DNSSEC. A configuração resultante define:

```text
recursion no;
allow-recursion { none; };
allow-query-cache { none; };
minimal-responses yes;
version none;
hostname none;
auth-nxdomain no;
transfer-format many-answers;
listen-on { <IPv4 autorizados>; };
listen-on-v6 { <IPv6 autorizados>; };
```

Não é adicionada uma política `allow-query { any; }`; a política preexistente
continua valendo. Os listeners usam somente endereços retornados pelo painel
para o servidor autenticado. A aplicação passa por `named-checkconf` do
`named.conf` completo e executa rollback dos includes, opções e zonas se a
validação ou o `rndc reconfig` falhar.

## Alertas internos

Sem SMTP ou Telegram, o painel destaca:

- primary ou servidor autoritativo offline;
- secondary sem atualizar;
- serial divergente;
- zona a menos de 24 horas da expiração;
- zona expirada;
- transferência falhando;
- recursão habilitada.

O dashboard usa contadores persistidos do tenant atual para primaries,
secondaries, sincronização, mismatch, falhas, expiração e publicações
pendentes.

## Homologação real descartável

Foram usados dois BIND 9.20.26 em uma rede Docker privada:

- primary: `172.31.53.10`;
- secondary: `172.31.53.11`;
- zona reservada: `observability.invalid`;
- transferência e NOTIFY autenticados por HMAC-SHA256;
- serial inicial: `2026073008`.

Resultados:

- `named-checkconf` aprovou primary e secondary;
- `named-checkzone` carregou a zona;
- SOA no primary e secondary respondeu com `aa`;
- nenhuma resposta apresentou `ra`;
- consulta recursiva externa recebeu `REFUSED` e
  `recursion requested but not available`;
- TCP e UDP 53 escutaram somente nos endereços autorizados;
- AXFR autenticado retornou a zona completa;
- o secondary recebeu NOTIFY e transferiu o serial com TSIG;
- com o primary desligado, o secondary continuou respondendo com `aa`;
- uma TSIG deliberadamente incorreta produziu `refresh: failure` e BADSIG;
- após restaurar a TSIG, a transferência retornou `success` e convergiu para
  o serial `2026073009`;
- o serial foi alterado novamente para comprovar detecção factual de
  divergência e convergência.

A expiração acelerada não foi aguardada até o fim: mesmo com SOA de laboratório
reduzido, o BIND aplicou limites internos e informou pelo `rndc` expiração cerca
de 13 minutos depois. O parser, a persistência, a interface e a auditoria de
`expired` foram cobertos por testes automatizados.

## Runbook de diagnóstico

1. Confirme serviço e configuração:

   ```text
   systemctl is-active named
   named-checkconf /etc/bind/named.conf
   rndc zonestatus exemplo.com
   ```

2. Confirme autoridade e ausência de recursão:

   ```text
   dig @IP exemplo.com SOA +norecurse
   dig @IP exemplo.net A
   ```

   A consulta autoritativa deve conter `aa`. A segunda deve ser recusada e não
   deve conter `ra`.

3. Compare o SOA local de cada servidor:

   ```text
   dig @IP_PRIMARY exemplo.com SOA +norecurse +short
   dig @IP_SECONDARY exemplo.com SOA +norecurse +short
   ```

4. Para `serial_mismatch`, confirme se a publicação esperada foi aplicada e
   execute `rndc reload` no primary ou `rndc refresh` no secondary após
   corrigir a causa.

5. Para `transfer_failed`, verifique conectividade TCP 53, correspondência da
   chave TSIG e endereços de `masters`/`also-notify`. Nunca copie o segredo
   para tickets ou logs.

6. Para `expired`, restaure o primary ou uma fonte de transferência válida,
   corrija TSIG/conectividade e solicite refresh manual. Não promova o
   secondary automaticamente.

## Limitações

- A disponibilidade é observada localmente; não substitui monitoramento
  externo de múltiplas redes.
- O journal é uma janela recente e limitada, não um histórico permanente.
- O horário de falha sem timestamp interpretável usa o horário da coleta.
- Não há promoção, failover ativo, mudança de NS/delegação, registrador,
  billing, SMTP ou Telegram.
- Nenhum deploy em produção faz parte desta fase.
