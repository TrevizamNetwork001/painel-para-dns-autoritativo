@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'app-page')

@section('content')
@php
    /*
     * Estes valores continuam em zero enquanto os módulos operacionais
     * ainda não foram implementados. Quando os módulos forem criados,
     * o controller poderá fornecer estes mesmos nomes.
     */
    $serverCount = $serverCount ?? 0;
    $zoneCount = $zoneCount ?? 0;
    $onlineServiceCount = $onlineServiceCount ?? 0;
    $activeAlertCount = $activeAlertCount ?? 0;

    $onlineServerCount = $onlineServerCount ?? 0;
    $warningServerCount = $warningServerCount ?? 0;
    $offlineServerCount = $offlineServerCount ?? 0;
    $unknownServerCount = $unknownServerCount ?? $serverCount;

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

            <a href="#" class="nav-item">
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

            <a href="#" class="nav-item">
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

                Zonas
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 7h4l2-3 4 6 2-3h4
                               M4 17h4l2 3 4-6 2 3h4"
                        />
                    </svg>
                </span>

                Registros DNS
            </a>

            <span class="nav-section">Operações</span>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 12h3l2-6 4 12 2-6h5"
                        />
                    </svg>
                </span>

                Atividades
            </a>

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

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="3" />
                        <path
                            d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1
                               -2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3
                               1.7 1.7 0 0 0-1 1.6V21h-4v-.1
                               a1.7 1.7 0 0 0-1-1.6
                               1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17
                               l.1-.1a1.7 1.7 0 0 0 .3-1.9
                               A1.7 1.7 0 0 0 3 14H3v-4h.1
                               a1.7 1.7 0 0 0 1.6-1
                               1.7 1.7 0 0 0-.3-1.9L4.3 7
                               7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6
                               1.7 1.7 0 0 0 10 3V3h4v.1
                               a1.7 1.7 0 0 0 1 1.6
                               1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7
                               l-.1.1a1.7 1.7 0 0 0-.3 1.9
                               1.7 1.7 0 0 0 1.6 1H21v4h-.1
                               a1.7 1.7 0 0 0-1.5 1Z"
                        />
                    </svg>
                </span>

                Configurações
            </a>
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

                <a href="#" class="dashboard-kpi-footer">
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

                <strong class="dashboard-kpi-value">
                    {{ $zoneCount }}
                </strong>

                <span class="dashboard-kpi-label">
                    Zonas autoritativas
                </span>

                <a href="#" class="dashboard-kpi-footer">
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

                <a href="#" class="dashboard-kpi-footer">
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

                <a href="#" class="dashboard-kpi-footer">
                    <span>
                        {{ $hasAlerts
                            ? 'Requer atenção'
                            : 'Nenhuma pendência' }}
                    </span>

                    <span aria-hidden="true">›</span>
                </a>
            </article>
        </section>

        <section class="dashboard-operations-grid">
            <article class="dashboard-panel dashboard-server-panel">
                <div class="dashboard-panel-heading">
                    <div>
                        <p class="eyebrow">Infraestrutura</p>
                        <h2>Estado dos servidores</h2>
                    </div>

                    <a href="#" class="dashboard-panel-link">
                        Ver todos
                    </a>
                </div>

                @if ($hasServers)
                    <div class="dashboard-server-list">
                        {{-- A lista real será conectada ao módulo de servidores. --}}
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

                        <a href="#" class="button button-primary">
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

                    <a href="#" class="dashboard-panel-link">
                        Ver todas
                    </a>
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
                    <a href="#" class="dashboard-quick-action">
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

                    <a href="#" class="dashboard-quick-action">
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

                    <a href="#" class="dashboard-quick-action">
                        <span class="quick-action-icon quick-icon-blue">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M8 6h12M8 12h12M8 18h12" />
                                <circle cx="4" cy="6" r="1" />
                                <circle cx="4" cy="12" r="1" />
                                <circle cx="4" cy="18" r="1" />
                            </svg>
                        </span>

                        <strong>Ver atividades</strong>
                        <small>Histórico operacional</small>
                    </a>

                    <a href="#" class="dashboard-quick-action">
                        <span class="quick-action-icon quick-icon-neutral">
                            <svg viewBox="0 0 24 24" fill="none">
                                <circle cx="12" cy="12" r="3" />
                                <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3A1.7 1.7 0 0 0 14 21h-4a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14v-4a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3h4a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10v4a1.7 1.7 0 0 0-1.6 1Z" />
                            </svg>
                        </span>

                        <strong>Configurações</strong>
                        <small>Ajustes da plataforma</small>
                    </a>
                </div>
            </article>
        </section>
    </main>
</div>
@endsection
