# Release 1.3.65 — ordem numérica nas zonas reversas

A listagem de registros das zonas reversas IPv4 passa a ordenar os PTR pelo
endereço numérico, evitando a sequência textual `1, 10, 100`. Em uma zona
`/24`, os hosts aparecem de `0` a `255`.

Zonas reversas IPv6 também passam a usar a ordem numérica do endereço
completo. Registros administrativos, como os NS do ápice, permanecem agrupados
antes dos PTR.

Na tabela, o nome técnico do ápice reverso agora é traduzido para o bloco
legível. Por exemplo, `c.2.1.7.4.0.8.2.ip6.arpa` é apresentado como
`2804:712c::/32`; o nome DNS original continua disponível no texto de ajuda.

## Validação

- build Vite de produção aprovado.
- ordenação e apresentação de zonas reversas: 25 testes, 88 asserções.
- Pint aprovado em 212 arquivos.
- suíte PHP completa: 432 testes, 2.040 asserções.
