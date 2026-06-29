<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dns_servers.php';

const DNS_ZONE_INVENTORY_SCRIPT = __DIR__ . '/../scripts/ns2/dns-zone-inventory.sh';

function dns_zones_garantir_esquema(): void
{
    static $pronto = false;
    if ($pronto) {
        return;
    }

    db()->exec(""
        . "CREATE TABLE IF NOT EXISTS dns_zone_inventory ("
        . "server_key TEXT NOT NULL,"
        . "server_id INTEGER,"
        . "server_nome TEXT NOT NULL,"
        . "server_role TEXT NOT NULL CHECK (server_role IN ('master', 'slave')),"
        . "zone_name TEXT NOT NULL COLLATE NOCASE,"
        . "zone_type TEXT NOT NULL,"
        . "serial TEXT,"
        . "file_path TEXT,"
        . "masters TEXT,"
        . "status TEXT NOT NULL DEFAULT 'ok',"
        . "message TEXT,"
        . "discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        . "PRIMARY KEY (server_key, zone_name)"
        . ")"
    );

    db()->exec(""
        . "CREATE TABLE IF NOT EXISTS dns_zone_inventory_status ("
        . "server_key TEXT PRIMARY KEY,"
        . "server_id INTEGER,"
        . "server_nome TEXT NOT NULL,"
        . "server_role TEXT NOT NULL CHECK (server_role IN ('master', 'slave')),"
        . "total_zones INTEGER NOT NULL DEFAULT 0,"
        . "last_ok INTEGER NOT NULL DEFAULT 0 CHECK (last_ok IN (0, 1)),"
        . "last_error TEXT,"
        . "checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
        . ")"
    );

    db()->exec(""
        . "CREATE TABLE IF NOT EXISTS dns_zone_extra_ignores ("
        . "server_key TEXT NOT NULL,"
        . "zone_name TEXT NOT NULL COLLATE NOCASE,"
        . "server_nome TEXT,"
        . "slave_serial TEXT,"
        . "note TEXT,"
        . "ignored_by TEXT,"
        . "ignored_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,"
        . "PRIMARY KEY (server_key, zone_name)"
        . ")"
    );

    $pronto = true;
}

function dns_zones_server_key_local(): string
{
    return 'local:NS1';
}

function dns_zones_server_key(array $servidor): string
{
    return 'server:' . (int) $servidor['id'];
}

function dns_zones_php_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && !empty($info['name'])) {
            return (string) $info['name'];
        }
    }

    return get_current_user() ?: 'desconhecido';
}

function dns_zones_exec_local(): array
{
    $script = realpath(DNS_ZONE_INVENTORY_SCRIPT) ?: DNS_ZONE_INVENTORY_SCRIPT;
    $existe = is_file($script);
    $executavel = $existe && is_executable($script);

    if (!$existe || !$executavel) {
        throw new RuntimeException(sprintf(
            'Script de inventario DNS indisponivel ou sem execucao. Caminho: %s; existe: %s; executavel: %s; usuario_php: %s.',
            $script,
            $existe ? 'sim' : 'nao',
            $executavel ? 'sim' : 'nao',
            dns_zones_php_user()
        ));
    }

    $resultado = dns_server_exec([$script], 30);
    if (!$resultado['ok']) {
        $resultado['saida'] = 'Falha ao executar inventario local em ' . $script . ': ' . $resultado['saida'];
    }

    return $resultado;
}

function dns_zones_parse_saida(string $saida): array
{
    $zonas = [];

    foreach (preg_split('/\R/', trim($saida)) ?: [] as $linha) {
        if (trim($linha) === '' || str_starts_with($linha, 'ERRO:')) {
            continue;
        }

        $partes = explode("\t", $linha);
        $partes = array_pad($partes, 7, '');
        [$zona, $tipo, $serial, $arquivo, $masters, $status, $mensagem] = array_map('trim', $partes);
        $zona = strtolower(rtrim($zona, '.'));
        $tipo = strtolower($tipo);

        if ($zona === '' || !dns_server_zone_name_valido($zona)) {
            continue;
        }
        if (!in_array($tipo, ['master', 'slave'], true)) {
            continue;
        }
        if ($serial !== '' && !preg_match('/^[0-9]+$/', $serial)) {
            $serial = null;
        }

        $zonas[] = [
            'zone_name' => $zona,
            'zone_type' => $tipo,
            'serial' => $serial !== '' ? $serial : null,
            'file_path' => $arquivo !== '' ? $arquivo : null,
            'masters' => $masters !== '' ? $masters : null,
            'status' => $status !== '' ? substr($status, 0, 40) : 'ok',
            'message' => $mensagem !== '' ? substr($mensagem, 0, 500) : null,
        ];
    }

    return $zonas;
}

function dns_zones_salvar_inventario(
    string $serverKey,
    ?int $serverId,
    string $serverNome,
    string $serverRole,
    bool $ok,
    string $saida
): array {
    dns_zones_garantir_esquema();
    $zonas = $ok ? dns_zones_parse_saida($saida) : [];
    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($ok) {
            $del = $pdo->prepare('DELETE FROM dns_zone_inventory WHERE server_key = :server_key');
            $del->execute([':server_key' => $serverKey]);

            $ins = $pdo->prepare(""
                . "INSERT INTO dns_zone_inventory ("
                . "server_key, server_id, server_nome, server_role, zone_name, zone_type, serial, file_path, masters, status, message, discovered_at"
                . ") VALUES ("
                . ":server_key, :server_id, :server_nome, :server_role, :zone_name, :zone_type, :serial, :file_path, :masters, :status, :message, CURRENT_TIMESTAMP"
                . ")"
            );

            foreach ($zonas as $zona) {
                $ins->execute([
                    ':server_key' => $serverKey,
                    ':server_id' => $serverId,
                    ':server_nome' => $serverNome,
                    ':server_role' => $serverRole,
                    ':zone_name' => $zona['zone_name'],
                    ':zone_type' => $zona['zone_type'],
                    ':serial' => $zona['serial'],
                    ':file_path' => $zona['file_path'],
                    ':masters' => $zona['masters'],
                    ':status' => $zona['status'],
                    ':message' => $zona['message'],
                ]);
            }
        }

        $totalZonas = count($zonas);
        if (!$ok) {
            $totalAnterior = $pdo->prepare('SELECT total_zones FROM dns_zone_inventory_status WHERE server_key = :server_key');
            $totalAnterior->execute([':server_key' => $serverKey]);
            $valorAnterior = $totalAnterior->fetchColumn();
            if ($valorAnterior !== false && (int) $valorAnterior > 0) {
                $totalZonas = (int) $valorAnterior;
            }
            if ($totalZonas === 0 && $serverId !== null) {
                $fallback = $pdo->prepare('SELECT zonas_slave FROM dns_servers WHERE id = :id');
                $fallback->execute([':id' => $serverId]);
                $valorFallback = $fallback->fetchColumn();
                $totalZonas = is_numeric($valorFallback) ? (int) $valorFallback : 0;
            }
        }

        $status = $pdo->prepare(""
            . "INSERT INTO dns_zone_inventory_status ("
            . "server_key, server_id, server_nome, server_role, total_zones, last_ok, last_error, checked_at"
            . ") VALUES ("
            . ":server_key, :server_id, :server_nome, :server_role, :total_zones, :last_ok, :last_error, CURRENT_TIMESTAMP"
            . ") ON CONFLICT(server_key) DO UPDATE SET "
            . "server_id = excluded.server_id, "
            . "server_nome = excluded.server_nome, "
            . "server_role = excluded.server_role, "
            . "total_zones = excluded.total_zones, "
            . "last_ok = excluded.last_ok, "
            . "last_error = excluded.last_error, "
            . "checked_at = CURRENT_TIMESTAMP"
        );
        $status->execute([
            ':server_key' => $serverKey,
            ':server_id' => $serverId,
            ':server_nome' => $serverNome,
            ':server_role' => $serverRole,
            ':total_zones' => $totalZonas,
            ':last_ok' => $ok ? 1 : 0,
            ':last_error' => $ok ? null : substr($saida, 0, 1000),
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $zonas;
}

function dns_zones_atualizar_local(): array
{
    $hostname = gethostname() ?: 'NS1 local';
    $resultado = dns_zones_exec_local();

    $zonas = dns_zones_salvar_inventario(
        dns_zones_server_key_local(),
        null,
        $hostname,
        'master',
        $resultado['ok'],
        $resultado['saida']
    );

    return $resultado + ['zonas' => count($zonas), 'servidor' => $hostname];
}

function dns_zones_atualizar_remoto(array $servidor): array
{
    $resultado = dns_server_executar_teste($servidor, 'inventory');
    $zonas = dns_zones_salvar_inventario(
        dns_zones_server_key($servidor),
        (int) $servidor['id'],
        (string) $servidor['nome'],
        'slave',
        $resultado['ok'],
        $resultado['saida']
    );

    if ($resultado['ok']) {
        dns_server_registrar_status(
            (int) $servidor['id'],
            true,
            'Inventario DNS: ' . count($zonas) . ' zonas importadas.',
            'instalado',
            null,
            count($zonas)
        );
    }

    return $resultado + ['zonas' => count($zonas), 'servidor' => $servidor['nome']];
}

function dns_zones_atualizar_todos(): array
{
    dns_servers_garantir_esquema();
    $resultados = ['local' => dns_zones_atualizar_local(), 'remotos' => []];

    foreach (dns_servers_listar() as $servidor) {
        if (($servidor['tipo'] ?? '') !== 'slave' || empty($servidor['ativo'])) {
            continue;
        }

        try {
            $resultados['remotos'][] = dns_zones_atualizar_remoto($servidor);
        } catch (Throwable $e) {
            dns_zones_salvar_inventario(
                dns_zones_server_key($servidor),
                (int) $servidor['id'],
                (string) $servidor['nome'],
                'slave',
                false,
                $e->getMessage()
            );
            $resultados['remotos'][] = [
                'ok' => false,
                'codigo' => 1,
                'saida' => $e->getMessage(),
                'duracao_ms' => 0,
                'zonas' => 0,
                'servidor' => $servidor['nome'],
            ];
        }
    }

    return $resultados;
}


function dns_zones_master_ip_padrao(): string
{
    $candidatos = [];

    if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $candidatos[] = (string) $_SERVER['SERVER_ADDR'];
    }

    $resultado = dns_server_exec(['/usr/bin/hostname', '-I'], 5);
    if ($resultado['ok']) {
        foreach (preg_split('/\s+/', trim($resultado['saida'])) ?: [] as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $candidatos[] = $ip;
            }
        }
    }

    foreach ($candidatos as $ip) {
        if (!preg_match('/^(127\.|169\.254\.|0\.)/', $ip)) {
            return $ip;
        }
    }

    throw new RuntimeException('Nao foi possivel detectar IPv4 do master NS1.');
}

function dns_zones_slave_file_name(string $zona): string
{
    $zona = strtolower(rtrim(trim($zona), '.'));
    if (!dns_server_zone_name_valido($zona)) {
        throw new RuntimeException('Zona invalida.');
    }

    if (str_ends_with($zona, '.in-addr.arpa') || str_ends_with($zona, '.ip6.arpa')) {
        return $zona . '.rev';
    }

    return $zona . '.hosts';
}

function dns_zones_comparacao_por_zona_servidor(string $zona, string $serverKey): ?array
{
    $zona = strtolower(rtrim(trim($zona), '.'));
    foreach (dns_zones_comparar() as $linha) {
        if ($linha['zone_name'] === $zona && $linha['server_key'] === $serverKey) {
            return $linha;
        }
    }

    return null;
}

function dns_zones_servidor_por_key(string $serverKey): ?array
{
    if (!preg_match('/^server:(\d+)$/', $serverKey, $m)) {
        return null;
    }

    return dns_server_por_id((int) $m[1]);
}

function dns_zones_sync_zona_ausente(string $zona, string $serverKey, ?string $masterIp = null): array
{
    dns_zones_garantir_esquema();
    $zona = strtolower(rtrim(trim($zona), '.'));
    if (!dns_server_zone_name_valido($zona)) {
        throw new RuntimeException('Zona invalida.');
    }

    $comparacao = dns_zones_comparacao_por_zona_servidor($zona, $serverKey);
    if (!$comparacao || $comparacao['estado'] !== 'missing_on_slave') {
        throw new RuntimeException('Esta zona nao esta marcada como ausente neste slave. Atualize o inventario antes de sincronizar.');
    }

    $servidor = dns_zones_servidor_por_key($serverKey);
    if (!$servidor || ($servidor['tipo'] ?? '') !== 'slave' || empty($servidor['ativo'])) {
        throw new RuntimeException('Servidor slave invalido ou inativo.');
    }

    $masterIp = trim((string) ($masterIp ?: dns_zones_master_ip_padrao()));
    if (!filter_var($masterIp, FILTER_VALIDATE_IP)) {
        throw new RuntimeException('IP do master invalido.');
    }

    $resultado = dns_server_executar_comando_zona(
        $servidor,
        'add_zone',
        $zona,
        $masterIp,
        dns_zones_slave_file_name($zona)
    );

    $reinventario = null;
    if ($resultado['ok']) {
        $reinventario = dns_zones_atualizar_remoto($servidor);
    }

    return [
        'ok' => $resultado['ok'],
        'servidor' => $servidor['nome'],
        'zona' => $zona,
        'master_ip' => $masterIp,
        'saida' => $resultado['saida'],
        'duracao_ms' => $resultado['duracao_ms'],
        'reinventario' => $reinventario,
    ];
}

function dns_zones_sync_todas_ausentes(?string $masterIp = null, ?string $serverKeyFiltro = null): array
{
    $acoes = [];
    foreach (dns_zones_comparar() as $linha) {
        if ($linha['estado'] !== 'missing_on_slave') {
            continue;
        }
        if ($serverKeyFiltro !== null && $linha['server_key'] !== $serverKeyFiltro) {
            continue;
        }

        $acoes[] = ['zona' => $linha['zone_name'], 'server_key' => $linha['server_key']];
    }

    if (!$acoes) {
        throw new RuntimeException('Nenhuma zona ausente encontrada para sincronizar.');
    }

    $resultados = [];
    foreach ($acoes as $acao) {
        $resultado = dns_zones_sync_zona_ausente($acao['zona'], $acao['server_key'], $masterIp);
        $resultados[] = $resultado;
        if (!$resultado['ok']) {
            break;
        }
    }

    dns_zones_atualizar_todos();

    return $resultados;
}

function dns_zones_status_servidores(): array
{
    dns_zones_garantir_esquema();
    return db()->query(""
        . "SELECT * FROM dns_zone_inventory_status "
        . "WHERE server_key = 'local:NS1' "
        . "OR server_key IN (SELECT 'server:' || id FROM dns_servers WHERE ativo = 1) "
        . "ORDER BY server_role, server_nome COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function dns_zones_inventario(): array
{
    dns_zones_garantir_esquema();
    return db()->query(""
        . "SELECT * FROM dns_zone_inventory "
        . "WHERE server_key = 'local:NS1' "
        . "OR server_key IN (SELECT 'server:' || id FROM dns_servers WHERE ativo = 1) "
        . "ORDER BY zone_name COLLATE NOCASE, server_role, server_nome COLLATE NOCASE"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function dns_zones_ignores_map(): array
{
    dns_zones_garantir_esquema();
    $rows = db()->query("SELECT * FROM dns_zone_extra_ignores")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];

    foreach ($rows as $row) {
        $key = (string) $row['server_key'] . "|" . strtolower((string) $row['zone_name']);
        $map[$key] = $row;
    }

    return $map;
}

function dns_zones_extra_ignore_key(string $serverKey, string $zona): string
{
    return $serverKey . "|" . strtolower(rtrim(trim($zona), '.'));
}

function dns_zones_marcar_extra_ignorada(string $zona, string $serverKey, string $nota = '', ?string $usuario = null): array
{
    dns_zones_garantir_esquema();
    $zona = strtolower(rtrim(trim($zona), '.'));
    if (!dns_server_zone_name_valido($zona)) {
        throw new RuntimeException('Zona invalida.');
    }

    $linha = dns_zones_comparacao_por_zona_servidor($zona, $serverKey);
    if (!$linha || !in_array($linha['estado'], ['extra_on_slave', 'extra_on_slave_ignored'], true)) {
        throw new RuntimeException('Apenas zonas extras atuais podem ser marcadas como ignoradas.');
    }

    $stmt = db()->prepare(""
        . "INSERT INTO dns_zone_extra_ignores (server_key, zone_name, server_nome, slave_serial, note, ignored_by, ignored_at) "
        . "VALUES (:server_key, :zone_name, :server_nome, :slave_serial, :note, :ignored_by, CURRENT_TIMESTAMP) "
        . "ON CONFLICT(server_key, zone_name) DO UPDATE SET "
        . "server_nome = excluded.server_nome, "
        . "slave_serial = excluded.slave_serial, "
        . "note = excluded.note, "
        . "ignored_by = excluded.ignored_by, "
        . "ignored_at = CURRENT_TIMESTAMP"
    );
    $stmt->execute([
        ':server_key' => $serverKey,
        ':zone_name' => $zona,
        ':server_nome' => $linha['server_nome'],
        ':slave_serial' => $linha['slave_serial'] ?? null,
        ':note' => trim(substr($nota, 0, 500)),
        ':ignored_by' => $usuario !== null ? substr($usuario, 0, 80) : null,
    ]);

    return $linha;
}

function dns_zones_remover_extra_ignorada(string $zona, string $serverKey): void
{
    dns_zones_garantir_esquema();
    $zona = strtolower(rtrim(trim($zona), '.'));
    if (!dns_server_zone_name_valido($zona)) {
        throw new RuntimeException('Zona invalida.');
    }

    $stmt = db()->prepare('DELETE FROM dns_zone_extra_ignores WHERE server_key = :server_key AND zone_name = :zone_name');
    $stmt->execute([':server_key' => $serverKey, ':zone_name' => $zona]);
}

function dns_zones_resumo(): array
{
    dns_zones_garantir_esquema();
    $status = dns_zones_status_servidores();
    $divergencias = dns_zones_comparar();
    $totalDivergencias = 0;

    foreach ($divergencias as $linha) {
        if (!in_array($linha['estado'], ['ok', 'extra_on_slave_ignored'], true)) {
            $totalDivergencias++;
        }
    }

    return [
        'servidores' => $status,
        'divergencias' => $totalDivergencias,
        'zonas_unicas' => (int) db()->query(""
            . "SELECT COUNT(DISTINCT zone_name) FROM dns_zone_inventory "
            . "WHERE server_key = 'local:NS1' "
            . "OR server_key IN (SELECT 'server:' || id FROM dns_servers WHERE ativo = 1)"
        )->fetchColumn(),
    ];
}

function dns_zones_comparar(): array
{
    dns_zones_garantir_esquema();
    $rows = dns_zones_inventario();
    $masters = [];
    $slaves = [];
    $slaveNames = [];
    $ignores = dns_zones_ignores_map();

    foreach ($rows as $row) {
        if ($row['server_role'] === 'master') {
            $masters[$row['zone_name']] = $row;
        } elseif ($row['server_role'] === 'slave') {
            $slaves[$row['server_key']][$row['zone_name']] = $row;
            $slaveNames[$row['server_key']] = $row['server_nome'];
        }
    }

    foreach (dns_zones_status_servidores() as $server) {
        if ($server['server_role'] === 'slave' && (int) $server['last_ok'] === 1) {
            $slaveNames[$server['server_key']] = $server['server_nome'];
            $slaves[$server['server_key']] = $slaves[$server['server_key']] ?? [];
        }
    }

    $comparacao = [];
    foreach ($masters as $zone => $master) {
        foreach ($slaveNames as $slaveKey => $slaveName) {
            $slave = $slaves[$slaveKey][$zone] ?? null;
            $estado = 'ok';
            $detalhe = 'Serial equivalente';

            if ($slave === null) {
                $estado = 'missing_on_slave';
                $detalhe = 'Zona ausente no slave';
            } elseif ($master['serial'] === null || $master['serial'] === '') {
                $estado = 'master_serial_unknown';
                $detalhe = 'Serial do master indisponivel';
            } elseif ($slave['serial'] === null || $slave['serial'] === '') {
                $estado = 'slave_serial_unknown';
                $detalhe = 'Serial do slave indisponivel';
            } elseif ((string) $master['serial'] !== (string) $slave['serial']) {
                $estado = 'serial_mismatch';
                $detalhe = 'Serial divergente';
            }

            $comparacao[] = [
                'zone_name' => $zone,
                'server_key' => $slaveKey,
                'server_nome' => $slaveName,
                'master_serial' => $master['serial'],
                'slave_serial' => $slave['serial'] ?? null,
                'estado' => $estado,
                'detalhe' => $detalhe,
            ];
        }
    }

    foreach ($slaves as $slaveKey => $zones) {
        foreach ($zones as $zone => $slave) {
            if (!isset($masters[$zone])) {
                $ignore = $ignores[dns_zones_extra_ignore_key($slaveKey, $zone)] ?? null;
                $comparacao[] = [
                    'zone_name' => $zone,
                    'server_key' => $slaveKey,
                    'server_nome' => $slave['server_nome'],
                    'master_serial' => null,
                    'slave_serial' => $slave['serial'],
                    'estado' => $ignore ? 'extra_on_slave_ignored' : 'extra_on_slave',
                    'detalhe' => $ignore ? 'Zona extra marcada como legitima/ignorada' : 'Zona existe no slave e nao existe no master',
                    'ignore_note' => $ignore['note'] ?? null,
                    'ignored_by' => $ignore['ignored_by'] ?? null,
                    'ignored_at' => $ignore['ignored_at'] ?? null,
                ];
            }
        }
    }

    usort($comparacao, static function (array $a, array $b): int {
        return [$a['estado'] === 'ok' ? 1 : 0, $a['zone_name'], $a['server_nome']]
            <=> [$b['estado'] === 'ok' ? 1 : 0, $b['zone_name'], $b['server_nome']];
    });

    return $comparacao;
}


function dns_zones_estado_codigo(string $estado): string
{
    return match ($estado) {
        'ok' => 'OK',
        'missing_on_slave' => 'AUSENTE_NO_SLAVE',
        'serial_mismatch' => 'SERIAL_DIFERENTE',
        'extra_on_slave' => 'EXTRA_NO_SLAVE',
        'extra_on_slave_ignored' => 'EXTRA_IGNORADA',
        'master_serial_unknown' => 'SOA_MASTER_INDISPONIVEL',
        'slave_serial_unknown' => 'SOA_SLAVE_INDISPONIVEL',
        'collection_failed' => 'FALHA_COLETA',
        default => strtoupper($estado),
    };
}

function dns_zones_resumo_classificacao(?array $comparacao = null): array
{
    $comparacao = $comparacao ?? dns_zones_comparar();
    $base = [
        'OK' => 0,
        'AUSENTE_NO_SLAVE' => 0,
        'SERIAL_DIFERENTE' => 0,
        'EXTRA_NO_SLAVE' => 0,
        'EXTRA_IGNORADA' => 0,
        'SOA_MASTER_INDISPONIVEL' => 0,
        'SOA_SLAVE_INDISPONIVEL' => 0,
        'FALHA_COLETA' => 0,
    ];

    foreach ($comparacao as $linha) {
        $codigo = dns_zones_estado_codigo((string) $linha['estado']);
        $base[$codigo] = ($base[$codigo] ?? 0) + 1;
    }

    foreach (dns_zones_status_servidores() as $server) {
        if ((int) $server['last_ok'] !== 1) {
            $base['FALHA_COLETA']++;
        }
    }

    return $base;
}

function dns_zones_extras_por_servidor(?array $comparacao = null, bool $ignoradas = false): array
{
    $comparacao = $comparacao ?? dns_zones_comparar();
    $extras = [];
    $estadoEsperado = $ignoradas ? 'extra_on_slave_ignored' : 'extra_on_slave';

    foreach ($comparacao as $linha) {
        if ($linha['estado'] !== $estadoEsperado) {
            continue;
        }

        $serverKey = (string) $linha['server_key'];
        if (!isset($extras[$serverKey])) {
            $extras[$serverKey] = [
                'server_key' => $serverKey,
                'server_nome' => $linha['server_nome'],
                'zonas' => [],
            ];
        }

        $extras[$serverKey]['zonas'][] = [
            'zone_name' => $linha['zone_name'],
            'slave_serial' => $linha['slave_serial'],
            'detalhe' => $linha['detalhe'],
            'ignore_note' => $linha['ignore_note'] ?? null,
            'ignored_by' => $linha['ignored_by'] ?? null,
            'ignored_at' => $linha['ignored_at'] ?? null,
        ];
    }

    uasort($extras, static fn(array $a, array $b): int => strcasecmp((string) $a['server_nome'], (string) $b['server_nome']));

    return array_values($extras);
}

function dns_zones_eventos_auditoria(int $limite = 30): array
{
    $limite = max(1, min(100, $limite));
    $stmt = db()->prepare(""
        . "SELECT acao, dominio, tipo_registro, nome_registro, valor_antigo, valor_novo, status, mensagem, criado_em "
        . "FROM audit_logs "
        . "WHERE acao LIKE 'DNS_ZONE%' "
        . "ORDER BY id DESC LIMIT :limite"
    );
    $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function dns_zones_status_falha_coleta(): array
{
    return array_values(array_filter(
        dns_zones_status_servidores(),
        static fn(array $server): bool => (int) $server['last_ok'] !== 1
    ));
}
