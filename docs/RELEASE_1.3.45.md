# Release 1.3.45 — agentes bloqueados por HTTP 429

## Causa

O limite de 120 chamadas por minuto da API do agente usava o IP visto pelo
Laravel. Atrás do Docker/Nginx, todos os agentes apareciam com o mesmo IP.
O contador compartilhado retornou HTTP 429 por mais de duas horas, impedindo
heartbeat, coleta de operações e upgrade. A coluna `agent_status` continuava
mostrando `online` com o último contato antigo.

## Correção

- O middleware autentica o token antes de limitar e usa o ID do agente como
  chave de 120 chamadas por minuto. Credenciais ausentes, inválidas ou revogadas
  continuam limitadas por IP a 60 tentativas por minuto.
- A solicitação e o acompanhamento do upgrade consideram offline o agente sem
  contato há mais de 10 minutos, mesmo que o status salvo ainda seja `online`.

Validação: 34 testes PHP direcionados (165 verificações), incluindo isolamento
do limite entre dois agentes, e Pint nos cinco arquivos alterados.
