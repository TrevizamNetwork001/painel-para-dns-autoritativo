# Roadmap

Planejamento tecnico posterior a V1. Itens abaixo nao representam funcionalidades entregues.

## Prioridade alta

### [V2_SIDEBAR_GLOBAL]

- Criar `includes/sidebar.php`.
- Integrar a sidebar em todas as paginas autenticadas.
- Destacar o link da pagina atual.
- Abrir automaticamente o grupo correspondente.
- Preservar regras de perfil e o comportamento responsivo.
- Padronizar layout e navegacao sem alterar a logica de cada pagina.

### Logs no Debian

- Substituir dependencias de `/var/log/auth.log` por consultas controladas ao journal.
- Padronizar mensagens de indisponibilidade e limites de leitura.
- Revisar as permissoes minimas necessarias para consulta.

### Testes controlados

- Criar usuario moderador temporario e validar todas as restricoes.
- Executar CRUD descartavel de dominio, registro A e PTR IPv6.
- Confirmar cada operacao na auditoria.
- Repetir smoke test autenticado de todas as paginas.

## Prioridade media

### Versionamento

- Inicializar repositorio Git.
- Definir estrategia de branches, tags e releases.
- Excluir banco, backups, segredos e artefatos operacionais do versionamento.
- Vincular cada release ao `CHANGELOG.md`.

### Configuracao e operacao

- Consolidar regras duplicadas de `rndc reload` no sudoers.
- Definir `ServerName` global do Apache.
- Documentar procedimento de backup, restauracao e rollback.
- Revisar proprietarios, grupos e modos dos arquivos do painel.

### Qualidade

- Criar testes automatizados para validadores, normalizacao DNS e permissoes.
- Adicionar smoke tests HTTP para login, dashboard e paginas protegidas.
- Padronizar componentes visuais compartilhados gradualmente.
- Revisar acessibilidade por teclado e contraste.

## Prioridade futura

- Atualizacao parcial das metricas da dashboard sem recarregar a pagina.
- Alertas operacionais configuraveis para servicos e uso de recursos.
- Relatorios de auditoria agendados.
- Politica de retencao e rotacao dos registros de auditoria.
