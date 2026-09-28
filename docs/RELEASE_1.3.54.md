# Release 1.3.54 (agente 0.14.0)

## Log do BIND no painel (somente leitura)

Na página do agente, cartão "Estado do BIND", botão **"Ver últimas linhas do log
do BIND"**: abre um modal, pede ao agente as últimas ~200 linhas do journal do
serviço `named` (`journalctl -u named -n 200`) e mostra. Nada é alterado; a
janela avisa isso.

- Agente: `tail_bind_log(config, lines=200)` (nova função, separada de
  `bind_recent_events()` — que continua só para o cálculo interno de status,
  com seu próprio limite de 8000 bytes). Sanitiza **cada linha** individualmente
  (não o texto inteiro — o regex de `token/secret/...` consome o resto da
  linha ao encontrar o rótulo; aplicado ao blob inteiro, uma única ocorrência
  no meio do log apagaria tudo depois dela).
- Painel: ação `tail_bind_log` nova em `DnsBindOperation`; `sanitizeResult()`
  precisou ganhar a chave `lines` com tratamento próprio (array, não escalar) —
  sem isso o resultado chegava vazio, o mesmo bug corrigido para
  `remove_legacy_zone_block` na 1.3.29. Sanitiza de novo no painel por defesa
  em profundidade, nunca confiando cegamente no agente.
- Rotas `servers.bind.log.store`/`.status` (admin, 2FA, throttle 5/min).

## Forçar transferência de zona (destrava secundário atrasado)

Na tela da zona, ao lado do resumo de publicação, botão **"Forçar
transferência em `<servidor secundário>`"**: pede ao BIND desse servidor
(`rndc retransfer`) para buscar a zona de novo agora, em vez de esperar o
próximo refresh natural. Não toca em `named.conf` nem em nenhum arquivo — é a
mesma transferência de rede que o BIND já faz sozinho.

- Agente: `force_zone_transfer(config, params)` — confirma que a zona existe
  no servidor (`rndc zonestatus`) antes de tentar, roda `rndc retransfer`,
  espera 3s e reporta serial antes/depois via `rndc zonestatus` de novo
  (reaproveita `parse_rndc_zonestatus`, já usado noutro lugar do agente).
  Exige root, `shell=False`, valida o nome da zona por regex antes de passar
  ao `rndc`.
- Painel: confere que o servidor de destino é o **secundário cadastrado desta
  zona** antes de autorizar (409 se for o primário ou um servidor sem relação
  com ela) — mesmo padrão de "reconfirma contra o que o painel sabe agora" já
  usado em `remove_legacy_zone_block`.
- Rotas `servers.bind.transfer.store`/`.status` (admin, 2FA, throttle 5/min).

## Achado ao investigar: alerta de secundário atrasado já existia

Não foi construído nesta release — outra sessão já implementou isso no
Dashboard (`serial_mismatch`/`transfer_failed`/`primary_unreachable`/`expired`
como itens pendentes com severidade), reaproveitando a tabela
`dns_authoritative_observations` que o agente já alimenta via `--observe-bind`.
Conferido e nada foi duplicado.

## Agente 0.14.0

**Exige atualizar o agente nos servidores** (Servidores → Agente → Atualizar)
para as duas ações novas funcionarem; sem isso `tail_bind_log`/
`force_zone_transfer` ficam listadas mas o agente antigo não as reconhece.

## Testes

- Python (`tests/Agent/test_dns_center_agent.py`): log sanitizado linha a
  linha sem apagar a linha inteira por causa de um segredo isolado, unidade
  do serviço correta, limite de linhas (clamped a 1000), falha sem
  `journalctl`, falha limpa se o journalctl retornar erro; transferência
  exige root, rejeita nome de zona inválido (incluindo tentativa de injeção),
  roda com `shell=False`, reporta serial antes/depois, zona desconhecida falha
  limpo, falha do `rndc retransfer` é reportada.
- PHP (`tests/Feature/DnsBindDiagnosticsTest.php`): admin-only, throttle/
  em-andamento, isolamento por tenant, `sanitizeResult()` preserva as linhas e
  sanitiza um segredo vazado sem apagar a linha toda, forçar transferência
  recusa servidor primário/sem relação com a zona, endpoint de próxima
  operação entrega os parâmetros certos ao agente.
- Suíte completa: 410 (PHP) + 165 (Python).

Sem migration.
