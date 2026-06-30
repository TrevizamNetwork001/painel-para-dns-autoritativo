# Inventario sanitizado de /home/cnetwork - 2026-06-29

## Escopo

Inventario read-only de `/home/cnetwork` como fonte historica privada do painel DNS/Bind.

Regras aplicadas:

- Nenhum arquivo sensivel foi copiado para o repositorio.
- Nenhum banco SQLite foi aberto para dump de dados.
- Nenhum segredo foi exposto neste relatorio.
- Nenhum arquivo foi apagado.
- Nenhum reload/restart do Bind foi executado.
- A comparacao de PHPs antigos foi limitada a nomes, tamanhos e datas.

## Diretorios principais

| Caminho | Permissao | Owner | Modificacao | Classificacao |
|---|---:|---|---|---|
| `/home/cnetwork` | 700 | cnetwork:cnetwork | 2026-06-29 08:55 | home privado |
| `/home/cnetwork/backups` | 750 | cnetwork:cnetwork | 2026-06-15 14:33 | backups operacionais |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415` | 750 | cnetwork:cnetwork | 2026-06-15 14:33 | snapshot operacional privado |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel` | 775 | www-data:www-data | 2026-06-15 14:33 | copia antiga do painel |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/master-aut` | 775 | bind:www-data | 2026-06-13 15:05 | zonas forward reais |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/master-rev` | 775 | bind:bind | 2026-06-13 15:07 | zonas reversas reais |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.d` | 775 | root:root | 2026-05-19 16:56 | regras firewall antigas |
| `/home/cnetwork/backup-auditoria-v1-20260613-1725` | 775 | root:root | 2026-06-13 17:21 | backup historico de auditoria |
| `/home/cnetwork/backup-usuarios-20260613-1735` | 775 | root:root | 2026-06-13 17:32 | backup historico de usuarios |
| `/home/cnetwork/erros` | 775 | cnetwork:cnetwork | 2026-06-22 19:49 | capturas de validacao/erros |

## Backups compactados

| Caminho | Tamanho | Modificacao | Tipo provavel | Risco |
|---|---:|---|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415.tar.gz` | 92180 bytes | 2026-06-15 14:33 | snapshot compactado do painel/BIND/firewall | Pode conter banco real, zonas reais, configs Bind e sudoers. Manter somente como backup privado. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415.tar.gz.sha256` | 126 bytes | 2026-06-15 14:33 | checksum do tarball | Baixo risco isolado, mas referencia backup privado. |

## Bancos SQLite encontrados

Registrar apenas metadados. Nao abrir como dump de dados.

| Caminho | Tamanho | Modificacao | Tipo provavel | Motivo do risco |
|---|---:|---|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/db/painel_dns.sqlite` | 36864 bytes | 2026-06-15 14:17 | banco SQLite real do painel antigo | Pode conter usuarios, hashes, auditoria e dados operacionais. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/db/painel_dns.sqlite.bak-20260615-110015` | 36864 bytes | 2026-06-15 11:00 | backup SQLite real | Mesmo risco do banco principal. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel_dns.sqlite` | 36864 bytes | 2026-06-15 14:24 | copia de banco SQLite real | Pode conter dados reais e historico de usuarios. |
| `/home/cnetwork/backup-usuarios-20260613-1735/painel_dns.sqlite` | 24576 bytes | 2026-06-13 17:32 | banco SQLite real de backup de usuarios | Pode conter usuarios, hashes e auditoria. |

## Configs Bind encontradas

| Caminho | Tamanho | Modificacao | Tipo provavel | Motivo do risco |
|---|---:|---|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/named.conf.local` | 1836 bytes | 2026-06-13 15:03 | configuracao Bind local antiga | Pode revelar zonas, paths e layout operacional. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/named.conf` | 463 bytes | 2026-05-02 15:55 | configuracao principal Bind antiga | Pode revelar includes/layout. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/named.conf.options` | 371 bytes | 2026-05-02 15:55 | opcoes Bind antigas | Pode revelar politica DNS local. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/master-aut/*.hosts` | varios | 2026-05-19 a 2026-06-13 | zonas forward reais | Contem registros DNS reais. Nunca versionar publico. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/master-rev/*.rev` | varios | 2026-05-02 a 2026-06-13 | zonas reversas reais | Contem mapeamentos reversos reais. Nunca versionar publico. |

Zonas forward reais presentes no snapshot:

- `zebratelecom.net.br.hosts`
- `batatatelecom.com.br.hosts`
- `gato.net.br.hosts`
- `tiringa.com.br.hosts`
- `conectanetwork.net.br.hosts`
- `teste-template.com.br.hosts`
- `pizza.com.br.hosts`

Zonas reversas/rev6 reais presentes no snapshot:

- `45.162.199.rev`
- `198.50.0.rev`
- `10.182.97.rev`
- `45.182.97.rev`
- `45.162.196.rev`
- `192.168.11.rev`
- `45.162.197.rev`
- `12.183.96.rev`
- `198.50.3.rev`
- `198.50.2.rev`
- `tiringa.com.br.rev6`
- `pizza.com.br.rev6`
- `10.60.0.rev`
- `192.168.10.rev`
- `2804.52bc.rev`
- `45.182.96.rev`
- `12.183.97.rev`
- `45.162.198.rev`
- `198.50.1.rev`
- `10.182.96.rev`
- `gato.net.br.rev6`

## Sudoers encontrados

| Caminho | Tamanho | Modificacao | Tipo provavel | Motivo do risco |
|---|---:|---|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/sudoers-rndc` | 51 bytes | 2026-05-02 16:27 | regra sudo antiga para rndc | Pode revelar comandos privilegiados permitidos. Nao publicar. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/sudoers-painel-dns.pre-v1` | 615 bytes | 2026-06-13 17:01 | sudoers antigo do painel | Pode revelar politica operacional privilegiada. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/sudoers-painel-dns` | 751 bytes | 2026-06-15 14:28 | sudoers antigo do painel | Deve permanecer privado e ser revisado antes de qualquer reaproveitamento. |

## nftables/firewall

| Caminho | Tamanho | Modificacao | Tipo provavel | Motivo do risco |
|---|---:|---|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.conf` | 1676 bytes | 2026-05-18 08:56 | configuracao nftables antiga | Pode revelar politica de firewall real. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.d/acl4.conf` | 110 bytes | 2026-05-19 16:57 | ACL IPv4 antiga | Pode revelar redes/IPs administrativos. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.d/acl6.conf` | 119 bytes | 2026-05-18 10:26 | ACL IPv6 antiga | Pode revelar redes/IPs administrativos. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.d/ports-admin.conf` | 71 bytes | 2026-05-18 08:30 | portas administrativas antigas | Pode revelar superficie administrativa. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/nftables.d/ports-public.conf` | 33 bytes | 2026-05-18 08:31 | portas publicas antigas | Pode revelar superficie exposta. |

## Handoffs e documentacao

Listagem por nomes e inferencia de tema. Conteudo nao foi transcrito neste relatorio.

| Arquivo | Tamanho | Modificacao | Resumo sanitizado |
|---|---:|---|---|
| `/home/cnetwork/HANDOFF_SERVICES_UI_2026-06-21.md` | 4293 bytes | 2026-06-21 09:38 | Handoff de UI da tela de servicos. Pode virar documentacao sanitizada apos revisao. |
| `/home/cnetwork/HANDOFF_PAINEL_DNS_COMPLETO_2026-06-13.md` | 4301 bytes | 2026-06-13 17:33 | Handoff historico do painel DNS completo. Revisar antes de publicar. |
| `/home/cnetwork/handoff_ux_painel_dns.txt` | 7442 bytes | 2026-06-15 12:08 | Anotacoes de UX do painel DNS. Candidato a documentacao sanitizada. |
| `/home/cnetwork/handoff_v2c.txt` | 5002 bytes | 2026-06-13 17:10 | Handoff historico V2C. Revisar por dados operacionais. |
| `/home/cnetwork/CHANGELOG.md` | 6499 bytes | 2026-06-21 09:38 | Changelog historico fora do repo atual. Pode ser comparado com `CHANGELOG.md` atual. |
| `/home/cnetwork/melhorias  na interface  dashbord principal.txt` | 11002 bytes | 2026-06-15 12:30 | Ideias/melhorias de interface do dashboard. Candidato a docs sanitizadas. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_PRODUCAO_V1.txt` | 5071 bytes | 2026-06-15 14:32 | Handoff de producao V1 dentro do snapshot. Manter privado ate revisao. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_V2_DASH.txt` | 2518 bytes | 2026-06-15 13:48 | Handoff dashboard V2. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_V3_DASH.txt` | 3336 bytes | 2026-06-15 13:57 | Handoff dashboard V3. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_AUDITORIA_E_SERVICOS_2026-06-13.md` | 9178 bytes | 2026-06-13 14:57 | Auditoria/servicos historicos. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_IMPLEMENTACAO_DASHBOARD.txt` | 9549 bytes | 2026-06-15 13:05 | Implementacao historica de dashboard. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/HANDOFF_QUESTIONAMENTOS_DASHBOARD.txt` | 10848 bytes | 2026-06-15 12:40 | Questionamentos de dashboard. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/handoff_respostas_dashbord.txt` | 7873 bytes | 2026-06-15 12:48 | Respostas de dashboard. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/dashbord_principal.txt` | 10497 bytes | 2026-06-15 12:34 | Descricao historica do dashboard principal. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/RELEASE_V1.txt` | 543 bytes | 2026-06-15 14:33 | Nota de release V1. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/BACKUP_INFO.txt` | 76 bytes | 2026-06-15 14:24 | Metadados do backup privado. |

## Copias antigas de PHP

Comparacao limitada a nomes, datas e tamanhos. Nenhum arquivo PHP antigo foi copiado ou aplicado.

### Snapshot `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel`

| Arquivo | Antigo: tamanho/data | Atual no projeto: tamanho/data | Observacao |
|---|---|---|---|
| `services.php` | 13721 bytes, 2026-06-15 12:18 | 33884 bytes, 2026-06-21 09:36 | Atual muito mais novo; backup pode servir para diff historico. |
| `dashboard.php` | 16294 bytes, 2026-06-15 14:01 | 31207 bytes, 2026-06-21 08:40 | Atual mais novo. |
| `domains.php` | 18715 bytes, 2026-06-13 14:45 | 49150 bytes, 2026-06-22 10:29 | Atual mais novo. |
| `zones.php` | 1899 bytes, 2026-06-12 19:41 | 61170 bytes, 2026-06-23 00:09 | Atual substitui amplamente a versao antiga. |
| `reverse-zones.php` | 3188 bytes, 2026-06-12 19:41 | 25711 bytes, 2026-06-21 08:48 | Atual mais novo. |
| `auditoria.php` | 12603 bytes, 2026-06-13 17:32 | 12850 bytes, 2026-06-20 20:51 | Atual mais novo, tamanho proximo. |
| `login.php` | 5426 bytes, 2026-06-13 17:32 | 5426 bytes, 2026-06-20 20:51 | Mesmo tamanho, atual com data mais nova. |
| `logout.php` | 439 bytes, 2026-06-13 14:44 | 439 bytes, 2026-06-13 14:44 | Mesmo tamanho/data. |
| `index.php` | 94 bytes, 2026-06-12 19:37 | 94 bytes, 2026-06-12 19:37 | Mesmo tamanho/data. |
| `config.php` | 358 bytes, 2026-06-13 17:32 | 358 bytes, 2026-06-13 17:32 | Mesmo tamanho/data. |
| `usuarios.php` | 12546 bytes, 2026-06-13 17:32 | 32059 bytes, 2026-06-21 08:52 | Atual mais novo. |
| `firewall.php` | 1957 bytes, 2026-06-13 17:32 | 205288 bytes, 2026-06-22 23:29 | Atual muito mais novo. |
| `includes/auth.php` | 1910 bytes, 2026-06-13 17:32 | 2948 bytes, 2026-06-21 11:08 | Atual mais novo. |
| `includes/db.php` | 211 bytes, 2026-06-12 16:36 | 596 bytes, 2026-06-21 11:07 | Atual mais novo. |
| `includes/users.php` | 2629 bytes, 2026-06-13 17:32 | 2167 bytes, 2026-06-21 11:06 | Atual mais novo; possivel reducao/refatoracao. |

Arquivos atuais sem equivalente visto nesse snapshot:

- `dns-servers.php`
- `dns-zones.php`
- `servidores-dns.php`
- `includes/dns_servers.php`
- `includes/dns_zones.php`

### Backups pontuais

| Origem | Conteudo PHP | Observacao |
|---|---|---|
| `/home/cnetwork/backup-auditoria-v1-20260613-1725` | `auditoria.php`, `edit-zone.php`, `edit-ptr.php`, `delete-ptr.php`, `edit-reverse-zone.php`, `includes/audit.php` | Fonte historica para comparar fluxo de auditoria/edicao, sem substituir automaticamente. |
| `/home/cnetwork/backup-usuarios-20260613-1735` | `auditoria.php`, `login.php`, `dashboard.php`, `firewall.php`, `security.php`, `acl6.php`, `config.php`, `includes/setup_db.php`, `includes/auth.php`, outros | Fonte historica ligada a usuarios/autenticacao; contem tambem banco SQLite real e deve permanecer privada. |
| `/home/cnetwork/services.php` | `services.php`, 33884 bytes, 2026-06-21 09:35 | Mesmo tamanho pratico do `services.php` atual, data muito proxima; pode ser comparado por diff em fase futura. |
| `/home/cnetwork/services.php.pre-layout-20260621` | arquivo historico de services, 23232 bytes | Backup antes de layout; comparar apenas se necessario. |
| `/home/cnetwork/services.php.antes-ux-20260615` | arquivo historico de services, 13721 bytes | Versao antiga antes de UX. |
| `/home/cnetwork/services.php.ux-demo` | arquivo historico de services, 13721 bytes | Demo UX antiga. |

## Scripts uteis

| Caminho | Tamanho | Modificacao | Observacao |
|---|---:|---|---|
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/scripts/install-dns-painel.sh` | 1863 bytes | 2026-05-02 16:27 | Script historico de instalacao. Revisar por sudo/rndc antes de qualquer reuso. |
| `/home/cnetwork/backups/painel_dns_v1_20260615_142415/painel/scripts/add-domain-full.sh` | 3617 bytes | 2026-06-09 18:29 | Script historico de criacao de dominio/zona. Comparar apenas em ambiente controlado. |
| `/home/cnetwork/teste3.sh` | 5524 bytes | 2026-05-03 19:32 | Script avulso de teste. Conteudo nao revisado nesta fase; manter privado ate classificacao. |

## Outros arquivos privados

| Caminho | Tamanho | Modificacao | Motivo para cuidado |
|---|---:|---|---|
| `/home/cnetwork/.bash_history` | 71 bytes | 2026-06-07 02:05 | Historico de shell pode conter comandos sensiveis. Nunca versionar. |
| `/home/cnetwork/.Xauthority` | 147 bytes | 2026-06-29 08:55 | Arquivo de autenticacao X11. Nunca versionar. |
| `/home/cnetwork/erros/*.png` | 31593 e 59476 bytes | 2026-06-22 | Capturas podem conter dados operacionais visuais. Revisar antes de publicar. |

## O que pode virar documentacao sanitizada

- Handoffs de UI e dashboard, apos revisao manual para remover IPs, dominios reais, usuarios, paths internos e comandos sensiveis.
- Changelog historico, se comparado com o `CHANGELOG.md` atual e higienizado.
- Notas de UX e melhorias de interface, desde que sem dados operacionais.
- `BACKUP_INFO.txt` somente se nao contiver caminhos/identificadores sensiveis alem de metadados gerais.

## O que deve permanecer somente como backup privado

- Tarball `/home/cnetwork/backups/painel_dns_v1_20260615_142415.tar.gz`.
- Bancos SQLite reais.
- Configs Bind reais.
- Zonas forward/reversas reais.
- Sudoers antigos.
- Regras nftables/firewall reais.
- Capturas de tela em `/home/cnetwork/erros` ate revisao visual.
- Dotfiles privados como `.bash_history` e `.Xauthority`.

## O que pode ser comparado no futuro

- `services.php` de `/home/cnetwork` contra `/var/www/html/painel/services.php`.
- Backups de `auditoria.php` e `includes/audit.php` contra versoes atuais.
- Backups de `usuarios.php`, `login.php`, `includes/auth.php` e `includes/users.php` para entender evolucao de autenticacao.
- Scripts historicos `install-dns-painel.sh` e `add-domain-full.sh` contra scripts atuais, apenas para aprendizado e sem executar.
- Handoffs antigos contra docs atuais para consolidar documentacao sanitizada.

## O que nunca deve entrar no Git

- `*.sqlite`
- `*.sqlite.*`
- `*.secret`
- Tarballs de backup com dados reais.
- Dumps de banco.
- Zonas Bind reais.
- `named.conf*` real sem sanitizacao.
- `sudoers*` real.
- `nftables.conf` e `nftables.d/*` reais.
- `.env`, chaves privadas, tokens, secrets e dotfiles privados.
- Capturas de tela que mostrem dados de producao.

## Validacao de copia

Durante esta fase, nenhum arquivo de `/home/cnetwork` foi copiado para dentro do repositorio. Apenas este relatorio sanitizado foi criado manualmente em `docs/handoff/`.
