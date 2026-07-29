@extends('layouts.app')

@section('title', 'Proteção administrativa')
@section('body-class', 'app-page')

@section('content')
<main class="auth-simple-shell">
    <section class="auth-card">
        <p class="eyebrow">Proteção administrativa</p>
        <h1>Configure a autenticação em duas etapas</h1>
        <p>
            Contas administrativas precisam de TOTP ou passkey. O prazo de
            adaptação configurado é de {{ $graceDays }} dias; operações
            administrativas permanecem protegidas durante a pendência.
        </p>

        @if (
            ! $user->hasAdministrativeSecondFactor()
            && $user->admin_2fa_grace_expires_at?->isFuture()
        )
            <p>
                Prazo atual:
                {{ $user->admin_2fa_grace_expires_at->format('d/m/Y H:i') }}.
            </p>
            <form method="POST" action="{{ route('security.two-factor.grace') }}">
                @csrf
                <button class="button" type="submit">
                    Continuar durante o prazo
                </button>
            </form>
        @endif

        @if (session('warning'))
            <div class="alert alert-error">{{ session('warning') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <article class="panel">
            <h2>Aplicativo autenticador (TOTP)</h2>
            @if ($totpEnabled)
                <p>Ativo.</p>
                <form method="POST" action="{{ route('two-factor.disable') }}">
                    @csrf
                    @method('DELETE')
                    <button class="button" type="submit">Desativar TOTP</button>
                </form>
            @elseif ($user->two_factor_secret)
                <p>Escaneie o QR code e confirme um código para concluir.</p>
                <img src="{{ route('two-factor.qr-code') }}" alt="QR code para configurar TOTP">
                <form method="POST" action="{{ route('two-factor.confirm') }}" class="auth-form">
                    @csrf
                    <label class="form-field">
                        <span>Código</span>
                        <input name="code" inputmode="numeric" autocomplete="one-time-code" required>
                    </label>
                    <button class="button button-primary" type="submit">Confirmar TOTP</button>
                </form>
            @else
                <form method="POST" action="{{ route('two-factor.enable') }}">
                    @csrf
                    <button class="button button-primary" type="submit">Iniciar configuração TOTP</button>
                </form>
            @endif
        </article>

        <article class="panel">
            <h2>Passkeys</h2>
            <p>{{ $passkeysCount }} passkey(s) cadastrada(s).</p>
            <p>
                O backend WebAuthn existente permanece disponível. O cadastro
                exige HTTPS e confirmação de senha.
            </p>
        </article>

        @if ($user->hasAdministrativeSecondFactor())
            <a class="button button-primary" href="{{ route('dashboard') }}">Continuar</a>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button" type="submit">Sair</button>
        </form>
    </section>
</main>
@endsection
