# Handoff FIREWALL_V1.6

Data: 2026-06-21

## Objetivo concluído

A V1.6 implementa aplicação real controlada e rollback manual das regras
nftables geradas pelo painel.

A aplicação somente é autorizada após confirmação forte, validação obrigatória
do mesmo arquivo temporário e persistência de backup válido.

## Arquivos alterados

- `firewall.php`
- `CHANGELOG.md`
- `HANDOFF_FIREWALL_V1_6.md`

## Escopo nftables gerenciado

O painel gerencia exclusivamente:

- `table inet painel_firewall`

A V1.6 não executa `flush ruleset` e não substitui o ruleset completo. Tabelas
criadas por outros serviços ou administradores não são removidas, restauradas ou
alteradas.

Quando a tabela gerenciada já existe, a transação validada contém:

1. remoção da tabela `inet painel_firewall`
2. criação da nova tabela `inet painel_firewall`
3. chains e regras geradas a partir do SQLite

Quando ela ainda não existe, a transação contém somente sua criação.

## Pré-condições obrigatórias

A aplicação é bloqueada se:

- não existir ACL administrativa IPv4 ou IPv6
- não existir porta administrativa
- existir ACL administrativa `0.0.0.0/0`
- existir ACL administrativa `::/0`
- uma ACL cadastrada não aparecer nas regras geradas
- uma porta administrativa não aparecer protegida por ACL
- a prévia segura não puder ser gerada
- o estado atual da tabela não puder ser consultado para backup
- o estado da tabela mudar entre a preparação e o backup
- o arquivo temporário não puder ser criado
- a validação `nft -c -f` falhar
- o backup não puder ser persistido
- CSRF for inválido
- a confirmação forte estiver incorreta
- a confirmação visual não estiver marcada

## Confirmação forte

Aplicação:

- texto obrigatório: `APLICAR FIREWALL`
- checkbox adicional confirmando revisão de ACLs e portas

Rollback:

- texto obrigatório: `REVERTER FIREWALL`
- checkbox adicional confirmando a reversão

As comparações são exatas e feitas no servidor.

## Sequência de aplicação

A ação `aplicar_firewall` usa POST e executa:

1. validação de autenticação pelo mecanismo existente
2. validação CSRF
3. auditoria da solicitação
4. confirmação forte
5. leitura e validação dos dados cadastrados
6. geração da tabela `inet painel_firewall`
7. verificação das proteções administrativas
8. consulta inicial do estado da tabela gerenciada
9. criação de arquivo temporário em modo `0600`
10. validação obrigatória com `nft -c -f`
11. nova consulta do estado para detectar alteração concorrente
12. persistência do backup em `firewall_meta`
13. aplicação com `nft -f` no mesmo arquivo validado
14. persistência do resultado da aplicação
15. auditoria do resultado
16. remoção do arquivo temporário

O `nft -f` não é chamado quando `nft -c -f` falha.

## Execução controlada

- argumentos são passados em array para `proc_open`
- nenhuma entrada do formulário é interpolada em shell
- timeout de 8 segundos
- stdout e stderr capturados
- saída comum limitada e sanitizada
- caminhos temporários e internos removidos da apresentação
- arquivo temporário removido em `finally`
- limpeza também registrada em `register_shutdown_function` para redirects
- nenhum comando completo é exibido na interface

O padrão da V1.5 foi preservado:

- execução direta quando o PHP possui privilégio suficiente
- `sudo -n` somente pelo padrão fixo existente quando o processo não é root

## Backup

O backup é persistido na tabela existente `firewall_meta` com a chave:

- `ultimo_backup_v16`

O backup cobre somente a tabela gerenciada pelo painel.

Se a tabela existia, são armazenados:

- conteúdo anterior retornado pelo nft
- hash SHA-256
- data e hora
- usuário
- estado disponível

Se a tabela não existia, o backup registra explicitamente:

- ausência da tabela anterior
- hash do marcador de ausência

Esse segundo caso permite que o rollback remova somente a tabela criada pela
primeira aplicação do painel.

O conteúdo do backup não é exibido na interface comum.

## Proteção contra alteração concorrente

O estado da tabela é consultado antes da validação e novamente antes da criação
do backup.

Se existência ou hash forem diferentes, a aplicação é bloqueada. Isso impede
aplicar uma transação preparada sobre um estado que foi alterado durante a
operação.

## Rollback manual

A ação `rollback_firewall`:

- usa POST
- exige autenticação e CSRF
- exige confirmação exata
- exige backup disponível e hash válido
- consulta o estado atual da tabela gerenciada
- cria transação temporária de rollback
- valida o backup com `nft -c -f`
- somente depois executa `nft -f`
- audita sucesso e erro
- persiste o resultado em `ultima_reversao_v16`
- marca o backup como utilizado após sucesso

Quando o backup indica tabela anterior:

- a tabela atual é removida
- a tabela anterior é restaurada

Quando o backup indica ausência anterior:

- somente a tabela criada pelo painel é removida

Nenhuma outra tabela nftables é alterada.

## Persistência da aplicação

O último resultado é armazenado em:

- `ultima_aplicacao_v16`

Campos persistidos:

- status `APLICADO`, `ERRO` ou `REVERTIDO`
- data e hora
- usuário
- resumo amigável
- saída técnica sanitizada
- resultado da validação prévia
- indicação de backup disponível
- quantidades de ACLs e portas
- hash SHA-256 da transação

O último rollback também é armazenado em:

- `ultima_reversao_v16`

## Interface

Foram adicionados:

- ação rápida `Aplicar`
- ação rápida `Rollback`
- card `Última Aplicação`
- status Nunca aplicado, Aplicado com sucesso, Erro e Revertido
- data e hora
- usuário
- resultado da validação prévia
- indicação de backup
- resumo de ACLs e portas
- saída técnica recolhida
- modal de aplicação forte
- modal de rollback forte
- alerta visual diferenciando validação e aplicação
- bloqueios preventivos exibidos antes de abrir aplicação

O layout dark/NOC, CRUD, modais anteriores, prévia, card de validação e toast
foram preservados.

## Auditoria

Eventos implementados:

- `FIREWALL_APLICAR_SOLICITADO`
- `FIREWALL_APLICAR_BLOQUEADO`
- `FIREWALL_APLICAR_VALIDACAO_OK`
- `FIREWALL_APLICAR_VALIDACAO_ERRO`
- `FIREWALL_BACKUP_CRIADO`
- `FIREWALL_BACKUP_ERRO`
- `FIREWALL_APLICAR_SUCESSO`
- `FIREWALL_APLICAR_ERRO`
- `FIREWALL_ROLLBACK_SOLICITADO`
- `FIREWALL_ROLLBACK_BLOQUEADO`
- `FIREWALL_ROLLBACK_VALIDACAO_OK`
- `FIREWALL_ROLLBACK_VALIDACAO_ERRO`
- `FIREWALL_ROLLBACK_SUCESSO`
- `FIREWALL_ROLLBACK_ERRO`
- `FIREWALL_CSRF_INVALIDO`

As mensagens incluem, quando disponíveis:

- usuário pelo mecanismo central de auditoria
- IP de origem
- status `SUCCESS` ou `ERROR`
- resumo sanitizado
- quantidade de ACLs IPv4
- quantidade de ACLs IPv6
- quantidade de portas administrativas
- quantidade de portas públicas
- data e hora pelo banco

## Testabilidade segura

Para testar os fluxos de sucesso sem tocar o firewall real, o helper aceita um
binário alternativo somente quando:

- `PHP_SAPI === 'cli'`
- a variável `FIREWALL_NFT_TEST_BINARY` aponta para executável válido

A interface web ignora essa variável e continua restrita aos caminhos fixos:

- `/usr/sbin/nft`
- `/usr/bin/nft`

O executor falso usado nos testes ficou em `/tmp` e não faz parte do projeto ou
do commit.

## Testes executados

- `php -l firewall.php`
- `git diff --check -- firewall.php`
- geração de tabela com IPv4 e IPv6
- portas administrativas TCP e UDP protegidas por ACL
- portas públicas TCP e UDP
- bloqueio sem ACL administrativa
- bloqueio sem porta administrativa
- bloqueio de `0.0.0.0/0`
- bloqueio de `::/0`
- CSRF inválido sem execução do nft
- confirmação de aplicação incorreta sem execução do nft
- confirmação de rollback incorreta sem execução do nft
- rollback sem backup sem execução do nft
- erro sintético de validação sem chamada posterior de `nft -f`
- erro sintético de aplicação após backup
- erro sintético na etapa de backup
- aplicação isolada com sucesso
- rollback isolado com sucesso
- rollback de primeira aplicação removendo a tabela criada
- rollback com tabela anterior restaurando seu conteúdo
- erro de validação do backup sem chamada posterior de `nft -f`
- persistência da última aplicação
- persistência da última reversão
- preservação e consumo do backup
- auditoria de sucesso e erro
- remoção dos temporários em redirects
- renderização sem caminhos internos
- busca por `systemctl`, reload, restart, `flush ruleset` e arquivos produtivos

## Ordem observada no teste isolado

Aplicação:

1. `list table inet painel_firewall`
2. `-c -f ARQUIVO_TEMPORARIO`
3. `list table inet painel_firewall`
4. `-f ARQUIVO_TEMPORARIO`

Rollback:

1. `list table inet painel_firewall`
2. `-c -f ARQUIVO_TEMPORARIO`
3. `-f ARQUIVO_TEMPORARIO`

Os caminhos temporários acima não são apresentados na interface.

## Limitação do ambiente real

O ambiente atual possui `/usr/sbin/nft`, mas não concede capacidade netlink.
A tentativa controlada de consultar a tabela gerenciada retornou falta de
permissão.

O sistema:

- reconheceu a falha
- auditou `FIREWALL_BACKUP_ERRO`
- auditou `FIREWALL_APLICAR_BLOQUEADO`
- registrou status `ERRO`
- não executou aplicação real
- não tentou elevar ou contornar permissões

Por essa limitação, aplicação e rollback reais devem ser homologados em host
com permissão mínima adequada.

## Confirmações de segurança

- Nenhuma aplicação real foi executada durante o desenvolvimento.
- Nenhum `systemctl` foi implementado ou executado.
- Nenhum reload ou restart foi implementado ou executado.
- Nenhum `flush ruleset` foi implementado.
- Nenhum arquivo produtivo nftables foi substituído.
- Nenhum script externo do projeto foi chamado.
- Nenhum rollback automático ou timer foi implementado.
- Nenhuma ACL foi criada automaticamente a partir do IP atual.
- Nenhuma regra externa à tabela gerenciada é removida.

## Arquivos mantidos fora do commit

- `db/dns_servers.secret`
- `db/painel_dns.sqlite`

## Próximos passos operacionais

Antes de uso produtivo:

1. conceder permissão mínima ao usuário do PHP para os subcomandos nft usados
2. validar aplicação em sessão de console ou acesso fora da banda
3. confirmar que a ACL administrativa contém a origem operacional
4. executar aplicação controlada
5. testar rollback manual imediatamente após a primeira homologação
