@extends('layouts.app')

@section('title', 'Servidores DNS')
@section('body-class', 'app-page')

@section('content')
@php
    $roleLabels = [
        'standalone' => 'Independente',
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
    <x-app-sidebar active="servers" />

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

        @if ($unassignedInstallRequests->isNotEmpty())
            <section class="servers-list-panel">
                <div class="servers-list-heading">
                    <div>
                        <p class="eyebrow">Intervenção administrativa</p>
                        <h2>Solicitações de agente não associadas</h2>
                    </div>

                    <span class="status-badge status-warning">
                        {{ $unassignedInstallRequests->count() }} pendente(s)
                    </span>
                </div>

                @foreach ($unassignedInstallRequests as $item)
                    @php
                        $installRequest = $item['request'];
                    @endphp
                    <article class="server-row">
                        <div class="server-row-main">
                            <div>
                                <strong>Associação necessária</strong>
                                <span>
                                    Request {{ substr($installRequest->request_id, 0, 8) }}…
                                </span>
                                <small>
                                    Hostname {{ $installRequest->reported_hostname }} ·
                                    IP observado {{ $installRequest->registered_ip ?? 'não informado' }} ·
                                    {{ $item['candidates']->count() }} candidato(s)
                                </small>
                            </div>
                        </div>

                        <a
                            class="button button-secondary"
                            href="{{ route(
                                'servers.agent.install-requests.assignment.show',
                                $installRequest,
                            ) }}"
                        >
                            Revisar associação
                        </a>
                    </article>
                @endforeach
            </section>
        @endif

        <section class="servers-list-panel">
            <div class="servers-list-heading">
                <div>
                    <p class="eyebrow">Infraestrutura</p>
                    <h2>Inventário de servidores</h2>
                </div>

                <span>{{ $servers->count() }} servidor(es)</span>
            </div>

            @forelse ($servers as $server)
                @php
                    $latestPublication = $server->agentPublications->first();
                    $latestAppliedPublication = $server
                        ->agentPublications
                        ->firstWhere('status', 'applied');
                    $runtime = $server->authoritative_runtime ?? [];
                    $observations = $server->authoritativeObservations;
                    $serverAlerts = collect();
                    if (($runtime['available'] ?? null) === false) {
                        $serverAlerts->push(
                            $server->role === 'primary'
                                ? 'Primary offline'
                                : 'Servidor autoritativo offline'
                        );
                    }
                    if (
                        $server->authoritative_observed_at
                        && $server->authoritative_observed_at->lt(now()->subMinutes(10))
                    ) {
                        $serverAlerts->push('Telemetria autoritativa desatualizada');
                    }
                    if (($runtime['recursion_enabled'] ?? null) === true) {
                        $serverAlerts->push('Recursão indevidamente habilitada');
                    }
                    if ($observations->where('status', 'serial_mismatch')->isNotEmpty()) {
                        $serverAlerts->push('Zona com serial divergente');
                    }
                    if ($observations->where('status', 'transfer_failed')->isNotEmpty()) {
                        $serverAlerts->push('Transferência falhando');
                    }
                    if ($observations->where('status', 'primary_unreachable')->isNotEmpty()) {
                        $serverAlerts->push('Secondary sem atualizar: primary indisponível');
                    }
                    if ($observations->where('status', 'expired')->isNotEmpty()) {
                        $serverAlerts->push('Zona expirada');
                    }
                    if ($observations->contains(
                        fn ($item) => $item->expires_at
                            && $item->expires_at->isFuture()
                            && $item->expires_at->lte(now()->addHours(24))
                    )) {
                        $serverAlerts->push('Zona próxima da expiração');
                    }
                @endphp
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

                            <small class="server-inventory-line">
                                BIND {{ ($runtime['service_active'] ?? false) ? 'ativo' : 'inativo' }}
                                · TCP/UDP 53 {{ ($runtime['tcp_53'] ?? false) && ($runtime['udp_53'] ?? false) ? 'ativos' : 'incompletos' }}
                                · recursão {{ ($runtime['recursion_enabled'] ?? null) === false ? 'desabilitada' : (($runtime['recursion_enabled'] ?? null) === true ? 'HABILITADA' : 'não observada') }}
                            </small>

                            <small class="server-inventory-line">
                                Sincronizadas {{ $observations->where('status', 'synchronized')->count() }}
                                · mismatch {{ $observations->where('status', 'serial_mismatch')->count() }}
                                · falhas {{ $observations->whereIn('status', ['transfer_failed', 'primary_unreachable'])->count() }}
                                · expiradas {{ $observations->where('status', 'expired')->count() }}
                                · coleta {{ $server->authoritative_observed_at?->diffForHumans() ?? 'pendente' }}
                                · agente {{ $server->last_seen_at?->diffForHumans() ?? 'sem comunicação' }}
                            </small>

                            @if ($serverAlerts->isNotEmpty())
                                <small class="server-inventory-line is-danger">
                                    {{ $serverAlerts->implode(' · ') }}
                                </small>
                            @endif

                            <small class="server-inventory-line">
                                @if ($latestPublication)
                                    Versão desejada:
                                    {{ $latestPublication->zoneVersion->version }}
                                    · instalada confirmada:
                                    {{ $latestAppliedPublication
                                        ?->installed_version
                                        ?? 'não confirmada' }}
                                    · aplicação:
                                    {{ $latestPublication->status }}
                                @else
                                    Nenhuma publicação destinada
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
