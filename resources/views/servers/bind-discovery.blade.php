@extends('layouts.app')

@section('title', 'BIND existente — '.$server->name)
@section('body-class', 'app-page')

@section('content')
<div class="app-shell servers-v1">
    <x-app-sidebar active="servers" />

    <main class="main-content">
        <header class="topbar servers-heading">
            <div>
                <p class="eyebrow">Servidores DNS</p>
                <h1>BIND existente — {{ $server->name }}</h1>
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

        <section class="panel-card">
            <div class="panel-card-header">
                <div>
                    <p class="eyebrow">Resumo</p>
                    <h2>Última descoberta</h2>
                </div>

                <a href="{{ route('servers.agent.show', $server) }}" class="button button-secondary">
                    Voltar ao agente
                </a>
            </div>

            @if ($lastOperation)
                <dl class="agent-server-details">
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
                </dl>
            @else
                <p>Nenhuma descoberta foi executada ainda.</p>
            @endif
        </section>

        <section class="panel-card">
            <div class="panel-card-header">
                <div>
                    <p class="eyebrow">Zonas</p>
                    <h2>Encontradas no BIND</h2>
                </div>
            </div>

            @if ($zones->isEmpty())
                <p>Nenhuma zona para revisar ainda.</p>
            @else
                <form method="POST" action="{{ route('servers.bind.discovery.import', $server) }}">
                    @csrf

                    <div class="servers-list-panel" style="overflow-x: auto;">
                        <table class="agent-server-details" style="width: 100%;">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Zona</th>
                                    <th>Tipo</th>
                                    <th>Sintaxe</th>
                                    <th>Serial</th>
                                    <th>Registros</th>
                                    <th>Estado</th>
                                    <th>Ação</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($zones as $zone)
                                    <tr>
                                        <td>
                                            @if ($zone->comparison_state === 'new')
                                                <input type="checkbox" name="zone_ids[]" value="{{ $zone->id }}">
                                            @endif
                                        </td>
                                        <td>{{ $zone->name }}</td>
                                        <td>{{ $zone->detected_type ?? '—' }}</td>
                                        <td>{{ $zone->detected_syntax ?? '—' }}</td>
                                        <td>{{ $zone->serial ?? '—' }}</td>
                                        <td>{{ $zone->node_count ?? '—' }}</td>
                                        <td>
                                            <span class="status-badge {{ match ($zone->comparison_state) {
                                                'new' => 'status-success',
                                                'exists', 'imported' => 'status-neutral',
                                                'conflict' => 'status-warning',
                                                'secondary_external', 'not_supported' => 'status-danger',
                                                default => 'status-neutral',
                                            } }}">
                                                {{ match ($zone->comparison_state) {
                                                    'new' => 'Novo',
                                                    'exists' => 'Já existe',
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
                                            <a href="{{ route('servers.bind.discovery.zone', [$server, $zone]) }}">
                                                Visualizar
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{ $zones->links() }}

                    <p>
                        Somente zonas <strong>Novo</strong> podem ser selecionadas. Zonas em
                        conflito, secondary externo e não suportadas não são importáveis
                        automaticamente nesta fase.
                    </p>

                    <button type="submit" class="button button-primary">
                        Importar selecionadas
                    </button>
                </form>
            @endif
        </section>
    </main>
</div>
@endsection
