@extends('layouts.app')

@section('title', 'Zonas DNS')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="brand-mark brand-mark-small"><span>DNS</span></div>
            <div><strong>DNS Center</strong><span>Operations Platform</span></div>
        </div>
        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item">▦ Dashboard</a>
            <span class="nav-section">DNS autoritativo</span>
            <a href="{{ route('servers.index') }}" class="nav-item">▤ Servidores</a>
            <a href="{{ route('zones.index') }}" class="nav-item is-active">◎ Zonas</a>
            <a href="{{ route('users.index') }}" class="nav-item">● Usuários</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">DNS autoritativo</p>
                <h1>Zonas DNS</h1>
                <p class="page-description">Cadastre zonas e gere artefatos BIND versionados.</p>
            </div>
            <x-account-menu />
        </header>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="zone-layout">
            <article class="zone-panel">
                <h2>Zonas cadastradas</h2>
                @forelse ($zones as $zone)
                    <a href="{{ route('zones.show', $zone) }}" class="zone-row">
                        <div>
                            <strong>{{ $zone->name }}</strong>
                            <span>Serial {{ $zone->serial }} · v{{ $zone->version }}</span>
                        </div>
                        <span>{{ $zone->records_count }} registro(s) · {{ $zone->status }}</span>
                    </a>
                @empty
                    <div class="empty-state">Nenhuma zona cadastrada.</div>
                @endforelse
            </article>

            <article class="zone-panel">
                <h2>Nova zona</h2>
                <form method="POST" action="{{ route('zones.store') }}" class="zone-form">
                    @csrf
                    <input name="name" placeholder="exemplo.com.br" required>
                    <select name="kind" required>
                        <option value="primary">Primary</option>
                        <option value="secondary">Secondary</option>
                    </select>
                    <select name="primary_server_id" required>
                        <option value="">Servidor primary</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} — {{ $server->hostname }}</option>
                        @endforeach
                    </select>
                    <select name="secondary_server_id">
                        <option value="">Sem secondary</option>
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }} — {{ $server->hostname }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="default_ttl" value="3600" min="60" required>
                    <input name="soa_mname" placeholder="ns1.exemplo.com.br" required>
                    <input name="soa_rname" placeholder="hostmaster.exemplo.com.br" required>
                    <input type="number" name="soa_refresh" value="3600" min="60" required>
                    <input type="number" name="soa_retry" value="900" min="60" required>
                    <input type="number" name="soa_expire" value="1209600" min="3600" required>
                    <input type="number" name="soa_minimum" value="300" min="60" required>
                    <textarea name="notes" placeholder="Observações"></textarea>
                    <button class="button button-primary" type="submit">Criar zona</button>
                </form>
            </article>
        </section>
    </main>
</div>
@endsection
