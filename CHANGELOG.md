# Changelog

Historico de alteracoes do Painel DNS Trevizam Network.

## [2.3.21] - 2026-06-22

### Fechamento do layout operacional do Firewall

- Ajustado layout visual do módulo Firewall para alinhar à referência
  operacional.
- Removida a prévia técnica de regras nftables da tela principal.
- Compactados cards, ações rápidas, tabelas e blocos de status.
- Corrigido alinhamento dos botões de ação nas tabelas.
- Preservada integralmente a lógica da V1.6.
- Handoff disponível em
  `HANDOFF_FECHAMENTO_LAYOUT_OPERACIONAL_FIREWALL_2026-06-22.md`.

## [2.3.20] - 2026-06-22

### Fechamento do ajuste visual do Firewall

- Ajustado o layout visual do módulo Firewall para alinhar à referência
  operacional.
- Removida a prévia técnica de regras nftables da tela principal.
- Compactados cards, ações rápidas, tabelas e blocos de status.
- Preservada integralmente a lógica da V1.6.
- Handoff final disponível em
  `HANDOFF_FECHAMENTO_VISUAL_FIREWALL_2026-06-22.md`.

## [2.3.19] - 2026-06-22

### Correção pontual dos botões cortados

- Substituídas larguras percentuais por medidas fixas na coluna Ações.
- Impedido o encolhimento dos botões Editar e Remover.
- Compactados padding, gap, fonte e ícones somente nas ações das tabelas.
- Ajustadas as colunas auxiliares para preservar espaço de Descrição.
- Nenhuma lógica ou estrutura geral foi alterada.
- Handoff disponível em
  `HANDOFF_CORRECAO_BOTOES_CORTADOS_FIREWALL_2026-06-22.md`.

## [2.3.18] - 2026-06-22

### Ajuste final de alinhamento das tabelas

- Redistribuídas as colunas das tabelas de portas para ampliar Ações.
- Compactados somente os botões Editar/Remover das tabelas de portas.
- Eliminados corte lateral e overflow horizontal desnecessário no desktop.
- Mantido truncamento da coluna Descrição.
- Alterado o cabeçalho `Data de criação` para `Criado em`.
- Nenhuma lógica ou operação do firewall foi alterada.
- Handoff disponível em
  `HANDOFF_AJUSTE_FINAL_TABELAS_FIREWALL_2026-06-22.md`.

## [2.3.17] - 2026-06-22

### Layout exclusivo do botão Backup

- Ajustado somente o botão Backup em Ações rápidas.
- Ícone fixado à esquerda e título/descrição posicionados à direita.
- Os demais atalhos não foram alterados.
- Handoff disponível em
  `HANDOFF_LAYOUT_BACKUP_FIREWALL_2026-06-22.md`.

## [2.3.16] - 2026-06-22

### Alinhamento do ícone Backup

- Corrigida a posição óptica do ícone Backup em Ações rápidas.
- Ajustados alinhamento vertical e dimensões do SVG em relação ao título e ao
  subtítulo do botão.
- Handoff disponível em
  `HANDOFF_ALINHAMENTO_ICONE_BACKUP_FIREWALL_2026-06-22.md`.

## [2.3.15] - 2026-06-22

### Ícones superiores fiéis à referência

- Redesenhados os cinco ícones dos cards de resumo com SVGs próprios.
- IPv4 e IPv6 agora possuem escudos internos diferentes como em `esse.png`.
- Cadeado, globo e escudo Firewall receberam geometria e detalhes equivalentes
  aos da referência.
- Ajustados tamanho, preenchimento translúcido e espaçamento dos ícones.
- Handoff disponível em
  `HANDOFF_ICONES_SUPERIORES_FIREWALL_2026-06-22.md`.

## [2.3.14] - 2026-06-22

### Permissão segura para validação nftables

- Adicionado wrapper restrito para executar somente `nft -c -f` em arquivos
  temporários de prévia criados pelo painel.
- O wrapper valida caminho, proprietário, permissões, tamanho e bloqueia
  diretivas externas antes da checagem.
- O painel passou a usar o wrapper somente na ação Validar.
- Adicionada regra `sudoers` específica para o usuário `www-data`.
- Nenhuma permissão genérica de aplicação de regras foi concedida.
- Handoff disponível em
  `HANDOFF_VALIDACAO_NFT_FIREWALL_2026-06-22.md`.

## [2.3.13] - 2026-06-22

### Portas Públicas, Validação e Auditoria alinhadas à referência

- Aplicado ao card Portas Públicas o mesmo padrão visual dos demais cards.
- Removidos o badge e o botão duplicado do cabeçalho.
- Ajustados tabela, protocolo roxo e ações Editar/Remover com ícones.
- Adicionado rodapé com total de portas e paginação visual.
- Auditoria Recente ajustada com avatares verdes, linhas compactas, horários
  alinhados e botão de histórico no padrão azul.
- Última Validação ajustada com painel de estado, data e tempo decorrido.
- Handoff disponível em
  `HANDOFF_PORTAS_PUBLICAS_FIREWALL_2026-06-22.md`.
- Handoff da auditoria disponível em
  `HANDOFF_AUDITORIA_RECENTE_FIREWALL_2026-06-22.md`.
- Handoff da validação disponível em
  `HANDOFF_ULTIMA_VALIDACAO_FIREWALL_2026-06-22.md`.

## [2.3.12] - 2026-06-22

### Portas Administrativas alinhadas à referência

- Aplicado ao card Portas Administrativas o mesmo padrão visual de Acesso
  Administrativo.
- Removidos o badge e o botão duplicado do cabeçalho.
- Ajustados tabela, protocolo e ações Editar/Remover com ícones.
- Adicionado rodapé com total de portas e paginação visual.
- Handoff disponível em
  `HANDOFF_PORTAS_ADMINISTRATIVAS_FIREWALL_2026-06-22.md`.

## [2.3.11] - 2026-06-22

### Acesso Administrativo alinhado à referência

- Ajustado o card Acesso Administrativo conforme a imagem `esse.png`.
- Mantidos título e subtítulo com busca compacta e ícone à direita.
- Removido o botão Adicionar IP do cabeçalho; a ação permanece nos atalhos.
- Tabela, badges e botões Editar/Remover receberam o estilo da referência.
- Adicionado rodapé com total de registros e paginação visual.
- Handoff disponível em
  `HANDOFF_ACESSO_ADMINISTRATIVO_FIREWALL_2026-06-22.md`.

## [2.3.10] - 2026-06-22

### Cor do card Firewall

- Mantido o ícone do Firewall na cor verde nos estados `Ativo` e `Inativo`.
- O estado operacional continua identificado pelo texto do card.
- Handoff de status atualizado.

## [2.3.9] - 2026-06-22

### Terminologia do status do Firewall

- Substituído o estado `Off` por `Inativo` no card Firewall.
- Ajustado o texto auxiliar para `Firewall inativo`.
- Handoff de status atualizado com a terminologia em português.

## [2.3.8] - 2026-06-22

### Status Ativo/Off no card Firewall

- Removida do card Firewall a informação sobre disponibilidade de backup.
- O card agora apresenta `Ativo` quando a última aplicação foi concluída e
  `Off` nos demais estados.
- Adicionadas descrições operacionais `Configuração em uso` e
  `Firewall desativado`.
- Handoff disponível em
  `HANDOFF_STATUS_CARD_FIREWALL_2026-06-22.md`.

## [2.3.7] - 2026-06-22

### Ícones dos cards de resumo do Firewall

- Adicionados ícones SVG aos cards IPv4 Liberados, IPv6 Liberados, Portas
  Admin, Portas Públicas e Firewall.
- Reproduzidas as cores e os fundos visuais da imagem `esse.png`.
- Preservadas as contagens, os estados e toda a lógica operacional existente.
- Handoff disponível em
  `HANDOFF_ICONES_CARDS_RESUMO_FIREWALL_2026-06-22.md`.

## [2.3.6] - 2026-06-22

### Ícones das ações rápidas do Firewall

- Substituídos caracteres e emojis por ícones SVG lineares equivalentes aos
  apresentados na imagem de referência `esse.png`.
- Aplicadas as cores visuais de cada ação: azul, laranja, roxo e verde.
- Ajustado o botão Validar para ocupar a mesma largura dos demais atalhos.
- Mantidas inalteradas as ações, os modais e a lógica do firewall.

## [2.3.5] - 2026-06-21

### Ajuste visual final do Firewall conforme `esse.png`

- Reorganizada a tela para reproduzir a hierarquia e as proporções da imagem
  de referência fornecida pelo operador.
- Sidebar simplificada com identificação do painel, Firewall destacado,
  usuário autenticado e versão no rodapé.
- Cabeçalho ajustado com retorno ao painel, título, subtítulo, breadcrumb e
  ação Atualizar.
- Mantidos cinco cards de resumo compactos e sete ações rápidas em linha.
- ACLs administrativas IPv4 e IPv6 consolidadas visualmente em um único card
  Acesso Administrativo, com busca única e badge de família.
- Eliminado o bloco vazio dedicado a IPv6 e mantido um único estado vazio.
- Portas Administrativas e Portas Públicas organizadas em cards equivalentes,
  com tabelas compactas e descrições truncadas quando necessário.
- Status de aplicação concentrado no card superior Firewall; aplicação e
  backup/rollback permanecem acessíveis pelas ações e modais existentes.
- Última Validação e Auditoria Recente reduzidas a cards laterais compactos.
- Limitada visualmente a auditoria às quatro entradas mais recentes.
- Prévia de regras nftables e faixa técnica de aplicação permanecem fora da
  tela principal.
- Mantida a responsividade para telas menores.
- Backend confirmado idêntico por hash; handlers POST, CRUD, SQLite, CSRF,
  validação, aplicação, backup, rollback, auditoria e comandos nft não foram
  alterados.
- Nenhuma regra real de firewall foi aplicada durante o ajuste.
- Documentação disponível em
  `HANDOFF_AJUSTE_VISUAL_FINAL_FIREWALL_ESSE_PNG.md`.

## [2.3.4] - 2026-06-21

### Limpeza visual da tela Firewall

- Removida da interface principal a seção Prévia das regras.
- Regras nftables deixaram de ocupar espaço visual na página.
- Mantida a validação na faixa Ações rápidas.
- Mantida a saída técnica recolhida nos cards de status.
- Aviso de aplicação real movido para uma nota compacta em Última Aplicação.
- Mantidos visíveis os bloqueios de segurança da aplicação.
- Aproximados os cards Última Aplicação, Última Validação e Auditoria Recente
  dos blocos operacionais.
- Backend confirmado idêntico por hash; geração, validação, aplicação, backup,
  rollback, auditoria e CSRF não foram alterados.
- Nenhuma regra real de firewall foi aplicada.
- Documentação disponível em `HANDOFF_LIMPEZA_VISUAL_PREVIA_FIREWALL.md`.

## [2.3.3] - 2026-06-21

### Alinhamento visual do Firewall ao mockup

- Adicionada sidebar fixa integrada ao padrão administrativo do painel.
- Reorganizado o conteúdo para priorizar Acesso Administrativo e portas.
- ACLs IPv4 e IPv6 agrupadas visualmente em um único bloco.
- Portas administrativas e públicas organizadas na coluna operacional.
- Última Aplicação, Última Validação e Auditoria Recente alinhadas em cards
  compactos.
- Rollback mantido como ação contextual da última aplicação.
- Adicionadas contagens discretas aos blocos de ACLs e portas.
- Preservados cards compactos, ações rápidas em linha e prévia recolhida.
- Adicionado menu lateral responsivo para dispositivos móveis.
- Backend confirmado idêntico por hash; nenhuma lógica da V1.6 foi alterada.
- Nenhuma regra real de firewall foi aplicada.
- Documentação disponível em `HANDOFF_ALINHAMENTO_MOCKUP_FIREWALL.md`.

## [2.3.2] - 2026-06-21

### Ajuste visual Firewall pós-V1.6

- Compactados os cards de resumo, cabeçalho e espaçamentos gerais.
- Ações rápidas convertidas visualmente em botões discretos.
- Prévia das regras recolhida por padrão em `details/summary`.
- Cards Última Aplicação e Última Validação reduzidos.
- Auditoria recente reorganizada em uma linha própria.
- Tabelas ajustadas para evitar scroll horizontal desnecessário no desktop.
- Reduzidos padding, tipografia, badges e botões das tabelas.
- Corrigida a mensagem duplicada no estado vazio da ACL IPv6.
- Preservados CRUD, validação, aplicação, rollback, auditoria, CSRF, SQLite e
  todas as travas de segurança.
- Nenhuma regra real de firewall foi aplicada.
- Documentação disponível em
  `HANDOFF_AJUSTE_VISUAL_FIREWALL_POS_V1_6.md`.

## [2.3.1] - 2026-06-21

### Fechamento FIREWALL_V1

- Consolidado o encerramento das fases V1.1, V1.2, V1.5 e V1.6.
- Registrados commits, decisões de segurança, limitações do ambiente, checklist
  de homologação real e plano operacional de rollback.
- Documentação final disponível em `HANDOFF_FINAL_FIREWALL_V1.txt`.
- Nenhuma alteração funcional foi realizada nesta revisão documental.

## [2.3.0] - 2026-06-21

### Firewall V1.6

- Adicionada aplicação real controlada da tabela `inet painel_firewall`.
- Implementadas confirmação exata `APLICAR FIREWALL` e confirmação visual
  adicional.
- Aplicação bloqueada sem ACL administrativa, sem porta administrativa ou com
  ACL aberta `/0`.
- Verificada a presença das ACLs e portas administrativas nas regras geradas.
- Tornada obrigatória a sequência `nft -c -f` antes de qualquer `nft -f`.
- Aplicação usa o mesmo arquivo temporário previamente validado.
- Adicionado backup persistente do estado anterior da tabela gerenciada em
  `firewall_meta`.
- Adicionada proteção contra alteração concorrente por comparação de hash.
- Implementado rollback manual com confirmação `REVERTER FIREWALL`.
- Rollback valida o backup antes de restaurar ou remover a tabela gerenciada.
- Nenhuma tabela nftables externa ao painel é removida ou restaurada.
- Adicionado card Última Aplicação com status, usuário, data, contagens,
  validação prévia, backup e saída técnica recolhida.
- Adicionados modais de aplicação e rollback e alertas claros de segurança.
- Implementada auditoria completa para solicitação, bloqueio, validação,
  backup, aplicação, rollback e CSRF inválido.
- Persistidos `ultima_aplicacao_v16`, `ultimo_backup_v16` e
  `ultima_reversao_v16`.
- Adicionada limpeza de temporários também no encerramento do PHP.
- Testados os fluxos de sucesso com executor isolado exclusivo para PHP CLI.
- No ambiente real, a falta de permissão netlink bloqueou a operação antes da
  aplicação, conforme esperado.
- Nenhum systemctl, reload, restart, `flush ruleset` ou substituição de arquivo
  produtivo foi implementado.
- Documentação detalhada disponível em `HANDOFF_FIREWALL_V1_6.md`.

## [2.2.0] - 2026-06-21

### Firewall V1.5

- Adicionada geração conservadora de prévia nftables a partir das ACLs IPv4,
  ACLs IPv6, portas administrativas e portas públicas cadastradas.
- Separadas regras administrativas por família com `ip saddr` e `ip6 saddr`.
- Adicionadas políticas seguras, loopback, conexões estabelecidas e
  ICMP/ICMPv6 à prévia.
- Bloqueadas ACLs administrativas abertas `0.0.0.0/0` e `::/0`.
- Adicionada ação POST com CSRF para validar a configuração.
- Implementada execução controlada somente com `nft -c -f` em arquivo
  temporário, timeout e captura de stdout/stderr.
- Adicionados tratamento de binário indisponível, falta de permissão, timeout e
  erro de sintaxe.
- Adicionadas remoção do arquivo temporário e sanitização de caminhos internos.
- Ativados o botão Validar, a área de prévia somente leitura e o card Última
  Validação com estados OK, ERRO e NÃO VALIDADO.
- Adicionada saída técnica recolhida sem exibir comandos ou caminhos internos.
- Persistido o último resultado na tabela existente `firewall_meta`, sem
  migração destrutiva.
- Adicionada auditoria de geração, validação, erros e CSRF inválido com
  quantidades de ACLs e portas.
- Preservados CRUD, SQLite existente, modais, toast e layout dark/NOC.
- Nenhuma regra real, reload, systemctl, arquivo produtivo ou rollback foi
  implementado.
- Documentação detalhada disponível em `HANDOFF_FIREWALL_V1_5.md`.

## [2.1.0] - 2026-06-21

### Página de serviços

- Redesenhada `services.php` conforme a referência visual aprovada.
- Adicionada barra lateral compacta, cards de resumo e grade responsiva dos
  serviços BIND9, Fail2Ban, SSH e Firewall.
- Padronizados ícones SVG, estados ativo/inativo, botões e efeitos de hover.
- Diferenciadas visualmente as ações Iniciar BIND, Reiniciar BIND, Parar BIND,
  Reiniciar Fail2Ban e Reiniciar SSH.
- Atualizada a tabela de últimas ações com nova tipografia, espaçamento,
  ícone de título e status OK/ERRO.
- Mantidas as confirmações para operações críticas.
- Preservadas autenticação, CSRF, comandos permitidos e auditoria.
- Documentação detalhada disponível em
  `HANDOFF_SERVICES_UI_2026-06-21.md`.

## [2.0.0] - 2026-06-15

### NS2 slave - Fase 1

- Gerado o diagnostico somente leitura `HANDOFF_NS2_DISCOVERY.txt` do NS2 existente.
- Confirmada replicacao parcial: `conectanetwork.net.br` sincronizada e zonas reversas legadas com falhas `NOTAUTH`/`REFUSED`.
- Documentadas divergencias de zonas, conectividade assimetrica, versoes e riscos operacionais do NS2.
- Criada a tabela SQLite `dns_servers` para cadastro de servidores DNS remotos.
- Criada a pagina administrativa `dns-servers.php`, restrita a administradores.
- Adicionados cadastro, alteracao, remocao, ativacao e consulta do ultimo status de NS2.
- Adicionados testes auditados de conexao SSH e status do BIND por comandos remotos fixos.
- Integrado o acesso Servidores DNS ao grupo Infraestrutura da dashboard.
- Adicionados rotulos de auditoria para cadastro, alteracao, remocao e teste de servidores DNS.
- Criados artefatos controlados para adicionar, remover, recarregar, consultar status e validar transferencia de zonas no NS2.
- Adicionado `forced command` SSH que limita a chave `dns-sync` aos testes permitidos na Fase 1.
- Documentada a preparacao de usuario `dns-sync`, sudoers restrito, chave SSH, `known_hosts`, `named.conf.slaves` e diretorio de zonas slaves.

### Seguranca e escopo

- SSH configurado com `BatchMode`, `IdentitiesOnly` e `StrictHostKeyChecking=yes`.
- Chave privada e `known_hosts` usam caminhos fixos e nao sao configuraveis pela interface.
- O painel nao aceita comandos SSH arbitrarios; somente scripts remotos predefinidos podem ser chamados.
- Scripts NS2 validam parametros, executam `named-checkconf` e revertem a configuracao quando o reload falha.
- Nenhuma zona real foi criada, removida ou sincronizada.
- A criacao automatica de slaves e a integracao com dominios permanecem reservadas para as Fases 2 e 3.

## [1.1.0] - 2026-06-15

### Melhorias visuais

- Sidebar da dashboard reorganizada nos grupos DNS, Seguranca, Logs, Infraestrutura e Conta.
- ACL IPv4 e ACL IPv6 movidas do grupo DNS para Seguranca.
- Logs separados dos recursos de seguranca e grupo Infraestrutura criado para Servicos e Firewall.
- Titulos dos grupos apresentados sem uso integral de letras maiusculas.
- Indicadores dos grupos atualizados para `▸` quando fechados e `▾` quando abertos.
- Cabecalho modernizado com badges para nome do servidor, IP principal e usuario autenticado.
- Icones de CPU, RAM, Disco e Uptime substituidos por SVGs vetoriais.
- Icones dos cards Dominios, Registros DNS e Zonas Reversas padronizados com o estilo vetorial da Saude do servidor.
- Icone colorido de grafico do titulo Dashboard DNS restaurado conforme a versao original aprovada.
- Icone de Zonas Reversas restaurado para a versao visual aprovada pelo operador.
- Link de alteracao de senha renomeado visualmente para Minha senha.

### Compatibilidade

- Mantida a exibicao de Usuarios somente para administradores.
- Preservados o comportamento mobile da sidebar e a responsividade dos cards.
- Bloco Acesso Rapido mantido sem alteracoes.
- Nenhuma regra de autenticacao, permissao, auditoria, DNS, SQLite, servicos ou firewall foi alterada.

## [1.0.1] - 2026-06-15

### Melhorias

- Sidebar da dashboard organizada nos grupos DNS, Logs / Seguranca, Sistema e Conta.
- Grupos recolhiveis com indicador visual e estado informado por `aria-expanded`.
- Dashboard mantida como link independente e destacado.
- ACL IPv4 e ACL IPv6 movidas visualmente para o grupo DNS.
- Removida a sombra lateral que acentuava a separacao entre sidebar e conteudo.
- Mantidos o menu responsivo e o fechamento ao selecionar um link no celular.

### Seguranca e compatibilidade

- Mantida a exibicao de Usuarios somente para administradores.
- Nenhuma regra de autenticacao, permissao, DNS, banco ou servico foi alterada.
- Bloco Acesso Rapido preservado sem alteracoes.

### Planejado

- `[V2_SIDEBAR_GLOBAL]`: transformar a sidebar em componente compartilhado entre as paginas.

## [1.0.0] - 2026-06-15

### Entregue

- Dashboard operacional com resumo DNS, saude do servidor, atividades recentes e acesso rapido.
- Gerenciamento de dominios e zonas DNS forward.
- Gerenciamento de zonas reversas e registros PTR IPv4 e IPv6.
- Validacao de zonas BIND antes da gravacao e recarga controlada por `rndc`.
- Auditoria de login, logout, usuarios, DNS, PTR e operacoes de servicos.
- Consulta de auditoria com filtros, paginacao, exportacao CSV e historico por dominio.
- Usuarios com perfis administrador e moderador, troca obrigatoria de senha e controle de contas ativas.
- Operacao controlada dos servicos BIND9, Fail2Ban, SSH e nftables.
- Visualizacao de firewall, ACL IPv4, ACL IPv6, logs do sistema, SSH, BIND e Fail2Ban.
- Protecao CSRF nos formularios POST criticos.
- Senhas armazenadas com hash e verificacao por APIs nativas do PHP.
- Layout responsivo da dashboard.

### Correcoes consolidadas

- Centralizada a identificacao do usuario nos eventos de auditoria.
- Adicionada auditoria para criacao e remocao de dominios e zonas.
- Registros DNS passaram a preservar owner, tipo e valores anterior e novo no historico.
- Normalizada a apresentacao de PTR IPv6 e nomes internos `.rev` e `.rev6`.
- Criada a pagina funcional de operacao de servicos.
- Dashboard passou a tratar falhas individuais de metricas e indisponibilidade da auditoria.

### Pendencias conhecidas

- Adequar `logs.php` ao journal do Debian quando `/var/log/auth.log` nao existir.
- Consolidar permissoes duplicadas de `rndc reload` no sudoers.
- Definir `ServerName` global do Apache.
- Inicializar repositorio Git e politica formal de releases.
- Executar testes controlados com perfil moderador e CRUD DNS/PTR descartavel.
