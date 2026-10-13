# Release 1.3.66 — listas DNS mais simples e exclusão de usuários

A tabela de registros passa a organizar hosts pelo endereço IP e mantém os
registros A e AAAA do mesmo nome juntos, com IPv4 acima do IPv6.

Nas zonas reversas, os PTR continuam em ordem numérica e os NS administrativos
do ápice deixam de aparecer na tabela. Eles permanecem armazenados e publicados
no BIND, mas não podem ser editados por essa tela. A área redundante “DNS
reverso” também é ocultada quando o usuário já está dentro de uma zona reversa,
e as explicações das ações de geração de PTR foram resumidas.

A gestão de usuários agora oferece o botão **Excluir**, com confirmação,
proteção contra autoexclusão, isolamento por organização e registro na
auditoria.

## Validação

- Pint aprovado em 212 arquivos.
- testes direcionados: 34 testes, 122 asserções.
- suíte PHP completa: 435 testes, 2.053 asserções.
