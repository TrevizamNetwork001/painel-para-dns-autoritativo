@extends('layouts.app')

@section('title', 'Usuários')
@section('body-class', 'app-page')

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

            <span class="nav-section">Administração</span>

            <a href="{{ route('users.index') }}"
               class="nav-item is-active">
                <span class="nav-icon">●</span>
                Usuários
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Administração</p>
                <h1>Usuários</h1>
            </div>

            <div class="topbar-actions">
                <a
                    href="{{ route('dashboard') }}"
                    class="button button-secondary"
                >
                    Voltar
                </a>

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

        <section class="users-grid">
            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Novo acesso</p>
                        <h2>Criar usuário</h2>
                    </div>
                </div>

                <form method="POST"
                      action="{{ route('users.store') }}"
                      class="auth-form panel-form">
                    @csrf

                    <label class="form-field">
                        <span>Nome completo</span>
                        <input
                            type="text"
                            name="name"
                            value="{{ old('name') }}"
                            required
                        >
                    </label>

                    <label class="form-field">
                        <span>E-mail</span>
                        <input
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            required
                        >
                    </label>

                    <label class="form-field">
                        <span>Papel</span>
                        <select name="role" required>
                            <option value="organization_admin">
                                Administrador
                            </option>
                            <option value="operator">
                                Operador
                            </option>
                            <option value="viewer">
                                Visualizador
                            </option>
                        </select>
                    </label>

                    <label class="form-field">
                        <span>Senha temporária</span>
                        <input
                            type="password"
                            name="password"
                            required
                        >
                    </label>

                    <label class="form-field">
                        <span>Confirmar senha</span>
                        <input
                            type="password"
                            name="password_confirmation"
                            required
                        >
                    </label>

                    <button class="button button-primary" type="submit">
                        Criar usuário
                    </button>
                </form>
            </article>

            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Equipe</p>
                        <h2>Usuários cadastrados</h2>
                    </div>

                    <span class="badge badge-neutral">
                        {{ $users->total() }} usuário(s)
                    </span>
                </div>

                <div class="table-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Usuário</th>
                                <th>Papel</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse ($users as $user)
                                <tr>
                                    <td>
                                        <strong>{{ $user->name }}</strong>
                                        <span>{{ $user->email }}</span>
                                    </td>

                                    <td>
                                        @if (
                                            auth()->user()->is($user)
                                            || (
                                                $user->is_platform_admin
                                                && ! auth()->user()->is_platform_admin
                                            )
                                        )
                                            <span class="badge badge-neutral">
                                                {{
                                                    match ($user->pivot->role) {
                                                        'organization_admin' => 'Administrador',
                                                        'operator' => 'Operador',
                                                        'viewer' => 'Visualizador',
                                                        default => $user->pivot->role,
                                                    }
                                                }}
                                            </span>
                                        @else
                                            <form method="POST"
                                                  action="{{ route('users.role', $user) }}">
                                                @csrf
                                                @method('PATCH')

                                                <select
                                                    name="role"
                                                    onchange="this.form.submit()"
                                                >
                                                    @foreach ($roles as $role)
                                                        <option
                                                            value="{{ $role }}"
                                                            @selected($user->pivot->role === $role)
                                                        >
                                                            {{
                                                                match ($role) {
                                                                    'organization_admin' => 'Administrador',
                                                                    'operator' => 'Operador',
                                                                    'viewer' => 'Visualizador',
                                                                    default => $role,
                                                                }
                                                            }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </form>
                                        @endif
                                    </td>

                                    <td>
                                        <span class="badge {{
                                            $user->status === 'active'
                                                ? 'badge-success'
                                                : 'badge-neutral'
                                        }}">
                                            {{
                                                $user->status === 'active'
                                                    ? 'Ativo'
                                                    : 'Inativo'
                                            }}
                                        </span>
                                    </td>

                                    <td>
                                        @if (
                                            ! auth()->user()->is($user)
                                            && (
                                                ! $user->is_platform_admin
                                                || auth()->user()->is_platform_admin
                                            )
                                        )
                                            <form method="POST"
                                                  action="{{ route('users.status', $user) }}">
                                                @csrf

                                                <button
                                                    class="button button-secondary"
                                                    type="submit"
                                                >
                                                    {{ $user->status === 'active'
                                                        ? 'Desativar'
                                                        : 'Ativar' }}
                                                </button>
                                            </form>
                                        @else
                                            <span class="table-note">
                                                @if (auth()->user()->is($user))
                                                    Sessão atual
                                                @elseif ($user->is_platform_admin)
                                                    Administrador da plataforma
                                                @else
                                                    Protegido
                                                @endif
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4">
                                        Nenhum usuário cadastrado.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $users->links() }}
            </article>
        </section>
    </main>
</div>
@endsection
