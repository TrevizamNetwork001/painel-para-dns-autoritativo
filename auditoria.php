<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

function dominio_legivel_auditoria(?string $dominio): string
{
    if ($dominio === null || trim($dominio) === '') {
        return '-';
    }

    return preg_replace('/\.(?:rev6|rev)$/i', '', trim($dominio));
}

function acao_legivel(string $acao): string
{
    return match ($acao) {
        'CRIAR_DOMINIO'             => '➕ Domínio',
        'CRIAR_ZONA_FORWARD'        => '➕ Zona forward',
        'CRIAR_ZONA_REVERSA_IPV4'   => '➕ Zona reversa IPv4',
        'CRIAR_ZONA_REVERSA_IPV6'   => '➕ Zona reversa IPv6',
        'ERRO_CRIAR_DOMINIO'        => '❌ Criar domínio',
        'REMOVER_DOMINIO'           => '🗑️ Domínio',
        'REMOVER_ZONA_FORWARD'      => '🗑️ Zona forward',
        'REMOVER_ZONA_REVERSA_IPV4' => '🗑️ Zona reversa IPv4',
        'REMOVER_ZONA_REVERSA_IPV6' => '🗑️ Zona reversa IPv6',
        'ERRO_REMOVER_DOMINIO'      => '❌ Remover domínio',
        'ADICIONAR_REGISTRO'        => '➕ Registro',
        'EDITAR_REGISTRO'           => '✏️ Registro',
        'REMOVER_REGISTRO'          => '🗑️ Registro',
        'ADICIONAR_PTR_IPV6'        => '➕ PTR IPv6',
        'EDITAR_PTR_IPV6'           => '✏️ PTR IPv6',
        'REMOVER_PTR_IPV6'          => '🗑️ PTR IPv6',
        'ADICIONAR_PTR_IPV4_GENERATE' => '➕ PTR IPv4',
        'EDITAR_PTR_IPV4'             => '✏️ PTR IPv4',
        'REMOVER_PTR_IPV4'            => '🗑️ PTR IPv4',
        'CHECK_BIND'       => 'Verificar BIND',
        'RELOAD_BIND'      => 'Recarregar BIND',
        'RESTART_BIND'     => 'Reiniciar BIND',
        'START_BIND'       => 'Iniciar BIND',
        'STOP_BIND'        => 'Parar BIND',
        'CHECK_FAIL2BAN'   => 'Verificar Fail2Ban',
        'RESTART_FAIL2BAN' => 'Reiniciar Fail2Ban',
        'CHECK_SSH'        => 'Verificar SSH',
        'RESTART_SSH'      => 'Reiniciar SSH',
        'CHECK_FIREWALL'   => 'Verificar firewall',
        'RELOAD_FIREWALL'  => 'Recarregar firewall',
        'LOGIN_SUCESSO'    => '✅ Login',
        'LOGIN_FALHA'      => '❌ Login falhou',
        'LOGOUT'           => '🚪 Logout',
        'TESTE_AUDITORIA'  => '🧪 Teste',
        'CRIAR_USUARIO'           => '➕ Usuário',
        'ALTERAR_USUARIO'         => '✏️ Usuário',
        'REMOVER_USUARIO'         => '🗑️ Usuário',
        'REDEFINIR_SENHA_USUARIO' => '🔑 Redefinir senha',
        'ALTERAR_PROPRIA_SENHA'   => '🔑 Alterar senha',
        'CADASTRAR_DNS_SERVER'     => '➕ Servidor DNS',
        'ALTERAR_DNS_SERVER'       => '✏️ Servidor DNS',
        'REMOVER_DNS_SERVER'       => '🗑️ Servidor DNS',
        'TESTAR_DNS_SERVER'        => '🔍 Testar servidor DNS',
        default            => $acao,
    };
}

function registro_legivel_auditoria(array $log): string
{
    $tipo = trim((string) ($log['tipo_registro'] ?? ''));
    $nome = trim((string) ($log['nome_registro'] ?? ''));

    if ($tipo === 'PTR') {
        if (str_contains($log['acao'], 'IPV6')) {
            return 'PTR IPv6';
        }

        if (str_contains($log['acao'], 'IPV4')) {
            return 'PTR IPv4';
        }
    }

    if ($nome === '') {
        return '-';
    }

    if ($tipo !== '' && !in_array($tipo, ['DOMINIO', 'ZONA', 'SERVICO'], true)) {
        return $nome . ' (' . $tipo . ')';
    }

    return $nome;
}

function valor_legivel_auditoria(array $log, string $campo): string
{
    $valor = trim((string) ($log[$campo] ?? ''));

    if ($campo === 'valor_novo' && str_starts_with($log['acao'], 'REMOVER_')) {
        return '❌ Removido';
    }

    if ($valor === '' || strcasecmp($valor, 'removido') === 0) {
        if (
            $campo === 'valor_antigo'
            && (
                str_starts_with($log['acao'], 'ADICIONAR_')
                || str_starts_with($log['acao'], 'CRIAR_')
            )
        ) {
            return 'inexistente';
        }

        return $valor === '' ? '-' : '❌ Removido';
    }

    if (($log['tipo_registro'] ?? '') !== 'PTR') {
        $nome = preg_quote(trim((string) ($log['nome_registro'] ?? '')), '/');
        $tipo = preg_quote(trim((string) ($log['tipo_registro'] ?? '')), '/');

        if ($nome !== '' && $tipo !== '') {
            $valor = preg_replace(
                '/^' . $nome . '\s+IN\s+' . $tipo . '\s+/i',
                '',
                $valor
            );
        }
    }

    if (preg_match('/^[0-9a-f](?:\.[0-9a-f])+\s+IN\s+PTR\s+(.+)$/i', $valor, $m)) {
        return $m[1];
    }

    return $valor;
}

function query_auditoria(array $substituicoes = []): string
{
    $query = array_merge($_GET, $substituicoes);

    foreach ($query as $chave => $valor) {
        if ($valor === '' || $valor === null) {
            unset($query[$chave]);
        }
    }

    return http_build_query($query);
}

$pdo = db();
$dominio = trim((string) ($_GET['dominio'] ?? ''));
$acao = trim((string) ($_GET['acao'] ?? ''));
$usuario = trim((string) ($_GET['usuario'] ?? ''));
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$pagina = max(1, filter_input(INPUT_GET, 'pagina', FILTER_VALIDATE_INT) ?: 1);
$porPagina = 50;

if (!in_array($status, ['', 'OK', 'ERRO'], true)) {
    $status = '';
}

$where = [];
$params = [];

if ($dominio !== '') {
    $where[] = 'dominio LIKE :dominio';
    $params[':dominio'] = '%' . $dominio . '%';
}

if ($acao !== '') {
    $where[] = 'acao = :acao';
    $params[':acao'] = $acao;
}

if ($usuario !== '') {
    $where[] = 'usuario = :usuario';
    $params[':usuario'] = $usuario;
}

if ($status !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$usuarios = $pdo
    ->query("SELECT DISTINCT usuario FROM audit_logs WHERE TRIM(usuario) <> '' ORDER BY usuario")
    ->fetchAll(PDO::FETCH_COLUMN);

$acoes = $pdo
    ->query("SELECT DISTINCT acao FROM audit_logs WHERE TRIM(acao) <> '' ORDER BY acao")
    ->fetchAll(PDO::FETCH_COLUMN);

$selectSql = 'SELECT * FROM audit_logs' . $whereSql . ' ORDER BY id DESC';

if (isset($_GET['exportar']) && $_GET['exportar'] === 'csv') {
    $stmt = $pdo->prepare($selectSql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="auditoria-dns-' . date('Y-m-d-His') . '.csv"');
    echo "\xEF\xBB\xBF";

    $saida = fopen('php://output', 'w');
    fputcsv(
        $saida,
        ['Data', 'Usuário', 'Ação', 'Domínio', 'Registro', 'Valor antigo', 'Valor novo', 'Status'],
        ';',
        '"',
        ''
    );

    while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($saida, [
            date('d/m/Y H:i:s', strtotime($log['criado_em'])),
            $log['usuario'],
            acao_legivel($log['acao']),
            dominio_legivel_auditoria($log['dominio'] ?? null),
            registro_legivel_auditoria($log),
            valor_legivel_auditoria($log, 'valor_antigo'),
            valor_legivel_auditoria($log, 'valor_novo'),
            $log['status'],
        ], ';', '"', '');
    }

    fclose($saida);
    exit;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs' . $whereSql);
$countStmt->execute($params);
$totalRegistros = (int) $countStmt->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalRegistros / $porPagina));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * $porPagina;

$stmt = $pdo->prepare($selectSql . ' LIMIT :limite OFFSET :offset');
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor, PDO::PARAM_STR);
}
$stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<title>Auditoria DNS</title>
<style>
body{font-family:Arial;background:#0f172a;color:#e2e8f0;margin:0;padding:25px;}
h1{color:#fff;margin-top:0;}
a{color:#38bdf8;text-decoration:none;}
.card{background:#020617;padding:20px;border-radius:12px;margin-bottom:20px;}
.filtros{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
input,select,button,.botao{padding:10px;border-radius:6px;border:1px solid #334155;background:#020617;color:white;}
button,.botao{background:#2563eb;cursor:pointer;display:inline-block;}
.botao-secundario{background:#166534;}
.table-wrap{background:#020617;border-radius:12px;overflow-x:auto;}
table{width:100%;border-collapse:collapse;background:#020617;table-layout:fixed;min-width:1050px;}
th,td{border:1px solid #334155;padding:8px;font-size:13px;vertical-align:top;overflow-wrap:anywhere;word-break:break-word;}
th{background:#111827;}
.col-data{width:140px;white-space:nowrap;}
.col-user{width:80px;}
.col-acao{width:135px;}
.col-dominio{width:125px;}
.col-registro{width:115px;font-family:Consolas,monospace;font-size:12px;}
.col-valor{font-family:Consolas,monospace;font-size:12px;line-height:1.35;}
.col-status{width:65px;text-align:center;}
.ok{color:#22c55e;font-weight:bold;}
.erro{color:#ef4444;font-weight:bold;}
.paginacao{display:flex;justify-content:space-between;align-items:center;margin-top:16px;gap:12px;}
.paginacao .desativado{color:#64748b;pointer-events:none;}
.vazio{text-align:center;color:#94a3b8;padding:25px;}
</style>
</head>
<body>
<h1>Auditoria DNS</h1>
<p><a href="dashboard.php">← Voltar ao painel</a></p>

<div class="card">
<form method="GET" class="filtros">
    <input type="text" name="dominio" placeholder="Filtrar por domínio" value="<?= htmlspecialchars($dominio) ?>">

    <select name="acao">
        <option value="">Todas as ações</option>
        <?php foreach ($acoes as $opcao): ?>
        <option value="<?= htmlspecialchars($opcao) ?>" <?= $acao === $opcao ? 'selected' : '' ?>>
            <?= htmlspecialchars(acao_legivel($opcao)) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <select name="usuario">
        <option value="">Todos os usuários</option>
        <?php foreach ($usuarios as $opcaoUsuario): ?>
        <option value="<?= htmlspecialchars($opcaoUsuario) ?>" <?= $usuario === $opcaoUsuario ? 'selected' : '' ?>>
            <?= htmlspecialchars($opcaoUsuario) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <select name="status">
        <option value="">Todos os status</option>
        <option value="OK" <?= $status === 'OK' ? 'selected' : '' ?>>OK</option>
        <option value="ERRO" <?= $status === 'ERRO' ? 'selected' : '' ?>>ERRO</option>
    </select>

    <button type="submit">Filtrar</button>
    <a href="auditoria.php">Limpar</a>
    <a class="botao botao-secundario" href="auditoria.php?<?= htmlspecialchars(query_auditoria(['exportar' => 'csv', 'pagina' => null])) ?>">Exportar CSV</a>
</form>
</div>

<div class="table-wrap">
<table>
<thead>
<tr>
    <th class="col-data">Data</th>
    <th class="col-user">Usuário</th>
    <th class="col-acao">Ação</th>
    <th class="col-dominio">Domínio</th>
    <th class="col-registro">Registro</th>
    <th class="col-valor">Valor antigo</th>
    <th class="col-valor">Valor novo</th>
    <th class="col-status">Status</th>
</tr>
</thead>
<tbody>
<?php if (!$logs): ?>
<tr><td colspan="8" class="vazio">Nenhum registro encontrado.</td></tr>
<?php endif; ?>
<?php foreach ($logs as $log): ?>
<tr>
    <td class="col-data"><?= date('d/m/Y H:i:s', strtotime($log['criado_em'])) ?></td>
    <td class="col-user"><?= htmlspecialchars($log['usuario']) ?></td>
    <td class="col-acao"><?= htmlspecialchars(acao_legivel($log['acao'])) ?></td>
    <td class="col-dominio"><?= htmlspecialchars(dominio_legivel_auditoria($log['dominio'] ?? null)) ?></td>
    <td class="col-registro"><?= htmlspecialchars(registro_legivel_auditoria($log)) ?></td>
    <td class="col-valor"><?= nl2br(htmlspecialchars(valor_legivel_auditoria($log, 'valor_antigo'))) ?></td>
    <td class="col-valor"><?= nl2br(htmlspecialchars(valor_legivel_auditoria($log, 'valor_novo'))) ?></td>
    <td class="col-status <?= $log['status'] === 'OK' ? 'ok' : 'erro' ?>"><?= htmlspecialchars($log['status']) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="paginacao">
    <a class="<?= $pagina <= 1 ? 'desativado' : '' ?>" href="auditoria.php?<?= htmlspecialchars(query_auditoria(['pagina' => max(1, $pagina - 1), 'exportar' => null])) ?>">← Anterior</a>
    <span>Página <?= $pagina ?> de <?= $totalPaginas ?> (<?= $totalRegistros ?> registros)</span>
    <a class="<?= $pagina >= $totalPaginas ? 'desativado' : '' ?>" href="auditoria.php?<?= htmlspecialchars(query_auditoria(['pagina' => min($totalPaginas, $pagina + 1), 'exportar' => null])) ?>">Próxima →</a>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
