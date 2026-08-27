# RC-HOMOLOGATION-1

Data: 2026-08-27
Classificação: **RC APROVADA COM RESSALVAS**

## Baseline homologado

- Branch: `main`.
- Código exato: `84ec458ff2b9cd4d19fc212e6569e84eac59bd54`.
- O worktree foi confirmado limpo antes do início das ações operacionais.
- A alteração não commitada encontrada inicialmente em
  `resources/views/components/account-menu.blade.php` foi removida antes da
  homologação, restaurando exatamente o conteúdo do baseline.
- Não houve correção nem alteração de código durante a homologação.
- Nenhuma tag foi criada e nenhuma imagem foi publicada externamente.

As evidências do `RC-PREFLIGHT-RECONCILE-1` foram aceitas para este mesmo HEAD:
Laravel com 145 testes e 802 assertions; Python com 46 testes; `py_compile`,
shell syntax, Pint no escopo, PHP lint, `view:cache`, 96 rotas,
`git diff --check`, inspeção de segredos e ausência de `__pycache__` versionado.
Como nenhum código foi corrigido nesta fase, as suítes completas não foram
repetidas.

## Ambiente descartável

- Host: Debian 13, Docker Engine 29.7.2, Compose 5.5.0, `overlayfs`, cgroup v2.
- Painel: imagens locais `dns-center-app:rc-homologation-1-a` e
  `dns-center-web:rc-homologation-1-a`.
- PostgreSQL: 17 Alpine, volume novo.
- Redis: 8 Alpine, volume novo.
- Nós autoritativos: dois containers Debian 13 com systemd, em rede bridge
  privada e sem dados ou endereços de clientes.
- Zona reservada: `rc-homologation.invalid`.
- Primary: `rc-primary.invalid`, endereço interno `172.20.0.8`.
- Secondary: `rc-secondary.invalid`, endereço interno `172.20.0.9`.
- Nenhum deploy em produção, delegação pública, failover ativo ou promoção de
  secondary foi realizado.

## Resultado por requisito

| # | Requisito | Resultado e evidência |
|---:|---|---|
| 1 | Build das imagens RC | **COMPROVADO**. Imagens locais versionadas, sem push. |
| 2 | Inspeção das imagens | **COMPROVADO**. App `sha256:57e531...`, web `sha256:0eb77a...`, labels corretas, app em `www-data`, assets app/web idênticos. |
| 3 | Docker descartável limpo | **COMPROVADO**. Projeto, rede e volumes novos vinculados ao baseline `84ec458`. |
| 4 | PostgreSQL e Redis novos | **COMPROVADO**. Ambos iniciados vazios e saudáveis. |
| 5 | Migrations de banco vazio | **COMPROVADO**. 28 migrations executadas no batch inicial. |
| 6 | Primeira organização/admin | **COMPROVADO**. Organização A e admin inicial criados pelo comando formal. |
| 7 | Login e segurança | **COMPROVADO**. Login HTTPS, desafio TOTP real, 2FA administrativo e headers CSP/HSTS/anti-framing/anti-sniffing. `security-check` retornou somente warning de proxy vazio, correto no laboratório sem proxy. |
| 8 | Isolamento multi-tenant | **COMPROVADO**. Viewer do tenant B viu somente seu servidor; acessos cruzados retornaram 404. Operação administrativa do viewer retornou 403. O admin do tenant A também recebeu 404 no recurso do tenant B. |
| 9 | Dois nós descartáveis | **COMPROVADO**. Primary e secondary independentes com systemd. |
| 10 | Onboarding real | **COMPROVADO**. Duas solicitações, aprovação web com 2FA e entrega única dos tokens; `claimed_at` preenchido e cópia do token removida no painel. |
| 11 | Instalação/configuração BIND | **COMPROVADO**. Operações `install_bind` separadas em `planned`, `authorized` e `succeeded`; BIND 9.20.26 instalado pela operação allowlisted. |
| 12 | Primary/secondary | **COMPROVADO**. `rndc zonestatus` confirmou papéis distintos. |
| 13 | Nameservers | **COMPROVADO**. Perfil ordenado com `ns1` e `ns2`, registros NS e glue A automáticos. |
| 14 | TSIG | **COMPROVADO**. HMAC-SHA256 gerado pelo painel, cifrado em repouso e aplicado somente nos dois nós. |
| 15 | Criação de zona | **COMPROVADO**. Zona e registros SOA, NS, A, AAAA, CNAME, MX e TXT criados pela interface. |
| 16 | Salvar sem publicar | **COMPROVADO**. Zona ficou `draft`/`ready`, zero novas publicações e BIND permaneceu no serial anterior. |
| 17 | Publicação explícita | **COMPROVADO**. POST separado criou snapshot e destinos pendentes. Uma primeira tentativa foi bloqueada enquanto o heartbeat ainda não havia marcado o servidor online. |
| 18 | Aplicação no primary | **COMPROVADO**. Sync padrão fez somente dry-run; apply exigiu variável local e frase forte. Arquivo atômico, `named-checkconf`, reload e serial foram confirmados. |
| 19 | NOTIFY | **COMPROVADO**. Primary enviou NOTIFY autenticado e secondary registrou `TSIG 'rc-xfr-key'`. |
| 20 | AXFR para secondary | **COMPROVADO**. AXFR inicial e fallback AXFR na atualização, autenticados por TSIG. IXFR incremental não é alegado. |
| 21 | DNS UDP/TCP | **COMPROVADO**. SOA, NS, A, AAAA, CNAME, MX, TXT e NXDOMAIN em ambos os nós; A e SOA também por TCP. |
| 22 | AA e ausência de RA | **COMPROVADO**. Respostas autoritativas tiveram `aa`; nenhuma teve `ra`. Sonda recursiva retornou `REFUSED`. |
| 23 | Atualização/convergência | **COMPROVADO**. Seriais `2026082707`, `2026082709` e final `2026082711`; secondary convergiu após NOTIFY/transferência. |
| 24 | Observabilidade | **COMPROVADO COM RESSALVA**. Painel persistiu `synchronized`, seriais esperados/observados, papel e transferência `succeeded`. A transição específica `primary_unreachable` não foi conclusivamente persistida durante a janela curta da queda; **NÃO COMPROVADO** para esse subestado. |
| 25 | Queda do primary | **COMPROVADO**. Somente `named` do primary foi parado; secondary continuou UDP/TCP com `aa` e serial vigente. Não houve promoção. |
| 26 | Continuidade do secondary | **COMPROVADO** dentro da validade SOA. Expiração completa não foi aguardada. |
| 27 | Falha TSIG/recuperação | **COMPROVADO**. TSIG incorreta gerou `BADSIG`, bloqueou refresh e preservou hash/serial anterior; restauração seguida de `rndc refresh` convergiu para `2026082711`. |
| 28 | Falha de configuração/publicação | **COMPROVADO**. Zonefile e include inválidos foram rejeitados em staging por `named-checkzone` e `named-checkconf`; hashes ativos, serviço, serial e resposta DNS não mudaram. |
| 29 | Backup | **COMPROVADO**. Dump custom de 134.969 bytes, catálogo válido e SHA-256 `0858b32b...`. O update também criou backup obrigatório. |
| 30 | Restore separado | **COMPROVADO**. Restore em PostgreSQL 17 e volume separados: 28 migrations, 2 organizações, 2 usuários, 3 servidores, 1 zona, 2 agentes e 6 publicações. |
| 31 | Ensaio de upgrade | **COMPROVADO COM RESSALVA**. Tag local `b` do mesmo artefato exerceu backup, migrations, recriação e health checks. O worker reiniciou uma vez durante a troca e recuperou-se automaticamente; todos os serviços ficaram saudáveis e a versão foi registrada. |
| 32 | Rollback da aplicação | **COMPROVADO**. Retorno formal de `b` para `a`, sem rollback de migrations, com todos os health checks e HTTPS 200. |
| 33 | Restart completo | **COMPROVADO**. Painel, PostgreSQL, Redis e os dois nós reiniciados. |
| 34 | Health checks | **COMPROVADO**. Seis serviços Compose saudáveis, HTTPS 200, migrations OK, Redis `PONG`, ambos os `named` ativos e serial final idêntico. |
| 35 | Segredos | **COMPROVADO COM RESSALVA**. Nenhum segredo embutido nas imagens; `.env`, `.git`, `node_modules`, logs e Docker socket ausentes. Os únicos hits foram templates vazios. Uma inspeção diagnóstica exibiu credenciais exclusivamente descartáveis do laboratório na saída local; não eram credenciais reais e foram invalidadas pela destruição dos volumes/arquivos. |
| 36 | Limpeza integral | **COMPROVADO**. Zero containers, redes, volumes, imagens RC e paths temporários remanescentes. Backups e credenciais descartáveis foram removidos junto com o laboratório. |
| 37 | Documentação | **COMPROVADO** por este documento. |

## Evidências operacionais principais

### Imagens

- Código amostrado dentro da imagem app teve SHA-256 idêntico ao checkout para
  `artisan`, `composer.lock`, `User.php`, o menu de conta e o agente Python.
- O conjunto completo de assets teve o mesmo hash agregado nas imagens app e
  web: `d0c3086b3c65ad4c75713821940ceed91e78f861fc08958048dbd268dab4338d`.
- Os dois hits do detector de padrões foram `.env.example` e
  `deploy/app.env.example`, ambos templates sem valores secretos.

### BIND e DNS

- Versão: BIND `9.20.26-1~deb13u1-Debian`.
- Unit canônica: `named`, ativa e habilitada.
- Recursão: `recursion no`, `allow-recursion { none; }` e
  `allow-query-cache { none; }`.
- Primary: zonefile `root:bind 0640`.
- Secondary: cópia transferida criada pelo BIND como `bind:bind`.
- Configurações gerenciadas: `root:bind 0640`.
- Configuração do agente: `root:root 0600` em diretório `0750`.
- Serial final nos dois nós: `2026082711`.

### Backup, upgrade e rollback

- O primeiro restore falhou apenas por uma corrida com o servidor temporário
  usado pelo entrypoint durante `initdb`; nenhum dado foi aplicado. Após o
  PostgreSQL definitivo ficar pronto, o mesmo dump foi restaurado com sucesso.
- O upgrade usou outra tag local do mesmo conteúdo para ensaiar o procedimento
  sem introduzir código diferente do RC.
- O rollback alterou somente app, queue, scheduler e web; banco e migrations
  não foram revertidos.

## Ressalvas e itens não comprovados

1. O estado persistido de observabilidade `primary_unreachable` é
   **NÃO COMPROVADO** nesta execução. A queda e a
   continuidade DNS foram comprovadas, mas a janela foi curta para o ciclo de
   refresh/classificação do secondary.
2. A expiração completa do secondary após `SOA EXPIRE` é **NÃO COMPROVADA**;
   somente a continuidade dentro da validade foi exercitada.
3. IXFR incremental é **NÃO COMPROVADO**. O BIND registrou fallback para AXFR,
   que é o método afirmado nesta homologação.
4. Firewall externo, WAF, proxy reverso real, cofre, monitoramento externo e
   delegação pública são **NÃO COMPROVADOS** e permanecem controles do ambiente
   de destino.
5. O restart único do worker durante o upgrade recuperou-se automaticamente,
   mas deve ser observado no próximo ensaio de release.

## Decisão

**RC APROVADA COM RESSALVAS**

O fluxo crítico de instalação, segurança, isolamento, publicação autoritativa,
TSIG, NOTIFY, AXFR, continuidade, falhas seguras, backup/restore,
upgrade/rollback, restart e limpeza foi comprovado no código exato
`84ec458ff2b9cd4d19fc212e6569e84eac59bd54`.

Esta decisão não autoriza produção. Nenhuma tag foi criada durante a
homologação; a criação local de `v1.0.0-rc1` pertence ao fechamento posterior,
após revisão e aprovação explícita deste relatório.
