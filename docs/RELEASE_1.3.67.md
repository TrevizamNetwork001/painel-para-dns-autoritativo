# Release 1.3.67 — NS administrativos ocultos

Os registros NS do ápice deixam de aparecer na tabela “Registros DNS” também
nas zonas diretas. Eles continuam armazenados, validados e publicados no BIND
pelo perfil de nameservers, mas não são oferecidos para edição manual.

## Validação

- Pint aprovado em 212 arquivos.
- teste direcionado: 26 testes, 95 asserções.
- suíte PHP completa: 435 testes, 2.055 asserções.
