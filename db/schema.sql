CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario TEXT NOT NULL,
    ip TEXT NOT NULL,
    acao TEXT NOT NULL,
    dominio TEXT,
    tipo_registro TEXT,
    nome_registro TEXT,
    valor_antigo TEXT,
    valor_novo TEXT,
    status TEXT NOT NULL DEFAULT 'OK',
    mensagem TEXT,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario TEXT NOT NULL COLLATE NOCASE UNIQUE,
            senha_hash TEXT NOT NULL,
            perfil TEXT NOT NULL DEFAULT 'moderador'
                CHECK (perfil IN ('administrador', 'moderador')),
            ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
            trocar_senha INTEGER NOT NULL DEFAULT 0 CHECK (trocar_senha IN (0, 1)),
            auth_version INTEGER NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
CREATE TABLE dns_servers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL COLLATE NOCASE UNIQUE,
            hostname TEXT NOT NULL COLLATE NOCASE UNIQUE,
            ip4 TEXT,
            ip6 TEXT,
            tipo TEXT NOT NULL DEFAULT 'slave'
                CHECK (tipo IN ('master', 'slave')),
            ativo INTEGER NOT NULL DEFAULT 1 CHECK (ativo IN (0, 1)),
            ssh_user TEXT NOT NULL DEFAULT 'dns-sync',
            ssh_port INTEGER NOT NULL DEFAULT 22
                CHECK (ssh_port BETWEEN 1 AND 65535),
            descricao TEXT,
            ultimo_status TEXT NOT NULL DEFAULT 'desconhecido'
                CHECK (ultimo_status IN ('desconhecido', 'online', 'offline')),
            ultima_verificacao DATETIME,
            ultima_mensagem TEXT,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        , agente_status TEXT NOT NULL DEFAULT 'desconhecido', bind_status TEXT NOT NULL DEFAULT 'desconhecido', zonas_slave INTEGER, modo_instalacao TEXT NOT NULL DEFAULT 'manual', admin_user TEXT, admin_auth TEXT NOT NULL DEFAULT 'senha', admin_secret TEXT, admin_sudo_secret TEXT, admin_secret_updated_at DATETIME, admin_key_secret TEXT);
CREATE TABLE dns_zone_inventory (server_key TEXT NOT NULL,server_id INTEGER,server_nome TEXT NOT NULL,server_role TEXT NOT NULL CHECK (server_role IN ('master', 'slave')),zone_name TEXT NOT NULL COLLATE NOCASE,zone_type TEXT NOT NULL,serial TEXT,file_path TEXT,masters TEXT,status TEXT NOT NULL DEFAULT 'ok',message TEXT,discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (server_key, zone_name));
CREATE TABLE dns_zone_inventory_status (server_key TEXT PRIMARY KEY,server_id INTEGER,server_nome TEXT NOT NULL,server_role TEXT NOT NULL CHECK (server_role IN ('master', 'slave')),total_zones INTEGER NOT NULL DEFAULT 0,last_ok INTEGER NOT NULL DEFAULT 0 CHECK (last_ok IN (0, 1)),last_error TEXT,checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE dns_zone_extra_ignores (server_key TEXT NOT NULL,zone_name TEXT NOT NULL COLLATE NOCASE,server_nome TEXT,slave_serial TEXT,note TEXT,ignored_by TEXT,ignored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (server_key, zone_name));
CREATE TABLE dns_zone_governance (server_key TEXT NOT NULL,zone_name TEXT NOT NULL COLLATE NOCASE,classification TEXT NOT NULL DEFAULT 'revisar' CHECK (classification IN ('legitima', 'ignorada', 'revisar')),zone_note TEXT,classified_by TEXT,classified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (server_key, zone_name));
CREATE TABLE dns_server_governance (server_key TEXT PRIMARY KEY,server_nome TEXT,note TEXT,updated_by TEXT,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE firewall_admin_access (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT NOT NULL CHECK (tipo IN ('IPv4', 'IPv6')),
        rede TEXT NOT NULL COLLATE NOCASE UNIQUE,
        descricao TEXT NOT NULL DEFAULT '',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
CREATE TABLE firewall_ports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        escopo TEXT NOT NULL CHECK (escopo IN ('admin', 'publica')),
        porta INTEGER NOT NULL CHECK (porta BETWEEN 1 AND 65535),
        protocolo TEXT NOT NULL CHECK (protocolo IN ('TCP', 'UDP', 'TCP/UDP')),
        servico TEXT NOT NULL DEFAULT '',
        descricao TEXT NOT NULL DEFAULT '',
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (escopo, porta)
    );
CREATE TABLE firewall_meta (
        chave TEXT PRIMARY KEY,
        valor TEXT NOT NULL
    );
