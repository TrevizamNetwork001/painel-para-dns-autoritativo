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

    <script nonce="{{ $cspNonce ?? '' }}">
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
            <div
                class="alert alert-success flash-toast"
                role="status"
                data-flash-toast
                data-flash-timeout="6000"
            >
                <span class="flash-toast-message">
                    {{ session('status') }}
                </span>

                <button
                    type="button"
                    class="flash-toast-close"
                    data-flash-toast-close
                    aria-label="Fechar"
                >
                    &times;
                </button>
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
                        {{ match ($server->role) {
                            'primary' => 'Primário',
                            'secondary' => 'Secundário',
                            'standalone' => 'Independente',
                            default => $server->role,
                        } }}
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
                            Serial SOA observado no BIND:
                            {{ $latestAppliedPublication?->reported_serial
                                ?? 'Ainda não confirmado' }}
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
                        <strong>Aguardando instalação</strong>

                        <span>
                            Execute o instalador no servidor correspondente.
                            A solicitação aparecerá abaixo para aprovação.
                        </span>
                    </div>
                @endif
            </article>
        </section>

        @if ($agent && $agent->revoked_at === null)
            <section class="panel-card">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">BIND autoritativo</p>
                        <h2>Prontidão factual</h2>
                    </div>

                    <span class="status-badge">
                        {{ $server->bind_readiness_at
                            ? 'Inventário recebido'
                            : 'Aguardando inventário' }}
                    </span>
                </div>

                @if ($server->bind_readiness_at)
                    @php
                        $readiness = $server->bind_readiness ?? [];
                        $bindInstalled = (bool) data_get(
                            $readiness,
                            'bind_installed',
                            false
                        );
                        $osFamily = data_get(
                            $readiness,
                            'os_family',
                            'unsupported'
                        );
                        $packages = match ($osFamily) {
                            'debian' => 'bind9, bind9-utils',
                            'rhel' => 'bind, bind-utils',
                            default => 'Distribuição não suportada',
                        };
                    @endphp

                    <dl class="agent-server-details">
                        <div>
                            <dt>BIND instalado</dt>
                            <dd>{{ $bindInstalled ? 'Sim' : 'Não' }}</dd>
                        </div>
                        <div>
                            <dt>Versão detectada</dt>
                            <dd>{{ data_get($readiness, 'bind_version')
                                ?: 'Não detectada' }}</dd>
                        </div>
                        <div>
                            <dt>Configuração principal</dt>
                            <dd>{{ data_get($readiness, 'paths.named_conf')
                                ?: 'Não detectada' }}</dd>
                        </div>
                        <div>
                            <dt>Include gerenciado</dt>
                            <dd>{{ data_get($readiness, 'paths.include_dir')
                                ?: 'Não detectado' }}</dd>
                        </div>
                        <div>
                            <dt>Diretório de zonas gerenciadas</dt>
                            <dd>{{ data_get($readiness, 'paths.zones_dir') }}</dd>
                        </div>
                        <div>
                            <dt>Serviço ativo</dt>
                            <dd>{{ data_get($readiness, 'service.active')
                                ? 'Sim'
                                : 'Não' }}</dd>
                        </div>
                        <div>
                            <dt>Listener TCP 53</dt>
                            <dd>{{ data_get($readiness, 'listeners.tcp_53')
                                ? 'Detectado'
                                : 'Não detectado' }}</dd>
                        </div>
                        <div>
                            <dt>Listener UDP 53</dt>
                            <dd>{{ data_get($readiness, 'listeners.udp_53')
                                ? 'Detectado'
                                : 'Não detectado' }}</dd>
                        </div>
                    </dl>

                    <p>
                        Plano local allowlisted:
                        {{ $bindInstalled
                            ? 'integrar o include gerenciado sem apagar a configuração existente'
                            : 'instalar '.$packages.' e integrar o include gerenciado' }}.
                    </p>

                    @if (! $latestBindOperation)
                        <form
                            method="POST"
                            action="{{ route('servers.bind.plan', $server) }}"
                        >
                            @csrf
                            <button type="submit" class="button button-secondary">
                                Preparar plano BIND
                            </button>
                        </form>
                    @else
                        <p>
                            Operação {{ $latestBindOperation->action }}
                            · estado {{ $latestBindOperation->status }}
                        </p>

                        @if ($latestBindOperation->error)
                            <p>Erro sanitizado: {{ $latestBindOperation->error }}</p>
                        @endif

                        @if ($latestBindOperation->status === 'planned')
                            <form
                                method="POST"
                                action="{{ route(
                                    'servers.bind.authorize',
                                    [$server, $latestBindOperation]
                                ) }}"
                            >
                                @csrf
                                <label>
                                    Confirmação forte
                                    <input
                                        name="confirmation"
                                        required
                                        autocomplete="off"
                                        placeholder="AUTORIZAR BIND {{ Str::upper($server->name) }}"
                                    >
                                </label>
                                <button type="submit" class="button button-primary">
                                    Autorizar operação
                                </button>
                            </form>
                        @endif
                    @endif
                @else
                    <p>
                        O agente ainda não enviou a detecção factual do BIND,
                        ferramentas, serviço, listeners e permissões.
                    </p>
                @endif
            </section>
        @endif

        @if (
            $latestInstallRequest
            && $latestInstallRequest->status === 'pending'
            && $latestInstallRequest->expires_at->isFuture()
            && ! $agent
        )
            <section class="panel-card agent-code-panel">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">Aprovação administrativa</p>
                        <h2>Instalação aguardando aprovação</h2>
                    </div>

                    <span class="status-badge status-warning">
                        Pendente
                    </span>
                </div>

                <div class="agent-security-list">
                    <span>Hostname: {{ $latestInstallRequest->reported_hostname }}</span>
                    <span>Endereço observado: {{ $latestInstallRequest->registered_ip ?? 'não informado' }}</span>
                    <span>Sistema: {{ $latestInstallRequest->operating_system ?? 'não informado' }} {{ $latestInstallRequest->operating_system_version }}</span>
                    <span>Expira em: {{ $latestInstallRequest->expires_at->format('d/m/Y H:i') }}</span>
                </div>

                <div class="agent-actions">
                    <form
                        method="POST"
                        action="{{ route(
                            'servers.agent.install-requests.approve',
                            [$server, $latestInstallRequest]
                        ) }}"
                    >
                        @csrf
                        <button type="submit" class="button button-primary">
                            Aprovar instalação
                        </button>
                    </form>

                    <form
                        method="POST"
                        action="{{ route(
                            'servers.agent.install-requests.reject',
                            [$server, $latestInstallRequest]
                        ) }}"
                    >
                        @csrf
                        <button type="submit" class="button button-danger-soft">
                            Rejeitar
                        </button>
                    </form>
                </div>
            </section>
        @elseif (! $agent)
            <section class="panel-card agent-code-panel">
                <p class="eyebrow">Instalação automática</p>
                <h2>Instalar o agente</h2>

                <p>
                    Execute no servidor cadastrado. O agente identificará esta
                    máquina e aparecerá aqui para aprovação.
                </p>

                <div class="agent-command-box">
                    <code id="agent-install-command">curl -fsSL https://dnscenter.trevizamnetwork.com.br/install/agent_install.sh | sudo bash</code>

                    <button
                        type="button"
                        class="button button-secondary"
                        data-copy-agent-command
                    >
                        Copiar
                    </button>
                </div>
            </section>
        @endif

        <section class="panel-card">
            <p class="eyebrow">Segurança</p>
            <h2>Como funciona</h2>

            <div class="agent-security-list">
                <span>A solicitação é associada por hostname/IP cadastrado.</span>
                <span>A solicitação expira após 24 horas.</span>
                <span>A aprovação reutiliza a sessão administrativa com 2FA.</span>
                <span>A credencial da solicitação é armazenada somente como hash.</span>
                <span>A credencial permanente aparece somente para o agente.</span>
                <span>O agente não recebe acesso ao painel administrativo.</span>
            </div>
        </section>
    </main>

    <script nonce="{{ $cspNonce ?? '' }}">
        document
            .querySelector('[data-copy-agent-command]')
            ?.addEventListener('click', async () => {
                const command = document
                    .querySelector('#agent-install-command')
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
