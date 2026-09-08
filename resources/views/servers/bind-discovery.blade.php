@extends('layouts.app')

@section('title', 'BIND existente — '.$server->name)
@section('body-class', 'app-page')

@section('content')
<div class="app-shell servers-v1">
    <x-app-sidebar active="servers" />

    <main class="main-content bind-discovery-page">
        <header class="topbar servers-heading bind-discovery-heading">
            <div>
                <p class="eyebrow">{{ $server->name }} · Descoberta BIND</p>
                <h1>Zonas encontradas</h1>
                <p class="page-description">Revise o inventário antes de importar. Nenhuma configuração do servidor é alterada nesta etapa.</p>
            </div>

            <div class="topbar-actions">
                <x-account-menu />
            </div>
        </header>

        @if (session('status'))
            <div class="alert alert-success flash-toast" role="status" data-flash-toast data-flash-timeout="6000">
                <span class="flash-toast-message">{{ session('status') }}</span>
                <button type="button" class="flash-toast-close" data-flash-toast-close aria-label="Fechar">&times;</button>
            </div>
        @endif

        <section class="panel-card bind-discovery-summary">
            <div class="panel-card-header">
                <div>
                    <p class="eyebrow">Última coleta</p>
                    <h2>Resumo da descoberta</h2>
                </div>

                <a href="{{ route('servers.agent.show', $server) }}" class="button button-secondary">
                    Voltar ao agente
                </a>
            </div>

            @if ($lastOperation)
                <dl class="bind-discovery-metrics">
                    <div>
                        <dt>Executada em</dt>
                        <dd>{{ $lastOperation->completed_at?->format('d/m/Y H:i') ?? 'Em andamento' }}</dd>
                    </div>
                    <div>
                        <dt>Zonas encontradas</dt>
                        <dd>{{ $summary['total'] }}</dd>
                    </div>
                    <div>
                        <dt>Primary</dt>
                        <dd>{{ $summary['primary'] }}</dd>
                    </div>
                    <div>
                        <dt>Secondary</dt>
                        <dd>{{ $summary['secondary'] }}</dd>
                    </div>
                    <div>
                        <dt>Prontas para importar</dt>
                        <dd>{{ $summary['new'] }}</dd>
                    </div>
                </dl>
            @else
                <p>Nenhuma descoberta foi executada ainda.</p>
            @endif
        </section>

        <section class="panel-card bind-discovery-list">
            <div class="panel-card-header bind-discovery-toolbar">
                <div>
                    <p class="eyebrow">Inventário</p>
                    <h2>Zonas do BIND</h2>
                </div>
                @if ($zones->isNotEmpty())
                    <label class="bind-discovery-search">
                        <span aria-hidden="true">⌕</span>
                        <input type="search" placeholder="Buscar zona nesta página" data-discovery-search aria-label="Buscar zona nesta página">
                    </label>
                @endif
            </div>

            @if ($zones->isEmpty())
                <div class="bind-discovery-empty">
                    <strong>Nenhuma zona para revisar</strong>
                    <p>Execute uma descoberta pelo agente para preencher este inventário.</p>
                </div>
            @else
                <form method="POST" action="{{ route('servers.bind.discovery.import', $server) }}" data-discovery-form>
                    @csrf

                    <div class="discovery-table-wrap">
                        <table class="discovery-table">
                            <thead>
                                <tr>
                                    <th class="discovery-check"><input type="checkbox" data-discovery-select-all aria-label="Selecionar todas as zonas importáveis"></th>
                                    <th>Zona</th>
                                    <th>Tipo</th>
                                    <th>Sintaxe</th>
                                    <th>Serial</th>
                                    <th>Nodes (BIND)</th>
                                    <th>Registros parseados</th>
                                    <th>Estado</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($zones as $zone)
                                    <tr data-discovery-row data-zone-name="{{ strtolower($zone->name) }}">
                                        <td class="discovery-check">
                                            @if ($zone->comparison_state === 'new')
                                                <input type="checkbox" name="zone_ids[]" value="{{ $zone->id }}" data-discovery-check aria-label="Selecionar {{ $zone->name }}">
                                            @else
                                                <span class="discovery-check-unavailable">—</span>
                                            @endif
                                        </td>
                                        <td><a class="discovery-zone-link" href="{{ route('servers.bind.discovery.zone', [$server, $zone]) }}">{{ $zone->name }}</a></td>
                                        <td><span class="discovery-type">{{ ucfirst($zone->detected_type ?? '—') }}</span></td>
                                        <td>{{ $zone->detected_syntax ?? '—' }}</td>
                                        <td>{{ $zone->serial ?? '—' }}</td>
                                        <td>{{ $zone->node_count ?? '—' }}</td>
                                        <td>{{ is_array($zone->records) ? count($zone->records) : '—' }}</td>
                                        <td>
                                            <span class="status-badge {{ match ($zone->comparison_state) {
                                                'new' => 'status-success',
                                                'exists', 'imported' => 'status-neutral',
                                                'conflict' => 'status-warning',
                                                'secondary_external', 'not_supported' => 'status-danger',
                                                default => 'status-neutral',
                                            } }}">
                                                {{ match ($zone->comparison_state) {
                                                'new' => 'Pronta para importar',
                                                'exists' => 'Já cadastrada',
                                                    'conflict' => 'Conflito',
                                                    'secondary_external' => 'Secondary externo',
                                                    'not_supported' => 'Não suportado',
                                                    'imported' => 'Importado',
                                                    default => $zone->comparison_state,
                                                } }}
                                            </span>
                                            @if (! empty($zone->warnings))
                                                <br><small>{{ implode(' · ', $zone->warnings) }}</small>
                                            @endif
                                        </td>
                                        <td>
                                            <a class="discovery-detail-link" href="{{ route('servers.bind.discovery.zone', [$server, $zone]) }}">
                                                Detalhes →
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <p class="bind-discovery-no-results" data-discovery-no-results hidden>Nenhuma zona corresponde à busca.</p>

                    <div class="bind-discovery-footer">
                        <div>{{ $zones->links() }}</div>
                        <div class="bind-discovery-import-action">
                            <span data-discovery-selected>0 zonas selecionadas</span>
                            <button type="button" class="button button-secondary" data-discovery-select-button>Selecionar todas</button>
                            <button type="submit" class="button button-primary" data-discovery-submit disabled>Importar selecionadas</button>
                        </div>
                    </div>

                    <p class="bind-discovery-note">Apenas zonas <strong>Prontas para importar</strong> podem ser selecionadas.</p>

                    @if ($server->role === 'secondary')
                        <p class="bind-discovery-note">
                            <strong>{{ $server->name }} é um servidor secundário, não o master.</strong>
                            As zonas aqui chegam por réplica (AXFR) do primário e por isso não podem
                            ser importadas por esta tela — importação só é necessária uma vez, feita
                            no servidor primário. Rodar a descoberta aqui serve só para conferir se a
                            réplica está sincronizada com o primário.
                        </p>
                    @endif
                </form>
            @endif
        </section>
    </main>
</div>

@if ($zones->isNotEmpty())
<script nonce="{{ $cspNonce ?? '' }}">
    (() => {
        const form = document.querySelector('[data-discovery-form]');
        if (!form) return;
        const all = form.querySelector('[data-discovery-select-all]');
        const checks = [...form.querySelectorAll('[data-discovery-check]')];
        const selected = form.querySelector('[data-discovery-selected]');
        const selectButton = form.querySelector('[data-discovery-select-button]');
        const submit = form.querySelector('[data-discovery-submit]');
        const rows = [...form.querySelectorAll('[data-discovery-row]')];
        const search = document.querySelector('[data-discovery-search]');
        const empty = form.querySelector('[data-discovery-no-results]');
        const update = () => {
            const count = checks.filter((item) => item.checked).length;
            selected.textContent = `${count} ${count === 1 ? 'zona selecionada' : 'zonas selecionadas'}`;
            submit.disabled = count === 0;
            all.checked = checks.length > 0 && count === checks.length;
            all.indeterminate = count > 0 && count < checks.length;
            selectButton.textContent = all.checked ? 'Desmarcar todas' : 'Selecionar todas';
        };
        all.addEventListener('change', () => { checks.forEach((item) => item.checked = all.checked); update(); });
        selectButton.addEventListener('click', () => {
            const shouldSelect = !checks.every((item) => item.checked);
            checks.forEach((item) => item.checked = shouldSelect);
            update();
        });
        checks.forEach((item) => item.addEventListener('change', update));
        search?.addEventListener('input', () => {
            const term = search.value.trim().toLocaleLowerCase('pt-BR');
            let visible = 0;
            rows.forEach((row) => {
                const show = !term || row.dataset.zoneName.includes(term);
                row.hidden = !show;
                if (show) visible++;
            });
            empty.hidden = visible > 0;
        });
        update();
    })();
</script>
@endif
@endsection
