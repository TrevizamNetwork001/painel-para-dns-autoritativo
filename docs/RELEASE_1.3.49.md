# Release 1.3.49 — inventário de firewall somente leitura

## Entrega

- Agent 0.13.0 detecta a disponibilidade do `nftables`.
- A coleta observa somente a tabela reservada `inet dns_center`.
- O painel persiste e exibe presença, estado, SHA-256 e contagens agregadas.
- Ruleset, saídas de comando e erros locais não saem do servidor.
- Agentes anteriores continuam aceitos porque o novo bloco é opcional na API.

## Limites desta fase

Não há geração, validação, aplicação ou rollback de regras. O campo de última
validação permanece vazio até a Fase 2. A implementação não interage com a
tabela `inet dns_center_agent_guard` do firewall do próprio painel.

## Validação

- Testes unitários cobrem `nftables` ausente, tabela ausente, falta de acesso,
  timeout, hash e contagens da tabela gerenciada.
- Teste da API cobre persistência do resumo e rejeição de estados
  inconsistentes.
- Suíte PHP: 370 testes e 1.839 asserções aprovados em PostgreSQL 17.
- Suíte dos scripts: 150 testes aprovados.
- Compilação Python, views Blade, build Vite e whitespace aprovados.
