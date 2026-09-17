@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'app-page')

@section('content')
@php
    $organizationId = auth()->user()->current_organization_id;

    $dashboardServers = App\Models\DnsServer::query()
        ->forOrganization($organizationId)
        ->where('status', '!=', 'transferred')
        ->orderBy('name')
        ->get();
    $dashboardObservations = Illuminate\Support\Facades\Schema::hasTable(
        'dns_authoritative_observations'
    )
        ? App\Models\DnsAuthoritativeObservation::query()
            ->where('organization_id', $organizationId)
            ->get()
        : collect();

    $dashboardZones = App\Models\DnsZone::query()
        ->forOrganization($organizationId)
        ->get(['id', 'name']);
    $zoneNames = $dashboardZones->pluck('name', 'id');
    $serverCount = $dashboardServers->count();
    $zoneCount = $dashboardZones->count();

    $onlineServerCount = $dashboardServers
        ->where('status', 'online')
        ->count();

    $warningServerCount = $dashboardServers
        ->where('status', 'warning')
        ->count();

    $offlineServerCount = $dashboardServers
        ->where('status', 'offline')
        ->count();

    $unknownServerCount = $dashboardServers
        ->whereIn('status', ['pending', 'maintenance'])
        ->count();

    $primaryServers = $dashboardServers->where('role', 'primary');
    $secondaryServers = $dashboardServers->where('role', 'secondary');
    $infrastructureGroups = [
        ['label' => 'Primários', 'servers' => $primaryServers],
        ['label' => 'Secundários', 'servers' => $secondaryServers],
        ['label' => 'Independentes', 'servers' => $dashboardServers->where('role', 'standalone')],
    ];
    $isAuthoritativeOnline = fn ($server) =>
        $server->authoritative_observed_at?->gte(now()->subMinutes(10))
        && (bool) data_get($server->authoritative_runtime, 'available', false);
    $serviceOnlineCount = $dashboardServers->filter($isAuthoritativeOnline)->count();
    $primaryOnlineCount = $primaryServers->filter($isAuthoritativeOnline)->count();
    $secondaryOnlineCount = $secondaryServers->filter($isAuthoritativeOnline)->count();
    $synchronizedZoneCount = $dashboardObservations
        ->where('status', 'synchronized')
        ->pluck('dns_zone_id')
        ->unique()
        ->count();
    $mismatchZoneCount = $dashboardObservations
        ->where('status', 'serial_mismatch')
        ->pluck('dns_zone_id')
        ->unique()
        ->count();
    $mismatchObservationCount = $dashboardObservations->where('status', 'serial_mismatch')->count();
    $failedTransferCount = $dashboardObservations
        ->whereIn('status', ['transfer_failed', 'primary_unreachable'])
        ->count();
    $expiredZoneCount = $dashboardObservations
        ->where('status', 'expired')
        ->pluck('dns_zone_id')
        ->unique()
        ->count();
    $dashboardPublications = App\Models\DnsAgentPublication::query()
        ->where('organization_id', $organizationId)
        ->whereIn('status', ['pending', 'downloaded', 'applying'])
        ->get();
    $pendingPublicationCount = $dashboardPublications->count();
    $pendingItems = collect();
    foreach ($dashboardServers as $server) {
        if (in_array($server->status, ['offline', 'warning'], true)) {
            $pendingItems->push(['type' => 'Servidor '.($server->status === 'offline' ? 'offline' : 'em atenção'), 'object' => $server->name, 'detail' => $server->hostname, 'severity' => $server->status === 'offline' ? 'critical' : 'warning', 'at' => $server->last_seen_at, 'url' => route('servers.index')]);
        }
        if (in_array($server->role, ['primary', 'secondary'], true) && ! $isAuthoritativeOnline($server) && in_array($server->status, ['online', 'warning'], true)) {
            $pendingItems->push(['type' => 'Saúde autoritativa não confirmada', 'object' => $server->name, 'detail' => 'Sem observação recente de serviço disponível', 'severity' => 'warning', 'at' => $server->authoritative_observed_at, 'url' => route('servers.index')]);
        }
        if (data_get($server->authoritative_runtime, 'recursion_enabled') === true) {
            $pendingItems->push(['type' => 'Recursão habilitada', 'object' => $server->name, 'detail' => 'Observada no serviço autoritativo', 'severity' => 'warning', 'at' => $server->authoritative_observed_at, 'url' => route('servers.index')]);
        }
    }
    foreach ($dashboardObservations as $observation) {
        $type = match ($observation->status) {
            'serial_mismatch' => 'Zona divergente',
            'transfer_failed' => 'Transferência falhando',
            'primary_unreachable' => 'Primário indisponível',
            'expired' => 'Zona expirada',
            default => null,
        };
        if ($type) {
            $pendingItems->push(['type' => $type, 'object' => $zoneNames[$observation->dns_zone_id] ?? 'Zona #'.$observation->dns_zone_id, 'detail' => ($observation->status === 'serial_mismatch' ? 'Serial observado difere do esperado' : 'Estado reportado pelo agente').($dashboardServers->firstWhere('id', $observation->dns_server_id) ? ' · '.$dashboardServers->firstWhere('id', $observation->dns_server_id)->name : ''), 'severity' => in_array($observation->status, ['expired', 'transfer_failed', 'primary_unreachable'], true) ? 'critical' : 'warning', 'at' => $observation->updated_at, 'url' => route('zones.index')]);
        }
    }
    foreach ($dashboardPublications as $publication) {
        $pendingItems->push(['type' => 'Publicação pendente', 'object' => $dashboardServers->firstWhere('id', $publication->dns_server_id)?->name ?? 'Servidor DNS', 'detail' => 'Estado: '.match ($publication->status) { 'downloaded' => 'baixada', 'applying' => 'aplicando', default => 'aguardando' }, 'severity' => 'warning', 'at' => $publication->updated_at, 'url' => route('zones.index')]);
    }
    $activeAlertCount = $pendingItems->count();
    $healthState = $serverCount === 0 || ($onlineServerCount === 0 && $activeAlertCount === 0) ? 'neutral' : ($pendingItems->contains(fn ($item) => $item['severity'] === 'critical') ? 'critical' : ($activeAlertCount || $onlineServerCount < $serverCount ? 'warning' : 'healthy'));
    $healthLabel = match ($healthState) { 'healthy' => 'Ambiente operacional', 'warning' => 'Ambiente requer atenção', 'critical' => 'Ambiente crítico', default => $serverCount ? 'Estado operacional não confirmado' : 'Aguardando infraestrutura' };
    $serverIds = $dashboardServers->pluck('id');
    $recentOperations = $serverIds->isEmpty() ? collect() : App\Models\DnsBindOperation::query()->whereIn('dns_server_id', $serverIds)->whereIn('status', ['succeeded', 'failed', 'expired'])->latest('updated_at')->limit(4)->get()->map(fn ($operation) => ['label' => match ($operation->action) { 'discover_bind_zones' => 'Descoberta de zonas', 'install_bind' => 'Instalação do BIND', 'configure_bind' => 'Configuração do BIND', 'upgrade_agent' => 'Atualização do agente', default => 'Aplicação de zonas' }, 'icon' => match ($operation->action) { 'discover_bind_zones' => 'online', 'install_bind', 'configure_bind' => 'server', 'upgrade_agent' => 'upload', default => 'zone' }, 'context' => $dashboardServers->firstWhere('id', $operation->dns_server_id)?->name ?? 'Servidor DNS', 'status' => $operation->status, 'at' => $operation->completed_at ?? $operation->updated_at]);
    $recentActivity = $recentOperations->sortByDesc('at')->take(4);

    $hasServers = $serverCount > 0;
    $hasAlerts = $activeAlertCount > 0;

    $canManageUsers =
        auth()->user()->is_platform_admin
        || auth()->user()->roleForOrganization(
            auth()->user()->current_organization_id
        ) === 'organization_admin';
@endphp

<div class="app-shell dashboard-v2">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark brand-mark-small">
                <span>DNS</span>
            </div>

            <div>
                <strong>DNS Center</strong>
                <span>Operations Platform</span>
            </div>
        </div>

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <a
                href="{{ route('dashboard') }}"
                class="nav-item is-active"
            >
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect
                            x="4"
                            y="4"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="14"
                            y="4"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="4"
                            y="14"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="14"
                            y="14"
                            width="6"
                            height="6"
                            rx="1"
                        />
                    </svg>
                </span>

                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="{{ route('servers.index') }}" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect
                            x="4"
                            y="3"
                            width="16"
                            height="7"
                            rx="2"
                        />
                        <rect
                            x="4"
                            y="14"
                            width="16"
                            height="7"
                            rx="2"
                        />
                        <path d="M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5" />
                    </svg>
                </span>

                Servidores
            </a>

            <a href="{{ route('nameservers.index') }}" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M7 7h12M15 3l4 4-4 4M17 17H5M9 13l-4 4 4 4" />
                    </svg>
                </span>

                Nameservers
            </a>

            <a href="{{ route('zones.index') }}" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" />
                        <path
                            d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9
                              S14.4 18.5 12 21
                              C9.6 18.5 8.4 15.5 8.4 12
                              S9.6 5.5 12 3Z"
                        />
                    </svg>
                </span>

                Domínios
            </a>

            <span class="nav-section">Operações</span>

            @if ($canManageUsers)
                <a href="{{ route('users.index') }}" class="nav-item">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="9" cy="8" r="3" />
                            <path d="M3.5 20a5.5 5.5 0 0 1 11 0" />
                            <path
                                d="M16 5.5a3 3 0 0 1 0 5.5
                                   M17 14a5 5 0 0 1 3.5 4.8"
                            />
                        </svg>
                    </span>

                    Usuários
                </a>
            @endif

        </nav>
    </aside>

    <main class="main-content dashboard-main">
        <header class="topbar dashboard-heading">
            <div>
                <p class="eyebrow dashboard-eyebrow">
                    <span class="dashboard-live-dot"></span>
                    Central de operações
                </p>

                <h1>Visão geral do ambiente</h1>

                <p class="page-description">
                    Monitore sua infraestrutura DNS autoritativa em tempo real.
                </p>
            </div>

            <div class="dashboard-header-right">
                <div class="topbar-actions">
                    <a class="dashboard-search" data-dashboard-search href="{{ route('servers.index') }}" aria-label="Abrir inventário de servidores" title="Buscar servidores (Ctrl + K)">
                        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                    </a>
                    <x-account-menu />
                </div>
            </div>
        </header>

        @if (session('status'))
            <div
                class="alert alert-success flash-message"
                data-flash-message
            >
                {{ session('status') }}
            </div>
        @endif

        <section class="dashboard-health dashboard-health-{{ $healthState }}" aria-label="Saúde do ambiente">
            <span class="dashboard-health-dot" aria-hidden="true"></span>
            <div class="dashboard-health-copy"><strong>{{ $healthLabel }}</strong><p>{{ $onlineServerCount }} de {{ $serverCount }} servidores online <span aria-hidden="true">·</span> {{ $mismatchZoneCount }} {{ $mismatchZoneCount === 1 ? 'zona divergente' : 'zonas divergentes' }} <span aria-hidden="true">·</span> {{ $activeAlertCount }} {{ $activeAlertCount === 1 ? 'pendência operacional' : 'pendências operacionais' }}</p></div>
            <span class="dashboard-health-arrow" aria-hidden="true">›</span>
        </section>

        <section class="dashboard-kpi-grid" aria-label="Indicadores operacionais">
            @foreach ([
                ['Servidores DNS', $serverCount, $onlineServerCount.' online · '.($serverCount - $onlineServerCount).' offline', 'servers.index', 'server', $serverCount ? $onlineServerCount / $serverCount * 100 : 0],
                ['Zonas autoritativas', $zoneCount, $mismatchZoneCount.' '.($mismatchZoneCount === 1 ? 'divergente' : 'divergentes'), 'zones.index', 'zone', $zoneCount ? $mismatchZoneCount / $zoneCount * 100 : 0],
                ['Serviços', $serviceOnlineCount, $serviceOnlineCount.' online · '.max(0, $serverCount - $serviceOnlineCount).' offline', 'servers.index', 'online', $serverCount ? $serviceOnlineCount / $serverCount * 100 : 0],
                ['Pendências', $activeAlertCount, $hasAlerts ? 'Requerem atenção' : 'Ambiente sem alertas', null, 'alert', min(100, $activeAlertCount * 15)],
            ] as [$label, $value, $detail, $destination, $icon, $progress])
                <article class="dashboard-kpi-card dashboard-kpi-{{ $icon }}">
                    <span class="dashboard-kpi-icon kpi-icon-{{ $icon === 'alert' && ! $hasAlerts ? 'online' : $icon }}" aria-hidden="true"><x-dashboard-icon :name="$icon" /></span>
                    <div class="dashboard-kpi-content"><strong class="dashboard-kpi-value" @if ($icon === 'zone') data-dashboard-zone-count @endif>{{ $value }}</strong><span class="dashboard-kpi-label">{{ $label }}</span><span class="dashboard-kpi-detail">{{ $detail }}</span><span class="dashboard-kpi-progress"><span style="width: {{ $progress }}%"></span></span></div>
                    @if ($destination)<a class="dashboard-kpi-arrow" href="{{ route($destination) }}" aria-label="Ver {{ strtolower($label) }}">›</a>@endif
                </article>
            @endforeach
        </section>

        <section class="dashboard-operations-grid" aria-label="Infraestrutura e pendências">
            <article class="dashboard-panel dashboard-topology-panel">
                <div class="dashboard-panel-heading"><h2>Topologia da infraestrutura</h2><a class="dashboard-panel-link" href="{{ route('servers.index') }}">Ver todos</a></div>
                <div class="dashboard-topology-canvas">
                    <div class="dashboard-topology-root">DNS autoritativo <small>Funções cadastradas</small></div>
                    @if ($hasServers)
                        <div class="dashboard-topology-groups">
                            @foreach ($infrastructureGroups as $group)
                                @if ($group['servers']->isNotEmpty())
                                    <div class="dashboard-topology-group">
                                        <h3>{{ $group['label'] }} <span>{{ $group['servers']->count() }}</span></h3>
                                        @foreach ($group['servers']->take(3) as $server)
                                            @php
                                                $topologyStatus = ! $server->enabled || $server->status === 'maintenance' ? 'maintenance' : $server->status;
                                                $topologyStatusLabel = match ($topologyStatus) { 'online' => 'Online', 'warning' => 'Atenção', 'offline' => 'Offline', 'maintenance' => 'Desativado', default => 'Desconhecido' };
                                            @endphp
                                            <div class="dashboard-topology-node"><span class="dashboard-server-status dashboard-server-status-{{ $topologyStatus }}" aria-hidden="true"></span><span class="dashboard-topology-node-copy"><strong title="{{ $server->name }}">{{ $server->name }}</strong><small>{{ $topologyStatusLabel }}</small></span></div>
                                        @endforeach
                                        @if ($group['servers']->count() > 3)<small class="dashboard-topology-more">+ {{ $group['servers']->count() - 3 }} servidores</small>@endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @else
                        <p class="dashboard-empty-line">Nenhum servidor cadastrado.</p>
                    @endif
                </div>
            </article>
            <article class="dashboard-panel dashboard-server-panel">
                <div class="dashboard-panel-heading"><h2>Estado dos servidores</h2><a class="dashboard-panel-link" href="{{ route('servers.index') }}">Ver todos</a></div>
                @if ($hasServers)
                    <div class="dashboard-server-list">
                        @foreach ($dashboardServers->take(6) as $server)
                            @php
                                $serverStatusLabel = match ($server->status) { 'online' => 'Online', 'warning' => 'Atenção', 'offline' => 'Offline', 'maintenance' => 'Desativado', default => 'Aguardando agente' };
                            @endphp
                            <a href="{{ route('servers.index') }}" class="dashboard-server-item">
                                <span class="dashboard-server-status dashboard-server-status-{{ $server->status }}" aria-hidden="true"></span>
                                <span class="dashboard-server-info"><strong>{{ $server->name }}</strong><small title="{{ $server->hostname }}">{{ $server->hostname }}</small><small class="dashboard-server-contact">{{ $server->last_seen_at ? 'Último visto '.$server->last_seen_at->diffForHumans() : 'Sem heartbeat recente' }}</small></span>
                                <span class="dashboard-server-badges"><span class="dashboard-server-role">{{ match ($server->role) { 'primary' => 'Primário', 'secondary' => 'Secundário', default => 'Independente' } }}</span><span class="dashboard-server-state dashboard-server-state-{{ $server->status }}">{{ $serverStatusLabel }}</span></span>
                            </a>
                        @endforeach
                    </div>
                    @if ($serverCount > 6)<a class="dashboard-panel-link dashboard-more" href="{{ route('servers.index') }}">Mais {{ $serverCount - 6 }} servidores</a>@endif
                @else
                    <p class="dashboard-empty-line">Nenhum servidor DNS cadastrado.</p>
                @endif
            </article>
            <article class="dashboard-panel dashboard-pending-panel">
                <div class="dashboard-panel-heading"><h2>Pendências operacionais</h2><span class="dashboard-count-badge">{{ $activeAlertCount }}</span><a class="dashboard-panel-link" href="{{ route('servers.index') }}">Ver todas</a></div>
                @forelse ($pendingItems->take(4) as $item)
                    <a class="dashboard-pending-item" href="{{ $item['url'] }}">
                        <span class="dashboard-issue-marker issue-{{ $item['severity'] }}" aria-hidden="true"><x-dashboard-icon name="alert" /></span>
                        <span class="dashboard-issue-copy"><strong>{{ $item['type'] }}</strong><span class="dashboard-issue-object">{{ $item['object'] }}</span><small>{{ $item['detail'] }}</small></span>
                        <time class="dashboard-issue-severity" @if ($item['at']) datetime="{{ $item['at']->toIso8601String() }}" @endif>{{ $item['at']?->diffForHumans() ?? 'Agora' }}</time>
                    </a>
                @empty
                    <p class="dashboard-empty-line">✓ Nenhuma pendência operacional</p>
                @endforelse
                @if ($activeAlertCount > 4)<p class="dashboard-more">+ {{ $activeAlertCount - 4 }} pendências adicionais nos painéis de servidores e zonas.</p>@endif
            </article>
        </section>

        <section class="dashboard-lower-grid">
        <section class="dashboard-panel dashboard-authoritative" aria-labelledby="authoritative-title">
            <div class="dashboard-panel-heading"><h2 id="authoritative-title"><span class="dashboard-title-symbol">▣</span> DNS autoritativo</h2></div>
            @if ($mismatchObservationCount > $mismatchZoneCount)
                <p class="dashboard-observation-note">{{ $mismatchZoneCount }} {{ $mismatchZoneCount === 1 ? 'zona divergente' : 'zonas divergentes' }} · {{ $mismatchObservationCount }} observações afetadas</p>
            @endif
            <div class="dashboard-authoritative-grid">
                @foreach ([
                    ['Primários', $primaryOnlineCount, $primaryServers->count(), 'primaries-online', $primaryServers->count() ? $primaryOnlineCount / $primaryServers->count() * 100 : 0],
                    ['Secundários', $secondaryOnlineCount, $secondaryServers->count(), 'secondaries-online', $secondaryServers->count() ? $secondaryOnlineCount / $secondaryServers->count() * 100 : 0],
                    ['Sincronizadas', $synchronizedZoneCount, null, 'zonas-sincronizadas', $zoneCount ? $synchronizedZoneCount / $zoneCount * 100 : 0],
                    ['Divergentes', $mismatchZoneCount, null, 'zonas-divergentes', $zoneCount ? $mismatchZoneCount / $zoneCount * 100 : 0],
                ] as [$label, $value, $total, $key, $progress])
                    <div class="dashboard-authoritative-stat dashboard-authoritative-{{ $key }}{{ $key === 'zonas-divergentes' && $value > 0 ? ' has-alert' : '' }}"><span class="dashboard-auth-icon" aria-hidden="true"><x-dashboard-icon :name="$key" /></span><span>{{ $label }}</span><strong data-authoritative-counter="{{ $key }}" data-authoritative-value="{{ $value }}">{{ $value }}@if ($total !== null)<small> / {{ $total }}</small>@endif</strong><small>{{ $key === 'primaries-online' || $key === 'secondaries-online' ? ($total - $value).' offline' : ($key === 'zonas-divergentes' ? 'Serial mismatch' : 'Zonas convergentes') }}</small><span class="dashboard-auth-progress"><span style="width: {{ $progress }}%"></span></span></div>
                @endforeach
            </div>
            <div class="dashboard-authoritative-secondary"><span><i><x-dashboard-icon name="server" /></i>Transferências<strong data-authoritative-counter="transferencias-falhando" data-authoritative-value="{{ $failedTransferCount }}">{{ $failedTransferCount }}</strong><small>Falhando</small></span><span><i><x-dashboard-icon name="clock" /></i>Zonas expiradas<strong data-authoritative-counter="zonas-expiradas" data-authoritative-value="{{ $expiredZoneCount }}">{{ $expiredZoneCount }}</strong><small>Requer atenção</small></span><span><i><x-dashboard-icon name="upload" /></i>Publicações pendentes<strong data-authoritative-counter="publicacoes-pendentes" data-authoritative-value="{{ $pendingPublicationCount }}">{{ $pendingPublicationCount }}</strong><small>Aguardando aplicação</small></span></div>
        </section>

            <article class="dashboard-panel dashboard-activity-panel">
                <div class="dashboard-panel-heading"><h2>Atividade recente</h2>@if ($canManageUsers)<a class="dashboard-panel-link" href="{{ route('audit.index') }}">Ver todas</a>@endif</div>
                @forelse ($recentActivity as $activity)
                    <div class="dashboard-activity-item"><span class="activity-icon {{ $activity['status'] === 'succeeded' ? 'activity-icon-green' : 'activity-icon-orange' }}" aria-hidden="true"><x-dashboard-icon :name="$activity['status'] === 'succeeded' ? $activity['icon'] : 'alert'" /></span><div><strong>{{ $activity['label'] }} · {{ $activity['status'] === 'succeeded' ? 'concluída' : ($activity['status'] === 'failed' ? 'falhou' : 'expirou') }}</strong><span class="dashboard-activity-meta"><span>{{ $activity['context'] }}</span><time datetime="{{ $activity['at']?->toIso8601String() }}">{{ $activity['at']?->diffForHumans() }}</time></span></div></div>
                @empty
                    <p class="dashboard-empty-line">Sem operações recentes registradas.</p>
                @endforelse
            </article>
            <article class="dashboard-panel dashboard-actions-panel">
                <div class="dashboard-panel-heading"><h2>Ações rápidas</h2></div>
                <div class="dashboard-quick-actions">
                    @if ($canManageUsers)
                        <a href="{{ route('servers.index') }}" class="dashboard-quick-action"><span class="quick-action-icon" aria-hidden="true"><x-dashboard-icon name="server" /></span><strong>Novo servidor</strong><small>Gerenciar servidores</small></a>
                        <a href="{{ route('zones.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-purple" aria-hidden="true"><x-dashboard-icon name="zone" /></span><strong>Nova zona</strong><small>Gerenciar zonas</small></a>
                        <a href="{{ route('users.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-red" aria-hidden="true"><x-dashboard-icon name="user" /></span><strong>Novo usuário</strong><small>Gerenciar acessos</small></a>
                        <a href="{{ route('audit.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-blue" aria-hidden="true"><x-dashboard-icon name="logs" /></span><strong>Ver logs</strong><small>Analisar eventos</small></a>
                    @else
                        <a href="{{ route('servers.index') }}" class="dashboard-quick-action"><span class="quick-action-icon" aria-hidden="true"><x-dashboard-icon name="server" /></span><strong>Ver servidores</strong></a>
                        <a href="{{ route('zones.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-purple" aria-hidden="true"><x-dashboard-icon name="zone" /></span><strong>Ver zonas</strong></a>
                    @endif
                </div>
            </article>
        </section>
        <footer class="dashboard-footer"><span>“DNS estável. Internet mais confiável.”</span><span>DNS Center v1.0 <i></i> {{ auth()->user()->currentOrganization?->name ?? config('app.name') }}</span></footer>
    </main>
</div>
@endsection
