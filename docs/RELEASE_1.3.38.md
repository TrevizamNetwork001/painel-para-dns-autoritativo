# Release 1.3.38

## "Remover declarações antigas e publicar" espera o relatório de prontidão

O botão combinado (tela da zona) publicava logo após o agente confirmar a última
remoção, mas a validação de publicação lê o relatório de prontidão, que o agente
envia alguns segundos depois. A publicação podia ser recusada por um conflito já
resolvido. Agora, após cada remoção `succeeded`, o script espera
`readiness_refreshed` (campo já devolvido por `servers.bind.legacy-block.status`
desde a 1.3.36), com limite de 30s, antes de seguir para a próxima remoção ou para
a publicação. Sem migration; teste cobre a presença da espera na página.
