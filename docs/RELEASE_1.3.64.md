# Release 1.3.64 — histórico de zona compacto

Reorganiza cada versão do histórico em uma linha compacta com versão e serial,
descrição e data, além da ação de restauração. Descrições extensas são
abreviadas visualmente e permanecem disponíveis no texto de ajuda do navegador.

Em telas pequenas, os dados continuam empilhados para preservar a leitura.

O preview e o artefato BIND também passam a seguir a organização do modelo
antigo: SOA e registros do domínio principal aparecem sob `$ORIGIN .`; em
seguida, `$ORIGIN dominio.` agrupa os registros de cada host, mantendo `A` e
`AAAA` próximos. O SOA e os registros usam o alinhamento tradicional por
colunas dos arquivos do BIND, com serial e temporizadores recuados. Somente
dados realmente cadastrados são renderizados.

A pré-publicação passa a ter uma única ação principal, **Publicar e
sincronizar**. O resumo da última publicação mostra em uma faixa compacta as
confirmações de aplicação e serial; pendências, falhas e agentes offline só
aparecem quando existirem. A ação manual de forçar transferência deixa de ser
exibida nesse fluxo: depois da confirmação do master, o secundário já é
liberado automaticamente em segundo plano.

## Validação

- build Vite de produção aprovado.
- Pint aprovado em 212 arquivos.
- suíte PHP completa: 430 testes, 2.033 asserções.
- suíte do agente: 175 testes aprovados.
