# DNS Center v1.2.2

Data: 2026-09-08

## Escopo

Dois bugs funcionais no dashboard, achados pelo próprio operador ao
testar a feature de transferência de servidor (v1.2.0) ao vivo. Sem
alteração de schema.

1. **Servidor arquivado (transferido) continuava aparecendo no
   dashboard.** O widget "Estado dos servidores"
   (`resources/views/dashboard/index.blade.php`) não excluía
   `status='transferred'` — então, depois de transferir
   `ns1`/`ns2.legacy.example` da organização de origem pra
   "Cliente Legado", o cadastro antigo (arquivado, desativado)
   continuava listado nessa organização com o rótulo enganoso
   "Aguardando agente" (`'transferred'` não bate em nenhum case do
   `match()` de rótulo, cai no `default`), mesmo com o cadastro novo já
   `online` na organização de destino. `/servidores` já tinha esse
   filtro (`status != 'transferred'`); o dashboard não. Confirmado por
   consulta direta: servidores #3/#4 (org 1, `status=transferred`) eram
   a causa; #7/#8 (org 2, `status=online`) já estavam corretos.
2. **Badge de status sem cor própria.** `.dashboard-server-state`
   (o texto "Online"/"Atenção"/etc.) nunca teve variante de cor — só o
   pontinho ao lado (`.dashboard-server-status-*`) ficava colorido.
   Adicionadas as classes `.dashboard-server-state-{online,warning,
   offline,maintenance}` espelhando as cores já usadas no pontinho.

Ver commit `0c51ca6` para o detalhamento técnico.

## Testes e gates

- `php -l` e `view:cache`: aprovados.
- Query corrigida simulada via tinker contra o banco de produção
  (leitura): confirma que a organização de origem passa a listar
  apenas os 4 servidores ativos, sem o cadastro arquivado.
- Sem teste automatizado dedicado (dashboard não tem cobertura de
  feature test hoje).

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `0c51ca6` (tag
`v1.2.2`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.2.2
```

O primeiro build falhou por instabilidade de rede (timeout conectando
em `fonts.bunny.net` durante `npm run build`, plugin de fontes do
Vite) — sem relação com o código; refeito com sucesso na segunda
tentativa.

- imagens `dns-center-app:1.2.2`/`dns-center-web:1.2.2` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") antes do corte de tráfego. Essa
  versão já leva junto o texto explicativo do secundário da v1.2.1
  (nunca chegou a ser deployada isoladamente — ficou pronta e foi
  incluída aqui);
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260908T153447Z.dump`, 1357967 bytes, SHA-256
  `70dce3c51caf057c730fd2f3877318d175886b4bcb8f3b26b303f7b1e9c8f799`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.2.2`, `previous-version=1.2.0` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200;
imagem ativa confirmada como `dns-center-app:1.2.2`; os 6 serviços
saudáveis.
