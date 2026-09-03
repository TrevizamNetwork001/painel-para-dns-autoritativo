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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4">
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
@endsection
