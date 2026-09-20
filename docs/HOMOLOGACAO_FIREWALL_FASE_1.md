# Homologação do inventário de firewall — Fase 1

Esta etapa é somente leitura. Ela não cria a tabela `inet dns_center`, não
executa `nft -c` e não altera regras existentes.

## Pré-requisitos

- painel com a release 1.3.49;
- agent 0.13.0 instalado em um servidor não crítico;
- timer `dns-center-agent.timer` ativo;
- `nftables` instalado para testar os estados além de `unavailable`.

## Roteiro

1. Atualize o agent pelo fluxo normal do painel e confirme a versão 0.13.0.
2. Aguarde o timer de cinco minutos ou execute o serviço oneshot:

   ```bash
   sudo systemctl start dns-center-agent.service
   sudo systemctl status dns-center-agent.service --no-pager
   ```

3. Abra `Servidores → servidor → Agente` e confira o cartão **Firewall**.
4. Sem a tabela gerenciada, o resultado esperado é **Tabela ausente**.
5. Se `nft` não estiver instalado, o resultado esperado é
   **nftables indisponível**.
6. Em um host descartável que já possua `inet dns_center`, confira
   **Tabela detectada**, hash com 64 caracteres e contagens coerentes.
7. Confirme que as demais tabelas do host permanecem inalteradas antes e
   depois de pelo menos dois ciclos do timer.

## Critérios de aprovação

- o serviço termina com sucesso em ciclos consecutivos;
- o painel atualiza o horário observado e mantém o agent online;
- nenhum ruleset, stderr ou caminho local aparece no painel ou na API;
- o mesmo conteúdo mantém o mesmo hash;
- mudanças externas na tabela gerenciada alteram o hash no ciclo seguinte;
- nenhuma regra é criada, removida ou modificada pelo agent.

## Evidência sugerida

Registre servidor, versão do agent, estado exibido, hash, contagens, horário de
dois ciclos e o resultado do `systemctl status`. Não copie regras, tokens ou
outros dados sensíveis para o relatório de homologação.
