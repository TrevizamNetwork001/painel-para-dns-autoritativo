# Release 1.3.56 (agente 0.15.0)

Primeira entrega da Fase 2 do firewall via agente.

- política por servidor com ACLs IPv4/IPv6 e portas públicas/administrativas;
- recusa de ACL administrativa aberta em `/0`;
- contrato determinístico e hash do estado desejado;
- operação autorizada `validate_firewall_policy`;
- o agente gera a tabela isolada em arquivo temporário e executa somente
  `nft --check --file`; nenhuma regra é aplicada ou persistida;
- o resultado informa explicitamente `rules_applied: false`.

Aplicação, rollback e piloto permanecem nas Fases 3 e 4 e não fazem parte
desta release. R2 e Zabbix também permanecem fora do escopo.
