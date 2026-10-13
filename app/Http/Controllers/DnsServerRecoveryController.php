<?php

namespace App\Http\Controllers;

use App\Models\DnsAgentPublication;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Support\DnsAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DnsServerRecoveryController extends Controller
{
    public function store(Request $request, DnsServer $server): RedirectResponse
    {
        $organizationId = $this->authorizeServer($request, $server);
        $expectedConfirmation = 'RECUPERAR '.Str::upper($server->name);

        if ($request->string('confirmation')->trim()->toString() !== $expectedConfirmation) {
            throw ValidationException::withMessages([
                'confirmation' => "Digite {$expectedConfirmation} para confirmar.",
            ]);
        }

        $queued = DB::transaction(function () use ($request, $server, $organizationId): int {
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);

            $agent = $server->agent()
                ->whereNull('revoked_at')
                ->latest('id')
                ->first();
            abort_unless($agent && $agent->revoked_at === null, 409, 'Vincule um agente ativo à nova máquina antes de iniciar a recuperação.');

            DnsBindOperation::expireStaleOperations($server->id);
            abort_if(
                DnsBindOperation::query()
                    ->where('dns_server_id', $server->id)
                    ->where('action', 'apply_zones')
                    ->whereIn('status', ['authorized', 'running'])
                    ->exists(),
                409,
                'Já existe uma aplicação de zonas em andamento neste servidor.',
            );

            $zones = DnsZone::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'published')
                ->where('enabled', true)
                ->whereHas('servers', fn ($query) => $query->whereKey($server->id))
                ->with(['versions' => fn ($query) => $query->latest('id')->limit(1)])
                ->lockForUpdate()
                ->get();

            $queued = 0;
            foreach ($zones as $zone) {
                $version = $zone->versions->first();
                if (! $version) {
                    continue;
                }

                DnsAgentPublication::query()->updateOrCreate(
                    [
                        'dns_zone_version_id' => $version->id,
                        'dns_server_id' => $server->id,
                        'dns_agent_id' => $agent->id,
                    ],
                    [
                        'organization_id' => $organizationId,
                        'status' => 'pending',
                        'installed_version' => null,
                        'artifact_checksum' => null,
                        'downloaded_at' => null,
                        'last_apply_at' => null,
                        'agent_reported_at' => null,
                        'last_apply_error' => null,
                        'reported_serial' => null,
                        'serial_confirmed_at' => null,
                    ],
                );
                $queued++;
            }

            abort_if($queued === 0, 409, 'Este servidor não possui zonas publicadas para recuperar.');

            DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'apply_zones',
                'status' => 'authorized',
                'authorization_nonce' => (string) Str::uuid(),
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ]);

            DnsAuditLogger::record(
                organizationId: $organizationId,
                user: $request->user(),
                action: 'server.recovery_queued',
                recordName: $server->name,
                newValue: (string) $queued,
                message: "{$queued} zona(s) colocada(s) na fila de recuperação.",
            );

            return $queued;
        });

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', "Recuperação iniciada: {$queued} zona(s) serão reconstruídas nesta máquina.");
    }

    private function authorizeServer(Request $request, DnsServer $server): int
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        abort_unless($organizationId > 0, 403);
        abort_unless((int) $server->organization_id === $organizationId, 404);
        abort_unless(
            $user->is_platform_admin || $user->roleForOrganization($organizationId) === 'organization_admin',
            403,
        );

        return $organizationId;
    }
}
