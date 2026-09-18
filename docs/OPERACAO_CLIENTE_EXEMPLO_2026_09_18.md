# Operação Cliente Exemplo — adoção das zonas reversas (18/09/2026)

Registro do que foi feito em produção nos servidores `dns-primary` (`ns1.customer.example`,
`203.0.113.42`) e `dns-secondary` (`ns2.customer.example`, `203.0.113.43`), do que
aprendemos e do que ficou pendente. Versões envolvidas: painel 1.3.30 → 1.3.40, agente
0.10.0 → 0.10.1.

## Estado ao final

| Zona | Origem | Estado | NS |
|---|---|---|---|
| `0.2.0.192.in-addr.arpa` | managed | publicada (dns-primary primário, dns-secondary secundário) | 2 |
| `100.51.198.in-addr.arpa` | managed | publicada | 2 |
| `113.0.203.in-addr.arpa` | managed | publicada | **4 (duplicados)** |
| `113.0.203.in-addr.arpa` | adotada (era bind_import) | publicada | 2 |
| `8.b.d.0.1.0.0.2.ip6.arpa` | adotada (era bind_import) | publicada | 2 |
| `customer.example` | managed | publicada | **4 (duplicados)** |

Nenhum bloco de zona legado restou nos dois servidores. Os dois agentes estão na 0.10.1.
Os 6 `apply_zones` seguintes ao agente 0.10.1 passaram na primeira tentativa nos dois
servidores.

## Runbook: adotar uma zona que já roda num BIND

1. **Descobrir** (Servidores → Agente → "Executar nova descoberta"). Somente leitura.
2. **Importar** a zona na tela "Zonas encontradas" (fica `bind_import`, rascunho, não publica).
3. Na zona, aba **Configuração** → **Servidores de publicação**: principal `dns-primary`,
   secundário `dns-secondary` (**devem ser diferentes**) e o perfil de nameservers
   "Cliente Exemplo". **Salvar configuração** (isso re-sincroniza NS/glue pelo perfil
   e deixa a zona `managed`).
4. Clicar em **"Remover declarações antigas e publicar"**. Ele remove o bloco antigo de cada
   servidor (com backup em `/var/backups/dns-center-agent/…-legacy-removal`) e só publica se
   todas as remoções derem certo.
5. Conferir no painel os `apply_zones` dos dois servidores e rodar nova descoberta nos dois
   para a coluna "Entre servidores" mostrar `Sincronizada`.

## Incidentes e lições

- **Bloco antigo não some sozinho, por decisão de projeto**: o DNS Center nunca edita
  configuração que não criou. A remoção é explícita, mas foi unificada com a publicação
  (1.3.32).
- **Relatório de prontidão chega ~6–15s depois da operação do agente.** A lista de conflitos
  vem dele, então tela recarregada cedo demais mostrava conflito já resolvido e a validação
  recusava a publicação. Corrigido nos modais (1.3.36) e no botão combinado (1.3.38) com o
  campo `readiness_refreshed` de `servers.bind.legacy-block.status`.
- **NS duplicados no apex** (1.3.37): o sincronizador só apagava NS com nome `@`/`zona`, e
  zonas importadas trazem `zona.` (FQDN). Zonas já afetadas só ficam limpas ao **Salvar
  configuração** de novo. É inofensivo para o BIND (junta os iguais), mas o TTL efetivo fica
  o padrão da zona. **Pendentes: `158` e `customer.example`** (deixadas assim de
  propósito para testar a mensagem da 1.3.40; para limpar: Configuração → Salvar
  configuração → Publicar e sincronizar).
- **Primeira publicação falhava no secundário** ("BIND não confirmou o serial SOA esperado"):
  primário e secundário aplicam ao mesmo tempo e o secundário só tem o serial novo depois de
  transferir a zona; o agente esperava ~10s. Agora zonas `secondary` esperam até 45 tentativas
  (`serial_confirmation_attempts_secondary`, máx. 60). Ocorreu na `158` e na IPv6 antes do
  agente 0.10.1 e **voltou na `customer.example`** mesmo com 0.10.1 (o primário terminou
  3s depois de o secundário desistir): a correção definitiva é a **ordem primário →
  secundário no painel (1.3.42)**.
- **Confirmação de upgrade do agente** depende do heartbeat, que roda a cada 5 min
  (`dns-center-agent.timer`); o upgrade em si leva segundos. Não é lentidão; a espera varia
  de segundos a 5 min conforme o momento do clique. Melhoria possível (heartbeat imediato
  após o upgrade) foi avaliada e descartada por não compensar.
- **Erro de uso já visto**: escolher o mesmo servidor nos dois campos dá "secondary server id
  deve ser diferente de primary server id"; e **não** renomear o servidor em Servidores →
  Editar (o nome do dropdown "dns-primary — ns1…" foi parar no campo Nome do servidor 5 e teve
  de ser revertido para `dns-primary`).
- **Descoberta é uma foto**: pode rodar quantas vezes quiser (somente leitura) e é ela que
  mantém a comparação entre servidores atual. A página do agente destaca em amarelo
  descoberta com mais de 24h (1.3.35). Não há bloqueio de "já descoberto".
- **Comparação só entre servidores relacionados** (1.3.34): dividem zona descoberta ou zona
  gerenciada; senão um servidor de outro ambiente da mesma empresa aparecia como "Ausente".

## Cancelamento do cliente Cliente Legado (mesma sessão)

Empresa desativada e depois excluída pelo mecanismo novo (1.3.31). Restos de uma homologação
antiga na empresa padrão (servidores `ns1`/`ns2` conectanetwork, 6 zonas, 2075 registros,
descobertas e operações) foram removidos à parte, numa transação, sem enviar nada aos
servidores BIND. Fora dos logs de auditoria não resta nenhuma menção ao nome; as 20 entradas
de auditoria foram **mantidas** por decisão do operador.

## Pendências

- `158` e `customer.example`: limpar NS duplicados (ver acima).
- Perfil de nameservers `teste` e a zona `test-zone.example` (rascunho, sem servidor) são sobra de
  testes; remover se confirmado.
- "Perfil padrão" de nameservers está "Não definido" (só sugere o perfil ao criar zona).
- SOA das reversas importadas tem `retry (3600) > refresh (900)`, o que gera o alerta "SOA
  retry normalmente deve ser menor que o refresh"; valores herdados do BIND antigo, alerta não
  bloqueia a publicação.
- Alerta de NS repetido: implementado na 1.3.41 (aparece em Alertas com botão "Ir para
  Configuração" e na mensagem de publicar quando não há nada novo).
- Escrita direta no banco de produção pelo assistente é bloqueada pelo classificador da
  sessão; correções de dados devem passar pela interface (ou ser liberadas explicitamente).

## Decisões de escopo (18/09/2026)

- **Mini painel de estatísticas do cliente: descartado.** Estatísticas de consulta ficam com o
  Zabbix (o BIND expõe `statistics-channels`); firewall e fail2ban não entram no painel.
- **Adiados, sem decisão de fazer:** logs do BIND na tela, testar/forçar transferência (AXFR /
  `rndc retransfer`) — juntos numa futura versão do agente — e alerta de secundário atrasado.
- **Candidatos aprovados em conversa, ainda não implementados:** assistente de adoção em lote;
  backup agendado do banco com cópia fora do servidor (hoje só há `pg_dump` a cada deploy, no
  mesmo disco); restaurar versão anterior da zona.
