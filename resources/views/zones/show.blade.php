@extends('layouts.app')

@section('title', $zone->name)
@section('body-class', 'app-page')

@php
    $user = auth()->user();
    $organizationId = (int) $user->current_organization_id;

    $canManageDomain = $user->is_platform_admin
        || $user->roleForOrganization($organizationId) === 'organization_admin';

    $primaryServer = $zone->servers->first(
        fn ($server) => $server->pivot?->role === 'primary'
    );

    $secondaryServer = $zone->servers->first(
        fn ($server) => $server->pivot?->role === 'secondary'
    );

    $currentNameserverProfile = $zone->nameserverProfile;

    $currentNameserverIdentities = $currentNameserverProfile
        ?->identities
        ?->where(
            'organization_id',
            (int) $zone->organization_id
        )
        ?->where('enabled', true)
        ?->sortBy(
            fn ($identity) => (int) $identity->pivot->position
        )
        ?->values() ?? collect();

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
        'ready' => 'Pronto',
        'published' => 'Publicado',
        'disabled' => 'Desativado',
    ];
@endphp

@section('content')
<div class="app-shell dashboard-v2">
    <x-app-sidebar active="zones" />

    <main class="main-content domain-workspace-page">
        <header class="topbar domain-workspace-topbar">
            <div>
                <p class="eyebrow">Domínio autoritativo</p>

                <div class="domain-workspace-title">
                    <h1>{{ $zone->name }}</h1>

                    <span class="domain-status domain-status-{{ $zone->status }}">
                        {{ $statusLabels[$zone->status] ?? $zone->status }}
                    </span>
                </div>

                <p class="page-description">
                    {{ $zone->records->count() }} registro(s)
                    · versão {{ $zone->version }}
                    · serial {{ $zone->serial }}
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
            <div
                class="alert alert-success flash-toast"
                role="status"
                data-flash-toast
                data-flash-timeout="6000"
            >
                <span class="flash-toast-message">
                    {{ session('status') }}
                </span>

                <button
                    type="button"
                    class="flash-toast-close"
                    aria-label="Fechar mensagem"
                    title="Fechar"
                    data-flash-toast-close
                >
                    ×
                </button>

                <span
                    class="flash-toast-progress"
                    aria-hidden="true"
                ></span>
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

        <section class="domain-overview-grid">
            <article class="domain-overview-card">
                <span>Estado</span>

                <strong>
                    {{ $statusLabels[$zone->status] ?? $zone->status }}
                </strong>

                <small>
                    @if ($zone->status === 'draft')
                        Alterações ainda não publicadas.
                    @elseif ($zone->status === 'ready')
                        Alterações ainda não publicadas.
                    @elseif ($zone->status === 'published')
                        A versão atual está publicada.
                    @else
                        Domínio fora da operação normal.
                    @endif
                </small>
            </article>

            <article class="domain-overview-card">
                <span>Validação</span>

                <strong class="{{ $validationOk ? 'is-success' : 'is-danger' }}">
                    {{ $validationOk ? 'Aprovada' : 'Com pendências' }}
                </strong>

                <small>
                    {{ count($validationErrors) }} erro(s)
                    · {{ count($validationWarnings) }} alerta(s)
                </small>
            </article>

            <article class="domain-overview-card">
                <span>Perfil de nameservers</span>

                <strong>
                    {{ $currentNameserverProfile?->name ?? 'Não definido' }}
                </strong>

                <small>
                    {{ $currentNameserverIdentities->count() }}
                    identidade(s) DNS pública(s)
                </small>
            </article>

            <article class="domain-overview-card">
                <span>Publicação principal</span>

                <strong>
                    {{ $primaryServer?->name ?? 'Não definido' }}
                </strong>

                <small>
                    {{ $primaryServer?->hostname ?? 'Selecione um servidor.' }}
                </small>
            </article>
        </section>

        <nav class="domain-tabs" aria-label="Áreas do domínio">
            <button
                type="button"
                class="domain-tab is-active"
                data-domain-tab="records"
            >
                Registros DNS
                <span>{{ $zone->records->count() }}</span>
            </button>

            <button
                type="button"
                class="domain-tab"
                data-domain-tab="configuration"
            >
                Configuração
            </button>

            <button
                type="button"
                class="domain-tab"
                data-domain-tab="reverse"
            >
                DNS reverso
            </button>

            <button
                type="button"
                class="domain-tab"
                data-domain-tab="publication"
            >
                Publicação

                @if (! $validationOk)
                    <span class="is-warning">!</span>
                @endif
            </button>

            <button
                type="button"
                class="domain-tab"
                data-domain-tab="history"
            >
                Histórico
                <span>{{ $zone->versions->count() }}</span>
            </button>
        </nav>

        <section
            class="domain-tab-panel is-active"
            data-domain-panel="records"
        >
            <article class="domain-section-card">
                <header class="domain-section-header">
                    <div>
                        <p class="eyebrow">Registros DNS</p>
                        <h2>Gerenciamento de registros</h2>

                        <p>
                            Cadastre e edite as entradas DNS deste domínio.
                        </p>
                    </div>

                    @if ($canManageDomain)
                        <button
                            type="button"
                            class="button button-primary domain-add-record-button"
                            data-record-modal-open
                        >
                            <span aria-hidden="true">＋</span>
                            Adicionar registro
                        </button>
                    @endif
                </header>

                <div class="domain-record-toolbar">
                    <label class="domain-record-search">
                        <span aria-hidden="true">⌕</span>

                        <input
                            type="search"
                            placeholder="Pesquisar por nome, tipo ou conteúdo"
                            data-record-search
                        >
                    </label>

                    <span class="domain-record-total">
                        {{ $zone->records->count() }}
                        registro(s)
                    </span>
                </div>

                @if ($zone->records->isNotEmpty())
                    <div class="domain-record-table-wrapper">
                        <div class="domain-record-table">
                            <div class="domain-record-table-header">
                                <span>Nome</span>
                                <span>Tipo</span>
                                <span>Conteúdo</span>
                                <span>TTL</span>
                                <span>Estado</span>
                                <span></span>
                            </div>

                            @foreach ($zone->records as $record)
                                <div
                                    class="domain-record-row"
                                    data-record-row
                                    data-record-search-value="{{ mb_strtolower(
                                        $record->name
                                        . ' '
                                        . $record->type
                                        . ' '
                                        . $record->content
                                    ) }}"
                                >
                                    <div class="domain-record-name">
                                        <strong>{{ $record->name }}</strong>

                                        <small>
                                            {{ $record->name === '@'
                                                ? $zone->name
                                                : $record->name . '.' . $zone->name }}
                                        </small>
                                    </div>

                                    <div>
                                        <span class="domain-record-type">
                                            {{ $record->type }}
                                        </span>
                                    </div>

                                    <code class="domain-record-content">
                                        @if ($record->priority !== null)
                                            {{ $record->priority }}
                                        @endif

                                        {{ $record->content }}
                                    </code>

                                    <div class="domain-record-ttl">
                                        {{ $record->ttl ?: $zone->default_ttl }}
                                    </div>

                                    <div>
                                        <span class="domain-record-state">
                                            Ativo
                                        </span>
                                    </div>

                                    <div class="domain-record-actions">
                                        @if ($canManageDomain)
                                            <button
                                                type="button"
                                                class="domain-edit-record-button"
                                                data-record-edit
                                                data-edit-id="{{ $record->id }}"
                                                data-edit-name="{{ $record->name }}"
                                                data-edit-type="{{ $record->type }}"
                                                data-edit-ttl="{{ $record->ttl }}"
                                                data-edit-priority="{{ $record->priority }}"
                                                data-edit-content="{{ $record->content }}"
                                                data-update-url="{{ route(
                                                    'zones.records.update',
                                                    [$zone, $record]
                                                ) }}"
                                                data-delete-url="{{ route(
                                                    'zones.records.destroy',
                                                    [$zone, $record]
                                                ) }}"
                                            >
                                                Editar
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @else
                    <div class="domain-record-empty">
                        <div aria-hidden="true">◎</div>

                        <h3>Nenhum registro DNS cadastrado</h3>

                        <p>
                            Adicione os registros NS, A, AAAA, MX, TXT e demais
                            entradas necessárias para este domínio.
                        </p>

                        @if ($canManageDomain)
                            <button
                                type="button"
                                class="button button-primary"
                                data-record-modal-open
                            >
                                Adicionar primeiro registro
                            </button>
                        @endif
                    </div>
                @endif

                <div
                    class="domain-record-no-results"
                    data-record-no-results
                    hidden
                >
                    Nenhum registro corresponde à pesquisa.
                </div>
            </article>
        </section>

        <section
            class="domain-tab-panel"
            data-domain-panel="configuration"
            hidden
        >
            <article class="domain-section-card">
                <header class="domain-section-header">
                    <div>
                        <p class="eyebrow">Configuração</p>
                        <h2>Configuração do domínio</h2>

                        <p>
                            Servidores DNS, TTL e parâmetros avançados do SOA.
                        </p>
                    </div>
                </header>

                @if ($canManageDomain)
                    <form
                        method="POST"
                        action="{{ route('zones.update', $zone) }}"
                        class="domain-configuration-form"
                    >
                        @csrf
                        @method('PUT')

                        <input
                            type="hidden"
                            name="name"
                            value="{{ old('name', $zone->name) }}"
                        >

                        <input
                            type="hidden"
                            name="kind"
                            value="{{ old('kind', $zone->kind) }}"
                        >

                        <div class="domain-configuration-section">
                            <div class="domain-configuration-heading">
                                <h3>Identidade DNS pública</h3>

                                <p>
                                    O perfil controla os registros NS, o MNAME
                                    do SOA e os registros glue da zona.
                                </p>
                            </div>

                            <label class="domain-field domain-field-full">
                                <span>Perfil de nameservers</span>

                                <select
                                    name="dns_nameserver_profile_id"
                                    required
                                    data-zone-profile-select
                                >
                                    @foreach ($nameserverProfiles as $profile)
                                        @php
                                            $profileIdentities = $profile->identities
                                                ->where(
                                                    'organization_id',
                                                    $profile->organization_id
                                                )
                                                ->sortBy(
                                                    fn ($identity) => (int) $identity->pivot->position
                                                )
                                                ->values()
                                                ->map(fn ($identity) => [
                                                    'hostname' => $identity->normalizedHostname(),
                                                    'ipv4' => $identity->ipv4_address,
                                                    'ipv6' => $identity->ipv6_address,
                                                ])
                                                ->all();
                                        @endphp

                                        <option
                                            value="{{ $profile->id }}"
                                            data-profile-name="{{ $profile->name }}"
                                            data-profile-identities='{{ Illuminate\Support\Js::encode($profileIdentities) }}'
                                            @selected(
                                                (int) old(
                                                    'dns_nameserver_profile_id',
                                                    $zone
                                                        ->dns_nameserver_profile_id
                                                ) === (int) $profile->id
                                            )
                                        >
                                            {{ $profile->name }}
                                            @if ($profile->is_default)
                                                — padrão
                                            @endif
                                        </option>
                                    @endforeach
                                </select>

                                <small>
                                    Alterar o perfil sincroniza automaticamente
                                    os registros NS e glue da zona.
                                </small>
                            </label>

                            <div
                                class="domain-profile-preview"
                                data-zone-profile-preview
                            >
                                <header>
                                    <div>
                                        <span>Nameservers do perfil</span>

                                        <strong data-zone-profile-name>
                                            {{ $currentNameserverProfile?->name }}
                                        </strong>
                                    </div>

                                    <span data-zone-profile-count>
                                        {{ $currentNameserverIdentities->count() }}
                                        NS
                                    </span>
                                </header>

                                <div data-zone-profile-identities>
                                    @foreach (
                                        $currentNameserverIdentities
                                        as $identity
                                    )
                                        <div class="domain-profile-identity">
                                            <span>{{ $loop->iteration }}</span>

                                            <div>
                                                <strong>
                                                    {{ $identity
                                                        ->normalizedHostname() }}
                                                </strong>

                                                <small>
                                                    @php
                                                        $addresses = collect([
                                                            $identity
                                                                ->ipv4_address,
                                                            $identity
                                                                ->ipv6_address,
                                                        ])->filter();
                                                    @endphp

                                                    {{ $addresses->isNotEmpty()
                                                        ? $addresses->join(' · ')
                                                        : 'Nameserver externo ou sem glue' }}
                                                </small>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div class="domain-configuration-section">
                            <div class="domain-configuration-heading">
                                <h3>Servidores de publicação</h3>

                                <p>
                                    Destinos que receberão futuramente os
                                    artefatos da zona. Não definem os nomes NS
                                    públicos.
                                </p>
                            </div>

                            <div class="domain-form-grid">
                                <label class="domain-field">
                                    <span>Servidor de publicação principal</span>

                                    <select
                                        name="primary_server_id"
                                        required
                                    >
                                        <option value="">
                                            Selecione o servidor
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

                                <label class="domain-field">
                                    <span>Servidor de publicação secundário</span>

                                    <select name="secondary_server_id">
                                        <option value="">
                                            Sem servidor secundário
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
                        </div>

                        <div class="domain-configuration-section">
                            <div class="domain-configuration-heading">
                                <h3>Parâmetros do SOA</h3>

                                <p>
                                    Estes valores raramente precisam ser
                                    alterados.
                                </p>
                            </div>

                            <div class="domain-form-grid domain-form-grid-three">
                                <label class="domain-field">
                                    <span>TTL padrão</span>

                                    <input
                                        type="number"
                                        name="default_ttl"
                                        value="{{ old(
                                            'default_ttl',
                                            $zone->default_ttl
                                        ) }}"
                                        min="60"
                                        required
                                    >
                                </label>

                                <label class="domain-field">
                                    <span>Servidor principal do SOA</span>

                                    <input
                                        type="text"
                                        value="{{ $zone->soa_mname }}"
                                        data-zone-soa-mname
                                        readonly
                                        aria-readonly="true"
                                    >

                                    <small>
                                        Derivado automaticamente do primeiro
                                        nameserver do perfil.
                                    </small>
                                </label>

                                <label class="domain-field">
                                    <span>Contato responsável</span>

                                    <input
                                        type="text"
                                        name="soa_rname"
                                        value="{{ old(
                                            'soa_rname',
                                            $zone->soa_rname
                                        ) }}"
                                        required
                                    >
                                </label>

                                <label class="domain-field">
                                    <span>Refresh</span>

                                    <input
                                        type="number"
                                        name="soa_refresh"
                                        value="{{ old(
                                            'soa_refresh',
                                            $zone->soa_refresh
                                        ) }}"
                                        min="60"
                                        required
                                    >
                                </label>

                                <label class="domain-field">
                                    <span>Retry</span>

                                    <input
                                        type="number"
                                        name="soa_retry"
                                        value="{{ old(
                                            'soa_retry',
                                            $zone->soa_retry
                                        ) }}"
                                        min="60"
                                        required
                                    >
                                </label>

                                <label class="domain-field">
                                    <span>Expire</span>

                                    <input
                                        type="number"
                                        name="soa_expire"
                                        value="{{ old(
                                            'soa_expire',
                                            $zone->soa_expire
                                        ) }}"
                                        min="3600"
                                        required
                                    >
                                </label>

                                <label class="domain-field">
                                    <span>Minimum</span>

                                    <input
                                        type="number"
                                        name="soa_minimum"
                                        value="{{ old(
                                            'soa_minimum',
                                            $zone->soa_minimum
                                        ) }}"
                                        min="60"
                                        required
                                    >
                                </label>
                            </div>
                        </div>

                        <label class="domain-field domain-field-full">
                            <span>Observações internas</span>

                            <textarea
                                name="notes"
                                rows="4"
                                placeholder="Observações operacionais sobre o domínio"
                            >{{ old('notes', $zone->notes) }}</textarea>
                        </label>

                        <footer class="domain-form-footer">
                            <p>
                                O serial e a versão serão incrementados
                                automaticamente.
                            </p>

                            <button
                                type="submit"
                                class="button button-primary"
                            >
                                Salvar configuração
                            </button>
                        </footer>
                    </form>
                @else
                    <div class="domain-readonly-message">
                        Seu perfil possui acesso somente para consulta.
                    </div>
                @endif
            </article>
        </section>

        <section
            class="domain-tab-panel"
            data-domain-panel="reverse"
            hidden
        >
            <article class="domain-section-card">
                <header class="domain-section-header">
                    <div>
                        <p class="eyebrow">DNS reverso</p>
                        <h2>Zonas reversas IPv4 e IPv6</h2>

                        <p>
                            Gerencie blocos de endereços e registros PTR
                            separadamente da zona direta.
                        </p>
                    </div>
                </header>

                <div class="domain-reverse-empty">
                    <div aria-hidden="true">↺</div>

                    <h3>Nenhum reverso associado</h3>

                    <p>
                        O assistente de reverso IPv4 e IPv6 será implementado
                        como um fluxo próprio, sem misturar os registros PTR
                        com os registros da zona direta.
                    </p>

                    <span>Funcionalidade preparada para a próxima fase</span>
                </div>
            </article>
        </section>

        <section
            class="domain-tab-panel"
            data-domain-panel="publication"
            hidden
        >
            <div class="domain-publication-grid">
                <article class="domain-section-card">
                    <header class="domain-section-header">
                        <div>
                            <p class="eyebrow">Pré-publicação</p>
                            <h2>Validação do domínio</h2>

                            <p>
                                Valide e publique explicitamente a versão
                                salva.
                            </p>
                        </div>

                        <span
                            class="domain-validation-badge {{ $validationOk
                                ? 'is-valid'
                                : 'is-invalid' }}"
                        >
                            {{ $validationOk ? 'Aprovado' : 'Pendente' }}
                        </span>
                    </header>

                    @if ($validationOk)
                        <div class="domain-validation-success">
                            <strong>Domínio estruturalmente válido</strong>

                            <p>
                                A configuração pode ser publicada.
                            </p>
                        </div>
                    @else
                        <div class="domain-validation-errors">
                            <strong>Correções necessárias</strong>

                            <ul>
                                @forelse ($validationErrors as $error)
                                    <li>{{ $error }}</li>
                                @empty
                                    <li>
                                        O domínio ainda possui pendências.
                                    </li>
                                @endforelse
                            </ul>
                        </div>
                    @endif

                    @if ($validationWarnings)
                        <div class="domain-validation-warnings">
                            <strong>Alertas</strong>

                            <ul>
                                @foreach ($validationWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if ($canManageDomain)
                        <form
                            method="POST"
                            action="{{ route('zones.publish', $zone) }}"
                            class="domain-publication-action"
                            onsubmit="return confirm('Publicar agora a versão salva desta zona?')"
                        >
                            @csrf

                            <button
                                type="submit"
                                class="button button-primary"
                                @disabled(! $validationOk)
                            >
                                Publicar zona
                            </button>

                            <small>
                                Salvar não publica. Esta ação disponibiliza o
                                artefato aos agentes configurados.
                            </small>
                        </form>
                    @endif

                    <div class="domain-validation-success">
                        <strong>Última publicação</strong>

                        <p>
                            @if ($lastPublication)
                                {{ $lastPublication->created_at?->format(
                                    'd/m/Y H:i'
                                ) }}
                                · versão {{ $lastPublication->version }}
                            @else
                                Nenhuma publicação realizada.
                            @endif
                        </p>
                    </div>

                    @if ($lastPublication)
                        @php
                            $publicationTargets = $lastPublication
                                ->agentPublications;
                            $appliedCount = $publicationTargets
                                ->where('status', 'applied')
                                ->count();
                            $failedCount = $publicationTargets
                                ->where('status', 'failed')
                                ->count();
                            $offlineCount = $publicationTargets
                                ->filter(
                                    fn ($target) =>
                                        $target->server?->agent_status === 'offline'
                                        || $target->server?->status === 'offline'
                                )
                                ->count();
                            $pendingCount = $publicationTargets->count()
                                - $appliedCount
                                - $failedCount;
                        @endphp

                        <dl class="agent-server-details">
                            <div>
                                <dt>Agentes aplicáveis</dt>
                                <dd>{{ $publicationTargets->count() }}</dd>
                            </div>
                            <div>
                                <dt>Aplicação confirmada</dt>
                                <dd>{{ $appliedCount }}</dd>
                            </div>
                            <div>
                                <dt>Pendentes</dt>
                                <dd>{{ $pendingCount }}</dd>
                            </div>
                            <div>
                                <dt>Falharam</dt>
                                <dd>{{ $failedCount }}</dd>
                            </div>
                            <div>
                                <dt>Agentes offline</dt>
                                <dd>{{ $offlineCount }}</dd>
                            </div>
                        </dl>
                    @endif
                </article>

                <article class="domain-section-card">
                    <header class="domain-section-header">
                        <div>
                            <p class="eyebrow">Artefato</p>
                            <h2>Preview BIND</h2>

                            <p>
                                Conteúdo que será disponibilizado futuramente
                                ao agente.
                            </p>
                        </div>

                        <span class="domain-readonly-chip">
                            somente leitura
                        </span>
                    </header>

                    <pre class="domain-bind-preview">{{ $preview }}</pre>
                </article>
            </div>
        </section>

        <section
            class="domain-tab-panel"
            data-domain-panel="history"
            hidden
        >
            <article class="domain-section-card">
                <header class="domain-section-header">
                    <div>
                        <p class="eyebrow">Histórico</p>
                        <h2>Versões do domínio</h2>

                        <p>
                            Acompanhe as alterações realizadas na configuração.
                        </p>
                    </div>
                </header>

                <div class="domain-history-list">
                    @forelse ($zone->versions as $version)
                        <article class="domain-history-item">
                            <div class="domain-history-version">
                                <strong>v{{ $version->version }}</strong>

                                <span>
                                    Serial {{ $version->serial }}
                                </span>
                            </div>

                            <div class="domain-history-description">
                                <strong>
                                    {{ $version->reason ?: 'Alteração registrada' }}
                                </strong>

                                <time datetime="{{ $version->created_at?->toIso8601String() }}">
                                    {{ $version->created_at?->format(
                                        'd/m/Y H:i'
                                    ) }}
                                </time>
                            </div>
                        </article>
                    @empty
                        <div class="domain-history-empty">
                            Nenhuma versão registrada.
                        </div>
                    @endforelse
                </div>
            </article>
        </section>
    </main>
</div>

@if ($canManageDomain)
    <div
        class="record-modal-backdrop"
        data-record-modal
        aria-hidden="true"
    >
        <section
            class="record-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="record-modal-title"
        >
            <header class="record-modal-header">
                <div>
                    <p class="eyebrow" data-record-modal-eyebrow>
                        Novo registro
                    </p>

                    <h2 id="record-modal-title" data-record-modal-title>
                        Adicionar registro
                    </h2>

                    <p data-record-description>
                        Configure a nova entrada DNS.
                    </p>
                </div>

                <button
                    type="button"
                    class="record-modal-close"
                    data-record-modal-close
                    aria-label="Fechar"
                >
                    ×
                </button>
            </header>

            <form
                method="POST"
                action="{{ route('zones.records.store', $zone) }}"
                class="record-modal-form"
                data-record-form
                data-create-url="{{ route(
                    'zones.records.store',
                    $zone
                ) }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="_method"
                    value="POST"
                    data-record-method
                >

                <div class="record-modal-main-grid">
                    <label class="domain-field record-type-field">
                        <span>Tipo</span>

                        <select name="type" required data-record-type>
                            @foreach (\App\Models\DnsRecord::TYPES as $type)
                                <option value="{{ $type }}">
                                    {{ $type }}
                                </option>
                            @endforeach
                        </select>
                    </label>

                    <label class="domain-field record-name-field">
                        <span>Nome</span>

                        <input
                            type="text"
                            name="name"
                            placeholder="Use @ para a raiz"
                            required
                            data-record-name
                        >
                    </label>
                </div>

                <div class="record-modal-value-grid">
                    <label class="domain-field record-content-field">
                        <span data-record-content-label>
                            Endereço IPv4
                        </span>

                        <input
                            type="text"
                            name="content"
                            placeholder="192.0.2.10"
                            required
                            data-record-content
                        >
                    </label>

                    <label
                        class="domain-field record-priority-field"
                        data-record-priority-field
                        hidden
                    >
                        <span>Prioridade</span>

                        <input
                            type="number"
                            name="priority"
                            min="0"
                            max="65535"
                            data-record-priority
                        >
                    </label>

                    <label class="domain-field record-ttl-field">
                        <span>TTL</span>

                        <select name="ttl" data-record-ttl>
                            <option value="">
                                Automático — {{ $zone->default_ttl }}
                            </option>
                            <option value="60">1 minuto</option>
                            <option value="300">5 minutos</option>
                            <option value="900">15 minutos</option>
                            <option value="1800">30 minutos</option>
                            <option value="3600">1 hora</option>
                            <option value="14400">4 horas</option>
                            <option value="86400">1 dia</option>
                        </select>
                    </label>
                </div>

                <details class="record-modal-attributes">
                    <summary>
                        <span>Atributos do registro</span>
                        <small>Opções adicionais</small>
                    </summary>

                    <div>
                        <label class="domain-field">
                            <span>Comentário interno</span>

                            <textarea
                                rows="3"
                                disabled
                                placeholder="Comentários serão implementados em uma fase futura."
                            ></textarea>

                            <small>
                                O comentário não fará parte do zonefile.
                            </small>
                        </label>
                    </div>
                </details>

                <footer class="record-modal-footer">
                    <div>
                        <button
                            type="button"
                            class="record-delete-button"
                            data-record-delete-trigger
                            hidden
                        >
                            Excluir
                        </button>
                    </div>

                    <div>
                        <button
                            type="button"
                            class="button button-secondary"
                            data-record-modal-close
                        >
                            Cancelar
                        </button>

                        <button
                            type="submit"
                            class="button button-primary"
                            data-record-submit-label
                        >
                            Salvar registro
                        </button>
                    </div>
                </footer>
            </form>

            <form
                method="POST"
                action=""
                data-record-delete-form
                hidden
            >
                @csrf
                @method('DELETE')
            </form>
        </section>
    </div>
@endif

<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const tabs = document.querySelectorAll('[data-domain-tab]');
    const panels = document.querySelectorAll('[data-domain-panel]');

    const activateTab = (tabName) => {
        tabs.forEach((tab) => {
            tab.classList.toggle(
                'is-active',
                tab.dataset.domainTab === tabName
            );
        });

        panels.forEach((panel) => {
            const active = panel.dataset.domainPanel === tabName;

            panel.classList.toggle('is-active', active);
            panel.hidden = !active;
        });

        window.history.replaceState(
            null,
            '',
            `#${tabName}`
        );
    };

    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            activateTab(tab.dataset.domainTab);
        });
    });

    const initialTab = window.location.hash.replace('#', '');

    if (
        initialTab
        && document.querySelector(`[data-domain-panel="${initialTab}"]`)
    ) {
        activateTab(initialTab);
    }

    const zoneProfileSelect = document.querySelector(
        '[data-zone-profile-select]'
    );
    const zoneProfileName = document.querySelector(
        '[data-zone-profile-name]'
    );
    const zoneProfileCount = document.querySelector(
        '[data-zone-profile-count]'
    );
    const zoneProfileIdentities = document.querySelector(
        '[data-zone-profile-identities]'
    );
    const zoneSoaMname = document.querySelector(
        '[data-zone-soa-mname]'
    );

    const updateZoneProfilePreview = () => {
        if (!zoneProfileSelect) {
            return;
        }

        const option = zoneProfileSelect.options[
            zoneProfileSelect.selectedIndex
        ];

        let identities = [];

        try {
            identities = JSON.parse(
                option?.dataset.profileIdentities || '[]'
            );
        } catch (error) {
            identities = [];
        }

        if (zoneProfileName) {
            zoneProfileName.textContent =
                option?.dataset.profileName || 'Sem perfil';
        }

        if (zoneProfileCount) {
            zoneProfileCount.textContent =
                `${identities.length} NS`;
        }

        if (zoneSoaMname) {
            zoneSoaMname.value =
                identities[0]?.hostname || '';
        }

        if (zoneProfileIdentities) {
            zoneProfileIdentities.replaceChildren();

            identities.forEach((identity, index) => {
                const item = document.createElement('div');
                item.className = 'domain-profile-identity';

                const order = document.createElement('span');
                order.textContent = String(index + 1);

                const body = document.createElement('div');

                const hostname = document.createElement('strong');
                hostname.textContent =
                    identity.hostname || 'Sem hostname';

                const metadata = document.createElement('small');

                const addresses = [
                    identity.ipv4,
                    identity.ipv6,
                ].filter(Boolean);

                metadata.textContent = addresses.length
                    ? addresses.join(' · ')
                    : 'Nameserver externo ou sem glue';

                body.append(hostname, metadata);
                item.append(order, body);

                zoneProfileIdentities.append(item);
            });
        }
    };

    zoneProfileSelect?.addEventListener(
        'change',
        updateZoneProfilePreview
    );

    const searchInput = document.querySelector('[data-record-search]');
    const recordRows = document.querySelectorAll('[data-record-row]');
    const noResults = document.querySelector('[data-record-no-results]');

    searchInput?.addEventListener('input', () => {
        const query = searchInput.value
            .trim()
            .toLocaleLowerCase('pt-BR');

        let visible = 0;

        recordRows.forEach((row) => {
            const matches = row.dataset.recordSearchValue.includes(query);

            row.hidden = !matches;

            if (matches) {
                visible++;
            }
        });

        if (noResults) {
            noResults.hidden = visible !== 0 || query === '';
        }
    });

    const modal = document.querySelector('[data-record-modal]');
    const modalTitle = document.querySelector('[data-record-modal-title]');
    const modalEyebrow = document.querySelector(
        '[data-record-modal-eyebrow]'
    );
    const description = document.querySelector(
        '[data-record-description]'
    );

    const form = document.querySelector('[data-record-form]');

    const methodInput = form?.querySelector(
        '[data-record-method]'
    );

    const typeInput = form?.querySelector(
        'select[name="type"]'
    );

    const nameInput = form?.querySelector(
        'input[name="name"]'
    );

    const contentInput = form?.querySelector(
        'input[name="content"]'
    );

    const ttlInput = form?.querySelector(
        'select[name="ttl"]'
    );

    const priorityInput = form?.querySelector(
        'input[name="priority"]'
    );

    const contentLabel = form?.querySelector(
        '[data-record-content-label]'
    );

    const priorityField = form?.querySelector(
        '[data-record-priority-field]'
    );

    const submitLabel = form?.querySelector(
        '[data-record-submit-label]'
    );

    const deleteTrigger = form?.querySelector(
        '[data-record-delete-trigger]'
    );

    const deleteForm = document.querySelector(
        '[data-record-delete-form]'
    );

    const recordFieldDefinitions = {
        A: {
            label: 'Endereço IPv4',
            placeholder: '192.0.2.10',
            priority: false,
        },
        AAAA: {
            label: 'Endereço IPv6',
            placeholder: '2001:db8::10',
            priority: false,
        },
        CNAME: {
            label: 'Nome de destino',
            placeholder: 'destino.exemplo.com.br',
            priority: false,
        },
        MX: {
            label: 'Servidor de e-mail',
            placeholder: 'mail.exemplo.com.br',
            priority: true,
        },
        NS: {
            label: 'Servidor de nomes',
            placeholder: 'ns1.exemplo.com.br',
            priority: false,
        },
        TXT: {
            label: 'Conteúdo TXT',
            placeholder: 'Texto do registro',
            priority: false,
        },
        PTR: {
            label: 'Nome de destino',
            placeholder: 'host.exemplo.com.br',
            priority: false,
        },
        SRV: {
            label: 'Destino do serviço',
            placeholder: 'servidor.exemplo.com.br',
            priority: true,
        },
        CAA: {
            label: 'Valor CAA',
            placeholder: '0 issue "letsencrypt.org"',
            priority: false,
        },
    };

    const updateRecordFields = () => {
        if (!typeInput || !contentInput || !contentLabel) {
            return;
        }

        const definition = recordFieldDefinitions[typeInput.value] || {
            label: 'Conteúdo',
            placeholder: 'Valor do registro',
            priority: false,
        };

        contentLabel.textContent = definition.label;
        contentInput.placeholder = definition.placeholder;

        if (priorityField) {
            priorityField.hidden = !definition.priority;
        }

        if (!definition.priority && priorityInput) {
            priorityInput.value = '';
        }
    };

    const openRecordModal = () => {
        if (!modal) {
            return;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-open-modal');

        window.setTimeout(() => {
            nameInput?.focus();
        }, 50);
    };

    const closeRecordModal = () => {
        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('has-open-modal');
    };

    const prepareCreateRecord = () => {
        if (!form) {
            return;
        }

        form.action = form.dataset.createUrl;
        methodInput.value = 'POST';

        modalTitle.textContent = 'Adicionar registro';
        modalEyebrow.textContent = 'Novo registro';
        description.textContent = 'Configure a nova entrada DNS.';
        submitLabel.textContent = 'Salvar registro';

        typeInput.value = 'A';
        nameInput.value = '';
        contentInput.value = '';
        ttlInput.value = '';
        priorityInput.value = '';

        deleteTrigger.hidden = true;
        deleteForm.action = '';

        updateRecordFields();
        openRecordModal();
    };

    const prepareEditRecord = (button) => {
        if (
            !form
            || !methodInput
            || !typeInput
            || !nameInput
            || !contentInput
            || !ttlInput
            || !priorityInput
            || !submitLabel
            || !deleteTrigger
            || !deleteForm
        ) {
            console.error(
                'DNS Center: campos do modal de registro não encontrados.'
            );

            return;
        }

        const record = {
            id: button.getAttribute('data-edit-id') || '',
            name: button.getAttribute('data-edit-name') || '',
            type: button.getAttribute('data-edit-type') || 'A',
            ttl: button.getAttribute('data-edit-ttl') || '',
            priority: button.getAttribute('data-edit-priority') || '',
            content: button.getAttribute('data-edit-content') || '',
            updateUrl: button.getAttribute('data-update-url') || '',
            deleteUrl: button.getAttribute('data-delete-url') || '',
        };

        console.debug('DNS Center: editando registro', record);

        form.action = record.updateUrl;
        methodInput.value = 'PUT';

        modalTitle.textContent = 'Editar registro';
        modalEyebrow.textContent = 'Registro DNS';
        description.textContent =
            'Atualize os dados desta entrada DNS.';
        submitLabel.textContent = 'Salvar alterações';

        typeInput.value = record.type;
        nameInput.value = record.name;
        contentInput.value = record.content;
        ttlInput.value = record.ttl;
        priorityInput.value = record.priority;

        deleteTrigger.hidden = false;
        deleteForm.action = record.deleteUrl;

        updateRecordFields();

        // Reaplica depois da atualização visual do tipo.
        nameInput.value = record.name;
        contentInput.value = record.content;
        ttlInput.value = record.ttl;
        priorityInput.value = record.priority;

        openRecordModal();
    };

    document.querySelectorAll('[data-record-modal-open]').forEach(
        (button) => {
            button.addEventListener('click', prepareCreateRecord);
        }
    );

    document.querySelectorAll('[data-record-edit]').forEach(
        (button) => {
            button.addEventListener('click', () => {
                prepareEditRecord(button);
            });
        }
    );

    document.querySelectorAll('[data-record-modal-close]').forEach(
        (button) => {
            button.addEventListener('click', closeRecordModal);
        }
    );

    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeRecordModal();
        }
    });

    typeInput?.addEventListener('change', updateRecordFields);

    deleteTrigger?.addEventListener('click', () => {
        if (
            deleteForm
            && window.confirm('Excluir este registro DNS?')
        ) {
            deleteForm.submit();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeRecordModal();
        }
    });

    updateRecordFields();
});
</script>

{{-- DNS-CENTER-FLASH-TOAST-START --}}
<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-flash-toast]').forEach((toast) => {
        const closeButton = toast.querySelector('[data-flash-toast-close]');
        const timeout = Number(toast.dataset.flashTimeout || 6000);

        let timer = null;
        let startedAt = 0;
        let remaining = timeout;

        const dismiss = () => {
            if (toast.classList.contains('is-leaving')) {
                return;
            }

            toast.classList.add('is-leaving');

            window.setTimeout(() => {
                toast.remove();
            }, 260);
        };

        const startTimer = () => {
            window.clearTimeout(timer);
            startedAt = Date.now();

            timer = window.setTimeout(dismiss, remaining);
        };

        const pauseTimer = () => {
            window.clearTimeout(timer);
            remaining -= Date.now() - startedAt;
            remaining = Math.max(remaining, 500);
        };

        closeButton?.addEventListener('click', dismiss);
        toast.addEventListener('mouseenter', pauseTimer);
        toast.addEventListener('mouseleave', startTimer);

        startTimer();
    });
});
</script>
{{-- DNS-CENTER-FLASH-TOAST-END --}}

@endsection
