<?php

namespace App\Http\Controllers;

use App\Models\DnsAgentPublication;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DnsBindApplyController extends Controller
{
    public function store(Request $request, DnsServer $server): JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        $operation = DB::transaction(function () use ($request, $server, $organizationId): DnsBindOperation {
            // Lock the server even when no operation exists yet, so concurrent
            // requests cannot both pass the in-flight check.
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);

            $agent = $server->agent;
            abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');

            DnsBindOperation::expireStaleOperations($server->id);

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'apply_zones')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            abort_if($inFlight, 409, 'Já existe uma aplicação em andamento para este servidor.');

            $hasPending = DnsAgentPublication::query()
                ->where('dns_server_id', $server->id)
                ->whereIn('status', ['pending', 'downloaded', 'applying', 'failed'])
                ->exists();

            abort_unless($hasPending, 409, 'Nenhuma publicação pendente para este servidor.');

            return DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'apply_zones',
                'status' => 'authorized',
                'authorization_nonce' => (string) Str::uuid(),
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ]);
        });

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
        ]);
    }

    public function status(Request $request, DnsServer $server): JsonResponse
    {
        $this->authorizeServer($request, $server);

        $operation = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->where('action', 'apply_zones')
            ->latest('id')
            ->first();

        if (! $operation) {
            return response()->json(['ok' => true, 'status' => null]);
        }

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'result' => $operation->result,
            'error' => $operation->error,
            'requested_at' => $operation->authorized_at?->toIso8601String(),
            'agent_online' => $server->agent_status === 'online',
            'agent_last_seen_at' => $server->agent?->last_seen_at?->toIso8601String(),
        ]);
    }

    private function authorizeServer(Request $request, DnsServer $server): int
    {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        abort_unless($organizationId > 0, 403);
        abort_unless((int) $server->organization_id === $organizationId, 404);

        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin || $role === 'organization_admin',
            403,
        );

        return $organizationId;
    }
}
