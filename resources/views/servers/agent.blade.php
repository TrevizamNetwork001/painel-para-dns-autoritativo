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
            && (! $agent || $agent->revoked_at !== null)
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
            && (! $agent || $agent->revoked_at !== null)
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
        @elseif (! $agent || $agent->revoked_at !== null)
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
                    <div class="agent-enrollment-token" role="status">
                        <div>
                            <span class="status-badge status-warning">Ação necessária</span>
                            <strong>Token temporário para integrar o agente</strong>
                            <p>Copie este token e informe-o no prompt do agente no servidor.</p>
                        </div>

                        <div class="agent-code-box">
                            <code id="agent-enrollment-code">{{ $issuedEnrollmentCode }}</code>
                            <button type="button" class="button button-warning-soft" data-copy-target="agent-enrollment-code">
                                Copiar token
                            </button>
                        </div>

                        <small>Exibido uma única vez. Não será possível recuperá-lo após fechar ou recarregar esta tela.</small>
                    </div>

                    <div class="agent-choice-grid">
                        <div class="agent-choice-card">
                            <h3>Agente já instalado</h3>
                            <p>Execute o comando e cole o código no prompt seguro. O segredo não entra em argv nem no histórico shell.</p>
                            <div class="agent-command-box">
                                <code id="agent-enroll-command">$(command -v sudo || true) /usr/local/sbin/dns-center-agent --enroll --wait 0</code>
                                <button type="button" class="button button-secondary" data-copy-target="agent-enroll-command">Copiar</button>
                            </div>
                            <p>
                                Erro <code>unrecognized arguments: --enroll</code>? O agente instalado é anterior a
                                esta funcionalidade. Atualize somente o binário antes (não reinstala, não mexe no BIND):
                            </p>
                            <div class="agent-command-box">
                                <code id="agent-upgrade-command">curl -fsSL https://dnscenter.trevizamnetwork.com.br/install/agent_install.sh | $(command -v sudo || true) bash -s -- --upgrade-agent</code>
                                <button type="button" class="button button-secondary" data-copy-target="agent-upgrade-command">Copiar</button>
                            </div>
                        </div>

                        <div class="agent-choice-card">
                            <h3>Máquina sem agente</h3>
                            <p>Execute o comando abaixo. O instalador completo solicitará o código de vínculo pelo terminal.</p>
                            <div class="agent-command-box">
                                <code id="agent-install-command">wget -qO- https://dnscenter.trevizamnetwork.com.br/install/agent_install.sh | $(command -v sudo || true) bash -s -- --enroll</code>
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
        <div
            hidden
            data-agent-install-request-watcher
            data-status-url="{{ route('servers.agent.install-requests.status', $server) }}"
            data-current-request-id="{{ $latestInstallRequest?->id }}"
            data-current-request-status="{{ $latestInstallRequest?->status }}"
        ></div>
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

    <x-async-operation-modal
        name="discovery"
        eyebrow="BIND existente"
        title="Descoberta do BIND"
        badge="Somente leitura"
    >
        <div class="async-operation-body" data-discovery-modal-body>
            <div class="async-operation-status" aria-live="polite" aria-atomic="true">
                <span class="discovery-spinner" data-discovery-spinner aria-hidden="true"></span>
                <div>
                    <strong data-discovery-current-status>Aguardando execução do agente</strong>
                    <p data-discovery-modal-message>Solicitação criada e aguardando o próximo contato do agente.</p>
                </div>
            </div>

            <ol class="async-operation-timeline" data-discovery-timeline aria-label="Progresso da descoberta">
                <li data-discovery-step="request"><span aria-hidden="true">✓</span><strong>Solicitação</strong><small>Enviada</small></li>
                <li data-discovery-step="agent"><span aria-hidden="true">●</span><strong>Agente</strong><small>Aguardando</small></li>
                <li data-discovery-step="execution"><span aria-hidden="true">○</span><strong>Execução</strong><small>Pendente</small></li>
                <li data-discovery-step="result"><span aria-hidden="true">○</span><strong>Resultado</strong><small>Pendente</small></li>
            </ol>

            <div class="async-operation-meta" data-discovery-progress-meta>
                <span>Tempo decorrido <strong data-discovery-elapsed>00:00</strong></span>
                <span data-discovery-agent-note>Agente online</span>
            </div>

            <p class="async-operation-background-note" data-discovery-background-note>
                Você pode fechar esta janela. A operação continuará em segundo plano.
            </p>

            <div class="async-operation-summary" data-discovery-summary hidden>
                <p class="async-operation-total"><strong data-discovery-summary-total>0 zonas encontradas</strong></p>
                <dl class="async-operation-metrics" data-discovery-summary-counts></dl>
                <p class="async-operation-result-note" data-discovery-result-note></p>
                <div class="async-operation-zone-preview" data-discovery-summary-list></div>
            </div>
        </div>

        <footer class="async-operation-actions" data-discovery-modal-actions>
            <a class="button button-primary" data-discovery-review-link href="#" hidden>Revisar zonas encontradas</a>
            <button type="button" class="button button-secondary" data-discovery-modal-close data-discovery-close-label>Continuar em segundo plano</button>
        </footer>
    </x-async-operation-modal>

    <x-async-operation-modal
        name="agent-upgrade"
        eyebrow="Agente"
        title="Atualização do agente"
        badge="Sem tocar no BIND"
    >
        <div class="async-operation-body" data-agent-upgrade-modal-body>
            <div class="async-operation-status" aria-live="polite" aria-atomic="true">
                <span class="discovery-spinner" data-agent-upgrade-spinner aria-hidden="true"></span>
                <div>
                    <strong data-agent-upgrade-current-status>Aguardando o próximo ciclo do agente</strong>
                    <p data-agent-upgrade-modal-message>Solicitação registrada com sucesso. A atualização será executada no próximo ciclo de comunicação do agente.</p>
                </div>
            </div>

            <ol class="async-operation-timeline" data-agent-upgrade-timeline aria-label="Progresso da atualização">
                <li data-agent-upgrade-step="request"><span aria-hidden="true">✓</span><strong>Solicitação</strong><small>Enviada</small></li>
                <li data-agent-upgrade-step="agent"><span aria-hidden="true">●</span><strong>Agente</strong><small>Aguardando</small></li>
                <li data-agent-upgrade-step="execution"><span aria-hidden="true">○</span><strong>Download/validação</strong><small>Pendente</small></li>
                <li data-agent-upgrade-step="result"><span aria-hidden="true">○</span><strong>Resultado</strong><small>Pendente</small></li>
            </ol>

            <div class="async-operation-meta" data-agent-upgrade-progress-meta>
                <span>Tempo decorrido <strong data-agent-upgrade-elapsed>00:00</strong></span>
                <span data-agent-upgrade-agent-note>Agente online</span>
            </div>

            <p class="async-operation-background-note" data-agent-upgrade-background-note>
                Você pode fechar esta janela. A operação continuará em segundo plano.
            </p>

            <div class="async-operation-summary" data-agent-upgrade-summary hidden>
                <p class="async-operation-total" data-agent-upgrade-summary-title></p>
                <dl class="async-operation-metrics" data-agent-upgrade-summary-counts></dl>
                <p class="async-operation-result-note is-success" data-agent-upgrade-result-note></p>
            </div>
        </div>

        <footer class="async-operation-actions" data-agent-upgrade-modal-actions>
            <button type="button" class="button button-primary" data-agent-upgrade-retry hidden>Tentar novamente</button>
            <button type="button" class="button button-secondary" data-agent-upgrade-modal-close data-agent-upgrade-close-label>Continuar em segundo plano</button>
        </footer>
    </x-async-operation-modal>

    <div class="record-modal-backdrop" data-legacy-block-modal aria-hidden="true">
        <section class="record-modal" role="dialog" aria-modal="true" aria-labelledby="legacy-block-modal-title">
            <header class="record-modal-header">
                <div>
                    <p class="eyebrow">Conflito de configuração</p>
                    <h2 id="legacy-block-modal-title">Remover declaração de <span data-legacy-block-zone-label></span></h2>
                    <p class="agent-technical-value" data-legacy-block-zone-name-label></p>
                    <p>Isso remove só o bloco de declaração antigo do arquivo — não altera nem aplica a zona. Use "Aplicar agora" na tela da zona depois, como um passo separado.</p>
                </div>
                <button type="button" class="record-modal-close" data-legacy-block-close aria-label="Fechar">×</button>
            </header>
            <div class="domain-publication-action" style="padding: 1.2rem;">
                <p data-legacy-block-location class="agent-technical-value"></p>
                <pre class="apply-zones-diagnostics" data-legacy-block-snippet-preview></pre>
                <p data-legacy-block-state></p>
                <div class="legacy-block-progress" data-legacy-block-progress hidden>
                    <div class="legacy-block-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-legacy-block-progress-track>
                        <span data-legacy-block-progress-fill></span>
                    </div>
                    <div class="async-operation-meta">
                        <span data-legacy-block-progress-stage>Aguardando o agente</span>
                        <span><strong data-legacy-block-elapsed>0s</strong></span>
                    </div>
                </div>
                <div data-legacy-block-confirm-actions>
                    <button type="button" class="button button-secondary" data-legacy-block-close>Cancelar</button>
                    <button type="button" class="button button-primary" data-legacy-block-confirm>Confirmar remoção</button>
                </div>
            </div>
        </section>
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
        (() => {
            const watcher = document.querySelector('[data-agent-install-request-watcher]');
            if (!watcher) return;

            const currentId = watcher.dataset.currentRequestId || null;
            const currentStatus = watcher.dataset.currentRequestStatus || null;
            let checking = false;

            const check = async () => {
                if (checking || document.hidden) return;
                checking = true;
                try {
                    const response = await fetch(watcher.dataset.statusUrl, {
                        headers: { Accept: 'application/json' },
                    });
                    if (!response.ok) return;
                    const request = (await response.json()).request;
                    if (
                        request?.actionable
                        && (
                            String(request.id) !== currentId
                            || request.status !== currentStatus
                        )
                    ) {
                        window.location.reload();
                    }
                } catch (error) {
                    // Uma falha transitória será tentada novamente no próximo ciclo.
                } finally {
                    checking = false;
                }
            };

            const timer = window.setInterval(check, 3000);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) check();
            });
            window.addEventListener('pagehide', () => window.clearInterval(timer));
            check();
        })();

        document
            .querySelectorAll('[data-copy-target]')
            .forEach((button) => button.addEventListener('click', async () => {
                const command = document.getElementById(button.dataset.copyTarget)
                    ?.textContent?.trim();
                if (command) await navigator.clipboard.writeText(command);
            }));

        (() => {
            const button = document.querySelector('[data-discovery-start]');
            const modal = document.querySelector('[data-discovery-modal]');
            if (!button || !modal) return;

            const find = (selector) => modal.querySelector(selector);
            const title = find('[data-discovery-modal-title]');
            const status = find('[data-discovery-current-status]');
            const message = find('[data-discovery-modal-message]');
            const spinner = find('[data-discovery-spinner]');
            const summary = find('[data-discovery-summary]');
            const summaryTotal = find('[data-discovery-summary-total]');
            const counts = find('[data-discovery-summary-counts]');
            const zones = find('[data-discovery-summary-list]');
            const resultNote = find('[data-discovery-result-note]');
            const review = find('[data-discovery-review-link]');
            const closeLabel = find('[data-discovery-close-label]');
            const meta = find('[data-discovery-progress-meta]');
            const backgroundNote = find('[data-discovery-background-note]');
            const elapsed = find('[data-discovery-elapsed]');
            const agentNote = find('[data-discovery-agent-note]');
            const steps = Object.fromEntries([...modal.querySelectorAll('[data-discovery-step]')]
                .map((step) => [step.dataset.discoveryStep, step]));
            const card = {
                status: document.querySelector('[data-discovery-card-status]'),
                time: document.querySelector('[data-discovery-card-time]'),
                total: document.querySelector('[data-discovery-card-total]'),
                primary: document.querySelector('[data-discovery-card-primary]'),
                secondary: document.querySelector('[data-discovery-card-secondary]'),
            };
            const labels = { new: 'Nova', exists: 'Existente', conflict: 'Conflito', secondary_external: 'Secondary externo', not_supported: 'Não suportada', imported: 'Importada' };
            const statusUrl = button.dataset.discoveryStatusUrl;
            let pollTimer;
            let elapsedTimer;
            let polling = false;
            let submitting = false;
            let attempts = 0;
            let requestedAt = button.dataset.discoveryRequestedAt ? new Date(button.dataset.discoveryRequestedAt) : null;

            const setStep = (name, state, detail) => {
                const step = steps[name];
                step.className = `is-${state}`;
                step.querySelector('span').textContent = state === 'complete' ? '✓' : state === 'current' ? '●' : state === 'failed' ? '!' : '○';
                step.querySelector('small').textContent = detail;
            };
            const timeline = (state) => {
                setStep('request', 'complete', 'Enviada');
                setStep('agent', state === 'waiting' ? 'current' : 'complete', state === 'waiting' ? 'Aguardando' : 'Conectado');
                setStep('execution', state === 'running' ? 'current' : ['succeeded', 'failed'].includes(state) ? 'complete' : 'pending', state === 'running' ? 'Em andamento' : ['succeeded', 'failed'].includes(state) ? 'Concluída' : 'Pendente');
                setStep('result', state === 'succeeded' ? 'complete' : state === 'failed' ? 'failed' : 'pending', state === 'succeeded' ? 'Recebido' : state === 'failed' ? 'Falha' : 'Pendente');
            };
            const tick = () => {
                if (!requestedAt || Number.isNaN(requestedAt.getTime())) return;
                const seconds = Math.max(0, Math.floor((Date.now() - requestedAt.getTime()) / 1000));
                elapsed.textContent = `${Math.floor(seconds / 60).toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;
            };
            const startClock = () => {
                clearInterval(elapsedTimer);
                tick();
                elapsedTimer = setInterval(tick, 1000);
            };
            const open = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                find('.users-modal-close')?.focus();
            };
            const close = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                button.focus();
            };
            modal.querySelectorAll('[data-discovery-modal-close]').forEach((item) => item.addEventListener('click', close));

            const progress = () => {
                spinner.hidden = false;
                summary.hidden = true;
                review.hidden = true;
                meta.hidden = false;
                backgroundNote.hidden = false;
                message.hidden = false;
                closeLabel.textContent = 'Continuar em segundo plano';
            };
            const waiting = (online = true, lastSeen = null) => {
                progress();
                title.textContent = 'Descoberta do BIND em andamento';
                status.textContent = online ? 'Aguardando execução do agente' : 'Aguardando agente';
                message.textContent = online ? 'A solicitação será coletada no próximo ciclo de comunicação.' : 'A operação será processada quando o agente voltar a se comunicar.';
                agentNote.textContent = online ? 'Agente online' : 'Agente offline';
                if (!online && lastSeen) agentNote.textContent += ` · último contato há ${Math.max(0, Math.floor((Date.now() - new Date(lastSeen).getTime()) / 60000))} min`;
                timeline('waiting');
            };
            const running = () => {
                progress();
                title.textContent = 'Descoberta do BIND em andamento';
                status.textContent = 'Executando descoberta';
                message.textContent = 'O agente está analisando a configuração e as zonas do BIND.';
                agentNote.textContent = 'Agente online';
                timeline('running');
            };
            const failed = () => {
                title.textContent = 'Descoberta não concluída';
                status.textContent = 'A operação falhou';
                spinner.hidden = true;
                summary.hidden = true;
                review.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                message.hidden = false;
                message.textContent = 'O agente retornou uma falha durante a descoberta. Tente novamente com uma nova solicitação.';
                closeLabel.textContent = 'Fechar';
                timeline('failed');
                clearInterval(elapsedTimer);
                button.disabled = false;
                button.textContent = 'Tentar nova descoberta';
                button.dataset.discoveryActive = 'false';
            };
            const succeeded = (data, url) => {
                title.textContent = 'Descoberta concluída';
                status.textContent = 'Resultado recebido com sucesso';
                spinner.hidden = true;
                message.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                summary.hidden = false;
                closeLabel.textContent = 'Fechar';
                timeline('succeeded');
                clearInterval(elapsedTimer);
                summaryTotal.textContent = `${data.total} zona${data.total === 1 ? '' : 's'} encontrada${data.total === 1 ? '' : 's'}`;
                counts.innerHTML = '';
                [['Primary', data.primary], ['Secondary', data.secondary], ['Novas', data.new], ['Existentes', data.exists], ['Conflitos', data.conflict], ['Não suportadas', data.not_supported]]
                    .filter(([, value], index) => index < 3 || value > 0)
                    .forEach(([label, value]) => {
                        const row = document.createElement('div');
                        const term = document.createElement('dt');
                        const description = document.createElement('dd');
                        term.textContent = label;
                        description.textContent = value;
                        row.append(term, description);
                        counts.appendChild(row);
                    });
                const hasWarning = data.not_supported > 0 || data.conflict > 0;
                resultNote.className = `async-operation-result-note ${hasWarning ? 'is-warning' : 'is-success'}`;
                resultNote.textContent = data.not_supported > 0 ? `${data.not_supported} zona(s) requerem atenção por incompatibilidade.` : data.conflict > 0 ? `${data.conflict} conflito(s) requerem revisão.` : 'Nenhum conflito ou incompatibilidade detectado.';
                zones.innerHTML = '';
                if (data.total <= 6) {
                    (data.zones || []).forEach((zone) => {
                        const item = document.createElement('span');
                        item.textContent = `${zone.name} · ${labels[zone.state] || zone.state}`;
                        zones.appendChild(item);
                    });
                } else {
                    zones.textContent = 'A lista completa está disponível na revisão da descoberta.';
                }
                review.href = url;
                review.hidden = false;
                card.status.textContent = 'Descoberta concluída';
                card.status.className = 'status-badge status-success';
                card.time.textContent = 'agora';
                card.time.classList.remove('discovery-time-stale');
                document.querySelector('[data-discovery-stale-hint]')?.remove();
                card.total.textContent = `${data.total} zonas`;
                card.primary.textContent = data.primary;
                card.secondary.textContent = data.secondary;
                button.disabled = false;
                button.textContent = 'Executar nova descoberta';
                button.dataset.discoveryActive = 'false';
            };
            const schedule = (delay = 3000) => {
                clearTimeout(pollTimer);
                pollTimer = setTimeout(poll, delay);
            };
            const poll = async () => {
                if (polling) return;
                if (document.hidden) return schedule(10000);
                if (++attempts > 200) {
                    status.textContent = 'Ainda aguardando o agente';
                    message.textContent = 'A operação continua em segundo plano e será processada quando o agente se comunicar.';
                }
                polling = true;
                let payload;
                try {
                    payload = await (await fetch(statusUrl, { headers: { Accept: 'application/json' } })).json();
                } catch (error) {
                    polling = false;
                    return schedule(5000);
                }
                polling = false;
                if (payload.requested_at) {
                    requestedAt = new Date(payload.requested_at);
                    startClock();
                }
                if (payload.status === 'succeeded') return succeeded(payload.summary, payload.discovery_url);
                if (payload.status === 'failed') return failed();
                payload.status === 'running' ? running() : waiting(payload.agent_online, payload.agent_last_seen_at);
                schedule();
            };
            button.addEventListener('click', async () => {
                if (submitting) return;
                if (button.dataset.discoveryActive === 'true') {
                    open();
                    if (!pollTimer) poll();
                    return;
                }
                submitting = true;
                button.disabled = true;
                requestedAt = new Date();
                attempts = 0;
                startClock();
                waiting(button.dataset.discoveryAgentOnline === 'true');
                open();
                try {
                    const response = await fetch(button.dataset.discoveryStoreUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': button.dataset.discoveryCsrf } });
                    if (!response.ok && response.status !== 409) return failed();
                } catch (error) {
                    return failed();
                } finally {
                    submitting = false;
                }
                button.disabled = false;
                button.dataset.discoveryActive = 'true';
                button.textContent = 'Acompanhar descoberta';
                card.status.textContent = 'Descoberta em andamento';
                card.status.className = 'status-badge status-warning';
                poll();
            });
            if (button.dataset.discoveryActive === 'true') {
                button.dataset.discoveryInitialStatus === 'running' ? running() : waiting(button.dataset.discoveryAgentOnline === 'true');
                startClock();
                poll();
            }
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && button.dataset.discoveryActive === 'true') poll();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
            });
        })();

        (() => {
            const button = document.querySelector('[data-agent-upgrade-start]');
            const modal = document.querySelector('[data-agent-upgrade-modal]');
            if (!button || !modal) return;

            const find = (selector) => modal.querySelector(selector);
            const title = find('[data-agent-upgrade-modal-title]');
            const status = find('[data-agent-upgrade-current-status]');
            const message = find('[data-agent-upgrade-modal-message]');
            const spinner = find('[data-agent-upgrade-spinner]');
            const summary = find('[data-agent-upgrade-summary]');
            const summaryTitle = find('[data-agent-upgrade-summary-title]');
            const counts = find('[data-agent-upgrade-summary-counts]');
            const resultNote = find('[data-agent-upgrade-result-note]');
            const retry = find('[data-agent-upgrade-retry]');
            const closeLabel = find('[data-agent-upgrade-close-label]');
            const meta = find('[data-agent-upgrade-progress-meta]');
            const backgroundNote = find('[data-agent-upgrade-background-note]');
            const elapsed = find('[data-agent-upgrade-elapsed]');
            const agentNote = find('[data-agent-upgrade-agent-note]');
            const cardStatus = document.querySelector('[data-agent-upgrade-card-status]');
            const cardInstalled = document.querySelector('[data-agent-upgrade-installed-version]');
            const cardAvailable = document.querySelector('[data-agent-upgrade-available-version]');
            const cardAvailableLabel = document.querySelector('[data-agent-upgrade-available-label]');
            const cardBindVersion = document.querySelector('[data-agent-upgrade-card-version]');
            const steps = Object.fromEntries([...modal.querySelectorAll('[data-agent-upgrade-step]')]
                .map((step) => [step.dataset.agentUpgradeStep, step]));
            const statusUrl = button.dataset.agentUpgradeStatusUrl;
            const isVersion = (value) => typeof value === 'string' && /^\d+\.\d+\.\d+$/.test(value);
            const compareVersions = (a, b) => {
                const pa = a.split('.').map(Number);
                const pb = b.split('.').map(Number);
                for (let index = 0; index < 3; index++) {
                    if (pa[index] !== pb[index]) return pa[index] - pb[index];
                }
                return 0;
            };
            let pollTimer;
            let elapsedTimer;
            let polling = false;
            let submitting = false;
            let attempts = 0;
            let requestedAt = button.dataset.agentUpgradeRequestedAt ? new Date(button.dataset.agentUpgradeRequestedAt) : null;
            let targetVersion = isVersion(button.dataset.agentUpgradeAvailable) ? button.dataset.agentUpgradeAvailable : null;

            const setStep = (name, state, detail) => {
                const step = steps[name];
                step.className = `is-${state}`;
                step.querySelector('span').textContent = state === 'complete' ? '✓' : state === 'current' ? '●' : state === 'failed' ? '!' : '○';
                step.querySelector('small').textContent = detail;
            };
            const timeline = (state) => {
                setStep('request', 'complete', 'Enviada');
                setStep('agent', state === 'waiting' ? 'current' : state === 'expired' ? 'failed' : 'complete', state === 'waiting' ? 'Aguardando' : state === 'expired' ? 'Não coletada' : 'Conectado');
                setStep('execution', state === 'running' ? 'current' : ['awaiting', 'succeeded', 'failed'].includes(state) ? 'complete' : 'pending', state === 'running' ? 'Em andamento' : ['awaiting', 'succeeded', 'failed'].includes(state) ? 'Concluída' : 'Pendente');
                setStep('result', state === 'succeeded' ? 'complete' : ['failed', 'expired'].includes(state) ? 'failed' : state === 'awaiting' ? 'current' : 'pending', state === 'succeeded' ? 'Recebido' : state === 'failed' ? 'Falha' : state === 'expired' ? 'Expirada' : state === 'awaiting' ? 'Confirmando' : 'Pendente');
            };
            const tick = () => {
                if (!requestedAt || Number.isNaN(requestedAt.getTime())) return;
                const seconds = Math.max(0, Math.floor((Date.now() - requestedAt.getTime()) / 1000));
                elapsed.textContent = `${Math.floor(seconds / 60).toString().padStart(2, '0')}:${(seconds % 60).toString().padStart(2, '0')}`;
            };
            const startClock = () => {
                clearInterval(elapsedTimer);
                tick();
                elapsedTimer = setInterval(tick, 1000);
            };
            const open = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.style.overflow = 'hidden';
                find('.users-modal-close')?.focus();
            };
            const close = () => {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
                button.focus();
            };
            modal.querySelectorAll('[data-agent-upgrade-modal-close]').forEach((item) => item.addEventListener('click', close));

            const renderCard = (installed, available, inFlight) => {
                if (cardInstalled) cardInstalled.textContent = installed || 'Não informada';
                if (cardAvailableLabel) cardAvailableLabel.textContent = inFlight ? 'Alvo' : 'Disponível';
                if (cardAvailable) cardAvailable.textContent = available || 'Não informada';
                if (cardBindVersion && installed) cardBindVersion.textContent = installed;

                const updateAvailable = !inFlight && isVersion(installed) && isVersion(available) && compareVersions(available, installed) > 0;
                let statusLabel = 'Atualizado';
                let statusClass = 'status-success';
                if (inFlight) {
                    statusLabel = 'Atualização em andamento';
                    statusClass = 'status-warning';
                } else if (!installed) {
                    statusLabel = 'Versão instalada desconhecida';
                    statusClass = 'status-neutral';
                } else if (updateAvailable) {
                    statusLabel = 'Atualização disponível';
                    statusClass = 'status-warning';
                }
                if (cardStatus) {
                    cardStatus.textContent = statusLabel;
                    cardStatus.className = `status-badge ${statusClass}`;
                }

                if (!inFlight) {
                    button.classList.remove('button-primary', 'button-secondary');
                    if (!installed) {
                        button.textContent = 'Atualizar software do agente';
                        button.classList.add('button-secondary');
                    } else if (updateAvailable) {
                        button.textContent = `Atualizar para ${available}`;
                        button.classList.add('button-primary');
                    } else {
                        button.textContent = 'Atualizar agente';
                        button.classList.add('button-secondary');
                    }
                }
            };

            const progress = () => {
                spinner.hidden = false;
                summary.hidden = true;
                retry.hidden = true;
                meta.hidden = false;
                backgroundNote.hidden = false;
                message.hidden = false;
                closeLabel.textContent = 'Continuar em segundo plano';
            };
            const waiting = (online = true, lastSeen = null) => {
                progress();
                title.textContent = targetVersion ? `Atualizar para ${targetVersion}` : 'Atualização do agente';
                status.textContent = online ? 'Aguardando o próximo ciclo do agente' : 'Aguardando agente';
                message.textContent = online ? 'Solicitação registrada com sucesso. A atualização será executada no próximo ciclo de comunicação do agente.' : 'A operação será processada quando o agente voltar a se comunicar.';
                agentNote.textContent = online ? 'Agente online' : 'Agente offline';
                if (!online && lastSeen) agentNote.textContent += ` · último contato há ${Math.max(0, Math.floor((Date.now() - new Date(lastSeen).getTime()) / 60000))} min`;
                timeline('waiting');
            };
            const running = () => {
                progress();
                title.textContent = targetVersion ? `Atualizando para ${targetVersion}` : 'Atualização do agente';
                status.textContent = 'Atualização em andamento';
                message.textContent = 'O agente está processando o pacote e aplicando a nova versão.';
                agentNote.textContent = 'Agente online';
                timeline('running');
            };
            const awaitingConfirmation = () => {
                progress();
                title.textContent = targetVersion ? `Atualizando para ${targetVersion}` : 'Atualização do agente';
                status.textContent = 'Aguardando confirmação da nova versão';
                message.textContent = 'O binário foi substituído. Aguardando o próximo contato do agente confirmar a versão instalada.';
                agentNote.textContent = 'Agente online';
                timeline('awaiting');
            };
            const failed = (errorText, installed, available) => {
                title.textContent = 'Falha na atualização';
                status.textContent = 'Falha na atualização';
                spinner.hidden = true;
                summary.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                message.hidden = false;
                message.textContent = `Versão instalada permanece: ${installed || 'não confirmada'}. ${errorText || 'O agente não conseguiu concluir a atualização.'} Revise o status e tente novamente.`;
                closeLabel.textContent = 'Fechar';
                retry.hidden = false;
                timeline('failed');
                clearInterval(elapsedTimer);
                button.disabled = false;
                button.dataset.agentUpgradeActive = 'false';
                renderCard(installed ?? (isVersion(cardInstalled?.textContent) ? cardInstalled.textContent : null), available ?? targetVersion, false);
            };
            const requestRejected = (errorText) => {
                title.textContent = 'Atualização não solicitada';
                status.textContent = 'Não foi possível enviar a solicitação';
                spinner.hidden = true;
                summary.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                message.hidden = false;
                message.textContent = errorText;
                closeLabel.textContent = 'Fechar';
                retry.hidden = false;
                setStep('request', 'failed', 'Não enviada');
                setStep('agent', 'failed', 'Indisponível');
                setStep('execution', 'pending', 'Pendente');
                setStep('result', 'failed', 'Interrompida');
                clearInterval(elapsedTimer);
                button.disabled = false;
                button.dataset.agentUpgradeActive = 'false';
            };
            const expired = (errorText, installed, available) => {
                title.textContent = 'Solicitação expirada';
                status.textContent = 'O prazo da atualização terminou';
                spinner.hidden = true;
                summary.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                message.hidden = false;
                message.textContent = errorText || 'A solicitação expirou antes de o agente voltar a se comunicar. Verifique o agente e tente novamente.';
                closeLabel.textContent = 'Fechar';
                retry.hidden = false;
                timeline('expired');
                clearInterval(elapsedTimer);
                button.disabled = false;
                button.dataset.agentUpgradeActive = 'false';
                renderCard(installed ?? (isVersion(cardInstalled?.textContent) ? cardInstalled.textContent : null), available ?? targetVersion, false);
            };
            const succeeded = (result, installedVersionConfirmed, targetVersionKnown) => {
                const changed = result?.changed !== false;
                const finalVersion = installedVersionConfirmed || targetVersionKnown || result?.previous_version;
                title.textContent = changed ? `Atualizado para ${finalVersion || '—'}` : 'Software revalidado';
                status.textContent = changed ? 'Atualização concluída' : 'Versão reinstalada com sucesso';
                spinner.hidden = true;
                message.hidden = true;
                meta.hidden = true;
                backgroundNote.hidden = true;
                summary.hidden = false;
                retry.hidden = true;
                closeLabel.textContent = 'Fechar';
                timeline('succeeded');
                clearInterval(elapsedTimer);
                summaryTitle.textContent = changed
                    ? 'Software do agente atualizado com sucesso.'
                    : `Versão ${finalVersion || 'atual'} reinstalada com sucesso.`;
                counts.innerHTML = '';
                const rows = changed
                    ? [
                        ['Versão anterior', result?.previous_version || '—'],
                        ['Versão atual', installedVersionConfirmed || '—'],
                        ['Binário', result?.binary_changed ? 'substituído' : 'inalterado'],
                        ['Units systemd', result?.units_changed ? 'atualizadas' : 'inalteradas'],
                    ]
                    : [['Versão instalada', finalVersion || '—']];
                rows.forEach(([label, value]) => {
                    const row = document.createElement('div');
                    const term = document.createElement('dt');
                    const description = document.createElement('dd');
                    term.textContent = label;
                    description.textContent = value;
                    row.append(term, description);
                    counts.appendChild(row);
                });
                resultNote.textContent = changed
                    ? 'A comunicação com o painel foi preservada.'
                    : 'Binário e units já estavam na versão mais recente.';
                button.disabled = false;
                button.dataset.agentUpgradeActive = 'false';
                renderCard(installedVersionConfirmed || finalVersion, targetVersionKnown || targetVersion, false);
                const dashboardUrl = button.dataset.agentUpgradeDashboardUrl;
                if (dashboardUrl) {
                    setTimeout(() => {
                        close();
                        window.location.assign(dashboardUrl);
                    }, 2500);
                }
            };
            const schedule = (delay = 3000) => {
                clearTimeout(pollTimer);
                pollTimer = setTimeout(poll, delay);
            };
            const poll = async () => {
                if (polling) return;
                if (document.hidden) return schedule(10000);
                if (++attempts > 200) {
                    status.textContent = 'Ainda aguardando o agente';
                    message.textContent = 'A operação continua em segundo plano e será processada quando o agente se comunicar.';
                    return;
                }
                polling = true;
                let payload;
                try {
                    payload = await (await fetch(statusUrl, { headers: { Accept: 'application/json' } })).json();
                } catch (error) {
                    polling = false;
                    return schedule(5000);
                }
                polling = false;
                if (payload.requested_at) {
                    requestedAt = new Date(payload.requested_at);
                    startClock();
                }
                if (payload.target_version) targetVersion = payload.target_version;
                if (payload.status === 'expired') return expired(payload.error, payload.installed_version, payload.target_version);
                if (payload.status === 'failed') return failed(payload.error, payload.installed_version, payload.target_version);
                if (payload.status === 'succeeded') {
                    if (payload.version_confirmed) return succeeded(payload.result, payload.installed_version, payload.target_version);
                    awaitingConfirmation();
                    schedule();
                    return;
                }
                payload.status === 'running' ? running() : waiting(payload.agent_online, payload.agent_last_seen_at);
                schedule();
            };
            const start = async () => {
                if (submitting) return;
                if (button.dataset.agentUpgradeActive === 'true') {
                    open();
                    if (!pollTimer) poll();
                    return;
                }
                submitting = true;
                button.disabled = true;
                requestedAt = new Date();
                attempts = 0;
                startClock();
                retry.hidden = true;
                targetVersion = isVersion(button.dataset.agentUpgradeAvailable) ? button.dataset.agentUpgradeAvailable : targetVersion;
                waiting(button.dataset.agentUpgradeAgentOnline === 'true');
                open();
                try {
                    const response = await fetch(button.dataset.agentUpgradeStoreUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': button.dataset.agentUpgradeCsrf } });
                    if (!response.ok) {
                        const payload = await response.json().catch(() => ({}));
                        return requestRejected(payload.message || 'Não foi possível solicitar a atualização.');
                    }
                } catch (error) {
                    return requestRejected('Não foi possível conectar ao painel.');
                } finally {
                    submitting = false;
                }
                button.disabled = false;
                button.dataset.agentUpgradeActive = 'true';
                renderCard(isVersion(cardInstalled?.textContent) ? cardInstalled.textContent : null, targetVersion, true);
                poll();
            };
            button.addEventListener('click', start);
            retry.addEventListener('click', start);
            if (button.dataset.agentUpgradeActive === 'true') {
                button.dataset.agentUpgradeInitialStatus === 'running' ? running() : waiting(button.dataset.agentUpgradeAgentOnline === 'true');
                startClock();
                poll();
            }
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden && button.dataset.agentUpgradeActive === 'true') poll();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
            });
        })();

        (() => {
            const modal = document.querySelector('[data-legacy-block-modal]');
            const zoneLabel = document.querySelector('[data-legacy-block-zone-label]');
            const location = document.querySelector('[data-legacy-block-location]');
            const snippetPreview = document.querySelector('[data-legacy-block-snippet-preview]');
            const stateText = document.querySelector('[data-legacy-block-state]');
            const confirmActions = document.querySelector('[data-legacy-block-confirm-actions]');
            const confirmButton = document.querySelector('[data-legacy-block-confirm]');
            const zoneNameLabel = document.querySelector('[data-legacy-block-zone-name-label]');
            const progressBox = document.querySelector('[data-legacy-block-progress]');
            const progressTrack = document.querySelector('[data-legacy-block-progress-track]');
            const progressFill = document.querySelector('[data-legacy-block-progress-fill]');
            const progressStage = document.querySelector('[data-legacy-block-progress-stage]');
            const progressElapsed = document.querySelector('[data-legacy-block-elapsed]');

            if (!modal || !zoneLabel || !location || !stateText || !confirmActions || !confirmButton) return;

            let activeButton = null;
            let pollTimer = null;
            let tickTimer = null;
            let startedAt = 0;
            let operationStatus = 'authorized';
            // O agente consulta o painel a cada ~30s; a barra mostra o tempo decorrido
            // dentro dessa janela e só chega a 100% quando o agente confirma.
            const AGENT_WINDOW_SECONDS = 35;

            const renderProgress = () => {
                const elapsed = Math.floor((Date.now() - startedAt) / 1000);
                let percent = Math.min(elapsed / AGENT_WINDOW_SECONDS, 1) * 85;
                let stage = 'Aguardando o agente pegar a operação';

                if (operationStatus === 'running') {
                    percent = Math.max(percent, 90);
                    stage = 'Agente removendo o bloco';
                } else if (operationStatus === 'succeeded') {
                    percent = 100;
                    stage = 'Concluído';
                } else if (elapsed > AGENT_WINDOW_SECONDS) {
                    stage = 'Agente demorando mais que o normal';
                }

                progressFill.style.width = `${percent}%`;
                progressTrack.setAttribute('aria-valuenow', String(Math.round(percent)));
                progressStage.textContent = stage;
                progressElapsed.textContent = `${elapsed}s`;
            };

            const startProgress = () => {
                startedAt = Date.now();
                operationStatus = 'authorized';
                progressBox.hidden = false;
                renderProgress();
                window.clearInterval(tickTimer);
                tickTimer = window.setInterval(renderProgress, 1000);
            };

            const stopProgress = (hide = true) => {
                window.clearInterval(tickTimer);
                tickTimer = null;
                if (hide) progressBox.hidden = true;
            };

            const open = () => {
                modal.classList.add('is-open');
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('has-open-modal');
            };

            const close = () => {
                window.clearTimeout(pollTimer);
                pollTimer = null;
                stopProgress();
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('has-open-modal');
            };

            const confirming = () => {
                stateText.textContent = 'Confirma remover esta declaração antiga?';
                stopProgress();
                confirmActions.hidden = false;
                confirmButton.disabled = false;
                modal.querySelectorAll('.apply-zones-diagnostics[data-legacy-block-error]').forEach((el) => el.remove());
            };

            const waiting = () => {
                stateText.textContent = 'Solicitação enviada.';
                confirmActions.hidden = true;
                startProgress();
            };

            const REFRESH_WAIT_MS = 30000;

            const succeeded = (statusUrl, waitedSince = Date.now()) => {
                operationStatus = 'succeeded';
                renderProgress();
                confirmActions.hidden = true;
                stateText.textContent = 'Bloco removido. Atualizando o estado do servidor…';

                const reload = () => {
                    stopProgress(false);
                    stateText.textContent = 'Bloco removido. A página vai atualizar.';
                    window.setTimeout(() => window.location.reload(), 600);
                };

                const check = async () => {
                    let refreshed = false;

                    try {
                        const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        refreshed = (await response.json()).readiness_refreshed === true;
                    } catch (error) {
                        refreshed = false;
                    }

                    if (refreshed || Date.now() - waitedSince > REFRESH_WAIT_MS) return reload();

                    pollTimer = window.setTimeout(check, 2000);
                };

                check();
            };

            const failed = (message, diagnostics) => {
                stateText.textContent = message || 'Falha ao remover.';
                confirmActions.hidden = true;
                stopProgress();

                const detail = diagnostics?.stderr || diagnostics?.stdout;

                if (diagnostics?.command && detail) {
                    const pre = document.createElement('pre');
                    pre.className = 'apply-zones-diagnostics';
                    pre.dataset.legacyBlockError = 'true';
                    pre.textContent = `${diagnostics.command}:\n${detail}`;
                    stateText.insertAdjacentElement('afterend', pre);
                }
            };

            const poll = async (statusUrl) => {
                let payload;

                try {
                    const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                    payload = await response.json();
                } catch (error) {
                    pollTimer = window.setTimeout(() => poll(statusUrl), 4000);
                    return;
                }

                if (payload.status === 'running') {
                    operationStatus = 'running';
                    renderProgress();
                }

                if (payload.status === 'succeeded') return succeeded(statusUrl);
                if (payload.status === 'failed' || payload.status === 'expired') return failed(payload.error, payload.result?.diagnostics);

                pollTimer = window.setTimeout(() => poll(statusUrl), 4000);
            };

            confirmButton.addEventListener('click', async () => {
                if (!activeButton) return;

                confirmButton.disabled = true;
                waiting();

                const data = activeButton.dataset;
                const storeUrl = data.legacyBlockStoreUrl;

                try {
                    const response = await fetch(storeUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': data.legacyBlockCsrf,
                        },
                        body: JSON.stringify({
                            source_file: data.legacyBlockSourceFile,
                            start_line: Number(data.legacyBlockStartLine),
                            end_line: Number(data.legacyBlockEndLine),
                            hash: data.legacyBlockHash,
                            zone_name: data.legacyBlockZoneName,
                        }),
                    });

                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        return failed(payload.message);
                    }

                    const statusUrl = data.legacyBlockStatusUrlTemplate.replace('OPERATION_ID', payload.operation_id);
                    poll(statusUrl);
                } catch (error) {
                    return failed('Não foi possível conectar ao painel.');
                }
            });

            document.querySelectorAll('[data-legacy-block-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    activeButton = button;
                    const data = button.dataset;
                    zoneLabel.textContent = data.legacyBlockCidr || data.legacyBlockZoneName || '';
                    zoneNameLabel.textContent = data.legacyBlockCidr ? (data.legacyBlockZoneName || '') : '';
                    zoneNameLabel.hidden = !data.legacyBlockCidr;
                    location.textContent = `${data.legacyBlockSourceFile}:${data.legacyBlockStartLine}`;
                    snippetPreview.textContent = data.legacyBlockSnippet || '';
                    snippetPreview.hidden = !data.legacyBlockSnippet;
                    confirming();
                    open();
                });
            });

            document.querySelectorAll('[data-legacy-block-close]').forEach((button) => {
                button.addEventListener('click', close);
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) close();
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && modal.classList.contains('is-open')) close();
            });
        })();
    </script>
</body>
</html>
