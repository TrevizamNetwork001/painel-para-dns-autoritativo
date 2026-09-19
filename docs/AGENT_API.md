# API do agente DNS

## Instalação e enrollment

O instalador formal está descrito em `DEPLOY_BASELINE_1.md`. Ele aceita
`DNS_CENTER_PANEL_URL`, exige HTTPS fora de localhost e verifica SHA-256 do
Python e de todas as units systemd antes de instalar. O servidor deve ser
cadastrado previamente no painel.

Instalação (Python, diretórios e units systemd) e enrollment (vínculo e
credencial) são etapas independentes. Uma instalação sem credencial é um
estado válido e pode ser vinculada novamente sem reinstalação:

```text
sudo /usr/local/sbin/dns-center-agent --enroll --wait 0
```

O comando solicita o código no terminal sem eco. Para automação controlada,
`--enroll --stdin` lê o código da entrada padrão; não existe argumento de CLI
que aceite o segredo.

`POST /api/agent/install-requests`

No fluxo principal, um administrador com 2FA emite no servidor selecionado um
código CSPRNG de uso único e curta duração. Somente SHA-256 é persistido. O
código carrega implicitamente `organization_id` e `dns_server_id`; ao ser
consumido sob lock, a solicitação já nasce associada. Hostname e IP observado
geram warnings de revisão, mas nunca mudam o servidor escolhido.

Sem código, o endpoint conserva o matching por hostname/IP exclusivamente como
modo legado e recovery. Solicitações desse modo continuam na fila de associação
manual e não são aprovadas durante a associação.

O administrador da organização aprova a instalação usando a sessão
administrativa já protegida por 2FA. O agente consulta
`POST /api/agent/install-requests/status`; após aprovação, o token operacional
é entregue uma única vez à mesma credencial efêmera. Somente hashes ficam
persistidos após a entrega.

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
  "agent_version": "0.12.0",
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
    "load_1m": 0.8,
    "cpu_load_percent": 20.0,
    "memory_total_mb": 8192,
    "memory_available_mb": 4096,
    "memory_used_percent": 50.0,
    "disk_total_gb": 100.0,
    "disk_free_gb": 75.0,
    "disk_used_percent": 25.0,
    "uptime_seconds": 90061
  }
}
```

Desde o agent 0.12.0, esses indicadores são coletados localmente por leitura
do sistema e enviados pelo endpoint autenticado. `cpu_load_percent` é a carga
de 1 minuto normalizada pela quantidade de CPUs lógicas; não é uma amostra
instantânea de utilização. Nenhum comando, processo, log, endereço ou segredo
é incluído nessa telemetria.

## Revogação

A revogação é feita pelo painel administrativo, vinculada à empresa e ao
servidor. Após revogada, a credencial não pode mais usar heartbeat ou
inventário. O histórico de instalação é preservado e um novo código inicia
reenrollment; não é necessário reinstalar o agente. Uma credencial ainda ativa
impede emissão e consumo de vínculo simples, exigindo revogação/rotação
explícita.

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
Isso não é aplicação. A aplicação BIND real já existe e pode acontecer de duas
formas, ambas exigindo `DNS_CENTER_AGENT_ALLOW_APPLY=1` e execução como root:

- manual via SSH: `--sync-zones --apply --confirm "APLICAR ZONAS <servidor>"`;
- via painel, botão "Aplicar agora" na aba de publicação da zona: cria uma
  operação autorizada `apply_zones` (mesmo mecanismo de `install_bind`/
  `upgrade_agent`) que o agente coleta na próxima janela do timer de operações
  (`--run-authorized-operation`, ~30s) e executa como
  `sync_zones(config, apply=True, confirmation="APLICAR ZONAS <servidor>")` —
  a frase de confirmação é suprida internamente pelo agente, já que a
  autorização humana aconteceu no clique de confirmação do modal do painel,
  não por digitação de texto.

Desde o agente 0.7.8, a unit `dns-center-agent-operation.service` instala
`DNS_CENTER_AGENT_ALLOW_APPLY=1` por padrão. Portanto, no fluxo pelo painel,
a autorização efetiva é a operação criada por um administrador no painel; a
variável de ambiente não é mais uma aprovação local independente. O comando
manual continua exigindo a variável e a frase de confirmação. Uma instalação
antiga que ainda use a unit anterior precisa de atualização do agente ou da
unit para aceitar aplicações pelo painel. A instalação automática de pacotes
BIND não faz parte deste ciclo.

Desde 0.10.2, um resultado final `succeeded`/`failed` é salvo em
`state_dir/pending-operation-report.json` antes do envio. Se a rede falhar, o
próximo ciclo de operações reenvia o mesmo `event_id` antes de buscar novo
trabalho. O arquivo é removido após a confirmação do painel.

O upgrade aguarda até dez minutos para ser coletado e, depois de iniciado,
tem até vinte minutos para terminar (configurações separadas no painel). A
unit de operações 0.10.2 limita a execução local a dezoito minutos. O agente
reporta a versão instalada no resultado do upgrade, permitindo confirmação
sem esperar o heartbeat periódico.

Desde 0.11.0, todas as operações autorizadas que tocam BIND compartilham o
mesmo lock usado pela sincronização periódica. Um relatório terminal local
corrompido é preservado em `state_dir/quarantine` e deixa de bloquear todos os
ciclos futuros. O diagnóstico local, sem acesso ao painel, está disponível em
`dns-center-agent --doctor`; detalhes em
`AGENT_HARDENING_2026_09_19.md`.

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
