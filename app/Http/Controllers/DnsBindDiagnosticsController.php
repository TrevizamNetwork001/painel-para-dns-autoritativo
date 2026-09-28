<?php

namespace App\Http\Controllers;

use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Models\DnsZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Diagnóstico somente leitura (log do BIND) e uma ação operacional rotineira
 * (forçar transferência de zona) — nenhuma escrita em named.conf, nenhuma
 * mudança de configuração. As duas reaproveitam 100% o mecanismo de operação
 * autorizada já existente (poll pelo agente, nonce, expiração por TTL).
 */
class DnsBindDiagnosticsController extends Controller
{
    public function storeLog(Request $request, DnsServer $server): JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        $operation = DB::transaction(function () use ($request, $server, $organizationId): DnsBindOperation {
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);

            $agent = $server->agent;
            abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');

            DnsBindOperation::expireStaleOperations($server->id);

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'tail_bind_log')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            abort_if($inFlight, 409, 'Já existe uma leitura de log em andamento para este servidor.');

            return DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'tail_bind_log',
                'params' => ['lines' => 200],
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

    public function logStatus(Request $request, DnsServer $server, DnsBindOperation $operation): JsonResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless((int) $operation->dns_server_id === (int) $server->id, 404);
        abort_unless($operation->action === 'tail_bind_log', 404);

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'result' => $operation->result,
            'error' => $operation->error,
            'agent_online' => $server->agent_status === 'online',
        ]);
    }

    public function storeTransfer(Request $request, DnsServer $server, DnsZone $zone): JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);
        abort_unless((int) $zone->organization_id === $organizationId, 404);

        $operation = DB::transaction(function () use ($request, $server, $zone, $organizationId): DnsBindOperation {
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);

            $role = $zone->servers()->where('dns_servers.id', $server->id)->value('dns_server_zone.role');

            abort_unless(
                $role === 'secondary',
                409,
                'Este servidor não está configurado como secundário desta zona.',
            );

            $agent = $server->agent;
            abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');

            DnsBindOperation::expireStaleOperations($server->id);

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'force_zone_transfer')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            abort_if($inFlight, 409, 'Já existe uma transferência em andamento para este servidor.');

            return DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'force_zone_transfer',
                'params' => ['zone_name' => $zone->name],
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

    public function transferStatus(Request $request, DnsServer $server, DnsBindOperation $operation): JsonResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless((int) $operation->dns_server_id === (int) $server->id, 404);
        abort_unless($operation->action === 'force_zone_transfer', 404);

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'result' => $operation->result,
            'error' => $operation->error,
            'agent_online' => $server->agent_status === 'online',
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
