@php
    $readiness = $server->bind_readiness ?? [];
    $readinessReceived = $server->bind_readiness_at !== null;
    $bindInstalled = (bool) data_get($readiness, 'bind_installed', false);
    $bindActive = (bool) data_get($readiness, 'service.active', false);
    $tcpReady = (bool) data_get($readiness, 'listeners.tcp_53', false);
    $udpReady = (bool) data_get($readiness, 'listeners.udp_53', false);
    $readinessIssueCount = collect([$bindInstalled, $bindActive, $tcpReady, $udpReady])->filter(fn ($value) => ! $value)->count();
    $readinessState = $server->agent_status === 'blocked' ? 'blocked' : ($readinessReceived && $readinessIssueCount === 0 ? 'ok' : 'warning');
    $agentVersion = $agent->metadata['agent_version'] ?? $server->agent_version ?? 'Não informada';
    $bindVersion = data_get($readiness, 'bind_version') ?: ($server->bind_version ?: 'Não informada');
    $lastContact = $agent->last_seen_at ?? $server->last_seen_at;
    $discoveryAt = $latestDiscoveryOperation?->completed_at ?? $latestDiscoveryOperation?->authorized_at;
    $discoverySucceeded = $latestDiscoveryOperation?->status === 'succeeded';
    $discoveryStale = $discoverySucceeded && $discoveryAt && $discoveryAt->lt(now()->subDay());
    $discoveryInFlight = $latestDiscoveryOperation && in_array($latestDiscoveryOperation->status, ['authorized', 'running'], true);
    $upgradeInFlight = $latestAgentUpgradeOperation && in_array($latestAgentUpgradeOperation->status, ['authorized', 'running'], true);
    $upgradeStatusLabel = match (true) {
        $upgradeInFlight => 'Atualização em andamento',
        $installedAgentVersion === null => 'Versão instalada desconhecida',
        $agentUpdateAvailable => 'Atualização disponível',
        default => 'Atualizado',
    };
    $upgradeStatusBadgeClass = match (true) {
        $upgradeInFlight => 'status-warning',
        $installedAgentVersion === null => 'status-neutral',
        $agentUpdateAvailable => 'status-warning',
        default => 'status-success',
    };
    $upgradeButtonLabel = match (true) {
        $upgradeInFlight => 'Acompanhar atualização',
        $installedAgentVersion === null => 'Atualizar software do agente',
        $agentUpdateAvailable => 'Atualizar para '.$availableAgentVersion,
        default => 'Atualizar agente',
    };
    $upgradeButtonClass = (! $upgradeInFlight && $agentUpdateAvailable) ? 'button-primary' : 'button-secondary';
    $imported = $discoveryStats['imported'] > 0;
    $publicationStatus = match ($latestPublication?->status) {
        'pending' => 'Pendente', 'downloaded' => 'Baixada', 'applying' => 'Aplicando',
        'applied' => 'Aplicada', 'failed' => 'Falhou', default => $latestPublication?->status,
    };
    $agentStatusLabel = match ($server->agent_status) {
        'online' => 'Online', 'offline' => 'Offline', 'blocked' => 'Bloqueado',
        'pending' => 'Aguardando contato', default => ucfirst(str_replace('_', ' ', $server->agent_status)),
    };
    $hostInventory = $server->inventory ?? [];
    $cpuLoadPercent = data_get($hostInventory, 'cpu_load_percent');
    $memoryUsedPercent = data_get($hostInventory, 'memory_used_percent');
    $diskUsedPercent = data_get($hostInventory, 'disk_used_percent');
    $uptimeSeconds = data_get($hostInventory, 'uptime_seconds');
    $uptimeLabel = is_numeric($uptimeSeconds)
        ? ((int) floor($uptimeSeconds / 86400)).'d '.((int) floor(($uptimeSeconds % 86400) / 3600)).'h'
        : 'Não informado';
@endphp

<section class="agent-ops-hero" aria-labelledby="agent-operational-title">
    <div class="agent-ops-identity">
        <p class="eyebrow">Servidor</p>
        <h2 id="agent-operational-title">{{ $server->name }}</h2>
        <p>{{ $server->hostname }}</p>
    </div>
    <div class="agent-ops-badges" aria-label="Estado operacional">
        <span class="status-badge {{ $server->agent_status === 'online' ? 'status-success' : 'status-danger' }}">{{ $agentStatusLabel }}</span>
        <span class="status-badge status-neutral">{{ ucfirst($server->role) }}</span>
        <span class="status-badge status-neutral">{{ match ($server->environment) {'production' => 'Produção', 'staging' => 'Homologação', 'development' => 'Desenvolvimento', default => ucfirst($server->environment)} }}</span>
        <span class="status-badge {{ match ($readinessState) {'ok' => 'status-success', 'blocked' => 'status-danger', default => 'status-warning'} }}">Readiness: {{ match ($readinessState) {'ok' => 'OK', 'blocked' => 'BLOCKED', default => 'WARNING'} }}</span>
    </div>
</section>

@if (! empty($legacyZoneConflicts))
    <section class="panel-card agent-legacy-conflict-card" aria-labelledby="legacy-conflict-title">
        <header class="agent-section-header">
            <div>
                <p class="eyebrow">Conflito de configuração</p>
                <h2 id="legacy-conflict-title">Declaração de zona legada detectada</h2>
            </div>
            <span class="status-badge status-danger">{{ count($legacyZoneConflicts) }} zona{{ count($legacyZoneConflicts) === 1 ? '' : 's' }}</span>
        </header>
        <p>Este servidor tem uma declaração estática de zona fora do include gerenciado pelo DNS Center — o apply será recusado pelo <code>named-checkconf</code> até o bloco antigo ser removido.</p>
        <ul class="agent-legacy-conflict-list">
            @foreach ($legacyZoneConflicts as $conflict)
                @php
                    $conflictBlock = app(\App\Services\ReverseZoneNameCalculator::class)->toIpv4Cidr($conflict['zone_name'])
                        ?? app(\App\Services\ReverseZoneNameCalculator::class)->toIpv6Prefix($conflict['zone_name']);
                @endphp
                <li>
                    <strong>{{ $conflictBlock ?? $conflict['zone_name'] }}</strong>
                    @if ($conflictBlock)<span class="agent-technical-value">{{ $conflict['zone_name'] }}</span>@endif
                    <span class="agent-technical-value">{{ $conflict['source_file'] }}:{{ $conflict['start_line'] }}@if ($conflict['end_line'] > $conflict['start_line'])–{{ $conflict['end_line'] }}@endif</span>
                    @if ($conflict['declared_type'])<span class="status-badge status-neutral">type {{ $conflict['declared_type'] }}</span>@endif
                    @if ($conflict['snippet'])<pre class="apply-zones-diagnostics">{{ $conflict['snippet'] }}</pre>@endif
                    <button
                        type="button"
                        class="button button-secondary"
                        data-legacy-block-open
                        data-legacy-block-zone-name="{{ $conflict['zone_name'] }}"
                        data-legacy-block-cidr="{{ $conflictBlock ?? '' }}"
                        data-legacy-block-source-file="{{ $conflict['source_file'] }}"
                        data-legacy-block-start-line="{{ $conflict['start_line'] }}"
                        data-legacy-block-end-line="{{ $conflict['end_line'] }}"
                        data-legacy-block-hash="{{ $conflict['hash'] ?? '' }}"
                        data-legacy-block-snippet="{{ $conflict['snippet'] }}"
                        data-legacy-block-store-url="{{ route('servers.bind.legacy-block.remove', $server) }}"
                        data-legacy-block-status-url-template="{{ route('servers.bind.legacy-block.status', [$server, 'OPERATION_ID']) }}"
                        data-legacy-block-csrf="{{ csrf_token() }}"
                    >
                        Remover declaração antiga
                    </button>
                </li>
            @endforeach
        </ul>
        <p class="agent-empty-message">A remoção não aplica a zona automaticamente — depois de remover, use "Aplicar agora" na tela da zona como um passo separado.</p>
    </section>
@endif

@if ($server->agent_status !== 'online')
    <section class="agent-ops-alert {{ $server->agent_status === 'blocked' ? 'is-danger' : 'is-warning' }}" role="alert">
        <div><strong>{{ $server->agent_status === 'blocked' ? 'Agente bloqueado' : 'Agente offline' }}</strong><p>Último contato: {{ $lastContact ? $lastContact->diffForHumans() : 'nenhum contato recebido' }}. O inventário abaixo representa a última informação conhecida.</p></div>
        <a href="#agent-technical-details">Ver diagnóstico</a>
    </section>
@elseif ($readinessState !== 'ok')
    <section class="agent-ops-alert is-warning" role="status">
        <div><strong>Atenção operacional</strong><p>{{ $readinessReceived ? $readinessIssueCount.' verificações requerem análise.' : 'O inventário de prontidão ainda não foi recebido.' }}</p></div>
        <a href="#agent-technical-details">Ver detalhes</a>
    </section>
@endif

<section class="agent-metrics" aria-label="Resumo operacional">
    <article class="agent-metric-card"><span>Agente</span><strong>{{ $agentStatusLabel }}</strong><small>{{ $lastContact ? 'Último contato '.$lastContact->diffForHumans() : 'Sem contato registrado' }}</small></article>
    <article class="agent-metric-card"><span>BIND</span><strong>{{ $bindActive ? 'Ativo' : ($bindInstalled ? 'Inativo' : 'Não detectado') }}</strong><small>TCP 53 {{ $tcpReady ? 'OK' : 'indisponível' }} · UDP 53 {{ $udpReady ? 'OK' : 'indisponível' }}</small></article>
    <article class="agent-metric-card"><span>Readiness</span><strong>{{ strtoupper($readinessState) }}</strong><small>{{ $readinessState === 'ok' ? 'Verificações operacionais OK' : $readinessIssueCount.' itens requerem atenção' }}</small></article>
    <article class="agent-metric-card"><span>Publicações</span><strong>{{ $pendingPublicationCount }} pendente{{ $pendingPublicationCount === 1 ? '' : 's' }}</strong><small>{{ $latestPublication ? 'Último estado: '.$publicationStatus : 'Nenhuma publicação destinada' }}</small></article>
    <article class="agent-metric-card"><span>Zonas</span><strong>{{ $discoverySucceeded ? $discoveredZoneCount.' descobertas' : 'Não verificadas' }}</strong><small>{{ $discoveryAt ? 'Descoberta '.$discoveryAt->diffForHumans() : 'Descoberta ainda não executada' }}</small></article>
    <article class="agent-metric-card"><span>Carga CPU</span><strong>{{ is_numeric($cpuLoadPercent) ? number_format($cpuLoadPercent, 1, ',', '.').'%' : 'Não informada' }}</strong><small>{{ data_get($hostInventory, 'cpu_count') ? data_get($hostInventory, 'cpu_count').' CPUs lógicas · média de 1 min' : 'Aguardando inventário 0.12.0+' }}</small></article>
    <article class="agent-metric-card"><span>Memória</span><strong>{{ is_numeric($memoryUsedPercent) ? number_format($memoryUsedPercent, 1, ',', '.').'%' : 'Não informada' }}</strong><small>{{ data_get($hostInventory, 'memory_available_mb') !== null ? number_format(data_get($hostInventory, 'memory_available_mb'), 0, ',', '.').' MB disponíveis' : 'Aguardando inventário 0.12.0+' }}</small></article>
    <article class="agent-metric-card"><span>Disco raiz</span><strong>{{ is_numeric($diskUsedPercent) ? number_format($diskUsedPercent, 1, ',', '.').'%' : 'Não informado' }}</strong><small>{{ data_get($hostInventory, 'disk_free_gb') !== null ? number_format(data_get($hostInventory, 'disk_free_gb'), 1, ',', '.').' GB livres' : 'Aguardando inventário 0.12.0+' }}</small></article>
    <article class="agent-metric-card"><span>Uptime</span><strong>{{ $uptimeLabel }}</strong><small>Tempo desde a última inicialização</small></article>
</section>

<section class="agent-ops-grid">
    <article class="panel-card agent-management-card">
        <header class="agent-section-header"><div><p class="eyebrow">BIND existente</p><h2>BIND existente detectado</h2></div><span class="status-badge status-neutral">Externo / CLI</span></header>
        <div class="agent-observe-callout"><strong>Gerenciamento atual: Externo / CLI</strong><p>O DNS Center está coletando inventário e realizando descoberta somente leitura.</p><p>Nenhuma configuração ativa do BIND é modificada.</p></div>
        <ol class="agent-lifecycle" aria-label="Etapas de gerenciamento do BIND">
            <li class="{{ $discoverySucceeded ? 'is-complete' : '' }}"><span aria-hidden="true">{{ $discoverySucceeded ? '✓' : '—' }}</span><strong>Descoberto</strong><small>{{ $discoverySucceeded ? 'Concluído' : 'Não iniciado' }}</small></li>
            <li class="{{ $imported ? 'is-complete' : '' }}"><span aria-hidden="true">{{ $imported ? '✓' : '—' }}</span><strong>Importado</strong><small>{{ $imported ? $discoveryStats['imported'].' zonas' : 'Não iniciado' }}</small></li>
            <li><span aria-hidden="true">—</span><strong>Gerenciado</strong><small>Não iniciado</small></li>
        </ol>
    </article>
    <article class="panel-card agent-bind-overview">
        <header class="agent-section-header"><div><p class="eyebrow">Estado do BIND</p><h2>{{ $bindActive ? 'Serviço ativo' : 'Requer atenção' }}</h2></div><span class="status-badge {{ $bindActive && $tcpReady && $udpReady ? 'status-success' : 'status-warning' }}">{{ $bindActive && $tcpReady && $udpReady ? 'Operacional' : 'Atenção' }}</span></header>
        <dl class="agent-ops-quick-list">
            <div><dt>Agente</dt><dd data-agent-upgrade-card-version>{{ $agentVersion }}</dd></div><div><dt>BIND</dt><dd>{{ $bindVersion }}</dd></div>
            <div><dt>Listeners</dt><dd>TCP 53 {{ $tcpReady ? '✓' : '—' }} · UDP 53 {{ $udpReady ? '✓' : '—' }}</dd></div><div><dt>Readiness</dt><dd>{{ strtoupper($readinessState) }}</dd></div>
        </dl>
    </article>
</section>

<section class="panel-card agent-discovery-card">
    <header class="agent-section-header"><div><p class="eyebrow">Descoberta somente leitura</p><h2>Inventário de zonas</h2></div><span class="status-badge {{ $discoveryInFlight ? 'status-warning' : ($discoverySucceeded ? 'status-success' : 'status-neutral') }}" data-discovery-card-status>{{ $discoveryInFlight ? 'Descoberta em andamento' : ($discoverySucceeded ? 'Detectado' : 'Não iniciado') }}</span></header>
    <dl class="agent-discovery-metrics">
        <div><dt>Última descoberta</dt><dd data-discovery-card-time @class(['discovery-time-stale' => $discoveryStale])>{{ $discoveryAt ? $discoveryAt->diffForHumans() : 'Não executada' }}</dd>@if ($discoveryStale)<dd class="discovery-stale-hint" data-discovery-stale-hint>Desatualizada (mais de 24h) — rode uma nova descoberta para conferir mudanças no BIND.</dd>@endif</div><div><dt>Resultado</dt><dd data-discovery-card-total>{{ $discoverySucceeded ? $discoveryStats['total'].' zonas' : 'Não disponível' }}</dd></div>
        <div><dt>Primary</dt><dd data-discovery-card-primary>{{ $discoverySucceeded ? $discoveryStats['primary'] : '—' }}</dd></div><div><dt>Secondary</dt><dd data-discovery-card-secondary>{{ $discoverySucceeded ? $discoveryStats['secondary'] : '—' }}</dd></div><div><dt>Alterações externas</dt><dd>Não verificado</dd></div>
    </dl>
    @if ($latestDiscoveryOperation?->status === 'failed')<p class="agent-inline-error" role="alert">A última descoberta não foi concluída. Tente novamente ou consulte os registros administrativos.</p>@endif
    <div class="agent-primary-actions">
        @if ($discoverySucceeded)<a href="{{ route('servers.bind.discovery.show', $server) }}" class="button button-primary">Ver zonas encontradas</a>@endif
        <button type="button" class="button button-secondary" data-discovery-start data-discovery-store-url="{{ route('servers.bind.discover', $server) }}" data-discovery-status-url="{{ route('servers.bind.discovery.status', $server) }}" data-discovery-show-url="{{ route('servers.bind.discovery.show', $server) }}" data-discovery-csrf="{{ csrf_token() }}" data-discovery-initial-status="{{ $latestDiscoveryOperation?->status }}" data-discovery-requested-at="{{ $latestDiscoveryOperation?->authorized_at?->toIso8601String() }}" data-discovery-agent-online="{{ $server->agent_status === 'online' ? 'true' : 'false' }}" data-discovery-active="{{ $discoveryInFlight ? 'true' : 'false' }}">{{ $discoveryInFlight ? 'Acompanhar descoberta' : 'Executar nova descoberta' }}</button>
    </div>
</section>

<section class="panel-card agent-publication-card">
    <header class="agent-section-header"><div><p class="eyebrow">Observabilidade</p><h2>Publicação</h2></div>@if ($latestPublication)<span class="status-badge {{ $latestPublication->status === 'applied' ? 'status-success' : 'status-warning' }}">{{ $publicationStatus }}</span>@endif</header>
    @if (! $latestPublication)
        <p class="agent-empty-message">Nenhuma publicação destinada a este servidor.</p>
    @else
        <dl class="agent-publication-details">
            <div><dt>Versão desejada</dt><dd>{{ $latestPublication->zoneVersion?->version }}</dd></div><div><dt>Versão instalada</dt><dd>{{ $latestPublication->installed_version ?? 'Aguardando confirmação' }}</dd></div><div><dt>Status</dt><dd>{{ $publicationStatus }}</dd></div>
            <div><dt>Serial esperado</dt><dd>{{ $latestPublication->zoneVersion?->serial ?? 'Não informado' }}</dd></div><div><dt>Serial observado</dt><dd>{{ $latestPublication->reported_serial ?? 'Aguardando confirmação' }}</dd></div><div><dt>Última confirmação</dt><dd>{{ $latestPublication->last_apply_at?->format('d/m/Y H:i') ?? 'Aguardando confirmação' }}</dd></div>
        </dl>
        @if ($latestPublication->last_apply_error)<p class="agent-inline-error" role="alert">{{ $latestPublication->last_apply_error }}</p>@endif
    @endif
</section>

<details class="panel-card agent-collapsible" id="agent-technical-details">
    <summary><span><span class="eyebrow">Inventário</span><strong>Detalhes técnicos</strong></span><small>Identidade, caminhos, timestamps e última informação conhecida</small></summary>
    <div class="agent-technical-grid">
        <section><h3>Agente</h3><dl class="agent-technical-list">
            <div><dt>UUID</dt><dd class="agent-technical-value">{{ $agent->agent_uuid }}</dd></div><div><dt>Hostname reportado</dt><dd>{{ $agent->reported_hostname }}</dd></div><div><dt>IP cadastrado</dt><dd class="agent-technical-value">{{ $server->ipv4_address ?: ($server->ipv6_address ?: 'Não informado') }}</dd></div>
            <div><dt>Registrado em</dt><dd>{{ $agent->registered_at?->format('d/m/Y H:i:s') ?: 'Não informado' }}</dd></div><div><dt>Último contato exato</dt><dd>{{ $lastContact?->format('d/m/Y H:i:s') ?: 'Não recebido' }}</dd></div><div><dt>Versão</dt><dd>{{ $agentVersion }}</dd></div><div><dt>Sistema / kernel</dt><dd>{{ trim(($server->operating_system ?: 'Não informado').' '.$server->operating_system_version) }}</dd></div>
        </dl></section>
        <section><h3>BIND</h3><dl class="agent-technical-list">
            <div><dt>Versão detectada</dt><dd>{{ $bindVersion }}</dd></div><div><dt>Configuração principal</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.named_conf') ?: 'Não detectada' }}</dd></div><div><dt>Include detectado</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.include_dir') ?: 'Não detectado' }}</dd></div><div><dt>Diretório gerenciado</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.zones_dir') ?: 'Não detectado' }}</dd></div>
            <div><dt>Serviço</dt><dd>{{ $bindActive ? 'Ativo' : 'Inativo ou não detectado' }}</dd></div><div><dt>Listener TCP 53</dt><dd>{{ $tcpReady ? 'Detectado' : 'Não detectado' }}</dd></div><div><dt>Listener UDP 53</dt><dd>{{ $udpReady ? 'Detectado' : 'Não detectado' }}</dd></div><div><dt>named-checkconf</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.named_checkconf') ?: 'Não detectado' }}</dd></div><div><dt>named-checkzone</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.named_checkzone') ?: 'Não detectado' }}</dd></div><div><dt>rndc</dt><dd class="agent-technical-value">{{ data_get($readiness, 'paths.rndc') ?: 'Não detectado' }}</dd></div><div><dt>Inventário recebido em</dt><dd>{{ $server->bind_readiness_at?->format('d/m/Y H:i:s') ?: 'Não recebido' }}</dd></div>
        </dl></section>
        <section><h3>Observabilidade</h3><dl class="agent-technical-list">
            <div><dt>Última descoberta exata</dt><dd>{{ $discoveryAt?->format('d/m/Y H:i:s') ?: 'Não executada' }}</dd></div><div><dt>Estado da descoberta</dt><dd>{{ $latestDiscoveryOperation?->status ?: 'Não iniciada' }}</dd></div><div><dt>Última coleta autoritativa</dt><dd>{{ $server->authoritative_observed_at?->format('d/m/Y H:i:s') ?: 'Não recebida' }}</dd></div><div><dt>Sequência observada</dt><dd>{{ $server->authoritative_sequence ?? 'Não recebida' }}</dd></div><div><dt>Serial observado</dt><dd>{{ $latestAppliedPublication?->reported_serial ?? 'Não recebido' }}</dd></div>
            <div><dt>CPU / carga 1 min</dt><dd>{{ data_get($hostInventory, 'cpu_count', '—') }} / {{ data_get($hostInventory, 'load_1m', '—') }}</dd></div><div><dt>Memória total</dt><dd>{{ data_get($hostInventory, 'memory_total_mb') !== null ? number_format(data_get($hostInventory, 'memory_total_mb'), 0, ',', '.').' MB' : 'Não recebida' }}</dd></div><div><dt>Disco raiz total</dt><dd>{{ data_get($hostInventory, 'disk_total_gb') !== null ? number_format(data_get($hostInventory, 'disk_total_gb'), 1, ',', '.').' GB' : 'Não recebido' }}</dd></div><div><dt>Uptime</dt><dd>{{ $uptimeLabel }}</dd></div>
        </dl></section>
    </div>
</details>

<details class="panel-card agent-collapsible">
    <summary><span><span class="eyebrow">Configuração avançada</span><strong>Adoção e gerenciamento do BIND</strong></span><small>O servidor permanece sob gerenciamento externo até uma ação explícita</small></summary>
    <div class="agent-collapsible-body"><p>Preparar um plano apenas registra a proposta local. Não executa alterações por si só. O fluxo de adoção completa ainda não possui evidência factual nesta tela.</p>
        @if (! $latestBindOperation)<form method="POST" action="{{ route('servers.bind.plan', $server) }}">@csrf<button type="submit" class="button button-tertiary">Preparar plano de configuração BIND</button></form>
        @else<p class="agent-operation-state">Operação {{ $latestBindOperation->action }} · estado {{ $latestBindOperation->status }}</p>@if ($latestBindOperation->error)<p class="agent-inline-error">{{ $latestBindOperation->error }}</p>@endif
            @if ($latestBindOperation->status === 'planned')<form class="agent-strong-confirmation" method="POST" action="{{ route('servers.bind.authorize', [$server, $latestBindOperation]) }}">@csrf<label>Confirmação forte<input name="confirmation" required autocomplete="off" placeholder="AUTORIZAR BIND {{ Str::upper($server->name) }}"></label><button type="submit" class="button button-secondary">Autorizar operação</button></form>@endif
        @endif
    </div>
</details>

<details class="panel-card agent-collapsible">
    <summary><span><span class="eyebrow">Acesso secundário</span><strong>Software e segurança</strong></span><small>Atualização do agente e detalhes do modelo de segurança</small></summary>
    <div class="agent-collapsible-body"><section class="agent-software-row">
        <div>
            <h3>Software do agente</h3>
            <dl class="agent-ops-quick-list">
                <div><dt>Instalada</dt><dd data-agent-upgrade-installed-version>{{ $installedAgentVersion ?? 'Não informada' }}</dd></div>
                <div><dt data-agent-upgrade-available-label>{{ $upgradeInFlight ? 'Alvo' : 'Disponível' }}</dt><dd data-agent-upgrade-available-version>{{ $availableAgentVersion ?? 'Não informada' }}</dd></div>
            </dl>
            <span class="status-badge {{ $upgradeStatusBadgeClass }}" data-agent-upgrade-card-status>{{ $upgradeStatusLabel }}</span>
        </div>
        <button type="button" class="button {{ $upgradeButtonClass }}" data-agent-upgrade-start data-agent-upgrade-store-url="{{ route('servers.agent.upgrade', $server) }}" data-agent-upgrade-status-url="{{ route('servers.agent.upgrade.status', $server) }}" data-agent-upgrade-csrf="{{ csrf_token() }}" data-agent-upgrade-requested-at="{{ $latestAgentUpgradeOperation?->authorized_at?->toIso8601String() }}" data-agent-upgrade-agent-online="{{ $server->agent_status === 'online' ? 'true' : 'false' }}" data-agent-upgrade-active="{{ $upgradeInFlight ? 'true' : 'false' }}" data-agent-upgrade-initial-status="{{ $latestAgentUpgradeOperation?->status }}" data-agent-upgrade-available="{{ $availableAgentVersion }}" data-agent-upgrade-dashboard-url="{{ route('dashboard') }}">{{ $upgradeButtonLabel }}</button>
    </section>
        <details class="agent-nested-details"><summary>Detalhes de segurança</summary><div class="agent-security-list"><span>O vínculo é associado previamente ao servidor e à organização.</span><span>Hostname e IP são fatores de revisão, não de associação.</span><span>O código é de uso único, expira e somente seu hash é persistido.</span><span>A credencial permanente aparece somente para o agente.</span><span>O agente não recebe acesso ao painel administrativo.</span></div></details>
    </div>
</details>

<details class="panel-card agent-collapsible agent-risk-zone">
    <summary><span><span class="eyebrow">Ações administrativas</span><strong>Zona de risco</strong></span><small>Ações que podem interromper a comunicação do agente</small></summary>
    <div class="agent-collapsible-body agent-risk-action"><div><h3>Revogar credencial</h3><p>O agente perderá acesso à API e precisará de um novo vínculo para voltar a operar.</p></div><form method="POST" action="{{ route('servers.agent.revoke', $server) }}" onsubmit="return confirm('Revogar a credencial deste agente?')">@csrf<button type="submit" class="button button-danger-soft">Revogar credencial</button></form></div>
</details>
