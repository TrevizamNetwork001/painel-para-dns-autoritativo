<?php

namespace App\Http\Controllers;

use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Services\DnsBindConfigConflicts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DnsBindLegacyZoneBlockController extends Controller
{
    public function store(Request $request, DnsServer $server): JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        $validated = $request->validate([
            'source_file' => ['required', 'string', 'max:255'],
            'start_line' => ['required', 'integer', 'min:1'],
            'end_line' => ['required', 'integer', 'min:1'],
            'hash' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]+\z/'],
            'zone_name' => ['required', 'string', 'max:255'],
        ]);

        $operation = DB::transaction(function () use ($request, $server, $organizationId, $validated): DnsBindOperation {
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);

            $agent = $server->agent;
            abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');

            DnsBindOperation::expireStaleOperations($server->id);

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'remove_legacy_zone_block')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            abort_if($inFlight, 409, 'Já existe uma remoção de bloco legado em andamento para este servidor.');

            // Re-check against the server's last known readiness snapshot —
            // the panel only ever offers a "Remover" button for blocks it
            // currently knows about, but this stops a stale or tampered
            // request from pointing the agent at an arbitrary file/line.
            $stillReported = collect((new DnsBindConfigConflicts)->forServer($server))
                ->contains(
                    fn (array $conflict) => $conflict['source_file'] === $validated['source_file']
                        && $conflict['start_line'] === $validated['start_line']
                        && $conflict['end_line'] === $validated['end_line'],
                );

            abort_unless(
                $stillReported,
                409,
                'Este bloco não corresponde mais ao último inventário conhecido do servidor. Aguarde a próxima verificação de prontidão e tente novamente.',
            );

            return DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'remove_legacy_zone_block',
                'params' => $validated,
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

    public function status(Request $request, DnsServer $server, DnsBindOperation $operation): JsonResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless((int) $operation->dns_server_id === (int) $server->id, 404);
        abort_unless($operation->action === 'remove_legacy_zone_block', 404);

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'result' => $operation->result,
            'error' => $operation->error,
            'agent_online' => $server->agent_status === 'online',
            'agent_last_seen_at' => $server->agent?->last_seen_at?->toIso8601String(),
            // A lista de conflitos vem do relatório de prontidão, que o agente envia
            // depois da operação; a tela só deve recarregar quando ele já chegou.
            'readiness_refreshed' => $operation->completed_at !== null
                && $server->bind_readiness_at !== null
                && $server->bind_readiness_at->gte($operation->completed_at),
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
