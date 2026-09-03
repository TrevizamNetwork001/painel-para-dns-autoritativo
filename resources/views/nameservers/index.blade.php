@extends('layouts.app')

@section('title', 'Nameservers')
@section('body-class', 'app-page')

@section('content')
@php
    $selectedTab = request('tab', 'identities');

    if (! in_array($selectedTab, ['identities', 'profiles'], true)) {
        $selectedTab = 'identities';
    }

    $enabledIdentities = $identities->where('enabled', true);
@endphp

<div class="app-shell nameservers-page">
    <x-app-sidebar active="nameservers" />

    <main class="main-content nameservers-main">
        <header class="topbar nameservers-heading">
            <div>
                <p class="eyebrow">DNS autoritativo</p>
                <h1>Nameservers</h1>

                <p class="page-description">
                    Cadastre os hostnames de NS da empresa (ex.:
                    ns1.suaempresa.com.br) como identidades, agrupe duas
                    ou mais em um perfil e reaproveite esse perfil em
                    quantas zonas quiser. Toda zona nova exige um perfil,
                    que gera os registros NS — e o glue, quando
                    necessário — automaticamente.
                </p>
            </div>

            <div class="topbar-actions">
                @if ($canManage)
                    <button
                        type="button"
                        class="button button-secondary"
                        data-ns-profile-new
                    >
                        Novo perfil
                    </button>

                    <button
                        type="button"
                        class="button button-primary"
                        data-ns-identity-new
                    >
                        Nova identidade
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
                    data-flash-toast-close
                    aria-label="Fechar"
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
            <div class="alert alert-error nameservers-errors">
                <strong>Não foi possível concluir a operação.</strong>

                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="nameservers-summary">
            <article>
                <span>Identidades</span>
                <strong>{{ $identities->count() }}</strong>
                <small>
                    {{ $enabledIdentities->count() }} ativas
                </small>
            </article>

            <article>
                <span>Perfis</span>
                <strong>{{ $profiles->count() }}</strong>
                <small>
                    {{ $profiles->where('enabled', true)->count() }}
                    ativos
                </small>
            </article>

            <article>
                <span>Perfil padrão</span>
                <strong class="nameservers-summary-text">
                    {{ $profiles->firstWhere('is_default', true)?->name
                        ?? 'Não definido' }}
                </strong>
                <small>Usado como sugestão para novas zonas</small>
            </article>

            <article>
                <span>Externos</span>
                <strong>
                    {{ $identities->whereNull('dns_server_id')->count() }}
                </strong>
                <small>Sem vínculo com servidor físico</small>
            </article>
        </section>

        <div class="nameservers-tabs">
            <a
                href="{{ route('nameservers.index', ['tab' => 'identities']) }}"
                class="{{ $selectedTab === 'identities' ? 'is-active' : '' }}"
            >
                Identidades
                <span>{{ $identities->count() }}</span>
            </a>

            <a
                href="{{ route('nameservers.index', ['tab' => 'profiles']) }}"
                class="{{ $selectedTab === 'profiles' ? 'is-active' : '' }}"
            >
                Perfis
                <span>{{ $profiles->count() }}</span>
            </a>
        </div>

        @if ($selectedTab === 'identities')
            <section class="nameservers-panel">
                <header class="nameservers-panel-heading">
                    <div>
                        <p class="eyebrow">Identidade pública</p>
                        <h2>Nameservers cadastrados</h2>

                        <p>
                            O hostname público pode estar ligado a uma
                            máquina do inventário ou representar serviço
                            externo (ex.: NS de outro provedor). Uma
                            identidade só vira registro DNS quando entra
                            em um perfil usado por uma zona.
                        </p>
                    </div>

                    @if ($canManage)
                        <button
                            type="button"
                            class="button button-primary button-small"
                            data-ns-identity-new
                        >
                            Adicionar
                        </button>
                    @endif
                </header>

                @forelse ($identities as $identity)
                    <article class="nameserver-identity-row">
                        <div class="nameserver-identity-main">
                            <span class="nameserver-icon">NS</span>

                            <div>
                                <div class="nameserver-title-line">
                                    <strong>{{ $identity->name }}</strong>

                                    <span class="nameserver-state {{ $identity->enabled ? 'is-enabled' : 'is-disabled' }}">
                                        {{ $identity->enabled ? 'Ativo' : 'Desativado' }}
                                    </span>
                                </div>

                                <span>{{ $identity->hostname }}</span>

                                <small>
                                    IPv4:
                                    {{ $identity->ipv4_address ?: 'não informado' }}
                                    · IPv6:
                                    {{ $identity->ipv6_address ?: 'não informado' }}
                                </small>
                            </div>
                        </div>

                        <div class="nameserver-server-link">
                            <span>Infraestrutura</span>

                            @if ($identity->server)
                                <strong>{{ $identity->server->name }}</strong>
                                <small>{{ $identity->server->hostname }}</small>
                            @else
                                <strong>Serviço externo</strong>
                                <small>Sem servidor físico vinculado</small>
                            @endif
                        </div>

                        <div class="nameserver-usage">
                            <strong>{{ $identity->profiles_count }}</strong>
                            <span>perfil(is)</span>
                        </div>

                        @if ($canManage)
                            <div class="nameserver-actions">
                                <button
                                    type="button"
                                    class="button button-secondary button-small"
                                    data-ns-identity-edit
                                    data-id="{{ $identity->id }}"
                                    data-name="{{ $identity->name }}"
                                    data-hostname="{{ $identity->hostname }}"
                                    data-server-id="{{ $identity->dns_server_id }}"
                                    data-ipv4="{{ $identity->ipv4_address }}"
                                    data-ipv6="{{ $identity->ipv6_address }}"
                                    data-notes="{{ $identity->notes }}"
                                >
                                    Editar
                                </button>

                                <form
                                    method="POST"
                                    action="{{ route('nameservers.identity.status', $identity) }}"
                                >
                                    @csrf
                                    @method('PATCH')

                                    <button
                                        type="submit"
                                        class="button button-small {{ $identity->enabled
                                            ? 'button-danger-soft'
                                            : 'button-success-soft' }}"
                                    >
                                        {{ $identity->enabled
                                            ? 'Desativar'
                                            : 'Ativar' }}
                                    </button>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="nameservers-empty">
                        <span class="nameserver-icon">NS</span>
                        <h2>Nenhuma identidade cadastrada</h2>
                        <p>
                            Cadastre os hostnames públicos usados nas
                            delegações dos domínios — normalmente ns1 e
                            ns2 desta empresa. Depois, agrupe-os em um
                            perfil na aba Perfis para usá-los nas zonas.
                        </p>

                        @if ($canManage)
                            <button
                                type="button"
                                class="button button-primary"
                                data-ns-identity-new
                            >
                                Criar primeira identidade
                            </button>
                        @endif
                    </div>
                @endforelse
            </section>
        @else
            <section class="nameservers-panel">
                <header class="nameservers-panel-heading">
                    <div>
                        <p class="eyebrow">Conjuntos reutilizáveis</p>
                        <h2>Perfis de nameservers</h2>

                        <p>
                            Agrupe dois ou mais nameservers e mantenha a
                            ordem em que serão usados nas zonas (NS1,
                            NS2...). Toda zona nova exige um perfil — o
                            mesmo perfil pode ser reaproveitado em
                            quantos domínios esta empresa hospedar.
                        </p>
                    </div>

                    @if ($canManage)
                        <button
                            type="button"
                            class="button button-primary button-small"
                            data-ns-profile-new
                            @disabled($enabledIdentities->count() < 2)
                        >
                            Adicionar
                        </button>
                    @endif
                </header>

                @if ($enabledIdentities->count() < 2 && $canManage)
                    <div class="nameservers-warning">
                        Cadastre e mantenha ativas pelo menos duas
                        identidades antes de criar um perfil.
                    </div>
                @endif

                <div class="nameserver-profile-grid">
                    @forelse ($profiles as $profile)
                        <article class="nameserver-profile-card">
                            <header>
                                <div>
                                    <div class="nameserver-title-line">
                                        <h3>{{ $profile->name }}</h3>

                                        @if ($profile->is_default)
                                            <span class="nameserver-default">
                                                Padrão
                                            </span>
                                        @endif
                                    </div>

                                    <span class="nameserver-state {{ $profile->enabled ? 'is-enabled' : 'is-disabled' }}">
                                        {{ $profile->enabled ? 'Ativo' : 'Desativado' }}
                                    </span>
                                </div>

                                <strong>
                                    {{ $profile->identities->count() }} NS
                                </strong>
                            </header>

                            <ol class="nameserver-profile-list">
                                @foreach ($profile->identities as $identity)
                                    <li>
                                        <span>
                                            {{ $identity->pivot->position }}
                                        </span>

                                        <div>
                                            <strong>
                                                {{ $identity->hostname }}
                                            </strong>

                                            <small>
                                                {{ $identity->name }}
                                                ·
                                                {{ $identity->server?->name
                                                    ?? 'Externo' }}
                                            </small>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>

                            @if ($profile->notes)
                                <p class="nameserver-profile-notes">
                                    {{ $profile->notes }}
                                </p>
                            @endif

                            @if ($canManage)
                                <footer>
                                    <button
                                        type="button"
                                        class="button button-secondary button-small"
                                        data-ns-profile-edit
                                        data-id="{{ $profile->id }}"
                                        data-name="{{ $profile->name }}"
                                        data-default="{{ $profile->is_default ? '1' : '0' }}"
                                        data-notes="{{ $profile->notes }}"
                                        data-identities='@json($profile->identities->pluck("id")->values())'
                                    >
                                        Editar
                                    </button>

                                    <form
                                        method="POST"
                                        action="{{ route('nameservers.profile.status', $profile) }}"
                                    >
                                        @csrf
                                        @method('PATCH')

                                        <button
                                            type="submit"
                                            class="button button-small {{ $profile->enabled
                                                ? 'button-danger-soft'
                                                : 'button-success-soft' }}"
                                        >
                                            {{ $profile->enabled
                                                ? 'Desativar'
                                                : 'Ativar' }}
                                        </button>
                                    </form>
                                </footer>
                            @endif
                        </article>
                    @empty
                        <div class="nameservers-empty nameservers-empty-wide">
                            <span class="nameserver-icon">⇄</span>
                            <h2>Nenhum perfil cadastrado</h2>
                            <p>
                                Crie um perfil para reutilizar o mesmo
                                conjunto de NS em vários domínios desta
                                empresa — toda zona nova precisa de um
                                perfil escolhido para ser criada.
                            </p>
                        </div>
                    @endforelse
                </div>
            </section>
        @endif
    </main>
</div>

@if ($canManage)
    <div
        class="nameservers-modal"
        data-ns-identity-modal
        aria-hidden="true"
    >
        <button
            type="button"
            class="nameservers-modal-backdrop"
            data-ns-identity-close
            aria-label="Fechar"
        ></button>

        <section class="nameservers-modal-dialog">
            <header>
                <div>
                    <p class="eyebrow">Identidade pública</p>
                    <h2 data-ns-identity-title>Nova identidade</h2>
                    <p>
                        Nenhuma alteração será aplicada ao BIND.
                    </p>
                </div>

                <button
                    type="button"
                    class="nameservers-modal-close"
                    data-ns-identity-close
                >
                    ×
                </button>
            </header>

            <form
                method="POST"
                action="{{ route('nameservers.identity.store') }}"
                data-ns-identity-form
                data-store-action="{{ route('nameservers.identity.store') }}"
                data-update-template="{{ url('/nameservers/identidades/__ID__') }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="_method"
                    value="POST"
                    data-ns-identity-method
                >

                <div class="nameservers-form-grid">
                    <label class="form-field">
                        <span>Nome amigável</span>

                        <input
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="Ex.: NS Produção 01"
                            required
                        >
                    </label>

                    <label class="form-field">
                        <span>Hostname público</span>

                        <input
                            type="text"
                            name="hostname"
                            value="{{ old('hostname') }}"
                            placeholder="ns1.provedor.com.br"
                            required
                        >
                    </label>

                    <label class="form-field nameservers-full-field">
                        <span>Servidor de publicação (opcional)</span>

                        <select name="dns_server_id">
                            <option value="">
                                Nenhum — sem vínculo com servidor
                            </option>

                            @foreach ($servers as $server)
                                <option
                                    value="{{ $server->id }}"
                                    @selected(
                                        (string) old('dns_server_id')
                                            === (string) $server->id
                                    )
                                >
                                    {{ $server->name }}
                                    — {{ $server->hostname }}
                                    {{ $server->enabled
                                        ? ''
                                        : '(desativado)' }}
                                </option>
                            @endforeach
                        </select>

                        <small>
                            O vínculo identifica onde essa identidade poderá ser
                            publicada, sem restringir os registros DNS dos clientes.
                        </small>
                    </label>

                    <label class="form-field">
                        <span>IPv4 público</span>

                        <input
                            type="text"
                            name="ipv4_address"
                            value="{{ old('ipv4_address') }}"
                            placeholder="192.0.2.53"
                        >
                    </label>

                    <label class="form-field">
                        <span>IPv6 público</span>

                        <input
                            type="text"
                            name="ipv6_address"
                            value="{{ old('ipv6_address') }}"
                            placeholder="2001:db8::53"
                        >
                    </label>
                </div>

                <label class="form-field">
                    <span>Observações</span>

                    <textarea
                        name="notes"
                        rows="4"
                        placeholder="Informações administrativas opcionais"
                    >{{ old('notes') }}</textarea>
                </label>

                <footer>
                    <button
                        type="button"
                        class="button button-secondary"
                        data-ns-identity-close
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="button button-primary"
                        data-ns-identity-submit
                    >
                        Criar identidade
                    </button>
                </footer>
            </form>
        </section>
    </div>

    <div
        class="nameservers-modal"
        data-ns-profile-modal
        aria-hidden="true"
    >
        <button
            type="button"
            class="nameservers-modal-backdrop"
            data-ns-profile-close
            aria-label="Fechar"
        ></button>

        <section class="nameservers-modal-dialog nameservers-profile-dialog">
            <header>
                <div>
                    <p class="eyebrow">Perfil de nameservers</p>
                    <h2 data-ns-profile-title>Novo perfil</h2>
                    <p>
                        A ordem selecionada define NS1, NS2 e seguintes.
                        Esse perfil poderá ser escolhido por qualquer
                        zona desta empresa.
                    </p>
                </div>

                <button
                    type="button"
                    class="nameservers-modal-close"
                    data-ns-profile-close
                >
                    ×
                </button>
            </header>

            <form
                method="POST"
                action="{{ route('nameservers.profile.store') }}"
                data-ns-profile-form
                data-store-action="{{ route('nameservers.profile.store') }}"
                data-update-template="{{ url('/nameservers/perfis/__ID__') }}"
            >
                @csrf

                <input
                    type="hidden"
                    name="_method"
                    value="POST"
                    data-ns-profile-method
                >

                <label class="form-field">
                    <span>Nome do perfil</span>

                    <input
                        type="text"
                        name="name"
                        placeholder="Ex.: Produção compartilhada"
                        required
                    >
                </label>

                <div class="nameserver-profile-selector">
                    <div>
                        <strong>Nameservers</strong>
                        <small>
                            Selecione ao menos dois. Arraste não é
                            necessário: use os botões de ordem.
                        </small>
                    </div>

                    <div data-ns-profile-options>
                        @forelse ($enabledIdentities as $identity)
                            <label
                                class="nameserver-profile-option"
                                data-ns-profile-option
                                data-identity-id="{{ $identity->id }}"
                            >
                                <input
                                    type="checkbox"
                                    value="{{ $identity->id }}"
                                    data-ns-profile-checkbox
                                >

                                <span class="nameserver-profile-position">
                                    —
                                </span>

                                <span>
                                    <strong>{{ $identity->hostname }}</strong>

                                    <small>
                                        {{ $identity->name }}
                                        ·
                                        {{ $identity->server?->name
                                            ?? 'Sem vínculo com servidor' }}
                                    </small>
                                </span>

                                <span class="nameserver-order-actions">
                                    <button
                                        type="button"
                                        data-ns-move-up
                                        aria-label="Mover para cima"
                                    >
                                        ↑
                                    </button>

                                    <button
                                        type="button"
                                        data-ns-move-down
                                        aria-label="Mover para baixo"
                                    >
                                        ↓
                                    </button>
                                </span>
                            </label>
                        @empty
                            <div class="nameserver-profile-modal-empty">
                                <span class="nameserver-icon">NS</span>

                                <div>
                                    <strong>
                                        Nenhuma identidade disponível
                                    </strong>

                                    <small>
                                        Cadastre e ative pelo menos dois
                                        nameservers antes de criar um perfil.
                                    </small>
                                </div>

                                <button
                                    type="button"
                                    class="button button-secondary button-small"
                                    data-ns-profile-create-identity
                                >
                                    Criar identidade
                                </button>
                            </div>
                        @endforelse
                    </div>

                    <div data-ns-profile-hidden-inputs></div>
                </div>

                <label class="nameserver-default-option">
                    <input
                        type="checkbox"
                        name="is_default"
                        value="1"
                    >

                    <span>
                        <strong>Usar como perfil padrão</strong>
                        <small>
                            Substitui o perfil padrão atual da empresa.
                        </small>
                    </span>
                </label>

                <label class="form-field">
                    <span>Observações</span>

                    <textarea
                        name="notes"
                        rows="3"
                        placeholder="Finalidade administrativa do perfil"
                    ></textarea>
                </label>

                <footer>
                    <button
                        type="button"
                        class="button button-secondary"
                        data-ns-profile-close
                    >
                        Cancelar
                    </button>

                    <button
                        type="submit"
                        class="button button-primary"
                        data-ns-profile-submit
                        @disabled($enabledIdentities->count() < 2)
                    >
                        Criar perfil
                    </button>
                </footer>
            </form>
        </section>
    </div>
@endif

<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const openModal = (modal) => {
        modal?.classList.add('is-open');
        modal?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('nameservers-modal-open');
    };

    const closeModal = (modal) => {
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');

        if (!document.querySelector('.nameservers-modal.is-open')) {
            document.body.classList.remove('nameservers-modal-open');
        }
    };

    const identityModal = document.querySelector(
        '[data-ns-identity-modal]'
    );

    const identityForm = document.querySelector(
        '[data-ns-identity-form]'
    );

    const resetIdentityForm = () => {
        if (!identityForm) {
            return;
        }

        identityForm.reset();
        identityForm.action = identityForm.dataset.storeAction;

        identityForm.querySelector(
            '[data-ns-identity-method]'
        ).value = 'POST';

        document.querySelector(
            '[data-ns-identity-title]'
        ).textContent = 'Nova identidade';

        document.querySelector(
            '[data-ns-identity-submit]'
        ).textContent = 'Criar identidade';
    };

    document.querySelectorAll(
        '[data-ns-identity-new]'
    ).forEach((button) => {
        button.addEventListener('click', () => {
            resetIdentityForm();
            openModal(identityModal);

            window.setTimeout(() => {
                identityForm?.querySelector(
                    'input[name="name"]'
                )?.focus();
            }, 50);
        });
    });

    document.querySelectorAll(
        '[data-ns-identity-edit]'
    ).forEach((button) => {
        button.addEventListener('click', () => {
            resetIdentityForm();

            const id = button.dataset.id;

            identityForm.action = identityForm
                .dataset.updateTemplate
                .replace('__ID__', id);

            identityForm.querySelector(
                '[data-ns-identity-method]'
            ).value = 'PUT';

            identityForm.elements.name.value =
                button.dataset.name || '';

            identityForm.elements.hostname.value =
                button.dataset.hostname || '';

            identityForm.elements.dns_server_id.value =
                button.dataset.serverId || '';

            identityForm.elements.ipv4_address.value =
                button.dataset.ipv4 || '';

            identityForm.elements.ipv6_address.value =
                button.dataset.ipv6 || '';

            identityForm.elements.notes.value =
                button.dataset.notes || '';

            document.querySelector(
                '[data-ns-identity-title]'
            ).textContent = 'Editar identidade';

            document.querySelector(
                '[data-ns-identity-submit]'
            ).textContent = 'Salvar alterações';

            openModal(identityModal);
        });
    });

    document.querySelectorAll(
        '[data-ns-identity-close]'
    ).forEach((button) => {
        button.addEventListener(
            'click',
            () => closeModal(identityModal)
        );
    });

    const profileModal = document.querySelector(
        '[data-ns-profile-modal]'
    );

    const profileForm = document.querySelector(
        '[data-ns-profile-form]'
    );

    const optionsContainer = document.querySelector(
        '[data-ns-profile-options]'
    );

    const hiddenContainer = document.querySelector(
        '[data-ns-profile-hidden-inputs]'
    );

    const selectedOptions = () => {
        if (!optionsContainer) {
            return [];
        }

        return Array.from(
            optionsContainer.querySelectorAll(
                '[data-ns-profile-option]'
            )
        ).filter((option) => {
            return option.querySelector(
                '[data-ns-profile-checkbox]'
            ).checked;
        });
    };

    const refreshProfileOrder = () => {
        if (!optionsContainer || !hiddenContainer) {
            return;
        }

        hiddenContainer.innerHTML = '';

        optionsContainer.querySelectorAll(
            '[data-ns-profile-option]'
        ).forEach((option) => {
            const checkbox = option.querySelector(
                '[data-ns-profile-checkbox]'
            );

            option.classList.toggle(
                'is-selected',
                checkbox.checked
            );

            option.querySelector(
                '.nameserver-profile-position'
            ).textContent = '—';
        });

        selectedOptions().forEach((option, index) => {
            option.querySelector(
                '.nameserver-profile-position'
            ).textContent = String(index + 1);

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'identity_ids[]';
            input.value = option.dataset.identityId;

            hiddenContainer.appendChild(input);
        });
    };

    const resetProfileForm = () => {
        if (!profileForm || !optionsContainer) {
            return;
        }

        profileForm.reset();
        profileForm.action = profileForm.dataset.storeAction;

        profileForm.querySelector(
            '[data-ns-profile-method]'
        ).value = 'POST';

        optionsContainer.querySelectorAll(
            '[data-ns-profile-checkbox]'
        ).forEach((checkbox) => {
            checkbox.checked = false;
        });

        document.querySelector(
            '[data-ns-profile-title]'
        ).textContent = 'Novo perfil';

        document.querySelector(
            '[data-ns-profile-submit]'
        ).textContent = 'Criar perfil';

        refreshProfileOrder();
    };

    document.querySelectorAll(
        '[data-ns-profile-new]'
    ).forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) {
                return;
            }

            resetProfileForm();
            openModal(profileModal);
        });
    });

    document.querySelectorAll(
        '[data-ns-profile-edit]'
    ).forEach((button) => {
        button.addEventListener('click', () => {
            resetProfileForm();

            const id = button.dataset.id;

            profileForm.action = profileForm
                .dataset.updateTemplate
                .replace('__ID__', id);

            profileForm.querySelector(
                '[data-ns-profile-method]'
            ).value = 'PUT';

            profileForm.elements.name.value =
                button.dataset.name || '';

            profileForm.elements.notes.value =
                button.dataset.notes || '';

            profileForm.elements.is_default.checked =
                button.dataset.default === '1';

            let identityIds = [];

            try {
                identityIds = JSON.parse(
                    button.dataset.identities || '[]'
                ).map(String);
            } catch (error) {
                identityIds = [];
            }

            identityIds.forEach((identityId) => {
                const option = optionsContainer.querySelector(
                    `[data-identity-id="${identityId}"]`
                );

                if (!option) {
                    return;
                }

                option.querySelector(
                    '[data-ns-profile-checkbox]'
                ).checked = true;

                optionsContainer.appendChild(option);
            });

            refreshProfileOrder();

            document.querySelector(
                '[data-ns-profile-title]'
            ).textContent = 'Editar perfil';

            document.querySelector(
                '[data-ns-profile-submit]'
            ).textContent = 'Salvar alterações';

            openModal(profileModal);
        });
    });

    document.querySelectorAll(
        '[data-ns-profile-close]'
    ).forEach((button) => {
        button.addEventListener(
            'click',
            () => closeModal(profileModal)
        );
    });

    document.querySelectorAll(
        '[data-ns-profile-create-identity]'
    ).forEach((button) => {
        button.addEventListener('click', () => {
            closeModal(profileModal);
            resetIdentityForm();
            openModal(identityModal);

            window.setTimeout(() => {
                identityForm?.querySelector(
                    'input[name="name"]'
                )?.focus();
            }, 50);
        });
    });

    optionsContainer?.addEventListener('change', (event) => {
        if (
            event.target.matches(
                '[data-ns-profile-checkbox]'
            )
        ) {
            refreshProfileOrder();
        }
    });

    optionsContainer?.addEventListener('click', (event) => {
        const upButton = event.target.closest(
            '[data-ns-move-up]'
        );

        const downButton = event.target.closest(
            '[data-ns-move-down]'
        );

        if (!upButton && !downButton) {
            return;
        }

        const option = event.target.closest(
            '[data-ns-profile-option]'
        );

        const checkbox = option?.querySelector(
            '[data-ns-profile-checkbox]'
        );

        if (!option || !checkbox?.checked) {
            return;
        }

        const selected = selectedOptions();
        const index = selected.indexOf(option);

        if (upButton && index > 0) {
            optionsContainer.insertBefore(
                option,
                selected[index - 1]
            );
        }

        if (
            downButton
            && index >= 0
            && index < selected.length - 1
        ) {
            optionsContainer.insertBefore(
                selected[index + 1],
                option
            );
        }

        refreshProfileOrder();
    });

    profileForm?.addEventListener('submit', (event) => {
        refreshProfileOrder();

        if (selectedOptions().length < 2) {
            event.preventDefault();

            window.alert(
                'Selecione pelo menos dois nameservers.'
            );
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        closeModal(identityModal);
        closeModal(profileModal);
    });

    @if ($errors->any())
        const errorBag = @json(array_keys($errors->messages()));

        const profileError = errorBag.some((field) => {
            return field === 'identity_ids'
                || field.startsWith('identity_ids.')
                || field === 'is_default';
        });

        if (profileError) {
            openModal(profileModal);
        } else {
            openModal(identityModal);
        }
    @endif
});
</script>
@endsection
