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
<div class="app-shell dashboard-v2">
    <x-app-sidebar active="zones" />

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
                        Escolha a identidade DNS pública e os servidores que
                        receberão a publicação desta zona.
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

                    <section class="domains-ns-section">
                        <div class="domains-section-heading">
                            <div>
                                <strong>Identidade DNS pública</strong>

                                <p>
                                    O perfil define os hostnames NS publicados,
                                    o servidor principal do SOA e eventuais
                                    registros glue.
                                </p>
                            </div>

                            <a
                                href="{{ route('nameservers.index') }}"
                                class="domains-inline-link"
                            >
                                Gerenciar nameservers
                            </a>
                        </div>

                        @if ($nameserverProfiles->isNotEmpty())
                            <label class="domains-field domains-field-full">
                                <span>Perfil de nameservers</span>

                                <select
                                    name="dns_nameserver_profile_id"
                                    required
                                    data-nameserver-profile-select
                                >
                                    <option value="">
                                        Selecione o perfil
                                    </option>

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
                                            data-profile-default="{{ $profile->is_default ? '1' : '0' }}"
                                            data-profile-identities='{{ Illuminate\Support\Js::encode($profileIdentities) }}'
                                            @selected(
                                                (int) old(
                                                    'dns_nameserver_profile_id',
                                                    $nameserverProfiles
                                                        ->firstWhere(
                                                            'is_default',
                                                            true
                                                        )
                                                        ?->id
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
                                    A zona receberá automaticamente os registros
                                    NS definidos neste perfil.
                                </small>
                            </label>

                            <div
                                class="domains-profile-preview"
                                data-nameserver-profile-preview
                            >
                                <div class="domains-profile-preview-header">
                                    <div>
                                        <span>Nameservers publicados</span>

                                        <strong data-profile-preview-name>
                                            Selecione um perfil
                                        </strong>
                                    </div>

                                    <span
                                        class="domains-profile-count"
                                        data-profile-preview-count
                                    >
                                        0 NS
                                    </span>
                                </div>

                                <div
                                    class="domains-profile-identities"
                                    data-profile-preview-identities
                                ></div>
                            </div>
                        @else
                            <div class="domains-profile-empty">
                                <div aria-hidden="true">⇄</div>

                                <div>
                                    <strong>
                                        Nenhum perfil de nameservers disponível
                                    </strong>

                                    <p>
                                        Crie pelo menos duas identidades e um
                                        perfil antes de adicionar um domínio.
                                    </p>
                                </div>

                                <a
                                    href="{{ route('nameservers.index') }}"
                                    class="button button-secondary button-small"
                                >
                                    Criar perfil
                                </a>
                            </div>
                        @endif
                    </section>

                    <section class="domains-publication-section">
                        <div class="domains-section-heading">
                            <div>
                                <strong>Servidores de publicação</strong>

                                <p>
                                    Estes servidores recebem os artefatos da
                                    zona. Eles não definem os nomes NS públicos.
                                </p>
                            </div>
                        </div>

                        <div class="domains-modal-grid">
                            <label class="domains-field">
                                <span>Servidor de publicação principal</span>

                                <select name="primary_server_id" required>
                                    <option value="">
                                        Selecione o servidor
                                    </option>

                                    @foreach ($servers as $server)
                                        <option
                                            value="{{ $server->id }}"
                                            @selected(
                                                (int) old(
                                                    'primary_server_id'
                                                ) === (int) $server->id
                                            )
                                        >
                                            {{ $server->name }}
                                            — {{ $server->hostname }}
                                        </option>
                                    @endforeach
                                </select>
                            </label>

                            <label class="domains-field">
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
                                                    'secondary_server_id'
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
                    </section>

                    <div class="domains-info-box">
                        <span aria-hidden="true">i</span>

                        <div>
                            <strong>Configuração automática</strong>

                            <p>
                                O MNAME do SOA, os registros NS e os registros
                                glue serão derivados do perfil selecionado.
                                Nenhum arquivo será aplicado ao BIND durante o
                                cadastro.
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
                                    value=""
                                    placeholder="Derivado do perfil"
                                    data-soa-mname
                                    readonly
                                    aria-readonly="true"
                                >

                                <small>
                                    Preenchido automaticamente com o primeiro
                                    nameserver do perfil selecionado.
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
                        @disabled($nameserverProfiles->isEmpty())
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
    const profileSelect = modal?.querySelector(
        '[data-nameserver-profile-select]'
    );
    const profilePreview = modal?.querySelector(
        '[data-nameserver-profile-preview]'
    );
    const profilePreviewName = modal?.querySelector(
        '[data-profile-preview-name]'
    );
    const profilePreviewCount = modal?.querySelector(
        '[data-profile-preview-count]'
    );
    const profilePreviewIdentities = modal?.querySelector(
        '[data-profile-preview-identities]'
    );
    const soaMnameInput = modal?.querySelector('[data-soa-mname]');
    const soaRnameInput = modal?.querySelector('[data-soa-rname]');

    let soaRnameWasEdited = Boolean(soaRnameInput?.value.trim());

    const normalizeDomain = (value) => {
        return value
            .trim()
            .toLocaleLowerCase('pt-BR')
            .replace(/^https?:\/\//, '')
            .replace(/^www\./, '')
            .replace(/\/$/, '');
    };

    const profileIdentities = () => {
        if (!profileSelect) {
            return [];
        }

        const option = profileSelect.options[
            profileSelect.selectedIndex
        ];

        if (!option?.dataset.profileIdentities) {
            return [];
        }

        try {
            return JSON.parse(option.dataset.profileIdentities);
        } catch (error) {
            return [];
        }
    };

    const updateNameserverProfilePreview = () => {
        const identities = profileIdentities();
        const option = profileSelect?.options[
            profileSelect.selectedIndex
        ];

        if (soaMnameInput) {
            soaMnameInput.value = identities[0]?.hostname || '';
        }

        if (profilePreviewName) {
            profilePreviewName.textContent =
                option?.dataset.profileName || 'Selecione um perfil';
        }

        if (profilePreviewCount) {
            profilePreviewCount.textContent =
                `${identities.length} NS`;
        }

        if (profilePreviewIdentities) {
            profilePreviewIdentities.replaceChildren();

            identities.forEach((identity, index) => {
                const item = document.createElement('div');
                item.className = 'domains-profile-identity';

                const order = document.createElement('span');
                order.textContent = String(index + 1);

                const content = document.createElement('div');

                const hostname = document.createElement('strong');
                hostname.textContent = identity.hostname || 'Sem hostname';

                const metadata = document.createElement('small');

                const addresses = [
                    identity.ipv4,
                    identity.ipv6,
                ].filter(Boolean);

                metadata.textContent = addresses.length
                    ? addresses.join(' · ')
                    : 'Nameserver externo ou sem glue';

                content.append(hostname, metadata);
                item.append(order, content);

                profilePreviewIdentities.append(item);
            });

            if (identities.length === 0) {
                const empty = document.createElement('p');
                empty.className = 'domains-profile-preview-empty';
                empty.textContent =
                    'Selecione um perfil para visualizar os nameservers.';

                profilePreviewIdentities.append(empty);
            }
        }

        profilePreview?.classList.toggle(
            'has-profile',
            identities.length > 0
        );
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

        updateNameserverProfilePreview();
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

    profileSelect?.addEventListener('change', () => {
        updateNameserverProfilePreview();
    });

    soaRnameInput?.addEventListener('input', () => {
        soaRnameWasEdited = soaRnameInput.value.trim() !== '';
    });

    updateNameserverProfilePreview();
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
