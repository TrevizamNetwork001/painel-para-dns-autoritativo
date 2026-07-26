# API do agente DNS

## Registro

`POST /api/agent/enroll`

O registro utiliza um código temporário de uso único. O token permanente é
retornado somente nessa resposta e apenas o SHA-256 é armazenado no banco.

## Autenticação operacional

Os endpoints operacionais exigem:

```text
Authorization: Bearer <token-do-agente>
```

Tokens ausentes ou inválidos retornam `401`. Credenciais revogadas retornam
`403`.

## Heartbeat

`POST /api/agent/heartbeat`

Exemplo:

```json
{
  "hostname": "ns1.exemplo.net",
  "agent_version": "1.0.0",
  "status": "online",
  "capabilities": {
    "bind": true,
    "zones": true
  }
}
```

O heartbeat atualiza `last_seen_at`, estado do agente e capacidades.

## Inventário

`POST /api/agent/inventory`

Exemplo:

```json
{
  "hostname": "ns1.exemplo.net",
  "operating_system": "Debian",
  "operating_system_version": "13",
  "bind_version": "9.20",
  "agent_version": "1.0.0",
  "capabilities": {
    "bind": true
  },
  "inventory": {
    "cpu_count": 4,
    "memory_mb": 8192
  }
}
```

## Revogação

A revogação é feita pelo painel administrativo, vinculada à empresa e ao
servidor. Após revogada, a credencial não pode mais usar heartbeat ou
inventário. Um novo onboarding pode ser realizado com outro código.
