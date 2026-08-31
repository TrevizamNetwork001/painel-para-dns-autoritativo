# DNS Center v1.0.0

Data da promoção: 2026-08-31

## Decisão

`v1.0.0` promovida a partir de `v1.0.0-rc3` (commit `bacf3bd`), depois de a
homologação operacional curta exigida por
[`RELEASE_CANDIDATE_2.md`](RELEASE_CANDIDATE_2.md) ter sido concluída e
documentada em [`RC_HOMOLOGATION_2.md`](RC_HOMOLOGATION_2.md)
(2026-08-31). O requisito de idempotência do relatório DISCOVER (commit
`6aedb40`, ver [`RELEASE_CANDIDATE_3.md`](RELEASE_CANDIDATE_3.md)) foi
confirmado ao vivo, via HTTP real contra uma instância isolada da aplicação
— não apenas pela suíte automatizada — fechando a última pendência que o
fechamento da rc3 deixava em aberto.

## Correção de uma tag irregular

Já existia neste repositório uma tag `v1.0.0`, criada em
2026-08-29 12:03:27 -03:00 (17 minutos depois da tag `v1.0.0-rc3`),
apontando para o mesmo commit `bacf3bd`, com a mensagem "Stable release
promoted from v1.0.0-rc3 after successful final smoke tests, security
checks and release gates." Essa tag não tinha nenhuma documentação
correspondente — quebrando o padrão de todas as outras fases deste projeto,
que sempre registram hashes, timestamps e evidências — e contradizia
diretamente o texto do próprio fechamento da rc3: "`v1.0.0` não foi criada
neste fechamento." Dezessete minutos não é tempo suficiente para repetir o
tipo de homologação operacional que este projeto historicamente exige.

Não foi encontrado, em commits, branches, docs ou reflog, nenhum registro
que explicasse a origem dessa tag; o autor também não soube identificá-la.
Como a homologação operacional real desse fix só foi concluída agora
(`RC_HOMOLOGATION_2.md`, 2026-08-31), a tag original fazia uma afirmação
("smoke tests, security checks and release gates" bem-sucedidos) que ainda
não era verdadeira no momento em que foi criada.

Ela foi apagada e recriada apontando para o commit que adiciona este
documento e a homologação que o sustenta, com uma mensagem que reflete o
que de fato aconteceu e quando. Nenhuma tag deste projeto foi publicada
externamente em nenhum momento (`git tag` local, sem `push`) — esta
correção é inteiramente local e não afeta nenhum sistema fora deste
repositório.

## Escopo da 1.0.0

- Feature freeze desde `v1.0.0-rc2` (`RELEASE_CANDIDATE_2.md`).
- Correção de idempotência do DISCOVER (`6aedb40`, `RELEASE_CANDIDATE_3.md`).
- Homologação operacional completa herdada de `RC_HOMOLOGATION_1.md`
  (37 itens: BIND real, primary/secondary, TSIG, backup/restore,
  upgrade/rollback).
- Homologação operacional curta específica do fix de idempotência,
  concluída em `RC_HOMOLOGATION_2.md`.
- Fora de escopo: IMPORT-PILOT e ADOPT de BIND legado, SMTP, Telegram, WAF
  integrado, automações adicionais, failover automático de delegação e
  IXFR incremental (lista completa de limitações em
  `RELEASE_CANDIDATE_2.md`).

## Deploy

Esta tag marca o código pronto para promoção. O procedimento formal de
build/deploy com imagem imutável (`compose.build.yaml` +
`deploy/dns-center-deploy`, documentado em `DEPLOY_BASELINE_1.md`) foi
executado nesta produção em 2026-08-31, a partir do HEAD `261e3ee` (um
commit à frente da tag `v1.0.0`, só com a atualização de status do README).

Já havia uma instalação registrada nesta stack, então o subcomando usado foi
`update`, não `install`:

```
sudo DNS_CENTER_DEPLOY_CONFIG=/etc/dns-center/deployment.env \
  ./deploy/dns-center-deploy update 1.0.0
```

- backup do PostgreSQL criado e validado antes de qualquer migration:
  `dns-center-20260831T202940Z.dump`, 579375 bytes, SHA-256
  `ad09bf834a921caab7700e375f13c4b48b3c4bcdc98888ff67680adc50761072`;
- nenhuma migration nova a aplicar (as 31 já estavam `Ran` na versão
  anterior — `migrate` reportou "Nothing to migrate");
- `security-check` rodou duas vezes (pré e pós corte de tráfego) e retornou
  apenas o warning já conhecido de `TRUSTED_PROXIES` vazio, sem nenhum
  bloqueio;
- corte de tráfego com `compose up --wait` bem-sucedido; os seis serviços
  (`app`, `queue`, `scheduler`, `web`, `postgres`, `redis`) ficaram
  saudáveis com a imagem `:1.0.0`;
- `https://dnscenter.trevizamnetwork.com.br/up` respondeu HTTP 200 após o
  deploy;
- estado registrado: `current-version=1.0.0`,
  `previous-version=1.0.0-rc3-candidate` — rollback disponível via
  `./deploy/dns-center-deploy rollback`, sem reversão de banco (não houve
  migration nova, então não há incompatibilidade de schema a considerar).

Antes do build, o host tinha 84% do disco em uso (3,0 GB livres); imagens e
build cache de ciclos de desenvolvimento antigos, já sem uso, foram
removidos (`docker image prune`/`docker builder prune`, ~9 GB liberados),
preservando a imagem em produção até o corte de tráfego.
