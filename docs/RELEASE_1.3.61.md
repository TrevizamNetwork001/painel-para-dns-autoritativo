# Release 1.3.61 — domínio PTR sempre vem de uma zona direta

Corrige o assistente de criação reversa quando aberto a partir de uma zona
IPv4 ou IPv6 reversa. Antes, trocar a família para IPv4 ainda podia gerar uma
prévia incorreta como `host-10.<zona>.ip6.arpa`.

O assistente agora só pode ser aberto dentro de uma zona direta. Em páginas de
zonas `in-addr.arpa` ou `ip6.arpa`, o painel mostra uma orientação e um atalho
para abrir um domínio direto disponível. O modal comum de registros continua
funcionando normalmente nas zonas reversas.

Ao selecionar IPv6, o formulário também oculta a escolha de formato PTR e toda
a prévia: basta informar o bloco IPv6 e os dados necessários para criar a zona.

## Validação

- build Vite de produção aprovado;
- assistente reverso: 23 testes, 81 asserções.
- suíte completa: 430 testes, 2034 asserções.
