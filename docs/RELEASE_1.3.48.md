# Release 1.3.48 — bloqueio de agentes órfãos

## Entrega

- A revogação de um agente adiciona o hash irreversível da credencial à lista de bloqueio da API.
- A exclusão de uma empresa preserva somente os hashes e IPs necessários à defesa, sem nome ou identificador do tenant.
- IPs de servidores pertencentes à empresa excluída entram em um conjunto isolado do `nftables`, sincronizado a cada minuto por um timer do host.
- O painel **Empresas** mostra origem, motivo, estado do firewall, quantidade de tentativas e último acesso bloqueado.
- O sincronizador só substitui a tabela `inet dns_center_agent_guard`; regras externas do host não são alteradas.

## Validação

- Testes de revogação, rejeição do token, contagem de tentativas e exclusão integral do tenant.
- Validação de estilo PHP, sintaxe dos scripts e carga do conjunto do firewall.
