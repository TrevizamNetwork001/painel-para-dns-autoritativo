# Revisão cirúrgica do DNS Center Agent — 18/09/2026

## Escopo e evidência

Revisados o agente Python, as units systemd, endpoints Laravel, testes e os
changelogs do agente da RC2 até as releases 1.3.42. Esta revisão não acessou
os servidores de produção nem executou BIND ou systemd reais. A suíte Python
do agente passou localmente (114 testes), e a do instalador também (15 testes).
Três testes adicionais de deploy que abrem servidor HTTP local falham por
restrição de socket deste sandbox. Em contêineres isolados, passaram 365 testes
PHP (1.805 verificações), Pint (191 arquivos) e o build Vite. O build Vite
exigiu remover uma fonte remota que tornava a publicação dependente da rede.
A nova versão de artefato do agente é 0.10.2 e ainda precisa de rollout nos hosts.

## Padrão visto no histórico

| Etapa | Fato observado | Lição |
| --- | --- | --- |
| 0.7.4–0.7.7 | Include BIND ausente, operação expirada ainda executada, falha em confirmação de serial e rollback da atualização de units | O resultado precisa refletir o estado real do host e do painel |
| 0.7.8–0.7.9 | Aplicação pelo painel habilitada por padrão; observação de serial só atualizava no timer de 5 min | A documentação de opt-in local ficou desatualizada; telemetria atrasada parece falha |
| 0.8.0–0.8.1 | Erros BIND sem detalhe e perda de `diagnostics` ao relançar exceção | Testes isolados não cobriam a cadeia completa do erro |
| 0.9.0–0.10.0 | Conflitos de zonas legadas detectados e removidos; resultado inicialmente sumiu na sanitização do painel | Cada novo contrato agente/painel precisa de teste HTTP completo |
| 0.10.1 e painel 1.3.42 | Secondary desistia antes da transferência; a ordenação passou a depender da tela aberta | O fluxo de publicação precisa avançar no servidor, independente do navegador |

Os registros de produção da release 1.3.39 anotam 16 falhas e 12 sucessos
históricos de `apply_zones`, sendo 14 falhas no onboarding inicial e duas
ligadas à espera do secondary. Há duas operações antigas de `upgrade_agent`
expiradas. Esses números não medem a taxa de falha da versão atual.

## Revisão por componente

| Componente | Avaliação | Evidência/limite |
| --- | --- | --- |
| Vínculo e autenticação | Base sólida | Token individual, aprovação no painel, revogação e armazenamento local restrito; o fluxo real de reenrollment não foi repetido em host nesta revisão |
| Download de artefatos | Base sólida | HTTPS, limite de tamanho, checksum, metadados de versão e escrita atômica; mocks não substituem teste contra proxy/rede reais |
| Aplicação BIND | Boa proteção local | `named-checkzone`, `named-checkconf`, backup, troca atômica, `rndc` e rollback; falta ensaio recente de queda do processo em cada etapa |
| Secondary e transferência | Sensível à ordem | AXFR/NOTIFY/TSIG e confirmação do serial existem; a liberação foi movida do navegador para o backend nesta revisão |
| Upgrade | Era o ponto mais frágil da experiência | Prazo, confirmação tardia e polling parado tinham causas separadas; mudanças abaixo precisam de piloto real |
| Observabilidade | Funcional, com atraso eventual | Coleta serial, readiness e eventos; os timers e a rede podem atrasar a tela sem alterar o BIND |
| Operação e manutenção | Complexidade alta | Um arquivo Python concentra muitos papéis; testes de integração host a host ainda são necessários |

## Achados e mudanças desta revisão

### 1. Upgrade parecia interminável mesmo depois de concluído

O agente concluía o upgrade e reportava `succeeded`, mas o painel exigia um
heartbeat posterior para reconhecer a nova versão. O timer geral roda a cada
cinco minutos. Além disso, o JavaScript deixava de consultar o status após
200 tentativas, mantendo o modal parado até nova navegação.

O agente agora informa a versão verificada no resultado da operação; o painel
confirma imediatamente quando coincide com o artefato disponível. O polling
continua até chegar um estado terminal. O heartbeat segue como telemetria
normal, sem ser a única confirmação do upgrade.

### 2. O mesmo prazo era usado para fila e execução

Antes, tanto uma operação `authorized` não coletada quanto uma operação
`running` expiravam em dez minutos. O upgrade baixa o binário e seis units
sequencialmente, verifica checksums, testa o binário e pode recarregar o
systemd. Rede lenta podia esgotar o prazo após a execução já ter começado.

Agora a espera na fila continua em dez minutos e a execução tem prazo
configurável separado, padrão de vinte minutos. A unit de operações recebeu
`TimeoutStartSec=18min`, menor que o prazo de execução do painel. O status
mostra o prazo correto conforme a fase e distingue “não coletada” de “excedeu
o prazo de execução”. A criação da solicitação também bloqueia o registro do
servidor durante a checagem, impedindo duas autorizações simultâneas. A unit
antiga só será substituída após um upgrade
bem-sucedido; se um host antigo falhar repetidamente antes disso, pode exigir
atualização manual da unit.

### 3. Dois timers podiam alterar o mesmo estado local

O timer geral executa `--sync-zones` enquanto o timer de operações pode
executar `apply_zones`. Ambos usam `state.json`, staging e artefatos locais.
Não havia lock entre processos. Adicionado lock exclusivo no diretório de
estado para sincronização, aplicação e observação; o ciclo periódico pula uma
rodada se o lock estiver ocupado, enquanto uma aplicação autorizada aguarda sua
vez. O upgrade não espera esse lock, pois troca o
binário de modo atômico e não altera o estado das zonas.

### 4. A autorização local descrita nos documentos não existia mais

A release 1.3.15 colocou `DNS_CENTER_AGENT_ALLOW_APPLY=1` na unit de
operações por decisão explícita do operador. A documentação da API ainda
instruía configurar a variável manualmente e a avaliação antiga a chamava de
trava local. A documentação foi corrigida: para operações do painel, a
autorização efetiva está no RBAC e na operação aprovada no painel.

### 5. Publicação do secondary dependia do navegador

Após o primário aplicar, a tela precisava fazer outro POST para autorizar o
secondary. Fechar o modal deixava a aplicação pendente. A confirmação
`applied` do primário agora cria a operação do secondary no backend, com
bloqueio por servidor e proteção contra duplicação. O navegador só observa o
resultado.

### 6. Resultado final podia se perder após uma falha de rede

O agente enviava `succeeded`/`failed` uma vez e não guardava o evento fora da
memória do processo. Agora grava o relatório final em arquivo local 0600 antes
do POST e reenvia o mesmo `event_id` no próximo ciclo, antes de buscar outra
operação. Uma resposta do painel remove o arquivo; erro de rede o preserva.
O teste simula a queda e confirma que o reenvio usa o mesmo evento.

## Riscos ainda abertos para uma versão de maturidade

1. **Self-upgrade ainda depende da unit antiga para o primeiro salto.** Uma
   unit já instalada com timeout curto pode encerrar o processo antes de
   instalar a unit nova. O rollout deve começar por um host piloto e conferir
   o `TimeoutStartUSec` efetivo e o resultado da operação.
2. **O agente está concentrado em um arquivo de cerca de 4.700 linhas.**
   Download, BIND, descoberta, observação, upgrade e CLI compartilham o mesmo
   módulo. A separação deve ser incremental, com testes de contrato, sem troca
   completa de uma vez em servidores autoritativos reais.
3. **Faltam testes de integração recentes com BIND e systemd reais** para
   upgrade lento, reinício durante aplicação, transferência primary/secondary
   e reconciliação após perda de rede. Os testes unitários e mocks cobrem
   caminhos importantes, mas não provam esses cenários operacionais.

## Rollout recomendado

Atualizar um secondary piloto,
confirmar `succeeded`, versão 0.10.2 e timer ativo; depois atualizar o
primary. Conferir aplicação e serial em ambos e observar pelo menos dois
ciclos. Esta revisão não alterou os hosts autoritativos.
