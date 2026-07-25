@extends('layouts.app')

@section('title', 'Alterar senha')
@section('body-class', 'auth-page')

@section('content')
@php
    $isFirstAccess = auth()->user()->must_change_password;
@endphp

<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <div class="password-page-header">
            <div>
                <p class="eyebrow">
                    {{ $isFirstAccess
                        ? 'Primeiro acesso'
                        : 'Segurança da conta' }}
                </p>

                <h1>
                    {{ $isFirstAccess
                        ? 'Defina sua nova senha'
                        : 'Alterar senha' }}
                </h1>

                <p class="auth-description-small">
                    @if ($isFirstAccess)
                        Sua senha atual é temporária. Para continuar, crie uma
                        senha definitiva com pelo menos 12 caracteres.
                    @else
                        Informe sua senha atual e defina uma nova senha para
                        proteger sua conta.
                    @endif
                </p>
            </div>

            @unless ($isFirstAccess)
                <a
                    href="{{ route('dashboard') }}"
                    class="button button-secondary"
                >
                    Voltar
                </a>
            @endunless
        </div>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            method="POST"
            action="{{ route('password.change.update') }}"
            class="auth-form"
        >
            @csrf
            @method('PUT')

            <label class="form-field">
                <span>
                    {{ $isFirstAccess
                        ? 'Senha temporária atual'
                        : 'Senha atual' }}
                </span>

                <span class="password-field">
                    <input
                        id="current_password"
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-password-toggle
                        aria-controls="current_password"
                        aria-label="Mostrar senha atual"
                    >
                        Mostrar
                    </button>
                </span>
            </label>

            <label class="form-field">
                <span>Nova senha</span>

                <span class="password-field">
                    <input
                        id="new_password"
                        type="password"
                        name="password"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-password-toggle
                        aria-controls="new_password"
                        aria-label="Mostrar nova senha"
                    >
                        Mostrar
                    </button>
                </span>
            </label>

            <label class="form-field">
                <span>Confirmar nova senha</span>

                <span class="password-field">
                    <input
                        id="password_confirmation"
                        type="password"
                        name="password_confirmation"
                        autocomplete="new-password"
                        required
                    >

                    <button
                        type="button"
                        class="password-toggle"
                        data-password-toggle
                        aria-controls="password_confirmation"
                        aria-label="Mostrar confirmação da senha"
                    >
                        Mostrar
                    </button>
                </span>
            </label>

            <div class="password-requirements">
                <strong>A nova senha deve conter:</strong>
                <span>12 ou mais caracteres</span>
                <span>Letra maiúscula e minúscula</span>
                <span>Número e símbolo</span>
            </div>

            <button type="submit" class="button button-primary">
                {{ $isFirstAccess
                    ? 'Alterar senha e continuar'
                    : 'Salvar nova senha' }}
            </button>
        </form>

        @if ($isFirstAccess)
            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <button
                    type="submit"
                    class="button button-secondary password-logout"
                >
                    Sair da conta
                </button>
            </form>
        @endif
    </section>
</main>
@endsection
