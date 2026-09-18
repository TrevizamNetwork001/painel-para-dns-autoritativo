# Release 1.3.32

## "Remover declarações antigas e publicar" num só passo

Ao adotar uma zona que já rodava num BIND (Discover → Import → Adopt → Publish), o
bloco `zone "x" {…}` antigo fica fora do include gerenciado e o `named-checkconf`
recusa o apply. O painel nunca edita configuração que não criou sozinho, então a
remoção continuou explícita, só que agora **unificada com a publicação**.

- `DnsZoneController::show` calcula `legacyZoneConflicts` (por servidor da zona, via
  `DnsBindConfigConflicts::forServer`) — dado somente leitura.
- Com conflito, a tela da zona troca "Publicar e sincronizar/Só publicar" por um painel
  com o trecho exato de cada servidor e o botão **"Remover declarações antigas e
  publicar"**.
- O script do botão **reaproveita os endpoints já existentes**
  (`servers.bind.legacy-block.remove` / `.status`, com verificação de hash) e depois
  envia a publicação normal (`zones.publish`). Nenhum endpoint de escrita novo.
- Se qualquer remoção falhar, **para e não publica nada**.
- 3 testes novos em `DnsBindLegacyZoneBlockTest` (painel de conflito, ação normal sem
  conflito e conflito de outra zona não vaza).
- Correção posterior: a espera pelo relatório de prontidão foi acrescentada na 1.3.38.
