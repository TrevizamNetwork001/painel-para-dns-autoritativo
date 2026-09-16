# DNS Center v1.3.17

Data: 2026-09-16

## Escopo

Melhoria de UX pedida pelo operador: ao concluir uma atualização de
agente com sucesso, o modal de progresso ("Atualização do agente")
antes ficava aberto esperando um clique manual em "Fechar". Agora, ao
receber o resultado de sucesso, o modal fecha sozinho e a página
navega automaticamente para a dashboard principal do painel — sem
exigir a ação manual.

### Mudança

- `resources/views/servers/partials/agent-operational.blade.php`:
  botão de atualização passa a carregar
  `data-agent-upgrade-dashboard-url="{{ route('dashboard') }}'`.
- `resources/views/servers/agent.blade.php`: `succeeded()` (handler de
  sucesso do polling de atualização) agora agenda, 2.5s depois de
  mostrar o resumo (versão anterior/atual), o fechamento do modal e a
  navegação pra dashboard (`window.location.assign`). O atraso dá
  tempo do operador ver o resumo antes da tela mudar. Só se aplica ao
  fluxo de sucesso — falha, expiração e "aguardando confirmação"
  continuam exigindo fechamento manual, sem navegação automática.

Nenhuma mudança de backend, banco de dados ou agente Python nesta
versão — só o front-end da página de servidor.

## Testes e gates

- `tests/Feature/DnsAgentUpgradeTest.php`: 20 testes, 83 assertions —
  sem regressão (nenhum teste depende do atributo/comportamento
  alterado além de uma checagem de substring que continua válida).
- `php artisan test`: 277 testes, 1387 assertions — sem regressão.
- Pint: 169 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `f33c846` (tag
`v1.3.17`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.17
```

- imagens `dns-center-app:1.3.17`/`dns-center-web:1.3.17` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado antes de qualquer migration:
  `dns-center-20260916T184303Z.dump`, 2353664 bytes, SHA-256
  `1dce3f6d7f3b4d771afbd2f4ad0c36ff51d50dfb3cabc671faa5b634b1d7c5de`;
- nenhuma migration nova nesta versão (mudança restrita a front-end);
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação (`app`, `web`, `queue`, `scheduler`) confirmados
na imagem `1.3.17`.
