@extends('layouts.app')

@section('title', 'Configurações — Backup')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell">
    <x-app-sidebar active="settings" />

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Configurações</p>
                <h1>Backup do banco</h1>

                <p class="page-description">
                    O backup diário roda no servidor e guarda uma cópia local.
                    Aqui você informa as credenciais do Cloudflare R2 para o envio
                    de uma segunda cópia, <strong>sempre criptografada</strong>, fora do servidor.
                </p>
            </div>

            <div class="topbar-actions">
                <x-account-menu />
            </div>
        </header>

        @if (session('status'))
            <div class="alert alert-success flash-message" data-flash-message>
                {{ session('status') }}
            </div>
        @endif

        @if (session('test_error'))
            <div class="alert alert-error" role="alert">
                <strong>Teste de conexão falhou.</strong>
                {{ session('test_error') }}
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

        <article class="panel">
            <div class="compact-panel-heading">
                <p class="eyebrow">Cópia fora do servidor</p>
                <h2>
                    Cloudflare R2
                    <span class="status-badge {{ $configured ? 'status-success' : 'status-neutral' }}">
                        {{ $configured ? 'Configurado' : 'Não configurado' }}
                    </span>
                </h2>

                @if ($configured && $updatedAt)
                    <p class="page-description">
                        Última alteração em {{ $updatedAt->format('d/m/Y H:i') }}@if ($updatedBy) por {{ $updatedBy }}@endif.
                    </p>
                @endif
            </div>

            <form method="POST" action="{{ route('settings.backup.update') }}" autocomplete="off">
                @csrf
                @method('PUT')

                <label class="form-field">
                    <span>Account ID</span>
                    <input type="text" name="account_id" value="{{ old('account_id', $settings['account_id']) }}"
                        placeholder="32 caracteres (aparece na URL do painel da Cloudflare)" required spellcheck="false">
                </label>

                <label class="form-field">
                    <span>Bucket</span>
                    <input type="text" name="bucket" value="{{ old('bucket', $settings['bucket']) }}"
                        placeholder="dns-center-backups" required spellcheck="false">
                </label>

                <label class="form-field">
                    <span>Pasta dentro do bucket (opcional)</span>
                    <input type="text" name="prefix" value="{{ old('prefix', $settings['prefix']) }}"
                        placeholder="dns-center/" spellcheck="false">
                </label>

                <label class="form-field">
                    <span>ID da chave de acesso</span>
                    <input type="text" name="access_key_id" value="" spellcheck="false" autocomplete="off"
                        placeholder="{{ $settings['access_key_id_masked'] !== '' ? 'Atual: '.$settings['access_key_id_masked'].' — deixe em branco para manter' : 'Cole o ID da chave de acesso' }}"
                        @required($settings['access_key_id_masked'] === '')>
                </label>

                <label class="form-field">
                    <span>Chave de acesso secreta</span>

                    <span class="password-field">
                        <input id="r2_secret" type="password" name="secret_access_key" value="" spellcheck="false" autocomplete="new-password"
                            placeholder="{{ $settings['has_secret'] ? 'Já definida — deixe em branco para manter' : 'Cole a chave de acesso secreta' }}"
                            @required(! $settings['has_secret'])>

                        <button type="button" class="password-toggle" data-password-toggle aria-controls="r2_secret">
                            Mostrar
                        </button>
                    </span>

                    <small>A chave secreta é guardada criptografada e nunca é exibida de novo.</small>
                </label>

                <div class="form-actions">
                    <button type="submit" class="button button-primary">Salvar configuração</button>
                </div>
            </form>
        </article>

        @if ($configured)
            <article class="panel">
                <div class="compact-panel-heading">
                    <p class="eyebrow">Verificação</p>
                    <h2>Testar e remover</h2>
                </div>

                <p class="page-description">
                    O teste envia um arquivo minúsculo ao bucket, confere e apaga.
                    Não envia nenhum dado do painel.
                </p>

                <div class="form-actions">
                    <form method="POST" action="{{ route('settings.backup.test') }}">
                        @csrf
                        <button type="submit" class="button button-secondary">Testar conexão</button>
                    </form>

                    <form method="POST" action="{{ route('settings.backup.destroy') }}"
                        onsubmit="return confirm('Remover as credenciais do R2? O backup diário continua, mas somente local.')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="button button-danger-soft">Remover credenciais</button>
                    </form>
                </div>
            </article>
        @endif

        <article class="panel">
            <div class="compact-panel-heading">
                <p class="eyebrow">Como funciona</p>
                <h2>O que fica no servidor</h2>
            </div>

            <ul>
                <li>O backup diário (02:30) e o teste de restauração semanal rodam pelo cron do servidor; os logs ficam em <code>/var/log/dns-center-backup.log</code>.</li>
                <li>Cada dump é <strong>criptografado</strong> antes de sair, com a senha do arquivo <code>/etc/dns-center/backup.pass</code>. Essa senha <strong>não</strong> fica no painel: se ficasse no banco, o backup não abriria justamente no dia em que o banco se perdesse. <strong>Guarde uma cópia dela no seu gerenciador de senhas.</strong></li>
                <li>Sem as credenciais aqui, o backup segue só local. A retenção no R2 é definida pela regra de ciclo de vida do próprio bucket.</li>
                <li>As credenciais ficam criptografadas com a <code>APP_KEY</code> do painel; guarde também o arquivo <code>/etc/dns-center/app.env</code> fora do servidor.</li>
            </ul>
        </article>
    </main>
</div>
@endsection
