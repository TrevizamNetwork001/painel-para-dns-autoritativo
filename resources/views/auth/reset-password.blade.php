@extends('layouts.app')

@section('title', 'Definir nova senha')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <p class="eyebrow">DNS Center</p>
        <h1>Definir nova senha</h1>

        @if ($errors->any())
            <div class="alert alert-error">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('password.update') }}"
              class="auth-form">
            @csrf

            <input
                type="hidden"
                name="token"
                value="{{ $request->route('token') }}"
            >

            <label class="form-field">
                <span>E-mail</span>
                <input
                    type="email"
                    name="email"
                    value="{{ old('email', $request->email) }}"
                    required
                    autofocus
                >
            </label>

            <label class="form-field">
                <span>Nova senha</span>
                <input
                    type="password"
                    name="password"
                    autocomplete="new-password"
                    required
                >
            </label>

            <label class="form-field">
                <span>Confirme a nova senha</span>
                <input
                    type="password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    required
                >
            </label>

            <button type="submit" class="button button-primary">
                Atualizar senha
            </button>
        </form>
    </section>
</main>
@endsection
