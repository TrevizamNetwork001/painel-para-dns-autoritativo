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
                            <span class="status-badge {{ match ($server->agent_status) {
                                'online' => 'status-success',
                                'warning' => 'status-warning',
                                'blocked' => 'status-danger',
                                default => 'status-neutral',
                            } }}">
                                {{ match ($server->agent_status) {
                                    'online' => 'Online',
                                    'warning' => 'Atenção',
                                    'blocked' => 'Bloqueado',
                                    'pending' => 'Aguardando primeiro contato',
                                    default => $server->agent_status,
                                } }}
                            </span>
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
                            Versão do agente:
                            {{ $agent->metadata['agent_version'] ?? $server->agent_version ?? 'Não informado' }}
                        </span>

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

                    <div class="agent-actions">
                        <button
                            type="button"
                            class="button button-primary"
                            data-agent-upgrade-start
                            data-agent-upgrade-store-url="{{ route('servers.agent.upgrade', $server) }}"
                            data-agent-upgrade-status-url="{{ route('servers.agent.upgrade.status', $server) }}"
                            data-agent-upgrade-csrf="{{ csrf_token() }}"
                        >
                            Atualizar agente
                        </button>

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
                    </div>
                @else
                    <div class="agent-state">
                        <strong>{{ $agent?->revoked_at ? 'Credencial revogada — reenrollment necessário' : 'Agente não vinculado' }}</strong>

                        <span>
                            A instalação e o vínculo são etapas independentes.
                            Gere um vínculo para instalar ou reenrolar sem tocar no BIND.
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

            @if ($server->bind_readiness_at)
                <section class="panel-card">
                    <div class="panel-card-header">
                        <div>
                            <p class="eyebrow">BIND existente</p>
                            <h2>Descoberta somente leitura</h2>
                        </div>

                        <span class="status-badge {{ $latestDiscoveryOperation ? 'status-success' : 'status-neutral' }}">
                            {{ $latestDiscoveryOperation ? 'Detectado' : 'Nunca executada' }}
                        </span>
                    </div>

                    <p>
                        Inventaria zonas, seriais e metadados do BIND já
                        existente, sem escrever em <code>/etc/bind</code>,
                        sem <code>rndc reload/reconfig</code> e sem publicar
                        nada. Servidores e reversas podem ser revisados e
                        importados manualmente depois.
                    </p>

                    @if ($latestDiscoveryOperation)
                        <p>
                            Última descoberta:
                            {{ $latestDiscoveryOperation->completed_at?->format('d/m/Y H:i')
                                ?? $latestDiscoveryOperation->authorized_at?->format('d/m/Y H:i') }}
                            · estado {{ $latestDiscoveryOperation->status }}
                            @if ($latestDiscoveryOperation->status === 'succeeded')
                                · {{ $discoveredZoneCount }} zona(s) encontrada(s)
                            @endif
                        </p>

                        @if ($latestDiscoveryOperation->error)
                            <p>Erro sanitizado: {{ $latestDiscoveryOperation->error }}</p>
                        @endif

                        @if ($latestDiscoveryOperation->status === 'succeeded')
                            <a
                                href="{{ route('servers.bind.discovery.show', $server) }}"
                                class="button button-secondary"
                            >
                                Ver zonas encontradas
                            </a>
                        @endif
                    @endif

                    <button
                        type="button"
                        class="button button-primary"
                        data-discovery-start
                        data-discovery-store-url="{{ route('servers.bind.discover', $server) }}"
                        data-discovery-status-url="{{ route('servers.bind.discovery.status', $server) }}"
                        data-discovery-show-url="{{ route('servers.bind.discovery.show', $server) }}"
                        data-discovery-csrf="{{ csrf_token() }}"
                        data-discovery-initial-status="{{ $latestDiscoveryOperation?->status }}"
                        @disabled($latestDiscoveryOperation && in_array($latestDiscoveryOperation->status, ['authorized', 'running'], true))
                    >
                        Executar nova descoberta
                    </button>
                </section>
            @endif
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
                        <h2>Solicitação recebida</h2>
                    </div>

                    <span class="status-badge status-warning">
                        Pendente
                    </span>
                </div>

                <div class="agent-security-list">
                    <span>Hostname: {{ $latestInstallRequest->reported_hostname }}</span>
                    <span>Endereço observado: {{ $latestInstallRequest->registered_ip ?? 'não informado' }}</span>
                    <span>Fingerprint: {{ substr($latestInstallRequest->fingerprint, 0, 16) }}…</span>
                    <span>Sistema: {{ $latestInstallRequest->operating_system ?? 'não informado' }} {{ $latestInstallRequest->operating_system_version }}</span>
                    <span>Expira em: {{ $latestInstallRequest->expires_at->format('d/m/Y H:i') }}</span>
                    @foreach ($latestInstallRequest->review_warnings ?? [] as $warning)
                        <span class="status-badge status-warning">
                            {{ $warning === 'hostname_mismatch'
                                ? 'WARNING: hostname informado diverge do servidor selecionado.'
                                : 'WARNING: IP observado diverge dos endereços cadastrados (possível NAT).' }}
                        </span>
                    @endforeach
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
                            Aprovar agente
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
                <p class="eyebrow">Enrollment pré-vinculado</p>
                <h2>{{ $latestEnrollmentCode?->expires_at?->isPast() ? 'Vínculo expirado' : 'Gerar vínculo' }}</h2>

                <p>
                    O código nascerá associado a <strong>{{ $server->hostname }}</strong>
                    e não dependerá de matching por hostname ou IP.
                </p>

                <form method="POST" action="{{ route('servers.agent.enrollment-codes.store', $server) }}">
                    @csrf
                    <button type="submit" class="button button-primary">
                        {{ $latestEnrollmentCode ? 'Gerar novo vínculo' : 'Gerar vínculo' }}
                    </button>
                </form>

                @if ($issuedEnrollmentCode)
                    <div class="alert alert-warning">
                        <strong>Código exibido uma única vez</strong>
                        <code id="agent-enrollment-code">{{ $issuedEnrollmentCode }}</code>
                        <span>Não será possível recuperá-lo ao fechar ou recarregar esta tela.</span>
                    </div>

                    <div class="agent-choice-grid">
                        <div class="agent-choice-card">
                            <h3>Agente já instalado</h3>
                            <p>Execute o comando e cole o código no prompt seguro. O segredo não entra em argv nem no histórico shell.</p>
                            <div class="agent-command-box">
                                <code id="agent-enroll-command">sudo /usr/local/sbin/dns-center-agent --enroll --wait 0</code>
                                <button type="button" class="button button-secondary" data-copy-target="agent-enroll-command">Copiar</button>
                            </div>
                            <p>
                                Erro <code>unrecognized arguments: --enroll</code>? O agente instalado é anterior a
                                esta funcionalidade. Atualize somente o binário antes (não reinstala, não mexe no BIND):
                            </p>
                            <div class="agent-command-box">
                                <code id="agent-upgrade-command">curl -fsSL https://dnscenter.trevizamnetwork.com.br/install/agent_install.sh | sudo bash -s -- --upgrade-agent</code>
                                <button type="button" class="button button-secondary" data-copy-target="agent-upgrade-command">Copiar</button>
                            </div>
                        </div>

                        <div class="agent-choice-card">
                            <h3>Máquina sem agente</h3>
                            <p>O instalador completo aceita o mesmo código e o solicita pelo terminal.</p>
                            <div class="agent-command-box">
                                <code id="agent-install-command">curl -fsSL https://dnscenter.trevizamnetwork.com.br/install/agent_install.sh | sudo bash -s -- --enroll</code>
                                <button type="button" class="button button-secondary" data-copy-target="agent-install-command">Copiar</button>
                            </div>
                        </div>
                    </div>
                @elseif ($latestEnrollmentCode && ! $latestEnrollmentCode->revoked_at && ! $latestEnrollmentCode->used_at && $latestEnrollmentCode->expires_at->isFuture())
                    <p>Vínculo gerado e aguardando solicitação. O código não pode ser exibido novamente; gere outro se ele foi perdido.</p>
                    <span>Expira em {{ $latestEnrollmentCode->expires_at->format('d/m/Y H:i:s') }}</span>
                @endif

                <p><strong>Modo legado / associação manual:</strong> o instalador genérico continua disponível apenas para recovery.</p>
            </section>
        @endif

        <section class="panel-card">
            <p class="eyebrow">Segurança</p>
            <h2>Como funciona</h2>

            <div class="agent-security-list">
                <span>O novo vínculo é associado previamente ao servidor e à organização.</span>
                <span>Hostname e IP são fatores de revisão, não de associação.</span>
                <span>O código é CSPRNG, uso único, expira e só seu hash é persistido.</span>
                <span>A aprovação reutiliza a sessão administrativa com 2FA.</span>
                <span>A credencial da solicitação é armazenada somente como hash.</span>
                <span>A credencial permanente aparece somente para o agente.</span>
                <span>O agente não recebe acesso ao painel administrativo.</span>
            </div>
        </section>
    </main>

    <div class="servers-modal" data-discovery-modal aria-hidden="true">
        <button
            type="button"
            class="servers-modal-backdrop"
            data-discovery-modal-close
            aria-label="Fechar"
        ></button>

        <section class="servers-modal-dialog discovery-modal-dialog">
            <header class="servers-modal-header">
                <div>
                    <p class="eyebrow">BIND existente</p>
                    <h2 data-discovery-modal-title>Buscando zonas no servidor…</h2>
                </div>

                <button
                    type="button"
                    class="users-modal-close"
                    data-discovery-modal-close
                >
                    ×
                </button>
            </header>

            <div data-discovery-modal-body>
                <div class="discovery-spinner" data-discovery-spinner></div>

                <p data-discovery-modal-message>
                    Solicitação enviada. O agente executa a descoberta somente
                    leitura no próximo ciclo do timer (normalmente até 5
                    minutos) — esta janela atualiza sozinha.
                </p>

                <div data-discovery-summary hidden>
                    <dl class="agent-server-details" data-discovery-summary-counts></dl>

                    <div class="agent-security-list" data-discovery-summary-list></div>
                </div>
            </div>

            <div class="agent-actions" data-discovery-modal-actions>
                <a
                    class="button button-primary"
                    data-discovery-review-link
                    href="#"
                    hidden
                >
                    Revisar zonas encontradas
                </a>

                <button
                    type="button"
                    class="button button-secondary"
                    data-discovery-modal-close
                >
                    Fechar
                </button>
            </div>
        </section>
    </div>

    <div class="servers-modal" data-agent-upgrade-modal aria-hidden="true">
        <button
            type="button"
            class="servers-modal-backdrop"
            data-agent-upgrade-modal-close
            aria-label="Fechar"
        ></button>

        <section class="servers-modal-dialog discovery-modal-dialog">
            <header class="servers-modal-header">
                <div>
                    <p class="eyebrow">Agente</p>
                    <h2 data-agent-upgrade-modal-title>Atualizando agente…</h2>
                </div>

                <button
                    type="button"
                    class="users-modal-close"
                    data-agent-upgrade-modal-close
                >
                    ×
                </button>
            </header>

            <div data-agent-upgrade-modal-body>
                <div class="discovery-spinner" data-agent-upgrade-spinner></div>

                <p data-agent-upgrade-modal-message>
                    Solicitação enviada. O agente baixa, valida e substitui o
                    binário no próximo ciclo do timer (normalmente até 5
                    minutos) — nenhum serviço do BIND é tocado. Esta janela
                    atualiza sozinha.
                </p>
            </div>

            <div class="agent-actions">
                <button
                    type="button"
                    class="button button-secondary"
                    data-agent-upgrade-modal-close
                >
                    Fechar
                </button>
            </div>
        </section>
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
        document
            .querySelectorAll('[data-copy-target]')
            .forEach((button) => button.addEventListener('click', async () => {
                const command = document.getElementById(button.dataset.copyTarget)
                    ?.textContent?.trim();
                if (command) await navigator.clipboard.writeText(command);
            }));

        (() => {
            const startButton = document.querySelector('[data-discovery-start]');
            const modal = document.querySelector('[data-discovery-modal]');
            if (!startButton || !modal) return;

            const title = modal.querySelector('[data-discovery-modal-title]');
            const message = modal.querySelector('[data-discovery-modal-message]');
            const spinner = modal.querySelector('[data-discovery-spinner]');
            const summaryBlock = modal.querySelector('[data-discovery-summary]');
            const summaryCounts = modal.querySelector('[data-discovery-summary-counts]');
            const summaryList = modal.querySelector('[data-discovery-summary-list]');
            const reviewLink = modal.querySelector('[data-discovery-review-link]');

            const stateLabels = {
                new: 'Novo', exists: 'Já existe', conflict: 'Conflito',
                secondary_external: 'Secondary externo', not_supported: 'Não suportado',
                imported: 'Importado',
            };

            let pollTimer = null;
            let pollAttempts = 0;
            const maxPollAttempts = 200; // ~10min a cada 3s

            const openModal = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            };

            const closeModal = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                if (pollTimer) clearTimeout(pollTimer);
            };

            modal.querySelectorAll('[data-discovery-modal-close]')
                .forEach((button) => button.addEventListener('click', closeModal));

            const showWaiting = () => {
                title.textContent = 'Buscando zonas no servidor…';
                spinner.hidden = false;
                summaryBlock.hidden = true;
                reviewLink.hidden = true;
                message.hidden = false;
                message.textContent = 'Solicitação enviada. O agente executa a descoberta '
                    + 'somente leitura no próximo ciclo do timer (normalmente até 5 '
                    + 'minutos) — esta janela atualiza sozinha.';
            };

            const showRunning = () => {
                title.textContent = 'Descoberta em andamento…';
                spinner.hidden = false;
                summaryBlock.hidden = true;
                reviewLink.hidden = true;
                message.hidden = false;
                message.textContent = 'O agente está lendo rndc status, named-checkconf e as '
                    + 'zonas do BIND. Nada é escrito no servidor.';
            };

            const showFailed = (errorText) => {
                title.textContent = 'A descoberta falhou';
                spinner.hidden = true;
                summaryBlock.hidden = true;
                reviewLink.hidden = true;
                message.hidden = false;
                message.textContent = errorText || 'O agente reportou uma falha. Tente novamente.';
            };

            const showTimeout = () => {
                title.textContent = 'Ainda aguardando o agente';
                spinner.hidden = false;
                message.hidden = false;
                message.textContent = 'Isso está levando mais tempo que o normal. Pode fechar '
                    + 'esta janela — a descoberta continua em segundo plano e o card na tela '
                    + 'atualiza quando você recarregar a página.';
            };

            const showSucceeded = (summary, discoveryUrl) => {
                title.textContent = 'Descoberta concluída';
                spinner.hidden = true;
                message.hidden = true;

                if (summary) {
                    summaryBlock.hidden = false;
                    summaryCounts.innerHTML = '';
                    const counts = [
                        ['Zonas encontradas', summary.total],
                        ['Primary', summary.primary],
                        ['Secondary', summary.secondary],
                        ['Novas (importáveis)', summary.new],
                        ['Já existentes', summary.exists],
                        ['Em conflito', summary.conflict],
                        ['Não suportadas', summary.not_supported],
                    ];
                    counts.forEach(([label, value]) => {
                        const row = document.createElement('div');
                        row.innerHTML = `<dt>${label}</dt><dd>${value}</dd>`;
                        summaryCounts.appendChild(row);
                    });

                    summaryList.innerHTML = '';
                    (summary.zones || []).forEach((zone) => {
                        const span = document.createElement('span');
                        span.textContent = `${zone.name} — ${stateLabels[zone.state] || zone.state}`;
                        summaryList.appendChild(span);
                    });
                }

                if (discoveryUrl) {
                    reviewLink.href = discoveryUrl;
                    reviewLink.hidden = false;
                }
            };

            const poll = async (statusUrl) => {
                pollAttempts += 1;

                if (pollAttempts > maxPollAttempts) {
                    showTimeout();
                    return;
                }

                let payload;
                try {
                    const response = await fetch(statusUrl, {
                        headers: { Accept: 'application/json' },
                    });
                    payload = await response.json();
                } catch (error) {
                    pollTimer = setTimeout(() => poll(statusUrl), 5000);
                    return;
                }

                if (payload.status === 'succeeded') {
                    showSucceeded(payload.summary, payload.discovery_url);
                    return;
                }

                if (payload.status === 'failed') {
                    showFailed(payload.error);
                    return;
                }

                if (payload.status === 'running') {
                    showRunning();
                } else {
                    showWaiting();
                }

                pollTimer = setTimeout(() => poll(statusUrl), 3000);
            };

            startButton.addEventListener('click', async () => {
                if (startButton.disabled) return;

                const storeUrl = startButton.dataset.discoveryStoreUrl;
                const statusUrl = startButton.dataset.discoveryStatusUrl;
                const csrfToken = startButton.dataset.discoveryCsrf;

                pollAttempts = 0;
                showWaiting();
                openModal();

                try {
                    const response = await fetch(storeUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    if (!response.ok && response.status !== 409) {
                        showFailed('Não foi possível solicitar a descoberta.');
                        return;
                    }
                } catch (error) {
                    showFailed('Não foi possível conectar ao painel.');
                    return;
                }

                startButton.disabled = true;
                poll(statusUrl);
            });

            const initialStatus = startButton.dataset.discoveryInitialStatus;
            if (initialStatus === 'authorized' || initialStatus === 'running') {
                pollAttempts = 0;
                openModal();
                initialStatus === 'running' ? showRunning() : showWaiting();
                poll(startButton.dataset.discoveryStatusUrl);
            }
        })();

        (() => {
            const startButton = document.querySelector('[data-agent-upgrade-start]');
            const modal = document.querySelector('[data-agent-upgrade-modal]');
            if (!startButton || !modal) return;

            const title = modal.querySelector('[data-agent-upgrade-modal-title]');
            const message = modal.querySelector('[data-agent-upgrade-modal-message]');
            const spinner = modal.querySelector('[data-agent-upgrade-spinner]');

            let pollTimer = null;
            let pollAttempts = 0;
            const maxPollAttempts = 200; // ~10min a cada 3s

            const openModal = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
            };

            const closeModal = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                if (pollTimer) clearTimeout(pollTimer);
            };

            modal.querySelectorAll('[data-agent-upgrade-modal-close]')
                .forEach((button) => button.addEventListener('click', closeModal));

            const showWaiting = () => {
                title.textContent = 'Atualizando agente…';
                spinner.hidden = false;
                message.textContent = 'Solicitação enviada. O agente baixa, valida e substitui '
                    + 'o binário no próximo ciclo do timer (normalmente até 5 minutos) — nenhum '
                    + 'serviço do BIND é tocado. Esta janela atualiza sozinha.';
            };

            const showRunning = () => {
                title.textContent = 'Atualização em andamento…';
                spinner.hidden = false;
                message.textContent = 'Baixando e validando o novo binário (checksum, sintaxe, '
                    + '--version/--help) antes de substituir. O binário anterior é restaurado '
                    + 'automaticamente se a validação falhar.';
            };

            const showFailed = (errorText) => {
                title.textContent = 'A atualização falhou';
                spinner.hidden = true;
                message.textContent = errorText
                    || 'O agente reportou uma falha; o binário anterior foi preservado.';
            };

            const showTimeout = () => {
                title.textContent = 'Ainda aguardando o agente';
                spinner.hidden = false;
                message.textContent = 'Isso está levando mais tempo que o normal. Pode fechar '
                    + 'esta janela — a atualização continua em segundo plano.';
            };

            const showSucceeded = (result) => {
                spinner.hidden = true;
                if (result && result.changed === false) {
                    title.textContent = 'Agente já está atualizado';
                    message.textContent = 'Nada precisou ser alterado — binário e units já '
                        + 'estavam na versão mais recente.';
                } else {
                    title.textContent = 'Agente atualizado';
                    const parts = [];
                    if (result && result.binary_changed) parts.push('binário substituído');
                    if (result && result.units_changed) parts.push('units systemd atualizadas');
                    message.textContent = (parts.length ? parts.join(', ') + '. ' : '')
                        + 'A nova versão vale a partir do próximo ciclo do agente. Recarregue a '
                        + 'página para ver a versão atualizada.';
                }
            };

            const poll = async (statusUrl) => {
                pollAttempts += 1;
                if (pollAttempts > maxPollAttempts) {
                    showTimeout();
                    return;
                }

                let payload;
                try {
                    const response = await fetch(statusUrl, {
                        headers: { Accept: 'application/json' },
                    });
                    payload = await response.json();
                } catch (error) {
                    pollTimer = setTimeout(() => poll(statusUrl), 5000);
                    return;
                }

                if (payload.status === 'succeeded') {
                    showSucceeded(payload.result);
                    return;
                }
                if (payload.status === 'failed') {
                    showFailed(payload.error);
                    return;
                }
                if (payload.status === 'running') {
                    showRunning();
                } else {
                    showWaiting();
                }

                pollTimer = setTimeout(() => poll(statusUrl), 3000);
            };

            startButton.addEventListener('click', async () => {
                if (startButton.disabled) return;

                const storeUrl = startButton.dataset.agentUpgradeStoreUrl;
                const statusUrl = startButton.dataset.agentUpgradeStatusUrl;
                const csrfToken = startButton.dataset.agentUpgradeCsrf;

                pollAttempts = 0;
                showWaiting();
                openModal();

                try {
                    const response = await fetch(storeUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                    });

                    if (!response.ok && response.status !== 409) {
                        showFailed('Não foi possível solicitar a atualização.');
                        return;
                    }
                } catch (error) {
                    showFailed('Não foi possível conectar ao painel.');
                    return;
                }

                poll(statusUrl);
            });
        })();
    </script>
</body>
</html>
