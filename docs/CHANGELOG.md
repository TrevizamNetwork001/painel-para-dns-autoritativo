# Changelog

Histórico de mudanças por release, da mais nova para a mais antiga. Cada item
aponta para a nota completa da release em `docs/RELEASE_X.Y.Z.md`; o índice
geral continua na tabela de documentação do `README.md`. Gerado a partir dessa
tabela e das datas das tags do git — releases sem tag (anteriores à v1.3.29
em alguns casos) aparecem sem data.

## 1.3.57 — 2026-10-13

Adoção protegida de zonas importadas e formatos explícitos de PTR no assistente de DNS reverso. [Nota completa](RELEASE_1.3.57.md)

## 1.3.56 — 2026-10-13

Firewall Fase 2: política por servidor e validação local com `nft -c`, sem aplicar regras. [Nota completa](RELEASE_1.3.56.md)

## 1.3.55 — 2026-10-02

Auditoria: retenção dos eventos dos agentes, alerta de secundário sem a zona e backup que não falha em silêncio. [Nota completa](RELEASE_1.3.55.md)

## Sem release (02/10/2026) — correção em `deploy/disk-guard.sh`

A limpeza automática de disco da 1.3.53 usava `docker image prune -a --filter until=168h`,
que olha a data de **criação** da imagem: a imagem da versão anterior (alvo do
`dns-center-deploy rollback`) seria apagada depois de 7 dias, e o rollback falharia.
Agora só são removidas tags de release antigas que **não** sejam a versão atual, a
anterior nem as 3 mais recentes (`DNS_CENTER_KEEP_IMAGE_VERSIONS`). Os testes passaram
a usar um `docker` falso, para nunca tocar no Docker real do host. Detalhes em
[RELEASE_1.3.53.md](RELEASE_1.3.53.md).

## 1.3.54 — 2026-09-28

Agente 0.14.0: log do BIND no painel e forçar transferência de zona (destrava secundário atrasado). [Nota completa](RELEASE_1.3.54.md)

## 1.3.53 — 2026-09-28

Limpeza automática de disco (cron) e importar+configurar zonas em lote na descoberta. [Nota completa](RELEASE_1.3.53.md)

## 1.3.52 — 2026-09-28

Corrige DISC-001: falso conflito de descoberta por grafia de nome DNS (canonização única no modelo + comando de reconciliação). [Nota completa](RELEASE_1.3.52.md)

## 1.3.51 — 2026-09-20

Serial mismatch usa estado semântico e o rodapé acompanha automaticamente a release implantada. [Nota completa](RELEASE_1.3.51.md)

## 1.3.50 — 2026-09-20

Dashboard ignora servidores desativados e usa indicadores semânticos de sucesso/alerta. [Nota completa](RELEASE_1.3.50.md)

## 1.3.49 — 2026-09-20

Agent 0.13.0 observa a tabela de firewall gerenciada em modo somente leitura. [Nota completa](RELEASE_1.3.49.md)

## 1.3.48 — 2026-09-18

Bloqueio de agentes órfãos na API e no firewall, visível no painel. [Nota completa](RELEASE_1.3.48.md)

## 1.3.47 — 2026-09-18

Exclusão integral do tenant, inclusive auditorias vinculadas. [Nota completa](RELEASE_1.3.47.md)

## 1.3.46 — 2026-09-18

Confirma upgrade pelo próximo poll autenticado do binário novo. [Nota completa](RELEASE_1.3.46.md)

## 1.3.45 — 2026-09-18

Hotfix: limite da API por agente; evita HTTP 429 compartilhado atrás do Docker. [Nota completa](RELEASE_1.3.45.md)

## 1.3.44 — 2026-09-18

Agente 0.10.2 e fluxos de upgrade, publicação e restauração mais robustos. [Nota completa](RELEASE_1.3.44.md)

## 1.3.43 — 2026-09-18

Configurações → Backup: credenciais do R2 pelo painel, criptografadas, com teste de conexão. [Nota completa](RELEASE_1.3.43.md)

## 1.3.42 — 2026-09-18

Publicar e sincronizar: o secundário só aplica depois do primário. [Nota completa](RELEASE_1.3.42.md)

## 1.3.41 — 2026-09-18

Alerta de NS repetido no apex e mensagem específica ao publicar. [Nota completa](RELEASE_1.3.41.md)

## 1.3.40 — 2026-09-18

Publicação sem alterações explica o motivo e leva à aba Configuração. [Nota completa](RELEASE_1.3.40.md)

## 1.3.39 — 2026-09-18

Agente 0.10.1: secundário espera a transferência antes de confirmar o serial. [Nota completa](RELEASE_1.3.39.md)

## 1.3.38 — 2026-09-18

Botão combinado remover+publicar espera o relatório de prontidão. [Nota completa](RELEASE_1.3.38.md)

## 1.3.37 — 2026-09-18

Sincronizador de nameservers não duplica mais os NS do apex. [Nota completa](RELEASE_1.3.37.md)

## 1.3.36 — 2026-09-18

Modal de remoção espera o relatório de prontidão antes de recarregar. [Nota completa](RELEASE_1.3.36.md)

## 1.3.35 — 2026-09-18

Descoberta com mais de 24h destacada como desatualizada. [Nota completa](RELEASE_1.3.35.md)

## 1.3.34 — 2026-09-18

Comparação só entre servidores relacionados. [Nota completa](RELEASE_1.3.34.md)

## 1.3.33 — 2026-09-18

Descoberta: comparação entre servidores, zonas ignoradas, modal com CIDR e barra de progresso. [Nota completa](RELEASE_1.3.33.md)

## 1.3.32 — 2026-09-17

"Remover declarações antigas e publicar" num só passo. [Nota completa](RELEASE_1.3.32.md)

## 1.3.31 — 2026-09-17

Desativar e excluir empresa (cancelamento de cliente), com cascata e confirmação forte. [Nota completa](RELEASE_1.3.31.md)

## 1.3.30 — 2026-09-17

Página "Reversos": zonas reversas separadas de Domínios, com bloco CIDR. [Nota completa](RELEASE_1.3.30.md)

## 1.3.29 — 2026-09-17

Hotfix: sanitizeResult() não descarta mais o resultado de remove_legacy_zone_block. [Nota completa](RELEASE_1.3.29.md)

## 1.3.28 — 2026-09-17

Agente 0.10.0 remove bloco de zona legado com 1 clique, sem SSH (Fase 3 — fecha o plano). [Nota completa](RELEASE_1.3.28.md)

## 1.3.27 — 2026-09-17

Cartão "Conflito de configuração" no painel e bloqueio de publicação (Fase 2). [Nota completa](RELEASE_1.3.27.md)

## 1.3.26 — 2026-09-17

Agente 0.9.0 detecta blocos de zona legados fora do include gerenciado (Fase 1). [Nota completa](RELEASE_1.3.26.md)

## 1.3.25 — 2026-09-16

Hotfix: sync_zones não descarta mais diagnostics/rolled_back de uma falha de apply. [Nota completa](RELEASE_1.3.25.md)

## 1.3.24 — 2026-09-16

Agente 0.8.0 reporta o erro real de named-checkconf/checkzone/rndc, sem mais vir vazio. [Nota completa](RELEASE_1.3.24.md)

## 1.3.23 — 2026-09-16

Comandos do agente funcionam sem sudo instalado. [Nota completa](RELEASE_1.3.23.md)

## 1.3.22 — 2026-09-16

Template de nome pro PTR gerado a partir de registros A. [Nota completa](RELEASE_1.3.22.md)

## 1.3.21 — 2026-09-16

Tela de Auditoria global por organização, com filtros e exportação CSV. [Nota completa](RELEASE_1.3.21.md)

## 1.3.20 — 2026-09-16

Histórico de versões mostra qual registro mudou, não só "Registro atualizado.". [Nota completa](RELEASE_1.3.20.md)

## 1.3.19 — 2026-09-16

Botão "Publicar e sincronizar" reduz publicação+aplicação a 1 clique. [Nota completa](RELEASE_1.3.19.md)

## 1.3.18 — 2026-09-16

TTL padrão de zonas novas reduzido de 3600s para 300s. [Nota completa](RELEASE_1.3.18.md)

## 1.3.17 — 2026-09-16

Modal de atualização do agente fecha sozinho e volta pra dashboard ao concluir. [Nota completa](RELEASE_1.3.17.md)

## 1.3.16 — 2026-09-16

Agente 0.7.9 atualiza a observação autoritativa logo após aplicar zonas. [Nota completa](RELEASE_1.3.16.md)

## 1.3.15 — 2026-09-16

Agente 0.7.8 habilita DNS_CENTER_AGENT_ALLOW_APPLY por padrão. [Nota completa](RELEASE_1.3.15.md)

## 1.3.14 — 2026-09-16

Aceita IP curto ao editar/criar registro PTR, remove prefixo "bloco". [Nota completa](RELEASE_1.3.14.md)

## 1.3.13 — 2026-09-16

Bloco CIDR na lista geral de domínios, no título da zona e PTR sem nome truncado. [Nota completa](RELEASE_1.3.13.md)

## 1.3.12 — 2026-09-16

Registros PTR mostram o IP calculado em vez do nome reverso de 32 nibbles. [Nota completa](RELEASE_1.3.12.md)

## 1.3.11 — 2026-09-16

Mostra o bloco CIDR das zonas reversas na forma cadastrada. [Nota completa](RELEASE_1.3.11.md)

## 1.3.10 — 2026-09-16

Corrige layout do modal de criar zona reversa. [Nota completa](RELEASE_1.3.10.md)

## 1.3.9 — 2026-09-16

Assistente de DNS reverso IPv4/IPv6. [Nota completa](RELEASE_1.3.9.md)

## 1.3.8 — 2026-09-16

Continuação do fix de fonte da aba Nameservers (Identidades e Perfis). [Nota completa](RELEASE_1.3.8.md)

## 1.3.7 — 2026-09-16

Fix de CSS na aba Nameservers e de teste que só passava como root. [Nota completa](RELEASE_1.3.7.md)

## 1.3.6

Agente 0.7.7 com correções de robustez operacional. [Nota completa](RELEASE_1.3.6.md)

## 1.3.5

Trava de concorrência na autorização de aplicação, CI e ajuste de fila. [Nota completa](RELEASE_1.3.5.md)

## 1.3.4 — 2026-09-08

Botão "Aplicar agora" aplica zonas pendentes no BIND sem SSH manual. [Nota completa](RELEASE_1.3.4.md)

## 1.3.3 — 2026-09-08

Checagem de prontidão detecta BIND não vinculado ao include gerenciado antes de publicar. [Nota completa](RELEASE_1.3.3.md)

## 1.0.0 — 2026-08-31

Promoção da v1.0.0 a partir da RC3 e correção da tag irregular. [Nota completa](RELEASE_1.0.0.md)

## Marcos anteriores (release candidates e documentos de fase)

- [Release candidate 2](RELEASE_CANDIDATE_2.md) — Escopo congelado, gates, homologações e limitações da RC2
- [Release candidate 3](RELEASE_CANDIDATE_3.md) — Correção de idempotência do DISCOVER encontrada no gate da RC2
