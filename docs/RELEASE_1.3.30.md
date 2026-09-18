# Release 1.3.30

## Página própria para zonas reversas ("Reversos")

As zonas de DNS reverso (`in-addr.arpa` e `ip6.arpa`) deixam de aparecer misturadas
à lista de Domínios.

- **Domínios** (`zones.index`) passa a excluir zonas reversas (`isReverseZone()` /
  `isIpv6ReverseZone()`).
- **Reversos** (`zones.reverse`, `GET /zonas/reversos`): duas seções, IPv4 e IPv6,
  cada uma com a tabela de zonas mostrando o **bloco CIDR calculado**
  (`203.0.113.0/24`, prefixo IPv6) no lugar do nome longo, e busca compartilhada.
  Calculado por `ReverseZoneNameCalculator`.
- Item "Reversos" na barra lateral, logo abaixo de "Domínios".
- **A rota `/zonas/reversos` precisa vir antes de `/zonas/{zone}`** em `routes/web.php`;
  caso contrário o route-model-binding tenta resolver "reversos" como ID de zona e
  devolve 404.
- Inspirado na organização do painel antigo (módulos separados de PTR IPv4/IPv6 e
  domínios). Sem migration.
