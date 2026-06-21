# Changelog

Historico de alteracoes do Painel DNS Trevizam Network.

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
- Confirmada replicacao parcial: `legacy.example` sincronizada e zonas reversas legadas com falhas `NOTAUTH`/`REFUSED`.
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
