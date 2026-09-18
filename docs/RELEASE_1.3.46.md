# Release 1.3.46 — confirmação imediata após self-upgrade

O agente antigo executa a troca do próprio binário e, nas versões anteriores,
seu relatório de sucesso não incluía a versão instalada. O painel aguardava o
heartbeat de cinco minutos para confirmar a atualização, embora a operação já
tivesse terminado.

Agora o próximo poll autenticado do processo novo informa sua versão pelo
User-Agent. O painel registra uma versão maior que a última conhecida e
confirma o upgrade sem esperar o heartbeat. A comparação impede que uma chamada
remanescente do processo antigo rebaixe a versão registrada.

Validação: 25 testes direcionados de upgrade (106 verificações), inclusive o
poll do binário novo antes do heartbeat, e Pint nos dois arquivos alterados.
