<?php

namespace App\Http\Controllers;

use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Support\DnsAgentInstallRequestMatcher;
use App\Support\SecurityAuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DnsAgentInstallAssignmentController extends Controller
{
    public function __construct(
        private readonly DnsAgentInstallRequestMatcher $matcher,
    ) {}

    public function show(
        Request $request,
        DnsAgentInstallRequest $installRequest,
    ): View {
        $candidates = $this->authorizedCandidates($request, $installRequest);

        return view('servers.agent-assignment', [
            'installRequest' => $installRequest,
            'candidates' => $candidates,
        ]);
    }

    public function store(
        Request $request,
        DnsAgentInstallRequest $installRequest,
    ): RedirectResponse {
        $validated = $request->validate([
            'dns_server_id' => ['required', 'integer'],
            'confirmation' => ['required', 'string', 'max:500'],
        ]);

        $selectedServer = DB::transaction(function () use (
            $request,
            $installRequest,
            $validated,
        ): DnsServer {
            $locked = DnsAgentInstallRequest::query()
                ->whereKey($installRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $locked->status === 'pending'
                && $locked->dns_server_id === null
                && $locked->organization_id === null
                && $locked->expires_at->isFuture(),
                409,
                'A solicitação não está disponível para associação.',
            );

            $candidates = $this->authorizedCandidates($request, $locked);
            $server = $candidates->firstWhere(
                'id',
                (int) $validated['dns_server_id'],
            );
            abort_unless($server, 422, 'Servidor candidato inválido.');

            $expected = mb_strtoupper(
                'ASSOCIAR '.substr($locked->request_id, 0, 8)
                .' AO SERVIDOR '.$server->hostname,
            );

            if (! hash_equals($expected, mb_strtoupper(trim(
                (string) $validated['confirmation'],
            )))) {
                throw ValidationException::withMessages([
                    'confirmation' => 'A confirmação explícita não corresponde.',
                ]);
            }

            $locked->forceFill([
                'dns_server_id' => $server->id,
                'organization_id' => $server->organization_id,
            ])->save();

            SecurityAuditLogger::record(
                event: 'agent.enrollment_assigned',
                user: $request->user(),
                result: 'success',
                actor: 'user:'.$request->user()->id,
                source: 'web',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                organizationId: $server->organization_id,
                reason: 'install_request:'.$locked->id
                    .';server:'.$server->id
                    .';hostname:'.$locked->reported_hostname
                    .';fingerprint:'.substr($locked->fingerprint, 0, 12),
            );

            return $server;
        });

        return redirect()
            ->route('servers.agent.show', $selectedServer)
            ->with('status', 'Solicitação associada. Revise e aprove o agente separadamente.');
    }

    private function authorizedCandidates(
        Request $request,
        DnsAgentInstallRequest $installRequest,
    ): Collection {
        abort_unless(
            $installRequest->status === 'pending'
            && $installRequest->dns_server_id === null
            && $installRequest->organization_id === null
            && $installRequest->expires_at->isFuture(),
            404,
        );

        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;
        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin || $role === 'organization_admin',
            403,
        );

        $candidates = $this->matcher->candidates(
            $installRequest->reported_hostname,
            $installRequest->registered_ip,
        );
        abort_if($candidates->isEmpty(), 404);

        if (! $user->is_platform_admin) {
            abort_unless(
                $candidates->every(
                    fn (DnsServer $server) => (int) $server->organization_id === $organizationId,
                ),
                404,
            );
        }

        return $candidates;
    }
}
