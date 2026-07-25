@extends('layouts.app')

@section('title', 'Entrar')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-shell">
    <section class="auth-brand">
        <div class="brand-mark" aria-hidden="true">
            <span>DNS</span>
        </div>

        <div class="auth-brand-content">
            <p class="eyebrow">Trevizam Network</p>

            <h1>DNS Center</h1>

            <p class="auth-description">
                Plataforma centralizada para administração de DNS autoritativo,
                políticas, zonas e operações de infraestrutura.
            </p>

            <div class="auth-status">
                <span class="status-dot"></span>
                Ambiente protegido por HTTPS
            </div>
        </div>
    </section>

    <section class="auth-panel">
        <div class="auth-card">
            <div class="auth-card-header">
                <div>
                    <p class="eyebrow">Acesso administrativo</p>
                    <h2>Bem-vindo</h2>
                    <p>Entre com suas credenciais para continuar.</p>
                </div>

                <button
                    class="theme-toggle"
                    type="button"
                    data-theme-toggle
                    aria-label="Alternar tema"
                    title="Alternar tema"
                >
                    <span data-theme-icon>◐</span>
                </button>
            </div>

            @if (session('status'))
                <div class="alert alert-success" role="status">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="alert alert-error" role="alert">
                    <strong>Não foi possível entrar.</strong>

                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="auth-form">
                @csrf

                <label class="form-field">
                    <span>E-mail</span>

                    <input
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="username"
                        inputmode="email"
                        required
                        autofocus
                        placeholder="nome@empresa.com.br"
                    >
                </label>

                <label class="form-field">
                    <span>Senha</span>

                    <span class="password-field">
                        <input
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            placeholder="Digite sua senha"
                        >

                        <button
                            type="button"
                            class="password-toggle"
                            data-password-toggle
                            aria-controls="password"
                            aria-label="Mostrar senha"
                        >
                            Mostrar
                        </button>
                    </span>
                </label>

                <div class="form-options">
                    <label class="checkbox-field">
                        <input
                            type="checkbox"
                            name="remember"
                            value="1"
                            @checked(old('remember'))
                        >
                        <span>Lembrar de mim</span>
                    </label>

                    <a href="{{ route('password.request') }}">
                        Esqueci minha senha
                    </a>
                </div>

                <button type="submit" class="button button-primary">
                    Entrar no DNS Center
                </button>
            </form>

            <footer class="auth-footer">
                <span>Acesso restrito a usuários autorizados.</span>
            </footer>
        </div>
    </section>
</main>
@endsection
