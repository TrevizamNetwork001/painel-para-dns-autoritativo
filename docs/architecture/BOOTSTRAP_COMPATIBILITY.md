# Bootstrap de compatibilidade

## Objetivo

A camada em `app/Support/` e preparatoria para uma migracao gradual do painel Bind para a estrutura futura em `app/`, sem alterar o funcionamento atual.

Nesta fase:

- nenhuma pagina publica foi movida;
- nenhum include antigo foi substituido;
- nenhuma logica DNS foi alterada;
- nenhum reload/restart do Bind foi executado;
- nenhum banco ou secret foi alterado;
- nenhum fluxo de autenticacao foi modificado.

## Arquivos criados

### `app/Support/paths.php`

Define constantes de caminho para uso futuro:

- `PANEL_ROOT`
- `PANEL_APP_DIR`
- `PANEL_INCLUDE_DIR`
- `PANEL_STORAGE_DIR`
- `PANEL_DB_DIR`
- `PANEL_DOCS_DIR`

O arquivo usa guardas com `defined()` para evitar redefinicao quando carregado mais de uma vez.

### `app/Support/bootstrap.php`

Carrega apenas `paths.php`.

O bootstrap nao:

- inicia sessao;
- altera headers;
- carrega autenticacao;
- abre banco;
- toca em secrets;
- executa scripts;
- chama comandos Bind;
- inclui paginas legadas automaticamente.

### `app/Support/legacy.php`

Fornece helpers simples de resolucao de caminho:

- `panel_include_path()`
- `panel_root_path()`

Essas funcoes retornam strings de caminho. Elas nao executam `require`, nao fazem carregamento automatico e nao alteram estado.

## Includes antigos continuam fonte da verdade

Os arquivos em `includes/` continuam sendo a fonte da verdade do runtime atual:

- `includes/auth.php`
- `includes/db.php`
- `includes/audit.php`
- `includes/security.php`
- `includes/users.php`
- `includes/dns_servers.php`
- `includes/dns_zones.php`

As paginas publicas continuam usando seus `require` atuais. A camada `app/Support/` ainda nao esta conectada ao fluxo de producao.

## Por que nenhuma pagina publica foi movida

Mover paginas publicas agora mudaria paths, sessao, includes, formularios, CSRF, links e possivelmente o document root.

Antes disso, e necessario:

- mapear dependencias;
- criar adaptadores compativeis;
- preservar URLs atuais;
- testar login/logout;
- testar formularios POST com CSRF;
- testar fluxos DNS sem aplicar mudancas operacionais;
- planejar rollback.

## Como isso prepara a migracao gradual

Esta camada permite que fases futuras comecem a usar constantes centralizadas de caminho sem alterar a estrutura atual.

Ordem segura sugerida:

1. Usar `paths.php` em novos codigos internos, sem tocar nas paginas antigas.
2. Criar adaptadores em `app/Support/` para leitura de configuracao e paths.
3. Migrar helpers puros primeiro.
4. Manter `includes/` como fachada temporaria.
5. Migrar modulos com efeitos colaterais somente depois de testes.
6. Conectar `public/` apenas no fim, quando rotas e compatibilidade estiverem prontas.

## Limites de seguranca

Esta camada nao deve ser usada para:

- carregar secrets;
- abrir o banco real automaticamente;
- iniciar sessao;
- executar comandos externos;
- chamar `rndc`, `named-checkconf`, `nft`, `ssh` ou `sudo`;
- alterar arquivos de zona;
- substituir `includes/auth.php` sem plano especifico.

Qualquer uso futuro deve ser feito em commits pequenos, com validacoes e rollback claro.
