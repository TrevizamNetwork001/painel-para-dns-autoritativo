# Operação segura do agente BIND

O agente 0.2.0 preserva o enrollment existente e adiciona:

- heartbeat;
- inventário;
- download autenticado de manifesto e zonefiles;
- staging isolado;
- `named-checkzone`;
- `named-checkconf`;
- dry-run obrigatório por padrão;
- backup;
- escrita atômica;
- rollback automático;
- `rndc reconfig` somente após validação.
- configuração distinta para primary e secondary;
- AXFR/IXFR e NOTIFY autenticados por TSIG;
- confirmação do serial SOA realmente carregado pelo BIND.

## Dry-run

```bash
dns-center-agent --sync-zones
```

O dry-run baixa, prepara e valida os artefatos, mas não escreve em `/etc/bind`
e não executa `rndc`.

## Apply protegido

```bash
DNS_CENTER_AGENT_ALLOW_APPLY=1 \
dns-center-agent \
  --sync-zones \
  --apply \
  --confirm "APLICAR ZONAS NS1"
```

O apply exige root, variável de ambiente e confirmação com o nome do servidor.

## Integração com o BIND

O arquivo principal do BIND deve conter uma inclusão explícita criada pelo
administrador:

```text
include "/etc/bind/dns-center-managed.conf";
```

O agente não altera automaticamente `named.conf`, não instala pacotes e não
habilita serviços systemd durante a sincronização comum de zonas.

## Prontidão e operação autorizada

`--readiness` detecta BIND, ferramentas, caminhos allowlisted, serviço,
listeners 53, permissões e módulos de segurança e envia fatos ao painel.

`--run-authorized-operation` consulta uma autorização persistente. Instalação
e integração são traduzidas por catálogo local fixo; o painel não envia
comandos. Consulte `BIND_READINESS_INSTALLATION.md`.
