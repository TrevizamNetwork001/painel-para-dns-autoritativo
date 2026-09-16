# DNS Center v1.3.15

Data: 2026-09-16

## Escopo

Decisão consciente de segurança/operacional, discutida e confirmada com
o operador em produção: o agente 0.7.8 passa a habilitar
`DNS_CENTER_AGENT_ALLOW_APPLY=1` por padrão.

### Contexto

O botão "Aplicar agora" (v1.3.9) já funcionava inteiramente pelo
painel, mas ainda exigia um passo manual de SSH em cada servidor —
`systemctl edit dns-center-agent-operation.service` adicionando
`DNS_CENTER_AGENT_ALLOW_APPLY=1` — antes que a operação `apply_zones`
funcionasse de verdade. Isso foi confirmado ao vivo hoje: o primeiro
`apply_zones` real disparado no ns1 da Cliente Legado falhou com "Apply
bloqueado: defina DNS_CENTER_AGENT_ALLOW_APPLY=1." porque esse opt-in
nunca tinha sido feito nesse servidor.

O operador pediu uma forma de não depender de SSH manual por cliente.
Confirmado explicitamente: embutir o opt-in por padrão, aceitando o
trade-off de que a "trava local" por servidor deixa de existir — a
autorização passa a depender inteiramente do RBAC do painel (só
`organization_admin`/`platform_admin` cria a operação, via clique +
confirmação no modal), que já é a trava real usada no dia a dia.

### Mudança

- `agent/systemd/dns-center-agent-operation.service`: adiciona
  `Environment=DNS_CENTER_AGENT_ALLOW_APPLY=1` ao `[Service]`.
- Esse arquivo é servido diretamente pelo painel em
  `/install/dns-center-agent-operation.service`
  (`routes/web.php:43-66`), tanto pro instalador (`agent_install.sh`,
  instalação nova) quanto pelo próprio agente em auto-atualização
  (`upgrade_agent_self()`, que já compara byte-a-byte e reescreve
  units cujo conteúdo mudou, seguido de `systemctl daemon-reload`) —
  **nenhum outro arquivo precisou mudar** pra isso valer tanto pra
  instalações novas quanto pras já existentes.
- `AGENT_VERSION` bump pra `0.7.8`, necessário pro botão "Atualizar
  agente" detectar a atualização nos agentes já instalados (ns1/ns2 da
  Cliente Legado e dns-primary, hoje em 0.7.7) — a comparação de versão
  (`AgentArtifact::isNewerThan()`) é o que decide se o botão mostra
  "atualização disponível".

### Rollout

Depois do deploy, é preciso clicar em "Atualizar agente" em cada
servidor já cadastrado pra esse opt-in realmente passar a valer nele —
o deploy da imagem do painel sozinho não muda nada nos agentes já
instalados até a próxima atualização.

## Testes e gates

- Novo teste `tests/Agent/test_agent_install_script.py::AgentOperationUnitTest::test_operation_service_enables_apply_by_default`
  confere que a linha `Environment=DNS_CENTER_AGENT_ALLOW_APPLY=1`
  está presente no unit file.
- `python3 -m unittest discover -s tests/Agent`: 105 testes — sem
  regressão.
- `php artisan test`: 277 testes, 1387 assertions — sem regressão
  (nenhuma mudança de código Laravel nesta versão além de servir o
  arquivo já atualizado).
- Pint: 169 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `5dcadde` (tag
`v1.3.15`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.15
```

- imagens `dns-center-app:1.3.15`/`dns-center-web:1.3.15` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260916T171550Z.dump`, 2345040 bytes, SHA-256
  `8f9e23e196624750ad5dea009e5c9faf1b30761a9396467161818df552af4dc9`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.3.15`, `previous-version=1.3.14` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.15` e saudáveis.

Pendente (operacional, fora deste deploy): atualizar o agente do
ns1/ns2/dns-primary pra 0.7.8 via botão "Atualizar agente" — sem isso, o
opt-in não é aplicado nos servidores já existentes.
