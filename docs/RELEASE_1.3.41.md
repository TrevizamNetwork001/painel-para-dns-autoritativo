# Release 1.3.41

## Alerta de NS repetido e mensagem específica ao publicar

Na 1.3.40 uma zona publicada com NS duplicado no apex (herança do bug corrigido na
1.3.37) mostrava só "Sem alterações…", porque o painel não sabia do problema.

- `DnsZoneValidator` passa a detectar NS repetido no apex (mesmo alvo, com/sem ponto
  final, outra caixa) e a emitir o alerta "O apex da zona tem NS repetido (...). Salve
  a configuração na aba Configuração para normalizar." — não bloqueia a publicação.
  Método público `apexNameserverDuplicates()` para uso no controller.
- No painel **Alertas** da zona, o alerta traz o botão **Ir para Configuração**.
- Ao clicar em publicar uma zona já publicada, a mensagem (flash de "Só publicar" e modal
  de "Publicar e sincronizar", campo `nothing_new_message`) diz "Nada novo para publicar,
  mas o apex desta zona tem NS repetido (...). Salve a configuração … e depois publique.",
  em vez do texto genérico.
- Sem migration. 2 testes novos em `DnsZoneWorkflowTest`.
