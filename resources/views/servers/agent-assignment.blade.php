@extends('layouts.app')

@section('title', 'Associar solicitação de agente')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell servers-v1">
    <x-app-sidebar active="servers" />

    <main class="main-content">
        <header class="topbar servers-heading">
            <div>
                <p class="eyebrow">Modo legado / associação manual</p>
                <h1>Associar solicitação ao servidor</h1>
                <p class="page-description">
                    A associação não aprova o agente nem emite credencial.
                </p>
            </div>
            <x-account-menu />
        </header>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="panel-card">
            <p class="eyebrow">Associação necessária</p>
            <h2>Solicitação não associada</h2>

            <div class="agent-security-list">
                <span>Request: {{ substr($installRequest->request_id, 0, 8) }}…</span>
                <span>Hostname recebido: {{ $installRequest->reported_hostname }}</span>
                <span>IP observado: {{ $installRequest->registered_ip ?? 'não informado' }}</span>
                <span>Agente: {{ $installRequest->agent_version ?? 'não informado' }}</span>
                <span>Sistema: {{ $installRequest->operating_system ?? 'não informado' }} {{ $installRequest->operating_system_version }}</span>
                <span>Fingerprint: {{ substr($installRequest->fingerprint, 0, 12) }}…</span>
                <span>Criado: {{ $installRequest->created_at->format('d/m/Y H:i:s') }}</span>
                <span>Expira: {{ $installRequest->expires_at->format('d/m/Y H:i:s') }}</span>
                <span>Status: pending — associação necessária</span>
            </div>
        </section>

        @foreach ($candidates as $server)
            @php($confirmation = 'ASSOCIAR '.substr($installRequest->request_id, 0, 8).' AO SERVIDOR '.Str::upper($server->hostname))
            <section class="panel-card">
                <p class="eyebrow">Candidato permitido</p>
                <h2>{{ $server->hostname }}</h2>

                <div class="agent-security-list">
                    <span>Servidor: {{ $server->name }}</span>
                    <span>IP cadastrado: {{ $server->ipv4_address ?? 'sem IPv4' }}</span>
                    <span>IPv6 cadastrado: {{ $server->ipv6_address ?? 'sem IPv6' }}</span>
                    <span>IP observado: {{ $installRequest->registered_ip ?? 'não informado' }}</span>
                    @if (
                        $installRequest->registered_ip
                        && ! in_array($installRequest->registered_ip, [
                            $server->ipv4_address,
                            $server->ipv6_address,
                        ], true)
                    )
                        <span class="status-badge status-warning">
                            O IP observado diverge dos endereços cadastrados.
                        </span>
                    @endif
                </div>

                <form
                    method="POST"
                    action="{{ route(
                        'servers.agent.install-requests.assignment.store',
                        $installRequest,
                    ) }}"
                >
                    @csrf
                    <input type="hidden" name="dns_server_id" value="{{ $server->id }}">
                    <label>
                        Digite <code>{{ $confirmation }}</code>
                        <input name="confirmation" required autocomplete="off">
                    </label>
                    <button class="button button-primary" type="submit">
                        Associar ao servidor
                    </button>
                </form>
            </section>
        @endforeach
    </main>
</div>
@endsection
