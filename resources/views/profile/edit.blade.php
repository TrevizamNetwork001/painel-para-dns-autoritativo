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

        {{-- DNS CENTER SIDEBAR CONTEXT START --}}
@php
    $canManageUsers = auth()->check() && auth()->user()->can('manage-users');
@endphp
{{-- DNS CENTER SIDEBAR CONTEXT END --}}

<nav class="sidebar-nav" aria-label="Navegação principal">
            <a
                href="{{ route('dashboard') }}"
                class="nav-item is-active"
            >
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect
                            x="4"
                            y="4"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="14"
                            y="4"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="4"
                            y="14"
                            width="6"
                            height="6"
                            rx="1"
                        />
                        <rect
                            x="14"
                            y="14"
                            width="6"
                            height="6"
                            rx="1"
                        />
                    </svg>
                </span>

                Dashboard
            </a>

            <span class="nav-section">DNS autoritativo</span>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <rect
                            x="4"
                            y="3"
                            width="16"
                            height="7"
                            rx="2"
                        />
                        <rect
                            x="4"
                            y="14"
                            width="16"
                            height="7"
                            rx="2"
                        />
                        <path d="M8 6.5h.01M8 17.5h.01M12 6.5h5M12 17.5h5" />
                    </svg>
                </span>

                Servidores
            </a>

            <a href="{{ route('servers.index') }}" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="9" />
                        <path
                            d="M3 12h18M12 3c2.4 2.5 3.6 5.5 3.6 9
                              S14.4 18.5 12 21
                              C9.6 18.5 8.4 15.5 8.4 12
                              S9.6 5.5 12 3Z"
                        />
                    </svg>
                </span>

                Zonas
            </a>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 7h4l2-3 4 6 2-3h4
                               M4 17h4l2 3 4-6 2 3h4"
                        />
                    </svg>
                </span>

                Registros DNS
            </a>

            <span class="nav-section">Operações</span>

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <path
                            d="M4 12h3l2-6 4 12 2-6h5"
                        />
                    </svg>
                </span>

                Atividades
            </a>

            @if ($canManageUsers)
                <a href="{{ route('users.index') }}" class="nav-item">
                    <span class="nav-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none">
                            <circle cx="9" cy="8" r="3" />
                            <path d="M3.5 20a5.5 5.5 0 0 1 11 0" />
                            <path
                                d="M16 5.5a3 3 0 0 1 0 5.5
                                   M17 14a5 5 0 0 1 3.5 4.8"
                            />
                        </svg>
                    </span>

                    Usuários
                </a>
            @endif

            <a href="#" class="nav-item">
                <span class="nav-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="3" />
                        <path
                            d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1
                               -2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3
                               1.7 1.7 0 0 0-1 1.6V21h-4v-.1
                               a1.7 1.7 0 0 0-1-1.6
                               1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17
                               l.1-.1a1.7 1.7 0 0 0 .3-1.9
                               A1.7 1.7 0 0 0 3 14H3v-4h.1
                               a1.7 1.7 0 0 0 1.6-1
                               1.7 1.7 0 0 0-.3-1.9L4.3 7
                               7 4.2l.1.1A1.7 1.7 0 0 0 9 4.6
                               1.7 1.7 0 0 0 10 3V3h4v.1
                               a1.7 1.7 0 0 0 1 1.6
                               1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7
                               l-.1.1a1.7 1.7 0 0 0-.3 1.9
                               1.7 1.7 0 0 0 1.6 1H21v4h-.1
                               a1.7 1.7 0 0 0-1.5 1Z"
                        />
                    </svg>
                </span>

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
                            <dt>Perfil</dt>
                            <dd>{{ $roleLabel }}</dd>
                        </div>

                        <div>
                            <dt>Empresa</dt>
                            <dd>
                                {{ $user->currentOrganization?->name
                                    ?? 'Plataforma' }}
                            </dd>
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
                                    'ogre' => 'Ogro verde',
                                    'donkey' => 'Jegue',
                                    'ceo' => 'CEO',
                                    'anta' => 'Anta',
                                    'peixe' => 'Peixe',
                                    'carrasco' => 'Carrasco',
                                    'engenheiro_obra' => 'Engenheiro de obra',

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
