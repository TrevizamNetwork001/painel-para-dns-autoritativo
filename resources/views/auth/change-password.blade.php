@extends('layouts.app')

@section('title', 'Alterar senha')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <p class="eyebrow">Primeiro acesso</p>
        <h1>Defina sua nova senha</h1>

        <p class="auth-description-small">
            Sua senha atual é temporária. Para continuar, crie uma senha
            definitiva com pelo menos 12 caracteres.
        </p>

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
                <span>Senha temporária atual</span>

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
                Alterar senha e continuar
            </button>
        </form>

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
@endsection
