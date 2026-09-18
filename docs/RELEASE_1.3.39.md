# Release 1.3.39 (agente 0.10.1)

## Agente: secundário espera a transferência antes de confirmar o serial

`apply_zones` falhava na primeira tentativa em servidores secundários (ex.:
`dns-secondary`, zonas 158 e 8.b.d.0.1.0.0.2.ip6.arpa) com "BIND não confirmou o serial
SOA esperado": o primário e o secundário recebem a publicação ao mesmo tempo, e o
secundário só tem o serial novo depois de transferir a zona; o agente esperava só
10 tentativas de 1s. A segunda tentativa passava.

- `authoritative_serial(..., zone_type)`: zonas `secondary` esperam até 45
  tentativas (configurável em `serial_confirmation_attempts_secondary`, máx. 60);
  primárias mantêm 10 (`serial_confirmation_attempts`).
- `AGENT_VERSION` 0.10.0 → 0.10.1. **É preciso atualizar o agente nos servidores**
  (função "Atualizar agente" da página do agente) para o ajuste valer.
- Testes Python: espera da transferência, primário com espera curta, desistência
  no limite e limite configurável com teto.

## Histórico de falhas do agente (apurado em 18/09/2026)

`apply_zones`: 16 falhas / 12 sucessos no total, mas 14 das falhas são de
16/09 (onboarding: erro sem mensagem, já corrigido na `ea1ef72`, e a zona
legada duplicada, hoje tratada pelo painel). Desde 17/09 restaram só as 2 falhas
acima. `upgrade_agent`: 2 expiradas em agosto.
