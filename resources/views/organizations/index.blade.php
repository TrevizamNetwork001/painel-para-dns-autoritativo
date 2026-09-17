@extends('layouts.app')

@section('title', 'Empresas')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell">
    <x-app-sidebar active="organizations" />

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Plataforma</p>
                <h1>Empresas</h1>

                <p class="page-description">
                    Cadastre uma empresa (tenant) nova e o primeiro
                    administrador com acesso a ela. Depois de criar, você
                    pode deslogar e entrar com essas credenciais para ver
                    a aplicação como esse cliente veria.
                </p>
            </div>

            <div class="topbar-actions">
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
            <div class="alert alert-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <article class="panel">
            <div class="compact-panel-heading">
                <p class="eyebrow">Nova empresa</p>
                <h2>Cadastrar empresa e administrador</h2>
            </div>

            <form method="POST" action="{{ route('organizations.store') }}">
                @csrf

                <label class="form-field">
                    <span>Nome da empresa</span>

                    <input
                        type="text"
                        name="organization_name"
                        value="{{ old('organization_name') }}"
                        placeholder="Ex.: Cliente Alpha"
                        required
                        autofocus
                    >
                </label>

                <h3>Primeiro usuário (administrador da empresa)</h3>

                <label class="form-field">
                    <span>Nome completo</span>

                    <input
                        type="text"
                        name="name"
                        value="{{ old('name') }}"
                        autocomplete="name"
                        required
                    >
                </label>

                <label class="form-field">
                    <span>E-mail</span>

                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="email"
                        required
                    >
                </label>

                <label class="form-field">
                    <span>Senha</span>

                    <span class="password-field">
                        <input
                            id="new_org_password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle
                            aria-controls="new_org_password"
                        >
                            Mostrar
                        </button>
                    </span>
                </label>

                <label class="form-field">
                    <span>Confirmar senha</span>

                    <span class="password-field">
                        <input
                            id="new_org_password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle
                            aria-controls="new_org_password_confirmation"
                        >
                            Mostrar
                        </button>
                    </span>
                </label>

                <footer>
                    <button type="submit" class="button button-primary">
                        Criar empresa
                    </button>
                </footer>
            </form>
        </article>

        <article class="panel">
            <div class="compact-panel-heading">
                <p class="eyebrow">Tenants</p>
                <h2>Empresas cadastradas</h2>
            </div>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Status</th>
                            <th>Usuários</th>
                            <th>Criada em</th>
                            <th></th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($organizations as $organization)
                            <tr>
                                <td>
                                    <strong>{{ $organization->name }}</strong>
                                    <span>{{ $organization->slug }}</span>
                                </td>

                                <td>
                                    {{ $organization->status === 'active'
                                        ? 'Ativa'
                                        : $organization->status }}
                                </td>

                                <td>{{ $organization->users_count }}</td>

                                <td>
                                    {{ $organization->created_at?->format('d/m/Y') }}
                                </td>

                                <td>
                                    <div class="agent-actions">
                                        @unless ($organization->is_default)
                                            <form
                                                method="POST"
                                                action="{{ route('organizations.status', $organization) }}"
                                                onsubmit="return confirm(
                                                    '{{ $organization->status === 'active'
                                                        ? 'Desativar esta empresa? Os usuários dela perdem acesso ao painel imediatamente.'
                                                        : 'Reativar esta empresa?' }}'
                                                )"
                                            >
                                                @csrf
                                                @method('PATCH')

                                                <button
                                                    type="submit"
                                                    class="button button-secondary button-small"
                                                >
                                                    {{ $organization->status === 'active'
                                                        ? 'Desativar'
                                                        : 'Reativar' }}
                                                </button>
                                            </form>

                                            <button
                                                type="button"
                                                class="button button-danger-soft button-small"
                                                data-delete-org-open
                                                data-delete-org-name="{{ $organization->name }}"
                                                data-delete-org-url="{{ route('organizations.destroy', $organization) }}"
                                            >
                                                Excluir
                                            </button>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    Nenhuma empresa cadastrada.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </article>
    </main>
</div>

<div
    class="domains-modal-backdrop"
    data-delete-org-modal
    aria-hidden="true"
>
    <section
        class="domains-modal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="delete-org-modal-title"
    >
        <header class="domains-modal-header">
            <div>
                <p class="eyebrow">Ação irreversível</p>
                <h2 id="delete-org-modal-title">Excluir empresa</h2>

                <p>
                    Remove permanentemente a empresa
                    <strong data-delete-org-name-target></strong>
                    e tudo vinculado a ela: usuários, servidores,
                    credenciais de agente, zonas e registros DNS.
                    Nenhum comando é enviado aos servidores BIND — o
                    que já estiver configurado neles continua
                    rodando até uma ação manual no próprio servidor.
                    Esta ação não pode ser desfeita.
                </p>
            </div>

            <button
                type="button"
                class="domains-modal-close"
                data-delete-org-close
                aria-label="Fechar"
            >
                ×
            </button>
        </header>

        <form
            method="POST"
            data-delete-org-form
            class="record-modal-form"
        >
            @csrf
            @method('DELETE')

            <label class="domain-field">
                <span>Digite o nome exato da empresa para confirmar</span>

                <input
                    type="text"
                    name="confirmation"
                    required
                    autocomplete="off"
                    data-delete-org-input
                >
            </label>

            <footer class="domains-modal-footer">
                <button
                    type="button"
                    class="button button-secondary"
                    data-delete-org-close
                >
                    Cancelar
                </button>

                <button
                    type="submit"
                    class="button button-danger-soft"
                >
                    Excluir permanentemente
                </button>
            </footer>
        </form>
    </section>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-delete-org-modal]');
    const form = modal?.querySelector('[data-delete-org-form]');
    const input = modal?.querySelector('[data-delete-org-input]');
    const nameTarget = modal?.querySelector('[data-delete-org-name-target]');

    const close = () => {
        modal?.classList.remove('is-open');
        modal?.setAttribute('aria-hidden', 'true');
        if (input) input.value = '';
    };

    document.querySelectorAll('[data-delete-org-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (form) form.action = button.dataset.deleteOrgUrl;
            if (nameTarget) nameTarget.textContent = button.dataset.deleteOrgName;
            modal?.classList.add('is-open');
            modal?.setAttribute('aria-hidden', 'false');
            input?.focus();
        });
    });

    modal?.querySelectorAll('[data-delete-org-close]').forEach((button) => {
        button.addEventListener('click', close);
    });

    form?.addEventListener('submit', (event) => {
        if (input && input.value !== nameTarget?.textContent) {
            event.preventDefault();
            input.focus();
        }
    });
});
</script>
@endsection
