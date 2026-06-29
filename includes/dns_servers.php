<?php

require_once __DIR__ . '/db.php';

const DNS_SERVER_SSH_KEY = '/var/www/.ssh/id_ed25519_dns_sync';
const DNS_SERVER_KNOWN_HOSTS = '/var/www/.ssh/known_hosts';
const DNS_SERVER_NS2_AGENT_DIR = '/var/www/html/painel/scripts/ns2';
const DNS_SERVER_SYNC_USER = 'dns-sync';

function dns_servers_secret_path(): string
{
    $storagePath = __DIR__ . '/../storage/secrets/dns_servers.secret';
    if (is_file($storagePath) || is_dir(dirname($storagePath))) {
        return $storagePath;
    }

    return __DIR__ . '/../db/dns_servers.secret';
}

function dns_servers_garantir_esquema(): void
{
    static $pronto = false;

    if ($pronto) {
        return;
    }

    db()->exec("
        CREATE TABLE IF NOT EXISTS dns_servers (
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
            modo_instalacao TEXT NOT NULL DEFAULT 'manual',
            admin_user TEXT,
            admin_auth TEXT NOT NULL DEFAULT 'senha' CHECK (admin_auth IN ('senha', 'chave')),
            admin_secret TEXT,
            admin_sudo_secret TEXT,
            admin_key_secret TEXT,
            admin_secret_updated_at DATETIME,
            agente_status TEXT NOT NULL DEFAULT 'desconhecido',
            bind_status TEXT NOT NULL DEFAULT 'desconhecido',
            zonas_slave INTEGER,
            ultimo_status TEXT NOT NULL DEFAULT 'desconhecido'
                CHECK (ultimo_status IN ('desconhecido', 'online', 'offline')),
            ultima_verificacao DATETIME,
            ultima_mensagem TEXT,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $colunas = db()->query('PRAGMA table_info(dns_servers)')->fetchAll(PDO::FETCH_ASSOC);
    $nomes = array_column($colunas, 'name');

    if (!in_array('ultimo_status', $nomes, true)) {
        db()->exec("ALTER TABLE dns_servers ADD COLUMN ultimo_status TEXT NOT NULL DEFAULT 'desconhecido'");
    }
    if (!in_array('agente_status', $nomes, true)) {
        db()->exec("ALTER TABLE dns_servers ADD COLUMN agente_status TEXT NOT NULL DEFAULT 'desconhecido'");
    }
    if (!in_array('bind_status', $nomes, true)) {
        db()->exec("ALTER TABLE dns_servers ADD COLUMN bind_status TEXT NOT NULL DEFAULT 'desconhecido'");
    }
    if (!in_array('zonas_slave', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN zonas_slave INTEGER');
    }
    if (!in_array('ultima_verificacao', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN ultima_verificacao DATETIME');
    }
    if (!in_array('ultima_mensagem', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN ultima_mensagem TEXT');
    }
    if (!in_array('modo_instalacao', $nomes, true)) {
        db()->exec("ALTER TABLE dns_servers ADD COLUMN modo_instalacao TEXT NOT NULL DEFAULT 'manual'");
    }
    if (!in_array('admin_user', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN admin_user TEXT');
    }
    if (!in_array('admin_auth', $nomes, true)) {
        db()->exec("ALTER TABLE dns_servers ADD COLUMN admin_auth TEXT NOT NULL DEFAULT 'senha'");
    }
    if (!in_array('admin_secret', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN admin_secret TEXT');
    }
    if (!in_array('admin_sudo_secret', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN admin_sudo_secret TEXT');
    }
    if (!in_array('admin_key_secret', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN admin_key_secret TEXT');
    }
    if (!in_array('admin_secret_updated_at', $nomes, true)) {
        db()->exec('ALTER TABLE dns_servers ADD COLUMN admin_secret_updated_at DATETIME');
    }

    $pronto = true;
}

function dns_servers_listar(): array
{
    dns_servers_garantir_esquema();

    return db()
        ->query("SELECT * FROM dns_servers ORDER BY ativo DESC, nome COLLATE NOCASE")
        ->fetchAll(PDO::FETCH_ASSOC);
}

function dns_servers_credential_key(): string
{
    $secretKeyFile = dns_servers_secret_path();
    $dir = dirname($secretKeyFile);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Nao foi possivel criar diretorio da chave de credenciais.');
    }

    if (!is_file($secretKeyFile)) {
        $key = random_bytes(32);
        if (file_put_contents($secretKeyFile, base64_encode($key)) === false) {
            throw new RuntimeException('Nao foi possivel gravar chave de credenciais.');
        }
        @chmod($secretKeyFile, 0640);
    }

    $raw = base64_decode(trim((string) file_get_contents($secretKeyFile)), true);
    if (!is_string($raw) || strlen($raw) !== 32) {
        throw new RuntimeException('Chave de credenciais invalida.');
    }

    return $raw;
}

function dns_servers_encrypt_secret(string $valor): string
{
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL indisponivel para criptografar credenciais.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($valor, 'aes-256-gcm', dns_servers_credential_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($ciphertext) || $tag === '') {
        throw new RuntimeException('Falha ao criptografar credencial.');
    }

    return base64_encode(json_encode([
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'data' => base64_encode($ciphertext),
    ], JSON_THROW_ON_ERROR));
}

function dns_servers_decrypt_secret(?string $payload): string
{
    if ($payload === null || trim($payload) === '') {
        return '';
    }
    if (!function_exists('openssl_decrypt')) {
        throw new RuntimeException('OpenSSL indisponivel para ler credenciais salvas.');
    }

    $json = base64_decode($payload, true);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data) || (int) ($data['v'] ?? 0) !== 1) {
        throw new RuntimeException('Credencial salva invalida.');
    }

    $iv = base64_decode((string) ($data['iv'] ?? ''), true);
    $tag = base64_decode((string) ($data['tag'] ?? ''), true);
    $ciphertext = base64_decode((string) ($data['data'] ?? ''), true);
    if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext)) {
        throw new RuntimeException('Credencial salva corrompida.');
    }

    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', dns_servers_credential_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain)) {
        throw new RuntimeException('Falha ao descriptografar credencial salva.');
    }

    return $plain;
}

function dns_server_tem_credencial_admin(array $servidor): bool
{
    return !empty($servidor['admin_secret'])
        || !empty($servidor['admin_sudo_secret'])
        || !empty($servidor['admin_key_secret']);
}

function dns_server_tem_senha_admin(array $servidor): bool
{
    if (empty($servidor['admin_secret'])) {
        return false;
    }

    return !(($servidor['admin_auth'] ?? 'senha') === 'chave' && empty($servidor['admin_key_secret']));
}

function dns_server_senha_admin_salva(array $servidor): string
{
    if (!dns_server_tem_senha_admin($servidor)) {
        return '';
    }

    return dns_servers_decrypt_secret((string) $servidor['admin_secret']);
}

function dns_server_admin_payload(array $servidor, array $post): array
{
    $adminUser = strtolower(trim((string) ($post['admin_user'] ?? $servidor['admin_user'] ?? 'root')));
    $auth = (string) ($post['admin_auth'] ?? $servidor['admin_auth'] ?? 'senha');
    $password = (string) ($post['admin_password'] ?? '');
    $key = trim((string) ($post['admin_key'] ?? ''));
    $sudoPassword = (string) ($post['sudo_password'] ?? '');

    if ($password === '' && dns_server_tem_senha_admin($servidor)) {
        $password = dns_server_senha_admin_salva($servidor);
    }
    if ($key === '' && !empty($servidor['admin_key_secret'])) {
        $key = dns_servers_decrypt_secret((string) $servidor['admin_key_secret']);
    }
    if ($key === '' && $auth === 'chave' && !empty($servidor['admin_secret']) && empty($servidor['admin_key_secret'])) {
        $key = dns_servers_decrypt_secret((string) $servidor['admin_secret']);
    }
    if ($sudoPassword === '' && !empty($servidor['admin_sudo_secret'])) {
        $sudoPassword = dns_servers_decrypt_secret((string) $servidor['admin_sudo_secret']);
    }
    $sudoPasswordAlt = ($sudoPassword !== '' && $sudoPassword !== $password) ? $sudoPassword : '';

    return [
        'modo_instalacao' => 'adotar',
        'admin_user' => $adminUser,
        'admin_auth' => $auth,
        'admin_password' => $password,
        'sudo_password' => $password,
        'sudo_password_alt' => $sudoPasswordAlt,
        'admin_key' => $key,
    ];
}

function dns_server_salvar_credencial_admin(int $serverId, array $post, ?array $servidorAtual = null, bool $exigirCredencial = false): void
{
    $adminUser = strtolower(trim((string) ($post['admin_user'] ?? $servidorAtual['admin_user'] ?? 'root')));
    $auth = (string) ($post['admin_auth'] ?? $servidorAtual['admin_auth'] ?? 'senha');
    $password = (string) ($post['admin_password'] ?? '');
    $key = trim((string) ($post['admin_key'] ?? ''));
    $sudoPassword = (string) ($post['sudo_password'] ?? '');

    if (!dns_server_ssh_user_valido($adminUser)) {
        throw new RuntimeException('Usuario administrativo invalido para salvar credencial.');
    }
    if (!in_array($auth, ['senha', 'chave'], true)) {
        throw new RuntimeException('Metodo de autenticacao invalido para salvar credencial.');
    }

    $secretPayload = $password !== ''
        ? dns_servers_encrypt_secret($password)
        : ($servidorAtual['admin_secret'] ?? null);
    $keyPayload = $key !== ''
        ? dns_servers_encrypt_secret($key)
        : ($servidorAtual['admin_key_secret'] ?? null);
    $sudoPayload = $sudoPassword !== ''
        ? dns_servers_encrypt_secret($sudoPassword)
        : ($servidorAtual['admin_sudo_secret'] ?? null);

    if ($auth === 'chave' && $keyPayload === null && !empty($servidorAtual['admin_secret']) && empty($servidorAtual['admin_key_secret']) && $password === '') {
        $secretPayload = $servidorAtual['admin_secret'];
    }

    if ($exigirCredencial) {
        $temSenha = $secretPayload !== null && $secretPayload !== '';
        $temChave = $keyPayload !== null && $keyPayload !== '';
        if (($auth === 'senha' && !$temSenha) || ($auth === 'chave' && !$temChave)) {
            throw new RuntimeException('Informe a senha/chave administrativa para salvar credenciais.');
        }
    }

    $stmt = db()->prepare(""
        . "UPDATE dns_servers SET "
        . "admin_user = :admin_user, "
        . "admin_auth = :admin_auth, "
        . "admin_secret = :admin_secret, "
        . "admin_sudo_secret = :admin_sudo_secret, "
        . "admin_key_secret = :admin_key_secret, "
        . "admin_secret_updated_at = CURRENT_TIMESTAMP, "
        . "atualizado_em = CURRENT_TIMESTAMP "
        . "WHERE id = :id"
    );
    $stmt->execute([
        ':admin_user' => $adminUser,
        ':admin_auth' => $auth,
        ':admin_secret' => $secretPayload,
        ':admin_sudo_secret' => $sudoPayload,
        ':admin_key_secret' => $keyPayload,
        ':id' => $serverId,
    ]);
}


function dns_server_por_id(int $id): ?array
{
    dns_servers_garantir_esquema();
    $stmt = db()->prepare('SELECT * FROM dns_servers WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    return $registro ?: null;
}

function dns_server_nome_valido(string $nome): bool
{
    return preg_match('/^[A-Za-z0-9][A-Za-z0-9 ._-]{1,39}$/', $nome) === 1;
}

function dns_server_hostname_valido(string $hostname): bool
{
    if (strlen($hostname) > 253) {
        return false;
    }

    return preg_match(
        '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*' .
        '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
        $hostname
    ) === 1;
}

function dns_server_ssh_user_valido(string $usuario): bool
{
    return preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $usuario) === 1;
}

function dns_server_zone_name_valido(string $zona): bool
{
    if (strlen($zona) > 253) {
        return false;
    }

    return preg_match(
        '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
        $zona
    ) === 1;
}

function dns_server_validar_dados(array $dados): array
{
    $nome = trim((string) ($dados['nome'] ?? ''));
    $hostname = strtolower(rtrim(trim((string) ($dados['hostname'] ?? '')), '.'));
    $ip4 = trim((string) ($dados['ip4'] ?? ''));
    $ip6 = trim((string) ($dados['ip6'] ?? ''));
    $tipo = (string) ($dados['tipo'] ?? 'slave');
    $ativo = !empty($dados['ativo']) ? 1 : 0;
    $sshUser = strtolower(trim((string) ($dados['ssh_user'] ?? 'dns-sync')));
    $sshPort = filter_var($dados['ssh_port'] ?? 22, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $descricao = trim((string) ($dados['descricao'] ?? ''));

    if (!dns_server_nome_valido($nome)) {
        throw new RuntimeException('Nome inválido. Use de 2 a 40 caracteres simples.');
    }
    if (!dns_server_hostname_valido($hostname) && !filter_var($hostname, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('Hostname/IP inválido.');
    }
    if ($ip4 !== '' && !filter_var($ip4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('IPv4 inválido.');
    }
    if ($ip6 !== '' && !filter_var($ip6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        throw new RuntimeException('IPv6 inválido.');
    }
    if ($ip4 === '' && $ip6 === '') {
        throw new RuntimeException('Informe ao menos um endereço IP do servidor.');
    }
    if (!in_array($tipo, ['master', 'slave'], true)) {
        throw new RuntimeException('Tipo de servidor inválido.');
    }
    if (!dns_server_ssh_user_valido($sshUser)) {
        throw new RuntimeException('Usuário SSH inválido.');
    }
    if ($sshPort === false) {
        throw new RuntimeException('Porta SSH inválida.');
    }
    if (strlen($descricao) > 500) {
        throw new RuntimeException('A descrição deve ter no máximo 500 caracteres.');
    }

    return [
        'nome' => $nome,
        'hostname' => $hostname,
        'ip4' => $ip4 !== '' ? $ip4 : null,
        'ip6' => $ip6 !== '' ? $ip6 : null,
        'tipo' => $tipo,
        'ativo' => $ativo,
        'ssh_user' => $sshUser,
        'ssh_port' => (int) $sshPort,
        'descricao' => $descricao !== '' ? $descricao : null,
    ];
}

function dns_server_resumo(array $servidor): string
{
    $enderecos = array_filter([
        $servidor['hostname'] ?? null,
        $servidor['ip4'] ?? null,
        $servidor['ip6'] ?? null,
    ]);

    return implode(' / ', [
        (string) ($servidor['nome'] ?? 'Servidor DNS'),
        (string) ($servidor['tipo'] ?? 'slave'),
        implode(', ', $enderecos),
        'SSH ' . (string) ($servidor['ssh_user'] ?? 'dns-sync') . ':' .
            (string) ($servidor['ssh_port'] ?? 22),
        !empty($servidor['ativo']) ? 'ativo' : 'inativo',
    ]);
}

function dns_server_destino(array $servidor): string
{
    $host = trim((string) ($servidor['hostname'] ?? ''));

    if ($host === '') {
        $host = trim((string) ($servidor['ip4'] ?? ''));
    }
    if ($host === '') {
        $host = trim((string) ($servidor['ip6'] ?? ''));
    }

    return (string) $servidor['ssh_user'] . '@' . $host;
}


function dns_server_host_alvo(array $servidor): string
{
    $host = trim((string) ($servidor['hostname'] ?? ''));

    if ($host === '') {
        $host = trim((string) ($servidor['ip4'] ?? ''));
    }
    if ($host === '') {
        $host = trim((string) ($servidor['ip6'] ?? ''));
    }
    if ($host === '') {
        throw new RuntimeException('Hostname/IP do servidor indisponível.');
    }

    return $host;
}

function dns_server_exec(array $argumentos, int $timeout = 30, array $env = [], ?string $stdin = null): array
{
    $cmd = array_merge(['/usr/bin/timeout', (string) $timeout], $argumentos);
    $descritores = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $inicio = microtime(true);
    $processo = proc_open($cmd, $descritores, $pipes, null, $env ?: null);

    if (!is_resource($processo)) {
        throw new RuntimeException('Não foi possível iniciar processo local.');
    }

    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($processo);
    $texto = trim((string) $stdout . ((string) $stderr !== '' ? "\n" . (string) $stderr : ''));

    if (strlen($texto) > 6000) {
        $texto = substr($texto, 0, 6000) . "\n[saída truncada]";
    }

    return [
        'ok' => $codigo === 0,
        'codigo' => $codigo,
        'saida' => $texto !== '' ? $texto : 'Sem saída.',
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ];
}

function dns_server_preparar_chave_local(): string
{
    $dir = dirname(DNS_SERVER_SSH_KEY);

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Não foi possível criar diretório SSH local.');
    }
    @chmod($dir, 0700);

    if (!is_file(DNS_SERVER_SSH_KEY)) {
        $resultado = dns_server_exec([
            '/usr/bin/ssh-keygen',
            '-q',
            '-t',
            'ed25519',
            '-N',
            '',
            '-f',
            DNS_SERVER_SSH_KEY,
        ], 20);

        if (!$resultado['ok']) {
            throw new RuntimeException('Falha ao gerar chave SSH do painel: ' . $resultado['saida']);
        }
    }

    @chmod(DNS_SERVER_SSH_KEY, 0600);
    @chmod(DNS_SERVER_SSH_KEY . '.pub', 0644);

    $pub = @file_get_contents(DNS_SERVER_SSH_KEY . '.pub');
    if (!is_string($pub) || trim($pub) === '') {
        throw new RuntimeException('Chave pública SSH do painel indisponível.');
    }

    if (!is_file(DNS_SERVER_KNOWN_HOSTS)) {
        @touch(DNS_SERVER_KNOWN_HOSTS);
    }
    @chmod(DNS_SERVER_KNOWN_HOSTS, 0644);

    return trim($pub);
}

function dns_server_validar_bootstrap(array $post): array
{
    $dados = dns_server_validar_dados($post);
    $modo = (string) ($post['modo_instalacao'] ?? 'adotar');
    $auth = (string) ($post['admin_auth'] ?? 'senha');
    $adminUser = strtolower(trim((string) ($post['admin_user'] ?? 'root')));
    $adminPort = filter_var($post['ssh_port'] ?? 22, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    $adminPassword = (string) ($post['admin_password'] ?? '');
    $adminKey = trim((string) ($post['admin_key'] ?? ''));
    $sudoPassword = (string) ($post['sudo_password'] ?? '');

    if (!in_array($modo, ['adotar', 'provisionar', 'provision_clean'], true)) {
        throw new RuntimeException('Modo de instalação inválido.');
    }
    if (!in_array($auth, ['senha', 'chave'], true)) {
        throw new RuntimeException('Método de autenticação inválido.');
    }
    if (!dns_server_ssh_user_valido($adminUser)) {
        throw new RuntimeException('Usuário administrativo inválido.');
    }
    if ($adminPort === false) {
        throw new RuntimeException('Porta SSH administrativa inválida.');
    }
    if ($auth === 'senha' && $adminPassword === '') {
        throw new RuntimeException('Informe a senha SSH administrativa.');
    }
    if ($auth === 'chave' && $adminKey === '') {
        throw new RuntimeException('Informe a chave privada SSH administrativa.');
    }

    $dados['ssh_user'] = DNS_SERVER_SYNC_USER;
    $dados['ssh_port'] = (int) $adminPort;
    $dados['modo_instalacao'] = $modo === 'provisionar' ? 'provisionado' : $modo;
    $dados['modo_bootstrap'] = $modo;

    return [
        'server' => $dados,
        'admin' => [
            'user' => $adminUser,
            'port' => (int) $adminPort,
            'auth' => $auth,
            'password' => $adminPassword,
            'key' => $adminKey,
            'sudo_password' => $adminPassword,
            'sudo_password_alt' => ($sudoPassword !== '' && $sudoPassword !== $adminPassword) ? $sudoPassword : '',
        ],
    ];
}

function dns_server_auth_temp_files(array $admin): array
{
    $arquivos = [];

    if ($admin['auth'] === 'senha') {
        $askpass = tempnam(sys_get_temp_dir(), 'dns-panel-askpass-');
        if ($askpass === false) {
            throw new RuntimeException('Não foi possível criar askpass temporário.');
        }
        file_put_contents($askpass, "#!/bin/sh\nprintf '%s\\n' \"\$DNS_PANEL_SSH_PASSWORD\"\n");
        chmod($askpass, 0700);
        $arquivos['askpass'] = $askpass;
    }

    if ($admin['auth'] === 'chave') {
        $key = tempnam(sys_get_temp_dir(), 'dns-panel-key-');
        if ($key === false) {
            throw new RuntimeException('Não foi possível criar chave temporária.');
        }
        file_put_contents($key, rtrim((string) $admin['key']) . "\n");
        chmod($key, 0600);
        $arquivos['key'] = $key;
    }

    return $arquivos;
}

function dns_server_admin_ssh_args(array $server, array $admin, array $temporarios): array
{
    $args = [
        '/usr/bin/setsid',
        '/usr/bin/ssh',
        '-F',
        '/dev/null',
        '-o',
        'StrictHostKeyChecking=accept-new',
        '-o',
        'UserKnownHostsFile=' . DNS_SERVER_KNOWN_HOSTS,
        '-o',
        'ConnectTimeout=8',
        '-p',
        (string) $admin['port'],
    ];

    if ($admin['auth'] === 'senha') {
        array_push($args, '-o', 'BatchMode=no', '-o', 'PreferredAuthentications=password,keyboard-interactive');
    } else {
        array_push($args, '-i', $temporarios['key'], '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes');
    }

    $args[] = $admin['user'] . '@' . dns_server_host_alvo($server);

    return $args;
}

function dns_server_admin_scp_args(array $server, array $admin, array $temporarios, array $origens, string $destino): array
{
    $args = [
        '/usr/bin/setsid',
        '/usr/bin/scp',
        '-F',
        '/dev/null',
        '-o',
        'StrictHostKeyChecking=accept-new',
        '-o',
        'UserKnownHostsFile=' . DNS_SERVER_KNOWN_HOSTS,
        '-o',
        'ConnectTimeout=8',
        '-P',
        (string) $admin['port'],
    ];

    if ($admin['auth'] === 'senha') {
        array_push($args, '-o', 'BatchMode=no', '-o', 'PreferredAuthentications=password,keyboard-interactive');
    } else {
        array_push($args, '-i', $temporarios['key'], '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes');
    }

    foreach ($origens as $origem) {
        $args[] = $origem;
    }

    $args[] = $admin['user'] . '@' . dns_server_host_alvo($server) . ':' . $destino;

    return $args;
}

function dns_server_admin_env(array $admin, array $temporarios): array
{
    if ($admin['auth'] !== 'senha') {
        return [];
    }

    return [
        'SSH_ASKPASS' => $temporarios['askpass'],
        'SSH_ASKPASS_REQUIRE' => 'force',
        'DISPLAY' => 'dns-panel:0',
        'DNS_PANEL_SSH_PASSWORD' => (string) $admin['password'],
    ];
}

function dns_server_remote_bootstrap_script(string $modo, string $publicKey): string
{
    $provisionar = in_array($modo, ['provisionar', 'provisionado', 'provision_clean'], true) ? '1' : '0';
    $pubArg = escapeshellarg($publicKey);

    return <<<SH
set -eu
PANEL_PROVISION=$provisionar
PANEL_PUBLIC_KEY=$pubArg
REMOTE_DIR="\${DNS_PANEL_REMOTE_DIR:?}"
SUDO_PASSWORD="\${DNS_PANEL_SUDO_PASSWORD:-}"
SUDO_PASSWORD_ALT="\${DNS_PANEL_SUDO_PASSWORD_ALT:-}"
ROOT_METHOD=''
ROOT_PASSWORD=''

quote_args() {
    quoted=''
    for arg in "\$@"; do
        safe=\$(printf '%s' "\$arg" | sed "s/'/'\\''/g")
        quoted="\${quoted}\${quoted:+ }'\${safe}'"
    done
    printf '%s' "\$quoted"
}

select_root_method() {
    [ -n "\$ROOT_METHOD" ] && return
    if [ "\$(id -u)" -eq 0 ]; then
        ROOT_METHOD='direct'
        return
    fi
    if command -v sudo >/dev/null 2>&1; then
        ROOT_METHOD='sudo'
        return
    fi
    if command -v su >/dev/null 2>&1; then
        for candidate in "\$SUDO_PASSWORD" "\$SUDO_PASSWORD_ALT"; do
            [ -n "\$candidate" ] || continue
            if printf '%s
' "\$candidate" | su - root -c 'true' >/dev/null 2>&1; then
                ROOT_METHOD='su'
                ROOT_PASSWORD="\$candidate"
                return
            fi
        done
    fi
    printf '%s
' 'ERRO: sudo não está instalado no servidor remoto. Use root ou forneça a senha root no campo sudo/root para continuar o provisionamento.' >&2
    exit 1
}

run_root() {
    select_root_method
    case "\$ROOT_METHOD" in
        direct) "\$@" ;;
        sudo)
            if sudo -n true >/dev/null 2>&1; then
                sudo -n "\$@"
            else
                for candidate in "\$SUDO_PASSWORD" "\$SUDO_PASSWORD_ALT"; do
                    [ -n "\$candidate" ] || continue
                    if printf '%s
' "\$candidate" | sudo -S -p '' -v >/dev/null 2>&1; then
                        printf '%s
' "\$candidate" | sudo -S -p '' "\$@"
                        return
                    fi
                done
                printf '%s
' 'ERRO: sudo requer senha valida e nenhuma senha sudo/root funcionou.' >&2
                exit 1
            fi
            ;;
        su)
            cmd=\$(quote_args "\$@")
            printf '%s
' "\$ROOT_PASSWORD" | su - root -c "\$cmd"
            ;;
    esac
}

run_root_sh() {
    select_root_method
    case "\$ROOT_METHOD" in
        direct) sh -c "\$1" ;;
        sudo)
            if sudo -n true >/dev/null 2>&1; then
                sudo -n sh -c "\$1"
            else
                for candidate in "\$SUDO_PASSWORD" "\$SUDO_PASSWORD_ALT"; do
                    [ -n "\$candidate" ] || continue
                    if printf '%s
' "\$candidate" | sudo -S -p '' -v >/dev/null 2>&1; then
                        printf '%s
' "\$candidate" | sudo -S -p '' sh -c "\$1"
                        return
                    fi
                done
                printf '%s
' 'ERRO: sudo requer senha valida e nenhuma senha sudo/root funcionou.' >&2
                exit 1
            fi
            ;;
        su)
            printf '%s
' "\$ROOT_PASSWORD" | su - root -c "\$1"
            ;;
    esac
}

[ -f /etc/debian_version ] || {
    printf '%s\n' 'ERRO: servidor remoto nao e Debian' >&2
    exit 1
}
printf 'debian_version=%s\n' "\$(cat /etc/debian_version)"

if [ "\$PANEL_PROVISION" = '1' ]; then
    printf '%s\n' 'ETAPA=pacotes'
    run_root apt-get update
    run_root env DEBIAN_FRONTEND=noninteractive apt-get install -y bind9 bind9utils dnsutils sudo nftables fail2ban
fi

printf '%s\n' 'ETAPA=pacotes'
if ! command -v named-checkconf >/dev/null 2>&1; then
    printf '%s\n' 'ERRO: BIND nao detectado. Use provisionamento para instalar BIND em Debian limpo.' >&2
    exit 1
fi

if ! getent group bind >/dev/null 2>&1; then
    printf '%s\n' 'ERRO: grupo bind nao encontrado' >&2
    exit 1
fi

printf '%s\n' 'ETAPA=dns-sync'
if ! id dns-sync >/dev/null 2>&1; then
    run_root useradd --system --user-group --create-home --home-dir /opt/dns-sync --shell /bin/sh dns-sync
fi
if ! getent group dns-sync >/dev/null 2>&1; then
    run_root groupadd --system dns-sync
fi
if ! id -nG dns-sync 2>/dev/null | tr ' ' '\n' | grep -Fx dns-sync >/dev/null 2>&1; then
    run_root usermod -a -G dns-sync dns-sync
fi

run_root install -d -o dns-sync -g dns-sync -m 0700 /opt/dns-sync/.ssh
auth_line="restrict,command=\"/usr/local/bin/dns-sync-command.sh\" \$PANEL_PUBLIC_KEY"
tmp_auth=\$(mktemp)
run_root_sh "touch /opt/dns-sync/.ssh/authorized_keys && chown dns-sync:dns-sync /opt/dns-sync/.ssh/authorized_keys && chmod 0600 /opt/dns-sync/.ssh/authorized_keys"
run_root cat /opt/dns-sync/.ssh/authorized_keys >"\$tmp_auth" 2>/dev/null || true
if ! grep -Fqx "\$auth_line" "\$tmp_auth"; then
    printf '%s\n' "\$auth_line" >>"\$tmp_auth"
fi
run_root install -o dns-sync -g dns-sync -m 0600 "\$tmp_auth" /opt/dns-sync/.ssh/authorized_keys
rm -f "\$tmp_auth"

printf '%s\n' 'ETAPA=agente'
run_root install -d -o root -g root -m 0755 /usr/local/bin
for f in dns-slave-status.sh dns-slave-reload.sh dns-slave-check-transfer.sh dns-slave-add-zone.sh dns-slave-remove-zone.sh dns-slave-migrate-layout.sh dns-zone-inventory.sh; do
    run_root install -o root -g root -m 0750 "\$REMOTE_DIR/\$f" "/usr/local/bin/\$f"
done
run_root install -o root -g dns-sync -m 0750 "\$REMOTE_DIR/dns-sync-command.sh" /usr/local/bin/dns-sync-command.sh
run_root install -d -o root -g bind -m 0770 /var/cache/bind/slave-aut /var/cache/bind/slave-rev

run_root visudo -cf "\$REMOTE_DIR/dns-sync.sudoers" >/dev/null
run_root install -o root -g root -m 0440 "\$REMOTE_DIR/dns-sync.sudoers" /etc/sudoers.d/dns-sync
run_root rm -f /etc/sudoers.d/dns-sync-bootstrap
run_root visudo -c >/dev/null

run_root named-checkconf
if [ "\$PANEL_PROVISION" = '1' ]; then
    run_root systemctl enable --now bind9
fi

printf '%s\n' 'ETAPA=status'
run_root /usr/local/bin/dns-slave-status.sh || true
SH;
}

function dns_server_modo_bootstrap(array $server): string
{
    return (string) ($server['modo_bootstrap'] ?? $server['modo_instalacao'] ?? 'adotar');
}

function dns_server_modo_eh_provisionamento(string $modo): bool
{
    return in_array($modo, ['provisionar', 'provisionado', 'provision_clean'], true);
}

function dns_server_descrever_etapa_falha(string $saida, string $fallback = 'bootstrap'): string
{
    if (preg_match_all('/^ETAPA=([a-z0-9_-]+)/mi', $saida, $matches) && !empty($matches[1])) {
        return (string) end($matches[1]);
    }

    if (str_contains($saida, '[COPIA]')) {
        return 'agente';
    }
    if (str_contains($saida, '[SSH]')) {
        return 'ssh';
    }

    return $fallback;
}

function dns_server_bootstrap(array $server, array $admin): array
{
    if (empty($server['ativo'])) {
        throw new RuntimeException('O servidor deve estar ativo para adoção/provisionamento.');
    }

    $publicKey = dns_server_preparar_chave_local();
    $temporarios = dns_server_auth_temp_files($admin);
    $remoteDir = '/tmp/dns-panel-node-' . bin2hex(random_bytes(6));
    $env = dns_server_admin_env($admin, $temporarios);
    $inicio = microtime(true);
    $saida = [];

    try {
        $ssh = dns_server_admin_ssh_args($server, $admin, $temporarios);
        $mkdir = dns_server_exec(array_merge($ssh, [
            'rm -rf ' . escapeshellarg($remoteDir) . ' && mkdir -p ' . escapeshellarg($remoteDir),
        ]), 30, $env);
        $saida[] = "[SSH]\n" . $mkdir['saida'];
        if (!$mkdir['ok']) {
            return $mkdir + ['saida' => implode("\n\n", $saida)];
        }

        $pacote = [
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-status.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-reload.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-check-transfer.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-add-zone.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-remove-zone.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-slave-migrate-layout.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-zone-inventory.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-sync-command.sh',
            DNS_SERVER_NS2_AGENT_DIR . '/dns-sync.sudoers',
        ];

        foreach ($pacote as $arquivo) {
            if (!is_file($arquivo)) {
                throw new RuntimeException('Arquivo ausente no pacote do agente: ' . basename($arquivo));
            }
        }

        $scp = dns_server_exec(
            dns_server_admin_scp_args($server, $admin, $temporarios, $pacote, $remoteDir . '/'),
            45,
            $env
        );
        $saida[] = "[COPIA]\n" . $scp['saida'];
        if (!$scp['ok']) {
            return $scp + ['saida' => implode("\n\n", $saida)];
        }

        $sudoB64 = base64_encode((string) $admin['sudo_password']);
        $sudoAltB64 = base64_encode((string) ($admin['sudo_password_alt'] ?? ''));
        $remoteCommand = 'export DNS_PANEL_REMOTE_DIR=' . escapeshellarg($remoteDir)
            . '; IFS= read -r DNS_PANEL_SUDO_PASSWORD_B64; IFS= read -r DNS_PANEL_SUDO_PASSWORD_ALT_B64; '
            . 'DNS_PANEL_SUDO_PASSWORD=$(printf %s "$DNS_PANEL_SUDO_PASSWORD_B64" | base64 -d); '
            . 'DNS_PANEL_SUDO_PASSWORD_ALT=$(printf %s "$DNS_PANEL_SUDO_PASSWORD_ALT_B64" | base64 -d); '
            . 'export DNS_PANEL_SUDO_PASSWORD DNS_PANEL_SUDO_PASSWORD_ALT; sh -s';
        $script = $sudoB64 . "\n" . $sudoAltB64 . "\n" . dns_server_remote_bootstrap_script(dns_server_modo_bootstrap($server), $publicKey);
        $install = dns_server_exec(array_merge($ssh, [$remoteCommand]), 900, $env, $script);
        $saida[] = "[BOOTSTRAP]\n" . $install['saida'];

        $clean = dns_server_exec(array_merge($ssh, [
            'rm -rf ' . escapeshellarg($remoteDir),
        ]), 20, $env);
        if (!$clean['ok']) {
            $saida[] = "[LIMPEZA]\n" . $clean['saida'];
        }

        $texto = trim(implode("\n\n", $saida));
        if (strlen($texto) > 6000) {
            $texto = substr($texto, 0, 6000) . "\n[saída truncada]";
        }

        return [
            'ok' => $install['ok'],
            'codigo' => $install['codigo'],
            'saida' => $texto !== '' ? $texto : 'Sem saída.',
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    } finally {
        foreach ($temporarios as $arquivo) {
            if (is_string($arquivo) && is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
    }
}


function dns_server_remover_agente(array $server, array $admin): array
{
    $temporarios = dns_server_auth_temp_files($admin);
    $env = dns_server_admin_env($admin, $temporarios);
    $inicio = microtime(true);

    try {
        $ssh = dns_server_admin_ssh_args($server, $admin, $temporarios);
        $sudoB64 = base64_encode((string) $admin['sudo_password']);
        $sudoAltB64 = base64_encode((string) ($admin['sudo_password_alt'] ?? ''));
        $remoteCommand = 'IFS= read -r DNS_PANEL_SUDO_PASSWORD_B64; IFS= read -r DNS_PANEL_SUDO_PASSWORD_ALT_B64; '
            . 'DNS_PANEL_SUDO_PASSWORD=$(printf %s "$DNS_PANEL_SUDO_PASSWORD_B64" | base64 -d); '
            . 'DNS_PANEL_SUDO_PASSWORD_ALT=$(printf %s "$DNS_PANEL_SUDO_PASSWORD_ALT_B64" | base64 -d); '
            . 'export DNS_PANEL_SUDO_PASSWORD DNS_PANEL_SUDO_PASSWORD_ALT; sh -s';
        $script = $sudoB64 . "\n" . $sudoAltB64 . "\n" . <<<'SH'
set -eu
SUDO_PASSWORD="${DNS_PANEL_SUDO_PASSWORD:-}"
SUDO_PASSWORD_ALT="${DNS_PANEL_SUDO_PASSWORD_ALT:-}"
ROOT_METHOD=''
ROOT_PASSWORD=''
quote_args() {
    quoted=''
    for arg in "$@"; do
        safe=$(printf '%s' "$arg" | sed "s/'/'\\''/g")
        quoted="${quoted}${quoted:+ }'${safe}'"
    done
    printf '%s' "$quoted"
}
select_root_method() {
    [ -n "$ROOT_METHOD" ] && return
    if [ "$(id -u)" -eq 0 ]; then
        ROOT_METHOD='direct'
        return
    fi
    if command -v sudo >/dev/null 2>&1; then
        ROOT_METHOD='sudo'
        return
    fi
    if command -v su >/dev/null 2>&1; then
        for candidate in "$SUDO_PASSWORD" "$SUDO_PASSWORD_ALT"; do
            [ -n "$candidate" ] || continue
            if printf '%s
' "$candidate" | su - root -c 'true' >/dev/null 2>&1; then
                ROOT_METHOD='su'
                ROOT_PASSWORD="$candidate"
                return
            fi
        done
    fi
    printf '%s\n' 'ERRO: sudo não está instalado no servidor remoto. Use root ou forneça a senha root no campo sudo/root para continuar.' >&2
    exit 1
}
run_root() {
    select_root_method
    case "$ROOT_METHOD" in
        direct) "$@" ;;
        sudo)
            if sudo -n true >/dev/null 2>&1; then
                sudo -n "$@"
            else
                for candidate in "$SUDO_PASSWORD" "$SUDO_PASSWORD_ALT"; do
                    [ -n "$candidate" ] || continue
                    if printf '%s\n' "$candidate" | sudo -S -p '' -v >/dev/null 2>&1; then
                        printf '%s\n' "$candidate" | sudo -S -p '' "$@"
                        return
                    fi
                done
                printf '%s\n' 'ERRO: sudo requer senha valida e nenhuma senha sudo/root funcionou.' >&2
                exit 1"
            fi
            ;;
        su)
            cmd=$(quote_args "$@")
            printf '%s\n' "$ROOT_PASSWORD" | su - root -c "$cmd"
            ;;
    esac
}
run_root rm -f /etc/sudoers.d/dns-sync /etc/sudoers.d/dns-sync-bootstrap
run_root rm -f \
    /usr/local/bin/dns-slave-status.sh \
    /usr/local/bin/dns-slave-reload.sh \
    /usr/local/bin/dns-slave-check-transfer.sh \
    /usr/local/bin/dns-slave-add-zone.sh \
    /usr/local/bin/dns-slave-remove-zone.sh \
    /usr/local/bin/dns-slave-migrate-layout.sh \
    /usr/local/bin/dns-sync-command.sh
if command -v visudo >/dev/null 2>&1; then
    run_root visudo -c >/dev/null
fi
if id dns-sync >/dev/null 2>&1; then
    run_root passwd -l dns-sync >/dev/null 2>&1 || true
fi
printf '%s\n' 'OK: agente DNS removido/desativado'
SH;
        $resultado = dns_server_exec(array_merge($ssh, [$remoteCommand]), 120, $env, $script);
        $resultado['duracao_ms'] = (int) round((microtime(true) - $inicio) * 1000);

        return $resultado;
    } finally {
        foreach ($temporarios as $arquivo) {
            if (is_string($arquivo) && is_file($arquivo)) {
                @unlink($arquivo);
            }
        }
    }
}

function dns_server_executar_teste(array $servidor, string $teste, ?string $zona = null): array
{
    $comandosRemotos = [
        'connection' => 'sudo -n /usr/local/bin/dns-slave-status.sh --connection-only',
        'status' => 'sudo -n /usr/local/bin/dns-slave-status.sh',
        'migrate_layout' => 'sudo -n /usr/local/bin/dns-slave-migrate-layout.sh',
        'inventory' => 'sudo -n /usr/local/bin/dns-zone-inventory.sh',
    ];

    if ($teste === 'transfer') {
        $zonaNormalizada = strtolower(rtrim(trim((string) $zona), '.'));
        if (!dns_server_zone_name_valido($zonaNormalizada)) {
            throw new RuntimeException('Zona de teste invalida.');
        }
        $comandosRemotos['transfer'] = 'sudo -n /usr/local/bin/dns-slave-check-transfer.sh ' . escapeshellarg($zonaNormalizada);
    }

    if (!isset($comandosRemotos[$teste])) {
        throw new InvalidArgumentException('Teste remoto não permitido.');
    }
    if (empty($servidor['ativo'])) {
        throw new RuntimeException('O servidor está inativo no painel.');
    }
    if (!is_file(DNS_SERVER_SSH_KEY) || !is_readable(DNS_SERVER_SSH_KEY)) {
        throw new RuntimeException(
            'Chave SSH indisponível em ' . DNS_SERVER_SSH_KEY . '.'
        );
    }
    if (!is_file(DNS_SERVER_KNOWN_HOSTS) || !is_readable(DNS_SERVER_KNOWN_HOSTS)) {
        throw new RuntimeException(
            'Arquivo known_hosts indisponível em ' . DNS_SERVER_KNOWN_HOSTS . '.'
        );
    }

    $destino = dns_server_destino($servidor);
    $porta = (int) $servidor['ssh_port'];
    $argumentos = [
        '/usr/bin/timeout',
        in_array($teste, ['inventory', 'migrate_layout'], true) ? '45' : '12',
        '/usr/bin/ssh',
        '-F',
        '/dev/null',
        '-i',
        DNS_SERVER_SSH_KEY,
        '-o',
        'BatchMode=yes',
        '-o',
        'IdentitiesOnly=yes',
        '-o',
        'StrictHostKeyChecking=yes',
        '-o',
        'UserKnownHostsFile=' . DNS_SERVER_KNOWN_HOSTS,
        '-o',
        'ConnectTimeout=6',
        '-p',
        (string) $porta,
        $destino,
        $comandosRemotos[$teste],
    ];

    $comando = implode(' ', array_map('escapeshellarg', $argumentos)) . ' 2>&1';
    $saida = [];
    $codigo = 1;
    $inicio = microtime(true);
    exec($comando, $saida, $codigo);
    $texto = trim(implode("\n", $saida));

    $limiteSaida = $teste === 'inventory' ? 200000 : 4000;
    if (strlen($texto) > $limiteSaida) {
        $texto = substr($texto, 0, $limiteSaida) . "\n[saída truncada]";
    }

    return [
        'ok' => $codigo === 0,
        'codigo' => $codigo,
        'saida' => $texto !== '' ? $texto : 'Sem saída.',
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ];
}

function dns_server_parse_status_saida(string $saida): array
{
    $dados = [];

    foreach (preg_split('/\R/', $saida) ?: [] as $linha) {
        if (!str_contains($linha, '=')) {
            continue;
        }

        [$chave, $valor] = explode('=', $linha, 2);
        $chave = trim($chave);

        if ($chave !== '') {
            $dados[$chave] = trim($valor);
        }
    }

    return $dados;
}


function dns_server_executar_comando_zona(
    array $servidor,
    string $acao,
    string $zona,
    ?string $masterIp = null,
    ?string $zoneFile = null
): array {
    if ($acao !== 'add_zone') {
        throw new InvalidArgumentException('Acao de zona remota nao permitida.');
    }
    if (empty($servidor['ativo'])) {
        throw new RuntimeException('O servidor esta inativo no painel.');
    }

    $zonaNormalizada = strtolower(rtrim(trim($zona), '.'));
    if (!dns_server_zone_name_valido($zonaNormalizada)) {
        throw new RuntimeException('Zona invalida.');
    }
    if (!is_string($masterIp) || !filter_var($masterIp, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('IP do master invalido.');
    }
    if (!is_string($zoneFile) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/', $zoneFile) !== 1) {
        throw new RuntimeException('Nome de arquivo de zona invalido.');
    }
    if (!is_file(DNS_SERVER_SSH_KEY) || !is_readable(DNS_SERVER_SSH_KEY)) {
        throw new RuntimeException('Chave SSH indisponivel em ' . DNS_SERVER_SSH_KEY . '.');
    }
    if (!is_file(DNS_SERVER_KNOWN_HOSTS) || !is_readable(DNS_SERVER_KNOWN_HOSTS)) {
        throw new RuntimeException('Arquivo known_hosts indisponivel em ' . DNS_SERVER_KNOWN_HOSTS . '.');
    }

    $comandoRemoto = 'sudo -n /usr/local/bin/dns-slave-add-zone.sh '
        . $zonaNormalizada . ' '
        . $masterIp . ' '
        . $zoneFile;

    $argumentos = [
        '/usr/bin/timeout',
        '30',
        '/usr/bin/ssh',
        '-F',
        '/dev/null',
        '-i',
        DNS_SERVER_SSH_KEY,
        '-o',
        'BatchMode=yes',
        '-o',
        'IdentitiesOnly=yes',
        '-o',
        'StrictHostKeyChecking=yes',
        '-o',
        'UserKnownHostsFile=' . DNS_SERVER_KNOWN_HOSTS,
        '-o',
        'ConnectTimeout=6',
        '-p',
        (string) ((int) $servidor['ssh_port']),
        dns_server_destino($servidor),
        $comandoRemoto,
    ];

    $comando = implode(' ', array_map('escapeshellarg', $argumentos)) . ' 2>&1';
    $saida = [];
    $codigo = 1;
    $inicio = microtime(true);
    exec($comando, $saida, $codigo);
    $texto = trim(implode("\n", $saida));

    if (strlen($texto) > 8000) {
        $texto = substr($texto, 0, 8000) . "\n[saída truncada]";
    }

    return [
        'ok' => $codigo === 0,
        'codigo' => $codigo,
        'saida' => $texto !== '' ? $texto : 'Sem saída.',
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ];
}

function dns_server_status_derivado(bool $ok, string $saida): array
{
    $dados = dns_server_parse_status_saida($saida);
    $bindAtivo = strtolower((string) ($dados['bind_active'] ?? ''));
    $checkconf = strtolower((string) ($dados['named_checkconf'] ?? ''));
    $zonas = null;

    if (isset($dados['slave_zones']) && ctype_digit((string) $dados['slave_zones'])) {
        $zonas = (int) $dados['slave_zones'];
    }

    return [
        'agente_status' => $ok ? 'instalado' : 'ausente',
        'bind_status' => ($ok && $bindAtivo === 'active' && $checkconf === 'ok') ? 'ok' : 'erro',
        'zonas_slave' => $zonas,
    ];
}

function dns_server_registrar_status(
    int $id,
    bool $online,
    string $mensagem,
    ?string $agenteStatus = null,
    ?string $bindStatus = null,
    ?int $zonasSlave = null
): void
{
    dns_servers_garantir_esquema();
    $stmt = db()->prepare("
        UPDATE dns_servers
        SET ultimo_status = :status,
            agente_status = COALESCE(:agente_status, agente_status),
            bind_status = COALESCE(:bind_status, bind_status),
            zonas_slave = COALESCE(:zonas_slave, zonas_slave),
            ultima_verificacao = CURRENT_TIMESTAMP,
            ultima_mensagem = :mensagem,
            atualizado_em = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $online ? 'online' : 'offline',
        ':agente_status' => $agenteStatus,
        ':bind_status' => $bindStatus,
        ':zonas_slave' => $zonasSlave,
        ':mensagem' => substr($mensagem, 0, 4000),
        ':id' => $id,
    ]);
}

function dns_server_instalar_agente(array $servidor): array
{
    if (empty($servidor['ativo'])) {
        throw new RuntimeException('O servidor está inativo no painel.');
    }

    $instalador = DNS_SERVER_NS2_AGENT_DIR . '/install-ns2-agent.sh';

    if (!is_file($instalador) || !is_executable($instalador)) {
        throw new RuntimeException('Instalador do agente NS2 indisponível ou sem permissão de execução.');
    }

    $host = trim((string) ($servidor['hostname'] ?: $servidor['ip4'] ?: $servidor['ip6']));
    $usuario = (string) $servidor['ssh_user'];
    $porta = (int) $servidor['ssh_port'];

    $argumentos = [
        '/usr/bin/timeout',
        '45',
        $instalador,
        $host,
        $usuario,
        (string) $porta,
    ];

    $comando = implode(' ', array_map('escapeshellarg', $argumentos)) . ' 2>&1';
    $saida = [];
    $codigo = 1;
    $inicio = microtime(true);
    exec($comando, $saida, $codigo);
    $texto = trim(implode("\n", $saida));

    if (strlen($texto) > 4000) {
        $texto = substr($texto, 0, 4000) . "\n[saída truncada]";
    }

    return [
        'ok' => $codigo === 0,
        'codigo' => $codigo,
        'saida' => $texto !== '' ? $texto : 'Sem saída.',
        'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
    ];
}
