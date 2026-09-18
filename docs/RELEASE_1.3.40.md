# Release 1.3.40

## Publicação sem alterações explica o motivo e leva à aba Configuração

Antes, "Só publicar" mostrava "Esta versão da zona já está publicada." e "Publicar
e sincronizar" mostrava todos os servidores como "Já sincronizado" — um "ok" mudo
mesmo quando o que faltava era salvar a configuração.

- `zones.publish`: quando não há nada novo, a mensagem passa a ser "Sem alterações
  desde a última publicação. Para mudar servidores, perfil de nameservers ou SOA,
  salve na aba Configuração; se editou registros, publique de novo." e o aviso traz
  o botão **Ir para Configuração** (flash `status_go_tab`).
- `zones.publish-and-sync` devolve `published` e `nothing_new` (nada publicado agora
  **e** todos os servidores já sincronizados). Nesse caso o modal mostra a mesma
  explicação, a lista de servidores e o botão **Ir para Configuração**. Com servidor
  ainda pendente, o comportamento segue o de antes (não é "sem alterações").
- Os botões `data-go-tab` fecham o modal, abrem a aba e rolam até ela.
- Sem migration. 3 testes novos + 1 ajustado.
