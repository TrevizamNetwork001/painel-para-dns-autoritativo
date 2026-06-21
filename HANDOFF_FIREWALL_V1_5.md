# Handoff FIREWALL_V1.5

Data: 2026-06-21

## Objetivo concluído

A V1.5 implementa geração de prévia e validação controlada da configuração
nftables cadastrada no painel.

Esta fase não aplica regras reais, não altera arquivos produtivos do firewall e
não executa reload ou restart de serviços.

## Arquivos alterados

- `firewall.php`
- `CHANGELOG.md`
- `HANDOFF_FIREWALL_V1_5.md`

## Geração da prévia

A prévia é gerada exclusivamente a partir dos registros existentes nas tabelas:

- `firewall_admin_access`
- `firewall_ports`

Foram consideradas separadamente:

- ACL administrativa IPv4
- ACL administrativa IPv6
- portas administrativas
- portas públicas
- protocolos TCP, UDP e TCP/UDP

A configuração gerada usa uma tabela independente:

- `table inet painel_firewall_preview`

A prévia contém:

- política `drop` na chain de entrada
- política `drop` na chain de encaminhamento
- política `accept` na chain de saída
- liberação da interface loopback
- liberação de conexões `established,related`
- liberação de ICMP para IPv4
- liberação de ICMPv6 para IPv6
- portas públicas liberadas sem restrição de origem
- portas administrativas condicionadas às ACLs correspondentes
- regras IPv4 com `ip saddr`
- regras IPv6 com `ip6 saddr`

Se existirem portas administrativas sem nenhuma ACL administrativa, essas
portas não são incluídas na prévia e a interface apresenta um aviso.

## Proteções da geração

- ACLs são normalizadas novamente antes de gerar regras.
- Portas e protocolos são validados novamente.
- IPv4 e IPv6 não são misturados.
- ACL administrativa `0.0.0.0/0` é bloqueada.
- ACL administrativa `::/0` é bloqueada.
- Dados do usuário não são usados diretamente para montar comandos shell.
- A prévia apresentada na interface é somente leitura.

## Validação controlada

A ação `validar_configuracao`:

- aceita somente POST
- exige token CSRF válido
- gera a prévia novamente no momento da validação
- cria arquivo temporário com `tempnam`
- ajusta o arquivo temporário para modo `0600`
- grava a prévia com `LOCK_EX`
- executa somente `nft -c -f ARQUIVO_TEMPORARIO`
- usa `/usr/bin/timeout` com limite de 8 segundos
- usa argumentos em array com `proc_open`, sem interpolação de entrada do usuário
- captura stdout e stderr
- limita a saída técnica a 6000 caracteres
- remove caminhos internos da saída apresentada
- tenta remover o arquivo temporário em bloco `finally`

Quando o processo PHP não está executando como root, a validação tenta usar:

- `sudo -n nft -c -f ARQUIVO_TEMPORARIO`

O uso de `sudo` continua não interativo e mantém obrigatoriamente a opção `-c`.
Ausência de permissão é tratada como erro de validação, sem qualquer aplicação.

## Persistência da última validação

O resultado é armazenado sem migração destrutiva na tabela existente
`firewall_meta`, usando a chave:

- `ultima_validacao_v15`

O registro JSON contém:

- status `OK` ou `ERRO`
- data e hora
- usuário
- resumo amigável
- saída técnica sanitizada
- código de saída
- duração em milissegundos
- quantidade de ACLs IPv4
- quantidade de ACLs IPv6
- quantidade de portas administrativas
- quantidade de portas públicas
- hash SHA-256 da prévia validada

## Interface

Foram adicionados ou ativados:

- ação rápida `Validar`
- botão `Validar configuração`
- área `Prévia das regras`
- card funcional `Última Validação`
- estados `OK`, `ERRO` e `NÃO VALIDADO`
- data e hora da validação
- usuário responsável
- resumo amigável
- saída técnica recolhida em `details/summary`

O layout dark/NOC, os modais, o CRUD existente e o toast com auto-hide foram
preservados.

## Auditoria

Eventos implementados:

- `FIREWALL_GERAR_PREVIA`
- `FIREWALL_GERAR_PREVIA_ERRO`
- `FIREWALL_VALIDAR_CONFIGURACAO`
- `FIREWALL_VALIDAR_CONFIGURACAO_ERRO`

Os eventos registram pelo mecanismo existente:

- usuário autenticado
- IP de origem
- ação
- status `SUCCESS` ou `ERROR`
- resumo
- tipo de registro `FIREWALL_VALIDACAO`
- data e hora
- quantidades de ACLs e portas na mensagem de auditoria

CSRF inválido na ação de validação também é auditado como erro com as contagens
disponíveis.

## Testes executados

- `php -l firewall.php`
- `git diff --check -- firewall.php`
- geração com ACL IPv4 administrativa
- geração com ACL IPv6 administrativa
- geração com porta TCP administrativa
- geração com porta UDP administrativa
- geração com porta TCP pública
- geração com porta UDP pública
- separação de regras IPv4 e IPv6
- bloqueio de `0.0.0.0/0`
- bloqueio de `::/0`
- POST com CSRF inválido
- auditoria de geração com sucesso
- auditoria de erro de geração
- auditoria de erro de validação
- persistência do card de última validação
- sanitização de caminhos internos
- remoção de arquivos temporários
- busca estática por reload, restart, systemctl e arquivos produtivos

Os testes de fluxo foram executados em uma cópia isolada do painel e do banco
SQLite em `/tmp`. Nenhum registro de teste foi gravado no banco produtivo.

## Limitação do ambiente de teste

O binário `/usr/sbin/nft` está disponível, mas o ambiente de execução utilizado
não concede a capacidade netlink necessária. Por isso, a chamada controlada
retornou erro de permissão:

- `Unable to initialize Netlink socket: Operation not permitted`

O erro foi reconhecido como falha de permissão, persistido no card e auditado
sem expor caminhos internos.

O caminho de sucesso real de `nft -c` deve ser validado em um host de homologação
com permissão restrita para executar somente a checagem:

- `nft -c -f ARQUIVO_TEMPORARIO`

## Confirmações de segurança

- Nenhum `nft -f` sem `-c` foi implementado ou executado.
- Nenhuma regra real foi aplicada.
- Nenhum reload foi implementado ou executado.
- Nenhum `systemctl` foi implementado ou executado.
- Nenhum arquivo produtivo do firewall foi lido, substituído ou gravado.
- Nenhuma porta foi aberta automaticamente.
- Nenhum rollback real foi implementado.
- A V1.6 não foi iniciada.

## Arquivos mantidos fora do commit

Conforme o estado anterior do projeto:

- `db/dns_servers.secret`
- `db/painel_dns.sqlite`

## Próxima fase

A aplicação real, reload controlado e rollback permanecem reservados para uma
fase posterior e exigem desenho específico de permissões, backup e recuperação.
