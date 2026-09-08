# DNS Center v1.3.1

Data: 2026-09-08

## Escopo

Correção de UX, sem alteração de schema nem de lógica de negócio.

As abas da tela de zona (Registros DNS, Configuração, DNS reverso,
Publicação, Histórico) são controladas só por `#hash` no navegador
(JS client-side) — o servidor nunca sabe em qual aba o usuário estava.
Os botões de ação dessas abas (concluir adoção, publicar, vincular
PTR, associar TSIG) são formulários POST reais, e usavam `back()`, que
redireciona pro `Referer` — mas o header `Referer` nunca inclui o
fragmento `#hash`. Resultado: depois de qualquer uma dessas ações, o
usuário caía sempre na primeira aba ("Registros DNS"), precisando
voltar manualmente pra aba certa pra continuar o fluxo.

Reportado ao vivo pelo operador enquanto testava a importação da
Conecta Network (commit `0ccc4ef`): troca `back()` por
`redirect(route('zones.show', $zone).'#aba')` em `adopt()`,
`publish()`, `ptrSync()` (`DnsZoneController`, aba `publication`) e
`associate()` (`DnsTsigKeyController`, aba `reverse`).

## Testes e gates

- `DnsZoneWorkflowTest`, `DnsZonePtrSyncTest`, `DnsAuthoritativeTransferTest`
  (17 testes) — sem regressão, nenhum teste dependia da URL exata de
  redirect anterior.
- Suíte completa: 236/236.
- Pint: 164 arquivos aprovados.
- `view:cache`: aprovado.
- `git diff --check`: aprovado.

## Deploy

Executado em produção em 2026-09-08, a partir do HEAD `0ccc4ef` (tag
`v1.3.1`):

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.3.1
```

_(seção a completar após a execução)_
