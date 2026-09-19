# Endurecimento do DNS Center Agent — 19/09/2026

## Decisão de arquitetura

O agent continua sendo o único caminho automatizado entre o painel e os
servidores autoritativos. Ele usa conexão de saída, token individual, catálogo
fechado de ações e confirmação idempotente. SSH não foi integrado ao painel e
permanece apenas como recurso manual de emergência. Backup não fez parte desta
etapa.

O artefato permanece um executável Python único. Essa compatibilidade é
intencional: agents antigos sabem baixar e substituir apenas esse arquivo e as
units já conhecidas. Transformá-lo imediatamente em vários módulos tornaria o
primeiro upgrade dependente de arquivos que a versão antiga não sabe instalar.
A separação começou pelas fronteiras internas e pelos contratos, sem quebrar o
formato de distribuição.

## Versão 0.11.0

### Serialização de operações BIND

Todas as operações autorizadas que consultam ou alteram BIND passam pelo mesmo
lock do estado local:

- instalação e configuração do BIND;
- descoberta;
- aplicação de zonas;
- remoção de declaração legada.

Antes, somente aplicação, sincronização periódica e observação estavam
protegidas. Uma configuração ou remoção de bloco legado podia coincidir com o
timer periódico. O self-upgrade fica fora desse lock porque altera somente o
binário do agent e suas units.

O despacho da ação foi separado de seu ciclo de transporte. Coleta,
transições `running`/terminal e persistência do resultado continuam em
`run_authorized_operation`; a execução local fica em
`execute_authorized_operation` e usa um catálogo explícito de ações.

### Recuperação de relatório terminal corrompido

Resultados terminais continuam sendo gravados antes do POST ao painel. Se o
arquivo estiver corrompido, ele agora é movido para
`state_dir/quarantine/pending-operation-report.invalid-*.json`, com diretório
modo `0700`. O ciclo atual falha de forma visível, preservando a evidência, mas
o próximo ciclo não fica bloqueado para sempre.

Erros de rede continuam preservando o arquivo original para reenvio com o
mesmo `event_id`; a quarentena vale somente para conteúdo local inválido.

### Diagnóstico local

O novo comando é somente leitura e não acessa o painel nem modifica BIND:

```text
sudo /usr/local/sbin/dns-center-agent --doctor
```

Ele retorna JSON e verifica:

- URL e presença da credencial, sem exibir o token;
- permissão do arquivo de configuração (grupo/outros não podem ter acesso);
- disponibilidade e segurança do diretório de estado;
- validade de `state.json` e do resultado terminal pendente;
- presença de `named-checkconf`, `named-checkzone` e `rndc`;
- presença das quatro units principais.

O código de saída é `0` sem erros e `2` quando alguma verificação está em
estado `error`. Um resultado terminal válido aguardando reenvio é `warning` e
não torna o diagnóstico malsucedido.

## Compatibilidade e rollout

- O protocolo HTTP e os endpoints não mudaram.
- Configuração, token, estado, zonas e backups locais não mudaram de formato.
- O painel detecta a versão diretamente no artefato, agora `0.11.0`.
- O upgrade continua atômico e conserva rollback do binário e das units.
- O primeiro rollout deve ser feito em um autoritativo piloto, executar
  `--doctor` e só depois avançar para o segundo nó.

## Testes e critérios

Foram adicionadas regressões para:

- diagnóstico saudável sem acesso de rede;
- detecção de configuração com permissão exposta;
- quarentena e desbloqueio após relatório terminal corrompido;
- uso do lock comum pelas operações BIND;
- independência do self-upgrade em relação ao lock do BIND.

Validação final executada:

- 144 testes Python do agent, instalador e deploy: aprovados;
- 368 testes PHP, com 1.821 verificações: aprovados;
- Pint: 197 arquivos aprovados;
- build Vite: aprovado.

Os três testes do R2 que abrem servidor HTTP em loopback foram executados fora
da restrição de socket do sandbox e também passaram.

## Dados de ambiente

Fixtures e documentos versionados foram anonimizados com domínios `.example`,
IPv4 das redes reservadas por RFC 5737 e IPv6 `2001:db8::/32`. Nenhuma
credencial, chave, endereço público ou identidade de cliente deve ser incluída
em novos commits.

Essa limpeza protege o estado atual do repositório. Referências que já tenham
sido publicadas no histórico Git só podem ser removidas com reescrita de
histórico e force-push, operação separada e destrutiva.

## Limites restantes

O executável ainda é grande. A próxima separação física deve manter um
artefato autocontido — por geração determinística ou empacotamento — e só deve
ser adotada depois que versões antigas conseguirem instalar esse formato. A
homologação final ainda precisa exercitar BIND e systemd reais, falha durante
aplicação e transferência primary/secondary.
