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

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <span class="nav-section">Conta</span>

            <a href="{{ route('profile.edit') }}"
               class="nav-item is-active">
                <span class="nav-icon">●</span>
                Meu perfil
            </a>
        </nav>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Conta</p>
                <h1>Meu perfil</h1>
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

        <section class="profile-grid">
            <article class="panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Identidade</p>
                        <h2>Informações da conta</h2>
                    </div>
                </div>

                <div class="profile-summary">
                    <x-user-avatar
                        :user="$user"
                        size="large"
                    />

                    <div>
                        <strong>{{ $user->name }}</strong>
                        <span>{{ $user->email }}</span>
                        <small>
                            {{ $user->currentOrganization?->name }}
                        </small>
                    </div>
                </div>

                <a
                    href="{{ route('password.change') }}"
                    class="button button-secondary profile-password-link"
                >
                    Alterar senha
                </a>
            </article>

            <article class="panel profile-avatar-panel">
                <div class="panel-header">
                    <div>
                        <p class="eyebrow">Personalização</p>
                        <h2>Escolher avatar</h2>
                    </div>
                </div>

                <p class="profile-help">
                    Selecione um avatar. Para voltar à inicial do nome,
                    escolha a primeira opção.
                </p>

                <form
                    method="POST"
                    action="{{ route('profile.avatar.update') }}"
                >
                    @csrf
                    @method('PUT')

                    <div class="avatar-picker">
                        @php
                            $initialPreviewUser = clone $user;
                            $initialPreviewUser->avatar_key = null;
                        @endphp

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
                                    size="large"
                                />

                                <small>Inicial</small>
                            </span>
                        </label>

                        @foreach ($avatars as $avatar)
                            @php
                                $previewUser = clone $user;
                                $previewUser->avatar_key = $avatar;
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
                                        size="large"
                                    />

                                    <small>
                                        {{ ucfirst($avatar) }}
                                    </small>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <button
                        type="submit"
                        class="button button-primary profile-save-avatar"
                    >
                        Salvar avatar
                    </button>
                </form>
            </article>
        </section>
    </main>
</div>
@endsection
