# DNS Center v1.3.5

Data: 2026-09-08

## Escopo

Código da entrega: `8e6c0eb`.

- Autorização de `apply_zones` dentro de transação, com bloqueio do servidor
  para impedir operações duplicadas por solicitações simultâneas.
- `retry_after` padrão de database, Redis e Beanstalkd elevado de 90 para 180
  segundos, acima do timeout de 120 segundos dos workers.
- Workflow GitHub Actions para Pint, build frontend, PHPUnit e testes Python,
  com Compose e PostgreSQL temporários para CI.

O deploy inicial da aplicação não alterou o agente. A revisão posterior
identificou correções necessárias no fluxo do agente; elas serão publicadas na
versão 0.7.7 junto da próxima imagem do painel.

Detalhes: [avaliação e melhorias](AVALIACAO_E_MELHORIAS_2026_09_08.md).

## Testes anteriores ao deploy

- PHP: 244 testes, 1.330 assertions; passaram.
- Python: 102 testes; passaram.
- Pint: 166 arquivos; passou.
- Build frontend e validação da configuração Compose: passaram.
- O teste de bloqueio falhou com o controlador anterior e passou com a correção.

## Verificações operacionais anteriores ao deploy

- Versão ativa: 1.3.4; serviços saudáveis; HTTPS `/up` respondeu 200.
- Fila Redis com `retry_after=90`, sem override externo: o padrão 180 será
  adotado pelos novos containers.
- Agentes ns1/ns2 de cliente legado já reportam versão 0.7.6.
- As 14 publicações registradas estão `applied`; nenhuma está pendente.
- Consultas SOA diretas aos dois servidores responderam `NOERROR`, com flag
  autoritativa `aa` e seriais iguais ao painel nas seis zonas verificadas.

| Zona | Serial em ambos os servidores |
| --- | --- |
| `192.0.2.in-addr.arpa` | 2026090805 |
| `197.162.45.in-addr.arpa` | 2026090801 |
| `198.162.45.in-addr.arpa` | 2026090801 |
| `199.162.45.in-addr.arpa` | 2026090801 |
| `8.b.d.0.1.0.0.2.ip6.arpa` | 2026090801 |
| `legacy.example` | 2026090801 |

## Deploy concluído

Executado pelo procedimento `deploy/dns-center-deploy update 1.3.5` em
2026-09-08. Versão anterior: 1.3.4.

- Backup: `/var/backups/dns-center/dns-center-20260908T200210Z.dump`.
- Tamanho: 1.457.960 bytes; catálogo validado antes das migrations.
- SHA-256: `6b5d334fbae42fb24445776882637ea20c0d89fa66a9fbcefe21705a74db8f1b`.
- Nenhuma migration nova; app, web, queue e scheduler saudáveis em 1.3.5.
- HTTPS `/up` e `/login`: HTTP 200 após a troca de versão.
- Configuração efetiva: fila Redis, `retry_after=180`.
- Composer audit da imagem: nenhum aviso de vulnerabilidade encontrado.
- Hashes do controlador corrigido e da configuração da fila iguais aos do
  workspace validado; imagem sem `.env` nem `.git`.
- Security check sem bloqueios; aviso de `TRUSTED_PROXIES` vazio, já presente
  antes do deploy. A necessidade de CIDRs depende da topologia de proxies.
- Rollback da aplicação disponível para 1.3.4 pelo procedimento documentado.

## Limites da validação

Não havia publicação pendente para testar uma nova aplicação pelo botão em
produção. O opt-in local `DNS_CENTER_AGENT_ALLOW_APPLY=1` não é reportado pela
telemetria consultada e sua configuração não foi confirmada nos hosts remotos.
Não foram criadas alterações DNS apenas para provocar esse teste.

O checkout não possui remoto Git configurado. O push e a primeira execução do
workflow no GitHub dependem da URL do repositório de destino.
