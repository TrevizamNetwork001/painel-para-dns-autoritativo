@extends('layouts.app')

@section('title', 'Servidores DNS')
@section('body-class', 'app-page')

@section('content')
@php
    $roleLabels = [
        'primary' => 'Primário',
        'secondary' => 'Secundário',
    ];

    $environmentLabels = [
        'production' => 'Produção',
        'staging' => 'Homologação',
        'development' => 'Desenvolvimento',
    ];

    $statusLabels = [
        'pending' => 'Aguardando agente',
        'online' => 'Online',
        'warning' => 'Atenção',
        'offline' => 'Offline',
        'maintenance' => 'Desativado',
    ];
@endphp

<div class="app-shell servers-v1">
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

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a
                href="{{ route('servers.index') }}"
                class="nav-item is-active"
            >
                <span class="nav-icon">▤</span>
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
    </aside>

    <main class="main-content">
        <header class="topbar servers-heading">
            <div>
                <p class="eyebrow">DNS autoritativo</p>
                <h1>Servidores DNS</h1>

                <p class="page-description">
                    Cadastre e acompanhe os servidores autoritativos
                    vinculados à empresa.
                </p>
            </div>

            <div class="topbar-actions">
                <button
                    type="button"
                    class="button button-primary"
                    data-server-modal-open
                >
                    Novo servidor
                </button>

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

        @if ($errors->any())
            <div
                class="alert alert-error"
                data-server-open-on-error
            >
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="servers-summary-grid">
            <article class="server-summary-card">
                <span>Total</span>
                <strong>{{ $servers->count() }}</strong>
                <small>Servidores cadastrados</small>
            </article>

            <article class="server-summary-card">
                <span>Online</span>
                <strong>
                    {{ $servers->where('status', 'online')->count() }}
                </strong>
                <small>Operacionais</small>
            </article>

            <article class="server-summary-card">
                <span>Atenção</span>
                <strong>
                    {{ $servers->where('status', 'warning')->count() }}
                </strong>
                <small>Requerem análise</small>
            </article>

            <article class="server-summary-card">
                <span>Aguardando</span>
                <strong>
                    {{ $servers->where('status', 'pending')->count() }}
                </strong>
                <small>Sem contato do agente</small>
            </article>
        </section>

        <section class="servers-list-panel">
            <div class="servers-list-heading">
                <div>
                    <p class="eyebrow">Infraestrutura</p>
                    <h2>Inventário de servidores</h2>
                </div>

                <span>{{ $servers->count() }} servidor(es)</span>
            </div>

            @forelse ($servers as $server)
                <article class="server-row">
                    <div class="server-row-main">
                        <div class="server-icon">▤</div>

                        <div>
                            <strong>{{ $server->name }}</strong>
                            <span>{{ $server->hostname }}</span>

                            <small>
                                {{ $server->ipv4_address ?: 'Sem IPv4' }}
                                ·
                                {{ $server->ipv6_address ?: 'Sem IPv6' }}
                            </small>

                            <small class="server-inventory-line">
                                @if (
                                    $server->operating_system
                                    || $server->bind_version
                                )
                                    {{ $server->operating_system
                                        ?: 'Sistema desconhecido' }}

                                    @if ($server->operating_system_version)
                                        {{ $server->operating_system_version }}
                                    @endif

                                    · BIND
                                    {{ $server->bind_version
                                        ?: 'desconhecido' }}
                                @else
                                    Inventário pendente do agente
                                @endif
                            </small>
                        </div>
                    </div>

                    <div class="server-row-meta">
                        <span>
                            {{ $roleLabels[$server->role]
                                ?? $server->role }}
                        </span>

                        <span>
                            {{ $environmentLabels[$server->environment]
                                ?? $server->environment }}
                        </span>

                        <span class="server-status server-status-{{ $server->status }}">
                            {{ $statusLabels[$server->status]
                                ?? $server->status }}
                        </span>
                    </div>

                    <div class="server-row-actions">
                        <a
                            href="{{ route('servers.agent.show', $server) }}"
                            class="button button-secondary button-small"
                        >
                            Agente
                        </a>

                        <button
                            type="button"
                            class="button button-secondary button-small"
                            data-server-edit
                            data-server-id="{{ $server->id }}"
                            data-server-name="{{ $server->name }}"
                            data-server-hostname="{{ $server->hostname }}"
                            data-server-ipv4="{{ $server->ipv4_address }}"
                            data-server-ipv6="{{ $server->ipv6_address }}"
                            data-server-role="{{ $server->role }}"
                            data-server-environment="{{ $server->environment }}"
                            data-server-notes="{{ $server->notes }}"
                        >
                            Editar
                        </button>

                        <form
                            method="POST"
                            action="{{ route('servers.status', $server) }}"
                        >
                            @csrf
                            @method('PATCH')

                            <button
                                type="submit"
                                class="button button-small {{ $server->enabled
                                    ? 'button-danger-soft'
                                    : 'button-success-soft' }}"
                            >
                                {{ $server->enabled
                                    ? 'Desativar'
                                    : 'Ativar' }}
                            </button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="servers-empty-state">
                    <div class="server-icon server-icon-large">▤</div>

                    <h2>Nenhum servidor DNS cadastrado</h2>

                    <p>
                        Cadastre o primeiro servidor autoritativo para
                        iniciar o inventário operacional.
                    </p>

                    <button
                        type="button"
                        class="button button-primary"
                        data-server-modal-open
                    >
                        Adicionar servidor
                    </button>
                </div>
            @endforelse
        </section>
    </main>
</div>

<div
    class="servers-modal"
    data-server-modal
    aria-hidden="true"
>
    <button
        type="button"
        class="servers-modal-backdrop"
        data-server-modal-close
        aria-label="Fechar"
    ></button>

    <section class="servers-modal-dialog">
        <header class="servers-modal-header">
            <div>
                <p class="eyebrow">Infraestrutura DNS</p>
                <h2 data-server-modal-title>Novo servidor</h2>

                <p>
                    Cadastre os dados de identificação e rede.
                    Nenhuma alteração será feita no BIND nesta etapa.
                </p>
            </div>

            <button
                type="button"
                class="users-modal-close"
                data-server-modal-close
            >
                ×
            </button>
        </header>

        <form
            method="POST"
            action="{{ route('servers.store') }}"
            class="servers-form"
            data-server-form
            data-store-action="{{ route('servers.store') }}"
            data-update-template="{{ url('/servidores/__ID__') }}"
        >
            @csrf

            <input
                type="hidden"
                name="_method"
                value="POST"
                data-server-method
            >

            <div class="servers-form-grid">
                <label class="form-field">
                    <span>Nome amigável</span>

                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        placeholder="Ex.: DNS-01"
                        required
                    >
                </label>

                <label class="form-field">
                    <span>Hostname</span>

                    <input
                        type="text"
                        name="hostname"
                        value="{{ old('hostname') }}"
                        placeholder="ns1.exemplo.com.br"
                        required
                    >
                </label>

                <label class="form-field">
                    <span>IPv4</span>

                    <input
                        type="text"
                        name="ipv4_address"
                        value="{{ old('ipv4_address') }}"
                        placeholder="192.0.2.53"
                    >
                </label>

                <label class="form-field">
                    <span>IPv6</span>

                    <input
                        type="text"
                        name="ipv6_address"
                        value="{{ old('ipv6_address') }}"
                        placeholder="2001:db8::53"
                    >
                </label>

                <label class="form-field">
                    <span>Papel do servidor</span>

                    <select name="role" required>
                        <option value="primary">
                            Primário
                        </option>

                        <option value="secondary">
                            Secundário
                        </option>
                    </select>
                </label>

                <label class="form-field">
                    <span>Ambiente</span>

                    <select name="environment" required>
                        <option value="production">Produção</option>
                        <option value="staging">Homologação</option>
                        <option value="development">Desenvolvimento</option>
                    </select>
                </label>

            </div>

            <label class="form-field">
                <span>Observações</span>

                <textarea
                    name="notes"
                    rows="4"
                    placeholder="Informações operacionais opcionais"
                >{{ old('notes') }}</textarea>
            </label>

            <footer class="servers-modal-actions">
                <button
                    type="button"
                    class="button button-secondary"
                    data-server-modal-close
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="button button-primary"
                    data-server-submit-label
                >
                    Criar servidor
                </button>
            </footer>
        </form>
    </section>
</div>
@endsection
