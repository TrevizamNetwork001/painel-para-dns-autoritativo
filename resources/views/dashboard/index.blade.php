@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'app-page')

@section('content')
@php
    $organizationId = auth()->user()->current_organization_id;

    $dashboardServers = App\Models\DnsServer::query()
        ->forOrganization($organizationId)
        ->orderBy('name')
        ->get();
    $dashboardObservations = Illuminate\Support\Facades\Schema::hasTable(
        'dns_authoritative_observations'
    )
        ? App\Models\DnsAuthoritativeObservation::query()
            ->where('organization_id', $organizationId)
            ->get()
        : collect();

    $serverCount = $dashboardServers->count();
    $zoneCount = App\Models\DnsZone::query()
        ->forOrganization($organizationId)
        ->count();

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

    $onlineServiceCount = $onlineServerCount;

    $primaryServers = $dashboardServers->where('role', 'primary');
    $secondaryServers = $dashboardServers->where('role', 'secondary');
    $isAuthoritativeOnline = fn ($server) =>
        $server->authoritative_observed_at?->gte(now()->subMinutes(10))
        && (bool) data_get($server->authoritative_runtime, 'available', false);
    $primaryOnlineCount = $primaryServers->filter($isAuthoritativeOnline)->count();
    $primaryOfflineCount = $primaryServers->count() - $primaryOnlineCount;
    $secondaryOnlineCount = $secondaryServers->filter($isAuthoritativeOnline)->count();
    $secondaryOfflineCount = $secondaryServers->count() - $secondaryOnlineCount;
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
    $failedTransferCount = $dashboardObservations
        ->whereIn('status', ['transfer_failed', 'primary_unreachable'])
        ->count();
    $expiredZoneCount = $dashboardObservations
        ->where('status', 'expired')
        ->pluck('dns_zone_id')
        ->unique()
        ->count();
    $pendingPublicationCount = App\Models\DnsAgentPublication::query()
        ->where('organization_id', $organizationId)
        ->whereIn('status', ['pending', 'downloaded', 'applying'])
        ->count();
    $recursionAlertCount = $dashboardServers->filter(
        fn ($server) => data_get(
            $server->authoritative_runtime,
            'recursion_enabled'
        ) === true
    )->count();
    $activeAlertCount = $primaryOfflineCount
        + $secondaryOfflineCount
        + $mismatchZoneCount
        + $failedTransferCount
        + $expiredZoneCount
        + $recursionAlertCount;

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

        <section
            class="dashboard-kpi-grid"
            aria-label="Indicadores operacionais"
        >
            <article class="dashboard-kpi-card">
                <div class="dashboard-kpi-topline">
                    <span class="dashboard-kpi-icon kpi-icon-server">
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
                            <path
                                d="M8 6.5h.01M8 17.5h.01
                                   M12 6.5h5M12 17.5h5"
                            />
                        </svg>
                    </span>

                    <span class="dashboard-kpi-badge">Total</span>
                </div>

                <strong class="dashboard-kpi-value">
                    {{ $serverCount }}
                </strong>

                <span class="dashboard-kpi-label">
                    Servidores DNS
                </span>

                <a href="{{ route('servers.index') }}" class="dashboard-kpi-footer">
                    <span>
                        {{ $serverCount
                            ? $onlineServerCount.' online'
                            : 'Nenhum cadastrado' }}
                    </span>

                    <span aria-hidden="true">›</span>
                </a>
            </article>

            <article class="dashboard-kpi-card">
                <div class="dashboard-kpi-topline">
                    <span class="dashboard-kpi-icon kpi-icon-zone">
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

                    <span class="dashboard-kpi-badge">Total</span>
                </div>

                <strong
                    class="dashboard-kpi-value"
                    data-dashboard-zone-count
                >
                    {{ $zoneCount }}
                </strong>

                <span class="dashboard-kpi-label">
                    Zonas autoritativas
                </span>

                <a href="{{ route('zones.index') }}" class="dashboard-kpi-footer">
                    <span>
                        {{ $zoneCount
                            ? 'Zonas gerenciadas'
                            : 'Nenhuma configurada' }}
                    </span>

                    <span aria-hidden="true">›</span>
                </a>
            </article>

            <article class="dashboard-kpi-card">
                <div class="dashboard-kpi-topline">
                    <span class="dashboard-kpi-icon kpi-icon-online">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M8.5 15.5a5 5 0 0 1 0-7
                                   M15.5 8.5a5 5 0 0 1 0 7
                                   M5.5 18.5a9 9 0 0 1 0-13
                                   M18.5 5.5a9 9 0 0 1 0 13"
                            />
                            <circle cx="12" cy="12" r="1.8" />
                        </svg>
                    </span>

                    <span class="dashboard-kpi-badge">Online</span>
                </div>

                <strong class="dashboard-kpi-value">
                    {{ $onlineServiceCount }}
                </strong>

                <span class="dashboard-kpi-label">
                    Serviços online
                </span>

                <a href="{{ route('servers.index') }}" class="dashboard-kpi-footer">
                    <span>
                        {{ $onlineServiceCount
                            ? 'Serviços operacionais'
                            : 'Aguardando agentes' }}
                    </span>

                    <span aria-hidden="true">›</span>
                </a>
            </article>

            <article class="dashboard-kpi-card">
                <div class="dashboard-kpi-topline">
                    <span class="dashboard-kpi-icon kpi-icon-alert">
                        <svg viewBox="0 0 24 24" fill="none">
                            <path
                                d="M10.3 4.4 2.7 18a2 2 0 0 0 1.8 3h15
                                   a2 2 0 0 0 1.8-3L14.7 4.4
                                   a2.5 2.5 0 0 0-4.4 0Z"
                            />
                            <path d="M12 9v4M12 17h.01" />
                        </svg>
                    </span>

                    <span class="dashboard-kpi-badge">
                        {{ $hasAlerts ? 'Atenção' : 'Normal' }}
                    </span>
                </div>

                <strong class="dashboard-kpi-value">
                    {{ $activeAlertCount }}
                </strong>

                <span class="dashboard-kpi-label">
                    Alertas ativos
                </span>

                <a href="{{ route('servers.index') }}" class="dashboard-kpi-footer">
                    <span>
                        {{ $hasAlerts
                            ? 'Requer atenção'
                            : 'Nenhuma pendência' }}
                    </span>

                    <span aria-hidden="true">›</span>
                </a>
            </article>
        </section>

        <section class="dashboard-kpi-grid" aria-label="Topologia autoritativa">
            @foreach ([
                ['Primaries online', $primaryOnlineCount, $primaryOfflineCount.' offline'],
                ['Secondaries online', $secondaryOnlineCount, $secondaryOfflineCount.' offline'],
                ['Zonas sincronizadas', $synchronizedZoneCount, 'Seriais convergentes'],
                ['Zonas divergentes', $mismatchZoneCount, 'Serial mismatch'],
                ['Transferências falhando', $failedTransferCount, 'Requer diagnóstico'],
                ['Zonas expiradas', $expiredZoneCount, 'Sem autoridade válida'],
                ['Publicações pendentes', $pendingPublicationCount, 'Aguardando aplicação'],
            ] as [$label, $value, $detail])
                <article class="dashboard-kpi-card">
                    <div class="dashboard-kpi-topline">
                        <span class="dashboard-kpi-badge">Autoritativo</span>
                    </div>
                    <strong
                        class="dashboard-kpi-value"
                        data-authoritative-counter="{{
                            Illuminate\Support\Str::slug($label)
                        }}"
                        data-authoritative-value="{{ $value }}"
                    >{{ $value }}</strong>
                    <span class="dashboard-kpi-label">{{ $label }}</span>
                    <div class="dashboard-kpi-footer"><span>{{ $detail }}</span></div>
                </article>
            @endforeach
        </section>

        <section class="dashboard-operations-grid">
            <article class="dashboard-panel dashboard-server-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Infraestrutura</p>
                        <h2>Estado dos servidores</h2>
                    </div>

                    <a
                        href="{{ route('servers.index') }}"
                        class="dashboard-panel-link"
                    >
                        Ver todos
                    </a>
                </div>

                @if ($hasServers)
                    <div class="dashboard-server-list">
                        @foreach ($dashboardServers->take(5) as $server)
                            @php
                                $serverStatusLabel = match ($server->status) {
                                    'online' => 'Online',
                                    'warning' => 'Atenção',
                                    'offline' => 'Offline',
                                    'maintenance' => 'Desativado',
                                    default => 'Aguardando agente',
                                };
                            @endphp

                            <a
                                href="{{ route('servers.index') }}"
                                class="dashboard-server-item"
                            >
                                <span
                                    class="dashboard-server-status
                                        dashboard-server-status-{{ $server->status }}"
                                ></span>

                                <span class="dashboard-server-info">
                                    <strong>{{ $server->name }}</strong>
                                    <small>{{ $server->hostname }}</small>
                                </span>

                                <span class="dashboard-server-role">
                                    {{ $server->role === 'primary'
                                        ? 'Primário'
                                        : 'Secundário' }}
                                </span>

                                <span class="dashboard-server-state">
                                    {{ $serverStatusLabel }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @else
                    <div class="dashboard-empty-state">
                        <span class="dashboard-empty-icon">
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
                                <path
                                    d="M8 6.5h.01M8 17.5h.01
                                       M12 6.5h5M12 17.5h5"
                                />
                            </svg>
                        </span>

                        <h3>Nenhum servidor DNS cadastrado</h3>

                        <p>
                            Adicione o primeiro servidor para iniciar
                            o gerenciamento da infraestrutura.
                        </p>

                        <a
                            href="{{ route('servers.index') }}"
                            class="button button-primary"
                        >
                            Adicionar servidor
                        </a>
                    </div>
                @endif
            </article>

            <article class="dashboard-panel dashboard-activity-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Operações</p>
                        <h2>Atividades recentes</h2>
                    </div>

                </div>

                <div class="dashboard-activity-list">
                    <div class="dashboard-activity-item">
                        <span class="activity-icon activity-icon-purple">＋</span>

                        <div>
                            <strong>Nenhuma atividade registrada</strong>
                            <span>
                                As operações mais recentes aparecerão aqui.
                            </span>
                        </div>
                    </div>

                    <div class="dashboard-activity-item">
                        <span class="activity-icon activity-icon-green">
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
                            </svg>
                        </span>

                        <div>
                            <strong>Nenhum servidor cadastrado</strong>
                            <span>
                                Cadastre servidores para acompanhar eventos.
                            </span>
                        </div>
                    </div>

                    <div class="dashboard-activity-item">
                        <span class="activity-icon activity-icon-blue">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="8" />
                                <path d="M4 12h16M12 4c2 2.2 3 4.8 3 8s-1 5.8-3 8" />
                            </svg>
                        </span>

                        <div>
                            <strong>Nenhuma zona configurada</strong>
                            <span>
                                O histórico de zonas será exibido aqui.
                            </span>
                        </div>
                    </div>

                    <div class="dashboard-activity-item">
                        <span class="activity-icon activity-icon-orange">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="8" r="3" />
                                <path d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                            </svg>
                        </span>

                        <div>
                            <strong>Gestão de usuários disponível</strong>
                            <span>
                                Convide usuários para acessar a plataforma.
                            </span>
                        </div>
                    </div>
                </div>
            </article>

            <article class="dashboard-panel dashboard-pending-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Operação</p>
                        <h2>Pendências operacionais</h2>
                    </div>

                    <span class="dashboard-count-badge">
                        {{ $activeAlertCount }}
                    </span>
                </div>

                @if ($hasAlerts)
                    <div class="dashboard-pending-list">
                        {{-- As pendências reais serão conectadas aos alertas. --}}
                    </div>
                @else
                    <div class="dashboard-empty-state dashboard-empty-compact">
                        <span class="dashboard-empty-icon empty-icon-success">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="m5 12 4 4L19 6" />
                            </svg>
                        </span>

                        <h3>Nenhuma pendência no momento</h3>

                        <p>
                            Tudo certo. Não existem alertas operacionais.
                        </p>
                    </div>
                @endif
            </article>
        </section>

        <section class="dashboard-bottom-grid">
            <article class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Resumo</p>
                        <h2>Infraestrutura DNS</h2>
                    </div>

                    <span class="dashboard-count-badge">
                        {{ $serverCount }} servidores
                    </span>
                </div>

                <div class="dashboard-summary-content">
                    <div
                        @class([
                            'dashboard-donut',
                            'has-operational-data' => $serverCount > 0,
                        ])
                    >
                        <div>
                            <strong>{{ $serverCount }}</strong>
                            <span>Total</span>
                        </div>
                    </div>

                    <dl class="dashboard-summary-list">
                        <div>
                            <dt>
                                <span class="summary-dot summary-online"></span>
                                Online
                            </dt>
                            <dd>{{ $onlineServerCount }}</dd>
                        </div>

                        <div>
                            <dt>
                                <span class="summary-dot summary-warning"></span>
                                Advertência
                            </dt>
                            <dd>{{ $warningServerCount }}</dd>
                        </div>

                        <div>
                            <dt>
                                <span class="summary-dot summary-offline"></span>
                                Offline
                            </dt>
                            <dd>{{ $offlineServerCount }}</dd>
                        </div>

                        <div>
                            <dt>
                                <span class="summary-dot summary-unknown"></span>
                                Desconhecido
                            </dt>
                            <dd>{{ $unknownServerCount }}</dd>
                        </div>
                    </dl>
                </div>
            </article>

            <article class="dashboard-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Atalhos</p>
                        <h2>Ações rápidas</h2>
                    </div>
                </div>

                <div class="dashboard-quick-actions">
                    <a href="{{ route('servers.index') }}" class="dashboard-quick-action">
                        <span class="quick-action-icon">
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
                            </svg>
                        </span>

                        <strong>Novo servidor</strong>
                        <small>Adicionar servidor DNS</small>
                    </a>

                    <a href="{{ route('zones.index') }}" class="dashboard-quick-action">
                        <span class="quick-action-icon quick-icon-purple">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21C9.6 18.5 8.4 15.5 8.4 12S9.6 5.5 12 3Z" />
                            </svg>
                        </span>

                        <strong>Nova zona</strong>
                        <small>Criar zona autoritativa</small>
                    </a>

                    @if ($canManageUsers)
                        <a
                            href="{{ route('users.index') }}"
                            class="dashboard-quick-action"
                        >
                            <span class="quick-action-icon quick-icon-red">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="8" r="3" />
                                    <path d="M5.5 20a6.5 6.5 0 0 1 13 0" />
                                </svg>
                            </span>

                            <strong>Novo usuário</strong>
                            <small>Gerenciar acessos</small>
                        </a>
                    @endif

                </div>
            </article>
        </section>
    </main>
</div>
@endsection
