# Handoff - Auditoria e Servicos do Painel DNS

Data: 13/06/2026  
Projeto: Painel DNS PHP + BIND9  
Diretorio: `/var/www/html/painel`

## Objetivo

Documentar os problemas encontrados, as correcoes implementadas, os testes
executados e os riscos que ainda precisam de tratamento.

## Atualizacao final - Integracao com o dashboard

A auditoria passou a ser acessivel e visivel diretamente no dashboard, sem
substituir a pagina completa `auditoria.php`.

Foram implementadas as duas formas de acesso:

- Link `Auditoria` na secao Logs da barra lateral.
- Card `Auditoria recente` no conteudo principal do dashboard.

O card apresenta:

- Total acumulado de eventos.
- Quantidade de eventos com status diferente de `OK`.
- Cinco eventos mais recentes.
- Data, usuario, acao, dominio ou registro e status.
- Link para abrir o historico completo com filtros.

A leitura do SQLite usa tratamento de excecao. Se o banco de auditoria
estiver indisponivel, o dashboard continua funcionando e mostra uma mensagem
de indisponibilidade no lugar do resumo.

No teste realizado em 13/06/2026, as consultas retornaram:

- 32 eventos no total.
- 5 eventos com erro.
- 5 eventos carregados na lista recente.

Esses numeros sao apenas o estado do banco no momento do teste e mudam
conforme novas operacoes sao executadas.

## Achados criticos

### 1. Usuario da auditoria nao era confiavel

Os pontos de auditoria consultavam campos de sessao diferentes e varios
usavam o fallback fixo `admin`. Isso permitia atribuir uma alteracao ao
usuario errado.

Situacao atual:

- A captura foi centralizada em `includes/audit.php`.
- A ordem usada e `username`, `user`, `usuario` e, por ultimo,
  `desconhecido`.
- Login com falha continua registrando o login digitado.
- Login com sucesso registra o usuario autenticado.
- Logout captura o usuario antes de destruir a sessao.

### 2. Criacao e remocao de dominio nao eram auditadas

Uma zona podia ser criada ou removida sem que o historico informasse quem
executou a operacao.

Situacao atual:

- Criacao registra o dominio, a zona forward e as zonas reversas criadas.
- Remocao registra cada arquivo de zona removido e o evento agregado do
  dominio.
- Falhas de validacao ou execucao geram eventos com status `ERRO`.

Eventos adicionados:

- `CRIAR_DOMINIO`
- `CRIAR_ZONA_FORWARD`
- `CRIAR_ZONA_REVERSA_IPV4`
- `CRIAR_ZONA_REVERSA_IPV6`
- `ERRO_CRIAR_DOMINIO`
- `REMOVER_DOMINIO`
- `REMOVER_ZONA_FORWARD`
- `REMOVER_ZONA_REVERSA_IPV4`
- `REMOVER_ZONA_REVERSA_IPV6`
- `ERRO_REMOVER_DOMINIO`

### 3. Logs de registros DNS perdiam contexto

Adicoes e remocoes gravavam apenas o valor final, por exemplo um endereco
IP, sem registrar o owner e o tipo DNS.

Situacao atual:

- Adicao grava `host IN TIPO valor`.
- Edicao grava as linhas antiga e nova completas.
- Remocao grava a linha antiga completa e `removido` como valor novo.
- Adicoes e criacoes sem valor anterior recebem `inexistente`.

### 4. PTR IPv6 expunha detalhes internos

Eventos antigos exibiam nomes como `pizza.com.br.rev6` e owners reversos
longos, dificultando a leitura operacional.

Situacao atual:

- Novos eventos gravam o dominio base, sem `.rev6` ou `.rev`.
- A coluna Registro mostra `PTR IPv6` ou `PTR IPv4`.
- Valores de PTR mostram o hostname, sem o owner reverso.
- A tela tambem corrige visualmente os eventos antigos, sem migrar o banco.

### 5. Pagina de servicos nao existia de fato

O arquivo `services.php` continha apenas `123` e o link no dashboard apontava
para `#`.

Situacao atual:

- Foi criada uma pagina autenticada de operacao de servicos.
- Todos os formularios usam protecao CSRF.
- Os comandos pertencem a uma lista fixa; o formulario nao fornece comandos.
- Sucesso e erro sao registrados na auditoria, incluindo a saida capturada.
- O dashboard agora aponta para `services.php`.

Acoes disponiveis:

- BIND: verificar, recarregar, reiniciar, iniciar e parar.
- Fail2Ban: verificar e reiniciar.
- SSH: verificar e reiniciar.
- Firewall: verificar e recarregar.

## Melhorias centrais

Arquivo `includes/audit.php`:

- Criada `audit_usuario_atual()`.
- Criada `audit_ip_atual()`.
- Criada `audit_dominio_base()`.
- Removido o fallback implicito para `admin`.
- Normalizados os valores `inexistente` e `removido`.

Arquivo `auditoria.php`:

- Adicionados rotulos para dominios, zonas, PTR e servicos.
- Adicionados os novos eventos ao filtro.
- Normalizada a exibicao de dominios reversos.
- Mantida compatibilidade visual com eventos antigos.

Arquivo `dashboard.php`:

- Adicionado link direto para Auditoria na barra lateral.
- Adicionado card com total de eventos e eventos com erro.
- Adicionada tabela com os cinco eventos mais recentes.
- Falhas no SQLite sao tratadas sem derrubar o dashboard.
- Mantido link para o historico completo e seus filtros.
- Adicionada traducao resumida das principais acoes para leitura no card.

Arquivos de registros alterados:

- `edit-zone.php`
- `edit-record.php`
- `delete-record.php`
- `edit-reverse-zone.php`
- `edit-ptr.php`
- `delete-ptr.php`

Arquivos adicionais alterados:

- `domains.php`
- `login.php`
- `logout.php`
- `services.php`
- `dashboard.php`
- `includes/teste_audit.php`

## Validacoes executadas

Foi executado `php -l` com sucesso nos seguintes arquivos:

- `includes/audit.php`
- `includes/teste_audit.php`
- `auditoria.php`
- `domains.php`
- `edit-zone.php`
- `edit-record.php`
- `delete-record.php`
- `edit-reverse-zone.php`
- `edit-ptr.php`
- `delete-ptr.php`
- `login.php`
- `logout.php`
- `services.php`
- `dashboard.php`

Apos a integracao da auditoria com o dashboard, foram repetidas as
validacoes:

- `php -l dashboard.php`: sem erros.
- `php -l auditoria.php`: sem erros.
- Consulta do total de eventos: executada com sucesso.
- Consulta do total de erros: executada com sucesso.
- Consulta dos cinco eventos recentes: executada com sucesso.

Tambem foi confirmado que existem no servidor:

- `/usr/bin/systemctl`
- `/usr/sbin/rndc`
- `/usr/bin/fail2ban-client`
- `/usr/sbin/nft`

As funcoes de usuario, IP e dominio base foram testadas sem escrita:

- Usuario de sessao: `operador.real`
- IP em CLI: `CLI`
- `pizza.com.br.rev6` foi normalizado para `pizza.com.br`

## Pendencias e riscos criticos

### 1. Credenciais fixas no codigo

O arquivo `config.php` ainda possui usuario e senha administrativos em texto
claro. Isso deve ser substituido por autenticacao com senha armazenada como
hash e configuracao fora da raiz publica.

### 2. Operacoes reais nao foram executadas

Nao foram criados ou removidos dominios descartaveis, nem reiniciados
servicos durante esta etapa. Esses testes alteram BIND e servicos do host e
devem ser executados em janela controlada.

### 3. Configuracao de sudoers precisa ser confirmada

Os botoes de servicos dependem de permissoes `sudo` sem prompt para o usuario
do servidor web. A lista deve permitir somente os comandos exatos usados
pela pagina.

### 4. Falha da auditoria pode afetar o fluxo principal

Nos fluxos DNS, a gravacao da auditoria acontece depois da alteracao. Se o
SQLite estiver indisponivel nesse momento, a operacao DNS pode ter sido
concluida, mas a resposta ao usuario pode falhar. Recomenda-se encapsular a
auditoria em tratamento de excecao e registrar a falha no log do sistema.

### 5. Resultado de reload nem sempre e validado

Alguns fluxos chamam `reload_dns()` sem verificar o retorno. A alteracao pode
ser gravada no arquivo e auditada como sucesso mesmo que o reload falhe.

### 6. Remocao de arquivos nao valida cada `unlink`

Na remocao de dominio, o retorno de cada `unlink()` nao e verificado. O
evento pode indicar remocao completa mesmo se algum arquivo permanecer.

### 7. Projeto sem repositorio Git

Nao existe `.git` em `/var/www/html/painel`. Nao ha historico confiavel,
revisao de diff ou rollback versionado das alteracoes.

### 8. Banco antigo nao foi migrado

Os eventos antigos continuam armazenados com `.rev6`, owner reverso e
valores incompletos. A tela os apresenta de forma melhor, mas consultas
diretas ao SQLite ainda mostram os valores originais.

## Testes funcionais recomendados

Executar em ambiente controlado:

1. Login com sucesso, login invalido e logout.
2. Criar um dominio descartavel com zona forward.
3. Criar o mesmo tipo de dominio com reversos IPv4 e IPv6.
4. Adicionar, editar e remover registros A, AAAA, CNAME, MX e TXT.
5. Adicionar, editar e remover PTR IPv4 e IPv6.
6. Remover o dominio descartavel e confirmar todos os arquivos.
7. Executar `CHECK_*` para todos os servicos.
8. Testar reload/restart conforme a janela operacional.
9. Conferir o SQLite depois de cada operacao.

Consulta sugerida:

```sql
SELECT
    id,
    usuario,
    ip,
    acao,
    dominio,
    nome_registro,
    valor_antigo,
    valor_novo,
    status,
    mensagem,
    criado_em
FROM audit_logs
ORDER BY id DESC
LIMIT 50;
```

## Proxima prioridade

1. Remover as credenciais fixas de `config.php`.
2. Fazer a auditoria nunca interromper uma operacao ja concluida.
3. Auditar o resultado real de `reload_dns()`.
4. Validar `unlink()` e registrar falhas parciais.
5. Executar os testes funcionais com dominio descartavel.
6. Inicializar um repositorio Git e criar um baseline do projeto.
