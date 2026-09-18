# Release 1.3.36

## Modal "Remover declaração antiga" espera o relatório novo antes de recarregar

Antes, a página recarregava ~1s após o sucesso, mas a lista de conflitos vem do
relatório de prontidão que o agente envia alguns segundos depois; a tela mostrava
por engano um conflito já resolvido.

- `servers.bind.legacy-block.status` passa a devolver `readiness_refreshed`
  (`bind_readiness_at >= completed_at` da operação).
- O modal, após `succeeded`, mostra "Atualizando o estado do servidor…" e consulta
  o status a cada 2s; recarrega quando o relatório chega, ou após 30s no máximo.
- Sem migration. Teste novo cobre o campo nos dois estados.
