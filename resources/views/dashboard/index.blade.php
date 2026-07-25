@extends('layouts.app')

@section('title', 'Dashboard')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell">
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
            <a href="{{ route('dashboard') }}" class="nav-item is-active">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="#" class="nav-item">
                <span class="nav-icon">◈</span>
                Servidores
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon">◎</span>
                Zonas
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon">≋</span>
                Registros DNS
            </a>

            <span class="nav-section">Operações</span>

            <a href="#" class="nav-item">
                <span class="nav-icon">⌁</span>
                Atividades
            </a>

            <a href="{{ route('users.index') }}" class="nav-item">
                <span class="nav-icon">●</span>
                Usuários
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon">⚙</span>
                Configurações
            </a>
        </nav>

        <div class="sidebar-user">
            <div class="user-avatar">
                {{ str(auth()->user()->name)->substr(0, 1)->upper() }}
            </div>

            <div class="sidebar-user-info">
                <strong>{{ auth()->user()->name }}</strong>
                <span>{{ auth()->user()->email }}</span>
            </div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Visão operacional</p>
                <h1>Dashboard</h1>
            </div>

            <div class="topbar-actions">
                <div class="organization-context">
                    <span>Empresa atual</span>
                    <strong>
                        {{ auth()->user()->currentOrganization?->name
                            ?? 'Administração da plataforma' }}
                    </strong>
                </div>

                <button
                    type="button"
                    class="theme-toggle"
                    data-theme-toggle
                    aria-label="Alternar tema"
                    title="Alternar tema"
                >
                    <span data-theme-icon>◐</span>
                </button>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <button type="submit" class="button button-secondary">
                        Sair
                    </button>
                </form>
            </div>
        </header>

        <section class="metrics-grid">
            <article class="metric-card">
                <div class="metric-card-header">
                    <span>Servidores DNS</span>
                    <span class="metric-icon">◈</span>
                </div>

                <strong>0</strong>
                <small>Nenhum servidor cadastrado</small>
            </article>

            <article class="metric-card">
                <div class="metric-card-header">
                    <span>Zonas autoritativas</span>
                    <span class="metric-icon">◎</span>
                </div>

                <strong>0</strong>
                <small>Nenhuma zona configurada</small>
            </article>

            <article class="metric-card">
                <div class="metric-card-header">
                    <span>Serviços online</span>
                    <span class="metric-icon metric-icon-success">●</span>
                </div>

                <strong>0</strong>
                <small>Aguardando agentes</small>
            </article>

            <article class="metric-card">
                <div class="metric-card-header">
                    <span>Alertas ativos</span>
                    <span class="metric-icon">!</span>
                </div>

                <strong>0</strong>
                <small>Nenhum alerta operacional</small>
            </article>
        </section>

        <section class="dashboard-grid">
            <article class="panel panel-wide">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Infraestrutura</p>
                        <h2>Estado dos servidores</h2>
                    </div>

                    <span class="badge badge-neutral">Sem dados</span>
                </div>

                <div class="empty-state">
                    <div class="empty-state-icon">◈</div>

                    <h3>Nenhum servidor DNS cadastrado</h3>

                    <p>
                        O inventário operacional aparecerá aqui quando os
                        primeiros servidores autoritativos forem adicionados.
                    </p>

                    <button class="button button-primary" type="button" disabled>
                        Adicionar servidor
                    </button>
                </div>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Conta</p>
                        <h2>Sessão atual</h2>
                    </div>

                    <span class="badge badge-success">Ativa</span>
                </div>

                <dl class="detail-list">
                    <div>
                        <dt>Usuário</dt>
                        <dd>{{ auth()->user()->name }}</dd>
                    </div>

                    <div>
                        <dt>Empresa</dt>
                        <dd>
                            {{ auth()->user()->currentOrganization?->name
                                ?? 'Plataforma' }}
                        </dd>
                    </div>

                    <div>
                        <dt>Papel</dt>
                        <dd>
                            {{ auth()->user()->roleForOrganization(
                                auth()->user()->current_organization_id
                            ) ?? 'platform_admin' }}
                        </dd>
                    </div>

                    <div>
                        <dt>Status</dt>
                        <dd>{{ auth()->user()->status }}</dd>
                    </div>
                </dl>
            </article>
        </section>
    </main>
</div>
@endsection
