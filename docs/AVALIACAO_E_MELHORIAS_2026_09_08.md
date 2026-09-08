# Avaliação e melhorias de confiabilidade

Data: 2026-09-08

## Avaliação

O projeto apresenta uma base funcional ampla para gerenciamento de DNS
autoritativo, com organizações, permissões, segundo fator administrativo,
publicações versionadas, agentes remotos e topologia BIND primary/secondary.
A avaliação examinou código, configurações, documentação e testes. Não foi
uma auditoria completa de segurança nem uma homologação da produção.

As prioridades identificadas foram concorrência na autorização de aplicações,
tempos da fila e ausência de integração contínua versionada. A concentração de
responsabilidades no agente Python e no controlador de zonas permanece como
oportunidade de manutenção futura; esses módulos não foram divididos nesta entrega.

## Mudanças implementadas

### Autorização de aplicação

Antes, duas solicitações simultâneas podiam verificar que não havia aplicação
em andamento e criar duas operações. O `DnsBindApplyController` agora abre uma
transação e bloqueia o registro do servidor com `lockForUpdate()` antes das
verificações e da criação. A autorização é conferida novamente após o bloqueio.
Uma solicitação concorrente aguarda a transação e encontra a operação existente,
recebendo HTTP 409 enquanto ela estiver autorizada ou em execução.

O teste de regressão usa duas conexões PostgreSQL e `FOR UPDATE NOWAIT` para
comprovar que o registro está bloqueado durante a verificação. Também verifica
a liberação após a transação e a rejeição de uma segunda solicitação.

### Fila

O `retry_after` padrão de database, Redis e Beanstalkd passou de 90 para 180
segundos, acima do `--timeout=120` dos workers. O `.env.example` registra os
três overrides correspondentes. Valores explícitos em ambientes existentes
continuam prevalecendo e precisam ser conferidos no próximo deploy.

### Integração contínua

O workflow `.github/workflows/ci.yml` define execução em push, pull request e
acionamento manual, com instalação das dependências PHP, Pint, build frontend,
PHPUnit e testes Python. O `compose.ci.yaml` fornece PostgreSQL temporário com
o banco `dns_center_testing`, sem portas publicadas nem volumes de produção.
O build frontend precede o PHPUnit porque as views utilizam o manifest Vite.

Os comandos para reprodução estão no [README](../README.md#integração-contínua).

## Validação realizada

| Verificação | Resultado |
| --- | --- |
| Suíte PHP completa | 244 testes, 1.330 assertions; passou |
| Suíte Python | 102 testes; passou |
| Pint | 166 arquivos; passou |
| Build frontend | Passou |
| Configuração Compose de CI | Validada pelo Docker Compose |
| `git diff --check` | Sem erros |
| Teste de bloqueio contra o controlador anterior | Falhou conforme esperado, detectando a ausência do bloqueio |

A validação local utilizou uma cópia do código em `/tmp`, a imagem PHP local
`dns-center-app:1.3.4`, Node 24 e um PostgreSQL separado dos serviços de
produção. As dependências locais existentes foram reutilizadas. Isso não
equivale a uma execução do workflow no GitHub ou a uma instalação limpa das
dependências. Os containers e a rede temporários foram removidos ao final.

As primeiras rodadas identificaram pré-requisitos do ambiente de CI: assets
Vite compilados e `APP_NAME` definido. A configuração final inclui esses
pré-requisitos, e a última suíte PHP completa passou.

## Situação operacional e próximos passos

- Não houve deploy nem alteração dos serviços de produção nesta entrega.
- O workflow precisa ser enviado ao GitHub com Actions habilitado para
  executar automaticamente; não foi executado remotamente nesta avaliação.
- No próximo deploy, conferir os overrides de `retry_after` no ambiente
  externo e atualizar workers e cache de configuração pelo procedimento
  documentado de deploy. Para SQS, conferir o visibility timeout separadamente.
- A atualização e o opt-in dos agentes mencionados na
  [versão 1.3.4](RELEASE_1.3.4.md) permanecem como pendência documental cuja
  conclusão operacional não foi verificada nesta avaliação.
- A refatoração dos módulos maiores fica para uma entrega posterior.
