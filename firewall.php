<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/audit.php';

function firewall_auditar(
    string $acao,
    string $alvo,
    string $status,
    string $mensagem,
    string $tipoRegistro = 'FIREWALL',
    ?string $valorAntigo = null,
    ?string $valorNovo = null
): void {
    try {
        registrar_auditoria([
            'acao' => $acao,
            'tipo_registro' => $tipoRegistro,
            'nome_registro' => $alvo,
            'valor_antigo' => $valorAntigo,
            'valor_novo' => $valorNovo,
            'status' => $status,
            'mensagem' => $mensagem,
        ]);
    } catch (Throwable $e) {
        error_log('Firewall - falha ao registrar auditoria: ' . $e->getMessage());
    }
}

function firewall_redirecionar(string $tipo, string $mensagem): never
{
    $_SESSION['firewall_flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    header('Location: firewall.php');
    exit;
}

function firewall_validar_csrf(string $acao, string $alvo, string $detalhesAuditoria = ''): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        $mensagem = 'Tentativa rejeitada por validação de segurança.';
        if ($detalhesAuditoria !== '') {
            $mensagem .= ' ' . $detalhesAuditoria;
        }
        firewall_auditar($acao, $alvo, 'ERROR', $mensagem);
        firewall_redirecionar('error', 'Não foi possível validar a solicitação. Atualize a página e tente novamente.');
    }
}

function firewall_normalizar_acl(string $valor, ?string $familiaEsperada = null): ?array
{
    $valor = strtolower(trim($valor));
    if ($valor === '') {
        return null;
    }

    $partes = explode('/', $valor, 2);
    $endereco = $partes[0];
    $binario = @inet_pton($endereco);
    if ($binario === false) {
        return null;
    }

    $tipo = strlen($binario) === 4 ? 'IPv4' : 'IPv6';
    $normalizado = @inet_ntop($binario);
    if ($normalizado === false) {
        return null;
    }

    if ($familiaEsperada !== null && $familiaEsperada !== $tipo) {
        return null;
    }

    if (count($partes) === 2) {
        $maximo = $tipo === 'IPv4' ? 32 : 128;
        if ($partes[1] === '' || !ctype_digit($partes[1])) {
            return null;
        }
        $prefixo = (int) $partes[1];
        if ($prefixo < 0 || $prefixo > $maximo) {
            return null;
        }
        $normalizado .= '/' . $prefixo;
    }

    return ['tipo' => $tipo, 'valor' => $normalizado];
}

function firewall_validar_porta(mixed $valor): ?int
{
    if (!is_string($valor) || $valor === '' || !ctype_digit($valor)) {
        return null;
    }
    $porta = (int) $valor;
    return $porta >= 1 && $porta <= 65535 ? $porta : null;
}

function firewall_descricao(string $valor): string
{
    return substr(trim($valor), 0, 120);
}

function firewall_servico(string $valor): string
{
    return substr(trim($valor), 0, 40);
}

function firewall_tipo_registro_acl(string $familia): string
{
    return 'ACL_' . strtoupper($familia);
}

function firewall_tipo_registro_porta(string $escopo): string
{
    return $escopo === 'admin' ? 'PORTA_ADMIN' : 'PORTA_PUBLICA';
}

function firewall_carregar_configuracao(PDO $pdo): array
{
    return [
        'acl_ipv4' => $pdo->query("SELECT * FROM firewall_admin_access WHERE tipo = 'IPv4' ORDER BY rede")->fetchAll(PDO::FETCH_ASSOC),
        'acl_ipv6' => $pdo->query("SELECT * FROM firewall_admin_access WHERE tipo = 'IPv6' ORDER BY rede")->fetchAll(PDO::FETCH_ASSOC),
        'portas_admin' => $pdo->query("SELECT * FROM firewall_ports WHERE escopo = 'admin' ORDER BY porta")->fetchAll(PDO::FETCH_ASSOC),
        'portas_publicas' => $pdo->query("SELECT * FROM firewall_ports WHERE escopo = 'publica' ORDER BY porta")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

function firewall_contagens(array $configuracao): array
{
    return [
        'acl_ipv4' => count($configuracao['acl_ipv4'] ?? []),
        'acl_ipv6' => count($configuracao['acl_ipv6'] ?? []),
        'portas_admin' => count($configuracao['portas_admin'] ?? []),
        'portas_publicas' => count($configuracao['portas_publicas'] ?? []),
    ];
}

function firewall_resumo_contagens(array $contagens): string
{
    return sprintf(
        'ACLs IPv4: %d; ACLs IPv6: %d; portas administrativas: %d; portas públicas: %d.',
        (int) ($contagens['acl_ipv4'] ?? 0),
        (int) ($contagens['acl_ipv6'] ?? 0),
        (int) ($contagens['portas_admin'] ?? 0),
        (int) ($contagens['portas_publicas'] ?? 0)
    );
}

function firewall_protocolos_portas(array $portas): array
{
    $resultado = ['tcp' => [], 'udp' => []];
    foreach ($portas as $registro) {
        $porta = firewall_validar_porta((string) ($registro['porta'] ?? ''));
        $protocolo = strtoupper((string) ($registro['protocolo'] ?? ''));
        if ($porta === null || !in_array($protocolo, ['TCP', 'UDP', 'TCP/UDP'], true)) {
            throw new RuntimeException('Há uma porta cadastrada com dados inválidos.');
        }
        if ($protocolo === 'TCP' || $protocolo === 'TCP/UDP') {
            $resultado['tcp'][$porta] = $porta;
        }
        if ($protocolo === 'UDP' || $protocolo === 'TCP/UDP') {
            $resultado['udp'][$porta] = $porta;
        }
    }
    sort($resultado['tcp'], SORT_NUMERIC);
    sort($resultado['udp'], SORT_NUMERIC);
    return $resultado;
}

function firewall_acl_para_previa(array $registros, string $familia): array
{
    $resultado = [];
    foreach ($registros as $registro) {
        $normalizado = firewall_normalizar_acl((string) ($registro['rede'] ?? ''), $familia);
        if ($normalizado === null) {
            throw new RuntimeException("Há uma ACL {$familia} cadastrada com formato inválido.");
        }
        if (
            ($familia === 'IPv4' && str_ends_with($normalizado['valor'], '/0'))
            || ($familia === 'IPv6' && str_ends_with($normalizado['valor'], '/0'))
        ) {
            throw new RuntimeException("A ACL administrativa {$familia} aberta para toda a Internet não é permitida.");
        }
        $resultado[$normalizado['valor']] = $normalizado['valor'];
    }
    return array_values($resultado);
}

function firewall_lista_nft(array $valores): string
{
    return '{ ' . implode(', ', $valores) . ' }';
}

function firewall_gerar_previa(array $configuracao): array
{
    $aclIpv4 = firewall_acl_para_previa($configuracao['acl_ipv4'] ?? [], 'IPv4');
    $aclIpv6 = firewall_acl_para_previa($configuracao['acl_ipv6'] ?? [], 'IPv6');
    $admin = firewall_protocolos_portas($configuracao['portas_admin'] ?? []);
    $publicas = firewall_protocolos_portas($configuracao['portas_publicas'] ?? []);
    $regras = [
        'table inet painel_firewall_preview {',
        '    chain input {',
        '        type filter hook input priority 0; policy drop;',
        '        iifname "lo" accept',
        '        ct state established,related accept',
        '        ip protocol icmp accept',
        '        ip6 nexthdr ipv6-icmp accept',
    ];
    $avisos = [];

    foreach (['tcp', 'udp'] as $protocolo) {
        if ($publicas[$protocolo]) {
            $regras[] = '        ' . $protocolo . ' dport ' . firewall_lista_nft($publicas[$protocolo]) . ' accept';
        }
    }

    if ($admin['tcp'] || $admin['udp']) {
        if (!$aclIpv4 && !$aclIpv6) {
            $avisos[] = 'Portas administrativas não foram incluídas porque não há ACL administrativa cadastrada.';
        }
        foreach (['tcp', 'udp'] as $protocolo) {
            if (!$admin[$protocolo]) {
                continue;
            }
            $portas = firewall_lista_nft($admin[$protocolo]);
            if ($aclIpv4) {
                $regras[] = '        ip saddr ' . firewall_lista_nft($aclIpv4) . ' ' . $protocolo . ' dport ' . $portas . ' accept';
            }
            if ($aclIpv6) {
                $regras[] = '        ip6 saddr ' . firewall_lista_nft($aclIpv6) . ' ' . $protocolo . ' dport ' . $portas . ' accept';
            }
        }
    }

    $regras[] = '    }';
    $regras[] = '    chain forward {';
    $regras[] = '        type filter hook forward priority 0; policy drop;';
    $regras[] = '    }';
    $regras[] = '    chain output {';
    $regras[] = '        type filter hook output priority 0; policy accept;';
    $regras[] = '    }';
    $regras[] = '}';

    return [
        'regras' => implode("\n", $regras) . "\n",
        'avisos' => $avisos,
    ];
}

function firewall_binario_nft(): ?string
{
    foreach (['/usr/sbin/nft', '/usr/bin/nft'] as $binario) {
        if (is_file($binario) && is_executable($binario)) {
            return $binario;
        }
    }
    return null;
}

function firewall_limpar_saida_tecnica(string $saida, ?string $arquivoTemporario = null): string
{
    if ($arquivoTemporario !== null && $arquivoTemporario !== '') {
        $saida = str_replace($arquivoTemporario, '[arquivo temporário]', $saida);
    }
    $saida = preg_replace('#/(?:tmp|var/tmp)/[^\s:]+#', '[arquivo temporário]', $saida) ?? $saida;
    $saida = preg_replace('#/(?:etc|usr|var|home|root)/[^\s:]+#', '[caminho interno]', $saida) ?? $saida;
    $saida = trim($saida);
    if (strlen($saida) > 6000) {
        $saida = substr($saida, 0, 6000) . "\n[saída truncada]";
    }
    return $saida !== '' ? $saida : 'Validação concluída sem mensagens técnicas.';
}

function firewall_falha_operacional_nft(array $validacao): ?string
{
    $codigo = $validacao['codigo'] ?? null;
    $saida = strtolower((string) ($validacao['saida'] ?? ''));
    if ($codigo === 124) {
        return 'A validação excedeu o tempo limite permitido.';
    }
    if (in_array($codigo, [126, 127], true)) {
        return 'O validador nft não pôde ser executado no servidor.';
    }
    if (
        str_contains($saida, 'operation not permitted')
        || str_contains($saida, 'permission denied')
        || str_contains($saida, 'unable to initialize netlink')
        || str_contains($saida, 'netlink socket')
        || str_contains($saida, 'sudo:')
    ) {
        return 'O validador nft não possui permissão suficiente para realizar a checagem.';
    }
    return null;
}

function firewall_executar_validacao(string $regras, int $timeout = 8): array
{
    $binario = firewall_binario_nft();
    if ($binario === null) {
        throw new RuntimeException('O validador nft não está disponível no servidor.');
    }

    $arquivo = tempnam(sys_get_temp_dir(), 'fw-preview-');
    if ($arquivo === false) {
        throw new RuntimeException('Não foi possível criar o arquivo temporário de validação.');
    }

    try {
        @chmod($arquivo, 0600);
        $bytes = file_put_contents($arquivo, $regras, LOCK_EX);
        if ($bytes === false || $bytes !== strlen($regras)) {
            throw new RuntimeException('Não foi possível gravar a prévia para validação.');
        }

        $argumentosNft = [$binario, '-c', '-f', $arquivo];
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($euid !== null && $euid !== 0 && is_executable('/usr/bin/sudo')) {
            $argumentosNft = array_merge(['/usr/bin/sudo', '-n'], $argumentosNft);
        }
        $comando = array_merge(['/usr/bin/timeout', (string) $timeout], $argumentosNft);
        $descritores = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $inicio = microtime(true);
        $processo = proc_open($comando, $descritores, $pipes);
        if (!is_resource($processo)) {
            throw new RuntimeException('Não foi possível iniciar a validação controlada.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($processo);
        $saida = trim((string) $stdout . ((string) $stderr !== '' ? "\n" . (string) $stderr : ''));

        return [
            'ok' => $codigo === 0,
            'codigo' => $codigo,
            'saida' => firewall_limpar_saida_tecnica($saida, $arquivo),
            'duracao_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];
    } finally {
        if (is_file($arquivo) && !@unlink($arquivo)) {
            error_log('Firewall - não foi possível remover arquivo temporário de validação.');
        }
    }
}

function firewall_salvar_ultima_validacao(PDO $pdo, array $resultado): void
{
    $json = json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Não foi possível registrar o resultado da validação.');
    }
    $stmt = $pdo->prepare("
        INSERT INTO firewall_meta (chave, valor) VALUES ('ultima_validacao_v15', :valor)
        ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor
    ");
    $stmt->execute([':valor' => $json]);
}

function firewall_carregar_ultima_validacao(PDO $pdo): ?array
{
    $stmt = $pdo->prepare("SELECT valor FROM firewall_meta WHERE chave = 'ultima_validacao_v15'");
    $stmt->execute();
    $valor = $stmt->fetchColumn();
    if (!is_string($valor) || $valor === '') {
        return null;
    }
    $resultado = json_decode($valor, true);
    return is_array($resultado) ? $resultado : null;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = is_string($_POST['acao'] ?? null) ? $_POST['acao'] : '';
    $acoesAuditoria = [
        'adicionar_ip' => 'FIREWALL_ADICIONAR_IP_ADMIN',
        'editar_ip' => 'FIREWALL_EDITAR_IP_ADMIN',
        'remover_ip' => 'FIREWALL_REMOVER_IP_ADMIN',
        'adicionar_porta_admin' => 'FIREWALL_ADICIONAR_PORTA_ADMIN',
        'adicionar_porta_publica' => 'FIREWALL_ADICIONAR_PORTA_PUBLICA',
        'editar_porta' => 'FIREWALL_EDITAR_PORTA',
        'remover_porta' => 'FIREWALL_REMOVER_PORTA',
        'validar_configuracao' => 'FIREWALL_VALIDAR_CONFIGURACAO',
    ];

    if (!isset($acoesAuditoria[$acao])) {
        firewall_redirecionar('error', 'Ação inválida.');
    }

    $acaoAuditoria = $acoesAuditoria[$acao];
    $alvoInformado = trim((string) ($_POST['rede'] ?? $_POST['porta'] ?? 'não informado'));
    $tipoRegistroAuditoria = 'FIREWALL';
    $detalhesCsrf = '';
    if ($acao === 'validar_configuracao') {
        try {
            $detalhesCsrf = firewall_resumo_contagens(firewall_contagens(firewall_carregar_configuracao($pdo)));
        } catch (Throwable) {
            $detalhesCsrf = 'Contagens indisponíveis.';
        }
    }
    firewall_validar_csrf($acaoAuditoria, $alvoInformado, $detalhesCsrf);

    try {
        if ($acao === 'validar_configuracao') {
            $configuracao = firewall_carregar_configuracao($pdo);
            $contagens = firewall_contagens($configuracao);
            $resumoContagens = firewall_resumo_contagens($contagens);

            try {
                $previa = firewall_gerar_previa($configuracao);
                firewall_auditar(
                    'FIREWALL_GERAR_PREVIA',
                    'Configuração nftables',
                    'SUCCESS',
                    'Prévia gerada para validação controlada. ' . $resumoContagens,
                    'FIREWALL_VALIDACAO'
                );
            } catch (Throwable $e) {
                firewall_auditar(
                    'FIREWALL_GERAR_PREVIA_ERRO',
                    'Configuração nftables',
                    'ERROR',
                    $e->getMessage() . ' ' . $resumoContagens,
                    'FIREWALL_VALIDACAO'
                );
                $resultadoPersistido = [
                    'status' => 'ERRO',
                    'data_hora' => date(DATE_ATOM),
                    'usuario' => audit_usuario_atual(),
                    'resumo' => $e->getMessage(),
                    'saida' => 'A prévia não foi enviada ao validador porque sua geração segura falhou.',
                    'contagens' => $contagens,
                ];
                firewall_salvar_ultima_validacao($pdo, $resultadoPersistido);
                firewall_auditar(
                    'FIREWALL_VALIDAR_CONFIGURACAO_ERRO',
                    'Configuração nftables',
                    'ERROR',
                    'Validação não executada porque a geração segura da prévia falhou. ' . $resumoContagens,
                    'FIREWALL_VALIDACAO'
                );
                firewall_redirecionar('error', $e->getMessage() . ' Nenhuma regra foi aplicada.');
            }

            $falhaExecucao = null;
            try {
                $validacao = firewall_executar_validacao($previa['regras']);
                if (!$validacao['ok']) {
                    $falhaExecucao = firewall_falha_operacional_nft($validacao);
                }
            } catch (Throwable $e) {
                $falhaExecucao = $e->getMessage();
                $validacao = [
                    'ok' => false,
                    'codigo' => null,
                    'saida' => firewall_limpar_saida_tecnica($falhaExecucao),
                    'duracao_ms' => 0,
                ];
            }

            $status = $validacao['ok'] ? 'OK' : 'ERRO';
            $resumo = $validacao['ok']
                ? 'A sintaxe da prévia foi validada com sucesso. Nenhuma regra foi aplicada.'
                : ($falhaExecucao !== null
                    ? $falhaExecucao . ' Nenhuma regra foi aplicada.'
                    : 'A prévia não passou na validação controlada. Nenhuma regra foi aplicada.');
            if ($previa['avisos']) {
                $resumo .= ' ' . implode(' ', $previa['avisos']);
            }
            $resultadoPersistido = [
                'status' => $status,
                'data_hora' => date(DATE_ATOM),
                'usuario' => audit_usuario_atual(),
                'resumo' => $resumo,
                'saida' => $validacao['saida'],
                'codigo' => $validacao['codigo'],
                'duracao_ms' => $validacao['duracao_ms'],
                'contagens' => $contagens,
                'hash_previa' => hash('sha256', $previa['regras']),
            ];
            firewall_salvar_ultima_validacao($pdo, $resultadoPersistido);
            firewall_auditar(
                $validacao['ok'] ? 'FIREWALL_VALIDAR_CONFIGURACAO' : 'FIREWALL_VALIDAR_CONFIGURACAO_ERRO',
                'Configuração nftables',
                $validacao['ok'] ? 'SUCCESS' : 'ERROR',
                $resumo . ' ' . $resumoContagens,
                'FIREWALL_VALIDACAO'
            );
            firewall_redirecionar(
                $validacao['ok'] ? 'success' : 'error',
                $validacao['ok'] ? 'Configuração validada com sucesso. Nenhuma regra foi aplicada.' : 'A configuração apresentou erro na validação. Nenhuma regra foi aplicada.'
            );
        }

        if ($acao === 'adicionar_ip') {
            $familiaEntrada = strtolower(trim((string) ($_POST['familia'] ?? '')));
            $familia = match ($familiaEntrada) {
                'ipv4' => 'IPv4',
                'ipv6' => 'IPv6',
                default => null,
            };
            if ($familia === null) {
                throw new InvalidArgumentException('Selecione uma família de IP válida.');
            }
            $tipoRegistroAuditoria = firewall_tipo_registro_acl($familia);
            $ip = firewall_normalizar_acl((string) ($_POST['rede'] ?? ''), $familia);
            $descricao = firewall_descricao((string) ($_POST['descricao'] ?? ''));
            if ($ip === null) {
                throw new InvalidArgumentException($familia === 'IPv4'
                    ? 'Informe um IPv4 válido.'
                    : 'Informe um IPv6 válido.');
            }

            $stmt = $pdo->prepare('SELECT 1 FROM firewall_admin_access WHERE rede = ? LIMIT 1');
            $stmt->execute([$ip['valor']]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('Este IP ou rede já está cadastrado.');
            }

            $stmt = $pdo->prepare('INSERT INTO firewall_admin_access (tipo, rede, descricao) VALUES (?, ?, ?)');
            $stmt->execute([$ip['tipo'], $ip['valor'], $descricao]);
            firewall_auditar($acaoAuditoria, $ip['valor'], 'SUCCESS', 'ACL administrativa adicionada.', firewall_tipo_registro_acl($ip['tipo']), null, $ip['valor']);
            firewall_redirecionar('success', 'IP administrativo adicionado com sucesso.');
        }

        if ($acao === 'editar_ip') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $descricao = firewall_descricao((string) ($_POST['descricao'] ?? ''));
            if (!$id) {
                throw new InvalidArgumentException('O acesso administrativo não foi encontrado.');
            }
            $stmt = $pdo->prepare('SELECT * FROM firewall_admin_access WHERE id = ?');
            $stmt->execute([$id]);
            $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$anterior) {
                throw new InvalidArgumentException('O acesso administrativo não foi encontrado.');
            }
            $tipoRegistroAuditoria = firewall_tipo_registro_acl($anterior['tipo']);
            $ip = firewall_normalizar_acl((string) ($_POST['rede'] ?? ''), $anterior['tipo']);
            if ($ip === null) {
                throw new InvalidArgumentException($anterior['tipo'] === 'IPv4'
                    ? 'Informe um IPv4 válido.'
                    : 'Informe um IPv6 válido.');
            }
            $stmt = $pdo->prepare('SELECT 1 FROM firewall_admin_access WHERE rede = ? AND id <> ? LIMIT 1');
            $stmt->execute([$ip['valor'], $id]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('Este IP ou rede já está cadastrado.');
            }
            $stmt = $pdo->prepare('UPDATE firewall_admin_access SET tipo = ?, rede = ?, descricao = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$ip['tipo'], $ip['valor'], $descricao, $id]);
            firewall_auditar($acaoAuditoria, $ip['valor'], 'SUCCESS', 'ACL administrativa atualizada.', firewall_tipo_registro_acl($ip['tipo']), $anterior['rede'], $ip['valor']);
            firewall_redirecionar('success', 'IP administrativo atualizado com sucesso.');
        }

        if ($acao === 'remover_ip') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $confirmacao = trim((string) ($_POST['confirmacao'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM firewall_admin_access WHERE id = ?');
            $stmt->execute([$id ?: 0]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$registro) {
                throw new InvalidArgumentException('O acesso administrativo não foi encontrado.');
            }
            $alvoInformado = $registro['rede'];
            if (!hash_equals($registro['rede'], $confirmacao)) {
                throw new InvalidArgumentException('A confirmação não corresponde ao IP ou rede informado.');
            }
            $tipoRegistroAuditoria = firewall_tipo_registro_acl($registro['tipo']);
            $stmt = $pdo->prepare('DELETE FROM firewall_admin_access WHERE id = ?');
            $stmt->execute([$id]);
            firewall_auditar($acaoAuditoria, $registro['rede'], 'SUCCESS', 'ACL administrativa removida.', firewall_tipo_registro_acl($registro['tipo']), $registro['rede'], null);
            firewall_redirecionar('success', 'IP administrativo removido com sucesso.');
        }

        if ($acao === 'adicionar_porta_admin' || $acao === 'adicionar_porta_publica') {
            $escopo = $acao === 'adicionar_porta_admin' ? 'admin' : 'publica';
            $tipoRegistroAuditoria = firewall_tipo_registro_porta($escopo);
            $porta = firewall_validar_porta($_POST['porta'] ?? null);
            $protocolo = strtoupper(trim((string) ($_POST['protocolo'] ?? '')));
            $servico = firewall_servico((string) ($_POST['servico'] ?? ''));
            $descricao = firewall_descricao((string) ($_POST['descricao'] ?? ''));
            if ($porta === null) {
                throw new InvalidArgumentException('Informe uma porta entre 1 e 65535.');
            }
            if (!in_array($protocolo, ['TCP', 'UDP', 'TCP/UDP'], true)) {
                throw new InvalidArgumentException('Selecione um protocolo válido.');
            }
            if ($servico === '') {
                throw new InvalidArgumentException('Informe um nome para o serviço.');
            }
            $stmt = $pdo->prepare('SELECT 1 FROM firewall_ports WHERE escopo = ? AND porta = ? LIMIT 1');
            $stmt->execute([$escopo, $porta]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('Esta porta já está cadastrada neste tipo.');
            }
            $stmt = $pdo->prepare('INSERT INTO firewall_ports (escopo, porta, protocolo, servico, descricao) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$escopo, $porta, $protocolo, $servico, $descricao]);
            $alvo = ($escopo === 'admin' ? 'Administrativa ' : 'Pública ') . $porta;
            firewall_auditar($acaoAuditoria, $alvo, 'SUCCESS', 'Porta cadastrada no painel.', firewall_tipo_registro_porta($escopo), null, $porta . '/' . $protocolo);
            firewall_redirecionar('success', 'Porta adicionada com sucesso.');
        }

        if ($acao === 'editar_porta') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $porta = firewall_validar_porta($_POST['porta'] ?? null);
            $protocolo = strtoupper(trim((string) ($_POST['protocolo'] ?? '')));
            $servico = firewall_servico((string) ($_POST['servico'] ?? ''));
            $descricao = firewall_descricao((string) ($_POST['descricao'] ?? ''));
            if (!$id) {
                throw new InvalidArgumentException('A porta não foi encontrada.');
            }
            $stmt = $pdo->prepare('SELECT * FROM firewall_ports WHERE id = ?');
            $stmt->execute([$id]);
            $anterior = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$anterior) {
                throw new InvalidArgumentException('A porta não foi encontrada.');
            }
            $tipoRegistroAuditoria = firewall_tipo_registro_porta($anterior['escopo']);
            if ($porta === null) {
                throw new InvalidArgumentException('Informe uma porta entre 1 e 65535.');
            }
            if (!in_array($protocolo, ['TCP', 'UDP', 'TCP/UDP'], true)) {
                throw new InvalidArgumentException('Selecione um protocolo válido.');
            }
            if ($servico === '') {
                throw new InvalidArgumentException('Informe um nome para o serviço.');
            }
            $stmt = $pdo->prepare('SELECT 1 FROM firewall_ports WHERE escopo = ? AND porta = ? AND id <> ? LIMIT 1');
            $stmt->execute([$anterior['escopo'], $porta, $id]);
            if ($stmt->fetchColumn()) {
                throw new InvalidArgumentException('Esta porta já está cadastrada neste tipo.');
            }
            $stmt = $pdo->prepare('UPDATE firewall_ports SET porta = ?, protocolo = ?, servico = ?, descricao = ?, atualizado_em = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([$porta, $protocolo, $servico, $descricao, $id]);
            $alvo = ($anterior['escopo'] === 'admin' ? 'Administrativa ' : 'Pública ') . $porta;
            firewall_auditar($acaoAuditoria, $alvo, 'SUCCESS', 'Porta atualizada no painel.', firewall_tipo_registro_porta($anterior['escopo']), $anterior['porta'] . '/' . $anterior['protocolo'], $porta . '/' . $protocolo);
            firewall_redirecionar('success', 'Porta atualizada com sucesso.');
        }

        if ($acao === 'remover_porta') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $confirmacao = trim((string) ($_POST['confirmacao'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM firewall_ports WHERE id = ?');
            $stmt->execute([$id ?: 0]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$registro) {
                throw new InvalidArgumentException('A porta não foi encontrada.');
            }
            $alvoInformado = ($registro['escopo'] === 'admin' ? 'Administrativa ' : 'Pública ') . $registro['porta'];
            if (!hash_equals((string) $registro['porta'], $confirmacao)) {
                throw new InvalidArgumentException('A confirmação não corresponde à porta informada.');
            }
            $tipoRegistroAuditoria = firewall_tipo_registro_porta($registro['escopo']);
            $stmt = $pdo->prepare('DELETE FROM firewall_ports WHERE id = ?');
            $stmt->execute([$id]);
            $alvo = ($registro['escopo'] === 'admin' ? 'Administrativa ' : 'Pública ') . $registro['porta'];
            firewall_auditar($acaoAuditoria, $alvo, 'SUCCESS', 'Porta removida do painel.', firewall_tipo_registro_porta($registro['escopo']), $registro['porta'] . '/' . $registro['protocolo'], null);
            firewall_redirecionar('success', 'Porta removida com sucesso.');
        }
    } catch (PDOException $e) {
        $duplicado = str_contains(strtolower($e->getMessage()), 'unique constraint');
        $mensagem = $duplicado ? 'Este item já está cadastrado.' : 'Não foi possível salvar a alteração.';
        firewall_auditar($acaoAuditoria, $alvoInformado, 'ERROR', $mensagem, $tipoRegistroAuditoria);
        firewall_redirecionar('error', $mensagem);
    } catch (InvalidArgumentException $e) {
        firewall_auditar($acaoAuditoria, $alvoInformado, 'ERROR', $e->getMessage(), $tipoRegistroAuditoria);
        firewall_redirecionar('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Firewall - falha na operação: ' . $e->getMessage());
        firewall_auditar($acaoAuditoria, $alvoInformado, 'ERROR', 'Falha ao processar a alteração.', $tipoRegistroAuditoria);
        firewall_redirecionar('error', 'Não foi possível concluir a alteração.');
    }
}

$flash = $_SESSION['firewall_flash'] ?? null;
unset($_SESSION['firewall_flash']);
$toastMensagemInicial = '';
$toastTipoInicial = 'info';
if (is_array($flash)) {
    $toastMensagemInicial = (string) ($flash['mensagem'] ?? '');
    $toastTipoInicial = ($flash['tipo'] ?? '') === 'success' ? 'success' : 'error';
}

$aclIpv4 = [];
$aclIpv6 = [];
$adminPorts = [];
$publicPorts = [];
$recentAudit = [];
$firewallLoadWarning = null;
$ultimaValidacao = null;
$previaAtual = null;
$previaErro = null;

try {
    $configuracaoAtual = firewall_carregar_configuracao($pdo);
    $aclIpv4 = $configuracaoAtual['acl_ipv4'];
    $aclIpv6 = $configuracaoAtual['acl_ipv6'];
    $adminPorts = $configuracaoAtual['portas_admin'];
    $publicPorts = $configuracaoAtual['portas_publicas'];
    $ultimaValidacao = firewall_carregar_ultima_validacao($pdo);
    try {
        $previaAtual = firewall_gerar_previa($configuracaoAtual);
    } catch (Throwable $e) {
        $previaErro = $e->getMessage();
    }

    $auditStmt = $pdo->query("
        SELECT usuario, acao, tipo_registro, nome_registro, status, mensagem, criado_em
        FROM audit_logs
        WHERE acao LIKE 'FIREWALL_%'
        ORDER BY id DESC
        LIMIT 4
    ");
    $recentAudit = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Firewall - leitura indisponivel: ' . $e->getMessage());
    $firewallLoadWarning = 'Os dados do firewall estao temporariamente indisponiveis. A pagina abriu em modo seguro.';
}

if ($firewallLoadWarning !== null && $toastMensagemInicial === '') {
    $toastMensagemInicial = $firewallLoadWarning;
    $toastTipoInicial = 'error';
}

$ipv4Count = count($aclIpv4);
$ipv6Count = count($aclIpv6);
$summary = [
    ['◉', 'IPv4 Liberados', (string) $ipv4Count, 'Redes e endereços', false],
    ['⬡', 'IPv6 Liberados', (string) $ipv6Count, 'Redes e endereços', false],
    ['⌁', 'Portas Admin', (string) count($adminPorts), 'Acesso restrito', false],
    ['⇄', 'Portas Públicas', (string) count($publicPorts), 'Acesso externo', false],
    ['✓', 'Validação', $ultimaValidacao['status'] ?? 'Não validado', 'Nenhuma aplicação automática', ($ultimaValidacao['status'] ?? '') === 'OK'],
];
$quickActions = [
    ['+', 'Adicionar IP', 'Autorizar endereço', 'add-ip-modal'],
    ['🔒', 'Porta Admin', 'Adicionar restrição', 'add-admin-port-modal'],
    ['🌐', 'Porta Pública', 'Liberar serviço', 'add-public-port-modal'],
    ['▣', 'Backup', 'Fase futura', null],
    ['✓', 'Validar', 'Checar sintaxe nftables', 'validate-firewall'],
    ['↻', 'Aplicar', 'Fase futura', null],
    ['≡', 'Ver Logs', 'Consultar eventos', null],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Firewall</title>
<style>
:root{color-scheme:dark;--bg:#080d18;--panel:#0b1424;--deep:#050a13;--raised:#101b2e;--line:#1e2c42;--line2:#33465f;--text:#e5edf7;--muted:#91a0b5;--subtle:#64748b;--accent:#38bdf8;--ok:#4ade80;--danger:#f87171}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(circle at 50% -15%,#15243e 0,var(--bg) 42%);color:var(--text);font-family:Arial,sans-serif;min-height:100vh}
button,input,a{font:inherit}button{color:inherit}a{color:inherit;text-decoration:none}
.page{width:min(1440px,calc(100% - 40px));margin:26px auto 38px}
.page-header{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin-bottom:22px}.back-link{display:inline-flex;color:#a8b7ca;font-size:13px}.back-link:hover,.back-link:focus{color:var(--accent);outline:none}
.page-header h1{margin:10px 0 5px;color:#fff;font-size:31px;letter-spacing:-.025em}.page-header p{margin:0;color:var(--muted)}
.refresh-button{display:inline-flex;align-items:center;gap:8px;min-height:39px;padding:9px 13px;border:1px solid var(--line2);border-radius:9px;background:var(--raised);color:#dce8f5;font-weight:700;cursor:pointer}
.refresh-button:hover,.refresh-button:focus{border-color:var(--accent);outline:none}
.summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:22px}
.summary-card{position:relative;min-height:112px;padding:16px 16px 15px 58px;border:1px solid var(--line);border-radius:13px;background:linear-gradient(145deg,var(--raised),var(--deep));box-shadow:0 12px 30px #0003}
.summary-icon{position:absolute;left:16px;top:17px;display:grid;place-items:center;width:30px;height:30px;border:1px solid #38bdf838;border-radius:9px;background:#0c4a6e33;color:#7dd3fc;font-size:15px;font-weight:800}
.summary-icon.status{border-color:#22c55e42;background:#14532d52;color:#86efac}
.summary-label{display:block;color:var(--muted);font-size:11px;letter-spacing:.04em;text-transform:uppercase}
.summary-value{display:block;margin-top:8px;color:var(--accent);font-size:25px;font-weight:800}.summary-value.status{color:var(--ok);font-size:22px}
.summary-detail{display:block;margin-top:7px;color:var(--subtle);font-size:11px}
.panel{padding:20px;border:1px solid var(--line);border-radius:14px;background:linear-gradient(160deg,#0d182a,var(--panel));box-shadow:0 14px 36px #0003;min-width:0}
.quick-panel{margin-bottom:22px}
.panel-header{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:17px}.panel-header h2{margin:0;color:#fff;font-size:18px}
.panel-header p{margin:5px 0 0;color:var(--muted);font-size:13px;line-height:1.45}
.panel-header .header-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap}
.quick-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:10px}
.quick-action{min-height:82px;padding:13px;border:1px solid var(--line);border-radius:11px;background:var(--deep);text-align:left;cursor:pointer;transition:.15s}
.quick-action:hover,.quick-action:focus{border-color:var(--accent);outline:none;transform:translateY(-2px)}
.quick-icon{display:block;height:19px;color:var(--accent);font-size:17px;font-weight:800}.quick-action strong{display:block;margin-top:8px;font-size:13px}
.quick-action small{display:block;margin-top:5px;color:var(--subtle);font-size:11px}
.content-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:18px;align-items:start}
.stack{display:grid;gap:18px}
.search{width:min(280px,100%);min-height:38px;padding:8px 11px;border:1px solid var(--line2);border-radius:10px;background:var(--deep);color:#fff;outline:none}
.search::placeholder{color:var(--subtle)}.search:focus{border-color:var(--accent);box-shadow:0 0 0 3px #38bdf81a}
.table-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:11px}table{width:100%;border-collapse:collapse;min-width:610px}
th,td{padding:11px 12px;border-bottom:1px solid var(--line);text-align:left;font-size:12px}th{background:var(--deep);color:var(--muted);font-size:9px;letter-spacing:.05em;text-transform:uppercase}
tr:last-child td{border-bottom:0}tbody tr{background:#02061766}tbody tr:hover{background:#0f172ab8}
.type-badge{display:inline-flex;padding:4px 8px;border:1px solid #38bdf838;border-radius:999px;background:#0e74901f;color:#bae6fd;font-size:11px;font-weight:700}
.family-badge{display:inline-flex;padding:4px 8px;border:1px solid var(--line2);border-radius:999px;background:#111827;color:#cbd5e1;font-size:11px;font-weight:700}
.strong{color:#fff;font-weight:700}.row-actions{display:flex;align-items:center;gap:7px;white-space:nowrap}
.text-action{padding:5px 8px;border:1px solid var(--line2);border-radius:7px;background:#101827;color:#cfeeff;cursor:pointer;font-size:11px;font-weight:700}.text-action:hover,.text-action:focus{border-color:var(--accent);outline:none}.text-action.remove{color:#fecaca}.text-action.remove:hover,.text-action.remove:focus{border-color:var(--danger)}
.validation{display:flex;gap:13px;min-height:128px;padding:17px;border:1px solid #22c55e4d;border-radius:11px;background:linear-gradient(135deg,#14532d38,#07170e)}
.validation.error{border-color:#ef44444d;background:linear-gradient(135deg,#7f1d1d38,#170707)}.validation.pending{border-color:#33465f;background:linear-gradient(135deg,#172236,#080d18)}
.validation-icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 30px;width:30px;height:30px;border-radius:50%;background:#14532d;color:#bbf7d0;font-weight:800}
.validation.error .validation-icon{background:#7f1d1d;color:#fecaca}.validation.pending .validation-icon{background:#1e293b;color:#cbd5e1}
.validation strong{display:block;margin:2px 0 13px;color:#bbf7d0;font-size:15px}.validation span{display:block;color:var(--muted);font-size:12px;line-height:1.65}.validation time,.validation em{color:var(--text);font-style:normal;font-weight:700}
.validation.error strong{color:#fecaca}.validation.pending strong{color:#cbd5e1}
.preview-panel{margin-top:18px}.rule-preview{max-height:430px;overflow:auto;margin:0;padding:16px;border:1px solid var(--line);border-radius:11px;background:#020617;color:#cbd5e1;font:12px/1.65 Consolas,Monaco,monospace;white-space:pre}.preview-note{margin:0 0 12px;color:var(--muted);font-size:12px;line-height:1.55}.technical-output{margin-top:13px;border-top:1px solid var(--line);padding-top:11px}.technical-output summary{color:#bae6fd;cursor:pointer;font-size:12px;font-weight:700}.technical-output pre{overflow:auto;max-height:240px;margin:10px 0 0;padding:12px;border:1px solid var(--line);border-radius:9px;background:#020617;color:#cbd5e1;font:11px/1.55 Consolas,Monaco,monospace;white-space:pre-wrap}
.inline-form{margin:0}.quick-action.validate{width:100%}
.status-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-top:18px}
.audit-list{display:grid;gap:10px}
.audit-item{display:grid;grid-template-columns:minmax(92px,.4fr) minmax(0,1fr) auto;gap:14px;align-items:start;padding:12px 0;border-bottom:1px solid var(--line);font-size:13px}
.audit-item:first-child{padding-top:0}.audit-item:last-child{border-bottom:0}.audit-user{display:block;color:#fff;font-weight:700}.audit-kind{display:block;margin-top:4px;color:var(--subtle);font-size:11px;letter-spacing:.04em;text-transform:uppercase}.audit-action{color:#cbd5e1}.audit-time{color:var(--subtle);font-size:11px;white-space:nowrap}
.secondary-button{display:inline-flex;align-items:center;justify-content:center;min-height:36px;margin-top:14px;padding:8px 12px;border:1px solid var(--line2);border-radius:9px;background:#0f172a;color:#dbeafe;font-size:12px;font-weight:700}
.secondary-button:hover,.secondary-button:focus{border-color:var(--accent);outline:none}
.alerts{display:grid;gap:10px;margin-bottom:18px}.alert{padding:12px 14px;border:1px solid;border-radius:10px;font-size:13px}.alert.success{border-color:#22c55e4d;background:#14532d52;color:#bbf7d0}.alert.error{border-color:#ef44444d;background:#7f1d1d52;color:#fecaca}
.empty-state{padding:22px 14px!important;color:var(--muted);text-align:center}
.modal{width:min(560px,calc(100vw - 28px));padding:0;border:1px solid var(--line2);border-radius:14px;background:#0b1424;color:var(--text);box-shadow:0 26px 70px #000a}
.modal::backdrop{background:#020617d9;backdrop-filter:blur(3px)}
.modal-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:18px 20px;border-bottom:1px solid var(--line)}
.modal-header h2{margin:0;color:#fff;font-size:19px}.modal-header p{margin:5px 0 0;color:var(--muted);font-size:12px;line-height:1.45}
.modal-close{padding:7px 9px;border:1px solid var(--line2);border-radius:8px;background:var(--deep);color:#cbd5e1;cursor:pointer}.modal-close:hover,.modal-close:focus{border-color:var(--accent);outline:none}
.modal-body{padding:20px}.modal-form{display:grid;gap:14px}.form-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.field label{display:block;margin-bottom:6px;color:#cbd5e1;font-size:12px;font-weight:700}.field input,.field select{width:100%;min-height:40px;padding:9px 11px;border:1px solid var(--line2);border-radius:9px;background:var(--deep);color:#fff;outline:none}
.field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 3px #38bdf81a}.field-help{display:block;margin-top:5px;color:var(--subtle);font-size:11px}
.button.small{min-height:34px;padding:7px 11px;font-size:12px}
.modal-warning{padding:11px 12px;border:1px solid #ef44444d;border-radius:9px;background:#7f1d1d38;color:#fecaca;font-size:12px;line-height:1.45}
.modal-actions{display:flex;justify-content:flex-end;gap:9px;padding-top:4px}.button{min-height:39px;padding:9px 13px;border:1px solid var(--line2);border-radius:9px;background:#172236;color:#e5edf7;font-weight:700;cursor:pointer}.button:hover,.button:focus{border-color:var(--accent);outline:none}.button.primary{border-color:#0369a1;background:#0369a1;color:#fff}.button.danger{border-color:#991b1b;background:#7f1d1d;color:#fff}
.ui-toast{position:fixed;right:20px;bottom:20px;z-index:20;max-width:min(380px,calc(100vw - 40px));padding:12px 14px;border:1px solid var(--line2);border-radius:11px;background:#111827;color:#cbd5e1;box-shadow:0 18px 45px #0006;font-size:13px}
.ui-toast.success{border-color:#22c55e4d;background:#14532d52;color:#bbf7d0}
.ui-toast.error{border-color:#ef44444d;background:#7f1d1d52;color:#fecaca}
.ui-toast[hidden],.empty-row[hidden]{display:none}.empty-row td{padding:22px 14px;color:var(--muted);text-align:center}
@media(max-width:1200px){.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.content-grid,.status-grid{grid-template-columns:1fr}}
@media(max-width:1050px){.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:760px){.page{width:min(100% - 20px,1180px);margin:20px auto 30px}.page-header{align-items:flex-start;flex-direction:column}.page-header h1{font-size:27px}.summary-grid,.quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.panel{padding:16px}.panel-header{display:block}.search{width:100%;margin-top:13px}.audit-item{grid-template-columns:80px 1fr}.audit-time{grid-column:2}.form-row{grid-template-columns:1fr}.modal-actions{display:grid}.modal-actions .button{width:100%}}
@media(max-width:460px){.summary-grid,.quick-grid{grid-template-columns:1fr}.summary-card{min-height:96px}}
</style>
</head>
<body>
<main class="page">
    <header class="page-header">
        <div>
            <a class="back-link" href="dashboard.php">← Voltar ao painel</a>
            <h1>Firewall</h1>
            <p>Controle de acesso e portas públicas.</p>
        </div>
        <button class="refresh-button" type="button" data-refresh-page><span aria-hidden="true">↻</span> Atualizar</button>
    </header>

    <section class="summary-grid" aria-label="Resumo do Firewall">
        <?php foreach ($summary as [$icon, $label, $value, $detail, $status]): ?>
            <article class="summary-card">
                <span class="summary-icon<?= $status ? ' status' : '' ?>" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
                <span class="summary-label"><?= htmlspecialchars($label) ?></span>
                <span class="summary-value<?= $status ? ' status' : '' ?>"><?= htmlspecialchars($value) ?></span>
                <span class="summary-detail"><?= htmlspecialchars($detail) ?></span>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="panel quick-panel">
        <header class="panel-header"><div><h2>Ações rápidas</h2><p>Atalhos para as operações mais utilizadas.</p></div></header>
        <div class="quick-grid">
            <?php foreach ($quickActions as [$icon, $label, $detail, $modalId]): ?>
                <?php if ($modalId === 'validate-firewall'): ?>
                    <form method="POST" class="inline-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="validar_configuracao">
                        <button class="quick-action validate" type="submit">
                            <span class="quick-icon" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
                            <strong><?= htmlspecialchars($label) ?></strong>
                            <small><?= htmlspecialchars($detail) ?></small>
                        </button>
                    </form>
                    <?php continue; ?>
                <?php endif; ?>
                <button
                    class="quick-action"
                    type="button"
                    <?= $modalId !== null
                        ? 'data-open-dialog="' . htmlspecialchars($modalId, ENT_QUOTES, 'UTF-8') . '"'
                        : 'data-future-action="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"' ?>>
                    <span class="quick-icon" aria-hidden="true"><?= htmlspecialchars($icon) ?></span>
                    <strong><?= htmlspecialchars($label) ?></strong>
                    <small><?= htmlspecialchars($detail) ?></small>
                </button>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="content-grid">
        <div class="stack">
            <section class="panel">
                <header class="panel-header">
                    <div><h2>ACL IPv4</h2><p>IPs autorizados para acesso administrativo em IPv4.</p></div>
                    <div class="header-actions">
                        <input class="search" id="acl-ipv4-search" data-search-target="acl-ipv4-rows" type="search" placeholder="Pesquisar IPv4..." aria-label="Pesquisar IPv4">
                        <button class="button small" type="button" data-open-dialog="add-ip-modal" data-default-family="IPv4">Adicionar IPv4</button>
                    </div>
                </header>
                <div class="table-wrap"><table>
                    <thead><tr><th>Tipo</th><th>IP/Rede</th><th>Descrição</th><th>Data de criação</th><th>Ações</th></tr></thead>
                    <tbody id="acl-ipv4-rows">
                        <?php foreach ($aclIpv4 as $access): ?>
                            <?php $createdAt = (new DateTimeImmutable($access['criado_em']))->format('d/m/Y H:i'); ?>
                            <tr data-search-text="<?= htmlspecialchars(strtolower($access['tipo'] . ' ' . $access['rede'] . ' ' . $access['descricao'] . ' ' . $createdAt), ENT_QUOTES, 'UTF-8') ?>">
                                <td><span class="type-badge"><?= htmlspecialchars($access['tipo']) ?></span></td>
                                <td class="strong"><?= htmlspecialchars($access['rede']) ?></td>
                                <td><?= htmlspecialchars($access['descricao']) ?></td>
                                <td><?= htmlspecialchars($createdAt) ?></td>
                                <td><div class="row-actions">
                                    <button class="text-action" type="button" data-open-dialog="edit-ip-modal" data-record-id="<?= (int) $access['id'] ?>" data-record-family="<?= htmlspecialchars($access['tipo'], ENT_QUOTES, 'UTF-8') ?>" data-record-value="<?= htmlspecialchars($access['rede'], ENT_QUOTES, 'UTF-8') ?>" data-record-description="<?= htmlspecialchars($access['descricao'], ENT_QUOTES, 'UTF-8') ?>">Editar</button>
                                    <button class="text-action remove" type="button" data-open-dialog="remove-ip-modal" data-record-id="<?= (int) $access['id'] ?>" data-record-family="<?= htmlspecialchars($access['tipo'], ENT_QUOTES, 'UTF-8') ?>" data-record-value="<?= htmlspecialchars($access['rede'], ENT_QUOTES, 'UTF-8') ?>">Remover</button>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$aclIpv4): ?><tr><td class="empty-state" colspan="5">Nenhum IPv4 administrativo cadastrado.</td></tr><?php endif; ?>
                        <tr class="empty-row" id="acl-ipv4-empty" hidden><td colspan="5">Nenhum IPv4 encontrado.</td></tr>
                    </tbody>
                </table></div>
            </section>

            <section class="panel">
                <header class="panel-header">
                    <div><h2>ACL IPv6</h2><p>IPs autorizados para acesso administrativo em IPv6.</p></div>
                    <div class="header-actions">
                        <input class="search" id="acl-ipv6-search" data-search-target="acl-ipv6-rows" type="search" placeholder="Pesquisar IPv6..." aria-label="Pesquisar IPv6">
                        <button class="button small" type="button" data-open-dialog="add-ip-modal" data-default-family="IPv6">Adicionar IPv6</button>
                    </div>
                </header>
                <div class="table-wrap"><table>
                    <thead><tr><th>Tipo</th><th>IP/Rede</th><th>Descrição</th><th>Data de criação</th><th>Ações</th></tr></thead>
                    <tbody id="acl-ipv6-rows">
                        <?php foreach ($aclIpv6 as $access): ?>
                            <?php $createdAt = (new DateTimeImmutable($access['criado_em']))->format('d/m/Y H:i'); ?>
                            <tr data-search-text="<?= htmlspecialchars(strtolower($access['tipo'] . ' ' . $access['rede'] . ' ' . $access['descricao'] . ' ' . $createdAt), ENT_QUOTES, 'UTF-8') ?>">
                                <td><span class="type-badge"><?= htmlspecialchars($access['tipo']) ?></span></td>
                                <td class="strong"><?= htmlspecialchars($access['rede']) ?></td>
                                <td><?= htmlspecialchars($access['descricao']) ?></td>
                                <td><?= htmlspecialchars($createdAt) ?></td>
                                <td><div class="row-actions">
                                    <button class="text-action" type="button" data-open-dialog="edit-ip-modal" data-record-id="<?= (int) $access['id'] ?>" data-record-family="<?= htmlspecialchars($access['tipo'], ENT_QUOTES, 'UTF-8') ?>" data-record-value="<?= htmlspecialchars($access['rede'], ENT_QUOTES, 'UTF-8') ?>" data-record-description="<?= htmlspecialchars($access['descricao'], ENT_QUOTES, 'UTF-8') ?>">Editar</button>
                                    <button class="text-action remove" type="button" data-open-dialog="remove-ip-modal" data-record-id="<?= (int) $access['id'] ?>" data-record-family="<?= htmlspecialchars($access['tipo'], ENT_QUOTES, 'UTF-8') ?>" data-record-value="<?= htmlspecialchars($access['rede'], ENT_QUOTES, 'UTF-8') ?>">Remover</button>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$aclIpv6): ?><tr><td class="empty-state" colspan="5">Nenhum IPv6 administrativo cadastrado.</td></tr><?php endif; ?>
                        <tr class="empty-row" id="acl-ipv6-empty" hidden><td colspan="5">Nenhum IPv6 encontrado.</td></tr>
                    </tbody>
                </table></div>
            </section>
        </div>

        <div class="stack">
            <section class="panel">
                <header class="panel-header">
                    <div><h2>Portas Administrativas</h2><p>Portas restritas aos IPs autorizados.</p></div>
                    <div class="header-actions">
                        <span class="family-badge">Admin</span>
                        <button class="button small" type="button" data-open-dialog="add-admin-port-modal">Adicionar porta</button>
                    </div>
                </header>
                <div class="table-wrap"><table>
                    <thead><tr><th>Porta</th><th>Protocolo</th><th>Serviço</th><th>Descrição</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($adminPorts as $port): ?>
                            <tr>
                                <td class="strong"><?= (int) $port['porta'] ?></td>
                                <td><span class="type-badge"><?= htmlspecialchars($port['protocolo']) ?></span></td>
                                <td><?= htmlspecialchars($port['servico']) ?></td>
                                <td><?= htmlspecialchars($port['descricao']) ?></td>
                                <td><div class="row-actions">
                                    <button class="text-action" type="button" data-open-dialog="edit-port-modal" data-record-id="<?= (int) $port['id'] ?>" data-record-scope="admin" data-record-port="<?= (int) $port['porta'] ?>" data-record-protocol="<?= htmlspecialchars($port['protocolo'], ENT_QUOTES, 'UTF-8') ?>" data-record-service="<?= htmlspecialchars($port['servico'], ENT_QUOTES, 'UTF-8') ?>" data-record-description="<?= htmlspecialchars($port['descricao'], ENT_QUOTES, 'UTF-8') ?>">Editar</button>
                                    <button class="text-action remove" type="button" data-open-dialog="remove-port-modal" data-record-id="<?= (int) $port['id'] ?>" data-record-scope="administrativa" data-record-value="<?= (int) $port['porta'] ?>">Remover</button>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$adminPorts): ?><tr><td class="empty-state" colspan="5">Nenhuma porta administrativa cadastrada.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="panel">
                <header class="panel-header">
                    <div><h2>Portas Públicas</h2><p>Disponíveis para acesso externo.</p></div>
                    <div class="header-actions">
                        <span class="family-badge">Pública</span>
                        <button class="button small" type="button" data-open-dialog="add-public-port-modal">Adicionar porta</button>
                    </div>
                </header>
                <div class="table-wrap"><table>
                    <thead><tr><th>Porta</th><th>Protocolo</th><th>Serviço</th><th>Descrição</th><th>Ações</th></tr></thead>
                    <tbody>
                        <?php foreach ($publicPorts as $port): ?>
                            <tr>
                                <td class="strong"><?= (int) $port['porta'] ?></td>
                                <td><span class="type-badge"><?= htmlspecialchars($port['protocolo']) ?></span></td>
                                <td><?= htmlspecialchars($port['servico']) ?></td>
                                <td><?= htmlspecialchars($port['descricao']) ?></td>
                                <td><div class="row-actions">
                                    <button class="text-action" type="button" data-open-dialog="edit-port-modal" data-record-id="<?= (int) $port['id'] ?>" data-record-scope="publica" data-record-port="<?= (int) $port['porta'] ?>" data-record-protocol="<?= htmlspecialchars($port['protocolo'], ENT_QUOTES, 'UTF-8') ?>" data-record-service="<?= htmlspecialchars($port['servico'], ENT_QUOTES, 'UTF-8') ?>" data-record-description="<?= htmlspecialchars($port['descricao'], ENT_QUOTES, 'UTF-8') ?>">Editar</button>
                                    <button class="text-action remove" type="button" data-open-dialog="remove-port-modal" data-record-id="<?= (int) $port['id'] ?>" data-record-scope="pública" data-record-value="<?= (int) $port['porta'] ?>">Remover</button>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$publicPorts): ?><tr><td class="empty-state" colspan="5">Nenhuma porta pública cadastrada.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
            </section>
        </div>
    </div>

    <section class="panel preview-panel">
        <header class="panel-header">
            <div><h2>Prévia das regras</h2><p>Conteúdo somente leitura gerado a partir dos dados cadastrados.</p></div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="validar_configuracao">
                <button class="button primary" type="submit">Validar configuração</button>
            </form>
        </header>
        <p class="preview-note">Esta prévia não altera o firewall ativo. A validação executa apenas checagem de sintaxe e remove o arquivo temporário ao final.</p>
        <?php if ($previaAtual !== null): ?>
            <?php foreach ($previaAtual['avisos'] as $aviso): ?><div class="alert error"><?= htmlspecialchars($aviso) ?></div><?php endforeach; ?>
            <pre class="rule-preview" tabindex="0"><?= htmlspecialchars($previaAtual['regras']) ?></pre>
        <?php else: ?>
            <div class="alert error"><?= htmlspecialchars($previaErro ?? 'Não foi possível gerar a prévia.') ?></div>
        <?php endif; ?>
    </section>

    <div class="status-grid">
        <section class="panel">
            <header class="panel-header"><div><h2>Última Validação</h2><p>Resultado da verificação mais recente.</p></div></header>
            <?php
            $statusValidacao = $ultimaValidacao['status'] ?? 'NÃO VALIDADO';
            $classeValidacao = $statusValidacao === 'OK' ? '' : ($statusValidacao === 'ERRO' ? ' error' : ' pending');
            $iconeValidacao = $statusValidacao === 'OK' ? '✓' : ($statusValidacao === 'ERRO' ? '!' : '–');
            $tituloValidacao = $statusValidacao === 'OK' ? 'Configuração válida' : ($statusValidacao === 'ERRO' ? 'Erro na validação' : 'Configuração não validada');
            $dataValidacao = null;
            if (!empty($ultimaValidacao['data_hora'])) {
                try {
                    $dataValidacao = new DateTimeImmutable((string) $ultimaValidacao['data_hora']);
                } catch (Throwable) {
                    $dataValidacao = null;
                }
            }
            ?>
            <div class="validation<?= $classeValidacao ?>"><span class="validation-icon" aria-hidden="true"><?= $iconeValidacao ?></span><div>
                <strong><?= htmlspecialchars($tituloValidacao) ?></strong>
                <?php if ($ultimaValidacao !== null): ?>
                    <span>Data/hora: <time<?= $dataValidacao ? ' datetime="' . htmlspecialchars($dataValidacao->format(DATE_ATOM), ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($dataValidacao ? $dataValidacao->format('d/m/Y H:i:s') : 'Não informada') ?></time></span>
                    <span>Usuário: <em><?= htmlspecialchars((string) ($ultimaValidacao['usuario'] ?? 'desconhecido')) ?></em></span>
                    <span><?= htmlspecialchars((string) ($ultimaValidacao['resumo'] ?? 'Sem resumo disponível.')) ?></span>
                    <details class="technical-output"><summary>Ver saída técnica</summary><pre><?= htmlspecialchars((string) ($ultimaValidacao['saida'] ?? 'Sem saída técnica.')) ?></pre></details>
                <?php else: ?>
                    <span>Use “Validar configuração” para checar a sintaxe da prévia.</span>
                    <span>Nenhuma regra será aplicada nesta fase.</span>
                <?php endif; ?>
            </div></div>
        </section>
        <section class="panel">
            <header class="panel-header"><div><h2>Auditoria Recente</h2><p>Últimas alterações.</p></div></header>
            <div class="audit-list">
                <?php foreach ($recentAudit as $event): ?>
                    <?php $eventTime = (new DateTimeImmutable($event['criado_em']))->format('d/m H:i'); ?>
                    <div class="audit-item">
                        <div>
                            <span class="audit-user"><?= htmlspecialchars($event['usuario']) ?></span>
                            <span class="audit-kind"><?= htmlspecialchars((string) ($event['tipo_registro'] ?? 'FIREWALL')) ?></span>
                        </div>
                        <div>
                            <div class="audit-action"><?= htmlspecialchars($event['mensagem'] ?: $event['acao']) ?></div>
                            <div class="audit-kind"><?= htmlspecialchars((string) ($event['nome_registro'] ?? '')) ?></div>
                        </div>
                        <span class="audit-time"><?= htmlspecialchars($eventTime) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (!$recentAudit): ?><div class="empty-state">Nenhuma alteração registrada.</div><?php endif; ?>
            </div>
            <a class="secondary-button" href="auditoria.php">Ver histórico completo</a>
        </section>
    </div>

    <dialog class="modal" id="add-ip-modal">
        <header class="modal-header"><div><h2>Adicionar ACL</h2><p>Autorizar um endereço ou rede para acesso administrativo.</p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
        <div class="modal-body">
            <form method="POST" class="modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="adicionar_ip">
                <div class="form-row">
                    <div class="field">
                        <label for="add-ip-family">Família</label>
                        <select id="add-ip-family" name="familia" required data-modal-family-select>
                            <option value="">Selecione</option>
                            <option value="IPv4">IPv4</option>
                            <option value="IPv6">IPv6</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="add-ip-description">Descrição</label>
                        <input id="add-ip-description" name="descricao" maxlength="120" placeholder="Acesso da equipe técnica">
                    </div>
                </div>
                <div class="field"><label for="add-ip-network">IP ou rede</label><input id="add-ip-network" name="rede" maxlength="80" placeholder="192.0.2.10 ou 2001:db8::/64" required><span class="field-help">IPv4, IPv6 ou rede com prefixo CIDR.</span></div>
                <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button primary" type="submit">Adicionar ACL</button></div>
            </form>
        </div>
    </dialog>

    <dialog class="modal" id="edit-ip-modal">
        <header class="modal-header"><div><h2>Editar ACL</h2><p>Atualizar o endereço, rede ou descrição do acesso.</p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
        <div class="modal-body">
            <form method="POST" class="modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="editar_ip">
                <input type="hidden" name="id" data-modal-id>
                <input type="hidden" name="familia" data-modal-family>
                <div class="field">
                    <label>Família</label>
                    <span class="family-badge" data-modal-family-label>IPv4</span>
                </div>
                <div class="field"><label for="edit-ip-network">IP ou rede</label><input id="edit-ip-network" name="rede" maxlength="80" data-modal-value required></div>
                <div class="field"><label for="edit-ip-description">Descrição</label><input id="edit-ip-description" name="descricao" maxlength="120" data-modal-description></div>
                <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button primary" type="submit">Salvar alterações</button></div>
            </form>
        </div>
    </dialog>

    <dialog class="modal" id="remove-ip-modal">
        <header class="modal-header"><div><h2>Remover ACL</h2><p>Esta ação remove o acesso apenas dos dados do painel.</p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
        <div class="modal-body">
            <form method="POST" class="modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="remover_ip">
                <input type="hidden" name="id" data-modal-id>
                <div class="field">
                    <label>Família</label>
                    <span class="family-badge" data-modal-family-label>IPv4</span>
                </div>
                <div class="modal-warning">Para confirmar, digite exatamente <strong data-confirmation-label></strong>.</div>
                <div class="field"><label for="remove-ip-confirmation">Confirmação</label><input id="remove-ip-confirmation" name="confirmacao" autocomplete="off" data-confirmation-input required></div>
                <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button danger" type="submit">Remover ACL</button></div>
            </form>
        </div>
    </dialog>

    <?php foreach ([
        ['add-admin-port-modal', 'adicionar_porta_admin', 'Adicionar Porta Administrativa', 'Cadastrar uma porta restrita aos IPs autorizados.'],
        ['add-public-port-modal', 'adicionar_porta_publica', 'Adicionar Porta Pública', 'Cadastrar uma porta disponível para acesso externo.'],
    ] as [$modalId, $actionName, $title, $subtitle]): ?>
        <dialog class="modal" id="<?= htmlspecialchars($modalId) ?>">
            <header class="modal-header"><div><h2><?= htmlspecialchars($title) ?></h2><p><?= htmlspecialchars($subtitle) ?></p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
            <div class="modal-body">
                <form method="POST" class="modal-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="acao" value="<?= htmlspecialchars($actionName) ?>">
                    <div class="form-row">
                        <div class="field"><label>Porta</label><input type="number" name="porta" min="1" max="65535" inputmode="numeric" required></div>
                        <div class="field"><label>Protocolo</label><select name="protocolo" required><option value="TCP">TCP</option><option value="UDP">UDP</option><option value="TCP/UDP">TCP/UDP</option></select></div>
                    </div>
                    <div class="field"><label>Serviço</label><input name="servico" maxlength="40" placeholder="Ex.: HTTPS" required></div>
                    <div class="field"><label>Descrição</label><input name="descricao" maxlength="120" placeholder="Finalidade da porta"></div>
                    <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button primary" type="submit">Adicionar porta</button></div>
                </form>
            </div>
        </dialog>
    <?php endforeach; ?>

    <dialog class="modal" id="edit-port-modal">
        <header class="modal-header"><div><h2>Editar Porta</h2><p>Atualizar a porta cadastrada no painel.</p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
        <div class="modal-body">
            <form method="POST" class="modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="editar_porta">
                <input type="hidden" name="id" data-modal-id>
                <input type="hidden" name="escopo" data-modal-scope>
                <div class="field">
                    <label>Escopo</label>
                    <span class="family-badge" data-modal-scope-label>Admin</span>
                </div>
                <div class="form-row">
                    <div class="field"><label>Porta</label><input type="number" name="porta" min="1" max="65535" inputmode="numeric" data-modal-port required></div>
                    <div class="field"><label>Protocolo</label><select name="protocolo" data-modal-protocol required><option value="TCP">TCP</option><option value="UDP">UDP</option><option value="TCP/UDP">TCP/UDP</option></select></div>
                </div>
                <div class="field"><label>Serviço</label><input name="servico" maxlength="40" data-modal-service required></div>
                <div class="field"><label>Descrição</label><input name="descricao" maxlength="120" data-modal-description></div>
                <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button primary" type="submit">Salvar alterações</button></div>
            </form>
        </div>
    </dialog>

    <dialog class="modal" id="remove-port-modal">
        <header class="modal-header"><div><h2>Remover Porta</h2><p>Remover a porta <span data-port-scope></span> dos dados do painel.</p></div><button class="modal-close" type="button" data-close-dialog>Fechar</button></header>
        <div class="modal-body">
            <form method="POST" class="modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="acao" value="remover_porta">
                <input type="hidden" name="id" data-modal-id>
                <div class="modal-warning">Para confirmar, digite exatamente a porta <strong data-confirmation-label></strong>.</div>
                <div class="field"><label for="remove-port-confirmation">Confirmação</label><input id="remove-port-confirmation" name="confirmacao" inputmode="numeric" autocomplete="off" data-confirmation-input required></div>
                <div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancelar</button><button class="button danger" type="submit">Remover porta</button></div>
            </form>
        </div>
    </dialog>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</main>
<div class="ui-toast <?= htmlspecialchars($toastTipoInicial, ENT_QUOTES, 'UTF-8') ?>" id="ui-toast" role="status" aria-live="polite"<?= $toastMensagemInicial === '' ? ' hidden' : '' ?>><?= htmlspecialchars($toastMensagemInicial) ?></div>
<script>
document.querySelectorAll('[data-search-target]').forEach(input=>{
    const target=document.getElementById(input.dataset.searchTarget);
    if(!target)return;
    const rows=[...target.querySelectorAll('tr[data-search-text]')];
    const emptyRow=target.querySelector('.empty-row');
    const applyFilter=()=>{
        const term=input.value.trim().toLocaleLowerCase('pt-BR');
        let visible=0;
        rows.forEach(row=>{
            const show=row.dataset.searchText.includes(term);
            row.hidden=!show;
            if(show)visible++;
        });
        if(emptyRow)emptyRow.hidden=visible!==0;
    };
    input.addEventListener('input',applyFilter);
    applyFilter();
});
document.querySelectorAll('[data-open-dialog]').forEach(button=>button.addEventListener('click',()=>{
    const dialog=document.getElementById(button.dataset.openDialog);
    if(!dialog)return;
    const idField=dialog.querySelector('[data-modal-id]');
    const familyField=dialog.querySelector('[data-modal-family]');
    const familySelect=dialog.querySelector('[data-modal-family-select]');
    const familyLabel=dialog.querySelector('[data-modal-family-label]');
    const scopeField=dialog.querySelector('[data-modal-scope]');
    const scopeLabel=dialog.querySelector('[data-modal-scope-label]');
    const valueField=dialog.querySelector('[data-modal-value]');
    const descriptionField=dialog.querySelector('[data-modal-description]');
    const portField=dialog.querySelector('[data-modal-port]');
    const protocolField=dialog.querySelector('[data-modal-protocol]');
    const serviceField=dialog.querySelector('[data-modal-service]');
    const confirmationLabel=dialog.querySelector('[data-confirmation-label]');
    const confirmationInput=dialog.querySelector('[data-confirmation-input]');
    const portScopeLabel=dialog.querySelector('[data-port-scope]');

    if(idField)idField.value=button.dataset.recordId||'';
    const family=button.dataset.defaultFamily||button.dataset.recordFamily||'';
    if(familyField)familyField.value=family;
    if(familySelect)familySelect.value=button.dataset.defaultFamily||'';
    if(familyLabel)familyLabel.textContent=family||'Não informado';
    if(scopeField)scopeField.value=button.dataset.recordScope||'';
    if(scopeLabel)scopeLabel.textContent=button.dataset.recordScope||'';
    if(valueField)valueField.value=button.dataset.recordValue||'';
    if(descriptionField)descriptionField.value=button.dataset.recordDescription||'';
    if(portField)portField.value=button.dataset.recordPort||button.dataset.recordValue||'';
    if(protocolField)protocolField.value=button.dataset.recordProtocol||'TCP';
    if(serviceField)serviceField.value=button.dataset.recordService||'';
    if(confirmationLabel)confirmationLabel.textContent=button.dataset.recordValue||button.dataset.recordPort||'';
    if(confirmationInput)confirmationInput.value='';
    if(portScopeLabel)portScopeLabel.textContent=button.dataset.recordScope||'';
    dialog.showModal();
}));
document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('click',event=>{
    if(event.target===dialog)dialog.close();
}));
const toast=document.getElementById('ui-toast');let toastTimer;
if(toast&&!toast.hidden){
    toastTimer=setTimeout(()=>{toast.hidden=true},3200);
}
document.querySelectorAll('[data-future-action]').forEach(button=>button.addEventListener('click',()=>{
    clearTimeout(toastTimer);
    toast.textContent=button.dataset.futureAction+': ação disponível em uma próxima etapa.';
    toast.hidden=false;
    toastTimer=setTimeout(()=>{toast.hidden=true},3200);
}));
document.querySelector('[data-refresh-page]').addEventListener('click',()=>window.location.assign(window.location.pathname+window.location.search));
</script>
</body>
</html>
