# Release 1.3.53

## Limpeza automática de disco (evita o incidente de 28/09/2026)

O build da 1.3.52 falhou porque o disco chegou a 100% (imagens/cache Docker
acumulados). `deploy/disk-guard.sh` (cron `deploy/cron.d/dns-center-disk-guard`,
todo dia às 03:15):

- `docker image prune -f` (dangling, sempre seguro);
- `docker image prune -a -f --filter until=168h` / `docker builder prune -f --filter until=168h`
  (imagens sem tag em uso e cache de build com mais de 7 dias — nunca atinge
  imagem de container em execução);
- registra uso do disco em `/var/log/dns-center-disk-guard.log`, com um `AVISO`
  quando passa de 80% (`DNS_CENTER_DISK_WARN_PERCENT`, configurável).

Tela **Configurações → Backup do banco** ganha um cartão "Espaço em disco"
(GB livres/total, badge de alerta acima de 65%/80%), reaproveitando a mesma
página admin-only já existente. Sem canal de e-mail/Zabbix configurado neste
servidor — o cartão no painel e o log são, por ora, os dois lugares para
acompanhar isso.

Testes: `tests/Agent/test_disk_guard_script.py` (limiar customizado, rejeita
valor não numérico, aviso aparece/não aparece conforme o uso, roda mesmo sem
`docker` no PATH).

## Importar e configurar em lote na tela de descoberta

Reduz o clique-a-clique de adotar várias zonas do mesmo servidor: a mesma tela
de "Zonas encontradas" ganha, antes do botão "Importar selecionadas", um
seletor opcional de **perfil de nameservers** + **servidor principal/secundário**.
Quando preenchido, cada zona importada já sai **configurada e adotada**
(`origin=managed`), reaproveitando exatamente a mesma lógica já testada de
`DnsZoneController::update()`/`adopt()`: sincroniza `dns_server_zone`, roda
`DnsZoneNameserverSynchronizer::synchronize()` (apex NS + glue vêm do perfil,
não do legado descoberto) e marca a zona como adotada. Sem os campos, o
comportamento continua idêntico ao de antes (só importa, zona fica
`bind_import`/rascunho).

Servidor/perfil são validados contra a organização antes de tocar em qualquer
zona (erro de validação não importa nada); a remoção do bloco legado e a
publicação continuam passos separados e deliberados, como já eram — o lote só
cobre até "adotada, pronta para publicar".

Testes: perfil+servidores aplicados corretamente (incluindo que o NS antigo do
BIND é substituído pelo do perfil), sem os campos continua só importando,
perfil de outra organização é rejeitado (nada é criado), falta de servidor
principal com perfil escolhido é rejeitada.

## Tags de git retroativas

`v1.3.44`–`v1.3.51` não tinham sido marcadas (código e `docs/RELEASE_*.md` já
existiam; a tag em si ficou faltando). Criadas apontando para o commit que
introduziu cada `docs/RELEASE_X.Y.Z.md`, sem alterar histórico.

Sem migration.

## Correção posterior (02/10/2026)

A primeira versão do `disk-guard.sh` removia imagens com `docker image prune -a
--filter until=168h`. Esse filtro usa a data de **criação** da imagem, então a imagem da
versão anterior — o alvo do `dns-center-deploy rollback` — seria apagada após 7 dias e o
rollback falharia (o painel não tem registry; `pull` não encontra a imagem). Corrigido: só
remove tags de release (`dns-center-app`/`dns-center-web`) que não sejam a versão atual
nem a anterior (lidas de `/var/lib/dns-center-deploy`) nem as 3 mais recentes
(`DNS_CENTER_KEEP_IMAGE_VERSIONS`); `docker rmi` sem `-f` recusa imagem em uso. O prune
de imagens "dangling" e do cache de build (>7 dias) continua. Os testes agora usam um
`docker` falso: antes, rodar a suíte no servidor executava o prune de verdade.
