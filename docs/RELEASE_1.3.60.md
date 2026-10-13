# Release 1.3.60 — redes IPv4 divididas em zonas /24

O assistente de DNS reverso recupera e melhora o fluxo do antigo
`domains.php`. Redes IPv4 entre `/16` e `/24` agora são divididas
automaticamente em zonas `/24`.

Por exemplo, `192.168.20.0/22` resulta em:

- `192.168.20.0/24`;
- `192.168.21.0/24`;
- `192.168.22.0/24`;
- `192.168.23.0/24`.

O assistente informa na prévia a quantidade e os blocos envolvidos. Se algumas
zonas já estiverem cadastradas, elas são preservadas e somente as ausentes são
criadas. Se todas existirem, a operação é bloqueada com uma mensagem em formato
CIDR. Endereços desalinhados informam qual deve ser o início correto da rede.

O modal também ficou mais compacto: as opções PTR voltaram a usar um seletor e
só aparecem depois de um bloco IPv4 válido. Os exemplos são calculados com o
bloco informado e o domínio associado continua sendo herdado automaticamente.

## Validação

- build Vite de produção aprovado;
- Laravel Pint aprovado nos arquivos alterados;
- DNS reverso e cálculo de zonas: 46 testes, 101 asserções;
- suíte PHP completa: 429 testes, 2.028 asserções.
