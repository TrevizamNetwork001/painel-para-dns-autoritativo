@extends('layouts.app')

@section('title', 'Transferir servidor DNS')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell servers-v1">
    <x-app-sidebar active="servers" />

    <main class="main-content">
        <header class="topbar servers-heading">
            <div>
                <p class="eyebrow">Administração da plataforma</p>
                <h1>Transferir servidor</h1>
                <p class="page-description">
                    Transfira o cadastro de {{ $server->hostname }} para outra empresa.
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

        <section class="servers-list-panel">
            <div class="servers-list-heading">
                <div>
                    <p class="eyebrow">Transferência conservadora</p>
                    <h2>{{ $server->name }}</h2>
                </div>
                <span>{{ $server->hostname }}</span>
            </div>

            <div class="alert alert-warning">
                O cadastro atual será desativado. Zonas, publicações, histórico e
                identidades de nameserver permanecerão na empresa de origem. A
                credencial atual será revogada e o agente precisará de um novo vínculo.
                Esta operação não altera o BIND do servidor.
            </div>

            <form method="POST" action="{{ route('servers.transfer.store', $server) }}" class="servers-form">
                @csrf

                <label class="form-field">
                    <span>Empresa de destino</span>
                    <select name="organization_id" required>
                        <option value="">Selecione a empresa</option>
                        @foreach ($organizations as $organization)
                            <option value="{{ $organization->id }}" @selected((string) old('organization_id') === (string) $organization->id)>
                                {{ $organization->name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="form-field">
                    <span>Confirmação explícita</span>
                    <input
                        type="text"
                        name="confirmation"
                        value="{{ old('confirmation') }}"
                        autocomplete="off"
                        placeholder="TRANSFERIR {{ $server->hostname }}"
                        required
                    >
                    <small>Digite exatamente: TRANSFERIR {{ $server->hostname }}</small>
                </label>

                <footer class="servers-modal-actions">
                    <a href="{{ route('servers.index') }}" class="button button-secondary">Cancelar</a>
                    <button type="submit" class="button button-danger-soft">Transferir servidor</button>
                </footer>
            </form>
        </section>
    </main>
</div>
@endsection
