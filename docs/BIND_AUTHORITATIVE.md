# Núcleo autoritativo BIND

A Fase 3A introduz:

- zonas primary e secondary;
- isolamento por empresa;
- associação com servidores primary e secondary;
- registros A, AAAA, CNAME, MX, TXT, CAA, NS e PTR;
- serial SOA controlado;
- histórico de versões;
- preview de zonefile BIND;
- publicação explícita;
- manifesto e artefato para agentes autenticados.

O painel não grava em `/etc/bind`, não executa `rndc` e não reinicia ou
recarrega o BIND. Aplicação real, validação local, backup e rollback ficam
para a fase operacional do agente.

A evolução primary/secondary, incluindo AXFR/IXFR, NOTIFY, TSIG, confirmação
do serial observado e continuidade do secondary, está descrita em
`BIND_PRIMARY_SECONDARY.md`.
