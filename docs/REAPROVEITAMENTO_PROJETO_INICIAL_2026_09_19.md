# Reaproveitamento seguro do projeto inicial — 19/09/2026

## Avaliação

O arquivo inicial localizado em `/tmp` representa a aplicação PHP legada de
29/06/2026. A comparação confirmou que DNS forward e reverso, PTR, auditoria,
usuários, validação de zonas e publicação já foram reimplementados no sistema
atual com isolamento por organização, API autenticada e agent de saída.

O código legado não foi copiado. Ele concentra interface, shell, sudo e SSH em
controllers extensos, guarda credenciais administrativas e contém inventários
com dados reais de ambiente. Esses padrões são incompatíveis com o modelo de
segurança atual.

## Funcionalidade reaproveitada

A ideia de indicadores locais da antiga dashboard foi reimplementada no agent
0.12.0 como telemetria somente leitura:

- quantidade de CPUs lógicas;
- carga de 1 minuto e sua normalização por CPU;
- memória total, disponível e percentual utilizado;
- espaço total, livre e percentual utilizado no filesystem raiz;
- uptime do host.

A coleta usa APIs do Python e os arquivos `/proc/meminfo` e `/proc/uptime`. Ela
não executa comandos, não abre portas, não lê logs e não envia processos,
endereços, zonefiles ou segredos. Falhas parciais de leitura apenas omitem o
indicador indisponível e não interrompem o ciclo do agent.

O endpoint de inventário valida tipos e limites antes de persistir os valores.
A tela operacional do servidor mostra os quatro indicadores e mantém estado de
compatibilidade para agents anteriores, exibindo que aguarda o inventário
0.12.0 ou superior.

## Itens deliberadamente rejeitados

- SSH direto e armazenamento de senha ou chave no painel;
- execução genérica de comandos e controle de serviços;
- cópia dos controllers, includes ou scripts NS2 legados;
- exibição de logs brutos do host;
- manipulação ampla de nftables, ACLs ou Fail2Ban pelo painel;
- importação dos handoffs e inventários antigos.

Fail2Ban e eventos resumidos do BIND podem ser avaliados futuramente, mas devem
usar contratos fechados, allowlist de dados e sanitização no agent. Não devem
reintroduzir shell remoto ou transformar o painel em console administrativo.

## Segurança do material analisado

O ZIP original em `/tmp` contém endereços e domínio reais e não pode ser
versionado. A cópia extraída usada na comparação foi removida ao final da
análise. Nenhum dado daquele ambiente foi incluído nesta implementação ou na
documentação versionada.
