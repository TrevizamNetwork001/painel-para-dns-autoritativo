@extends('layouts.app')

@section('title', 'Domínios')
@section('body-class', 'app-page')

@php
    $user = auth()->user();
    $organizationId = (int) $user->current_organization_id;

    $canManageDomains = $user->is_platform_admin
        || $user->roleForOrganization($organizationId) === 'organization_admin';

    $statusLabels = [
        'draft' => 'Rascunho',
        'ready' => 'Pronto',
        'published' => 'Publicado',
        'disabled' => 'Desativado',
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
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="{{ route('servers.index') }}" class="nav-item">
                <span class="nav-icon">▤</span>
                Servidores
            </a>

            <a href="{{ route('nameservers.index') }}" class="nav-item">
                <span class="nav-icon">⇄</span>
                Nameservers
            </a>


            <a href="{{ route('zones.index') }}" class="nav-item is-active">
                <span class="nav-icon">◎</span>
                Domínios
            </a>

            @if (Route::has('users.index'))
                <a href="{{ route('users.index') }}" class="nav-item">
                    <span class="nav-icon">●</span>
                    Usuários
                </a>
            @endif
        </nav>
    </aside>

    <main class="main-content domains-page">
        <header class="topbar domains-topbar">
            <div>
                <p class="eyebrow">DNS autoritativo</p>
                <h1>Domínios</h1>

                <p class="page-description">
                    Gerencie domínios, registros DNS e publicação nos servidores autoritativos.
                </p>
            </div>

            <div class="topbar-actions">
                @if ($canManageDomains)
                    <button
                        type="button"
                        class="button button-primary domains-add-button"
                        data-domain-modal-open
                    >
                        <span aria-hidden="true">＋</span>
                        Adicionar domínio
                    </button>
                @endif

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
                <strong>Não foi possível adicionar o domínio.</strong>

                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="domains-toolbar">
            <div class="domains-search">
                <span aria-hidden="true">⌕</span>

                <input
                    type="search"
                    placeholder="Pesquisar domínios"
                    aria-label="Pesquisar domínios"
                    data-domains-search
                >
            </div>

            <div class="domains-toolbar-meta">
                <strong>{{ $zones->count() }}</strong>
                domínio(s)
            </div>
        </section>

        <section class="domains-panel">
            <div class="domains-table-heading">
                <div>
                    <h2>Domínios cadastrados</h2>

                    <p>
                        Selecione um domínio para administrar seus registros DNS.
                    </p>
                </div>

                @if ($canManageDomains)
                    <button
                        type="button"
                        class="button button-secondary button-small"
                        data-domain-modal-open
                    >
                        Adicionar
                    </button>
                @endif
            </div>

            @forelse ($zones as $zone)
                @php
                    $primary = $zone->servers->first(
                        fn ($server) => $server->pivot?->role === 'primary'
                    );

                    $secondary = $zone->servers->first(
                        fn ($server) => $server->pivot?->role === 'secondary'
                    );
                @endphp

                @if ($loop->first)
                    <div class="domains-table" data-domains-list>
                        <div class="domains-table-header">
                            <span>Domínio</span>
                            <span>Primary</span>
                            <span>Secondary</span>
                            <span>Registros</span>
                            <span>Estado</span>
                            <span></span>
                        </div>
                @endif

                <a
                    href="{{ route('zones.show', $zone) }}"
                    class="domains-table-row"
                    data-domain-row
                    data-domain-name="{{ mb_strtolower($zone->name) }}"
                >
                    <div class="domains-name-cell">
                        <span class="domains-domain-icon" aria-hidden="true">◎</span>

                        <div>
                            <strong>{{ $zone->name }}</strong>

                            <small>
                                Serial {{ $zone->serial }}
                                · versão {{ $zone->version }}
                            </small>
                        </div>
                    </div>

                    <div class="domains-server-cell">
                        <strong>{{ $primary?->name ?? 'Não definido' }}</strong>
                        <small>{{ $primary?->hostname ?? 'Selecione o primary' }}</small>
                    </div>

                    <div class="domains-server-cell">
                        <strong>{{ $secondary?->name ?? 'Sem secondary' }}</strong>
                        <small>{{ $secondary?->hostname ?? 'Opcional' }}</small>
                    </div>

                    <div class="domains-record-count">
                        {{ $zone->records_count }}
                    </div>

                    <div>
                        <span class="domains-status domains-status-{{ $zone->status }}">
                            {{ $statusLabels[$zone->status] ?? $zone->status }}
                        </span>
                    </div>

                    <div class="domains-open-cell">
                        Abrir
                        <span aria-hidden="true">›</span>
                    </div>
                </a>

                @if ($loop->last)
                    </div>
                @endif
            @empty
                <div class="domains-empty-state">
                    <div class="domains-empty-icon" aria-hidden="true">◎</div>

                    <h2>Nenhum domínio cadastrado</h2>

                    <p>
                        Adicione o primeiro domínio para configurar seus servidores
                        autoritativos e registros DNS.
                    </p>

                    @if ($canManageDomains)
                        <button
                            type="button"
                            class="button button-primary"
                            data-domain-modal-open
                        >
                            Adicionar primeiro domínio
                        </button>
                    @endif
                </div>
            @endforelse

            <div class="domains-no-results" data-domains-no-results hidden>
                Nenhum domínio corresponde à pesquisa.
            </div>
        </section>
    </main>
</div>

@if ($canManageDomains)
    <div
        class="domains-modal-backdrop"
        data-domain-modal
        aria-hidden="true"
    >
        <section
            class="domains-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="domains-modal-title"
        >
            <header class="domains-modal-header">
                <div>
                    <p class="eyebrow">Novo domínio</p>
                    <h2 id="domains-modal-title">Adicionar domínio</h2>

                    <p>
                        Crie a zona autoritativa e vincule os servidores DNS.
                    </p>
                </div>

                <button
                    type="button"
                    class="domains-modal-close"
                    data-domain-modal-close
                    aria-label="Fechar"
                >
                    ×
                </button>
            </header>

            <form
                method="POST"
                action="{{ route('zones.store') }}"
                class="domains-create-form"
            >
                @csrf

                <div class="domains-modal-section">
                    <label class="domains-field domains-field-full">
                        <span>Nome do domínio</span>

                        <input
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="exemplo.com.br"
                            autocomplete="off"
                            required
                            autofocus
                        >

                        <small>
                            Informe somente o domínio, sem “http://” ou “www”.
                        </small>
                    </label>

                    <input type="hidden" name="kind" value="primary">

                    <div class="domains-modal-grid">
                        <label class="domains-field">
                            <span>Servidor primary</span>

                            <select name="primary_server_id" required>
                                <option value="">Selecione o servidor</option>

                                @foreach ($servers as $server)
                                    <option
                                        value="{{ $server->id }}"
                                        data-server-hostname="{{ $server->hostname }}"
                                        @selected(
                                            (int) old('primary_server_id')
                                            === (int) $server->id
                                        )
                                    >
                                        {{ $server->name }}
                                        — {{ $server->hostname }}
                                    </option>
                                @endforeach
                            </select>
                        </label>

                        <label class="domains-field">
                            <span>Servidor secondary</span>

                            <select name="secondary_server_id">
                                <option value="">Sem secondary</option>

                                @foreach ($servers as $server)
                                    <option
                                        value="{{ $server->id }}"
                                        @selected(
                                            (int) old('secondary_server_id')
                                            === (int) $server->id
                                        )
                                    >
                                        {{ $server->name }}
                                        — {{ $server->hostname }}
                                    </option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    <div class="domains-info-box">
                        <span aria-hidden="true">i</span>

                        <div>
                            <strong>Configuração automática</strong>

                            <p>
                                TTL e parâmetros SOA serão preenchidos com os
                                padrões seguros do DNS Center.
                            </p>
                        </div>
                    </div>
                </div>

                <details class="domains-advanced">
                    <summary>
                        <span>Configurações avançadas</span>
                        <small>TTL e parâmetros SOA</small>
                    </summary>

                    <div class="domains-advanced-content">
                        <div class="domains-modal-grid">
                            <label class="domains-field">
                                <span>TTL padrão</span>

                                <input
                                    type="number"
                                    name="default_ttl"
                                    value="{{ old('default_ttl', 3600) }}"
                                    min="60"
                                    required
                                >
                            </label>

                            <label class="domains-field">
                                <span>Servidor principal do SOA</span>

                                <input
                                    type="text"
                                    name="soa_mname"
                                    value="{{ old('soa_mname') }}"
                                    placeholder="Preenchido pelo servidor Primary"
                                    data-soa-mname
                                    required
                                >

                                <small>
                                    Será usado o hostname do servidor Primary selecionado.
                                </small>
                            </label>

                            <label class="domains-field">
                                <span>Contato responsável</span>

                                <input
                                    type="text"
                                    name="soa_rname"
                                    value="{{ old('soa_rname') }}"
                                    placeholder="hostmaster.dominio.com.br"
                                    data-soa-rname
                                    required
                                >

                                <small>
                                    Equivale ao e-mail hostmaster@domínio no formato do SOA.
                                </small>
                            </label>

                            <label class="domains-field">
                                <span>Refresh</span>

                                <input
                                    type="number"
                                    name="soa_refresh"
                                    value="{{ old('soa_refresh', 3600) }}"
                                    min="60"
                                    required
                                >
                            </label>

                            <label class="domains-field">
                                <span>Retry</span>

                                <input
                                    type="number"
                                    name="soa_retry"
                                    value="{{ old('soa_retry', 900) }}"
                                    min="60"
                                    required
                                >
                            </label>

                            <label class="domains-field">
                                <span>Expire</span>

                                <input
                                    type="number"
                                    name="soa_expire"
                                    value="{{ old('soa_expire', 1209600) }}"
                                    min="3600"
                                    required
                                >
                            </label>

                            <label class="domains-field">
                                <span>Minimum</span>

                                <input
                                    type="number"
                                    name="soa_minimum"
                                    value="{{ old('soa_minimum', 300) }}"
                                    min="60"
                                    required
                                >
                            </label>
                        </div>

                        <label class="domains-field domains-field-full">
                            <span>Observações</span>

                            <textarea
                                name="notes"
                                rows="3"
                                placeholder="Observações internas sobre o domínio"
                            >{{ old('notes') }}</textarea>
                        </label>
                    </div>
                </details>

                <section class="domains-reverse-preview">
                    <div>
                        <strong>DNS reverso</strong>

                        <p>
                            O reverso IPv4 e IPv6 será configurado em uma etapa
                            separada, depois da criação do domínio.
                        </p>
                    </div>

                    <span>Opcional</span>
                </section>

                <footer class="domains-modal-footer">
                    <button
                        type="button"
                        class="button button-secondary"
                        data-domain-modal-close
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="button button-primary"
                    >
                        Adicionar domínio
                    </button>
                </footer>
            </form>
        </section>
    </div>
@endif

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-domain-modal]');
    const openButtons = document.querySelectorAll('[data-domain-modal-open]');
    const closeButtons = document.querySelectorAll('[data-domain-modal-close]');
    const search = document.querySelector('[data-domains-search]');
    const rows = document.querySelectorAll('[data-domain-row]');
    const noResults = document.querySelector('[data-domains-no-results]');

    const domainInput = modal?.querySelector('input[name="name"]');
    const primarySelect = modal?.querySelector(
        'select[name="primary_server_id"]'
    );
    const soaMnameInput = modal?.querySelector('[data-soa-mname]');
    const soaRnameInput = modal?.querySelector('[data-soa-rname]');

    let soaMnameWasEdited = Boolean(soaMnameInput?.value.trim());
    let soaRnameWasEdited = Boolean(soaRnameInput?.value.trim());

    const normalizeDomain = (value) => {
        return value
            .trim()
            .toLocaleLowerCase('pt-BR')
            .replace(/^https?:\/\//, '')
            .replace(/^www\./, '')
            .replace(/\/$/, '');
    };

    const updateSoaMname = () => {
        if (!primarySelect || !soaMnameInput || soaMnameWasEdited) {
            return;
        }

        const option = primarySelect.options[primarySelect.selectedIndex];
        soaMnameInput.value = option?.dataset.serverHostname || '';
    };

    const updateSoaRname = () => {
        if (!domainInput || !soaRnameInput || soaRnameWasEdited) {
            return;
        }

        const domain = normalizeDomain(domainInput.value);

        soaRnameInput.value = domain
            ? `hostmaster.${domain}`
            : '';
    };

    const openModal = () => {
        if (!modal) {
            return;
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('has-open-modal');

        updateSoaMname();
        updateSoaRname();

        window.setTimeout(() => {
            domainInput?.focus();
        }, 50);
    };

    const closeModal = () => {
        if (!modal) {
            return;
        }

        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('has-open-modal');
    };

    domainInput?.addEventListener('input', () => {
        updateSoaRname();
    });

    primarySelect?.addEventListener('change', () => {
        updateSoaMname();
    });

    soaMnameInput?.addEventListener('input', () => {
        soaMnameWasEdited = soaMnameInput.value.trim() !== '';
    });

    soaRnameInput?.addEventListener('input', () => {
        soaRnameWasEdited = soaRnameInput.value.trim() !== '';
    });

    updateSoaMname();
    updateSoaRname();

    openButtons.forEach((button) => {
        button.addEventListener('click', openModal);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal?.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('pt-BR');
        let visible = 0;

        rows.forEach((row) => {
            const matches = row.dataset.domainName.includes(query);
            row.hidden = !matches;

            if (matches) {
                visible++;
            }
        });

        if (noResults) {
            noResults.hidden = visible !== 0 || query === '';
        }
    });

    @if ($errors->any())
        openModal();
    @endif
});
</script>

{{-- DNS-CENTER-FLASH-TOAST-START --}}
<script>
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
