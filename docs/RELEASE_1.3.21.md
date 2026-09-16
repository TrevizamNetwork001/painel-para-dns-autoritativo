# DNS Center v1.3.21

Data: 2026-09-16

## Escopo

Tela de **Auditoria** global, por organização — pedida pelo operador
depois de enviar o projeto antigo (PHP puro, pré-Laravel) do DNS
Center, que tinha uma página assim e não existia mais no sistema
atual. Diferente do histórico "Versões do domínio" (por zona,
`DnsZoneVersion.reason`, detalhado na v1.3.20), esta é uma trilha
única cobrindo todas as ações administrativas do sistema.

### Decisões confirmadas com o operador

- Acesso: `organization_admin` ou `platform_admin` (mesmo nível de
  hoje pra tela de Usuários).
- Escopo: sempre só da organização atual — igual todo o resto do
  painel, sem exceção pra platform_admin ver tudo junto.
- Login/logout/falha de login entram nesta v1, junto com as ações
  administrativas — parecido com o sistema antigo.

### O que foi feito

- Tabela nova `dns_audit_logs` e model `App\Models\DnsAuditLog` —
  sem tocar em `security_audits` (que segue existindo, exclusivo pra
  eventos de segurança sem tela própria).
- `App\Support\DnsAuditLogger::record()`, espelhando
  `SecurityAuditLogger`, chamado de dentro da mesma transação da ação
  que está registrando (mesmo padrão do `bump()` já usado em
  `DnsZoneController`).
- Instrumentado: criação/edição/publicação de zona, criação/edição/
  remoção de registro DNS (reaproveitando o mesmo texto já calculado
  pro histórico por zona da v1.3.20), criação/edição/status de
  servidor, criação/papel/status de usuário, criação de organização,
  e login/logout/falha de login (`RecordAuthenticationActivity`).
- Tela nova em `/auditoria`: filtro por domínio/ação/usuário/status,
  paginação (50/página), exportação CSV com as mesmas colunas do
  sistema antigo (Data, Usuário, Ação, Domínio, Registro, Valor
  antigo, Valor novo, Status). Item novo "Auditoria" no menu lateral,
  seção "Operações".

### Fora de escopo nesta v1

Ações de nameserver, rotação de chave TSIG e 2FA ainda não geram
entrada na auditoria nova — podem entrar depois se fizer falta.

## Testes e gates

- `tests/Feature/DnsAuditLogTest.php` (novo, 9 testes): registro de
  zona/registro/servidor/usuário/login-logout gera a entrada esperada;
  RBAC (operator/viewer recebem 403); isolamento entre organizações;
  filtros; exportação CSV.
- `php artisan test`: 290 testes, 1458 assertions — sem regressão
  (281 → 290).
- Pint: 175 arquivos aprovados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `b2f7d74` (tag
`v1.3.21`), junto com a v1.3.20 (bundle único, nenhum deploy separado
da v1.3.20 foi feito):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.21
```

- imagens `dns-center-app:1.3.21`/`dns-center-web:1.3.21` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado antes da migration:
  `dns-center-20260916T202130Z.dump`, 2508359 bytes, SHA-256
  `d98e17e3c656869dfc503e74d3221e59ff444ef8d2901db3fd3811ffef20df8c`;
- `migrate --force`: `2026_09_16_200000_create_dns_audit_logs_table`
  aplicada com sucesso (`migrate:status` confirmado como `Ran`);
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.21`.
