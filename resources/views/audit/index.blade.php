@extends('layouts.app')

@section('title', 'Auditoria')
@section('body-class', 'app-page')

@section('content')
<div class="app-shell">
    <x-app-sidebar active="audit" />

    <main class="main-content">
        <header class="topbar">
            <div>
                <p class="eyebrow">Operações</p>
                <h1>Auditoria</h1>

                <p class="page-description">
                    Histórico de ações administrativas e de DNS
                    realizadas nesta empresa.
                </p>
            </div>

            <div class="topbar-actions">
                <a
                    class="button button-secondary"
                    href="{{ route('audit.export', $filters) }}"
                >
                    Exportar CSV
                </a>

                <x-account-menu />
            </div>
        </header>

        <section class="domain-section-card">
            <form method="GET" class="domain-form-grid domain-form-grid-three">
                <label class="domain-field">
                    <span>Domínio</span>

                    <input
                        type="text"
                        name="domain"
                        placeholder="Filtrar por domínio"
                        value="{{ $filters['domain'] ?? '' }}"
                    >
                </label>

                <label class="domain-field">
                    <span>Ação</span>

                    <select name="action">
                        <option value="">Todas as ações</option>

                        @foreach ($actions as $action)
                            <option
                                value="{{ $action }}"
                                @selected(($filters['action'] ?? '') === $action)
                            >
                                {{ \App\Models\DnsAuditLog::actionLabel($action) }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="domain-field">
                    <span>Usuário</span>

                    <select name="user_id">
                        <option value="">Todos os usuários</option>

                        @foreach ($users as $user)
                            <option
                                value="{{ $user->id }}"
                                @selected((string) ($filters['user_id'] ?? '') === (string) $user->id)
                            >
                                {{ $user->name }} ({{ $user->email }})
                            </option>
                        @endforeach
                    </select>
                </label>

                <label class="domain-field">
                    <span>Status</span>

                    <select name="status">
                        <option value="">Todos os status</option>
                        <option value="ok" @selected(($filters['status'] ?? '') === 'ok')>OK</option>
                        <option value="error" @selected(($filters['status'] ?? '') === 'error')>Erro</option>
                    </select>
                </label>

                <div class="topbar-actions">
                    <button type="submit" class="button button-primary">Filtrar</button>
                    <a class="button button-secondary" href="{{ route('audit.index') }}">Limpar</a>
                </div>
            </form>
        </section>

        <section class="domain-section-card">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Usuário</th>
                            <th>Ação</th>
                            <th>Domínio</th>
                            <th>Registro</th>
                            <th>Valor antigo</th>
                            <th>Valor novo</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td>{{ $log->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td>{{ $log->actor }}</td>
                                <td>{{ \App\Models\DnsAuditLog::actionLabel($log->action) }}</td>
                                <td>{{ $log->domain ?? '-' }}</td>
                                <td>
                                    {{ $log->record_name ?? '-' }}
                                    @if ($log->record_type)
                                        ({{ $log->record_type }})
                                    @endif
                                </td>
                                <td>{{ $log->old_value ?? '-' }}</td>
                                <td>{{ $log->new_value ?? '-' }}</td>
                                <td>
                                    <span class="status-badge {{ $log->status === 'ok' ? 'status-success' : 'status-danger' }}">
                                        {{ strtoupper($log->status) }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="domain-record-no-results">
                                    Nenhum registro encontrado.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="audit-pagination">
                {{ $logs->links() }}
            </div>
        </section>
    </main>
</div>
@endsection
