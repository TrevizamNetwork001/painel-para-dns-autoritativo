# Release 1.3.42

## Publicar e sincronizar: o secundário só aplica depois do primário

Falha recorrente (158, IPv6 e `customer.example`): `apply_zones` no secundário
("BIND não confirmou o serial SOA esperado"). Os agentes consultam o painel cada um no
seu relógio (30s), então o secundário começava a esperar o serial novo antes de o
primário terminar de carregar a zona, e o aumento de espera do agente 0.10.1 não
bastou (o primário terminou 3s depois de o secundário desistir; a nova tentativa passou
em 5s).

- `zones.publish-and-sync`: se a publicação **desta versão** ainda não está `applied`
  em nenhum servidor `primary` da zona, o servidor `secondary` volta com
  `deferred: true` e **nenhuma operação é criada para ele**. Todo alvo passa a ter o
  campo `deferred`.
- O modal mostra "Aguardando o primário" no secundário. Quando o primário aplica, o modal
  chama o mesmo endpoint de novo (idempotente): o primário vira "Já sincronizado" e a
  operação do secundário é criada. Se o primário falhar, o secundário fica "Não iniciado".
- Se o modal for fechado antes de o primário terminar, o secundário fica pendente: basta
  usar "Publicar e sincronizar" de novo (não há perda; a publicação continua registrada).
- Só painel, sem migration e sem upgrade de agente. 3 testes novos/ajustados em
  `DnsZoneWorkflowTest`.
