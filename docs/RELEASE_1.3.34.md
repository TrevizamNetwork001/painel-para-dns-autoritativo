# Release 1.3.34

## Descoberta BIND: comparação só entre servidores relacionados

Na 1.3.33 a coluna "Entre servidores" comparava com **todos** os servidores da
empresa que tinham descoberta; um servidor sem relação (outro cliente/ambiente)
aparecia como "Ausente em X". Agora só entram na comparação servidores que
compartilham ao menos uma zona descoberta ou uma zona gerenciada atribuída aos
dois (`DnsBindDiscoveryDivergence::relatedPeers`).

- Coluna "Ação" da tabela de descoberta não quebra mais linha (Detalhes / Ignorar).
- Testes: servidor sem relação não é comparado; servidor que compartilha zona
  gerenciada é comparado mesmo sem descoberta sobreposta.
