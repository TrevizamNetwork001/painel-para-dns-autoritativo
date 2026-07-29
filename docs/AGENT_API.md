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

## Ciclo persistente de publicações

O timer executa heartbeat, inventário, readiness e `--sync-zones`. A
sincronização consulta `GET /api/agent/zones` e usa, sem protocolo paralelo:

- `publication_id`, `desired_version`, `installed_version`, `apply_status` e
  `update_available` para decidir o trabalho;
- `artifact_url`, `artifact_size` e `artifact_checksum` para obter e validar o
  snapshot imutável;
- `POST /api/agent/publications/{publication}/apply` para confirmar
  `applying`, `applied` ou `failed`.

O download bem-sucedido já registra `downloaded` no servidor de forma
idempotente. Não existe uma segunda confirmação de download. O agente não
baixa quando `update_available` é falso e reutiliza um artefato local somente
quando publicação, versão e SHA-256 coincidem.

Cada download é limitado (2 MiB por padrão), verifica `Content-Type`,
`Content-Length`, publicação, versão e SHA-256 quando informados. Ele é escrito
primeiro em arquivo temporário e movido atomicamente para
`state_dir/artifacts`. O staging de uma tentativa fica em
`state_dir/staging/<attempt_id>`. Nomes de zona e URLs são validados; traversal
e symlinks são rejeitados.

### Aplicação e transições

O fluxo é:

```text
pending -> downloaded -> applying -> applied
                            |
                            +-> failed
```

O agente só envia `applying` imediatamente antes da aplicação real e só envia
`applied` depois de escrita atômica das zonas/include, validação final e
`rndc reconfig` bem-sucedido. Em falha após alteração, restaura o backup,
executa nova reconfiguração e mantém a versão instalada anterior.

Por padrão, o serviço periódico conclui apenas download, staging e validação.
Isso não é aplicação. A aplicação BIND real já existe, mas continua exigindo a
decisão local explícita `DNS_CENTER_AGENT_ALLOW_APPLY=1`, execução como root e
`--sync-zones --apply --confirm "APLICAR ZONAS <servidor>"`. O painel não envia
comandos e a instalação automática de pacotes BIND não faz parte deste ciclo.

### Estado local e retomada

`state_dir/state.json` é gravado atomicamente com modo `0600` e contém:

- publicação/versão desejadas, baixadas e instaladas;
- último estado, horário e erro sanitizado;
- `attempt_id`;
- IDs de evento associados ao hash do payload;
- somente metadados dos artefatos (versão, tamanho e checksum).

O arquivo não contém token, headers, assinatura, snapshot, saída bruta de
comando ou TSIG. Após reinício, o mesmo payload reutiliza o mesmo `event_id`;
payload divergente recebe outro ID. Uma tentativa interrompida em `applying`
retoma o mesmo staging e evento idempotente. A confirmação do manifesto evita
reaplicar uma versão já reconhecida pelo painel.

### Rede e falhas

HTTPS é obrigatório, exceto em localhost. Requisições usam timeout e até três
tentativas com backoff limitado. `429` respeita `Retry-After` até 60 segundos;
5xx transitórios usam backoff. `401`, `403`, `404`, `409` e `422` não entram em
retry rápido. Em `409 event_replay`, o evento divergente é descartado para que
uma tentativa posterior gere ID novo.

Erros persistidos, enviados ou registrados removem HTML, controles, segredos,
headers de autorização, comandos e caminhos sensíveis, e são limitados a 1000
caracteres. Subprocessos usam catálogo local, argumentos em lista,
`shell=False`, timeout e saída limitada.
