# Funcionalidades

Catalogo das funcionalidades presentes na V1 do Painel DNS Trevizam Network.

## Dashboard

- Resumo de dominios, registros DNS e zonas reversas.
- Metricas de CPU, RAM, disco e uptime.
- Indicadores visuais de uso normal, atencao e estado critico.
- Exibicao das seis atividades mais recentes.
- Acesso rapido para operacoes frequentes.
- Menu responsivo para desktop e celular.

## DNS Forward

- Criacao e remocao de dominios.
- Listagem e edicao de zonas.
- Criacao, edicao e remocao de registros DNS.
- Suporte operacional a registros A, AAAA, CNAME, MX e TXT.
- Incremento do serial da zona.
- Validacao com `named-checkzone` e `named-checkconf`.
- Escrita segura dos arquivos e recarga do BIND por `rndc`.

## DNS Reverso

- Listagem e edicao de zonas reversas.
- Gerenciamento de PTR IPv4 e IPv6.
- Conversao e validacao de owners reversos.
- Suporte a zonas relacionadas ao dominio.
- Auditoria com apresentacao legivel de enderecos e hostnames.

## Auditoria

- Registro de login, falha de login e logout.
- Registro de operacoes em dominios, zonas, registros, PTR, usuarios e servicos.
- Armazenamento de usuario, IP, acao, valores anterior e novo, status e data.
- Filtros por periodo, usuario, acao, status e dominio.
- Paginacao, exportacao CSV e historico por dominio.
- Normalizacao visual de eventos antigos de PTR.

## Usuarios e acesso

- Autenticacao por usuario e senha.
- Perfis administrador e moderador.
- Pagina de usuarios restrita a administradores.
- Criacao, alteracao, ativacao, desativacao e remocao de usuarios.
- Redefinicao e troca obrigatoria de senha.
- Protecao contra remocao da propria conta e do ultimo administrador ativo.
- Expiracao de sessao por inatividade.

## Seguranca

- Tokens CSRF em formularios POST criticos.
- Senhas protegidas com `password_hash()` e `password_verify()`.
- Atualizacao de hash com `password_needs_rehash()`.
- Validacao de dominio, hostname, CIDR e conteudo de zona.
- Escape de argumentos em comandos sensiveis.
- Lista fixa de comandos permitidos na operacao de servicos.
- Auditoria de sucesso e falha nas operacoes relevantes.

## Servicos e rede

- Consulta de estado de BIND9, Fail2Ban, SSH e nftables.
- Acoes controladas de recarga, inicio, parada e reinicio conforme o servico.
- Visualizacao do ruleset do firewall.
- Gerenciamento de ACL IPv4 e IPv6.
- Validacao e recarga do nftables.
- Consulta do estado das jails Fail2Ban para SSH e BIND9.

## Logs

- Consulta de logs do sistema.
- Consulta de eventos SSH/Auth.
- Consulta de logs do BIND e seguranca DNS.
- Consulta do Fail2Ban para SSH e BIND9.

## Limites da V1

- A sidebar agrupada existe somente na dashboard.
- As demais paginas usam links proprios de retorno ao painel.
- A centralizacao de logs no journal do Debian ainda esta planejada.
- Testes destrutivos de servicos e CRUD em zonas reais nao fazem parte da validacao automatica.
