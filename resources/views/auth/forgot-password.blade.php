@extends('layouts.app')

@section('title', 'Recuperar senha')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <p class="eyebrow">{{ config('app.name') }}</p>
        <h1>Recuperar senha</h1>

        <p class="auth-description-small">
            Informe seu e-mail para receber as instruções de redefinição.
        </p>

        @if (session('status'))
            <div class="alert alert-success">
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

        <form method="POST" action="{{ route('password.email') }}"
              class="auth-form">
            @csrf

            <label class="form-field">
                <span>E-mail</span>
                <input
                    type="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    inputmode="email"
                    required
                    autofocus
                >
            </label>

            <button type="submit" class="button button-primary">
                Enviar link de recuperação
            </button>
        </form>

        <a class="back-link" href="{{ route('login') }}">
            Voltar ao login
        </a>
    </section>
</main>
@endsection
