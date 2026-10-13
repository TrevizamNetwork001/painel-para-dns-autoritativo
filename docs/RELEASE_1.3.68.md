# Release 1.3.68 — nome DNS sem repetição visual

A tabela de registros deixa de repetir o mesmo nome completo em duas linhas
quando o registro já está armazenado como FQDN. O ponto final técnico também é
omitido na apresentação.

O domínio raiz e o host `www` permanecem registros distintos, e nenhum dado DNS
é removido ou modificado por esta correção visual.

## Validação

- Pint aprovado em 212 arquivos.
- teste direcionado: 26 testes, 96 asserções.
- suíte PHP completa: 435 testes, 2.056 asserções.
