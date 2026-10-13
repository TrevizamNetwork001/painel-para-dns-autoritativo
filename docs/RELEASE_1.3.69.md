# Release 1.3.69 — recuperação de servidor DNS

A tela operacional do servidor passa a oferecer **Recuperar esta máquina**.
O fluxo foi pensado para uma VM substituta que já recebeu um vínculo válido do
agente e reenvia a versão publicada mais recente de todas as zonas ativas
associadas ao servidor.

A recuperação:

- não altera registros, SOA, versão ou serial das zonas;
- recria o estado pendente de entrega para o agente vinculado;
- autoriza uma operação `apply_zones` para reconstruir o BIND;
- exige administrador da organização e a confirmação forte
  `RECUPERAR NOME-DO-SERVIDOR`;
- registra a ação e a quantidade de zonas na auditoria;
- orienta recuperar o primário antes do secundário.

O código do painel antigo não foi incorporado. A avaliação existente confirma
que as funções DNS úteis já foram reimplementadas no sistema atual; componentes
baseados em SSH, shell remoto e credenciais administrativas continuam
deliberadamente excluídos.

## Validação

- testes direcionados de recuperação: 4 testes, 16 asserções;
- testes integrados de recuperação, publicação e agente: 24 testes, 190 asserções;
- suíte PHP completa: 439 testes, 2.072 asserções;
- Pint aprovado em 214 arquivos.
