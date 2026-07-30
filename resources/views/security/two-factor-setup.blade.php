@extends('layouts.app')

@section('title', 'Proteção administrativa')
@section('body-class', 'app-page')

@section('content')
<main class="security-setup-shell">
    <section class="security-setup-card">
        <header class="security-setup-heading">
            <div>
                <p class="eyebrow">Proteção administrativa</p>
                <h1>Autenticação em duas etapas</h1>
                <p>
                    Proteja operações administrativas com aplicativo
                    autenticador ou passkey.
                </p>
            </div>

            <span class="status-badge status-warning">
                Configuração necessária
            </span>
        </header>

        @if (
            ! $user->hasAdministrativeSecondFactor()
            && $user->admin_2fa_grace_expires_at?->isFuture()
        )
            <div class="security-grace-notice">
                <p>
                    Prazo de adaptação:
                    <strong>
                        {{ $user->admin_2fa_grace_expires_at->format('d/m/Y H:i') }}
                    </strong>
                </p>

                <form method="POST" action="{{ route('security.two-factor.grace') }}">
                    @csrf
                    <button class="button button-secondary" type="submit">
                        Continuar depois
                    </button>
                </form>
            </div>
        @endif

        @if (session('warning'))
            <div class="alert alert-error">{{ session('warning') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">{{ $errors->first() }}</div>
        @endif

        <div class="security-factor-grid">
            <article class="panel security-factor-card">
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

                <div class="totp-qr-code" aria-label="QR code para configurar TOTP">
                    {!! $totpQrCodeSvg !!}
                </div>

                <p>
                    No Google Authenticator, escolha
                    <strong>Inserir chave de configuração</strong> caso não
                    consiga escanear o QR Code.
                </p>

                <div class="agent-command-box">
                    <code id="totp-secret-key">{{ $totpSecretKey }}</code>

                    <button
                        type="button"
                        class="button button-secondary"
                        data-copy-totp-secret
                    >
                        Copiar
                    </button>
                </div>

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

            <article class="panel security-factor-card">
                <h2>Passkeys</h2>
                <p>{{ $passkeysCount }} passkey(s) cadastrada(s).</p>
                <p>
                    O backend WebAuthn existente permanece disponível. O cadastro
                    exige HTTPS e confirmação de senha.
                </p>
            </article>
        </div>

        <footer class="security-setup-actions">
            @if ($user->hasAdministrativeSecondFactor())
                <a class="button button-primary" href="{{ route('dashboard') }}">
                    Continuar
                </a>
            @endif

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="button button-secondary" type="submit">Sair</button>
            </form>
        </footer>
    </section>
</main>

<script nonce="{{ $cspNonce ?? '' }}">
    document
        .querySelector('[data-copy-totp-secret]')
        ?.addEventListener('click', async () => {
            const secret = document
                .querySelector('#totp-secret-key')
                ?.textContent
                ?.trim();

            if (secret) {
                await navigator.clipboard.writeText(secret);
            }
        });
</script>
@endsection
