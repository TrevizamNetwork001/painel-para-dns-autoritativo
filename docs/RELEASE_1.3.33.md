# Release 1.3.33

## Descoberta BIND: comparação entre servidores e zonas ignoradas

Inspirado no inventário do painel antigo (comparação master × slave e "marcar
extra como legítima"). Só leitura: nenhuma configuração de servidor é alterada.

- **Coluna "Entre servidores"** (`servers.bind.discovery.show`): compara cada
  zona com a última descoberta bem-sucedida dos outros servidores da mesma
  empresa — `Sincronizada`, `Divergente` (ausente em X / serial diferente em X).
  Serviço: `App\Services\DnsBindDiscoveryDivergence`.
- **Painel "Presentes em outros servidores, ausentes aqui"**, com a data da coleta
  de cada servidor comparado.
- **Ignorar / Reativar zona** (`servers.bind.discovery.ignore` / `.unignore`):
  tabela nova `dns_bind_ignored_zones` (por servidor + nome, sobrevive a novas
  descobertas). Zona ignorada sai de "Prontas para importar" e a importação a
  recusa. Zona já importada não pode ser ignorada. Auditoria:
  `dns.bind_zone_ignored` / `dns.bind_zone_unignored`. Apenas admin, escopo por
  empresa, rota sob `servers.bind.*` (2FA já aplicado).

## Modal "Remover declaração antiga"

- Título e lista de conflitos mostram o bloco em CIDR (`203.0.113.0/24`); o nome
  da zona reversa fica como detalhe.
- Barra de progresso no lugar de "até ~30s": tempo decorrido na janela de poll do
  agente (até 85%), 90% com a operação `running`, 100% só quando confirmada.

## Migration

`2026_09_18_100000_create_dns_bind_ignored_zones_table` — tabela nova, sem alterar
dados existentes.
