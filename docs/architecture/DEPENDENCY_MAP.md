# Mapa de dependencias - Painel Bind

## Escopo

Mapa estatico das dependencias internas antes de qualquer migracao de arquivos.

Esta fase foi read-only para codigo, banco, secrets e Bind. Nenhum PHP foi movido, nenhuma logica DNS foi alterada e nenhum script operacional foi executado.

## Mapa de includes

### Dependencias internas entre includes

| Modulo | Depende de | Papel |
|---|---|---|
| `includes/auth.php` | `includes/users.php` | Sessao, timeout, usuario ativo, troca obrigatoria de senha e fallback quando SQLite esta indisponivel. |
| `includes/users.php` | `includes/db.php` | CRUD/validacao de usuarios e perfis. |
| `includes/audit.php` | `includes/db.php` | Registro de auditoria em SQLite. |
| `includes/setup_db.php` | `includes/db.php` | Inicializacao de tabelas historicas. |
| `includes/dns_servers.php` | `includes/db.php` | Cadastro de NS, credenciais, SSH, agente remoto e comandos operacionais. |
| `includes/dns_zones.php` | `includes/db.php`, `includes/dns_servers.php` | Inventario, comparacao, governanca e sincronizacao de zonas entre master/slaves. |
| `includes/teste_audit.php` | `includes/audit.php` | Teste historico de auditoria. |

### PHPs publicos que incluem `includes/auth.php`

Exigem sessao autenticada:

- `acl.php`
- `acl6.php`
- `alterar-senha.php`
- `auditoria.php`
- `bind.php`
- `dashboard.php`
- `delete-ptr.php`
- `delete-record.php`
- `dns-servers.php`
- `dns-zones.php`
- `domains.php`
- `edit-ptr.php`
- `edit-record.php`
- `edit-reverse-zone.php`
- `edit-zone.php`
- `fail2ban-bind.php`
- `fail2ban.php`
- `firewall.php`
- `index.php`
- `logs.php`
- `reverse-zones.php`
- `security.php`
- `services.php`
- `ssh.php`
- `usuarios.php`
- `zones.php`

Observacoes:

- `login.php` nao inclui `auth.php`; usa `includes/users.php`, `includes/security.php` e `includes/audit.php`.
- `logout.php` inclui apenas `includes/audit.php`.
- `servidores-dns.php` e wrapper de `dns-servers.php`.

### PHPs que incluem `includes/db.php` diretamente

- `dashboard.php`
- `auditoria.php`
- `services.php`
- includes que dependem do banco: `audit.php`, `users.php`, `setup_db.php`, `dns_servers.php`, `dns_zones.php`

Outras paginas acessam banco indiretamente por `users`, `audit`, `dns_servers`, `dns_zones` ou funcoes locais.

### PHPs que incluem `includes/audit.php`

- `alterar-senha.php`
- `delete-ptr.php`
- `delete-record.php`
- `dns-servers.php`
- `dns-zones.php`
- `domains.php`
- `edit-ptr.php`
- `edit-record.php`
- `edit-reverse-zone.php`
- `edit-zone.php`
- `firewall.php`
- `login.php`
- `logout.php`
- `reverse-zones.php`
- `services.php`
- `usuarios.php`
- `zones.php`

### PHPs que incluem `includes/dns_servers.php`

- `dashboard.php`
- `dns-servers.php`
- `includes/dns_zones.php`

`dns-servers.php` e o principal consumidor operacional. `dashboard.php` usa resumo/listagem. `includes/dns_zones.php` usa servidores para inventario remoto e sincronizacao.

### PHPs que incluem `includes/dns_zones.php`

- `dashboard.php`
- `dns-servers.php`
- `zones.php`

`zones.php` e a tela principal de inventario/comparacao. `dns-servers.php` usa inventario para contexto de servidores. `dashboard.php` usa resumo.

### Demais includes frequentes

- `config.php`: usado por paginas DNS e dashboard para sessao/listagem de dominios em `/var/cache/bind/master-aut/*.hosts`.
- `includes/security.php`: CSRF, validadores, escrita segura, validacao de zona e reload DNS.
- `includes/footer.php`: fechamento visual/HTML comum.
- `includes/session-timeout.php`: script de timeout de sessao em telas autenticadas.

## Mapa de paginas publicas

| Pagina | Includes principais | Responsabilidade | Dependencias de risco |
|---|---|---|---|
| `dashboard.php` | `config.php`, `auth.php`, `db.php`, `dns_zones.php`, `dns_servers.php` | Painel resumo, cards, atividade recente e atalhos. | Consulta SQLite e resume DNS servers/zones. |
| `domains.php` | `config.php`, `auth.php`, `security.php`, `audit.php` | Criacao/remocao de dominios forward e reversos. | Escreve configs/zonas, chama `named-checkconf` e `reload_dns()`. |
| `dns-zones.php` | `config.php`, `auth.php`, `security.php`, `audit.php` | Gestao direta de blocos de zona e reversos. | Escreve arquivos/configs, roda `named-checkconf -z`, chama `reload_dns()`. |
| `zones.php` | `config.php`, `auth.php`, `security.php`, `audit.php`, `dns_zones.php` | Inventario DNS, comparacao master/slaves, extras, sincronizacao de ausentes. | Pode acionar inventario remoto e criacao de zonas ausentes via `dns_zones_*`. |
| `dns-servers.php` | `auth.php`, `security.php`, `audit.php`, `users.php`, `dns_servers.php`, `dns_zones.php` | Cadastro de NS, credenciais, agente remoto, diagnosticos e manutencao. | Admin-only; usa SSH, sudo remoto, credenciais, comandos de agente e migracao. |
| `firewall.php` | `auth.php`, `security.php`, `audit.php` | Gestao de ACLs/portas/firewall. | Usa SQLite e `proc_open` para validacao/aplicacao firewall. |
| `services.php` | `config.php`, `auth.php`, `security.php`, `audit.php`, `db.php` | Status e acoes de servicos. | Usa `exec` para comandos de status/acao de servicos. |
| `usuarios.php` | `auth.php`, `security.php`, `audit.php`, `users.php` | Administracao de usuarios. | Admin-only; altera usuarios, senhas e perfis no SQLite. |
| `login.php` | `users.php`, `security.php`, `audit.php` | Login, sessao, auditoria de sucesso/falha. | Le banco de usuarios e registra auditoria. |
| `logout.php` | `audit.php` | Registra logout e encerra sessao. | Grava auditoria. |

## Mapa de funcoes por modulo

### Autenticacao - `includes/auth.php`

Responsabilidades:

- iniciar sessao quando necessario;
- aplicar headers de cache;
- exigir `$_SESSION['logado']`;
- resolver usuario atual via `includes/users.php`;
- invalidar sessao quando usuario esta inativo ou `auth_version` diverge;
- aplicar timeout de 900 segundos;
- redirecionar para `alterar-senha.php` quando troca de senha e obrigatoria.

Dependencias:

- `usuario_por_login()`
- `usuario_por_id()`
- SQLite via `includes/users.php`

### Banco - `includes/db.php`

Funcoes:

- `db_conectar(int $flags = 0): PDO`
- `db(): PDO`
- `db_leitura(): PDO`

Responsabilidades:

- conectar em `db/painel_dns.sqlite`;
- configurar `PDO::ATTR_ERRMODE`;
- configurar timeout/busy timeout;
- prover conexao read-only quando suportado.

### Auditoria - `includes/audit.php`

Funcoes principais:

- `audit_usuario_atual()`
- `audit_ip_atual()`
- `audit_dominio_base()`
- `audit_ptr_ipv6_para_endereco()`
- `audit_valor_ptr()`
- `registrar_auditoria()`

Responsabilidades:

- normalizar usuario/IP;
- normalizar dominio base;
- registrar eventos em `audit_logs`;
- padronizar valores para criacao/remocao.

### DNS zones - `includes/dns_zones.php`

Funcoes principais:

- schema: `dns_zones_garantir_esquema()`;
- chaves de servidor: `dns_zones_server_key_local()`, `dns_zones_server_key()`;
- inventario: `dns_zones_exec_local()`, `dns_zones_parse_saida()`, `dns_zones_salvar_inventario()`, `dns_zones_atualizar_local()`, `dns_zones_atualizar_remoto()`, `dns_zones_atualizar_todos()`;
- sincronizacao: `dns_zones_sync_zona_ausente()`, `dns_zones_sync_todas_ausentes()`;
- consulta/comparacao: `dns_zones_status_servidores()`, `dns_zones_inventario()`, `dns_zones_comparar()`, `dns_zones_resumo()`, `dns_zones_resumo_classificacao()`;
- governanca: `dns_zones_marcar_extra_ignorada()`, `dns_zones_remover_extra_ignorada()`, `dns_zones_extras_por_servidor()`;
- auditoria/status: `dns_zones_eventos_auditoria()`, `dns_zones_status_falha_coleta()`.

Dependencias:

- SQLite via `db()`;
- DNS servers via `includes/dns_servers.php`;
- script local `scripts/ns2/dns-zone-inventory.sh`;
- comandos remotos via funcoes de DNS servers.

### DNS servers - `includes/dns_servers.php`

Funcoes principais:

- schema/listagem: `dns_servers_garantir_esquema()`, `dns_servers_listar()`;
- credenciais: `dns_servers_credential_key()`, `dns_servers_encrypt_secret()`, `dns_servers_decrypt_secret()`, `dns_server_salvar_credencial_admin()`, `dns_server_senha_admin_salva()`;
- validacao: `dns_server_nome_valido()`, `dns_server_hostname_valido()`, `dns_server_ssh_user_valido()`, `dns_server_zone_name_valido()`, `dns_server_validar_dados()`, `dns_server_validar_bootstrap()`;
- execucao: `dns_server_exec()`, `dns_server_admin_ssh_args()`, `dns_server_admin_scp_args()`, `dns_server_admin_env()`;
- agente: `dns_server_preparar_chave_local()`, `dns_server_bootstrap()`, `dns_server_remover_agente()`, `dns_server_instalar_agente()`;
- testes/comandos: `dns_server_executar_teste()`, `dns_server_executar_comando_zona()`, `dns_server_parse_status_saida()`;
- status: `dns_server_status_derivado()`, `dns_server_registrar_status()`.

Dependencias:

- SQLite;
- secret local ignorado: `db/dns_servers.secret`;
- chave SSH local: `/var/www/.ssh/id_ed25519_dns_sync`;
- known hosts: `/var/www/.ssh/known_hosts`;
- scripts `scripts/ns2/*`;
- `ssh`, `scp`, `sudo` remoto e comandos do agente.

### Usuarios - `includes/users.php`

Funcoes principais:

- `usuarios_garantir_esquema()`
- `usuario_por_login()`
- `usuario_por_id()`
- `usuario_nome_valido()`
- `usuario_senha_valida()`
- `usuario_perfil_valido()`
- `usuario_perfil_legivel()`
- `usuario_eh_administrador()`
- `exigir_administrador()`
- `total_administradores_ativos()`

Responsabilidades:

- garantir schema de usuarios;
- buscar usuario por login/id;
- validar nome, senha e perfil;
- controlar autorizacao de administrador.

### CSRF/seguranca - `includes/security.php`

Funcoes principais:

- `csrf_token()`
- `csrf_field()`
- `require_csrf()`
- `valid_domain()`
- `valid_hostname()`
- `valid_cidr()`
- `increment_zone_serial()`
- `validate_zone_content()`
- `write_file_safely()`
- `reload_dns()`
- `bind_zone_name_for_file()`
- `ipv6_ptr_owner()`
- `ipv4_ptr_owner()`
- `format_forward_zone_content()`
- `normalize_dns_value()`

Ponto critico:

- `reload_dns()` executa `sudo /usr/sbin/rndc reload`.
- `validate_zone_content()` usa comando externo para validar conteudo de zona.
- `write_file_safely()` altera arquivos em disco quando chamado por paginas operacionais.

## Scripts operacionais

### `scripts/`

| Script | Papel | Comandos sensiveis encontrados |
|---|---|---|
| `scripts/add-domain-full.sh` | Criacao completa de dominio/zona forward/reversa. | `named-checkzone`, `rndc reload`. |
| `scripts/install-dns-painel.sh` | Instalacao historica do painel. | `apt`, `sudo rndc reload`, criacao de sudoers, `systemctl restart apache2`. |
| `scripts/painel-firewall-validar` | Validacao de regras firewall. | `/usr/sbin/nft`. |

### `scripts/ns2/`

| Script | Papel | Comandos sensiveis encontrados |
|---|---|---|
| `dns-zone-inventory.sh` | Inventario de zonas via `named-checkconf -p` e consultas SOA. | `named-checkconf`, `dig`. |
| `dns-slave-status.sh` | Status BIND/slave remoto. | `systemctl is-active/is-enabled bind9`, `named-checkconf`. |
| `dns-slave-reload.sh` | Reconfig/reload remoto do slave. | `named-checkconf`, `rndc reconfig`/`reload`. |
| `dns-slave-add-zone.sh` | Adiciona bloco slave em `named.conf.local`. | `named-checkconf`, `rndc reconfig`. |
| `dns-slave-remove-zone.sh` | Remove bloco slave gerenciado. | `named-checkconf`, `rndc reconfig`. |
| `dns-slave-migrate-layout.sh` | Migra layout legado de slave. | backup de config, `named-checkconf`, `rndc reconfig`. |
| `dns-slave-check-transfer.sh` | Testa transferencia/estado de zona. | `rndc zonestatus`, `dig`. |
| `dns-sync-command.sh` | Whitelist de comandos remotos permitidos. | `sudo -n /usr/local/bin/dns-*`. |
| `install-ns2-agent.sh` | Instala/atualiza agente remoto. | `ssh`, `scp`, `sudo -n`, instalacao em `/usr/local/bin`, validacao de sudoers. |
| `test-ns2-agent-final.sh` | Teste final do agente. | `ssh`, `sudo -u www-data`, `sudo -n`. |
| `dns-sync.sudoers` | Politica sudo do usuario `dns-sync`. | Permite scripts especificos como root. |
| `dns-sync-bootstrap.sudoers` | Politica temporaria de bootstrap. | Permissoes limitadas para instalacao/bootstrap. |
| `named.conf.slaves` | Config legado/exemplo de slave. | Arquivo de configuracao Bind. |

## Pontos de risco

- `includes/security.php::reload_dns()` e chamado por fluxos de dominios/zonas e executa `rndc reload`.
- `domains.php` e `dns-zones.php` escrevem arquivos/configs e validam com `named-checkconf`; sao fluxos operacionais diretos.
- `zones.php` pode sincronizar zonas ausentes em slaves via `dns_zones_sync_*`, que chega aos comandos remotos de `dns_servers`.
- `dns-servers.php` e admin-only, mas concentra credenciais, bootstrap, instalacao/remocao de agente, migracao de layout, testes SSH/BIND/AXFR e remocao de servidores do painel.
- `includes/dns_servers.php` manipula chave/secret local e credenciais criptografadas; nunca migrar sem plano de storage/secrets.
- Scripts `scripts/ns2/*` alteram config Bind remota e podem acionar `rndc reconfig/reload`.
- `scripts/install-dns-painel.sh` contem comportamento historico de instalacao e sudoers; nao deve ser reutilizado sem revisao.
- `acl.php`, `acl6.php`, `firewall.php` e `services.php` usam comandos externos para firewall/servicos.
- `logs.php`, `bind.php`, `ssh.php`, `fail2ban.php`, `fail2ban-bind.php` usam `shell_exec` para leitura de logs/status.
- `config.php` lista zonas reais em `/var/cache/bind/master-aut/*.hosts`; qualquer migracao de paths deve preservar compatibilidade.

## Ordem segura sugerida para futura migracao

1. **Documentar contratos antes de mover codigo**
   - Registrar entradas, saidas e efeitos colaterais das funcoes em `includes/security.php`, `includes/dns_servers.php` e `includes/dns_zones.php`.

2. **Criar adaptadores sem mudar chamadas atuais**
   - Introduzir wrappers em `app/Support/` para paths, comando shell e banco.
   - Manter `includes/*` como fachada temporaria.

3. **Migrar modulos sem efeitos colaterais primeiro**
   - Validadores de dominio/hostname/CIDR.
   - Formatadores de zona e normalizadores.
   - Helpers de auditoria sem escrita.

4. **Migrar banco com cautela**
   - Encapsular `db()` e `db_leitura()` antes de mover usuarios/auditoria.
   - Nao mover banco real; apenas configurar caminho futuro por ambiente.

5. **Migrar usuarios/autenticacao**
   - Separar validacao, repositorio de usuarios e controle de sessao.
   - Preservar `auth_version`, `trocar_senha` e regra de administrador.

6. **Migrar auditoria**
   - Manter schema e payloads atuais.
   - Garantir que falhas operacionais continuem registradas.

7. **Migrar DNS zones em duas partes**
   - Primeiro parsing/comparacao/inventario.
   - Depois sincronizacao e comandos remotos.

8. **Migrar DNS servers por camadas**
   - Primeiro validadores e DTOs.
   - Depois criptografia/credenciais.
   - Depois SSH/SCP/proc_open.
   - Por ultimo bootstrap, instalacao/remocao de agente e comandos de zona.

9. **Migrar paginas publicas para controllers**
   - Manter URLs atuais ate `public/` estar pronto.
   - Evitar trocar document root ate todos os includes/paths estarem cobertos.

10. **Somente no fim preparar `public/` como document root**
    - Criar roteamento compatível.
    - Testar login, CSRF, auditoria, DNS, firewall e servicos.
    - Planejar rollback antes de mudar Apache/Nginx.

## Validacoes desta fase

- Mapeamento feito por leitura estatica de `require/include`, funcoes declaradas e comandos sensiveis.
- Nenhum script operacional foi executado.
- Nenhum reload/restart do Bind foi executado.
- Nenhum arquivo PHP foi alterado.
