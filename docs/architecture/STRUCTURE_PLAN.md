# Plano de estrutura futura - Painel Bind

## Objetivo

Criar uma base de organizacao para uma migracao futura do painel DNS autoritativo com Bind9, inspirada na separacao usada no Unbound Manager: entrada publica isolada, aplicacao fora do document root, configuracao versionavel sem segredos, storage privado e documentacao operacional clara.

Esta fase nao altera funcionamento atual. Nenhum PHP existente foi movido, nenhuma logica DNS foi alterada e nenhum reload/restart do Bind foi executado.

## Estrutura atual

Hoje o projeto roda diretamente a partir de `/var/www/html/painel`.

Caracteristicas atuais:

- PHPs publicos ficam no document root, como `dashboard.php`, `domains.php`, `zones.php`, `dns-servers.php`, `firewall.php`, `login.php` e outros.
- Logica compartilhada fica em `includes/`.
- Scripts operacionais ficam em `scripts/` e `scripts/ns2/`.
- O banco real e secrets foram removidos do rastreamento Git na fase de higiene, mas os arquivos locais continuam existindo no disco e sao ignorados.
- Documentacao historica e handoffs ficam no root e em `docs/`.

Essa estrutura continua ativa. A nova estrutura criada nesta fase e apenas um alvo de migracao.

## Estrutura futura proposta

```text
app/
  Auth/
  Dns/
  DnsServers/
  Firewall/
  Audit/
  Services/
  Support/

config/
  examples/

storage/
  database/
  logs/
  cache/
  secrets/
  backups/

public/

docs/
  architecture/
  operations/
  handoff/
  migrations/

tests/
```

Responsabilidades pretendidas:

- `public/`: futuro document root, com ponto de entrada publico e assets. Ainda nao deve receber trafego em producao.
- `app/`: codigo de aplicacao separado por dominio.
- `app/Auth/`: autenticacao, sessao e usuarios.
- `app/Dns/`: zonas, registros, validacao e inventario DNS.
- `app/DnsServers/`: cadastro de NS, agente remoto, SSH e orquestracao de servidores.
- `app/Firewall/`: ACLs, portas e integracao com firewall.
- `app/Audit/`: trilha de auditoria e eventos.
- `app/Services/`: status de servicos e diagnosticos.
- `app/Support/`: helpers compartilhados e adaptadores.
- `config/`: configuracoes versionaveis sem segredo real.
- `config/examples/`: modelos de configuracao sanitizados.
- `storage/`: dados locais privados, fora do fluxo publico.
- `storage/database/`: bancos locais, nunca versionados quando reais.
- `storage/logs/`: logs gerados pela aplicacao.
- `storage/cache/`: cache local.
- `storage/secrets/`: chaves e segredos locais.
- `storage/backups/`: backups privados locais.
- `docs/`: arquitetura, operacao, handoff e migracoes.
- `tests/`: testes futuros unitarios e de integracao read-only.

## Por que `public/` ainda nao e document root

O document root atual ainda e `/var/www/html/painel` e os PHPs publicos continuam no root do projeto.

Nao apontar o servidor web para `public/` nesta fase evita uma mudanca operacional ampla. Antes disso, sera necessario:

- criar um ponto de entrada compativel em `public/`;
- decidir como rotear as paginas atuais;
- mover assets com compatibilidade;
- ajustar includes/paths;
- validar sessao, CSRF, downloads e redirecionamentos;
- testar todas as telas administrativas;
- planejar rollback.

Enquanto isso nao estiver pronto, `public/` permanece como estrutura futura versionada, sem participacao no runtime atual.

## Plano de migracao por fases

### Fase 1 - higiene Git

Concluida antes desta estrutura.

- Remover bancos reais e secrets do rastreamento Git sem apagar do disco.
- Criar `.gitignore` para SQLite, secrets, backups, logs, chaves e arquivos locais.
- Gerar schema sanitizado.
- Consolidar documentacao de auditoria e inventario externo.

### Fase 2 - estrutura base

Fase atual.

- Criar diretorios alvo.
- Adicionar `.gitkeep` onde necessario.
- Documentar responsabilidades e ordem de migracao.
- Nao mover PHPs nem alterar logica DNS.

### Fase 3 - configuracao e bootstrap

- Criar exemplos em `config/examples/`.
- Definir carregamento de paths sem segredos reais.
- Preparar adaptadores para manter compatibilidade com caminhos atuais.
- Documentar variaveis de ambiente sem criar `.env` real.

### Fase 4 - extracao incremental de dominio

- Extrair codigo de `includes/dns_servers.php` para `app/DnsServers/` em passos pequenos.
- Extrair codigo de `includes/dns_zones.php` para `app/Dns/`.
- Manter wrappers em `includes/` enquanto as paginas antigas ainda dependem deles.
- Adicionar testes de validacao de zonas, parsing e comandos gerados.

### Fase 5 - storage privado

- Migrar configuracao de caminhos para apontar bancos e secrets para `storage/`.
- Manter os arquivos reais fora do Git.
- Executar migracao somente com backup privado e rollback.

### Fase 6 - public document root

- Criar entrada em `public/`.
- Migrar assets e rotas.
- Manter compatibilidade temporaria com as URLs atuais ou documentar redirecionamento.
- Validar autenticacao, CSRF, auditoria e todas as telas administrativas.

### Fase 7 - operacoes Bind com validate/diff/apply

- Separar geracao de comando, validacao e aplicacao.
- Exigir `named-checkconf` e `named-checkzone` antes de qualquer apply.
- Manter remocao automatica bloqueada por padrao.
- Auditar usuario, IP, comando, arquivos afetados e resultado.

## O que nao deve entrar no Git

Nunca versionar:

- bancos reais: `*.sqlite`, `*.sqlite.*`;
- secrets: `*.secret`, `storage/secrets/*`;
- `.env` real;
- chaves privadas: `*.pem`, `*.key`, `id_rsa`, `id_ed25519`;
- logs: `*.log`, `storage/logs/*`;
- backups reais: `storage/backups/*`, tarballs e dumps;
- zonas Bind reais;
- configs Bind reais com dados operacionais;
- sudoers reais;
- regras firewall/nftables reais;
- capturas de tela com dados de producao.

Podem entrar no Git somente artefatos sanitizados:

- exemplos de configuracao;
- schema sem dados;
- documentacao revisada;
- fixtures sem dominios, IPs ou credenciais reais;
- testes sem dados produtivos.

## Relacao com o modelo do Unbound Manager

A referencia do Unbound Manager e a separacao de responsabilidades, nao uma copia direta de implementacao.

Principios adotados:

- manter runtime publico isolado em `public/`;
- manter codigo de dominio em `app/`;
- manter configuracao sem segredos reais em `config/`;
- manter dados mutaveis e privados em `storage/`;
- documentar operacoes em `docs/operations/`;
- tratar migracoes como etapas reversiveis;
- separar validacao de aplicacao em operacoes DNS.

Para o painel Bind, isso e especialmente importante porque a aplicacao controla arquivos de zona, inventario de NS, credenciais administrativas, scripts remotos e potencialmente comandos privilegiados. A estrutura futura deve reduzir o risco de exposicao acidental e facilitar testes antes de qualquer alteracao operacional.
