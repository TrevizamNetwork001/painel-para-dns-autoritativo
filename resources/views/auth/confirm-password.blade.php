@extends('layouts.app')

@section('title', 'Confirmar senha')
@section('body-class', 'auth-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card auth-card-small">
        <p class="eyebrow">Segurança</p>
        <h1>Confirme sua senha</h1>
        <p class="auth-description-small">
            Confirme sua senha antes de alterar fatores de autenticação.
        </p>

        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('password.confirm.store') }}" class="auth-form">
            @csrf
            <label class="form-field">
                <span>Senha</span>
                <input type="password" name="password" autocomplete="current-password" required autofocus>
            </label>
            <button type="submit" class="button button-primary">Confirmar</button>
        </form>
    </section>
</main>
@endsection
