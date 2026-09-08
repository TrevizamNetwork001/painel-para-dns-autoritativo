# DNS Center v1.2.0

Data: 2026-09-03

## Escopo

Feature nova, sem alteração de schema, consolidando duas frentes que se
complementam para administração multi-tenant pela plataforma:

- **Troca de contexto de organização** (`POST /plataforma/empresa-atual`):
  um `is_platform_admin` troca qual empresa está administrando via
  dropdown no menu de conta, sem precisar deslogar/logar como outro
  usuário.
- **Transferência de servidor** (`/servidores/{id}/transferencia`): move
  o cadastro de um servidor DNS de uma empresa pra outra, com
  confirmação explícita (`TRANSFERIR <hostname>`). O cadastro antigo é
  arquivado (`status=transferred`, desativado, não reativável); um novo
  cadastro `pending` nasce na empresa de destino; agente e códigos de
  vínculo antigos são revogados.
- Correção associada em `DnsAgentEnrollmentController::approve()`: o
  mesmo agente físico (mesmo `agent_uuid`) agora consegue reenrolar num
  servidor/empresa diferente depois de uma transferência — antes ficava
  preso num `409` porque o UUID continuava vinculado ao cadastro antigo.
- Lista de servidores separa ativos de arquivados (transferidos) e
  ganhou indicador visual de saúde do agente (operacional/atenção/falha).
- `tests/TestCase.php`: `Cache::flush()` no `setUp()` base, corrigindo
  uma instabilidade pré-existente de rate-limit vazando entre testes
  (`CACHE_STORE=array` em `phpunit.xml` é por processo, não por teste) —
  afetava `DnsAgentApprovalTest` e `DnsAgentPrelinkedEnrollmentTest`.
  Confirmado com 3 execuções completas da suíte (231/231 cada vez).

Ver commits `fecd3db` (onboarding de empresa, já coberto por
[Release 1.1.0](RELEASE_1.1.0.md)), `3ec2b54` (transferência de servidor
+ troca de contexto) e `a25ce95` (isolamento de teste) para o
detalhamento técnico completo.

## Nota sobre o histórico de deploy desta versão

Diferente das versões anteriores, esta não seguiu o ciclo
build → tag → doc → deploy em ordem. O código de `3ec2b54` já estava
presente no working tree (sem commit) desde antes desta sessão de
trabalho; o operador da plataforma rodou
`deploy/dns-center-deploy update` sete vezes em sequência rápida hoje
(`1.1.1` a `1.1.7`, entre 12:08 e 13:58, ver
`/var/lib/dns-center-deploy/history`), iterando/testando a feature
diretamente em produção, antes do código ser commitado e documentado
aqui.

Conferido byte a byte: o conteúdo de `app/`, `resources/` e `routes/`
dentro da imagem `dns-center-app:1.1.7` (a última dessas iterações) é
**idêntico** ao HEAD atual (`a25ce95`) — nenhuma divergência de código,
só a ausência de tag/doc formal para esse intervalo. `v1.2.0` fecha essa
lacuna: mesma versão de código já em produção, agora com identidade
oficial (tag + imagem + doc).

## Testes e gates

- Suíte completa: 231/231, confirmado em 3 execuções seguidas (ver nota
  acima sobre o fix de isolamento que tornou isso possível).
- Pint: 162 arquivos aprovados.
- `view:cache` e `route:list --except-vendor`: aprovados, rotas novas
  presentes (`servers.transfer.*`, `platform.organization-context.update`).
- `git diff --check`: aprovado.

## Deploy

Como o código já está em produção desde as iterações `1.1.1`-`1.1.7`
(idêntico ao commit desta tag), o deploy formal desta versão consiste em
apenas alinhar o rótulo/imagem oficial ao commit tagueado — sem mudança
funcional. Executado via:

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.2.0
```

Executado em produção em 2026-09-08, a partir do HEAD `8e0c3ea` (tag
`v1.2.0`):

- imagens `dns-center-app:1.2.0`/`dns-center-web:1.2.0` construídas
  localmente e conferidas via `composer audit` ("No security
  vulnerability advisories found.") e diff de hash contra a imagem
  `1.1.7` já em produção (idênticas) antes do corte de tráfego;
- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260908T150356Z.dump`, 1322462 bytes, SHA-256
  `0634ec84d00d4f3ab47caf4e1c2fbad72293d7a28a318329580a84cbafc52a11`;
- `migrate:status`/`migrate --force`: nenhuma migration nova;
- `security-check` rodou antes e depois do corte, ambas vezes apenas
  com o warning já conhecido de `TRUSTED_PROXIES` vazio;
- corte de tráfego bem-sucedido; estado registrado:
  `current-version=1.2.0`, `previous-version=1.1.7` — rollback
  disponível via `./deploy/dns-center-deploy rollback`.

Conferido de forma independente após o deploy:
`https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200; as
rotas `servers.transfer.*` e `platform.organization-context.update`
confirmadas via `route:list` no container `app-1` já em produção;
imagem ativa confirmada como `dns-center-app:1.2.0`.
