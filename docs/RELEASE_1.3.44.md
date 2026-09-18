# Release 1.3.44 — robustez do agente e dos fluxos DNS

- Agente 0.10.2: serializa ciclos concorrentes, guarda o relatório final em disco
  e o reenvia com o mesmo `event_id` após falha de rede. O upgrade informa a
  versão instalada no próprio resultado.
- Upgrade do agente: separa prazo de coleta (10 minutos) do prazo de execução
  (20 minutos), evita solicitações concorrentes e mantém o acompanhamento no
  painel até chegar ao estado final. A unit de operação tem timeout de 18 minutos.
- Publicação de zonas: a confirmação do primário autoriza a aplicação nos
  secundários pelo backend, sem depender da janela aberta no navegador.
- Histórico de versões: restauração de registros e SOA como alteração pendente,
  com nova versão e serial; a publicação continua explícita.
- Formulários de servidores: impedem escolher o mesmo primário e secundário e
  rejeitam texto de nome e hostname colado no campo de nome amigável.
- Build Vite deixa de buscar fonte externa; usa a pilha de fontes já definida
  no CSS, permitindo build sem essa conexão.

Validação: 365 testes PHP (1.805 verificações), 114 testes unitários do agente,
15 testes do instalador, Pint em 191 arquivos e build Vite. Três testes de
integração do upload remoto com servidor HTTP local não rodaram no sandbox por
restrição de socket. O rollout do agente 0.10.2 nos hosts autoritativos precisa
ser acompanhado host a host; esta release do painel apenas disponibiliza o
artefato e o fluxo de upgrade.

Análises detalhadas: [agente](REVISAO_AGENT_2026_09_18.md) e
[produto](REVISAO_PRODUTO_2026_09_18.md).
