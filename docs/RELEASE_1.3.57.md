# Release 1.3.57 — adoção protegida e formatos de PTR reverso

O fluxo de adoção que já existia foi endurecido. Antes, o botão apenas alterava
`origin` para `managed`, e a importação em lote podia contornar essa etapa.

Agora a adoção exige:

- zona configurada e aprovada pelo validador normal (perfil, NS e primary);
- descoberta do servidor de origem com menos de 24 horas;
- conteúdo descoberto inalterado desde a importação;
- confirmação forte `ADOTAR <NOME-DA-ZONA>`;
- auditoria explícita `zone.adopted`.

A importação em lote pode configurar perfil e topologia, mas mantém a zona como
`bind_import` até a adoção protegida. Adotar não publica automaticamente.

## DNS reverso

O assistente IPv4 voltou a oferecer formatos explícitos para o destino do PTR:

- nome original do registro A;
- IP completo com pontos, seguindo `exemplos/dns.sh` (por exemplo,
  `192.168.0.10.dominio.example`);
- prefixos prontos `host-$` e `ip-$`;
- formato personalizado, usando `$` para a parte do host.

A escolha também pode ser alterada depois na configuração da zona. O padrão do
script gera PTR apenas para endereços A existentes no DNS Center; ele não cria
256 registros especulativos para todo o `/24`.

## Validação

- fluxo de descoberta e adoção: 37 testes, 170 asserções;
- suíte PHP completa: 426 testes, 2.014 asserções;
- assistente reverso ampliado: 19 testes, 61 asserções;
- Laravel Pint: 212 arquivos aprovados, sem desvios de formatação.
