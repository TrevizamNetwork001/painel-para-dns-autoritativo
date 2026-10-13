# Release 1.3.59 — prévia reversa no formato do painel antigo

O assistente de zona reversa IPv4 recupera a apresentação usada no antigo
`domains.php`. A tela deixa explícito qual domínio já cadastrado será herdado
pela zona e apresenta exemplos legíveis para cada formato de hostname.

Os formatos disponíveis no fluxo incluem:

- `host-10.dominio`;
- `ip-192-168-0-10.dominio`;
- `192-168-0-10.dominio`;
- modelo personalizado com `$` no lugar da parte de host.

A prévia foi simplificada para mostrar **“Exemplo para o IP .10”** e somente o
nome PTR final. O domínio não precisa ser informado novamente: ele é herdado da
zona forward na qual o assistente foi aberto.

Os novos formatos de IP completo com hífens também são persistidos e aplicados
na geração real dos registros PTR. Formatos salvos nas versões anteriores
continuam compatíveis.

## Validação

- build Vite de produção aprovado;
- Laravel Pint aprovado nos arquivos alterados;
- testes do assistente reverso: 20 testes, 65 asserções;
- suíte PHP completa: 427 testes, 2.018 asserções.
