@extends('layouts.app')

@section('title', 'Alterar senha')
@section(
    'body-class',
    auth()->user()->must_change_password
        ? 'auth-page'
        : 'app-page'
)

@section('content')
@php
    $isFirstAccess = auth()->user()->must_change_password;
@endphp

@if ($isFirstAccess)
    <main class="auth-simple-shell">
        <section class="auth-card auth-card-small">
            <p class="eyebrow">Primeiro acesso</p>

            <h1>Defina sua nova senha</h1>

            <p class="auth-description-small">
                Sua senha atual é temporária. Para continuar, crie uma senha
                definitiva com pelo menos 12 caracteres.
            </p>

            @include('auth.partials.password-form', [
                'isFirstAccess' => true,
            ])

            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button
                    type="submit"
                    class="button button-secondary password-logout"
                >
                    Sair da conta
                </button>
            </form>
        </section>
    </main>
@else
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
            <header class="topbar">
                <div>
                    <p class="eyebrow">Segurança da conta</p>
                    <h1>Alterar minha senha</h1>

                    <p class="page-description">
                        Confirme sua senha atual e defina uma nova senha
                        de acesso.
                    </p>
                </div>

                <div class="topbar-actions">
                    <x-account-menu />
                </div>
            </header>

            <section class="panel password-ircenter-card">
                <div class="compact-panel-heading">
                    <p class="eyebrow">Credenciais</p>
                    <h2>Nova senha</h2>
                </div>

                @include('auth.partials.password-form', [
                    'isFirstAccess' => false,
                ])
            </section>
        </main>
    </div>
@endif
@endsection
