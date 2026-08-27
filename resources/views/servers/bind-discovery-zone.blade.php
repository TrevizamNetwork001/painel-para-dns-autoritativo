@extends('layouts.app')

@section('title', $zone->name.' — BIND existente')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell servers-v1">
    <x-app-sidebar active="servers" />

    <main class="main-content">
        <header class="topbar servers-heading">
            <div>
                <p class="eyebrow">BIND existente</p>
                <h1>{{ $zone->name }}</h1>
            </div>

            <div class="topbar-actions">
                <x-account-menu />
            </div>
        </header>

        <section class="panel-card">
            <div class="panel-card-header">
                <div>
                    <p class="eyebrow">Zona</p>
                    <h2>{{ $zone->name }}</h2>
                </div>

                <a href="{{ route('servers.bind.discovery.show', $server) }}" class="button button-secondary">
                    Voltar às zonas
                </a>
            </div>

            <dl class="agent-server-details">
                <div>
                    <dt>Tipo detectado</dt>
                    <dd>{{ $zone->detected_type ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Sintaxe no BIND</dt>
                    <dd>{{ $zone->detected_syntax ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Arquivo</dt>
                    <dd>{{ $zone->file_path ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Serial</dt>
                    <dd>{{ $zone->serial ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Nodes (BIND, via rndc)</dt>
                    <dd>{{ $zone->node_count ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Registros parseados</dt>
                    <dd>{{ $total }}</dd>
                </div>
                <div>
                    <dt>SHA-256</dt>
                    <dd>{{ $zone->file_sha256 ?? '—' }}</dd>
                </div>
            </dl>

            <p>
                <small>
                    "Nodes" é a contagem de owners distintos reportada pelo BIND
                    (<code>rndc zonestatus</code>) — um mesmo owner pode ter vários
                    registros (ex.: dois NS no apex). "Registros parseados" é a
                    contagem real de resource records lidos pela descoberta.
                </small>
            </p>

            @if (! empty($zone->unsupported_record_types))
                <div class="alert alert-warning">
                    Esta zona possui registros que o DNS Center ainda não consegue editar:
                    {{ implode(', ', $zone->unsupported_record_types) }}.
                    Importação bloqueada até tratamento manual.
                </div>
            @endif
        </section>

        @if ($zone->soa)
            <section class="panel-card">
                <div class="panel-card-header">
                    <div>
                        <p class="eyebrow">SOA</p>
                        <h2>Start of Authority</h2>
                    </div>
                </div>

                <p><small>O SOA não entra na tabela de registros abaixo — fica aqui, em seção própria, e será preservado integralmente numa futura importação.</small></p>

                <dl class="agent-server-details">
                    <div>
                        <dt>MNAME</dt>
                        <dd>{{ $zone->soa['mname'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>RNAME</dt>
                        <dd>{{ $zone->soa['rname'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Serial</dt>
                        <dd>{{ $zone->soa['serial'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Refresh</dt>
                        <dd>{{ $zone->soa['refresh'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Retry</dt>
                        <dd>{{ $zone->soa['retry'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Expire</dt>
                        <dd>{{ $zone->soa['expire'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>Minimum</dt>
                        <dd>{{ $zone->soa['minimum'] ?? '—' }}</dd>
                    </div>
                </dl>
            </section>
        @endif

        <section class="panel-card">
            <div class="panel-card-header">
                <div>
                    <p class="eyebrow">Registros</p>
                    <h2>Página {{ $page }} · {{ $total }} no total</h2>
                </div>
            </div>

            @if ($records->isEmpty())
                <p>Nenhum registro capturado para esta zona (secondary, não suportada ou fora do limite de tamanho).</p>
            @else
                <div class="discovery-table-wrap">
                    <table class="discovery-table">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th>TTL</th>
                                <th>Tipo</th>
                                <th>Conteúdo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($records as $record)
                                <tr>
                                    <td>{{ $record['name'] ?? '—' }}</td>
                                    <td>{{ $record['ttl'] ?? '—' }}</td>
                                    <td>{{ $record['type'] ?? '—' }}</td>
                                    <td style="word-break: break-all;">{{ $record['content'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="agent-actions">
                    @if ($page > 1)
                        <a class="button button-secondary" href="{{ route('servers.bind.discovery.zone', [$server, $zone]) }}?page={{ $page - 1 }}">Anterior</a>
                    @endif
                    @if ($page * $perPage < $total)
                        <a class="button button-secondary" href="{{ route('servers.bind.discovery.zone', [$server, $zone]) }}?page={{ $page + 1 }}">Próxima</a>
                    @endif
                </div>
            @endif
        </section>
    </main>
</div>
@endsection
