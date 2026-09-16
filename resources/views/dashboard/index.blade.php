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
    $isAuthoritativeOnline = fn ($server) =>
        $server->authoritative_observed_at?->gte(now()->subMinutes(10))
        && (bool) data_get($server->authoritative_runtime, 'available', false);
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
            $pendingItems->push(['type' => 'Servidor '.($server->status === 'offline' ? 'offline' : 'em atenção'), 'object' => $server->name, 'detail' => $server->hostname, 'severity' => $server->status === 'offline' ? 'critical' : 'warning', 'url' => route('servers.index')]);
        }
        if (in_array($server->role, ['primary', 'secondary'], true) && ! $isAuthoritativeOnline($server) && in_array($server->status, ['online', 'warning'], true)) {
            $pendingItems->push(['type' => 'Saúde autoritativa não confirmada', 'object' => $server->name, 'detail' => 'Sem observação recente de serviço disponível', 'severity' => 'warning', 'url' => route('servers.index')]);
        }
        if (data_get($server->authoritative_runtime, 'recursion_enabled') === true) {
            $pendingItems->push(['type' => 'Recursão habilitada', 'object' => $server->name, 'detail' => 'Observada no serviço autoritativo', 'severity' => 'warning', 'url' => route('servers.index')]);
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
            $pendingItems->push(['type' => $type, 'object' => $zoneNames[$observation->dns_zone_id] ?? 'Zona #'.$observation->dns_zone_id, 'detail' => ($observation->status === 'serial_mismatch' ? 'Serial observado difere do esperado' : 'Estado reportado pelo agente').($dashboardServers->firstWhere('id', $observation->dns_server_id) ? ' · '.$dashboardServers->firstWhere('id', $observation->dns_server_id)->name : ''), 'severity' => in_array($observation->status, ['expired', 'transfer_failed', 'primary_unreachable'], true) ? 'critical' : 'warning', 'url' => route('zones.index')]);
        }
    }
    foreach ($dashboardPublications as $publication) {
        $pendingItems->push(['type' => 'Publicação pendente', 'object' => $dashboardServers->firstWhere('id', $publication->dns_server_id)?->name ?? 'Servidor DNS', 'detail' => 'Estado: '.match ($publication->status) { 'downloaded' => 'baixada', 'applying' => 'aplicando', default => 'aguardando' }, 'severity' => 'warning', 'url' => route('zones.index')]);
    }
    $activeAlertCount = $pendingItems->count();
    $healthState = $serverCount === 0 || ($onlineServerCount === 0 && $activeAlertCount === 0) ? 'neutral' : ($pendingItems->contains(fn ($item) => $item['severity'] === 'critical') ? 'critical' : ($activeAlertCount || $onlineServerCount < $serverCount ? 'warning' : 'healthy'));
    $healthLabel = match ($healthState) { 'healthy' => 'Ambiente operacional', 'warning' => 'Ambiente requer atenção', 'critical' => 'Ambiente crítico', default => $serverCount ? 'Estado operacional não confirmado' : 'Aguardando infraestrutura' };
    $serverIds = $dashboardServers->pluck('id');
    $recentOperations = $serverIds->isEmpty() ? collect() : App\Models\DnsBindOperation::query()->whereIn('dns_server_id', $serverIds)->whereIn('status', ['succeeded', 'failed', 'expired'])->latest('updated_at')->limit(4)->get()->map(fn ($operation) => ['label' => match ($operation->action) { 'discover_bind_zones' => 'Descoberta de zonas', 'install_bind' => 'Instalação do BIND', 'configure_bind' => 'Configuração do BIND', 'upgrade_agent' => 'Atualização do agente', default => 'Aplicação de zonas' }, 'context' => $dashboardServers->firstWhere('id', $operation->dns_server_id)?->name ?? 'Servidor DNS', 'status' => $operation->status, 'at' => $operation->completed_at ?? $operation->updated_at]);
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
                    Acompanhe a infraestrutura DNS autoritativa,
                    os serviços e as pendências operacionais.
                </p>
            </div>

            <div class="topbar-actions">
                <x-account-menu />
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
            <div class="dashboard-health-title"><span class="dashboard-health-dot" aria-hidden="true"></span><span class="dashboard-health-heading"><small>Saúde do ambiente</small><strong>{{ $healthLabel }}</strong></span></div>
            <p>{{ $onlineServerCount }}/{{ $serverCount }} servidores online <span aria-hidden="true">·</span> {{ $zoneCount }} zonas autoritativas <span aria-hidden="true">·</span> {{ $activeAlertCount ? $activeAlertCount.' pendências operacionais' : 'Nenhuma pendência operacional' }}</p>
        </section>

        <section class="dashboard-kpi-grid" aria-label="Indicadores operacionais">
            @foreach ([
                ['Servidores DNS', $serverCount, $onlineServerCount.' online de '.$serverCount, 'servers.index', 'server'],
                ['Zonas autoritativas', $zoneCount, $mismatchZoneCount.' divergentes', 'zones.index', 'zone'],
                ['Servidores online', $onlineServerCount, $serverCount ? round($onlineServerCount / $serverCount * 100).'% da infraestrutura' : 'Sem servidores', 'servers.index', 'online'],
                ['Pendências', $activeAlertCount, $hasAlerts ? 'Requerem atenção' : 'Ambiente sem alertas', null, 'alert'],
            ] as [$label, $value, $detail, $destination, $icon])
                <article class="dashboard-kpi-card dashboard-kpi-{{ $icon }}">
                    <span class="dashboard-kpi-icon kpi-icon-{{ $icon === 'alert' && ! $hasAlerts ? 'online' : $icon }}" aria-hidden="true">{{ ['server' => '▣', 'zone' => '◎', 'online' => '◉', 'alert' => '!'][$icon] }}</span>
                    <div><strong class="dashboard-kpi-value" @if ($icon === 'zone') data-dashboard-zone-count @endif>{{ $value }}</strong><span class="dashboard-kpi-label">{{ $label }}</span></div>
                    @if ($destination)<a class="dashboard-kpi-footer" href="{{ route($destination) }}">{{ $detail }} <span aria-hidden="true">›</span></a>
                    @else <span class="dashboard-kpi-footer">{{ $detail }}</span> @endif
                </article>
            @endforeach
        </section>

        <section class="dashboard-operations-grid" aria-label="Infraestrutura e pendências">
            <article class="dashboard-panel dashboard-server-panel">
                <div class="dashboard-panel-heading"><div><p class="eyebrow">Infraestrutura</p><h2>Estado dos servidores</h2></div><a class="dashboard-panel-link" href="{{ route('servers.index') }}">Ver todos</a></div>
                @if ($hasServers)
                    <div class="dashboard-role-strip" aria-label="Funções dos servidores"><span><strong>{{ $primaryServers->count() }}</strong> primários</span><span><strong>{{ $secondaryServers->count() }}</strong> secundários</span><span><strong>{{ $dashboardServers->where('role', 'standalone')->count() }}</strong> independentes</span></div>
                    <div class="dashboard-infrastructure-bar" role="img" aria-label="{{ $onlineServerCount }} de {{ $serverCount }} servidores online"><span class="health-online" style="width: {{ $serverCount ? $onlineServerCount / $serverCount * 100 : 0 }}%"></span><span class="health-warning" style="width: {{ $serverCount ? $warningServerCount / $serverCount * 100 : 0 }}%"></span><span class="health-offline" style="width: {{ $serverCount ? $offlineServerCount / $serverCount * 100 : 0 }}%"></span><span class="health-unknown" style="width: {{ $serverCount ? $unknownServerCount / $serverCount * 100 : 0 }}%"></span></div>
                    <p class="dashboard-server-summary">{{ $onlineServerCount }} online <span>·</span> {{ $warningServerCount }} em atenção <span>·</span> {{ $offlineServerCount }} offline <span>·</span> {{ $unknownServerCount }} aguardando ou desativados</p>
                    <div class="dashboard-server-list">
                        @foreach ($dashboardServers->take(6) as $server)
                            @php
                                $serverStatusLabel = match ($server->status) { 'online' => 'Online', 'warning' => 'Atenção', 'offline' => 'Offline', 'maintenance' => 'Desativado', default => 'Aguardando agente' };
                            @endphp
                            <a href="{{ route('servers.index') }}" class="dashboard-server-item">
                                <span class="dashboard-server-status dashboard-server-status-{{ $server->status }}" aria-hidden="true"></span>
                                <span class="dashboard-server-info"><strong>{{ $server->name }}</strong><small title="{{ $server->hostname }}">{{ $server->hostname }}@if ($server->last_seen_at) · visto {{ $server->last_seen_at->diffForHumans() }}@endif</small></span>
                                <span class="dashboard-server-role">{{ match ($server->role) { 'primary' => 'Primário', 'secondary' => 'Secundário', default => 'Independente' } }}</span>
                                <span class="dashboard-server-state dashboard-server-state-{{ $server->status }}">{{ $serverStatusLabel }}</span>
                            </a>
                        @endforeach
                    </div>
                    @if ($serverCount > 6)<a class="dashboard-panel-link dashboard-more" href="{{ route('servers.index') }}">Mais {{ $serverCount - 6 }} servidores</a>@endif
                @else
                    <p class="dashboard-empty-line">Nenhum servidor DNS cadastrado.</p>
                @endif
            </article>
            <article class="dashboard-panel dashboard-pending-panel">
                <div class="dashboard-panel-heading"><div><p class="eyebrow">Operação</p><h2>Pendências operacionais</h2></div><span class="dashboard-count-badge">{{ $activeAlertCount }}</span></div>
                @forelse ($pendingItems->take(4) as $item)
                    <a class="dashboard-pending-item" href="{{ $item['url'] }}"><span class="dashboard-issue-marker issue-{{ $item['severity'] }}" aria-hidden="true"></span><span class="dashboard-issue-copy"><strong>{{ $item['type'] }}</strong><span class="dashboard-issue-object">{{ $item['object'] }}</span><small>{{ $item['detail'] }}</small></span><span class="dashboard-issue-severity">{{ $item['severity'] === 'critical' ? 'Crítico' : 'Atenção' }}</span></a>
                @empty
                    <p class="dashboard-empty-line">✓ Nenhuma pendência operacional</p>
                @endforelse
                @if ($activeAlertCount > 4)<p class="dashboard-more">+ {{ $activeAlertCount - 4 }} pendências adicionais nos painéis de servidores e zonas.</p>@endif
            </article>
        </section>

        <section class="dashboard-panel dashboard-authoritative" aria-labelledby="authoritative-title">
            <div class="dashboard-panel-heading"><div><p class="eyebrow">Estado da autoridade</p><h2 id="authoritative-title">DNS autoritativo</h2></div><a class="dashboard-panel-link" href="{{ route('zones.index') }}">Ver zonas</a></div>
            @if ($mismatchObservationCount > $mismatchZoneCount)
                <p class="dashboard-observation-note">{{ $mismatchZoneCount }} {{ $mismatchZoneCount === 1 ? 'zona divergente' : 'zonas divergentes' }} · {{ $mismatchObservationCount }} observações afetadas</p>
            @endif
            <div class="dashboard-authoritative-grid">
                @foreach ([
                    ['Primários online', $primaryOnlineCount, $primaryServers->count(), 'primaries-online'],
                    ['Secundários online', $secondaryOnlineCount, $secondaryServers->count(), 'secondaries-online'],
                    ['Zonas sincronizadas', $synchronizedZoneCount, null, 'zonas-sincronizadas'],
                    ['Zonas divergentes', $mismatchZoneCount, null, 'zonas-divergentes'],
                ] as [$label, $value, $total, $key])
                    <div class="dashboard-authoritative-stat dashboard-authoritative-{{ $key }}{{ $key === 'zonas-divergentes' && $value > 0 ? ' has-alert' : '' }}"><span>{{ $label }}</span><strong data-authoritative-counter="{{ $key }}" data-authoritative-value="{{ $value }}">{{ $value }}@if ($total !== null)<small> / {{ $total }}</small>@endif</strong></div>
                @endforeach
            </div>
            <div class="dashboard-authoritative-secondary"><span>Transferências falhando <strong data-authoritative-counter="transferencias-falhando" data-authoritative-value="{{ $failedTransferCount }}">{{ $failedTransferCount }}</strong></span><span>Zonas expiradas <strong data-authoritative-counter="zonas-expiradas" data-authoritative-value="{{ $expiredZoneCount }}">{{ $expiredZoneCount }}</strong></span><span>Publicações pendentes <strong data-authoritative-counter="publicacoes-pendentes" data-authoritative-value="{{ $pendingPublicationCount }}">{{ $pendingPublicationCount }}</strong></span></div>
        </section>

        <section class="dashboard-bottom-grid">
            <article class="dashboard-panel dashboard-activity-panel">
                <div class="dashboard-panel-heading"><div><p class="eyebrow">Histórico de operações</p><h2>Atividades recentes</h2></div></div>
                @forelse ($recentActivity as $activity)
                    <div class="dashboard-activity-item"><span class="activity-icon {{ $activity['status'] === 'succeeded' ? 'activity-icon-green' : 'activity-icon-orange' }}" aria-hidden="true">{{ $activity['status'] === 'succeeded' ? '✓' : '!' }}</span><div><strong>{{ $activity['label'] }} · {{ $activity['status'] === 'succeeded' ? 'concluída' : ($activity['status'] === 'failed' ? 'falhou' : 'expirou') }}</strong><span class="dashboard-activity-meta"><span>{{ $activity['context'] }}</span><time datetime="{{ $activity['at']?->toIso8601String() }}">{{ $activity['at']?->diffForHumans() }}</time></span></div></div>
                @empty
                    <p class="dashboard-empty-line">Sem operações recentes registradas.</p>
                @endforelse
            </article>
            <article class="dashboard-panel dashboard-actions-panel">
                <div class="dashboard-panel-heading"><div><p class="eyebrow">Atalhos</p><h2>Ações rápidas</h2></div></div>
                <div class="dashboard-quick-actions">
                    @if ($canManageUsers)
                        <a href="{{ route('servers.index') }}" class="dashboard-quick-action"><span class="quick-action-icon" aria-hidden="true">▣</span><strong>Novo servidor</strong><small>Gerenciar servidores</small></a>
                        <a href="{{ route('zones.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-purple" aria-hidden="true">◎</span><strong>Nova zona</strong><small>Gerenciar zonas</small></a>
                        <a href="{{ route('users.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-red" aria-hidden="true">♙</span><strong>Novo usuário</strong><small>Gerenciar acessos</small></a>
                    @else
                        <a href="{{ route('servers.index') }}" class="dashboard-quick-action"><span class="quick-action-icon" aria-hidden="true">▣</span><strong>Ver servidores</strong></a>
                        <a href="{{ route('zones.index') }}" class="dashboard-quick-action"><span class="quick-action-icon quick-icon-purple" aria-hidden="true">◎</span><strong>Ver zonas</strong></a>
                    @endif
                </div>
            </article>
        </section>
    </main>
</div>
@endsection
