# Revisão do produto DNS Center — 18/09/2026

## Avaliação

O produto tem uma base operacional real: multi-tenant, RBAC, 2FA
administrativo, publicações versionadas, agentes autenticados, validação BIND,
TSIG, auditoria e deploy com health checks. Há uso documentado em primary e
secondary reais. Os riscos mais relevantes desta revisão estavam nas
transições entre etapas, não no cadastro básico de zonas.

Esta análise foi feita em código, testes e documentação. Não houve acesso à
interface autenticada. Backup remoto fica fora do
escopo desta rodada por decisão do operador.

## Falhas de fluxo corrigidas no código

1. **Secondary dependia do modal aberto.** A confirmação `applied` do primary
   agora autoriza a aplicação do secondary no backend. A criação usa lock do
   servidor para não duplicar operações concorrentes.
2. **Upgrade tinha confirmação tardia e tela que parava de consultar.** O
   resultado do agente passa a incluir a versão instalada; o painel a aceita
   quando coincide com o artefato disponível. O polling não para após 200
   consultas. Prazo de fila e prazo de execução foram separados.
3. **Histórico não tinha ação de recuperação.** A tela agora permite restaurar
   registros e parâmetros SOA de uma versão antiga como alteração pendente,
   com novo serial. A publicação continua explícita. Perfis, servidores e
   chaves TSIG atuais não são alterados pela restauração.
4. **Erros de formulário evitáveis.** Os seletores de primary/secondary
   impedem escolher o mesmo servidor na interface. O backend rejeita colar
   “nome — hostname” inteiro no campo de nome amigável do servidor.

## Pendências operacionais e de maturidade

- Duas zonas reais ainda têm NS duplicados; a correção dos dados deve ser
  feita pela interface e confirmada nas respostas DNS dos dois servidores.
- O controlador de zonas e o agente são módulos grandes. Separar regras DNS,
  orquestração e apresentação em partes menores reduzirá o custo dos próximos
  ajustes, mas exige testes de contrato para manter o comportamento atual.
- A suíte passou em contêineres isolados: 365 testes PHP, Pint em 191 arquivos e
  build Vite. Uma homologação de release ainda deve incluir BIND/systemd reais
  e cenários de falha de rede nos hosts autoritativos.

## Ordem de validação antes do rollout

1. Exercitar publicação primary → secondary fechando o modal logo após o POST.
2. Simular upgrade com rede lenta e perda da resposta final; conferir que a
   versão aparece sem esperar heartbeat e que o relatório final é reenviado.
3. Atualizar um agente piloto para 0.10.2, confirmar timers e então avançar
   host a host.
