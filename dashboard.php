<?php
require "config.php";
require "includes/auth.php";
require_once __DIR__ . "/includes/db.php";
require_once __DIR__ . "/includes/dns_zones.php";

function metricError(string $name, string $message): void { error_log("Dashboard - falha em {$name}: {$message}"); }
function cpuSample(): ?array {
    $lines = @file('/proc/stat', FILE_IGNORE_NEW_LINES);
    if ($lines === false || empty($lines[0])) return null;
    $parts = preg_split('/\s+/', trim($lines[0]));
    if (!$parts || array_shift($parts) !== 'cpu' || count($parts) < 4) return null;
    $values = array_map('intval', $parts);
    return ['idle' => ($values[3] ?? 0) + ($values[4] ?? 0), 'total' => array_sum($values)];
}
function cpuUsage(): ?int {
    try {
        $a = cpuSample();
        if ($a === null) throw new RuntimeException('primeira leitura de /proc/stat indisponível');
        usleep(200000);
        $b = cpuSample();
        if ($b === null) throw new RuntimeException('segunda leitura de /proc/stat indisponível');
        $total = $b['total'] - $a['total'];
        if ($total <= 0) throw new RuntimeException('intervalo inválido');
        return max(0, min(100, (int) round((1 - (($b['idle'] - $a['idle']) / $total)) * 100)));
    } catch (Throwable $e) { metricError('CPU', $e->getMessage()); return null; }
}
function ramUsage(): ?int {
    try {
        $lines = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES);
        if ($lines === false) throw new RuntimeException('/proc/meminfo indisponível');
        $total = $available = 0;
        foreach ($lines as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m)) $total = (int) $m[1];
            elseif (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) $available = (int) $m[1];
        }
        if ($total <= 0) throw new RuntimeException('valores inválidos');
        return max(0, min(100, (int) round((($total - $available) / $total) * 100)));
    } catch (Throwable $e) { metricError('RAM', $e->getMessage()); return null; }
}
function diskUsage(): ?int {
    try {
        $total = @disk_total_space('/'); $free = @disk_free_space('/');
        if ($total === false || $free === false || $total <= 0) throw new RuntimeException('partição / indisponível');
        return max(0, min(100, (int) round((($total - $free) / $total) * 100)));
    } catch (Throwable $e) { metricError('disco', $e->getMessage()); return null; }
}
function uptimeText(): ?string {
    try {
        $raw = @file_get_contents('/proc/uptime');
        if ($raw === false) throw new RuntimeException('/proc/uptime indisponível');
        $seconds = (int) floor((float) explode(' ', trim($raw))[0]);
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            if ($hours > 0) {
                return $minutes > 0 ? $hours . 'h ' . $minutes . 'm' : $hours . 'h';
            }
            return $minutes > 0 ? $minutes . 'm' : $seconds . 's';
        }
        $days = intdiv($seconds, 86400);
        return $days . ($days === 1 ? ' dia' : ' dias');
    } catch (Throwable $e) { metricError('uptime', $e->getMessage()); return null; }
}
function countRecords(array $files, string $pattern): int {
    $total = 0;
    foreach ($files as $file) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) { error_log('Dashboard - falha ao ler zona: ' . $file); continue; }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, ';')) continue;
            if (preg_match($pattern, $line)) $total++;
        }
    }
    return $total;
}
function auditAction(string $action): string {
    $mapped = match ($action) {
        'CRIAR_DOMINIO'=>'Criar domínio','REMOVER_DOMINIO'=>'Remover domínio','ADICIONAR_REGISTRO'=>'Adicionar registro',
        'EDITAR_REGISTRO'=>'Editar registro','REMOVER_REGISTRO'=>'Remover registro','ADICIONAR_PTR_IPV6'=>'Adicionar PTR IPv6',
        'EDITAR_PTR_IPV6'=>'Editar PTR IPv6','REMOVER_PTR_IPV6'=>'Remover PTR IPv6','ADICIONAR_PTR_IPV4_GENERATE'=>'Adicionar PTR IPv4',
        'EDITAR_PTR_IPV4'=>'Editar PTR IPv4','REMOVER_PTR_IPV4'=>'Remover PTR IPv4','LOGIN_SUCESSO'=>'Login',
        'LOGIN_FALHA'=>'Login falhou','LOGOUT'=>'Logout','CRIAR_USUARIO'=>'Criar usuário','ALTERAR_USUARIO'=>'Alterar usuário',
        'REMOVER_USUARIO'=>'Remover usuário','REDEFINIR_SENHA_USUARIO'=>'Redefinir senha','ALTERAR_PROPRIA_SENHA'=>'Alterar própria senha',
        'CADASTRAR_DNS_SERVER'=>'Cadastrar servidor DNS','ALTERAR_DNS_SERVER'=>'Alterar servidor DNS',
        'REMOVER_DNS_SERVER'=>'Remover servidor DNS','TESTAR_DNS_SERVER'=>'Testar servidor DNS',
        default=>null
    };
    if ($mapped !== null) return $mapped;
    $text = str_replace('_', ' ', $action);
    return function_exists('mb_convert_case') ? mb_convert_case(mb_strtolower($text, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') : ucfirst(strtolower($text));
}
function auditDate(string $utc): string {
    try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i:s'); }
    catch (Throwable $e) { error_log('Dashboard - data inválida: ' . $e->getMessage()); return 'Indisponível'; }
}
function auditTarget(array $event): string {
    $domain = preg_replace('/\.(?:rev6|rev)$/i', '', trim((string) ($event['dominio'] ?? '')));
    $name = trim((string) ($event['nome_registro'] ?? ''));
    if ($domain !== '') return $domain;
    if ($name !== '' && !str_ends_with(strtolower($name), '.rev6') && !preg_match('/^[0-9a-f](?:\.[0-9a-f]){7,}$/i', $name)) return $name;
    return str_contains((string) ($event['acao'] ?? ''), 'PTR_IPV6') ? 'Registro reverso IPv6' : '-';
}
function usageClass(?int $value): string { return $value === null ? 'unavailable' : ($value >= 90 ? 'critical' : ($value >= 75 ? 'warning' : 'normal')); }

$auditEvents = []; $auditUnavailable = false;
try {
    $auditEvents = db()->query("SELECT usuario, acao, dominio, nome_registro, status, criado_em FROM audit_logs ORDER BY id DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $auditUnavailable = true; error_log('Dashboard - falha na auditoria: ' . $e->getMessage()); }
$forwardZones = glob('/var/cache/bind/master-aut/*.hosts') ?: [];
$reverseFiles = glob('/var/cache/bind/master-rev/*') ?: [];
$forwardRecords = countRecords($forwardZones, '/^(?:\S+\s+)?(?:\d+\s+)?IN\s+(?:A|AAAA|CNAME|MX|TXT)\s+/i');
$zoneInventorySummary = ['servidores' => [], 'divergencias' => 0, 'zonas_unicas' => null];
try {
    $zoneInventorySummary = dns_zones_resumo();
} catch (Throwable $e) {
    metricError('inventario_zonas', $e->getMessage());
}
$cpu = cpuUsage(); $ram = ramUsage(); $disk = diskUsage(); $uptime = uptimeText(); $hostname = gethostname() ?: 'Indisponível';
$serverIp = filter_var($_SERVER['SERVER_ADDR'] ?? '', FILTER_VALIDATE_IP);
if ($serverIp === false && $hostname !== 'Indisponível') {
    $resolvedIp = gethostbyname($hostname);
    $serverIp = filter_var($resolvedIp, FILTER_VALIDATE_IP) ? $resolvedIp : false;
}
$serverIp = $serverIp ?: 'Indisponível';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard DNS</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0}button,a{font:inherit}
.menu-toggle{display:none;position:fixed;top:14px;left:14px;z-index:30;border:1px solid #334155;border-radius:10px;background:#071226;color:#e2e8f0;padding:9px 12px;cursor:pointer}
.sidebar{position:fixed;left:0;top:0;width:270px;height:100vh;background:#020617;border-right:1px solid #1e293b;padding:20px;overflow-y:auto;box-shadow:none;z-index:20}.sidebar h2{color:#38bdf8;margin:0 0 30px}.sidebar a{display:block;color:#cbd5e1;text-decoration:none;padding:12px;border-radius:8px;margin-bottom:6px;transition:.2s}.sidebar a:hover,.sidebar a:focus,.sidebar a.active{background:#1e293b;color:#38bdf8;outline:none}.sidebar-group{margin-top:10px}.sidebar-group-toggle{display:flex;align-items:center;justify-content:space-between;width:100%;border:0;border-radius:8px;background:transparent;color:#94a3b8;padding:12px;cursor:pointer;text-align:left;font-size:13px;font-weight:bold;transition:.2s}.sidebar-group-toggle:hover,.sidebar-group-toggle:focus{background:#0f172a;color:#38bdf8;outline:none}.sidebar-group-arrow{font-size:14px;line-height:1}.sidebar-submenu{display:none;padding-left:8px}.sidebar-group.open .sidebar-submenu{display:block}.sidebar-submenu a{padding:10px 12px}
.main-content{margin-left:270px;padding:25px}.topbar{display:flex;justify-content:space-between;align-items:center;gap:18px;background:#071226;border:1px solid #1e293b;border-radius:14px;padding:17px 20px;margin-bottom:25px}.topbar-title{display:flex;align-items:center;gap:9px}.topbar-info{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:10px;color:#cbd5e1;font-size:13px}.info-badge{display:flex;align-items:center;gap:9px;min-height:40px;background:#020617;border:1px solid #1e293b;border-radius:11px;padding:9px 12px}.info-badge svg{width:19px;height:19px;color:#38bdf8;flex:0 0 auto}.info-badge-text{display:flex;flex-direction:column;gap:2px}.info-badge-label{color:#64748b;font-size:10px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase}.info-badge-value{color:#e2e8f0;font-weight:bold}
.section{background:#071226;border:1px solid #1e293b;border-radius:16px;padding:25px;margin-bottom:25px;box-shadow:0 0 20px #0004}.section-header{display:flex;justify-content:space-between;align-items:center;gap:15px;margin-bottom:18px}.section-header h2{font-size:18px;margin:0}.section-link{color:#38bdf8;text-decoration:none;font-weight:bold;font-size:14px}
.summary-grid,.health-grid,.quick-grid{display:grid;gap:16px}.summary-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.health-grid,.quick-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.stat{background:#020617;border:1px solid #1e293b;border-radius:14px;padding:18px;min-height:124px}.stat .icon{font-size:26px;margin-bottom:9px}.stat .label{color:#94a3b8;font-size:12px;text-transform:uppercase}.stat .value{font-size:25px;font-weight:bold;color:#38bdf8;margin-top:8px;overflow-wrap:anywhere}.stat .value.unavailable{font-size:18px;color:#94a3b8}.stat small{display:block;color:#64748b;margin-top:8px}.progress{width:100%;height:8px;background:#1e293b;border-radius:10px;overflow:hidden;margin-top:12px}.progress-bar{height:100%;border-radius:10px}.normal{background:#22c55e}.warning{background:#eab308}.critical{background:#ef4444}.progress-bar.unavailable{width:0!important}
.metric-icon{display:block;width:27px;height:27px;color:#38bdf8}
.activity-table{width:100%;border-collapse:collapse}.activity-table th,.activity-table td{padding:11px 10px;border-bottom:1px solid #1e293b;text-align:left;font-size:13px;vertical-align:top}.activity-table th{color:#94a3b8;font-size:12px;text-transform:uppercase}.activity-status{font-weight:bold}.activity-status.ok{color:#4ade80}.activity-status.error{color:#f87171}.empty{color:#94a3b8;margin:0}
.quick-link{display:block;min-height:105px;background:#020617;border:1px solid #1e293b;border-radius:14px;padding:18px;color:#e2e8f0;text-decoration:none;transition:.2s}.quick-link:hover,.quick-link:focus{border-color:#38bdf8;transform:translateY(-2px);outline:none}.quick-link strong{display:block;margin-top:10px}.quick-link small{display:block;color:#64748b;margin-top:6px}.menu-overlay{display:none}
@media(max-width:1100px){.summary-grid,.health-grid,.quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){.menu-toggle{display:block}.sidebar{transform:translateX(-100%);transition:transform .2s;width:min(300px,86vw)}.sidebar.open{transform:translateX(0)}.menu-overlay{position:fixed;inset:0;background:#020617b8;z-index:10}.menu-overlay.open{display:block}.main-content{margin-left:0;padding:70px 14px 20px}.topbar,.section-header{align-items:flex-start;flex-direction:column}.topbar-info{justify-content:flex-start;width:100%}.info-badge{flex:1 1 145px}.section{padding:18px}.summary-grid,.health-grid,.quick-grid{grid-template-columns:1fr}.activity-table thead{display:none}.activity-table,.activity-table tbody,.activity-table tr,.activity-table td{display:block;width:100%}.activity-table tr{background:#020617;border:1px solid #1e293b;border-radius:12px;padding:9px 12px;margin-bottom:12px}.activity-table td{display:grid;grid-template-columns:90px 1fr;gap:10px;border:0;padding:6px 0}.activity-table td:before{content:attr(data-label);color:#94a3b8;font-size:11px;text-transform:uppercase;font-weight:bold}}
</style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
<symbol id="icon-server" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="3" y="14" width="18" height="6" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 7h.01M7 17h.01M11 7h6M11 17h6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21c-2.4-2.5-3.6-5.5-3.6-9S9.6 5.5 12 3Z" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
<symbol id="icon-records" viewBox="0 0 24 24"><path d="M6 3h9l4 4v14H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M15 3v5h4M8 12h7M8 16h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="icon-reverse" viewBox="0 0 24 24"><path d="M17 2l4 4-4 4M3 11V9a3 3 0 0 1 3-3h15M7 22l-4-4 4-4m14-1v2a3 3 0 0 1-3 3H3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="icon-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4.5 21a7.5 7.5 0 0 1 15 0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-cpu" viewBox="0 0 24 24"><rect x="6" y="6" width="12" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="9" y="9" width="6" height="6" rx="1" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9 2v4m6-4v4M9 18v4m6-4v4M2 9h4m-4 6h4m12-6h4m-4 6h4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-memory" viewBox="0 0 24 24"><rect x="3" y="6" width="18" height="11" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M7 10v3m3-3v3m3-3v3m3-3v3M6 17v3m4-3v3m4-3v3m4-3v3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
<symbol id="icon-storage" viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="8" ry="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6m-16 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6" fill="none" stroke="currentColor" stroke-width="1.8"/></symbol>
<symbol id="icon-uptime" viewBox="0 0 24 24"><circle cx="12" cy="13" r="8" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 9v4l3 2M9 2h6M12 2v3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></symbol>
</svg>
<button class="menu-toggle" type="button" aria-controls="main-menu" aria-expanded="false">☰ Menu</button><div class="menu-overlay" aria-hidden="true"></div>
<nav class="sidebar" id="main-menu" aria-label="Menu principal">
<!-- [V2_SIDEBAR_GLOBAL] Transformar este menu em includes/sidebar.php e integrá-lo às demais páginas. -->
<h2>🌐 DNS Panel</h2><a class="active" href="dashboard.php" aria-current="page">🏠 Dashboard</a>
<div class="sidebar-group">
<button class="sidebar-group-toggle" type="button" aria-expanded="false" aria-controls="sidebar-dns"><span>DNS</span><span class="sidebar-group-arrow" aria-hidden="true">▸</span></button>
<div class="sidebar-submenu" id="sidebar-dns"><a href="domains.php">🌐 Domínios</a><a href="dns-zones.php">📦 Zonas DNS</a><a href="reverse-zones.php">🌍 Zonas Reversas</a><a href="zones.php">🧭 Inventário DNS</a><?php if (usuario_eh_administrador()): ?><a href="dns-servers.php">🌐 Servidores DNS</a><?php endif; ?></div>
</div>
<div class="sidebar-group">
<button class="sidebar-group-toggle" type="button" aria-expanded="false" aria-controls="sidebar-security"><span>Segurança</span><span class="sidebar-group-arrow" aria-hidden="true">▸</span></button>
<div class="sidebar-submenu" id="sidebar-security"><a href="security.php">🛡 Security DNS</a><a href="acl.php">🌐 ACL IPv4</a><a href="acl6.php">🌐 ACL IPv6</a><a href="fail2ban.php">🚫 Fail2Ban</a><a href="fail2ban-bind.php">🌐 Fail2Ban BIND9</a></div>
</div>
<div class="sidebar-group">
<button class="sidebar-group-toggle" type="button" aria-expanded="false" aria-controls="sidebar-logs"><span>Logs</span><span class="sidebar-group-arrow" aria-hidden="true">▸</span></button>
<div class="sidebar-submenu" id="sidebar-logs"><a href="logs.php">📜 Logs Sistema</a><a href="bind.php">🌐 Logs BIND</a><a href="ssh.php">🧾 SSH/Auth</a><a href="auditoria.php">📋 Auditoria</a></div>
</div>
<div class="sidebar-group">
<button class="sidebar-group-toggle" type="button" aria-expanded="false" aria-controls="sidebar-infrastructure"><span>Infraestrutura</span><span class="sidebar-group-arrow" aria-hidden="true">▸</span></button>
<div class="sidebar-submenu" id="sidebar-infrastructure"><a href="services.php">⚙ Serviços</a><a href="firewall.php">🔥 Firewall</a></div>
</div>
<div class="sidebar-group">
<button class="sidebar-group-toggle" type="button" aria-expanded="false" aria-controls="sidebar-account"><span>Conta</span><span class="sidebar-group-arrow" aria-hidden="true">▸</span></button>
<div class="sidebar-submenu" id="sidebar-account"><?php if (usuario_eh_administrador()): ?><a href="usuarios.php">👥 Usuários</a><?php endif; ?><a href="alterar-senha.php">🔑 Minha senha</a><a href="logout.php">🚪 Sair</a></div>
</div>
</nav>
<main class="main-content">
<header class="topbar"><strong class="topbar-title">📊 Dashboard DNS</strong><div class="topbar-info">
<span class="info-badge"><svg aria-hidden="true"><use href="#icon-server"></use></svg><span class="info-badge-text"><span class="info-badge-label">Servidor</span><span class="info-badge-value"><?= htmlspecialchars($hostname) ?></span></span></span>
<span class="info-badge"><svg aria-hidden="true"><use href="#icon-globe"></use></svg><span class="info-badge-text"><span class="info-badge-label">IP principal</span><span class="info-badge-value"><?= htmlspecialchars($serverIp) ?></span></span></span>
<span class="info-badge"><svg aria-hidden="true"><use href="#icon-user"></use></svg><span class="info-badge-text"><span class="info-badge-label">Usuário</span><span class="info-badge-value"><?= htmlspecialchars($_SESSION['usuario'] ?? 'admin') ?></span></span></span>
</div></header>
<section class="section"><div class="section-header"><h2>📦 Resumo DNS</h2></div><div class="summary-grid">
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-globe"></use></svg></div><div class="label">Domínios</div><div class="value"><?= count($forwardZones) ?></div><small>Zonas forward</small></div>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-records"></use></svg></div><div class="label">Registros DNS</div><div class="value"><?= $forwardRecords ?></div><small>A, AAAA, CNAME, MX e TXT</small></div>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-reverse"></use></svg></div><div class="label">Zonas reversas</div><div class="value"><?= count($reverseFiles) ?></div><small>IPv4 + IPv6</small></div>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-server"></use></svg></div><div class="label">Inventario DNS</div><div class="value <?= $zoneInventorySummary['zonas_unicas'] === null ? 'unavailable' : '' ?>"><?= $zoneInventorySummary['zonas_unicas'] === null ? 'Indisponível' : (int) $zoneInventorySummary['zonas_unicas'] ?></div><small>Zonas unicas NS1/slaves</small></div>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-records"></use></svg></div><div class="label">Divergencias</div><div class="value"><?= (int) $zoneInventorySummary['divergencias'] ?></div><small>Presenca e serial SOA</small></div>
<?php foreach ($zoneInventorySummary['servidores'] as $dnsInventoryServer): ?>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-server"></use></svg></div><div class="label"><?= htmlspecialchars($dnsInventoryServer['server_nome']) ?></div><div class="value <?= $dnsInventoryServer['last_ok'] ? '' : 'unavailable' ?>"><?= (int) $dnsInventoryServer['total_zones'] ?></div><small><?= htmlspecialchars(strtoupper($dnsInventoryServer['server_role'])) ?> · <?= $dnsInventoryServer['last_ok'] ? 'inventariado' : 'falha no inventario' ?></small></div>
<?php endforeach; ?>
</div></section>
<section class="section"><div class="section-header"><h2>🖥️ Saúde do servidor</h2></div><div class="health-grid">
<?php foreach ([['cpu','CPU',$cpu,'uso aproximado'],['memory','RAM',$ram,'memória em uso'],['storage','Disco',$disk,'partição /']] as $metric): $class=usageClass($metric[2]); ?>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-<?= $metric[0] ?>"></use></svg></div><div class="label"><?= htmlspecialchars($metric[1]) ?></div><div class="value <?= $metric[2] === null ? 'unavailable' : '' ?>"><?= $metric[2] === null ? 'Indisponível' : $metric[2].'%' ?></div><div class="progress" aria-hidden="true"><div class="progress-bar <?= $class ?>" style="width:<?= $metric[2] ?? 0 ?>%"></div></div><small><?= htmlspecialchars($metric[3]) ?></small></div>
<?php endforeach; ?>
<div class="stat"><div class="icon"><svg class="metric-icon" aria-hidden="true"><use href="#icon-uptime"></use></svg></div><div class="label">Uptime</div><div class="value <?= $uptime === null ? 'unavailable' : '' ?>"><?= htmlspecialchars($uptime ?? 'Indisponível') ?></div><small>Tempo ligado</small></div>
</div></section>
<section class="section"><div class="section-header"><h2>📋 Últimas atividades</h2><a class="section-link" href="auditoria.php">Ver auditoria completa</a></div>
<?php if ($auditUnavailable): ?><p class="empty">As atividades estão temporariamente indisponíveis.</p><?php elseif (!$auditEvents): ?><p class="empty">Nenhuma atividade registrada.</p><?php else: ?>
<table class="activity-table"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Domínio/Registro</th><th>Status</th></tr></thead><tbody>
<?php foreach ($auditEvents as $event): ?><tr><td data-label="Data"><?= htmlspecialchars(auditDate((string)$event['criado_em'])) ?></td><td data-label="Usuário"><?= htmlspecialchars((string)$event['usuario']) ?></td><td data-label="Ação"><?= htmlspecialchars(auditAction((string)$event['acao'])) ?></td><td data-label="Registro"><?= htmlspecialchars(auditTarget($event)) ?></td><td data-label="Status" class="activity-status <?= $event['status']==='OK'?'ok':'error' ?>"><?= htmlspecialchars((string)$event['status']) ?></td></tr><?php endforeach; ?>
</tbody></table><?php endif; ?></section>
<section class="section"><div class="section-header"><h2>⚡ Acesso rápido</h2></div><div class="quick-grid">
<?php foreach ([['domains.php','➕','Novo Domínio','Criar e administrar domínios'],['dns-zones.php','📦','Zonas DNS','Gerenciar registros forward'],['reverse-zones.php','🔁','Zonas Reversas','Gerenciar registros PTR'],['zones.php','🧭','Inventário DNS','Comparar NS1 e slaves'],['auditoria.php','📋','Auditoria','Consultar histórico completo'],['services.php','⚙️','Serviços','Administrar serviços do servidor'],['firewall.php','🔥','Firewall','Consultar e gerenciar regras']] as $link): ?>
<a class="quick-link" href="<?= $link[0] ?>"><span><?= $link[1] ?></span><strong><?= $link[2] ?></strong><small><?= $link[3] ?></small></a><?php endforeach; ?>
<?php if (usuario_eh_administrador()): ?><a class="quick-link" href="usuarios.php"><span>👥</span><strong>Usuários</strong><small>Administrar acessos ao painel</small></a><?php endif; ?>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
</main>
<?php require_once __DIR__ . '/includes/session-timeout.php'; ?>
<script>
const button=document.querySelector('.menu-toggle'),menu=document.querySelector('.sidebar'),overlay=document.querySelector('.menu-overlay');
function setMenu(open){menu.classList.toggle('open',open);overlay.classList.toggle('open',open);button.setAttribute('aria-expanded',open?'true':'false')}
button.addEventListener('click',()=>setMenu(!menu.classList.contains('open')));overlay.addEventListener('click',()=>setMenu(false));menu.addEventListener('click',e=>{if(e.target.closest('a')&&matchMedia('(max-width:760px)').matches)setMenu(false)});
document.querySelectorAll('.sidebar-group-toggle').forEach(toggle=>toggle.addEventListener('click',()=>{
const group=toggle.closest('.sidebar-group'),open=!group.classList.contains('open');
group.classList.toggle('open',open);toggle.setAttribute('aria-expanded',open?'true':'false');
toggle.querySelector('.sidebar-group-arrow').textContent=open?'▾':'▸';
}));
</script>
</body>
</html>
