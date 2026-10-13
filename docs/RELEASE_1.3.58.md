# Release 1.3.58 — prévia da zona reversa

O assistente de criação de zona reversa volta a mostrar uma prévia antes do
envio. Para IPv4 `/8`, `/16` ou `/24`, a visualização é atualizada enquanto o
operador digita o bloco ou troca o formato do PTR.

A prévia apresenta:

- nome da zona `in-addr.arpa` que será criada;
- um IP de exemplo dentro do bloco;
- linha PTR completa, incluindo o destino gerado pelo formato escolhido.

Endereços fora da faixa IPv4 ou blocos cujo endereço não seja o início da rede
são sinalizados diretamente na prévia. Nenhuma zona ou registro é criado nessa
etapa.

## Validação

- build Vite de produção aprovado;
- Laravel Pint: 212 arquivos aprovados;
- assistente reverso: 19 testes, 61 asserções;
- suíte PHP completa: 426 testes, 2.014 asserções.
