# DNS Center v1.3.18

Data: 2026-09-16

## Escopo

Redução do TTL padrão usado pelas zonas, de 3600s (1 hora) pra 300s (5
minutos), pra alinhar a velocidade de propagação de mudanças com o
que o operador espera de provedores como a Cloudflare.

### Contexto

Discussão com o operador sobre por que a Cloudflare "parece" mais
rápida ao publicar mudanças: não é a velocidade de publicação em si
(o DNS Center já publica em segundos via `NOTIFY`/AXFR, igual
qualquer provedor autoritativo) — é que um TTL mais baixo reduz por
quanto tempo os resolvers dos usuários finais podem continuar servindo
uma resposta antiga em cache depois de uma mudança. Registros nunca
consultados antes já ficam visíveis na hora, independente do TTL; o
TTL só importa pra quem já tinha a resposta anterior em cache.

### Mudança

- `resources/views/zones/index.blade.php`: campo "TTL padrão" do
  formulário de criação de zona agora sugere `300` em vez de `3600`.
- `app/Http/Controllers/DnsZoneController.php`: fallback de
  `default_ttl` usado pela criação de zona reversa (que não pergunta
  TTL na tela, ver v1.3.9) passa de `3600` pra `300`.
- Nova migration
  `2026_09_16_190000_lower_default_ttl_column_default.php`: ajusta o
  `DEFAULT` da coluna `dns_zones.default_ttl` no schema, de `3600`
  pra `300`, pra manter o schema consistente com o comportamento da
  aplicação.
- `resources/views/zones/show.blade.php`: rótulo da opção
  "Automático" no seletor de TTL de registro deixou de imprimir o
  número bruto ("Automático — 3600") ao lado de uma opção fixa igual
  ("1 hora"), o que parecia uma duplicata. Agora mostra "Automático
  (padrão da zona)", com o valor efetivo em segundos disponível via
  tooltip (`title`) — sem mudar nenhum valor salvo, só a legibilidade
  do formulário.

### Dado existente (produção)

Depois do deploy, as 20 zonas já cadastradas (13 de teste, 7 da
Cliente Legado) tiveram `default_ttl` atualizado de 3600 pra 300 via script
único, seguindo o mesmo padrão de qualquer edição de configuração de
zona: versão e serial incrementados, uma nova `DnsZoneVersion`
(snapshot) criada, e — pras zonas que estavam `published` — o status
voltou pra `ready`. Nenhuma zona foi publicada nem aplicada
automaticamente; o operador confirmou explicitamente antes da
execução e vai republicar/aplicar cada zona normalmente pelo painel.

## Testes e gates

- `php artisan test`: 277 testes, 1387 assertions — sem regressão
  (nenhum teste dependia do valor literal 3600 como fallback; os
  testes que usam 3600 sempre enviam esse valor explicitamente no
  payload).
- Pint: sem violações nos arquivos alterados.
- `composer audit`: sem vulnerabilidades.

## Deploy

Executado em produção em 2026-09-16, a partir do HEAD `4a92e92` (tag
`v1.3.18`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.18
```

- imagens `dns-center-app:1.3.18`/`dns-center-web:1.3.18` construídas
  localmente, suíte completa reconferida na imagem final e
  `composer audit` sem vulnerabilidades antes do corte de tráfego;
- backup do PostgreSQL criado antes da migration:
  `dns-center-20260916T190652Z.dump`, 2458239 bytes, SHA-256
  `2a15c6ca02e05a4c4b3a97264c86e7e3a9e214793e1b67f5ed8ef5f1444d2343`;
- `migrate --force`: `2026_09_16_190000_lower_default_ttl_column_default`
  aplicada com sucesso (`migrate:status` confirmado como `Ran`);
- corte de tráfego bem-sucedido.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; os 4
serviços da aplicação confirmados na imagem `1.3.18`.

Pendente (operacional, fora deste deploy): publicar e aplicar de novo
as 7 zonas da Cliente Legado que ficaram em `ready` depois da atualização de
TTL (`legacy.example` + as 5 zonas reversas + a zona de
delegação `1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa`), pra o `$TTL 300`
realmente valer nos servidores BIND.
