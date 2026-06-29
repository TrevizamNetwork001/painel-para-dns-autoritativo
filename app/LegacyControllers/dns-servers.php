<?php

require_once __DIR__ . '/app/Auth/auth.php';
require_once __DIR__ . '/app/Support/security.php';
require_once __DIR__ . '/app/Audit/audit.php';
require_once __DIR__ . '/app/Auth/users.php';
require_once __DIR__ . '/app/DnsServers/servers.php';
require_once __DIR__ . '/app/Dns/zones.php';

exigir_administrador();
dns_servers_garantir_esquema();

$erro = '';
$sucesso = $_SESSION['dns_servers_sucesso'] ?? '';
$resultadoTeste = $_SESSION['dns_servers_teste'] ?? null;
$modalRetorno = $_SESSION['dns_servers_return_modal'] ?? '';
$modalResultadoSection = $_SESSION['dns_servers_return_section'] ?? '';
unset($_SESSION['dns_servers_sucesso'], $_SESSION['dns_servers_teste'], $_SESSION['dns_servers_return_modal'], $_SESSION['dns_servers_return_section']);

function dns_servers_redirecionar(string $mensagem, ?array $teste = null): never
{
    $_SESSION['dns_servers_sucesso'] = $mensagem;

    $returnModal = (string) ($_POST['return_modal'] ?? '');
    $temReturnModal = preg_match('/^tools-server-[1-9][0-9]*$/', $returnModal) === 1;

    if ($teste !== null) {
        $_SESSION['dns_servers_teste'] = $teste;
    } elseif ($temReturnModal) {
        $_SESSION['dns_servers_teste'] = [
            'ok' => true,
            'titulo' => $mensagem,
            'saida' => $mensagem,
            'duracao_ms' => 0,
        ];
    }

    if ($temReturnModal) {
        $_SESSION['dns_servers_return_modal'] = $returnModal;
        $returnSection = (string) ($_POST['return_section'] ?? '');
        if (in_array($returnSection, ['diagnostico', 'agente', 'manutencao'], true)) {
            $_SESSION['dns_servers_return_section'] = $returnSection;
        }
    }

    $destino = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dns-servers.php'));
    header('Location: ' . $destino);
    exit;
}

function dns_servers_formatar_timestamp_local(?string $valor): string
{
    if (!$valor) {
        return 'Data indisponivel';
    }

    try {
        $utc = new DateTimeZone('UTC');
        $local = new DateTimeZone('America/Sao_Paulo');
        $dt = new DateTimeImmutable($valor, $utc);
        return $dt->setTimezone($local)->format('d/m/Y H:i');
    } catch (Throwable $e) {
        $ts = strtotime($valor);
        return $ts ? date('d/m/Y H:i', $ts) : 'Data indisponivel';
    }
}

function dns_servers_render_modal_resultado(?array $resultado, string $mensagem): void
{
    if (!$resultado) {
        return;
    }

    $ok = !empty($resultado['ok']);
    $titulo = trim((string) ($resultado['titulo'] ?? 'Resultado'));
    $duracao = (int) ($resultado['duracao_ms'] ?? 0);
    $rotulo = match (true) {
        str_starts_with($titulo, 'Conexão SSH') => 'SSH',
        str_starts_with($titulo, 'Status BIND') => 'BIND',
        str_starts_with($titulo, 'Transferencia de zona') => 'Transferência',
        default => preg_replace('/\s+-\s+.*$/u', '', $titulo) ?: 'Operação',
    };
    $resultadoCompacto = in_array($rotulo, ['SSH', 'BIND', 'Transferência', 'Atualizar agente', 'Migracao layout slave'], true);
    $nivel = $ok
        ? (preg_match('/\b(aviso|atenção|parcial)\b/iu', $mensagem) ? 'warn' : 'ok')
        : 'bad';
    ?>
    <div class="modal-result-group" data-auto-dismiss-result>
        <div class="modal-result <?= $nivel ?>">
            <div class="modal-result-line">
                <span class="modal-result-icon" aria-hidden="true"><?= $nivel === 'ok' ? '✓' : ($nivel === 'warn' ? '⚠' : '✖') ?></span>
                <strong><?= htmlspecialchars($rotulo) ?> <?= $nivel === 'ok' ? 'OK' : ($nivel === 'warn' ? 'PARCIAL' : 'ERRO') ?></strong>
                <?php if (!$resultadoCompacto): ?>
                <span class="modal-result-separator">•</span>
                <span class="modal-result-duration"><?= $duracao ?> ms</span>
                <span class="modal-result-separator">•</span>
                <details><summary><span class="show-output">Ver saída técnica</span><span class="hide-output">Ocultar saída</span></summary><div class="result"><?= htmlspecialchars((string) ($resultado['saida'] ?? '')) ?></div></details>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function dns_servers_auditar(
    string $acao,
    array $servidor,
    string $status,
    string $mensagem,
    ?string $valorAntigo = null
): void {
    registrar_auditoria([
        'acao' => $acao,
        'tipo_registro' => 'DNS_SERVER',
        'nome_registro' => $servidor['hostname'] ?? $servidor['nome'] ?? 'NS2',
        'valor_antigo' => $valorAntigo,
        'valor_novo' => dns_server_resumo($servidor),
        'status' => $status,
        'mensagem' => $mensagem,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'visualizar_credencial') {
        header('Content-Type: application/json; charset=UTF-8');
        try {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $servidor = $id ? dns_server_por_id($id) : null;
            if (!$servidor || !dns_server_tem_senha_admin($servidor)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'erro' => 'Nao existe senha SSH salva para este servidor.'], JSON_THROW_ON_ERROR);
                exit;
            }

            echo json_encode(['ok' => true, 'senha' => dns_server_senha_admin_salva($servidor)], JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'erro' => 'Nao foi possivel ler a senha salva.'], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    $acaoAuditoria = 'DNS_SERVER_TEST';
    $servidorAuditoria = ['nome' => 'NS2', 'hostname' => 'não informado'];

    try {
        if ($acao === 'cadastrar') {
            $acaoAuditoria = 'DNS_SERVER_ADD';
            $bootstrap = dns_server_validar_bootstrap($_POST);
            $dados = $bootstrap['server'];
            $servidorAuditoria = $dados;
            $teste = dns_server_bootstrap($dados, $bootstrap['admin']);
            $statusTeste = dns_server_executar_teste($dados, 'status');
            $derivado = dns_server_status_derivado($statusTeste['ok'], $statusTeste['saida']);
            $statusComprovaProvisionamento = $statusTeste['ok']
                && $derivado['agente_status'] === 'instalado'
                && $derivado['bind_status'] === 'ok';

            if (!$teste['ok'] && !$statusComprovaProvisionamento) {
                $etapa = dns_server_descrever_etapa_falha($teste['saida']);
                if (!$statusTeste['ok']) {
                    $etapa = 'status';
                }
                dns_servers_auditar(
                    'DNS_SERVER_AGENT_INSTALL',
                    $dados,
                    'ERRO',
                    substr('Bootstrap do servidor DNS falhou na etapa ' . $etapa . ': ' . $teste['saida'], 0, 4000)
                );
                dns_servers_redirecionar(
                    'Bootstrap do servidor DNS falhou na etapa: ' . $etapa . '.',
                    [
                        'ok' => false,
                        'titulo' => 'Adicionar servidor DNS - ' . $dados['nome'],
                        'saida' => "BOOTSTRAP\n" . $teste['saida'] . "\n\nSTATUS\n" . $statusTeste['saida'],
                        'duracao_ms' => $teste['duracao_ms'] + $statusTeste['duracao_ms'],
                    ]
                );
            }

            try {
                $stmt = db()->prepare("
                    INSERT INTO dns_servers (
                        nome, hostname, ip4, ip6, tipo, ativo,
                        ssh_user, ssh_port, descricao, modo_instalacao,
                        agente_status, bind_status, zonas_slave,
                        ultimo_status, ultima_verificacao, ultima_mensagem
                    ) VALUES (
                        :nome, :hostname, :ip4, :ip6, :tipo, :ativo,
                        :ssh_user, :ssh_port, :descricao, :modo_instalacao,
                        :agente_status, :bind_status, :zonas_slave,
                        :ultimo_status, CURRENT_TIMESTAMP, :ultima_mensagem
                    )
                ");
                $stmt->execute([
                    'nome' => $dados['nome'],
                    'hostname' => $dados['hostname'],
                    'ip4' => $dados['ip4'],
                    'ip6' => $dados['ip6'],
                    'tipo' => $dados['tipo'],
                    'ativo' => $dados['ativo'],
                    'ssh_user' => $dados['ssh_user'],
                    'ssh_port' => $dados['ssh_port'],
                    'descricao' => $dados['descricao'],
                    'modo_instalacao' => $dados['modo_instalacao'],
                    'agente_status' => $derivado['agente_status'],
                    'bind_status' => $derivado['bind_status'],
                    'zonas_slave' => $derivado['zonas_slave'],
                    'ultimo_status' => $statusTeste['ok'] ? 'online' : 'offline',
                    'ultima_mensagem' => substr('Bootstrap: ' . $teste['saida'] . "\n\nStatus: " . $statusTeste['saida'], 0, 4000),
                ]);
                dns_server_salvar_credencial_admin((int) db()->lastInsertId(), $_POST, null, true);
            } catch (PDOException $e) {
                error_log('Erro ao cadastrar servidor DNS no SQLite: ' . $e->getMessage());
                $mensagemSqlite = str_contains($e->getMessage(), 'UNIQUE')
                    ? 'Cadastro SQLite falhou: já existe um servidor com esse nome ou hostname.'
                    : 'Cadastro SQLite falhou: não foi possível gravar o servidor.';
                dns_servers_auditar('DNS_SERVER_ADD', $dados, 'ERRO', $mensagemSqlite);
                dns_servers_redirecionar(
                    $mensagemSqlite,
                    [
                        'ok' => false,
                        'titulo' => 'Adicionar servidor DNS - ' . $dados['nome'],
                        'saida' => "BOOTSTRAP\n" . $teste['saida'] . "\n\nSTATUS\n" . $statusTeste['saida'],
                        'duracao_ms' => $teste['duracao_ms'] + $statusTeste['duracao_ms'],
                    ]
                );
            }
            dns_servers_auditar(
                $acaoAuditoria,
                $dados,
                'OK',
                'Servidor DNS adicionado por ' . (dns_server_modo_eh_provisionamento($dados['modo_instalacao']) ? 'provisionamento' : 'adoção')
            );
            dns_servers_auditar(
                'DNS_SERVER_AGENT_INSTALL',
                $dados,
                'OK',
                substr('Agente instalado: ' . $teste['saida'], 0, 4000)
            );
            dns_servers_redirecionar(
                dns_server_modo_eh_provisionamento($dados['modo_instalacao'])
                    ? ($teste['ok'] ? 'Servidor DNS provisionado e registrado.' : 'Servidor DNS provisionado, validado por status e registrado.')
                    : 'Servidor DNS adotado e registrado.',
                [
                    'ok' => $statusTeste['ok'],
                    'titulo' => 'Adicionar servidor DNS - ' . $dados['nome'],
                    'saida' => "BOOTSTRAP\n" . $teste['saida'] . "\n\nSTATUS\n" . $statusTeste['saida'],
                    'duracao_ms' => $teste['duracao_ms'] + $statusTeste['duracao_ms'],
                ]
            );
        }

        $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $servidor = $id ? dns_server_por_id($id) : null;

        if (!$servidor) {
            throw new RuntimeException('Servidor DNS não encontrado.');
        }
        $servidorAuditoria = $servidor;

        if ($acao === 'atualizar') {
            $acaoAuditoria = 'DNS_SERVER_UPDATE';
            $dados = dns_server_validar_dados($_POST);
            $servidorAuditoria = $dados;
            $stmt = db()->prepare("
                UPDATE dns_servers
                SET nome = :nome,
                    hostname = :hostname,
                    ip4 = :ip4,
                    ip6 = :ip6,
                    tipo = :tipo,
                    ativo = :ativo,
                    ssh_user = :ssh_user,
                    ssh_port = :ssh_port,
                    descricao = :descricao,
                    atualizado_em = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute($dados + ['id' => $servidor['id']]);
            dns_server_salvar_credencial_admin((int) $servidor['id'], $_POST, $servidor);
            dns_servers_auditar($acaoAuditoria, $dados, 'OK', 'Servidor DNS atualizado', dns_server_resumo($servidor));
            dns_servers_redirecionar(
                ($_POST['origem_form'] ?? '') === 'agente'
                    ? 'Configurações do agente salvas.'
                    : 'Servidor DNS atualizado.'
            );
        }

        if ($acao === 'remover') {
            $acaoAuditoria = 'DNS_SERVER_REMOVE';
            $serverKey = 'server:' . (int) $servidor['id'];
            dns_zones_garantir_esquema();

            $pdo = db();
            $pdo->beginTransaction();
            try {
                foreach ([
                    'dns_zone_inventory',
                    'dns_zone_inventory_status',
                    'dns_zone_extra_ignores',
                ] as $tabelaInventario) {
                    $stmt = $pdo->prepare("DELETE FROM {$tabelaInventario} WHERE server_key = :server_key");
                    $stmt->execute([':server_key' => $serverKey]);
                }

                $stmt = $pdo->prepare('DELETE FROM dns_servers WHERE id = :id');
                $stmt->execute([':id' => $servidor['id']]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
            registrar_auditoria([
                'acao' => $acaoAuditoria,
                'tipo_registro' => 'DNS_SERVER',
                'nome_registro' => $servidor['hostname'],
                'valor_antigo' => dns_server_resumo($servidor),
                'valor_novo' => 'removido',
                'status' => 'OK',
                'mensagem' => 'Servidor DNS removido do painel',
            ]);
            dns_servers_redirecionar('Servidor DNS removido.');
        }

        if ($acao === 'atualizar_agente_admin') {
            $acaoAuditoria = 'DNS_SERVER_AGENT_UPDATE';
            $payload = array_replace($servidor, dns_server_admin_payload($servidor, $_POST));
            $bootstrap = dns_server_validar_bootstrap($payload);
            $bootstrap['server']['modo_instalacao'] = 'adotar';
            $teste = dns_server_bootstrap($bootstrap['server'], $bootstrap['admin']);
            $statusTeste = $teste['ok'] ? dns_server_executar_teste($bootstrap['server'], 'status') : $teste;
            $derivado = dns_server_status_derivado($statusTeste['ok'], $statusTeste['saida']);
            dns_server_registrar_status((int) $servidor['id'], $statusTeste['ok'], 'Atualização do agente: ' . $teste['saida'], $derivado['agente_status'], $derivado['bind_status'], $derivado['zonas_slave']);
            if ($teste['ok']) {
                dns_server_salvar_credencial_admin((int) $servidor['id'], $_POST, $servidor);
            }
            dns_servers_auditar($acaoAuditoria, $servidor, $teste['ok'] ? 'OK' : 'ERRO', substr('Atualização do agente DNS: ' . $teste['saida'], 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? 'Agente atualizado e validado.' : 'Atualização do agente falhou.',
                [
                    'ok' => $statusTeste['ok'],
                    'titulo' => 'Atualizar agente - ' . $servidor['nome'],
                    'saida' => "BOOTSTRAP\n" . $teste['saida'] . "\n\nSTATUS\n" . $statusTeste['saida'],
                    'duracao_ms' => $teste['duracao_ms'] + ($statusTeste['duracao_ms'] ?? 0),
                ]
            );
        }

        if ($acao === 'remover_agente_admin') {
            $acaoAuditoria = 'DNS_SERVER_AGENT_REMOVE';
            $payload = array_replace($servidor, dns_server_admin_payload($servidor, $_POST));
            $bootstrap = dns_server_validar_bootstrap($payload);
            $teste = dns_server_remover_agente($bootstrap['server'], $bootstrap['admin']);
            dns_server_registrar_status((int) $servidor['id'], false, 'Remoção do agente: ' . $teste['saida'], 'ausente', null, null);
            dns_servers_auditar($acaoAuditoria, $servidor, $teste['ok'] ? 'OK' : 'ERRO', substr('Remoção do agente DNS: ' . $teste['saida'], 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? 'Agente removido/desativado.' : 'Remoção do agente falhou.',
                ['ok' => $teste['ok'], 'titulo' => 'Remover agente - ' . $servidor['nome'], 'saida' => $teste['saida'], 'duracao_ms' => $teste['duracao_ms']]
            );
        }

        if ($acao === 'instalar_agente') {
            $acaoAuditoria = 'DNS_SERVER_AGENT_UPDATE';
            $teste = dns_server_instalar_agente($servidor);
            $status = $teste['ok'] ? 'OK' : 'ERRO';
            $mensagem = 'Instalação do agente NS2: ' . $teste['saida'];
            $derivado = dns_server_status_derivado($teste['ok'], $teste['saida']);
            dns_server_registrar_status((int) $servidor['id'], $teste['ok'], $mensagem, $derivado['agente_status'], $derivado['bind_status'], $derivado['zonas_slave']);
            dns_servers_auditar($acaoAuditoria, $servidor, $status, substr($mensagem, 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? 'Agente NS2 instalado e validado.' : 'Instalação do agente NS2 falhou.',
                ['ok' => $teste['ok'], 'titulo' => 'Instalar agente NS2 - ' . $servidor['nome'], 'saida' => $teste['saida'], 'duracao_ms' => $teste['duracao_ms']]
            );
        }

        if ($acao === 'testar_transferencia') {
            $zonaTeste = strtolower(rtrim(trim((string) ($_POST['zona_teste'] ?? 'legacy.example')), '.'));
            $rotulo = 'Transferencia de zona';
            $teste = dns_server_executar_teste($servidor, 'transfer', $zonaTeste);
            $status = $teste['ok'] ? 'OK' : 'ERRO';
            $mensagem = $rotulo . ' ' . $zonaTeste . ': ' . $teste['saida'];
            dns_servers_auditar('DNS_SERVER_TEST', $servidor, $status, substr($mensagem, 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? "{$rotulo} concluida." : "{$rotulo} falhou.",
                ['ok' => $teste['ok'], 'titulo' => $rotulo . ' - ' . $servidor['nome'], 'saida' => $teste['saida'], 'duracao_ms' => $teste['duracao_ms']]
            );
        }

        if ($acao === 'migrar_layout_slave') {
            $rotulo = 'Migracao layout slave';
            $teste = dns_server_executar_teste($servidor, 'migrate_layout');
            $status = $teste['ok'] ? 'OK' : 'ERRO';
            $mensagem = $rotulo . ': ' . $teste['saida'];
            dns_servers_auditar('DNS_SERVER_SLAVE_LAYOUT_MIGRATE', $servidor, $status, substr($mensagem, 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? 'Layout migrado com sucesso.' : 'Migracao do layout slave falhou.',
                ['ok' => $teste['ok'], 'titulo' => $rotulo . ' - ' . $servidor['nome'], 'saida' => $teste['saida'], 'duracao_ms' => $teste['duracao_ms']]
            );
        }

        if (in_array($acao, ['testar_ssh', 'testar_bind'], true)) {
            $tipoTeste = $acao === 'testar_ssh' ? 'connection' : 'status';
            $rotulo = $acao === 'testar_ssh' ? 'Conexão SSH' : 'Status BIND';
            $teste = dns_server_executar_teste($servidor, $tipoTeste);
            $status = $teste['ok'] ? 'OK' : 'ERRO';
            $mensagem = $rotulo . ': ' . $teste['saida'];
            $derivado = $acao === 'testar_bind'
                ? dns_server_status_derivado($teste['ok'], $teste['saida'])
                : ['agente_status' => $teste['ok'] ? null : 'ausente', 'bind_status' => null, 'zonas_slave' => null];
            dns_server_registrar_status((int) $servidor['id'], $teste['ok'], $mensagem, $derivado['agente_status'], $derivado['bind_status'], $derivado['zonas_slave']);
            dns_servers_auditar('DNS_SERVER_TEST', $servidor, $status, substr($mensagem, 0, 4000));
            dns_servers_redirecionar(
                $teste['ok'] ? "{$rotulo} concluído." : "{$rotulo} falhou.",
                ['ok' => $teste['ok'], 'titulo' => $rotulo . ' - ' . $servidor['nome'], 'saida' => $teste['saida'], 'duracao_ms' => $teste['duracao_ms']]
            );
        }

        throw new RuntimeException('Ação inválida.');
    } catch (PDOException $e) {
        error_log('Erro ao administrar servidor DNS: ' . $e->getMessage());
        $erro = str_contains($e->getMessage(), 'UNIQUE')
            ? 'Já existe um servidor com esse nome ou hostname.'
            : 'Não foi possível concluir a operação no banco.';
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }

    try {
        dns_servers_auditar($acaoAuditoria, $servidorAuditoria, 'ERRO', substr(strip_tags($erro), 0, 4000));
    } catch (Throwable $auditError) {
        error_log('Falha ao auditar erro de servidor DNS: ' . $auditError->getMessage());
    }
}

$servidores = dns_servers_listar();
$hostnameLocal = gethostname() ?: 'NS1';
$enderecosLocais = [];
$saidaEnderecos = [];
$codigoEnderecos = 1;
exec('/usr/bin/hostname -I 2>/dev/null', $saidaEnderecos, $codigoEnderecos);
if ($codigoEnderecos === 0 && !empty($saidaEnderecos[0])) {
    $enderecosLocais = preg_split('/\s+/', trim($saidaEnderecos[0])) ?: [];
}
if (!$enderecosLocais && !empty($_SERVER['SERVER_ADDR'])) {
    $enderecosLocais[] = (string) $_SERVER['SERVER_ADDR'];
}
$totalServidoresRemotos = count($servidores);
$totalOnline = count(array_filter($servidores, static fn(array $s): bool => ($s['ultimo_status'] ?? '') === 'online'));
$totalSlaves = count(array_filter($servidores, static fn(array $s): bool => ($s['tipo'] ?? '') === 'slave'));
$inventarioPorServidor = [];
try {
    $stmtInventario = db()->query("
        SELECT s.server_id, s.total_zones, (
            SELECT zone_name
            FROM dns_zone_inventory i
            WHERE i.server_id = s.server_id
            ORDER BY i.zone_name COLLATE NOCASE
            LIMIT 1
        ) AS zone_name
        FROM dns_zone_inventory_status s
        WHERE s.server_id IS NOT NULL
    ");
    foreach ($stmtInventario->fetchAll(PDO::FETCH_ASSOC) as $linhaInventario) {
        $serverIdInventario = (int) ($linhaInventario['server_id'] ?? 0);
        if ($serverIdInventario > 0) {
            $inventarioPorServidor[$serverIdInventario] = $linhaInventario;
        }
    }
} catch (Throwable $e) {
    $inventarioPorServidor = [];
}
$ultimoInventario = 'Indisponivel';
try {
    $valorInventario = db()->query('SELECT MAX(checked_at) FROM dns_zone_inventory_status')->fetchColumn();
    if ($valorInventario) {
        $ultimoInventario = dns_servers_formatar_timestamp_local((string) $valorInventario);
    }
} catch (Throwable $e) {
    $ultimoInventario = 'Indisponivel';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Servidores DNS</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#0b1120;color:#e5edf7}a{color:#38bdf8;text-decoration:none}button,input,select,textarea{font:inherit}.page{max-width:1480px;margin:0 auto;padding:22px 18px 18px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}.topbar h1{font-size:26px;margin:0 0 4px}.lead{margin:0;color:#94a3b8}.top-actions{display:flex;gap:10px;align-items:center}.primary-button,.button-like,button{border:1px solid #2563eb;border-radius:8px;background:#2563eb;color:#fff;padding:9px 12px;font-weight:700;cursor:pointer}.secondary{background:#334155;border-color:#475569}.danger{background:#7f1d1d;border-color:#991b1b}.message{padding:10px 12px;border-radius:8px;margin-bottom:12px}.error{background:#7f1d1d;color:#fecaca}.success{background:#14532d;color:#bbf7d0}.toast-stack{position:fixed;right:18px;top:18px;z-index:20;width:min(420px,calc(100vw - 36px))}.toast-stack .message{box-shadow:0 18px 40px rgba(0,0,0,.35);animation:toast-out .35s ease 5s forwards}@keyframes toast-out{to{opacity:0;transform:translateY(-8px);visibility:hidden}}.result-panel{background:#111827;border:1px solid #7f1d1d;border-radius:8px;padding:12px;margin-bottom:12px}.result-panel h2{font-size:17px;margin:0 0 8px}.result-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;border-top:1px solid #1f2a44;border-bottom:1px solid #1f2a44;padding:12px 0;margin:10px 0}.technical-output>summary{cursor:pointer;color:#93c5fd;font-weight:700;margin-top:8px}.technical-output .result{margin-top:8px}.result{white-space:pre-wrap;overflow-wrap:anywhere;background:#071226;border:1px solid #334155;border-radius:8px;padding:10px;color:#cbd5e1;max-height:220px;overflow:auto}.metric-strip{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.metric-card,.sidebar,.panel{background:#111827;border:1px solid #1f2a44;border-radius:8px}.metric-card{padding:10px 12px}.metric-label{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.metric-value{display:block;font-size:20px;font-weight:800;margin-top:5px}.workspace{display:grid;grid-template-columns:300px minmax(0,1fr);gap:14px;align-items:start}.sidebar{height:calc(100vh - 132px);max-height:820px;min-height:560px;padding:14px;position:sticky;top:22px;display:flex;flex-direction:column;gap:12px}.sidebar-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.sidebar-head h2{font-size:16px;margin:0}.server-filter{width:100%;border:1px solid #334155;border-radius:8px;background:#0b1120;color:#fff;padding:9px 10px}.server-list{display:grid;gap:8px;overflow:auto;padding-right:2px}.server-list-item{width:100%;text-align:left;background:#0f172a;border:1px solid #1f2a44;border-radius:8px;padding:10px;color:#e5edf7;cursor:pointer}.server-list-item:hover,.server-list-item:focus{border-color:#38bdf8;outline:none}.server-list-item.active{border-color:#22d3ee;box-shadow:0 0 0 2px rgba(34,211,238,.32) inset,0 8px 22px rgba(8,145,178,.12);background:#102033}.server-list-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start}.server-list-name{font-size:16px;font-weight:800}.server-list-meta{display:block;color:#94a3b8;font-size:12px;line-height:1.45;margin-top:4px}.badge-row{display:flex;gap:5px;flex-wrap:wrap;margin-top:8px}.pill{display:inline-flex;align-items:center;border-radius:999px;background:#1e293b;border:1px solid #334155;color:#cbd5e1;padding:3px 7px;font-size:10px;font-weight:800;white-space:nowrap}.pill.ok{color:#86efac}.pill.bad{color:#fecaca}.pill.warn{color:#fde68a;background:#3b2f13;border-color:#854d0e}.panel{display:none;min-height:calc(100vh - 132px);overflow:visible;scroll-margin-top:22px}.panel.active{display:block}.panel-header{padding:16px 18px 12px;border-bottom:1px solid #1f2a44}.panel-main-line{display:flex;align-items:flex-start;justify-content:space-between;gap:16px}.panel h2{font-size:28px;margin:0 0 4px}.panel-meta{color:#94a3b8;font-size:13px;line-height:1.55}.quick-menu{position:relative}.quick-menu>summary{list-style:none;cursor:pointer;border:1px solid #334155;border-radius:8px;background:#0f172a;color:#cbd5e1;padding:9px 12px;font-weight:800}.quick-menu>summary::-webkit-details-marker{display:none}.quick-menu[open]>summary{border-color:#38bdf8;background:#102033}.quick-menu-box{position:absolute;right:0;top:42px;width:300px;background:#111827;border:1px solid #334155;border-radius:6px;padding:8px;z-index:4;box-shadow:0 18px 40px rgba(0,0,0,.35)}.quick-group{padding:8px 0;border-top:1px solid #263247}.quick-group:first-child{border-top:0;padding-top:0}.quick-group:last-child{padding-bottom:0}.quick-title{display:block;color:#7f8da3;font-size:9px;text-transform:uppercase;letter-spacing:.1em;font-weight:800;padding:3px 8px 5px}.quick-group form{margin:0}.menu-action{width:100%;display:flex;align-items:center;gap:10px;margin:0;padding:8px;border:0;border-radius:4px;background:transparent;color:#e5edf7;text-align:left;font-weight:700;line-height:1.25;transition:background-color .15s ease,color .15s ease}.menu-action:hover,.menu-action:focus{background:#1b2739;outline:none}.menu-action .icon{display:inline-flex;align-items:center;justify-content:center;flex:0 0 22px;width:22px;color:#cbd5e1;font-size:14px}.menu-action.danger-link{color:#fecaca}.menu-action.danger-link .icon{color:#fca5a5}.panel-body{padding:14px 16px 16px}.action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.action-card{background:#0f172a;border:1px solid #1f2a44;border-radius:8px;overflow:hidden}.compact-card{min-height:112px;padding:11px;display:flex;flex-direction:column}.action-kicker,.tools-kicker{color:#94a3b8;font-size:10px;text-transform:uppercase;letter-spacing:.04em;font-weight:800}.action-card h3{font-size:15px;margin:6px 0 4px}.action-card p{color:#94a3b8;font-size:12px;line-height:1.3;margin:0 0 8px}.action-open{margin-top:auto;align-self:flex-start;padding:6px 9px;font-size:12px}.form-grid,.admin-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}.tools-section{background:#0f172a;border:1px solid #1f2a44;border-radius:8px;padding:12px;margin-bottom:12px}.tools-section.danger-zone{border-color:#7f1d1d;background:#140f12}.tools-section h3{font-size:15px;margin:5px 0 10px}.tools-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}.tools-actions form{margin:0}.action-chip{display:inline-flex;align-items:center;gap:6px;width:auto;border:1px solid #334155;border-radius:999px;background:#101827;color:#dbeafe;padding:6px 10px;font-size:12px;font-weight:800;line-height:1.1;min-height:30px;box-shadow:none}.action-chip:hover,.action-chip:focus{transform:translateY(-1px);outline:none;border-color:#38bdf8;background:#13243a}.action-chip .chip-icon{font-size:13px;line-height:1}.action-chip.diag{color:#bfdbfe;background:#0d1b2f;border-color:#1e3a5f}.action-chip.diag:hover,.action-chip.diag:focus{background:#102a48;border-color:#38bdf8}.action-chip.agent{color:#bbf7d0;background:#0d231b;border-color:#14532d}.action-chip.agent:hover,.action-chip.agent:focus{background:#123326;border-color:#22c55e}.action-chip.maint{color:#fde68a;background:#2b2110;border-color:#854d0e}.action-chip.maint:hover,.action-chip.maint:focus{background:#3a2a12;border-color:#f59e0b}.action-chip.danger{color:#fecaca;background:#2a1114;border-color:#7f1d1d}.action-chip.danger:hover,.action-chip.danger:focus{background:#3a1418;border-color:#ef4444}.agent-config{margin-top:8px}.agent-config>summary{list-style:none;display:inline-flex;cursor:pointer}.agent-config>summary::-webkit-details-marker{display:none}.agent-config[open]>summary{background:#123326;border-color:#22c55e}.agent-config-panel{margin-top:10px;border:1px solid #1f2a44;border-radius:8px;background:#0b1120;padding:12px}.agent-config-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:10px}.modal-result{margin-top:10px;border:1px solid #334155;border-radius:8px;background:#0b1120;padding:10px;color:#cbd5e1}.modal-result.ok{border-color:#166534;background:#10231a}.modal-result.bad{border-color:#7f1d1d;background:#1f1115}.modal-result strong{display:block;color:#e5edf7;margin-bottom:4px}.modal-result span{display:block;color:#94a3b8;font-size:12px}.modal-result details{margin-top:8px}.modal-result summary{cursor:pointer;color:#93c5fd;font-weight:700;font-size:12px}.modal-result .result{margin-top:8px;max-height:180px}.operation-card{background:#111827;border:1px solid #1f2a44;border-radius:8px;padding:10px}label{display:block;color:#94a3b8;font-size:12px;margin-bottom:5px}input,select,textarea{width:100%;padding:9px;border:1px solid #334155;border-radius:8px;background:#0b1120;color:#fff}textarea{min-height:76px;resize:vertical}.wide{grid-column:1/-1}.checkbox{display:flex;align-items:center;gap:8px;min-height:39px;color:#94a3b8;font-size:12px}.checkbox input{width:auto}.option-row{display:flex;gap:14px;align-items:center;flex-wrap:wrap}.option-row label{margin:0}.hint{color:#cbd5e1;background:#0b1120;border:1px solid #334155;border-radius:8px;padding:9px;font-size:12px;line-height:1.4}.credentials-card,.technical-footer{margin-top:10px;background:#0f172a;border:1px solid #1f2a44;border-radius:8px;padding:10px 12px}.credentials-card h3{font-size:15px;margin:0 0 8px}.credentials-grid{display:grid;grid-template-columns:1.1fr 1fr 1fr auto;gap:10px;align-items:center}.credential-state{display:flex;align-items:center;gap:7px;font-weight:800;color:#bbf7d0}.credential-check{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#14532d;color:#bbf7d0;font-size:12px}.credentials-note{margin:8px 0 0;color:#94a3b8;font-size:12px;line-height:1.4}.info-label{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.info-value{display:block;margin-top:4px;font-weight:800}.technical-footer{display:flex;gap:10px;flex-wrap:wrap;color:#cbd5e1;font-size:12px}.empty-state{padding:22px;color:#94a3b8}.modal{width:min(980px,calc(100vw - 34px));max-height:calc(100vh - 34px);border:1px solid #334155;border-radius:10px;background:#111827;color:#e5edf7;padding:0}.secret-modal,.confirm-modal{width:min(460px,calc(100vw - 34px))}.secret-value{white-space:pre-wrap;overflow-wrap:anywhere;background:#071226;border:1px solid #334155;border-radius:8px;padding:12px;color:#e5edf7;min-height:46px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:15px;letter-spacing:.06em}.secret-actions,.confirm-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:12px}.credential-actions{display:flex;gap:8px;flex-wrap:wrap}.secret-meta{display:grid;grid-template-columns:80px minmax(0,1fr);gap:5px 10px;margin-bottom:12px;color:#cbd5e1}.secret-meta span:nth-child(odd){color:#94a3b8}.modal-warning{border:1px solid #854d0e;background:#3b2f13;color:#fde68a;border-radius:8px;padding:9px;font-size:12px;line-height:1.4;margin:10px 0}.modal::backdrop{background:rgba(2,6,23,.72);backdrop-filter:blur(2px)}.modal-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:16px 18px;border-bottom:1px solid #1f2a44}.modal-header h2{margin:0;font-size:20px}.modal-content{padding:16px;overflow:auto}.close-button{background:#0f172a;border-color:#334155;color:#cbd5e1}.main-panels{min-width:0}@media(max-width:1120px){.workspace{grid-template-columns:270px minmax(0,1fr)}.metric-strip{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:840px){.page{padding:12px}.topbar,.panel-main-line{display:block}.top-actions{margin-top:12px}.workspace{grid-template-columns:1fr}.sidebar,.panel{height:auto;min-height:0;position:static}.action-grid,.form-grid,.admin-grid,.credentials-grid{grid-template-columns:1fr}.wide{grid-column:auto}.metric-strip{grid-template-columns:1fr}.quick-menu-box{left:0;right:auto;width:min(300px,calc(100vw - 38px))}.tools-actions{display:grid}.tools-actions button{width:100%}}
.agent-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-top:8px}.agent-actions form{margin:0}.agent-config-summary{margin-top:10px;border:1px solid #1f2a44;border-radius:8px;background:#0b1120;padding:12px}.agent-config-summary[hidden]{display:none}.agent-config-summary h4{margin:0 0 9px;font-size:14px}.agent-config-meta{display:flex;gap:18px;flex-wrap:wrap;color:#cbd5e1;font-size:12px}.agent-config-meta strong{color:#e5edf7}.agent-config-fields{margin-top:10px}.agent-config-fields>summary{list-style:none;display:inline-flex;cursor:pointer}.agent-config-fields>summary::-webkit-details-marker{display:none}.agent-config-fields[open]>summary{background:#123326;border-color:#22c55e}.agent-config-fields[open]>summary span:last-child{font-size:0}.agent-config-fields[open]>summary span:last-child::after{content:"Recolher";font-size:12px}.agent-config-fields .agent-config-panel{border:0;border-top:1px solid #1f2a44;border-radius:0;background:transparent;padding:12px 0 0}.modal-result-group{transition:opacity .3s ease,transform .3s ease}.modal-result-group.is-dismissing{opacity:0;transform:translateY(-6px)}@media(max-width:840px){.agent-actions{display:grid}.agent-actions button{width:100%}}
dialog[id^="tools-server-"]{width:min(900px,calc(100vw - 34px));border-color:#29364a;border-radius:8px}
dialog[id^="tools-server-"] .modal-header{padding:14px 16px;border-bottom-color:rgba(71,85,105,.45)}
dialog[id^="tools-server-"] .modal-header h2{font-size:18px;letter-spacing:-.01em}
dialog[id^="tools-server-"] .modal-header .close-button{min-height:27px;padding:4px 8px;border-radius:4px;border-color:#2f3b4e;background:#101827;color:#aeb9c8;font-size:12px}
dialog[id^="tools-server-"] .modal-header .close-button:hover,dialog[id^="tools-server-"] .modal-header .close-button:focus{border-color:#526176;background:#182235;color:#e2e8f0;outline:none}
dialog[id^="tools-server-"] .modal-content{display:block;padding:14px}
dialog[id^="tools-server-"] .tools-section{min-width:0;margin:0 0 10px;padding:11px 12px;border:1px solid #26344a;border-radius:6px;background:#0f172a}
dialog[id^="tools-server-"] .tools-section:last-child{margin-bottom:0}
dialog[id^="tools-server-"] .tools-section h3{margin:4px 0 9px;font-size:14px}
dialog[id^="tools-server-"] .tools-kicker{display:flex;align-items:center;gap:6px;margin-bottom:9px;letter-spacing:.08em;color:#a8b5c7}
dialog[id^="tools-server-"] .tools-kicker .section-icon{font-size:12px;line-height:1;filter:saturate(.75)}
dialog[id^="tools-server-"] .tools-actions,dialog[id^="tools-server-"] .agent-actions{display:flex;align-items:center;gap:8px;margin-top:0;flex-wrap:wrap}
dialog[id^="tools-server-"] .tools-actions form,dialog[id^="tools-server-"] .agent-actions form{display:flex}
dialog[id^="tools-server-"] .action-chip{min-height:32px;padding:7px 11px;border-radius:5px;background:#111a29;font-size:12px;line-height:1;border-color:#3a475b;text-transform:none;letter-spacing:0;transition:background-color .15s ease,border-color .15s ease,color .15s ease}
dialog[id^="tools-server-"] .action-chip:hover,dialog[id^="tools-server-"] .action-chip:focus{transform:none;outline:none;background:#1a273a}
dialog[id^="tools-server-"] .action-chip.diag{color:#bfdbfe;background:#101a29;border-color:#36506d}
dialog[id^="tools-server-"] .action-chip.diag:hover,dialog[id^="tools-server-"] .action-chip.diag:focus{background:#152a40;border-color:#38bdf8}
dialog[id^="tools-server-"] .action-chip.agent{color:#bbf7d0;background:#101c1a;border-color:#2e5944}
dialog[id^="tools-server-"] .action-chip.agent:hover,dialog[id^="tools-server-"] .action-chip.agent:focus{background:#153026;border-color:#22c55e}
dialog[id^="tools-server-"] .action-chip.maint{color:#fde68a;background:#211c13;border-color:#685426}
dialog[id^="tools-server-"] .action-chip.maint:hover,dialog[id^="tools-server-"] .action-chip.maint:focus{background:#302716;border-color:#f59e0b}
dialog[id^="tools-server-"] .tools-section.danger-zone{padding:0;border-color:#5f2932;background:#151219}
dialog[id^="tools-server-"] .danger-zone>summary{display:flex;align-items:center;gap:7px;padding:11px 12px;list-style:none;color:#fca5a5;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;cursor:pointer}
dialog[id^="tools-server-"] .danger-zone>summary::-webkit-details-marker{display:none}
dialog[id^="tools-server-"] .danger-zone>summary::before{content:"▶";font-size:9px;color:#ef4444}
dialog[id^="tools-server-"] .danger-zone[open]>summary::before{content:"▼"}
dialog[id^="tools-server-"] .danger-zone>summary:hover,dialog[id^="tools-server-"] .danger-zone>summary:focus{color:#fecaca;outline:none}
dialog[id^="tools-server-"] .danger-zone-content{padding:0 14px 14px}
dialog[id^="tools-server-"] .danger-zone-content p{margin:0 0 9px;color:#94a3b8;font-size:12px}
dialog[id^="tools-server-"] .action-chip.danger{color:#fecaca;background:#241216;border-color:#8f2832}
dialog[id^="tools-server-"] .action-chip.danger:hover,dialog[id^="tools-server-"] .action-chip.danger:focus{background:#3a171d;border-color:#ef4444}
dialog[id^="tools-server-"] .agent-config-summary{margin-top:12px;padding:11px 0 0;border:0;border-top:1px solid rgba(51,65,85,.65);border-radius:0;background:transparent}
dialog[id^="tools-server-"] .agent-config-panel{border-radius:0}
dialog[id^="tools-server-"] .modal-result-group{margin-top:10px}
dialog[id^="tools-server-"] .modal-result{margin:0;padding:6px 9px;border:0;border-left:2px solid #475569;border-radius:2px;background:rgba(15,23,42,.58)}
dialog[id^="tools-server-"] .modal-result.ok{border-left-color:#22c55e;background:rgba(20,83,45,.16)}
dialog[id^="tools-server-"] .modal-result.warn{border-left-color:#eab308;background:rgba(113,63,18,.16)}
dialog[id^="tools-server-"] .modal-result.bad{border-left-color:#ef4444;background:rgba(127,29,29,.16)}
dialog[id^="tools-server-"] .modal-result-line{display:flex;align-items:center;gap:7px;min-height:22px;flex-wrap:wrap;font-size:12px;line-height:1.25}
dialog[id^="tools-server-"] .modal-result-icon{color:#94a3b8;font-size:14px;font-weight:900}
dialog[id^="tools-server-"] .modal-result.ok .modal-result-icon,dialog[id^="tools-server-"] .modal-result.ok strong{color:#86efac}
dialog[id^="tools-server-"] .modal-result.warn .modal-result-icon,dialog[id^="tools-server-"] .modal-result.warn strong{color:#fde047}
dialog[id^="tools-server-"] .modal-result.bad .modal-result-icon,dialog[id^="tools-server-"] .modal-result.bad strong{color:#fca5a5}
dialog[id^="tools-server-"] .modal-result strong{display:inline;margin:0;font-size:12px;line-height:1.25}
dialog[id^="tools-server-"] .modal-result-separator{color:#64748b}
dialog[id^="tools-server-"] .modal-result-duration{color:#a8b5c7;font-variant-numeric:tabular-nums}
dialog[id^="tools-server-"] .modal-result details{display:block;margin:0}
dialog[id^="tools-server-"] .modal-result details[open]{flex-basis:100%;margin:3px 0 1px 21px}
dialog[id^="tools-server-"] .modal-result summary{display:inline;list-style:none;color:#93c5fd;font-size:12px;font-weight:700}
dialog[id^="tools-server-"] .modal-result summary::-webkit-details-marker{display:none}
dialog[id^="tools-server-"] .modal-result summary:hover,dialog[id^="tools-server-"] .modal-result summary:focus{color:#bae6fd;outline:none}
dialog[id^="tools-server-"] .modal-result .hide-output{display:none}
dialog[id^="tools-server-"] .modal-result details[open] .show-output{display:none}
dialog[id^="tools-server-"] .modal-result details[open] .hide-output{display:inline}
dialog[id^="tools-server-"] .modal-result .result{margin-top:7px;padding:8px;border-radius:3px}
@media(max-width:840px){dialog[id^="tools-server-"] .tools-actions,dialog[id^="tools-server-"] .agent-actions{display:flex}dialog[id^="tools-server-"] .tools-actions button,dialog[id^="tools-server-"] .agent-actions button{width:auto}}
.dns-manager-shell{min-height:100vh}.topbar{padding:6px 2px 2px}.topbar h1{font-size:32px;letter-spacing:0}.lead{font-size:14px}.top-actions .button-like,.top-actions button{min-height:38px}.button-like.secondary{background:#111827;border-color:#334155;color:#dbeafe}.sidebar{background:#070d1b;border-color:#172036;box-shadow:0 18px 60px rgba(0,0,0,.22)}.brand-block{display:flex;align-items:center;gap:10px;border-bottom:1px solid #182338;padding-bottom:12px}.brand-mark{display:inline-flex;width:34px;height:34px;align-items:center;justify-content:center;border-radius:8px;background:#0f2f5f;border:1px solid #1d4ed8;color:#bfdbfe;font-weight:900}.brand-title{display:block;font-weight:900;font-size:15px}.brand-subtitle{display:block;color:#7f8da3;font-size:11px;margin-top:2px}.sidebar-summary{margin-top:auto;border-top:1px solid #182338;padding-top:12px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.sidebar-summary-item{background:#0d1526;border:1px solid #1d2a43;border-radius:8px;padding:9px}.server-list-item{background:#0d1526;border-color:#1d2a43}.server-list-item.active{border-color:#3b82f6;box-shadow:inset 3px 0 0 #3b82f6,0 14px 30px rgba(37,99,235,.12);background:#101d33}.server-list-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:8px;color:#7f8da3;font-size:11px}.panel{background:#0d1424;border-color:#1a2539}.panel-header{padding:18px;border-bottom-color:#1d2a43;background:#101827}.server-title-wrap{display:flex;gap:14px;align-items:flex-start}.server-icon{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:8px;background:#0f2f5f;border:1px solid #1d4ed8;color:#bfdbfe;font-weight:900}.server-kicker{display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:#94a3b8;font-size:12px;margin-bottom:5px}.server-head-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:flex-end}.header-action-form{margin:0}.quick-status-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:12px}.quick-status-card,.dashboard-card{background:#101827;border:1px solid #1f2a44;border-radius:8px}.quick-status-card{min-height:96px;padding:12px}.status-card-top{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;margin-bottom:10px}.status-card-title{font-weight:900;font-size:13px;color:#e5edf7}.status-card-value{display:block;font-size:18px;font-weight:900}.status-card-note{display:block;color:#94a3b8;font-size:12px;margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.tab-row{display:flex;gap:6px;flex-wrap:wrap;margin:4px 0 12px}.tab-chip{border:1px solid #26344a;background:#0b1120;color:#cbd5e1;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:800}.tab-chip.active{background:#12305a;border-color:#2563eb;color:#dbeafe}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:12px}.dashboard-card{padding:14px}.dashboard-card h3{margin:0 0 12px;font-size:16px}.dashboard-card.wide{grid-column:1/-1}.dashboard-card.danger-panel{border-color:#7f1d1d;background:#211116}.health-list,.info-list{display:grid;gap:9px}.health-row,.info-row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #1c293d;padding-bottom:8px}.health-row:last-child,.info-row:last-child{border-bottom:0;padding-bottom:0}.health-label,.info-label-inline{color:#94a3b8;font-size:12px}.health-value,.info-value-inline{font-weight:800;text-align:right;overflow-wrap:anywhere}.event-box{border-left:2px solid #3b82f6;background:#0b1120;padding:10px;border-radius:4px;color:#cbd5e1;font-size:13px;line-height:1.45}.zone-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.zone-stat{background:#0b1120;border:1px solid #1f2a44;border-radius:8px;padding:11px}.zone-stat strong{display:block;font-size:20px;margin-top:4px}.danger-actions{display:flex;gap:8px;flex-wrap:wrap}.danger-actions form{margin:0}.credentials-card{margin-top:0}.technical-footer{margin-top:12px}
/* Round 2 visual refinement: presentation-only overrides. */
body{background:#07111f;color:#edf4ff}.page{max-width:1540px;padding:18px}.topbar{margin-bottom:10px;align-items:flex-end}.topbar h1{font-size:30px;font-weight:900;margin-top:3px}.topbar a{font-size:12px;color:#8fb8ff}.lead{color:#7f8da3}.top-actions{gap:8px}.top-actions .button-like,.top-actions button,.server-head-actions button,.quick-menu>summary{border-radius:10px;padding:9px 13px;box-shadow:0 8px 18px rgba(2,6,23,.16)}.primary-button{background:#2f6df6;border-color:#5288ff}.metric-strip{grid-template-columns:repeat(4,max-content);justify-content:end;gap:8px;margin:-2px 0 12px;opacity:.78}.metric-card{min-width:118px;border-color:#1b2940;background:#0b1424;padding:7px 10px;border-radius:10px}.metric-card .metric-label{font-size:9px}.metric-card .metric-value{font-size:14px;margin-top:2px}.workspace{grid-template-columns:318px minmax(0,1fr);gap:16px}.sidebar{height:calc(100vh - 116px);max-height:880px;min-height:570px;border-radius:16px;padding:16px;background:linear-gradient(180deg,#07101f,#091323 72%,#07101b);border-color:#18263d}.brand-block{gap:12px;padding-bottom:14px}.brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,#123d78,#0f172a);box-shadow:0 0 0 3px rgba(59,130,246,.12)}.brand-title{font-size:18px;letter-spacing:.01em}.brand-subtitle{font-size:12px;color:#90a0b8}.server-filter{height:40px;border-radius:12px;background:#0b1527;border-color:#20314b;padding:0 12px;color:#eaf2ff}.server-list{gap:10px;padding-right:4px}.server-list-item{border-radius:14px;padding:12px;background:#0c1729;border-color:#1a2941}.server-list-item:hover,.server-list-item:focus{border-color:#5aa3ff;background:#0f1e34}.server-list-item.active{border-color:#4f8fff;box-shadow:inset 3px 0 0 #58a6ff,0 0 0 1px rgba(88,166,255,.2),0 16px 36px rgba(37,99,235,.18);background:#11223a}.server-list-name{text-transform:uppercase;font-size:15px;letter-spacing:.04em}.server-list-meta{font-size:12px;color:#9aa8bb}.server-list-foot{font-size:10px;color:#738198}.pill{border-radius:999px;padding:4px 8px;font-size:10px;letter-spacing:.02em;background:#172237;border-color:#2a3850}.pill.ok{background:#0e2b23;border-color:#1d674d;color:#8bf0bc}.pill.bad{background:#35171b;border-color:#7f1d1d;color:#ffc4c4}.pill.warn{background:#332913;border-color:#8a6416;color:#ffe08a}.sidebar-summary{gap:8px}.sidebar-summary-item{border-radius:12px;background:#0b1527;border-color:#1b2b44;padding:9px 10px}.sidebar-summary-item .metric-value{font-size:16px}.panel{border-radius:18px;background:#0b1424;border-color:#17263d;box-shadow:0 24px 80px rgba(0,0,0,.2)}.panel-header{padding:22px 24px;background:linear-gradient(135deg,#101d32 0%,#0b1424 62%,#0d1b2f 100%);border-bottom-color:#1d2d46;border-radius:18px 18px 0 0}.panel-main-line{align-items:center}.server-title-wrap{align-items:center;gap:16px}.server-icon{width:58px;height:58px;border-radius:16px;font-size:18px;background:linear-gradient(135deg,#174e98,#11243f);box-shadow:0 14px 32px rgba(37,99,235,.18)}.server-kicker{margin-bottom:7px}.panel h2{font-size:40px;line-height:1;font-weight:900;text-transform:uppercase;letter-spacing:.01em}.panel-meta{color:#91a3ba}.panel-header .panel-meta{font-size:14px}.panel-header .badge-row{margin-top:11px}.server-head-actions{gap:9px}.quick-menu>summary{background:#0b1527;border-color:#243650}.quick-menu>summary:before{content:"⋮";font-size:18px;line-height:0;margin-right:7px;color:#93c5fd}.quick-menu-box{top:46px;border-radius:12px;background:#0b1322;border-color:#273854;padding:9px;box-shadow:0 24px 60px rgba(0,0,0,.45)}.menu-action{border-radius:8px;padding:9px 10px}.panel-body{padding:16px}.quick-status-grid{gap:12px;margin-bottom:14px}.quick-status-card{min-height:116px;border-radius:14px;padding:14px;background:#0f1b2d;border-color:#1e2e47;display:flex;flex-direction:column;justify-content:space-between}.quick-status-card:before{content:"";width:30px;height:3px;border-radius:999px;background:#3b82f6;opacity:.75;margin-bottom:7px}.status-card-title{font-size:11px;text-transform:uppercase;color:#91a3ba;letter-spacing:.06em}.status-card-top{margin-bottom:6px}.status-card-value{font-size:20px;line-height:1.15;color:#f2f7ff}.status-card-note{font-size:11px;color:#7f8fa6}.tab-row{margin:0 0 14px}.tab-chip{border-radius:10px;padding:7px 11px;background:#0a1220;border-color:#1c2a40;color:#9fb0c7}.tab-chip.active{background:#16345e;border-color:#3b82f6;color:#eaf2ff}.dashboard-grid{gap:14px;grid-template-columns:minmax(0,1fr) minmax(360px,.9fr)}.dashboard-card{border-radius:14px;padding:16px;background:#0f1b2d;border-color:#1e2e47}.dashboard-card h3{font-size:15px;text-transform:uppercase;letter-spacing:.04em;color:#eaf2ff;margin-bottom:14px}.health-list,.info-list{gap:0}.health-row,.info-row{min-height:36px;align-items:center;border-bottom-color:#1a2940;padding:7px 0}.health-label,.info-label-inline{font-size:12px;color:#8f9fb6}.health-value,.info-value-inline{font-size:13px;color:#e5edf7}.health-value:has(.action-open){display:flex;justify-content:flex-end}.action-open{border-radius:9px}.event-box{border:1px solid #1d2d45;border-left:3px solid #4f8fff;border-radius:12px;background:#0a1323;padding:12px 13px}.event-line{display:flex;gap:10px;align-items:flex-start}.event-time{flex:0 0 auto;color:#9fb0c7;font-size:12px;font-weight:800;font-variant-numeric:tabular-nums}.event-summary{color:#eaf2ff;font-size:13px;font-weight:800}.event-box details{margin-top:9px}.event-box summary{cursor:pointer;color:#93c5fd;font-size:12px;font-weight:800}.event-technical{margin-top:8px;color:#aebbd0;font-size:12px;line-height:1.45;overflow-wrap:anywhere}.zone-stats{gap:12px}.zone-stat{border-radius:12px;background:#0a1323;border-color:#1d2d45}.zone-stat strong{font-size:24px}.technical-footer{background:#0a1323;border-color:#1d2d45;border-radius:12px;color:#9fb0c7}.credentials-card{border-radius:12px;background:#0a1323;border-color:#1d2d45;padding:13px}.credentials-grid{grid-template-columns:1.15fr .9fr .9fr auto;gap:12px}.credential-actions{justify-content:flex-end}.agent-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.dashboard-card.danger-panel{background:linear-gradient(135deg,#261116,#160b0e);border-color:#6f1d24;box-shadow:inset 0 1px 0 rgba(248,113,113,.08)}.dashboard-card.danger-panel h3{color:#ffb4b4}.dashboard-card.danger-panel .panel-meta{color:#d29a9a}.danger-actions{gap:10px;margin-top:12px}.danger-actions .action-chip{border-radius:12px;min-height:38px;padding:9px 13px}.danger-actions .action-chip.maint{background:#33220f;border-color:#a16207}.danger-actions .action-chip.danger{background:#4a151c;border-color:#b91c1c;color:#ffe1e1}.modal{border-radius:16px;background:#0f1b2d;border-color:#263852}.modal-header{background:#101d32;border-bottom-color:#263852;border-radius:16px 16px 0 0}.modal-content{scrollbar-color:#334b70 #0b1424}.server-list,.result,.modal-content{scrollbar-width:thin;scrollbar-color:#334b70 #07101f}.server-list::-webkit-scrollbar,.result::-webkit-scrollbar,.modal-content::-webkit-scrollbar{width:9px;height:9px}.server-list::-webkit-scrollbar-track,.result::-webkit-scrollbar-track,.modal-content::-webkit-scrollbar-track{background:#07101f;border-radius:999px}.server-list::-webkit-scrollbar-thumb,.result::-webkit-scrollbar-thumb,.modal-content::-webkit-scrollbar-thumb{background:#334b70;border-radius:999px;border:2px solid #07101f}@media(max-width:1180px){.quick-status-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.dashboard-grid{grid-template-columns:1fr}.metric-strip{justify-content:start}}@media(max-width:840px){.topbar{align-items:flex-start}.top-actions,.server-head-actions,.danger-actions{display:flex}.metric-strip{grid-template-columns:repeat(2,minmax(0,1fr));opacity:1}.metric-card{min-width:0}.quick-status-grid,.zone-stats{grid-template-columns:1fr}.server-title-wrap{margin-bottom:12px}.panel h2{font-size:30px}.panel-main-line{display:block}.sidebar-summary{grid-template-columns:1fr}.tab-row{overflow:auto;flex-wrap:nowrap}.tab-chip{white-space:nowrap}.credentials-grid{grid-template-columns:1fr}.credential-actions{justify-content:flex-start}}
</style>
</head>
<body>
<main class="page dns-manager-shell">
<header class="topbar"><div><a href="dashboard.php">Voltar ao painel</a><h1>Servidores DNS</h1><p class="lead">Painel de gestão de servidores BIND9</p></div><div class="top-actions"><a class="button-like secondary" href="<?= htmlspecialchars(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dns-servers.php')), ENT_QUOTES, 'UTF-8') ?>">Atualizar</a><button class="secondary" type="button" data-open-dialog="help-server-modal">Ajuda</button><button class="primary-button" type="button" data-open-add>+ Adicionar servidor</button></div></header>
<?php if ($erro): ?><div class="message error"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="toast-stack"><div class="message <?= ($resultadoTeste && empty($resultadoTeste['ok'])) ? 'error' : 'success' ?>"><?= htmlspecialchars($sucesso) ?></div></div><?php endif; ?>
<?php if ($resultadoTeste && empty($resultadoTeste['ok']) && $modalRetorno === ''): ?>
<?php
$resumoTeste = ['Zona' => null, 'Serial' => null, 'Autoritativa' => null, 'Arquivo' => null, 'Ultimo carregamento' => null, 'Proximo refresh' => null, 'Expiracao' => null];
$linhasTeste = preg_split('/\R/', trim((string) ($resultadoTeste['saida'] ?? ''))) ?: [];
$rndcTeste = '';
foreach ($linhasTeste as $linhaTeste) {
    if (!str_contains($linhaTeste, '=')) {
        continue;
    }
    [$chaveTeste, $valorTeste] = explode('=', $linhaTeste, 2);
    $chaveTeste = trim($chaveTeste);
    $valorTeste = trim($valorTeste);
    if ($chaveTeste === 'zone') {
        $resumoTeste['Zona'] = $valorTeste;
    } elseif ($chaveTeste === 'serial') {
        $resumoTeste['Serial'] = $valorTeste;
    } elseif ($chaveTeste === 'authoritative') {
        $resumoTeste['Autoritativa'] = $valorTeste === 'yes' ? 'Sim' : ($valorTeste === 'no' ? 'Nao' : $valorTeste);
    } elseif ($chaveTeste === 'rndc_zonestatus') {
        $rndcTeste = $valorTeste;
    }
}
if (preg_match('/files:\s*([^ ]+)/', $rndcTeste, $m)) {
    $resumoTeste['Arquivo'] = $m[1];
}
foreach (['Ultimo carregamento' => '/last loaded:\s*(.+?)\s+next refresh:/', 'Proximo refresh' => '/next refresh:\s*(.+?)\s+expires:/', 'Expiracao' => '/expires:\s*(.+?)\s+(?:secure:|dynamic:|reconfigurable|$)/'] as $rotuloTeste => $regexTeste) {
    if (preg_match($regexTeste, $rndcTeste, $m)) {
        $tsTeste = strtotime($m[1]);
        $resumoTeste[$rotuloTeste] = $tsTeste ? date('d/m/Y H:i:s', $tsTeste) : trim($m[1]);
    }
}
$resumoTemDados = (bool) array_filter($resumoTeste, static fn($valor) => $valor !== null && $valor !== '');
?>
<section class="result-panel result-clean">
<h2><?= htmlspecialchars($resultadoTeste['titulo']) ?></h2>
<p><span class="<?= $resultadoTeste['ok'] ? 'pill ok' : 'pill bad' ?>"><?= $resultadoTeste['ok'] ? '✓ Sucesso' : 'Falha' ?></span> <span class="panel-meta"><?= (int) $resultadoTeste['duracao_ms'] ?> ms</span></p>
<?php if ($resumoTemDados): ?><div class="result-summary"><?php foreach ($resumoTeste as $rotulo => $valor): ?><?php if ($valor !== null && $valor !== ''): ?><div><span class="info-label"><?= htmlspecialchars($rotulo) ?></span><span class="info-value"><?= htmlspecialchars((string) $valor) ?></span></div><?php endif; ?><?php endforeach; ?></div><?php endif; ?>
<details class="technical-output"><summary>Ver saida tecnica</summary><div class="result"><?= htmlspecialchars($resultadoTeste['saida']) ?></div></details>
</section>
<?php endif; ?>
<section class="metric-strip" aria-label="Resumo dos servidores DNS"><div class="metric-card"><span class="metric-label">Total remotos</span><span class="metric-value"><?= (int) $totalServidoresRemotos ?></span></div><div class="metric-card"><span class="metric-label">Online</span><span class="metric-value"><?= (int) $totalOnline ?></span></div><div class="metric-card"><span class="metric-label">Slaves</span><span class="metric-value"><?= (int) $totalSlaves ?></span></div><div class="metric-card"><span class="metric-label">Ultimo inventario</span><span class="metric-value" style="font-size:16px"><?= htmlspecialchars($ultimoInventario) ?></span></div></section>
<section class="workspace"><aside class="sidebar"><div class="brand-block"><span class="brand-mark">DNS</span><span><span class="brand-title">DNS Manager</span><span class="brand-subtitle">Servidores DNS</span></span></div><input class="server-filter" type="search" placeholder="Buscar servidor..." data-server-filter><?php if (!$servidores): ?><div class="empty-state">Nenhum servidor remoto cadastrado.</div><?php else: ?><div class="server-list"><?php foreach ($servidores as $servidor): ?><?php $serverId = (int) $servidor['id']; $online = ($servidor['ultimo_status'] ?? '') === 'online'; $bindOk = ($servidor['bind_status'] ?? '') === 'ok'; $agentOk = ($servidor['agente_status'] ?? '') === 'instalado'; $ipPrincipal = trim((string) ($servidor['ip4'] ?: $servidor['hostname'])); $inventario = $inventarioPorServidor[$serverId] ?? null; $totalDominios = (int) ($inventario['total_zones'] ?? 0); ?><button type="button" class="server-list-item" data-server-target="server-panel-<?= $serverId ?>" data-server-id="<?= $serverId ?>" data-filter-text="<?= htmlspecialchars(strtolower($servidor['nome'] . ' ' . $ipPrincipal . ' ' . $servidor['hostname'] . ' ' . ($servidor['tipo'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"><span class="server-list-top"><span class="server-list-name"><?= htmlspecialchars(strtoupper((string) $servidor['nome'])) ?></span><span class="pill <?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'Online' : 'Falha' ?></span></span><span class="server-list-meta"><?= htmlspecialchars($ipPrincipal) ?> · <?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?></span><span class="badge-row"><?php if ($totalDominios > 0): ?><span class="pill ok"><?= (int) $totalDominios ?> <?= $totalDominios === 1 ? 'zona' : 'zonas' ?></span><?php else: ?><span class="pill warn">inventário pendente</span><?php endif; ?><span class="pill <?= $bindOk ? 'ok' : 'warn' ?>"><?= $bindOk ? 'BIND OK' : 'BIND pendente' ?></span><span class="pill <?= $agentOk ? 'ok' : 'warn' ?>"><?= $agentOk ? 'Agente OK' : 'Agente pendente' ?></span></span><span class="server-list-foot"><span>ID <?= $serverId ?></span><span><?= htmlspecialchars(dns_servers_formatar_timestamp_local($servidor['ultima_verificacao'] ?? null)) ?></span></span></button><?php endforeach; ?></div><?php endif; ?><div class="sidebar-summary"><div class="sidebar-summary-item"><span class="metric-label">Online</span><span class="metric-value"><?= (int) $totalOnline ?></span></div><div class="sidebar-summary-item"><span class="metric-label">Total</span><span class="metric-value"><?= (int) $totalServidoresRemotos ?></span></div><div class="sidebar-summary-item" style="grid-column:1/-1"><span class="metric-label">Inventário</span><span class="metric-value" style="font-size:13px"><?= htmlspecialchars($ultimoInventario) ?></span></div></div></aside>
<div class="main-panels"><?php if (!$servidores): ?><section class="panel active"><div class="empty-state">Cadastre um servidor para exibir o painel operacional.</div></section><?php endif; ?>
<?php foreach ($servidores as $servidor): ?><?php $serverId = (int) $servidor['id']; $online = ($servidor['ultimo_status'] ?? '') === 'online'; $bindOk = ($servidor['bind_status'] ?? '') === 'ok'; $agentOk = ($servidor['agente_status'] ?? '') === 'instalado'; $ipPrincipal = trim((string) ($servidor['ip4'] ?: $servidor['hostname'])); $inventario = $inventarioPorServidor[$serverId] ?? null; $totalDominios = (int) ($inventario['total_zones'] ?? 0); $ultimaVerificacao = dns_servers_formatar_timestamp_local($servidor['ultima_verificacao'] ?? null); $mensagemCurta = trim(preg_replace('/\s+/', ' ', (string) ($servidor['ultima_mensagem'] ?? ''))) ?: 'Sem eventos recentes carregados nesta tela.'; $mensagemCurta = substr($mensagemCurta, 0, 180); $eventoResumo = 'Status do servidor atualizado'; if (stripos($mensagemCurta, 'AXFR') !== false || stripos($mensagemCurta, 'Transferencia') !== false || stripos($mensagemCurta, 'Transferência') !== false) { $eventoResumo = 'Teste AXFR disponível'; } elseif (stripos($mensagemCurta, 'agente') !== false) { $eventoResumo = $agentOk ? 'Agente remoto validado' : 'Agente remoto requer validação'; } elseif (stripos($mensagemCurta, 'BIND') !== false || stripos($mensagemCurta, 'named') !== false) { $eventoResumo = $bindOk ? 'Status BIND verificado com sucesso' : 'Status BIND requer validação'; } elseif (!$online) { $eventoResumo = 'Servidor requer nova verificação'; } $credencialSalva = dns_server_tem_credencial_admin($servidor); $senhaSalva = dns_server_tem_senha_admin($servidor); $sudoSalva = !empty($servidor['admin_sudo_secret']); $chaveSalva = !empty($servidor['admin_key_secret']) || (($servidor['admin_auth'] ?? 'senha') === 'chave' && !empty($servidor['admin_secret']) && empty($servidor['admin_key_secret'])); $credencialData = dns_servers_formatar_timestamp_local($servidor['admin_secret_updated_at'] ?? null); ?>
<section class="panel" id="server-panel-<?= $serverId ?>" data-server-id="<?= $serverId ?>" data-default-open="<?= strcasecmp((string) $servidor['nome'], 'NS03') === 0 ? '1' : '0' ?>"><header class="panel-header"><div class="panel-main-line"><div class="server-title-wrap"><span class="server-icon">NS</span><div><div class="server-kicker"><span class="pill <?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'Online' : 'Falha' ?></span><span class="pill"><?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?></span><span>ID <?= $serverId ?></span></div><h2><?= htmlspecialchars(strtoupper((string) $servidor['nome'])) ?></h2><div class="panel-meta"><?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?> · <?= htmlspecialchars($ipPrincipal) ?> · <?= htmlspecialchars($servidor['hostname']) ?> · última verificação <?= htmlspecialchars($ultimaVerificacao) ?></div><div class="badge-row"><span class="pill <?= $bindOk ? 'ok' : 'bad' ?>"><?= $bindOk ? 'BIND OK' : 'BIND pendente' ?></span><span class="pill <?= $agentOk ? 'ok' : 'bad' ?>"><?= $agentOk ? 'Agente instalado' : 'Agente ausente' ?></span><span class="pill">SSH <?= htmlspecialchars((string) $servidor['ssh_user']) ?>:<?= (int) $servidor['ssh_port'] ?></span></div></div></div><div class="server-head-actions"><form method="POST" class="header-action-form"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_bind"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="secondary">Testar servidor</button></form><button type="button" class="secondary" data-open-dialog="tools-server-<?= $serverId ?>" data-open-section="diagnostico">Inventário</button><details class="quick-menu"><summary>Ações</summary><div class="quick-menu-box">
<div class="quick-group"><span class="quick-title">Diagnósticos</span>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_ssh"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="menu-action"><span class="icon" aria-hidden="true">🔐</span><span>Testar SSH</span></button></form>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_bind"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="menu-action"><span class="icon" aria-hidden="true">🌐</span><span>Testar BIND</span></button></form>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_transferencia"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="zona_teste" value="legacy.example"><button type="submit" class="menu-action"><span class="icon" aria-hidden="true">📦</span><span>Transferência (AXFR)</span></button></form>
</div>
<div class="quick-group"><span class="quick-title">Agente</span>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="atualizar_agente_admin"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="menu-action"><span class="icon" aria-hidden="true">⚙</span><span>Atualizar agente</span></button></form>
<button type="button" class="menu-action" data-open-agent-dialog="agent-server-<?= $serverId ?>" data-agent-config="agent-config-<?= $serverId ?>"><span class="icon" aria-hidden="true">👤</span><span>Gerenciar agente</span></button>
</div>
<div class="quick-group"><span class="quick-title">Manutenção</span>
<form method="POST" onsubmit="return confirm('Migrar blocos slave legados para named.conf.local neste servidor? Nenhum arquivo legado sera apagado.');"><?= csrf_field() ?><input type="hidden" name="acao" value="migrar_layout_slave"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="menu-action"><span class="icon" aria-hidden="true">🔄</span><span>Migrar layout</span></button></form>
</div>
<div class="quick-group"><span class="quick-title">Zona de perigo</span>
<form method="POST" onsubmit="return confirm('Remover este servidor do painel?');"><?= csrf_field() ?><input type="hidden" name="acao" value="remover"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="menu-action danger-link"><span class="icon" aria-hidden="true">🗑</span><span>Remover servidor</span></button></form>
</div>
</div></details></div></div></header>
<div class="panel-body">
<section class="quick-status-grid" aria-label="Status rapido do servidor">
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">SSH</span><span class="pill <?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'OK' : 'Falha' ?></span></div><span class="status-card-value"><?= $online ? 'Online' : 'Pendente' ?></span><span class="status-card-note"><?= htmlspecialchars((string) ($servidor['ultimo_status'] ?? 'desconhecido')) ?></span></div>
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">Agente remoto</span><span class="pill <?= $agentOk ? 'ok' : 'warn' ?>"><?= $agentOk ? 'OK' : 'Pendente' ?></span></div><span class="status-card-value"><?= htmlspecialchars($agentOk ? 'Instalado' : 'Validar') ?></span><span class="status-card-note"><?= htmlspecialchars((string) ($servidor['agente_status'] ?? 'desconhecido')) ?></span></div>
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">BIND / named</span><span class="pill <?= $bindOk ? 'ok' : 'warn' ?>"><?= $bindOk ? 'OK' : 'Pendente' ?></span></div><span class="status-card-value"><?= $bindOk ? 'Operacional' : 'Validar' ?></span><span class="status-card-note"><?= htmlspecialchars((string) ($servidor['bind_status'] ?? 'desconhecido')) ?></span></div>
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">named-checkconf</span><span class="pill <?= $bindOk ? 'ok' : 'warn' ?>"><?= $bindOk ? 'OK' : 'Pendente' ?></span></div><span class="status-card-value"><?= $bindOk ? 'Sem erro' : 'Validar' ?></span><span class="status-card-note">Baseado no status BIND</span></div>
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">AXFR</span><span class="pill warn">Testar</span></div><span class="status-card-value">Disponível</span><span class="status-card-note">Via ação Transferência</span></div>
<div class="quick-status-card"><div class="status-card-top"><span class="status-card-title">Última verificação</span><span class="pill"><?= $servidor['ultima_verificacao'] ? 'Registrada' : 'Indisponível' ?></span></div><span class="status-card-value" style="font-size:14px"><?= htmlspecialchars($ultimaVerificacao) ?></span><span class="status-card-note"><?= htmlspecialchars($mensagemCurta) ?></span></div>
</section>
<nav class="tab-row" aria-label="Seções do servidor"><span class="tab-chip active">Resumo</span><span class="tab-chip">Credenciais</span><span class="tab-chip">Agente remoto</span><span class="tab-chip">Zonas</span><span class="tab-chip">Auditoria</span><span class="tab-chip">Avançado</span></nav>
<section class="dashboard-grid">
<div class="dashboard-card"><h3>Saúde do servidor</h3><div class="health-list"><div class="health-row"><span class="health-label">Conexão SSH</span><span class="health-value"><span class="pill <?= $online ? 'ok' : 'bad' ?>"><?= $online ? 'OK' : 'Falha' ?></span></span></div><div class="health-row"><span class="health-label">Agente remoto</span><span class="health-value"><span class="pill <?= $agentOk ? 'ok' : 'warn' ?>"><?= $agentOk ? 'OK' : 'Pendente' ?></span></span></div><div class="health-row"><span class="health-label">Serviço BIND (named)</span><span class="health-value"><span class="pill <?= $bindOk ? 'ok' : 'warn' ?>"><?= $bindOk ? 'OK' : 'Validar' ?></span></span></div><div class="health-row"><span class="health-label">named-checkconf</span><span class="health-value"><?= $bindOk ? 'OK' : 'Pendente' ?></span></div><div class="health-row"><span class="health-label">Permissões</span><span class="health-value">Validar via ferramentas</span></div><div class="health-row"><span class="health-label">Leitura de SOA</span><span class="health-value">Validar via ferramentas</span></div><div class="health-row"><span class="health-label">Transferência AXFR</span><span class="health-value"><button type="button" class="secondary action-open" data-open-dialog="tools-server-<?= $serverId ?>" data-open-section="diagnostico">Testar</button></span></div></div></div>
<div class="dashboard-card"><h3>Eventos recentes</h3><div class="event-box"><div class="event-line"><span class="event-time"><?= htmlspecialchars($ultimaVerificacao) ?></span><span class="event-summary"><?= htmlspecialchars($eventoResumo) ?></span></div><details><summary>Ver saída técnica</summary><div class="event-technical"><?= htmlspecialchars($mensagemCurta) ?></div></details></div><?php if ($resultadoTeste && $modalRetorno === "tools-server-" . $serverId): ?><div class="event-box" style="margin-top:10px"><div class="event-line"><span class="event-time"><?= htmlspecialchars($ultimaVerificacao) ?></span><span class="event-summary"><?= htmlspecialchars((string) ($resultadoTeste['titulo'] ?? 'Resultado')) ?></span></div><details><summary>Ver saída técnica</summary><div class="event-technical"><?= htmlspecialchars($sucesso) ?></div></details></div><?php endif; ?></div>
<div class="dashboard-card"><h3>Informações gerais</h3><div class="info-list"><div class="info-row"><span class="info-label-inline">ID</span><span class="info-value-inline"><?= $serverId ?></span></div><div class="info-row"><span class="info-label-inline">Nome</span><span class="info-value-inline"><?= htmlspecialchars($servidor['nome']) ?></span></div><div class="info-row"><span class="info-label-inline">Hostname</span><span class="info-value-inline"><?= htmlspecialchars($servidor['hostname']) ?></span></div><div class="info-row"><span class="info-label-inline">IP principal</span><span class="info-value-inline"><?= htmlspecialchars($ipPrincipal) ?></span></div><div class="info-row"><span class="info-label-inline">IPv4</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['ip4'] ?? '')) ?: 'Indisponível' ?></span></div><div class="info-row"><span class="info-label-inline">IPv6</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['ip6'] ?? '')) ?: 'Indisponível' ?></span></div><div class="info-row"><span class="info-label-inline">Função</span><span class="info-value-inline"><?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?></span></div><div class="info-row"><span class="info-label-inline">SSH user</span><span class="info-value-inline"><?= htmlspecialchars((string) $servidor['ssh_user']) ?></span></div><div class="info-row"><span class="info-label-inline">SSH port</span><span class="info-value-inline"><?= (int) $servidor['ssh_port'] ?></span></div><div class="info-row"><span class="info-label-inline">Usuário administrativo</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root')) ?></span></div><div class="info-row"><span class="info-label-inline">Autenticação</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['admin_auth'] ?? 'senha')) ?></span></div><div class="info-row"><span class="info-label-inline">Modo de instalação</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['modo_instalacao'] ?? 'Indisponível')) ?></span></div><div class="info-row"><span class="info-label-inline">Descrição</span><span class="info-value-inline"><?= htmlspecialchars((string) ($servidor['descricao'] ?? '')) ?: 'Indisponível' ?></span></div><div class="info-row"><span class="info-label-inline">Criado em</span><span class="info-value-inline"><?= htmlspecialchars(dns_servers_formatar_timestamp_local($servidor['criado_em'] ?? null)) ?></span></div><div class="info-row"><span class="info-label-inline">Atualizado em</span><span class="info-value-inline"><?= htmlspecialchars(dns_servers_formatar_timestamp_local($servidor['atualizado_em'] ?? null)) ?></span></div></div></div>
<div class="dashboard-card"><h3>Zonas</h3><div class="zone-stats"><div class="zone-stat"><span class="info-label">Inventário</span><strong><?= (int) $totalDominios ?></strong></div><div class="zone-stat"><span class="info-label">Zonas slave</span><strong><?= htmlspecialchars((string) ($servidor['zonas_slave'] ?? '0')) ?></strong></div><div class="zone-stat"><span class="info-label">Último inventário</span><strong style="font-size:14px"><?= htmlspecialchars($ultimoInventario) ?></strong></div></div><footer class="technical-footer"><span>BIND: <strong><?= htmlspecialchars((string) ($servidor['bind_status'] ?? 'desconhecido')) ?></strong></span><span>Agente: <strong><?= htmlspecialchars((string) ($servidor['agente_status'] ?? 'desconhecido')) ?></strong></span><span>Tipo: <strong><?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?></strong></span></footer></div>
<div class="dashboard-card wide"><h3>Credenciais</h3><section class="credentials-card"><div class="credentials-grid"><div class="credential-state"><span class="credential-check">✓</span><span><?= $credencialSalva ? 'Salvas e criptografadas' : 'Nao cadastradas' ?></span></div><div><span class="info-label">Status</span><span class="info-value"><?= $credencialSalva ? 'Credenciais validas' : 'Ausentes' ?></span></div><div><span class="info-label">Atualizado em</span><span class="info-value"><?= htmlspecialchars($credencialSalva ? $credencialData : 'Nunca') ?></span></div><div class="credential-actions"><button type="button" class="secondary" data-view-secret="<?= $serverId ?>" data-secret-server="<?= htmlspecialchars($servidor['nome'], ENT_QUOTES, 'UTF-8') ?>" data-secret-user="<?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root'), ENT_QUOTES, 'UTF-8') ?>" <?= $senhaSalva ? '' : 'disabled' ?>>Visualizar senha salva</button></div></div><p class="credentials-note">As credenciais sao criptografadas localmente e usadas apenas em operacoes autorizadas do painel.</p></section></div>
<div class="dashboard-card"><h3>Agente remoto</h3><p class="panel-meta">Use os fluxos existentes para atualizar ou gerenciar a configuração administrativa do agente.</p><div class="agent-actions"><form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="atualizar_agente_admin"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="agente"><button type="submit" class="action-chip agent"><span class="chip-icon">⚙</span><span>Atualizar agente</span></button></form><button type="button" class="action-chip agent" data-open-agent-dialog="agent-server-<?= $serverId ?>" data-agent-config="agent-config-<?= $serverId ?>"><span class="chip-icon">👤</span><span>Gerenciar agente</span></button><button type="button" class="secondary action-open" data-open-dialog="edit-server-<?= $serverId ?>">Editar cadastro</button><button type="button" class="secondary action-open" data-open-dialog="tools-server-<?= $serverId ?>">Abrir ferramentas</button></div></div>
<div class="dashboard-card danger-panel"><h3>Avançado / Zona de risco</h3><p class="panel-meta">Ações administrativas sensíveis preservadas com os mesmos formulários e confirmações.</p><div class="danger-actions"><form method="POST" onsubmit="return confirm('Migrar blocos slave legados para named.conf.local neste servidor? Nenhum arquivo legado sera apagado.');"><?= csrf_field() ?><input type="hidden" name="acao" value="migrar_layout_slave"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="manutencao"><button type="submit" class="action-chip maint"><span class="chip-icon">↻</span><span>Migrar layout</span></button></form><form method="POST" onsubmit="return confirm('Remover este servidor do painel?');"><?= csrf_field() ?><input type="hidden" name="acao" value="remover"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="action-chip danger"><span class="chip-icon">×</span><span>Remover servidor</span></button></form></div></div>
</section>
<footer class="technical-footer"><span>Funcao: <strong><?= htmlspecialchars(strtoupper((string) $servidor['tipo'])) ?></strong></span><span>Usuario administrativo: <strong><?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root')) ?></strong></span><span>Autenticacao: <strong><?= htmlspecialchars((string) ($servidor['admin_auth'] ?? 'senha')) ?></strong></span><span>Porta SSH: <strong><?= (int) $servidor['ssh_port'] ?></strong></span></footer>
<dialog class="modal server-modal" id="tools-server-<?= $serverId ?>">
<div class="modal-header"><div><h2>Ferramentas • <?= htmlspecialchars($servidor['nome']) ?></h2></div><button class="close-button" type="button" data-close-dialog>Fechar</button></div>
<div class="modal-content">
<section class="tools-section" data-tools-section="diagnostico"><span class="tools-kicker"><span class="section-icon" aria-hidden="true">🔧</span>Diagnósticos</span><div class="tools-actions"><form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_ssh"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="diagnostico"><button type="submit" class="action-chip diag"><span class="chip-icon">🔐</span><span>Testar SSH</span></button></form><form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_bind"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="diagnostico"><button type="submit" class="action-chip diag"><span class="chip-icon">🌐</span><span>Testar BIND</span></button></form><form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="testar_transferencia"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="diagnostico"><input type="hidden" name="zona_teste" value="legacy.example"><button type="submit" class="action-chip diag"><span class="chip-icon">📦</span><span>Transferência (AXFR)</span></button></form></div><?php if ($modalRetorno === "tools-server-" . $serverId && $modalResultadoSection === "diagnostico"): ?><?php dns_servers_render_modal_resultado($resultadoTeste, $sucesso); ?><?php endif; ?></section>
<section class="tools-section" data-tools-section="agente">
<span class="tools-kicker"><span class="section-icon" aria-hidden="true">🤖</span>Agente</span>
<div class="agent-actions">
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="atualizar_agente_admin"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="agente"><button type="submit" class="action-chip agent"><span class="chip-icon">⚙</span><span>Atualizar agente</span></button></form>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="instalar_agente"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="agente"><button type="submit" class="action-chip agent"><span class="chip-icon">+</span><span>Instalar agente</span></button></form>
<button type="button" class="action-chip agent" data-open-agent-dialog="agent-server-<?= $serverId ?>" data-agent-config="agent-config-<?= $serverId ?>"><span class="chip-icon">👤</span><span>Gerenciar agente</span></button>
<form method="POST"><?= csrf_field() ?><input type="hidden" name="acao" value="remover_agente_admin"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="agente"><button type="submit" class="action-chip danger"><span class="chip-icon">×</span><span>Remover agente</span></button></form>
</div>
<div id="agent-config-<?= $serverId ?>" class="agent-config-summary" hidden>
<h4>Configuração do agente</h4>
<div class="agent-config-meta"><span>Usuário: <strong><?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root')) ?></strong></span><span>Credencial salva em: <strong><?= htmlspecialchars($credencialSalva ? $credencialData : 'Nunca') ?></strong></span></div>
<details class="agent-config-fields"><summary class="action-chip agent"><span class="chip-icon">▾</span><span>Expandir</span></summary>
<div class="agent-config-panel"><form method="POST" class="admin-grid"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="origem_form" value="agente"><input type="hidden" name="nome" value="<?= htmlspecialchars($servidor['nome'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="hostname" value="<?= htmlspecialchars($servidor['hostname'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="ip4" value="<?= htmlspecialchars((string) $servidor['ip4'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="ip6" value="<?= htmlspecialchars((string) $servidor['ip6'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="tipo" value="<?= htmlspecialchars((string) $servidor['tipo'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="ssh_user" value="<?= htmlspecialchars((string) $servidor['ssh_user'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="ssh_port" value="<?= (int) $servidor['ssh_port'] ?>"><?php if ($servidor['ativo']): ?><input type="hidden" name="ativo" value="1"><?php endif; ?><input type="hidden" name="descricao" value="<?= htmlspecialchars((string) $servidor['descricao'], ENT_QUOTES, 'UTF-8') ?>"><div><label>Usuário administrativo</label><input name="admin_user" value="<?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root')) ?>" maxlength="32" required></div><div><label>Método de autenticação</label><select name="admin_auth"><option value="senha" <?= (($servidor['admin_auth'] ?? 'senha') === 'senha') ? 'selected' : '' ?>>Senha</option><option value="chave" <?= (($servidor['admin_auth'] ?? 'senha') === 'chave') ? 'selected' : '' ?>>Chave SSH</option></select></div><div><label>Senha SSH</label><input type="password" name="admin_password" autocomplete="new-password" placeholder="<?= $senhaSalva ? 'senha salva; deixe vazio para manter' : '' ?>"></div><div><label>Senha sudo/root alternativa</label><input type="password" name="sudo_password" autocomplete="new-password" placeholder="<?= $sudoSalva ? 'senha salva; deixe vazio para manter' : 'deixe vazio para usar a senha SSH' ?>"></div><div><label>Data da última credencial salva</label><input value="<?= htmlspecialchars($credencialSalva ? $credencialData : 'Nunca', ENT_QUOTES, 'UTF-8') ?>" readonly></div><div class="wide"><label>Chave SSH administrativa</label><textarea name="admin_key" placeholder="<?= $chaveSalva ? 'chave salva; deixe vazio para manter' : 'Cole a chave privada quando usar autenticação por chave' ?>"></textarea></div><div class="wide agent-config-actions"><button type="submit" name="acao" value="atualizar" class="action-chip agent"><span class="chip-icon">💾</span><span>Salvar configuração</span></button></div></form></div>
</details>
</div>
<?php if ($modalRetorno === "tools-server-" . $serverId && $modalResultadoSection === "agente"): ?><?php dns_servers_render_modal_resultado($resultadoTeste, $sucesso); ?><?php endif; ?>
</section>
<section class="tools-section" data-tools-section="manutencao"><span class="tools-kicker"><span class="section-icon" aria-hidden="true">🛠</span>Manutenção</span><div class="tools-actions"><form method="POST" onsubmit="return confirm('Migrar blocos slave legados para named.conf.local neste servidor? Nenhum arquivo legado sera apagado.');"><?= csrf_field() ?><input type="hidden" name="acao" value="migrar_layout_slave"><input type="hidden" name="id" value="<?= $serverId ?>"><input type="hidden" name="return_modal" value="tools-server-<?= $serverId ?>"><input type="hidden" name="return_section" value="manutencao"><button type="submit" class="action-chip maint"><span class="chip-icon">🔄</span><span>Migrar layout</span></button></form></div><?php if ($modalRetorno === "tools-server-" . $serverId && $modalResultadoSection === "manutencao"): ?><?php dns_servers_render_modal_resultado($resultadoTeste, $sucesso); ?><?php endif; ?></section>
<details class="tools-section danger-zone"><summary><span aria-hidden="true">⚠</span>Zona de perigo</summary><div class="danger-zone-content"><p>Remover servidor do painel</p><div class="tools-actions"><form method="POST" onsubmit="return confirm('Remover este servidor do painel?');"><?= csrf_field() ?><input type="hidden" name="acao" value="remover"><input type="hidden" name="id" value="<?= $serverId ?>"><button type="submit" class="action-chip danger"><span class="chip-icon">🗑</span><span>Remover servidor</span></button></form></div></div></details>
</div>
</dialog>
<dialog class="modal server-modal agent-manager-modal" id="agent-server-<?= $serverId ?>"><div class="modal-header"><div><h2>Agente • <?= htmlspecialchars($servidor['nome']) ?></h2></div><button class="close-button" type="button" data-close-dialog>Fechar</button></div><div class="modal-content" data-agent-config-host="agent-config-<?= $serverId ?>"></div></dialog>
<dialog class="modal server-modal" id="edit-server-<?= $serverId ?>"><div class="modal-header"><div><h2>Cadastro - <?= htmlspecialchars($servidor['nome']) ?></h2><p class="panel-meta" style="margin:4px 0 0">Dados salvos no painel</p></div><button class="close-button" type="button" data-close-dialog>Fechar</button></div><div class="modal-content"><form method="POST" class="form-grid"><?= csrf_field() ?><input type="hidden" name="acao" value="atualizar"><input type="hidden" name="id" value="<?= $serverId ?>"><div><label>Nome</label><input name="nome" value="<?= htmlspecialchars($servidor['nome']) ?>" maxlength="40" required></div><div><label>Hostname</label><input name="hostname" value="<?= htmlspecialchars($servidor['hostname']) ?>" maxlength="253" required></div><div><label>IPv4</label><input name="ip4" value="<?= htmlspecialchars((string) $servidor['ip4']) ?>" maxlength="45"></div><div><label>IPv6</label><input name="ip6" value="<?= htmlspecialchars((string) $servidor['ip6']) ?>" maxlength="45"></div><div><label>Tipo</label><select name="tipo"><option value="slave">Slave</option></select></div><div><label>Porta SSH</label><input type="number" name="ssh_port" value="<?= (int) $servidor['ssh_port'] ?>" min="1" max="65535" required></div><div><label>Usuario administrativo</label><input name="admin_user" value="<?= htmlspecialchars((string) ($servidor['admin_user'] ?? 'root')) ?>" maxlength="32" required></div><div><label>Metodo de autenticacao</label><select name="admin_auth"><option value="senha" <?= (($servidor['admin_auth'] ?? 'senha') === 'senha') ? 'selected' : '' ?>>Senha</option><option value="chave" <?= (($servidor['admin_auth'] ?? 'senha') === 'chave') ? 'selected' : '' ?>>Chave SSH</option></select></div><div><label>Usuario gerenciado</label><input name="ssh_user" value="<?= htmlspecialchars($servidor['ssh_user']) ?>" maxlength="32" required></div><label class="checkbox"><input type="checkbox" name="ativo" value="1" <?= $servidor['ativo'] ? 'checked' : '' ?>> Ativo</label><div><label>Senha SSH</label><input type="password" name="admin_password" autocomplete="new-password" placeholder="<?= $senhaSalva ? 'senha salva; deixe vazio para manter' : '' ?>"></div><div><label>Senha sudo/root alternativa</label><input type="password" name="sudo_password" autocomplete="new-password" placeholder="<?= $sudoSalva ? 'senha salva; deixe vazio para manter' : 'deixe vazio para usar a senha SSH' ?>"></div><div class="wide"><label>Chave SSH administrativa</label><textarea name="admin_key" placeholder="<?= $chaveSalva ? 'chave salva; deixe vazio para manter' : 'Cole a chave privada quando usar autenticacao por chave' ?>"></textarea></div><div class="wide"><label>Descricao</label><textarea name="descricao" maxlength="500"><?= htmlspecialchars((string) $servidor['descricao']) ?></textarea></div><button type="submit">Salvar cadastro</button></form></div></dialog>

</div></section><?php endforeach; ?></div></section>
<dialog class="modal confirm-modal" id="help-server-modal"><div class="modal-header"><div><h2>Ajuda</h2></div><button class="close-button" type="button" data-close-dialog>Fechar</button></div><div class="modal-content"><p class="panel-meta" style="margin:0 0 10px">Use a sidebar para alternar servidores. O botão Ações concentra diagnósticos, agente, manutenção e zona de perigo.</p><p class="panel-meta" style="margin:0">Os botões de teste e manutenção usam os fluxos atuais da página.</p></div></dialog>
<dialog class="modal" id="add-server-modal"><div class="modal-header"><div><h2>Adicionar servidor</h2><p class="panel-meta" style="margin:4px 0 0">Cadastrar, adotar ou provisionar novo NS</p></div><button class="close-button" type="button" data-close-add>Fechar</button></div><div class="modal-content"><form method="POST" class="form-grid"><?= csrf_field() ?><input type="hidden" name="acao" value="cadastrar"><div class="wide option-row"><label class="checkbox"><input type="radio" name="modo_instalacao" value="adotar" checked> Adotar servidor existente</label><label class="checkbox"><input type="radio" name="modo_instalacao" value="provisionar"> Provisionar servidor novo</label></div><div class="wide hint">Para provisionar Debian limpo sem sudo, use login root ou informe a senha root alternativa. Usuario comum sem sudo nao consegue provisionar servidor limpo.</div><div><label>Nome</label><input name="nome" maxlength="40" placeholder="NS2" required></div><div><label>Hostname/IP</label><input name="hostname" maxlength="253" placeholder="ns2.exemplo.com.br" required></div><div><label>IPv4</label><input name="ip4" maxlength="45" placeholder="203.0.113.2"></div><div><label>IPv6</label><input name="ip6" maxlength="45" placeholder="2001:db8::2"></div><div><label>Funcao</label><select name="tipo"><option value="slave">Slave</option></select></div><div><label>Porta SSH</label><input type="number" name="ssh_port" value="22" min="1" max="65535" required></div><div><label>Usuario administrativo</label><input name="admin_user" value="root" maxlength="32" required></div><div><label>Metodo de autenticacao</label><select name="admin_auth"><option value="senha">Senha</option><option value="chave">Chave SSH</option></select></div><div><label>Senha SSH</label><input type="password" name="admin_password" autocomplete="new-password"></div><div><label>Senha sudo/root alternativa</label><input type="password" name="sudo_password" autocomplete="new-password" placeholder="deixe vazio para usar a senha SSH"></div><label class="checkbox"><input type="checkbox" name="ativo" value="1" checked> Ativo</label><div><label>Usuario gerenciado</label><input name="ssh_user" value="dns-sync" maxlength="32" readonly></div><div class="wide"><label>Chave SSH administrativa</label><textarea name="admin_key" placeholder="Cole a chave privada quando usar autenticacao por chave"></textarea></div><div class="wide"><label>Descricao</label><textarea name="descricao" maxlength="500" placeholder="Servidor DNS secundario"></textarea></div><button type="submit">Adicionar servidor</button></form></div></dialog>
<dialog class="modal confirm-modal" id="credential-confirm-modal"><div class="modal-header"><div><h2>Visualizar credencial salva</h2></div><button class="close-button" type="button" data-cancel-secret>Cancelar</button></div><div class="modal-content"><p class="panel-meta" style="margin:0">Deseja exibir a senha administrativa deste servidor?</p><div class="modal-warning">Use apenas em ambiente administrativo seguro.</div><div class="confirm-actions"><button type="button" class="secondary" data-cancel-secret>Cancelar</button><button type="button" data-confirm-secret>Visualizar</button></div></div></dialog><dialog class="modal secret-modal" id="saved-secret-modal"><div class="modal-header"><div><h2>Senha SSH Administrativa</h2></div><button class="close-button" type="button" data-close-dialog>Fechar</button></div><div class="modal-content"><div class="secret-meta"><span>Servidor:</span><strong data-secret-server-name></strong><span>Usuario:</span><strong data-secret-user-name></strong></div><div class="secret-value" data-secret-value>************</div><div class="secret-actions"><button type="button" class="secondary" data-toggle-secret>Mostrar</button><button type="button" class="secondary" data-copy-secret>Copiar</button><button type="button" class="close-button" data-close-dialog>Fechar</button><span class="panel-meta" data-secret-status></span></div></div></dialog>
</main><script>
function openDialogById(id){const dialog=document.getElementById(id);if(!dialog)return;if(typeof dialog.showModal==='function')dialog.showModal();else dialog.setAttribute('open','open');}
const addModal=document.getElementById('add-server-modal');document.querySelector('[data-open-add]')?.addEventListener('click',()=>openDialogById('add-server-modal'));document.querySelector('[data-close-add]')?.addEventListener('click',()=>addModal?.close?.());document.querySelectorAll('[data-open-dialog]').forEach(button=>button.addEventListener('click',()=>{const targetId=button.dataset.openDialog||'';const sectionId=button.dataset.openSection||'';const currentDialog=button.closest('dialog');if(currentDialog&&currentDialog.id!==targetId)currentDialog.close?.();button.closest('details')?.removeAttribute('open');openDialogById(targetId);if(sectionId){const section=document.getElementById(sectionId);if(section?.tagName==='DETAILS')section.open=true;setTimeout(()=>section?.scrollIntoView({block:'nearest'}),0);}}));document.querySelectorAll('[data-close-dialog]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close?.()));document.querySelectorAll('dialog').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();}));
document.querySelectorAll('[data-open-agent-dialog]').forEach(button=>button.addEventListener('click',()=>{const dialogId=button.dataset.openAgentDialog||'';const configId=button.dataset.agentConfig||'';const dialog=document.getElementById(dialogId);const config=document.getElementById(configId);const host=dialog?.querySelector(`[data-agent-config-host="${configId}"]`);if(!dialog||!config||!host)return;button.closest('details')?.removeAttribute('open');button.closest('dialog')?.close?.();config.hidden=false;host.append(config);const fields=config.querySelector('.agent-config-fields');if(fields)fields.open=true;openDialogById(dialogId);}));
const confirmSecretModal=document.getElementById('credential-confirm-modal');const secretModal=document.getElementById('saved-secret-modal');const secretValue=document.querySelector('[data-secret-value]');const secretStatus=document.querySelector('[data-secret-status]');const secretServerName=document.querySelector('[data-secret-server-name]');const secretUserName=document.querySelector('[data-secret-user-name]');const toggleSecretButton=document.querySelector('[data-toggle-secret]');let pendingSecretButton=null;let savedSecretPlain='';let secretVisible=false;function renderSecret(){if(!secretValue)return;secretValue.textContent=secretVisible?savedSecretPlain:(savedSecretPlain?'************':'');if(toggleSecretButton)toggleSecretButton.textContent=secretVisible?'Ocultar':'Mostrar';}document.querySelectorAll('[data-view-secret]').forEach(button=>button.addEventListener('click',()=>{pendingSecretButton=button;if(secretStatus)secretStatus.textContent='';openDialogById('credential-confirm-modal');}));document.querySelectorAll('[data-cancel-secret]').forEach(button=>button.addEventListener('click',()=>{pendingSecretButton=null;confirmSecretModal?.close?.();}));document.querySelector('[data-confirm-secret]')?.addEventListener('click',async()=>{const button=pendingSecretButton;if(!button)return;if(secretStatus)secretStatus.textContent='Carregando...';savedSecretPlain='';secretVisible=false;renderSecret();const form=new FormData();form.append('acao','visualizar_credencial');form.append('id',button.dataset.viewSecret||'');form.append('csrf_token','<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>');try{const response=await fetch('<?= htmlspecialchars(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'dns-servers.php')), ENT_QUOTES, 'UTF-8') ?>',{method:'POST',body:form,credentials:'same-origin'});const data=await response.json();if(!response.ok||!data.ok){throw new Error(data.erro||'Nao foi possivel visualizar a senha.');}savedSecretPlain=String(data.senha||'');secretVisible=false;if(secretServerName)secretServerName.textContent=button.dataset.secretServer||'';if(secretUserName)secretUserName.textContent=button.dataset.secretUser||'';if(secretStatus)secretStatus.textContent='';renderSecret();confirmSecretModal?.close?.();openDialogById('saved-secret-modal');}catch(error){if(secretStatus)secretStatus.textContent=error.message||'Nao foi possivel visualizar a senha.';}});toggleSecretButton?.addEventListener('click',()=>{if(!savedSecretPlain)return;secretVisible=!secretVisible;renderSecret();});document.querySelector('[data-copy-secret]')?.addEventListener('click',async()=>{if(savedSecretPlain==='')return;try{await navigator.clipboard.writeText(savedSecretPlain);if(secretStatus)secretStatus.textContent='Copiado.';}catch(error){if(secretStatus)secretStatus.textContent='Nao foi possivel copiar automaticamente.';}});secretModal?.addEventListener('close',()=>{savedSecretPlain='';secretVisible=false;pendingSecretButton=null;renderSecret();if(secretStatus)secretStatus.textContent='';if(secretServerName)secretServerName.textContent='';if(secretUserName)secretUserName.textContent='';});const serverButtons=[...document.querySelectorAll('[data-server-target]')];const serverPanels=[...document.querySelectorAll('.panel[data-server-id]')];function selectServer(id){let selectedPanel=null;serverPanels.forEach(panel=>{const active=panel.dataset.serverId===id;panel.classList.toggle('active',active);if(active)selectedPanel=panel;});serverButtons.forEach(button=>button.classList.toggle('active',button.dataset.serverId===id));if(selectedPanel)localStorage.setItem('dnsServerOpenId',id);}let selectedId=localStorage.getItem('dnsServerOpenId');if(!selectedId||!serverPanels.some(panel=>panel.dataset.serverId===selectedId)){selectedId=serverPanels.find(panel=>panel.dataset.defaultOpen==='1')?.dataset.serverId||serverPanels[0]?.dataset.serverId||'';}if(selectedId)selectServer(selectedId);const returnModalId='<?= htmlspecialchars((string) $modalRetorno, ENT_QUOTES, 'UTF-8') ?>';const returnSectionId='<?= htmlspecialchars((string) $modalResultadoSection, ENT_QUOTES, 'UTF-8') ?>';if(returnModalId){const match=returnModalId.match(/^tools-server-(\d+)$/);if(match)selectServer(match[1]);setTimeout(()=>{openDialogById(returnModalId);const section=document.querySelector(`#${returnModalId} [data-tools-section="${returnSectionId}"]`);section?.scrollIntoView({block:'nearest'});},0);}serverButtons.forEach(button=>button.addEventListener('click',()=>selectServer(button.dataset.serverId||'')));document.querySelector('[data-server-filter]')?.addEventListener('input',event=>{const term=(event.target.value||'').trim().toLowerCase();serverButtons.forEach(button=>{button.hidden=term!==''&&!String(button.dataset.filterText||'').includes(term);});});
function showAgentConfig(id){const config=document.getElementById(id);if(!config)return;config.hidden=false;const toggle=document.querySelector(`[data-toggle-agent-config="${id}"]`);toggle?.setAttribute('aria-expanded','true');}
document.querySelectorAll('[data-toggle-agent-config]').forEach(button=>button.addEventListener('click',()=>{const id=button.dataset.toggleAgentConfig||'';const config=document.getElementById(id);if(!config)return;const willShow=config.hidden;config.hidden=!willShow;button.setAttribute('aria-expanded',willShow?'true':'false');if(willShow)setTimeout(()=>config.scrollIntoView({block:'nearest'}),0);}));
document.querySelectorAll('[data-open-section]').forEach(button=>button.addEventListener('click',()=>{const id=button.dataset.openSection||'';if(document.getElementById(id)?.classList.contains('agent-config-summary'))showAgentConfig(id);}));
document.querySelectorAll('[data-auto-dismiss-result]').forEach(result=>{const details=result.querySelector('details');let dismissTimer;const scheduleDismiss=()=>{clearTimeout(dismissTimer);if(details?.open)return;dismissTimer=setTimeout(()=>{if(details?.open)return;result.classList.add('is-dismissing');setTimeout(()=>result.remove(),300);},6000);};details?.addEventListener('toggle',()=>{if(details.open){clearTimeout(dismissTimer);result.classList.remove('is-dismissing');}else{scheduleDismiss();}});scheduleDismiss();});
</script><?php require_once __DIR__ . '/includes/session-timeout.php'; ?></body></html>
