# Avaliação do DNS Center Agent

Data: 2026-09-08

O agente é um processo `oneshot` executado por timers systemd no servidor BIND.
Ele não mantém um daemon permanente: a cada ciclo consulta o painel por HTTPS,
executa uma tarefa local e encerra. A autenticação usa um token individual do
agente, enviado como Bearer token.

## Fluxo normal

```text
timer
  -> heartbeat, inventory, readiness, sync-zones, observe-bind
  -> painel autentica o token e valida organização/servidor
  -> agente salva estado local atômico
```

O `dns-center-agent.timer` roda o ciclo geral a cada cinco minutos. O timer de
operações autorizadas roda a cada 30 segundos. O timer de aprovação consulta
solicitações de instalação a cada cinco minutos apenas quando ainda não existe
credencial local.

### Instalação e vínculo

O instalador baixa o Python e as units, baixa os SHA-256 correspondentes,
confere cada arquivo, executa `py_compile`, instala com permissões restritas e
envia uma solicitação de aprovação. O administrador aprova a solicitação no
painel com o servidor e a organização corretos. O token permanente é salvo em
`/etc/dns-center-agent/agent.json` com escrita atômica e modo 0600.

### Operação autorizada

Operações administrativas não são comandos shell enviados pelo painel. O
painel registra uma operação enumerada (`install_bind`, `configure_bind`,
`discover_bind_zones`, `upgrade_agent` ou `apply_zones`). O agente busca uma
operação, confirma a transição `authorized -> running`, executa somente a ação
correspondente e informa `succeeded` ou `failed` com um evento idempotente.

No `apply_zones`, o agente exige simultaneamente root, a variável local
`DNS_CENTER_AGENT_ALLOW_APPLY=1` e a frase `APLICAR ZONAS <servidor>`. Essa
decisão local impede que uma autorização do painel sozinha altere o BIND.

## Publicação de zonas

1. O agente baixa o manifesto HTTPS do painel.
2. Para cada primary pendente, baixa o artefato em staging e verifica tamanho,
   versão e SHA-256 contra o manifesto e a resposta do painel.
3. Valida `named-checkzone` e `named-checkconf` antes de tocar os arquivos ao
   vivo.
4. Cria backup local de zones e includes.
5. Faz escrita atômica, executa `rndc reconfig` e `rndc reload`.
6. Se qualquer etapa de aplicação falhar, restaura o backup e recarrega o BIND.
7. Consulta `rndc zonestatus` até confirmar o serial SOA esperado e reporta a
   versão e o serial ao painel.

Secondary não recebe zonefile do painel. Ele obtém a zona do primary por AXFR
ou IXFR, com NOTIFY e TSIG conforme o manifesto. O agente apenas observa e
reporta o resultado da transferência.

## Observabilidade

`observe-bind` consulta `rndc zonestatus`, fallback para SOA local quando
necessário, o estado do serviço, listeners TCP/UDP 53, recursão e logs recentes.
Ele envia uma sequência monotônica para o painel. Payloads rejeitados de forma
permanente são descartados localmente na versão 0.7.7 para não ficarem presos
em replay infinito.

## Problemas encontrados e correções 0.7.7

- Uma resposta `expired` ao relatório `running` não era verificada; o agente
  podia executar uma operação que o painel já havia expirado. Agora a execução
  para se o painel não confirmar `running`.
- Falha de serial na primeira zona abortava a confirmação das zonas seguintes.
  Agora cada zona é processada e os erros são acumulados, mantendo o resultado
  das zonas já confirmadas.
- `TimeoutError` bruto podia escapar do retry de HTTP. Agora vira `AgentError`
  e segue a política de tentativas.
- Observação rejeitada permanentemente era reenviada em todos os ciclos. Agora
  404, 409 e 422 limpam o snapshot pendente antes da próxima coleta.
- Cópia direta de unit durante self-upgrade podia deixar arquivo parcial. A
  instalação agora usa arquivo temporário no mesmo diretório e `os.replace`.
- Falha ao habilitar o timer de operações era ignorada. Agora falha a atualização
  e aciona o rollback dos artefatos alterados.

## Evidência operacional

Na produção, antes da correção do agente, havia três operações `upgrade_agent`
expiradas e duas falhas históricas. Uma falha registrada foi incompatibilidade
de filesystem, corrigida anteriormente usando diretório temporário no mesmo
filesystem. Os agentes ns1/ns2 da Conecta estavam em 0.7.6, online, com seis
zonas sincronizadas e seriais iguais nos dois servidores.

As reproduções dos problemas atuais foram feitas com mocks e arquivos
temporários, sem rede, BIND ou systemd reais. A suíte Python passou com 104
testes após a inclusão de regressões para operação expirada e timeout de rede.

## Procedimento de atualização

Após a publicação da imagem que contém o agente 0.7.7, solicitar a operação
`upgrade_agent` para cada servidor e confirmar no painel `succeeded`. Conferir
nos hosts:

```bash
sudo systemctl is-enabled --quiet dns-center-agent.timer
sudo systemctl is-enabled --quiet dns-center-agent-operation.timer
sudo systemctl list-timers 'dns-center-agent*'
sudo /usr/local/sbin/dns-center-agent --status
```

Em seguida, observar heartbeat, readiness, operação autorizada e observações
por pelo menos dois ciclos do timer. Não atualizar todos os servidores ao mesmo
tempo: manter um primary disponível e validar o secondary após cada etapa.
