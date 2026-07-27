@extends('layouts.app')

@section('title', $zone->name)
@section('body-class', 'app-page')

@php
    $user = auth()->user();
    $organizationId = (int) $user->current_organization_id;

    $canManageZone = $user->is_platform_admin
        || $user->roleForOrganization($organizationId) === 'organization_admin';

    $primaryServer = $zone->servers->first(
        fn ($server) => $server->pivot?->role === 'primary'
    );

    $secondaryServer = $zone->servers->first(
        fn ($server) => $server->pivot?->role === 'secondary'
    );

    $validationOk = (bool) data_get($validation, 'ok', false);
    $validationErrors = data_get($validation, 'errors', []);
    $validationWarnings = data_get($validation, 'warnings', []);

    $validationErrors = is_array($validationErrors)
        ? $validationErrors
        : [];

    $validationWarnings = is_array($validationWarnings)
        ? $validationWarnings
        : [];

    $statusLabels = [
        'draft' => 'Rascunho',
        'ready' => 'Pronta',
        'published' => 'Publicada',
        'disabled' => 'Desativada',
    ];

    $kindLabels = [
        'primary' => 'Primary',
        'secondary' => 'Secondary',
    ];
@endphp

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

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item">
                ▦ Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="{{ route('servers.index') }}" class="nav-item">
                ▤ Servidores
            </a>

            <a href="{{ route('zones.index') }}" class="nav-item is-active">
                ◎ Zonas
            </a>

            @if (Route::has('users.index'))
                <a href="{{ route('users.index') }}" class="nav-item">
                    ● Usuários
                </a>
            @endif
        </nav>
    </aside>

    <main class="main-content zone-management-page">
        <header class="topbar zone-management-topbar">
            <div>
                <p class="eyebrow">Zona autoritativa</p>

                <div class="zone-title-row">
                    <h1>{{ $zone->name }}</h1>

                    <span class="zone-status-badge zone-status-{{ $zone->status }}">
                        {{ $statusLabels[$zone->status] ?? $zone->status }}
                    </span>
                </div>

                <p class="page-description">
                    Serial {{ $zone->serial }}
                    · versão {{ $zone->version }}
                    · {{ $kindLabels[$zone->kind] ?? $zone->kind }}
                    · {{ $zone->records->count() }} registro(s)
                </p>
            </div>

            <div class="topbar-actions">
                <a
                    href="{{ route('zones.index') }}"
                    class="button button-secondary"
                >
                    Voltar
                </a>

                <x-account-menu />
            </div>
        </header>

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                <strong>Não foi possível concluir a operação.</strong>

                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="zone-summary-grid">
            <article class="zone-summary-card">
                <span>Estado</span>

                <strong>
                    {{ $statusLabels[$zone->status] ?? $zone->status }}
                </strong>

                <small>
                    @if ($zone->status === 'draft')
                        Ainda existem alterações em preparação.
                    @elseif ($zone->status === 'ready')
                        Validada e pronta para publicação futura.
                    @elseif ($zone->status === 'published')
                        Existe uma versão publicada.
                    @else
                        Zona fora da operação normal.
                    @endif
                </small>
            </article>

            <article class="zone-summary-card">
                <span>Validação</span>

                <strong class="{{ $validationOk ? 'text-success' : 'text-danger' }}">
                    {{ $validationOk ? 'Aprovada' : 'Com pendências' }}
                </strong>

                <small>
                    {{ count($validationErrors) }} erro(s)
                    · {{ count($validationWarnings) }} alerta(s)
                </small>
            </article>

            <article class="zone-summary-card">
                <span>Primary</span>

                <strong>
                    {{ $primaryServer?->name ?? 'Não definido' }}
                </strong>

                <small>
                    {{ $primaryServer?->hostname ?? 'Vincule um servidor primary.' }}
                </small>
            </article>

            <article class="zone-summary-card">
                <span>Secondary</span>

                <strong>
                    {{ $secondaryServer?->name ?? 'Não definido' }}
                </strong>

                <small>
                    {{ $secondaryServer?->hostname ?? 'Secondary opcional.' }}
                </small>
            </article>
        </section>

        <section class="zone-workspace">
            <div class="zone-workspace-main">
                <article class="zone-panel zone-configuration-panel">
                    <div class="zone-panel-heading">
                        <div>
                            <p class="eyebrow">Configuração</p>
                            <h2>Parâmetros da zona</h2>

                            <p>
                                Edite servidores, TTL e parâmetros SOA.
                                Salvar apenas atualiza o banco e gera uma nova versão.
                            </p>
                        </div>

                        <span class="zone-version-chip">
                            v{{ $zone->version }}
                        </span>
                    </div>

                    @if ($canManageZone)
                        <form
                            method="POST"
                            action="{{ route('zones.update', $zone) }}"
                            class="zone-edit-form"
                        >
                            @csrf
                            @method('PUT')

                            <div class="zone-form-grid zone-form-grid-main">
                                <label class="zone-field zone-field-wide">
                                    <span>Nome da zona</span>

                                    <input
                                        type="text"
                                        name="name"
                                        value="{{ old('name', $zone->name) }}"
                                        required
                                        maxlength="255"
                                        autocomplete="off"
                                    >
                                </label>

                                <label class="zone-field">
                                    <span>Tipo</span>

                                    <select name="kind" required>
                                        @foreach (\App\Models\DnsZone::KINDS as $kind)
                                            <option
                                                value="{{ $kind }}"
                                                @selected(old('kind', $zone->kind) === $kind)
                                            >
                                                {{ $kindLabels[$kind] ?? $kind }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="zone-field">
                                    <span>TTL padrão</span>

                                    <input
                                        type="number"
                                        name="default_ttl"
                                        value="{{ old('default_ttl', $zone->default_ttl) }}"
                                        min="60"
                                        max="2147483647"
                                        required
                                    >
                                </label>

                                <label class="zone-field">
                                    <span>Servidor primary</span>

                                    <select name="primary_server_id" required>
                                        <option value="">
                                            Selecione o primary
                                        </option>

                                        @foreach ($servers as $server)
                                            <option
                                                value="{{ $server->id }}"
                                                @selected(
                                                    (int) old(
                                                        'primary_server_id',
                                                        $primaryServer?->id
                                                    ) === (int) $server->id
                                                )
                                            >
                                                {{ $server->name }}
                                                — {{ $server->hostname }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="zone-field">
                                    <span>Servidor secondary</span>

                                    <select name="secondary_server_id">
                                        <option value="">
                                            Sem secondary
                                        </option>

                                        @foreach ($servers as $server)
                                            <option
                                                value="{{ $server->id }}"
                                                @selected(
                                                    (int) old(
                                                        'secondary_server_id',
                                                        $secondaryServer?->id
                                                    ) === (int) $server->id
                                                )
                                            >
                                                {{ $server->name }}
                                                — {{ $server->hostname }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>

                            <div class="zone-form-section">
                                <div class="zone-form-section-heading">
                                    <div>
                                        <h3>Start of Authority</h3>
                                        <p>
                                            Parâmetros utilizados no registro SOA.
                                        </p>
                                    </div>
                                </div>

                                <div class="zone-form-grid">
                                    <label class="zone-field">
                                        <span>MNAME</span>

                                        <input
                                            type="text"
                                            name="soa_mname"
                                            value="{{ old('soa_mname', $zone->soa_mname) }}"
                                            required
                                            maxlength="255"
                                            autocomplete="off"
                                        >
                                    </label>

                                    <label class="zone-field">
                                        <span>RNAME</span>

                                        <input
                                            type="text"
                                            name="soa_rname"
                                            value="{{ old('soa_rname', $zone->soa_rname) }}"
                                            required
                                            maxlength="255"
                                            autocomplete="off"
                                        >
                                    </label>

                                    <label class="zone-field">
                                        <span>Refresh</span>

                                        <input
                                            type="number"
                                            name="soa_refresh"
                                            value="{{ old('soa_refresh', $zone->soa_refresh) }}"
                                            min="60"
                                            max="2147483647"
                                            required
                                        >
                                    </label>

                                    <label class="zone-field">
                                        <span>Retry</span>

                                        <input
                                            type="number"
                                            name="soa_retry"
                                            value="{{ old('soa_retry', $zone->soa_retry) }}"
                                            min="60"
                                            max="2147483647"
                                            required
                                        >
                                    </label>

                                    <label class="zone-field">
                                        <span>Expire</span>

                                        <input
                                            type="number"
                                            name="soa_expire"
                                            value="{{ old('soa_expire', $zone->soa_expire) }}"
                                            min="3600"
                                            max="2147483647"
                                            required
                                        >
                                    </label>

                                    <label class="zone-field">
                                        <span>Minimum</span>

                                        <input
                                            type="number"
                                            name="soa_minimum"
                                            value="{{ old('soa_minimum', $zone->soa_minimum) }}"
                                            min="60"
                                            max="2147483647"
                                            required
                                        >
                                    </label>

                                    <label class="zone-field zone-field-full">
                                        <span>Observações</span>

                                        <textarea
                                            name="notes"
                                            rows="3"
                                            maxlength="2000"
                                            placeholder="Observações operacionais sobre a zona"
                                        >{{ old('notes', $zone->notes) }}</textarea>
                                    </label>
                                </div>
                            </div>

                            <div class="zone-form-actions">
                                <span>
                                    O serial e a versão serão incrementados
                                    automaticamente.
                                </span>

                                <button
                                    type="submit"
                                    class="button button-primary zone-action-button"
                                >
                                    Salvar parâmetros
                                </button>
                            </div>
                        </form>
                    @else
                        <div class="zone-readonly-grid">
                            <div>
                                <span>Tipo</span>
                                <strong>{{ $kindLabels[$zone->kind] ?? $zone->kind }}</strong>
                            </div>

                            <div>
                                <span>TTL padrão</span>
                                <strong>{{ $zone->default_ttl }}</strong>
                            </div>

                            <div>
                                <span>MNAME</span>
                                <strong>{{ $zone->soa_mname }}</strong>
                            </div>

                            <div>
                                <span>RNAME</span>
                                <strong>{{ $zone->soa_rname }}</strong>
                            </div>

                            <div>
                                <span>Refresh</span>
                                <strong>{{ $zone->soa_refresh }}</strong>
                            </div>

                            <div>
                                <span>Retry</span>
                                <strong>{{ $zone->soa_retry }}</strong>
                            </div>

                            <div>
                                <span>Expire</span>
                                <strong>{{ $zone->soa_expire }}</strong>
                            </div>

                            <div>
                                <span>Minimum</span>
                                <strong>{{ $zone->soa_minimum }}</strong>
                            </div>
                        </div>

                        <p class="zone-readonly-note">
                            Seu perfil possui acesso somente para consulta.
                        </p>
                    @endif
                </article>

                <article class="zone-panel zone-records-panel">
                    <div class="zone-panel-heading">
                        <div>
                            <p class="eyebrow">Resource records</p>
                            <h2>Registros DNS</h2>

                            <p>
                                Cadastre e edite os registros que compõem
                                o zonefile.
                            </p>
                        </div>

                        <span class="zone-count-chip">
                            {{ $zone->records->count() }}
                        </span>
                    </div>

                    @if ($canManageZone)
                        <form
                            method="POST"
                            action="{{ route('zones.records.store', $zone) }}"
                            class="zone-record-create-form"
                        >
                            @csrf

                            <label class="zone-field">
                                <span>Nome</span>

                                <input
                                    type="text"
                                    name="name"
                                    value="{{ old('name') }}"
                                    placeholder="@ ou www"
                                    required
                                    maxlength="255"
                                    autocomplete="off"
                                >
                            </label>

                            <label class="zone-field">
                                <span>Tipo</span>

                                <select name="type" required>
                                    @foreach (\App\Models\DnsRecord::TYPES as $type)
                                        <option
                                            value="{{ $type }}"
                                            @selected(old('type', 'A') === $type)
                                        >
                                            {{ $type }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="zone-field">
                                <span>TTL</span>

                                <input
                                    type="number"
                                    name="ttl"
                                    value="{{ old('ttl') }}"
                                    placeholder="{{ $zone->default_ttl }}"
                                    min="60"
                                    max="2147483647"
                                >
                            </label>

                            <label class="zone-field">
                                <span>Prioridade</span>

                                <input
                                    type="number"
                                    name="priority"
                                    value="{{ old('priority') }}"
                                    placeholder="MX"
                                    min="0"
                                    max="65535"
                                >
                            </label>

                            <label class="zone-field zone-record-content-field">
                                <span>Conteúdo</span>

                                <input
                                    type="text"
                                    name="content"
                                    value="{{ old('content') }}"
                                    placeholder="192.0.2.10 ou destino.exemplo.com"
                                    required
                                    maxlength="4096"
                                    autocomplete="off"
                                >
                            </label>

                            <button
                                type="submit"
                                class="button button-primary zone-record-add-button"
                            >
                                Adicionar
                            </button>
                        </form>
                    @endif

                    <div class="zone-record-list">
                        @forelse ($zone->records as $record)
                            <article class="zone-record-card">
                                @if ($canManageZone)
                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'zones.records.update',
                                            [$zone, $record]
                                        ) }}"
                                        class="zone-record-edit-form"
                                    >
                                        @csrf
                                        @method('PUT')

                                        <label class="zone-field">
                                            <span>Nome</span>

                                            <input
                                                type="text"
                                                name="name"
                                                value="{{ $record->name }}"
                                                required
                                                maxlength="255"
                                                autocomplete="off"
                                            >
                                        </label>

                                        <label class="zone-field">
                                            <span>Tipo</span>

                                            <select name="type" required>
                                                @foreach (\App\Models\DnsRecord::TYPES as $type)
                                                    <option
                                                        value="{{ $type }}"
                                                        @selected($record->type === $type)
                                                    >
                                                        {{ $type }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </label>

                                        <label class="zone-field">
                                            <span>TTL</span>

                                            <input
                                                type="number"
                                                name="ttl"
                                                value="{{ $record->ttl }}"
                                                placeholder="{{ $zone->default_ttl }}"
                                                min="60"
                                                max="2147483647"
                                            >
                                        </label>

                                        <label class="zone-field">
                                            <span>Prioridade</span>

                                            <input
                                                type="number"
                                                name="priority"
                                                value="{{ $record->priority }}"
                                                placeholder="MX"
                                                min="0"
                                                max="65535"
                                            >
                                        </label>

                                        <label class="zone-field zone-record-content-field">
                                            <span>Conteúdo</span>

                                            <input
                                                type="text"
                                                name="content"
                                                value="{{ $record->content }}"
                                                required
                                                maxlength="4096"
                                                autocomplete="off"
                                            >
                                        </label>

                                        <button
                                            type="submit"
                                            class="button button-secondary button-small"
                                        >
                                            Salvar
                                        </button>
                                    </form>

                                    <form
                                        method="POST"
                                        action="{{ route(
                                            'zones.records.destroy',
                                            [$zone, $record]
                                        ) }}"
                                        class="zone-record-delete-form"
                                        onsubmit="return confirm(
                                            'Remover este registro DNS?'
                                        );"
                                    >
                                        @csrf
                                        @method('DELETE')

                                        <button
                                            type="submit"
                                            class="zone-danger-button"
                                            aria-label="Remover registro"
                                            title="Remover registro"
                                        >
                                            Remover
                                        </button>
                                    </form>
                                @else
                                    <div class="zone-record-readonly">
                                        <strong>{{ $record->name }}</strong>
                                        <span>{{ $record->type }}</span>
                                        <span>
                                            TTL {{ $record->ttl ?: $zone->default_ttl }}
                                        </span>

                                        <code>
                                            @if ($record->priority !== null)
                                                {{ $record->priority }}
                                            @endif

                                            {{ $record->content }}
                                        </code>
                                    </div>
                                @endif
                            </article>
                        @empty
                            <div class="empty-state zone-empty-state">
                                Nenhum registro DNS cadastrado nesta zona.
                            </div>
                        @endforelse
                    </div>
                </article>
            </div>

            <aside class="zone-workspace-sidebar">
                <article class="zone-panel zone-validation-panel">
                    <div class="zone-panel-heading zone-panel-heading-compact">
                        <div>
                            <p class="eyebrow">Pré-publicação</p>
                            <h2>Validação</h2>
                        </div>

                        <span
                            class="zone-validation-indicator {{ $validationOk ? 'is-ok' : 'is-error' }}"
                        >
                            {{ $validationOk ? 'OK' : 'Pendente' }}
                        </span>
                    </div>

                    @if ($validationOk)
                        <div class="zone-validation-success">
                            <strong>Zona estruturalmente válida</strong>

                            <p>
                                O artefato pode ser marcado como pronto.
                                Nenhuma alteração será aplicada ao BIND.
                            </p>
                        </div>
                    @else
                        <div class="zone-validation-block">
                            <strong>Correções necessárias</strong>

                            <ul>
                                @forelse ($validationErrors as $error)
                                    <li>{{ $error }}</li>
                                @empty
                                    <li>
                                        A zona ainda não atende aos critérios
                                        de publicação.
                                    </li>
                                @endforelse
                            </ul>
                        </div>
                    @endif

                    @if ($validationWarnings)
                        <div class="zone-validation-warning">
                            <strong>Alertas</strong>

                            <ul>
                                @foreach ($validationWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($canManageZone)
                        <form
                            method="POST"
                            action="{{ route('zones.publish', $zone) }}"
                            class="zone-publish-form"
                        >
                            @csrf

                            <button
                                type="submit"
                                class="button button-primary"
                                @disabled(! $validationOk)
                            >
                                Validar plano de publicação
                            </button>

                            <small>
                                Esta ação apenas grava o estado
                                <strong>ready</strong> e uma nova versão.
                            </small>
                        </form>
                    @endif
                </article>

                <article class="zone-panel zone-preview-panel">
                    <div class="zone-panel-heading zone-panel-heading-compact">
                        <div>
                            <p class="eyebrow">Artefato</p>
                            <h2>Preview BIND</h2>
                        </div>

                        <span class="zone-preview-chip">
                            somente leitura
                        </span>
                    </div>

                    <pre class="zone-preview">{{ $preview }}</pre>

                    <p class="zone-preview-note">
                        Este conteúdo ainda não foi enviado aos servidores.
                    </p>
                </article>

                <article class="zone-panel zone-history-panel">
                    <div class="zone-panel-heading zone-panel-heading-compact">
                        <div>
                            <p class="eyebrow">Auditoria</p>
                            <h2>Últimas versões</h2>
                        </div>

                        <span class="zone-count-chip">
                            {{ $zone->versions->count() }}
                        </span>
                    </div>

                    <div class="zone-version-list">
                        @forelse ($zone->versions as $version)
                            <div class="zone-version-row">
                                <div>
                                    <strong>v{{ $version->version }}</strong>

                                    <span>
                                        Serial {{ $version->serial }}
                                    </span>
                                </div>

                                <div>
                                    <span>{{ $version->reason }}</span>

                                    <time datetime="{{ $version->created_at?->toIso8601String() }}">
                                        {{ $version->created_at?->format('d/m/Y H:i') }}
                                    </time>
                                </div>
                            </div>
                        @empty
                            <div class="empty-state">
                                Nenhuma versão registrada.
                            </div>
                        @endforelse
                    </div>
                </article>
            </aside>
        </section>
    </main>
</div>
@endsection
