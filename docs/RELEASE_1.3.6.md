# DNS Center v1.3.6

Data: 2026-09-09

## Escopo

Esta versão contém o agente 0.7.7 e as correções da avaliação operacional:

- não executa operação que o painel já marcou como expirada;
- continua a confirmação das demais zonas quando uma confirmação de serial
  falha;
- trata timeout de rede como erro recuperável pelo retry;
- descarta observações permanentemente rejeitadas para evitar replay infinito;
- atualiza units systemd por cópia atômica;
- falha e faz rollback quando não consegue habilitar o timer de operações.

Não há migrations novas. O painel continua na base de dados compatível com
1.3.5.

## Deploy

As imagens `dns-center-app:1.3.6` e `dns-center-web:1.3.6` foram construídas,
auditadas e implantadas pelo procedimento formal. O backup PostgreSQL foi
executado antes das migrations; não havia migration nova. App, web, queue e
scheduler ficaram saudáveis, e HTTPS `/up` e `/login` responderam HTTP 200.

O Composer audit da imagem não encontrou vulnerabilidades conhecidas.

## Atualização dos agentes

Foram autorizadas e concluídas as operações 50 (ns1) e 51 (ns2), ambas com:

```json
{"status":"succeeded","binary_changed":true,"units_changed":false,"previous_version":"0.7.6"}
```

O resultado confirma que o binário foi substituído e que as units existentes
não precisaram ser alteradas. A versão exibida no painel é atualizada no
próximo heartbeat geral do timer de cinco minutos.

## Validação posterior

Após o heartbeat, confirmar no painel `agent_version=0.7.7`, heartbeat recente,
readiness saudável e observações autoritativas sem pendências. Em seguida,
acompanhar pelo menos dois ciclos do timer antes de considerar a atualização
encerrada.
