# Prontidão e instalação controlada do BIND

## Modos suportados

- Painel e BIND na mesma máquina: o agente continua obrigatório e pode usar
  HTTP somente em `localhost`.
- Painel em Docker e BIND no host: o agente roda no host. O container do
  painel não recebe montagem gravável de `/etc/bind`.
- BIND remoto: o agente consulta o painel por HTTPS. Não há SSH operacional.
- Painel centralizado: cada servidor possui agente e credencial próprios.

## Catálogo local

O painel autoriza apenas `install_bind` ou `configure_bind`. Ele nunca envia
comandos. O agente traduz a ação localmente:

- Debian/Ubuntu: `bind9` e `bind9-utils`;
- RHEL compatível: `bind` e `bind-utils`.

Os subprocessos usam argumentos fixos, `shell=False`, timeout e saída
limitada. Distribuições fora da allowlist são reportadas como não suportadas.

## Integração preservando configuração

O agente mantém um include exclusivo e um diretório próprio de zonas. O
`named.conf` existente é preservado e recebe somente a diretiva de include,
caso ausente. Antes da escrita é criado backup. Symlinks são rejeitados.
`named-checkconf` deve aprovar a configuração antes de `rndc reconfig` ou da
ativação inicial do serviço. Em falha após a escrita, os arquivos anteriores
são restaurados.

Restart não faz parte desta fase. A instalação tenta impedir a ativação do
serviço pelo gerenciador de pacotes e só o libera após validação.

## Papéis e transferências

O cadastro aceita `standalone`, `primary` e `secondary`. Esta fase não cria
AXFR/IXFR, NOTIFY ou TSIG. A evolução deve relacionar secundários ao primary,
armazenar TSIG cifrado, endereços autorizados para transferência, serial
esperado/aplicado e resultado da última transferência.

## Failover real esperado

1. Os servidores autoritativos continuam respondendo sem o painel.
2. A delegação publica múltiplos NS.
3. Secundários mantêm cópia válida por AXFR/IXFR ou distribuição controlada.
4. A queda do primary não retira a zona do ar enquanto secundários respondem.
5. O painel mostra indisponibilidade factual, sem declarar failover fictício.
6. Promoção de secondary será explícita, autorizada e auditada.
7. Delegação no registrador não será alterada sem integração futura própria.
