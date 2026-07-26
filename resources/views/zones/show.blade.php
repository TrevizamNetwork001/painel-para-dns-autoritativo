@extends('layouts.app')

@section('title', $zone->name)
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
            <a href="{{ route('servers.index') }}" class="nav-item">▤ Servidores</a>
            <a href="{{ route('zones.index') }}" class="nav-item is-active">◎ Zonas</a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Zona autoritativa</p>
                <h1>{{ $zone->name }}</h1>
                <p class="page-description">Serial {{ $zone->serial }} · v{{ $zone->version }} · {{ $zone->status }}</p>
            </div>
            <div class="topbar-actions">
                <a href="{{ route('zones.index') }}" class="button button-secondary">Voltar</a>
                <x-account-menu />
            </div>
        </header>

        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="zone-detail-grid">
            <article class="zone-panel">
                <h2>Registros DNS</h2>
                <form method="POST" action="{{ route('zones.records.store', $zone) }}" class="record-form">
                    @csrf
                    <input name="name" placeholder="@ ou www" required>
                    <select name="type">
                        @foreach (\App\Models\DnsRecord::TYPES as $type)
                            <option value="{{ $type }}">{{ $type }}</option>
                        @endforeach
                    </select>
                    <input type="number" name="ttl" placeholder="TTL" min="60">
                    <input type="number" name="priority" placeholder="Prioridade MX" min="0">
                    <input name="content" placeholder="Valor" required>
                    <button class="button button-primary" type="submit">Adicionar</button>
                </form>

                @forelse ($zone->records as $record)
                    <div class="record-row">
                        <strong>{{ $record->name }}</strong>
                        <span>{{ $record->type }}</span>
                        <span>{{ $record->ttl ?: $zone->default_ttl }}</span>
                        <code>{{ $record->priority }} {{ $record->content }}</code>
                        <form method="POST" action="{{ route('zones.records.destroy', [$zone, $record]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="button button-secondary button-small" type="submit">Remover</button>
                        </form>
                    </div>
                @empty
                    <div class="empty-state">Nenhum registro cadastrado.</div>
                @endforelse
            </article>

            <article class="zone-panel">
                <h2>Preview BIND</h2>
                <pre class="zone-preview">{{ $preview }}</pre>
                <form method="POST" action="{{ route('zones.publish', $zone) }}">
                    @csrf
                    <button class="button button-primary" type="submit">Publicar artefato</button>
                </form>
            </article>
        </section>
    </main>
</div>
@endsection
