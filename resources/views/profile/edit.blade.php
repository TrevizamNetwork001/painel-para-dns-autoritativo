@extends('layouts.app')

@section('title', 'Meu perfil')
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

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <a href="{{ route('dashboard') }}" class="nav-item">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="#" class="nav-item">
                <span class="nav-icon">◈</span>
                Servidores
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon">◎</span>
                Zonas
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon">≋</span>
                Registros DNS
            </a>

            <span class="nav-section">Operações</span>

            <a href="#" class="nav-item">
                <span class="nav-icon">⌁</span>
                Atividades
            </a>

            @if (
                auth()->user()->is_platform_admin
                || auth()->user()->roleForOrganization(
                    auth()->user()->current_organization_id
                ) === 'organization_admin'
            )
                <a href="{{ route('users.index') }}" class="nav-item">
                    <span class="nav-icon">●</span>
                    Usuários
                </a>
            @endif

            <a href="#" class="nav-item">
                <span class="nav-icon">⚙</span>
                Configurações
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar profile-topbar">
            <div>
                <p class="eyebrow">Conta pessoal</p>
                <h1>Meu perfil</h1>

                <p class="page-description">
                    Consulte seus dados e personalize sua identificação
                    na plataforma.
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

        @php
            $role = auth()->user()->roleForOrganization(
                auth()->user()->current_organization_id
            );

            $roleLabel = match ($role) {
                'organization_admin' => 'Administrador',
                'operator' => 'Operador',
                'viewer' => 'Visualizador',
                default => auth()->user()->is_platform_admin
                    ? 'Administrador da plataforma'
                    : 'Usuário',
            };

            $initialPreviewUser = clone $user;
            $initialPreviewUser->avatar_key = null;
        @endphp

        <section class="profile-ircenter-layout">
            <div class="profile-account-column">
                <article class="panel profile-identity-card">
                    <x-user-avatar
                        :user="$user"
                        size="large"
                    />

                    <div class="profile-identity-main">
                        <strong>{{ $user->name }}</strong>

                        <span class="badge badge-success">
                            {{ mb_strtoupper($roleLabel) }}
                        </span>
                    </div>
                </article>

                <article class="panel profile-data-card">
                    <div class="compact-panel-heading">
                        <p class="eyebrow">Conta</p>
                        <h2>Dados da conta</h2>
                    </div>

                    <dl class="profile-data-list">
                        <div>
                            <dt>Nome</dt>
                            <dd>{{ $user->name }}</dd>
                        </div>

                        <div>
                            <dt>E-mail de acesso</dt>
                            <dd>{{ $user->email }}</dd>
                        </div>

                        <div>
                            <dt>Empresa</dt>
                            <dd>
                                {{ $user->currentOrganization?->name
                                    ?? 'Plataforma' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Perfil</dt>
                            <dd>{{ $roleLabel }}</dd>
                        </div>

                        <div>
                            <dt>Último acesso</dt>
                            <dd>
                                {{ $user->last_login_at
                                    ? $user->last_login_at->format(
                                        'd/m/Y H:i'
                                    )
                                    : 'Primeiro acesso' }}
                            </dd>
                        </div>

                        <div>
                            <dt>Senha alterada em</dt>
                            <dd>
                                {{ $user->password_changed_at
                                    ? $user->password_changed_at->format(
                                        'd/m/Y H:i'
                                    )
                                    : 'Nunca alterada' }}
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>

            <article class="panel profile-avatar-workspace">
                <div class="compact-panel-heading">
                    <p class="eyebrow">Personalização</p>
                    <h2>Escolha seu avatar</h2>
                </div>

                <form
                    method="POST"
                    action="{{ route('profile.avatar.update') }}"
                >
                    @csrf
                    @method('PUT')

                    <div class="avatar-picker-compact">
                        <label class="avatar-option">
                            <input
                                type="radio"
                                name="avatar_key"
                                value=""
                                @checked(blank($user->avatar_key))
                            >

                            <span class="avatar-option-card">
                                <x-user-avatar
                                    :user="$initialPreviewUser"
                                    size="medium"
                                />

                                <small>Inicial do nome</small>
                            </span>
                        </label>

                        @foreach ($avatars as $avatar)
                            @php
                                $previewUser = clone $user;
                                $previewUser->avatar_key = $avatar;

                                $avatarLabel = match ($avatar) {
                                    'astronaut' => 'Astronauta',
                                    'robot' => 'Robô',
                                    'wolf' => 'Lobo',
                                    'fox' => 'Raposa',
                                    'eagle' => 'Águia',
                                    'owl' => 'Coruja',
                                    'lion' => 'Leão',
                                    'tiger' => 'Tigre',
                                    'panda' => 'Panda',
                                    'dolphin' => 'Golfinho',
                                    'rocket' => 'Foguete',
                                    'planet' => 'Planeta',
                                    'mountain' => 'Montanha',
                                    'cloud' => 'Nuvem',
                                    'cactus' => 'Cacto',
                                    'camera' => 'Câmera',
                                    'gamepad' => 'Gamepad',
                                    default => ucfirst($avatar),
                                };
                            @endphp

                            <label class="avatar-option">
                                <input
                                    type="radio"
                                    name="avatar_key"
                                    value="{{ $avatar }}"
                                    @checked($user->avatar_key === $avatar)
                                >

                                <span class="avatar-option-card">
                                    <x-user-avatar
                                        :user="$previewUser"
                                        size="medium"
                                    />

                                    <small>{{ $avatarLabel }}</small>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div class="profile-avatar-actions">
                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Salvar avatar
                        </button>
                    </div>
                </form>
            </article>
        </section>
    </main>
</div>
@endsection
