# Release 1.3.55

Auditoria de lógica feita em 02/10/2026. Produção estava saudável (sem erros nos
logs, nada travado, zonas e observações consistentes, sem duplicatas, sem job
falho); as lacunas abaixo são de **manutenção e alertas que faltavam**, não
falhas visíveis.

## Retenção dos eventos de idempotência dos agentes

`dns_authoritative_observation_events` e `dns_agent_bind_readiness_events`
crescem ~500 linhas/dia cada (dois agentes, relatórios de poucos em poucos
minutos) e nada as limpava — e o scheduler do Laravel não tinha nenhuma tarefa
definida. Elas só servem para descartar reenvio do **mesmo** relatório (agente,
`event_id`, hash); a ordem das observações é controlada por
`servers.authoritative_sequence`, não por elas.

- Novo comando `dns-center:prune-agent-events [--days=30] [--dry-run]`: apaga em
  lotes de 5000, mínimo de 7 dias (recusa menos que isso).
- Agendado todos os dias às 03:40 (`routes/console.php`); o container `scheduler`
  já roda `schedule:work`.
- `dns_bind_operation_events` fica de fora de propósito: volume baixo e o agente
  pode reenviar o relatório final de uma operação depois de uma queda longa.

## Dashboard: secundário que nunca recebeu a zona

O Dashboard só avisava `serial_mismatch`, `transfer_failed`, `primary_unreachable`
e `expired`. Um secundário que **nunca** recebe a zona (ACL, TSIG ou primário
errado) fica em `awaiting_transfer` e não gera nenhum log de falha — ficava
invisível. Agora aparece "Secundário sem receber a zona" (aviso) quando a zona foi
alterada há mais de 10 minutos e o secundário continua sem ela; logo após publicar,
quando é normal, não alerta.

## Backup: painel indisponível não vira mais "somente local" em silêncio

`dns-center-deploy backup` tratava qualquer falha ao ler as credenciais do R2 do
painel como "R2 não configurado". Se o app estivesse fora do ar na hora do backup,
a cópia externa simplesmente não acontecia, sem nenhum sinal. Agora
`dns-center:backup-r2-config` devolve código **3** quando não há configuração
(segue só local, sem erro); qualquer outro erro faz o backup **falhar alto** no
log, a não ser que exista o arquivo de credenciais (`DNS_CENTER_BACKUP_REMOTE_ENV`),
que então é usado com um aviso. O dump local e a limpeza já tinham sido feitos antes.

## Verificado e sem mudança

- Nenhum caminho de escrita de registros pula o hook de canonização do `DnsRecord`
  (conferido por busca no código).
- Importação em lote já recusava perfil sem identidades suficientes **antes** de
  tocar em qualquer zona (`profileForOrganization`); ganhou um teste de regressão.
- Cosmético, não corrigido: o resultado de "Forçar transferência" traz `zone_state`
  vazio (o `rndc zonestatus` do BIND 9.20 provavelmente não imprime essa linha); a tela
  não usa o campo.

## Testes

418 (PHP) e 173 (Python). Novos: `PruneAgentEventsTest` (5), dois do Dashboard,
um da importação em lote e três do upload de backup (não configurado, painel
indisponível sem arquivo, painel indisponível com arquivo). Sem migration.
