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
                <h1>{{ $agent && $agent->revoked_at === null ? $server->hostname : 'Onboarding do agente' }}</h1>
                <p>
                    {{ $agent && $agent->revoked_at === null
                        ? 'Operação e observabilidade do agente DNS.'
                        : 'Vincule o agente local ao servidor '.$server->name.'.' }}
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

        @if ($agent && $agent->revoked_at === null)
            @include('servers.partials.agent-operational')
        @else
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

                <div class="agent-state">
                    <strong>{{ $agent?->revoked_at ? 'Credencial revogada — novo vínculo necessário' : 'Agente não vinculado' }}</strong>

                    <span>
                        A instalação e o vínculo são etapas independentes.
                        Gere um vínculo para instalar ou vincular novamente sem tocar no BIND.
                    </span>
                </div>
            </article>
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
        @elseif (
            $latestInstallRequest
            && $latestInstallRequest->status === 'approved'
            && $latestInstallRequest->claimed_at === null
            && ! $agent
        )
            <section class="panel-card agent-code-panel">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">Aprovação administrativa</p>
                        <h2>Aguardando retirada pelo agente</h2>
                    </div>

                    <span class="status-badge status-warning">
                        Aprovado, credencial não retirada
                    </span>
                </div>

                <div class="agent-security-list">
                    <span>Hostname: {{ $latestInstallRequest->reported_hostname }}</span>
                    <span>Endereço observado: {{ $latestInstallRequest->registered_ip ?? 'não informado' }}</span>
                    <span>Aprovado em: {{ $latestInstallRequest->approved_at?->format('d/m/Y H:i') }}</span>
                </div>

                <p>
                    Esta solicitação foi aprovada, mas o agente ainda não
                    retirou a credencial (o timer de aprovação roda a cada 5
                    minutos). Se o agente foi reenrolado com um novo código
                    antes de retirar esta credencial, a solicitação anterior
                    fica presa neste estado — o painel bloqueia reenvios
                    automáticos para não sobrescrever uma aprovação já
                    concedida. Cancele abaixo para liberar um novo vínculo.
                </p>

                <div class="agent-actions">
                    <form
                        method="POST"
                        action="{{ route(
                            'servers.agent.install-requests.reject',
                            [$server, $latestInstallRequest]
                        ) }}"
                        onsubmit="return confirm(
                            'Cancelar esta aprovação e liberar um novo vínculo?'
                        )"
                    >
                        @csrf
                        <button type="submit" class="button button-danger-soft">
                            Cancelar aprovação
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

        @if (! $agent || $agent->revoked_at !== null)
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
        @endif
    </main>

    <div class="servers-modal" data-discovery-modal aria-hidden="true">
        <button
            type="button"
            class="servers-modal-backdrop"
            data-discovery-modal-close
            aria-label="Fechar"
        ></button>

        <section class="servers-modal-dialog discovery-modal-dialog agent-process-modal">
            <header class="servers-modal-header">
                <div>
                    <p class="eyebrow">BIND existente</p>
                    <h2 data-discovery-modal-title>Buscando zonas no servidor…</h2>
                </div>

                <button
                    type="button"
                    class="users-modal-close"
                    data-discovery-modal-close
                    aria-label="Fechar janela"
                    title="Fechar"
                >
                    ×
                </button>
            </header>

            <div data-discovery-modal-body>
                <div class="discovery-spinner" data-discovery-spinner></div>

                <p data-discovery-modal-message>
                    Solicitação enviada ao agente. A descoberta será iniciada
                    no próximo ciclo de comunicação, normalmente em até cinco
                    minutos. Esta janela será atualizada automaticamente.
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
                message.textContent = 'Solicitação enviada ao agente. A descoberta será '
                    + 'iniciada no próximo ciclo de comunicação, normalmente em até cinco '
                    + 'minutos. Esta janela será atualizada automaticamente.';
            };

            const showRunning = () => {
                title.textContent = 'Descoberta em andamento…';
                spinner.hidden = false;
                summaryBlock.hidden = true;
                reviewLink.hidden = true;
                message.hidden = false;
                message.textContent = 'O agente está consultando o status, a configuração e '
                    + 'as zonas do BIND. Nenhuma alteração será feita no servidor.';
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
