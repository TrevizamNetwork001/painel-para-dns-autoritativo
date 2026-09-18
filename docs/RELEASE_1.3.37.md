# Release 1.3.37

## Sincronizador de nameservers não duplica mais os NS do apex

`DnsZoneNameserverSynchronizer::synchronizeApexNameservers` só removia NS com
nome `@` ou `zona` (sem ponto final). Zonas importadas do BIND trazem o apex como
FQDN (`zona.`), então os NS antigos sobreviviam e a zona ficava com cada NS duas
vezes (uma com TTL 3600, outra sem TTL). Agora o apex é reconhecido como `@`,
`zona` ou `zona.`, em qualquer caixa. Delegações de subdomínio continuam intactas.

Zonas já afetadas (156–113.0.203.in-addr.arpa) ficam limpas ao **salvar a
configuração** da zona de novo, pois o salvamento re-sincroniza o perfil.
Teste novo cobre apex em FQDN, em outra caixa e "@", e a delegação preservada.
Sem migration.
