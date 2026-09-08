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
   `ns1`/`ns2.conectanetwork.net.br` da organização de origem pra
   "Conecta Network", o cadastro antigo (arquivado, desativado)
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

_(seção a completar após a execução)_
