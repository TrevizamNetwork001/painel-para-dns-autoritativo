<?php

namespace App\Http\Controllers;

use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollmentCode;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Models\Organization;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DnsServerTransferController extends Controller
{
    public function show(Request $request, DnsServer $server): View
    {
        $this->authorizeTransfer($request, $server);

        return view('servers.transfer', [
            'server' => $server,
            'organizations' => Organization::query()
                ->whereKeyNot($server->organization_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, DnsServer $server): RedirectResponse
    {
        $this->authorizeTransfer($request, $server);

        $validated = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('status', 'active'),
                Rule::notIn([(int) $server->organization_id]),
            ],
            'confirmation' => ['required', 'string', 'max:500'],
        ]);

        $expected = 'TRANSFERIR '.$server->hostname;
        if (trim((string) $validated['confirmation']) !== $expected) {
            return back()->withInput()->withErrors([
                'confirmation' => 'Digite exatamente: '.$expected,
            ]);
        }

        $destination = Organization::query()->findOrFail(
            (int) $validated['organization_id'],
        );

        if ($this->hostnameExistsAtDestination($server, $destination)) {
            return back()->withInput()->withErrors([
                'organization_id' => 'A empresa de destino já possui um servidor com este hostname.',
            ]);
        }

        $sourceOrganizationId = (int) $server->organization_id;
        $now = now();

        $newServer = DB::transaction(function () use (
            $server,
            $destination,
            $now,
        ): DnsServer {
            $locked = DnsServer::query()->lockForUpdate()->findOrFail($server->id);

            abort_if(
                $this->hostnameExistsAtDestination($locked, $destination),
                409,
                'A empresa de destino já possui um servidor com este hostname.',
            );

            DnsAgent::query()
                ->where('dns_server_id', $locked->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            DnsAgentEnrollmentCode::query()
                ->where('dns_server_id', $locked->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            DnsAgentInstallRequest::query()
                ->where('dns_server_id', $locked->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'rejected_at' => $now,
                    'updated_at' => $now,
                ]);

            $locked->forceFill([
                'enabled' => false,
                'status' => 'transferred',
                'agent_status' => 'blocked',
                'last_seen_at' => null,
            ])->save();

            return DnsServer::query()->create([
                'organization_id' => $destination->id,
                'name' => $locked->name,
                'hostname' => $locked->hostname,
                'ipv4_address' => $locked->ipv4_address,
                'ipv6_address' => $locked->ipv6_address,
                'role' => $locked->role,
                'environment' => $locked->environment,
                'status' => 'pending',
                'enabled' => true,
                'agent_status' => 'not_installed',
                'notes' => $locked->notes,
            ]);
        });

        SecurityAuditLogger::record(
            event: 'dns.server_transferred',
            user: $request->user(),
            result: 'success',
            actor: 'user',
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $sourceOrganizationId,
            reason: sprintf(
                'server:%d;destination_organization:%d;new_server:%d',
                $server->id,
                $destination->id,
                $newServer->id,
            ),
        );

        return redirect()->route('servers.index')->with(
            'status',
            "Servidor transferido para {$destination->name}. O cadastro anterior foi desativado e um novo vínculo do agente é necessário.",
        );
    }

    private function hostnameExistsAtDestination(
        DnsServer $server,
        Organization $destination,
    ): bool {
        return DnsServer::query()
            ->where('organization_id', $destination->id)
            ->where('hostname', $server->hostname)
            ->exists();
    }

    private function authorizeTransfer(Request $request, DnsServer $server): void
    {
        $user = $request->user();

        abort_unless($user?->is_platform_admin, 403);
        abort_unless(
            (int) $server->organization_id === (int) $user->current_organization_id,
            404,
        );
    }
}
