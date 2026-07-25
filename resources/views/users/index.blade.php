@extends('layouts.app')

@section('title', 'Usuários')
@section('body-class', 'app-page')

@section('content')
@php
    $currentUser = auth()->user();
@endphp

<div class="app-shell users-v2">
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

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <a href="{{ route('dashboard') }}" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect x="4" y="4" width="6" height="6" rx="1" />
                        <rect x="14" y="4" width="6" height="6" rx="1" />
                        <rect x="4" y="14" width="6" height="6" rx="1" />
                        <rect x="14" y="14" width="6" height="6" rx="1" />
                    </svg>
                </span>

                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect x="4" y="3" width="16" height="7" rx="2" />
                        <rect x="4" y="14" width="16" height="7" rx="2" />
                        <path d="M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5" />
                    </svg>
                </span>

                Servidores
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" />
                        <path d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9S14.4 18.5 12 21C9.6 18.5 8.4 15.5 8.4 12S9.6 5.5 12 3Z" />
                    </svg>
                </span>

                Zonas
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M4 7h4l2-3 4 6 2-3h4M4 17h4l2 3 4-6 2 3h4" />
                    </svg>
                </span>

                Registros DNS
            </a>

            <span class="nav-section">Operações</span>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path d="M4 12h3l2-6 4 12 2-6h5" />
                    </svg>
                </span>

                Atividades
            </a>

            <a
                href="{{ route('users.index') }}"
                class="nav-item is-active"
            >
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="9" cy="8" r="3" />
                        <path d="M3.5 20a5.5 5.5 0 0 1 11 0" />
                        <path d="M16 5.5a3 3 0 0 1 0 5.5M17 14a5 5 0 0 1 3.5 4.8" />
                    </svg>
                </span>

                Usuários
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="3" />
                        <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3A1.7 1.7 0 0 0 14 21h-4a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14v-4a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10 3h4a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9A1.7 1.7 0 0 0 21 10v4a1.7 1.7 0 0 0-1.6 1Z" />
                    </svg>
                </span>

                Configurações
            </a>
        </nav>
    </aside>

    <main class="main-content users-main">
        <header class="topbar users-heading">
            <div>
                <p class="eyebrow">Administração</p>
                <h1>Usuários</h1>

                <p class="page-description">
                    Controle acessos, papéis e estados dos usuários
                    vinculados à empresa.
                </p>
            </div>

            <div class="topbar-actions">
                <button
                    type="button"
                    class="button button-primary"
                    data-user-modal-open
                >
                    Novo usuário
                </button>

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
            <div
                class="alert alert-error"
                data-open-user-modal-on-error
            >
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="users-filter-panel">
            <div class="users-filter-grid">
                <label class="users-search-field">
                    <span class="sr-only">Pesquisar usuários</span>

                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="11" cy="11" r="7" />
                        <path d="m20 20-4-4" />
                    </svg>

                    <input
                        type="search"
                        placeholder="Nome ou e-mail..."
                        data-users-search
                    >
                </label>

                <label>
                    <span class="sr-only">Filtrar por papel</span>

                    <select data-users-role-filter>
                        <option value="">Todos os papéis</option>
                        <option value="organization_admin">
                            Administrador
                        </option>
                        <option value="operator">Operador</option>
                        <option value="viewer">Visualizador</option>
                    </select>
                </label>

                <label>
                    <span class="sr-only">Filtrar por status</span>

                    <select data-users-status-filter>
                        <option value="">Todos os estados</option>
                        <option value="active">Ativos</option>
                        <option value="inactive">Inativos</option>
                    </select>
                </label>

                <button
                    type="button"
                    class="button button-secondary"
                    data-users-clear-filters
                >
                    Limpar
                </button>
            </div>
        </section>

        <section class="users-table-panel">
            <div class="users-table-heading">
                <div>
                    <p class="eyebrow">Equipe</p>
                    <h2>Usuários cadastrados</h2>
                </div>

                <span class="users-count-badge">
                    {{ $users->count() }} usuário(s)
                </span>
            </div>

            <div class="users-table-scroll">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Papel</th>
                            <th>Estado</th>
                            <th>Último acesso</th>
                            <th>Ações</th>
                        </tr>
                    </thead>

                    <tbody data-users-table-body>
                        @forelse ($users as $user)
                            @php
                                $membership = $user->organizations
                                    ->firstWhere('id', $organization->id);

                                $role = $membership?->pivot?->role
                                    ?? 'viewer';

                                $membershipStatus =
                                    $membership?->pivot?->status
                                    ?? $user->status;

                                $roleLabel = match ($role) {
                                    'organization_admin' => 'Administrador',
                                    'operator' => 'Operador',
                                    'viewer' => 'Visualizador',
                                    default => $role,
                                };

                                $isCurrentUser = $currentUser->is($user);

                                $isProtectedPlatformAdmin =
                                    $user->is_platform_admin
                                    && ! $currentUser->is_platform_admin;

                                $canModify =
                                    ! $isCurrentUser
                                    && ! $isProtectedPlatformAdmin;
                            @endphp

                            <tr
                                data-user-row
                                data-user-search="{{ mb_strtolower(
                                    $user->name.' '.$user->email
                                ) }}"
                                data-user-role="{{ $role }}"
                                data-user-status="{{ $membershipStatus }}"
                            >
                                <td>
                                    <div class="users-user-cell">
                                        <x-user-avatar
                                            :user="$user"
                                            size="small"
                                        />

                                        <div>
                                            <strong>{{ $user->name }}</strong>
                                            <span>{{ $user->email }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    @if ($canModify)
                                        <form
                                            method="POST"
                                            action="{{ route(
                                                'users.role',
                                                $user
                                            ) }}"
                                            class="users-inline-form"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <select
                                                name="role"
                                                aria-label="Papel de {{ $user->name }}"
                                                onchange="this.form.submit()"
                                            >
                                                <option
                                                    value="organization_admin"
                                                    @selected(
                                                        $role ===
                                                        'organization_admin'
                                                    )
                                                >
                                                    Administrador
                                                </option>

                                                <option
                                                    value="operator"
                                                    @selected(
                                                        $role === 'operator'
                                                    )
                                                >
                                                    Operador
                                                </option>

                                                <option
                                                    value="viewer"
                                                    @selected(
                                                        $role === 'viewer'
                                                    )
                                                >
                                                    Visualizador
                                                </option>
                                            </select>
                                        </form>
                                    @else
                                        <span class="users-role-badge">
                                            {{ $roleLabel }}
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    <span
                                        @class([
                                            'status-badge',
                                            'status-active' =>
                                                $membershipStatus ===
                                                'active',
                                            'status-inactive' =>
                                                $membershipStatus !==
                                                'active',
                                        ])
                                    >
                                        {{ $membershipStatus === 'active'
                                            ? 'Ativo'
                                            : 'Inativo' }}
                                    </span>
                                </td>

                                <td>
                                    <span class="users-last-access">
                                        {{ $user->last_login_at
                                            ? $user->last_login_at->format(
                                                'd/m/Y H:i'
                                            )
                                            : 'Primeiro acesso' }}
                                    </span>
                                </td>

                                <td>
                                    @if ($isCurrentUser)
                                        <span class="users-protected-note">
                                            Sessão atual
                                        </span>
                                    @elseif ($isProtectedPlatformAdmin)
                                        <span class="users-protected-note">
                                            Administrador da plataforma
                                        </span>
                                    @else
                                        <form
                                            method="POST"
                                            action="{{ route(
                                                'users.status',
                                                $user
                                            ) }}"
                                        >
                                            @csrf

                                            <button
                                                type="submit"
                                                @class([
                                                    'button',
                                                    'button-small',
                                                    'button-danger-soft' =>
                                                        $membershipStatus ===
                                                        'active',
                                                    'button-success-soft' =>
                                                        $membershipStatus !==
                                                        'active',
                                                ])
                                            >
                                                {{ $membershipStatus ===
                                                    'active'
                                                    ? 'Desativar'
                                                    : 'Ativar' }}
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <div class="users-empty-state">
                                        Nenhum usuário cadastrado.
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="users-filter-empty" data-users-filter-empty hidden>
                Nenhum usuário corresponde aos filtros selecionados.
            </div>
        </section>
    </main>
</div>

<div
    class="users-modal"
    data-user-modal
    aria-hidden="true"
>
    <button
        type="button"
        class="users-modal-backdrop"
        data-user-modal-close
        aria-label="Fechar"
    ></button>

    <section
        class="users-modal-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="new-user-title"
    >
        <header class="users-modal-header">
            <div>
                <p class="eyebrow">Novo acesso</p>
                <h2 id="new-user-title">Criar usuário</h2>

                <p>
                    O usuário receberá uma senha temporária e deverá
                    substituí-la no primeiro acesso.
                </p>
            </div>

            <button
                type="button"
                class="users-modal-close"
                data-user-modal-close
                aria-label="Fechar modal"
            >
                ×
            </button>
        </header>

        <form
            method="POST"
            action="{{ route('users.store') }}"
            class="users-create-form"
        >
            @csrf

            <label class="form-field">
                <span>Nome completo</span>

                <input
                    type="text"
                    name="name"
                    value="{{ old('name') }}"
                    autocomplete="name"
                    required
                    autofocus
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
                <span>Papel</span>

                <select name="role" required>
                    <option
                        value="organization_admin"
                        @selected(
                            old('role') === 'organization_admin'
                        )
                    >
                        Administrador
                    </option>

                    <option
                        value="operator"
                        @selected(old('role') === 'operator')
                    >
                        Operador
                    </option>

                    <option
                        value="viewer"
                        @selected(old('role') === 'viewer')
                    >
                        Visualizador
                    </option>
                </select>
            </label>

            <div class="users-password-grid">
                <label class="form-field">
                    <span>Senha temporária</span>

                    <span class="password-field">
                        <input
                            id="temporary_password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle
                            aria-controls="temporary_password"
                        >
                            Mostrar
                        </button>
                    </span>
                </label>

                <label class="form-field">
                    <span>Confirmar senha</span>

                    <span class="password-field">
                        <input
                            id="temporary_password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle
                            aria-controls="temporary_password_confirmation"
                        >
                            Mostrar
                        </button>
                    </span>
                </label>
            </div>

            <p class="users-password-help">
                A senha temporária pode ser simples. No primeiro acesso,
                o usuário deverá criar uma senha definitiva segura.
            </p>

            <footer class="users-modal-actions">
                <button
                    type="button"
                    class="button button-secondary"
                    data-user-modal-close
                >
                    Cancelar
                </button>

                <button type="submit" class="button button-primary">
                    Criar usuário
                </button>
            </footer>
        </form>
    </section>
</div>
@endsection
