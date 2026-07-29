<!DOCTYPE html>
<html
    lang="pt-BR"
    data-theme="dark"
>
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Agente {{ $server->name }} — DNS Center
    </title>

    <script>
        (() => {
            const storedTheme = localStorage.getItem('dns-center-theme');
            const preferredTheme = window.matchMedia(
                '(prefers-color-scheme: light)'
            ).matches ? 'light' : 'dark';

            document.documentElement.dataset.theme =
                storedTheme || preferredTheme;
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="agent-onboarding-page">
    <main class="agent-onboarding-shell">
        <header class="agent-onboarding-header">
            <div>
                <p class="eyebrow">Servidores DNS</p>
                <h1>Onboarding do agente</h1>
                <p>
                    Vincule o agente local ao servidor
                    <strong>{{ $server->name }}</strong>.
                </p>
            </div>

            <a
                href="{{ route('servers.index') }}"
                class="button button-secondary"
            >
                Voltar aos servidores
            </a>
        </header>

        @if (session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <section class="agent-onboarding-grid">
            <article class="panel-card">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">Servidor</p>
                        <h2>{{ $server->name }}</h2>
                    </div>

                    <span class="status-badge">
                        {{ $server->role === 'primary'
                            ? 'Primário'
                            : 'Secundário' }}
                    </span>
                </div>

                <dl class="agent-server-details">
                    <div>
                        <dt>Hostname</dt>
                        <dd>{{ $server->hostname }}</dd>
                    </div>

                    <div>
                        <dt>IPv4</dt>
                        <dd>{{ $server->ipv4_address ?: 'Não informado' }}</dd>
                    </div>

                    <div>
                        <dt>IPv6</dt>
                        <dd>{{ $server->ipv6_address ?: 'Não informado' }}</dd>
                    </div>

                    <div>
                        <dt>Ambiente</dt>
                        <dd>{{ ucfirst($server->environment) }}</dd>
                    </div>
                </dl>
            </article>

            <article class="panel-card">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">Estado</p>
                        <h2>Agente</h2>
                    </div>
                </div>

                @if ($agent && $agent->revoked_at === null)
                    <div class="agent-state agent-state-connected">
                        <strong>Agente registrado</strong>

                        <span>
                            UUID: {{ $agent->agent_uuid }}
                        </span>

                        <span>
                            Host reportado:
                            {{ $agent->reported_hostname }}
                        </span>

                        <span>
                            Registrado em:
                            {{ $agent->registered_at?->format('d/m/Y H:i') }}
                        </span>

                        <span>
                            IP de registro:
                            {{ $agent->registered_ip ?: 'Não disponível' }}
                        </span>

                        <span>
                            Último contato:
                            {{ $agent->last_seen_at
                                ? $agent->last_seen_at->format('d/m/Y H:i')
                                : 'Ainda não recebido' }}
                        </span>

                        <span>
                            Estado operacional:
                            {{ $server->agent_status }}
                        </span>

                        <span>
                            Versão desejada:
                            {{ $latestPublication?->zoneVersion?->version
                                ?? 'Nenhuma publicação destinada' }}
                        </span>

                        <span>
                            Versão instalada confirmada:
                            {{ $latestAppliedPublication?->installed_version
                                ?? 'Ainda não confirmada' }}
                        </span>

                        <span>
                            Estado da aplicação:
                            {{ $latestPublication?->status
                                ?? 'Sem publicação' }}
                        </span>

                        @if (
                            $latestAppliedPublication
                            && $latestPublication
                            && $latestAppliedPublication->installed_version
                                < $latestPublication->zoneVersion->version
                        )
                            <span>
                                A versão instalada é anterior à desejada.
                            </span>
                        @endif

                        <span>
                            Última confirmação:
                            {{ $latestPublication?->last_apply_at
                                ?->format('d/m/Y H:i')
                                ?? 'Ainda não recebida' }}
                        </span>

                        @if ($latestPublication?->last_apply_error)
                            <span>
                                Erro informado:
                                {{ $latestPublication->last_apply_error }}
                            </span>
                        @endif

                        @if ($latestPublication?->zoneVersion?->zone)
                            <span>
                                Publicação:
                                <a href="{{ route(
                                    'zones.show',
                                    $latestPublication->zoneVersion->zone
                                ) }}">
                                    {{ $latestPublication
                                        ->zoneVersion->zone->name }}
                                    · versão
                                    {{ $latestPublication
                                        ->zoneVersion->version }}
                                </a>
                            </span>
                        @endif

                        <span>
                            Sistema:
                            {{ $server->operating_system ?: 'Não informado' }}
                            {{ $server->operating_system_version }}
                        </span>

                        <span>
                            BIND:
                            {{ $server->bind_version ?: 'Não informado' }}
                        </span>
                    </div>

                    <form
                        method="POST"
                        action="{{ route(
                            'servers.agent.revoke',
                            $server
                        ) }}"
                        onsubmit="return confirm(
                            'Revogar a credencial deste agente?'
                        )"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="button button-secondary"
                        >
                            Revogar credencial
                        </button>
                    </form>
                @else
                    <div class="agent-state">
                        <strong>Aguardando ativação</strong>

                        <span>
                            Gere um código temporário e execute o agente
                            no servidor correspondente.
                        </span>
                    </div>

                    <form
                        method="POST"
                        action="{{ route(
                            'servers.agent.enrollment.store',
                            $server
                        ) }}"
                    >
                        @csrf

                        <button
                            type="submit"
                            class="button button-primary"
                        >
                            Gerar código de ativação
                        </button>
                    </form>
                @endif
            </article>
        </section>

        @if (session('agent_enrollment_code'))
            <section class="panel-card agent-code-panel">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">Uso único</p>
                        <h2>Código de ativação</h2>
                    </div>

                    <span class="status-badge status-warning">
                        Expira em 30 minutos
                    </span>
                </div>

                <p class="agent-code-warning">
                    Este código será mostrado somente agora.
                    Guarde-o apenas durante a instalação.
                </p>

                <div class="agent-code-box">
                    <code id="agent-activation-code">
                        {{ session('agent_enrollment_code') }}
                    </code>

                    <button
                        type="button"
                        class="button button-secondary"
                        data-copy-agent-code
                    >
                        Copiar
                    </button>
                </div>

                <h3>Comando de ativação</h3>

                <div class="agent-command-box">
                    <code id="agent-enrollment-command">sudo python3 /opt/dns-center-agent/dns-center-agent.py --enroll --url={{ url('/') }} --code={{ session('agent_enrollment_code') }}</code>

                    <button
                        type="button"
                        class="button button-secondary"
                        data-copy-agent-command
                    >
                        Copiar comando
                    </button>
                </div>
            </section>
        @elseif (
            $latestEnrollment
            && $latestEnrollment->used_at === null
            && $latestEnrollment->revoked_at === null
            && $latestEnrollment->expires_at->isFuture()
            && ! $agent
        )
            <section class="panel-card">
                <p class="eyebrow">Ativação pendente</p>

                <h2>Existe um código ainda válido</h2>

                <p>
                    Por segurança, o código não pode ser exibido novamente.
                    Gere outro código caso tenha perdido o anterior.
                </p>

                <form
                    method="POST"
                    action="{{ route(
                        'servers.agent.enrollment.store',
                        $server
                    ) }}"
                >
                    @csrf

                    <button
                        type="submit"
                        class="button button-secondary"
                    >
                        Revogar e gerar novo código
                    </button>
                </form>
            </section>
        @endif

        <section class="panel-card">
            <p class="eyebrow">Segurança</p>
            <h2>Como funciona</h2>

            <div class="agent-security-list">
                <span>O código é vinculado a este servidor.</span>
                <span>O código expira após 30 minutos.</span>
                <span>O código só pode ser utilizado uma vez.</span>
                <span>Somente o hash é armazenado no painel.</span>
                <span>A credencial permanente aparece somente para o agente.</span>
                <span>O agente não recebe acesso ao painel administrativo.</span>
            </div>
        </section>
    </main>

    <script>
        document
            .querySelector('[data-copy-agent-code]')
            ?.addEventListener('click', async () => {
                const code = document
                    .querySelector('#agent-activation-code')
                    ?.textContent
                    ?.trim();

                if (!code) {
                    return;
                }

                await navigator.clipboard.writeText(code);
            });

        document
            .querySelector('[data-copy-agent-command]')
            ?.addEventListener('click', async () => {
                const command = document
                    .querySelector('#agent-enrollment-command')
                    ?.textContent
                    ?.trim();

                if (!command) {
                    return;
                }

                await navigator.clipboard.writeText(command);
            });
    </script>
</body>
</html>
