# DNS autoritativo primary/secondary

## Estado atual

- HEAD: `56bf0c62dc619fe2bc91b78975b24f862e5e1e54`
- Commit anterior de homologação real:
  `09feade16d0ae5b3483c1ac5980f10c2a03f6883`
- Git status limpo.
- SECURITY-BASELINE-1 concluída.
- Headers de segurança, throttle global de login, auditoria de falhas e 2FA
  administrativo obrigatório já estão implementados.
- O comando `dns-center:security-check` está disponível.
- Aplicação real do BIND homologada em Debian 13.6.
- AXFR/IXFR, NOTIFY e TSIG foram implementados nesta fase.
- Nenhum deploy em produção realizado.

## Implementação desta fase

O painel mantém uma zona lógica com exatamente um primary e um ou mais
secondaries. O agente do primary recebe o zonefile mestre. Agentes secondary
não recebem esse artefato: recebem somente a configuração de transferência e o
BIND cria e mantém a cópia transferida.

No primary, a configuração gerenciada usa:

- `allow-transfer` restrito à chave TSIG;
- `also-notify` para os endereços dos secondaries, autenticado por TSIG;
- `notify explicit`, evitando NOTIFY automático sem chave.

No secondary, a configuração gerenciada usa:

- `type slave` para compatibilidade com as versões homologadas do BIND;
- `masters` com os endereços do primary e a chave TSIG;
- `request-ixfr yes`;
- arquivo local persistente sob o diretório gerenciado.

O BIND escolhe IXFR quando o journal e os seriais permitem e recorre a AXFR
quando uma transferência incremental não é possível.

## TSIG e 2FA

Segredos são gerados pelo servidor, armazenados com o cast cifrado do Laravel,
nunca exibidos no painel e entregues somente aos agentes autenticados
destinados à zona.

Criação, associação, rotação e desativação usam o middleware administrativo de
2FA já existente. Não há um segundo mecanismo de autenticação.

A rotação cria uma nova geração (novo nome e novo segredo), reassocia as zonas
e mantém a geração anterior habilitada para rollback. A chave anterior só pode
ser desativada depois que não estiver associada a nenhuma zona ativa.

## Confirmação real do serial

Depois de `rndc reconfig`, o agente recarrega explicitamente cada zona primary
com `rndc reload <zona>`, executa `rndc zonestatus <zona>` e aguarda o serial
SOA esperado. A publicação só muda para `applied` quando:

1. a versão instalada coincide com a versão publicada; e
2. o serial observado no BIND coincide com o serial do snapshot.

O painel persiste `reported_serial` e `serial_confirmed_at`. Uma confirmação
com serial divergente é rejeitada e auditada.

## Continuidade com o primary indisponível

O secondary mantém em disco a última cópia válida recebida pelo BIND; o agente
não a remove durante reconfiguração. Se o primary ficar indisponível, ele
continua respondendo autoritativamente durante a janela `SOA EXPIRE`. Tentativas
de refresh seguem `SOA REFRESH` e `SOA RETRY`.

Essa continuidade não promove o secondary nem permite escrita nele. Depois de
`SOA EXPIRE`, o comportamento correto é deixar de servir dados potencialmente
obsoletos. O monitoramento deve alertar antes desse prazo.

## Homologação real descartável — 2026-07-30

A homologação usou exclusivamente `dns_center_testing`, a zona reservada
`homolog.invalid` e a rede Docker interna `dns-center-xfr-homolog`. Não houve
delegação pública, IP público, domínio de cliente, segredo real ou deploy.

### Ambiente

| Componente | Imagem/sistema | Endereço interno | Serviço |
| --- | --- | --- | --- |
| painel/testing | `dns-center-app:latest` | redes internas Docker | aplicação |
| primary | `dns-center-bind-homolog:debian13` | `172.19.0.3` | `named` |
| secondary | `dns-center-bind-homolog:debian13` | `172.19.0.4` | `named` |

Os dois nós privilegiados executaram systemd sobre Debian 13.6 (trixie). A
imagem base não continha BIND: o pacote foi instalado pela operação allowlisted
`install_bind` do agente, usando os repositórios Debian trixie,
trixie-updates e trixie-security. A versão observada foi
`BIND 9.20.26-1~deb13u1-Debian`; a unit canônica foi `named`.

O cliente `dnsutils` e, somente para simular indisponibilidade seletiva de
NOTIFY, `nftables`, foram instalados como ferramentas de homologação. Eles não
integram o contrato nem a instalação do agente.

### Zona e contratos

A zona contém SOA, dois NS, A, AAAA, CNAME, MX e TXT. O primary recebeu o
zonefile mestre e a configuração de `allow-transfer`/`also-notify`. O secondary
recebeu apenas `primaries`, TSIG e o caminho de sua cópia local; seu manifesto
não continha URL de artefato. O arquivo do secondary foi criado pelo BIND como
`bind:bind`, e não pelo painel.

A chave usada foi gerada apenas para a homologação com `hmac-sha256`. Seu valor
não foi registrado neste documento, nos comandos ou nos relatórios.

### Transferência, NOTIFY e seriais

O secondary foi configurado antes da ativação do primary e preservou essa
configuração enquanto a origem ainda não servia a zona. `named-checkconf`
passou nos dois nós. Depois da ativação do primary:

- o primary emitiu NOTIFY autenticado;
- o secondary registrou o recebimento com TSIG;
- a transferência inicial foi um AXFR confirmado pelos logs do BIND;
- `rndc zonestatus` e `dig SOA` confirmaram `2026073001` nos dois nós;
- o painel confirmou o mesmo serial nos dois agentes.

Na atualização, salvar sem publicar criou uma versão pronta, mas ambos os BIND
continuaram no serial anterior. Após publicação explícita, o primary recarregou
a zona, enviou NOTIFY e o secondary convergiu. O BIND registrou
`IXFR version not in journal, falling back to AXFR` e depois
`AXFR-style IXFR`; portanto, o método conclusivo desta execução foi **fallback
AXFR**, e não IXFR incremental.

Ao final das simulações, painel, primary e secondary convergiram no serial
`2026073008`.

### Consultas e continuidade

SOA, NS, A, AAAA, CNAME, MX e TXT foram consultados diretamente nos dois nós.
Também foram validados UDP, TCP, NXDOMAIN, serial SOA e a flag `AA`.

Com somente o serviço `named` do primary desligado, o secondary continuou
respondendo por UDP e TCP, com `AA` e o serial vigente. A prontidão do painel
marcou o primary indisponível e o secondary atendendo. Não ocorreu promoção,
alteração de NS, delegação ou reescrita da topologia. Após religar o primary,
os seriais convergiram sem artefatos duplicados.

### Falhas e recuperação

- **TSIG incorreta:** a transferência falhou com erro TSIG sanitizado; a cópia
  anterior permaneceu autoritativa e seu hash não mudou.
- **Primary inacessível:** o secondary continuou servindo a cópia existente.
- **Transferência sem permissão:** `allow-transfer` recusou a solicitação e o
  painel não recebeu uma nova confirmação de sincronização.
- **Serial divergente:** a API rejeitou o reporte com
  `authoritative_serial_mismatch` (HTTP 422), auditou a rejeição e manteve o
  serial confirmado.
- **NOTIFY indisponível:** uma regra temporária nativa do `nftables` bloqueou
  somente UDP/53 do primary para o secondary. `rndc refresh` no secondary
  convergiu para o novo serial por transferência normal; a indisponibilidade
  de NOTIFY não removeu a zona. A tabela temporária foi removida depois.
- **Configuração inválida:** `named-checkconf` bloqueou a configuração antes da
  troca ativa; a configuração e a zona anteriores foram preservadas.
- **Rotação TSIG:** o secondary recebeu primeiro a nova geração, preservou a
  cópia vigente enquanto o primary ainda usava a anterior e convergiu depois
  da troca do primary.
- **Rollback TSIG:** a mesma janela segura foi exercitada no retorno à geração
  anterior. A geração substituta permaneceu disponível, sem exibição do
  segredo.

### Segurança e limitações observadas

Os valores TSIG permaneceram cifrados no banco, ocultos na serialização dos
models e ausentes da auditoria. O include gerenciado com a chave ficou
`root:bind 0640`; o diretório de zonas ficou `root:bind 2770`. Nenhum segredo
foi passado em linha de comando. O agente usa subprocessos sem shell, comandos
allowlisted, timeout e limite de saída; testes cobrem traversal, symlink e
isolamento entre tenants. Criação, associação, rotação e desativação TSIG
continuam sob o middleware administrativo de 2FA existente.

O painel registra estado de publicação e prontidão, mas ainda não possui um
canal específico de telemetria para classificar uma falha de transferência
autônoma do BIND como `transfer_failed`; essa limitação não foi mascarada. O
BIND Debian manteve recursão habilitada por padrão e as respostas também
trouxeram `RA`; a autoridade da zona foi comprovada pela flag `AA`.
