@extends('layouts.app')

@section('title', 'Zonas reversas')
@section('body-class', 'app-page')

@php
    $statusLabels = [
        'draft' => 'Rascunho',
        'ready' => 'Pronto',
        'published' => 'Publicado',
        'disabled' => 'Desativado',
    ];

    $totalReverse = $ipv4Zones->count() + $ipv6Zones->count();
@endphp

@section('content')
<div class="app-shell dashboard-v2">
    <x-app-sidebar active="zones-reverse" />

    <main class="main-content domains-page">
        <header class="topbar domains-topbar">
            <div>
                <p class="eyebrow">DNS autoritativo</p>
                <h1>Zonas reversas</h1>

                <p class="page-description">
                    Zonas PTR IPv4 (<code>in-addr.arpa</code>) e IPv6 (<code>ip6.arpa</code>), separadas dos domínios.
                </p>
            </div>

            <div class="topbar-actions">
                <x-account-menu />
            </div>
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
                    aria-label="Fechar mensagem"
                    title="Fechar"
                    data-flash-toast-close
                >
                    ×
                </button>

                <span
                    class="flash-toast-progress"
                    aria-hidden="true"
                ></span>
            </div>
        @endif

        <section class="domains-toolbar">
            <div class="domains-search">
                <span aria-hidden="true">⌕</span>

                <input
                    type="search"
                    placeholder="Pesquisar zonas reversas"
                    aria-label="Pesquisar zonas reversas"
                    data-domains-search
                >
            </div>

            <div class="domains-toolbar-meta">
                <strong>{{ $totalReverse }}</strong>
                zona(s) reversa(s)
            </div>
        </section>

        @foreach ([['label' => 'IPv4', 'suffix' => 'in-addr.arpa', 'zones' => $ipv4Zones], ['label' => 'IPv6', 'suffix' => 'ip6.arpa', 'zones' => $ipv6Zones]] as $group)
            <section class="domains-panel">
                <div class="domains-table-heading">
                    <div>
                        <h2>Zona reversa {{ $group['label'] }}</h2>

                        <p>
                            Zonas baseadas em <code>{{ $group['suffix'] }}</code>.
                        </p>
                    </div>

                    <span class="status-badge status-neutral">{{ $group['zones']->count() }}</span>
                </div>

                @if ($group['zones']->isEmpty())
                    <div class="domains-empty-state">
                        <div class="domains-empty-icon" aria-hidden="true">◎</div>

                        <h2>Nenhuma zona reversa {{ $group['label'] }} encontrada</h2>

                        <p>
                            Zonas reversas são criadas pelo mesmo formulário de domínios,
                            usando um nome terminado em <code>{{ $group['suffix'] }}</code>.
                        </p>
                    </div>
                @else
                    <div class="domains-table" data-domain-group>
                        <div class="domains-table-header">
                            <span>Bloco</span>
                            <span>Primary</span>
                            <span>Secondary</span>
                            <span>Registros</span>
                            <span>Estado</span>
                            <span></span>
                        </div>

                        @foreach ($group['zones'] as $entry)
                            @php
                                $zone = $entry['zone'];
                                $primary = $zone->servers->first(
                                    fn ($server) => $server->pivot?->role === 'primary'
                                );
                                $secondary = $zone->servers->first(
                                    fn ($server) => $server->pivot?->role === 'secondary'
                                );
                            @endphp

                            <a
                                href="{{ route('zones.show', $zone) }}"
                                class="domains-table-row"
                                data-domain-row
                                data-domain-name="{{ mb_strtolower(($entry['block'] ?? '').' '.$zone->name) }}"
                            >
                                <div class="domains-name-cell">
                                    <span class="domains-domain-icon" aria-hidden="true">◎</span>

                                    <div>
                                        <strong title="{{ $zone->name }}">
                                            {{ $entry['block'] ?? $zone->name }}
                                        </strong>

                                        <small>
                                            Serial {{ $zone->serial }}
                                            · versão {{ $zone->version }}
                                        </small>
                                    </div>
                                </div>

                                <div class="domains-server-cell">
                                    <strong>{{ $primary?->name ?? 'Não definido' }}</strong>
                                    <small>{{ $primary?->hostname ?? 'Selecione o primary' }}</small>
                                </div>

                                <div class="domains-server-cell">
                                    <strong>{{ $secondary?->name ?? 'Sem secondary' }}</strong>
                                    <small>{{ $secondary?->hostname ?? 'Opcional' }}</small>
                                </div>

                                <div class="domains-record-count">
                                    {{ $zone->records_count }}
                                </div>

                                <div>
                                    <span class="domains-status domains-status-{{ $zone->status }}">
                                        {{ $statusLabels[$zone->status] ?? $zone->status }}
                                    </span>
                                </div>

                                <div class="domains-open-cell">
                                    Abrir
                                    <span aria-hidden="true">›</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach

        <div class="domains-no-results" data-domains-no-results hidden>
            Nenhuma zona reversa corresponde à pesquisa.
        </div>
    </main>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
document.addEventListener('DOMContentLoaded', () => {
    const search = document.querySelector('[data-domains-search]');
    const rows = document.querySelectorAll('[data-domain-row]');
    const groups = document.querySelectorAll('[data-domain-group]');
    const noResults = document.querySelector('[data-domains-no-results]');

    search?.addEventListener('input', () => {
        const query = search.value.trim().toLocaleLowerCase('pt-BR');
        let visible = 0;

        rows.forEach((row) => {
            const matches = row.dataset.domainName.includes(query);
            row.hidden = !matches;

            if (matches) {
                visible++;
            }
        });

        groups.forEach((group) => {
            const hasVisibleRow = Array.from(
                group.querySelectorAll('[data-domain-row]')
            ).some((row) => !row.hidden);

            group.closest('.domains-panel').hidden = !hasVisibleRow;
        });

        if (noResults) {
            noResults.hidden = visible !== 0 || query === '';
        }
    });
});
</script>
@endsection
