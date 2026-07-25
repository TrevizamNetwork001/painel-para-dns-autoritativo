@extends('layouts.app')

@section('title', 'Verificação em duas etapas')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <p class="eyebrow">Segurança</p>
        <h1>Verificação em duas etapas</h1>

        <p class="auth-description-small">
            Informe o código do seu aplicativo autenticador.
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
            action="{{ route('two-factor.login.store') }}"
            class="auth-form"
        >
            @csrf

            <label class="form-field">
                <span>Código de autenticação</span>
                <input
                    type="text"
                    name="code"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    autofocus
                >
            </label>

            <button type="submit" class="button button-primary">
                Confirmar acesso
            </button>
        </form>
    </section>
</main>
@endsection
