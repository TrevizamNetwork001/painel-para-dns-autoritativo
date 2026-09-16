<?php

namespace App\Http\Controllers;

use App\Models\DnsAuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DnsAuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $this->authorizeAccess($request);

        $query = $this->filteredQuery($request, $organizationId);

        $logs = $query->latest('id')->paginate(50)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'actions' => DnsAuditLog::query()
                ->forOrganization($organizationId)
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
            'users' => User::query()
                ->whereIn(
                    'id',
                    DnsAuditLog::query()
                        ->forOrganization($organizationId)
                        ->whereNotNull('user_id')
                        ->distinct()
                        ->pluck('user_id'),
                )
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'filters' => $request->only(['domain', 'action', 'user_id', 'status']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $organizationId = $this->authorizeAccess($request);

        $logs = $this->filteredQuery($request, $organizationId)
            ->latest('id')
            ->get();

        $filename = 'auditoria-dns-'.now()->format('Y-m-d-His').'.csv';

        return Response::streamDownload(function () use ($logs): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Data', 'Usuário', 'Ação', 'Domínio', 'Registro',
                'Valor antigo', 'Valor novo', 'Status',
            ], ';');

            foreach ($logs as $log) {
                fputcsv($handle, [
                    $log->created_at?->format('d/m/Y H:i:s'),
                    $log->actor,
                    DnsAuditLog::actionLabel($log->action),
                    $log->domain ?? '-',
                    $log->record_name ?? '-',
                    $log->old_value ?? '-',
                    $log->new_value ?? '-',
                    $log->status,
                ], ';');
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filteredQuery(Request $request, int $organizationId)
    {
        $query = DnsAuditLog::query()->forOrganization($organizationId);

        if ($domain = trim((string) $request->query('domain', ''))) {
            $query->where('domain', 'like', '%'.$domain.'%');
        }

        if ($action = trim((string) $request->query('action', ''))) {
            $query->where('action', $action);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', (int) $userId);
        }

        if (in_array($request->query('status'), ['ok', 'error'], true)) {
            $query->where('status', $request->query('status'));
        }

        return $query;
    }

    private function authorizeAccess(Request $request): int
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        abort_unless($organizationId > 0, 403);

        $allowed = $user->is_platform_admin
            || $user->roleForOrganization($organizationId) === 'organization_admin';

        abort_unless(
            $allowed,
            403,
            'Você não possui permissão para ver a auditoria.',
        );

        return $organizationId;
    }
}
