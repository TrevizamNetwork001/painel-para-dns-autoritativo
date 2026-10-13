<?php

namespace App\Http\Controllers;

use App\Models\DnsBindOperation;
use App\Models\DnsFirewallPolicy;
use App\Models\DnsServer;
use App\Services\DnsFirewallPolicyBuilder;
use App\Support\DnsAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DnsFirewallPolicyController extends Controller
{
    public function show(Request $request, DnsServer $server): View
    {
        $this->authorizeServer($request, $server);
        $policy = $server->firewallPolicy;
        $latest = $server->bindOperations()->where('action', 'validate_firewall_policy')->latest()->first();

        return view('servers.firewall', compact('server', 'policy', 'latest'));
    }

    public function update(Request $request, DnsServer $server, DnsFirewallPolicyBuilder $builder): RedirectResponse
    {
        $organizationId = $this->authorizeServer($request, $server);
        $validated = $request->validate([
            'administrative_acls' => ['required', 'string', 'max:4000'],
            'administrative_ports' => ['nullable', 'string', 'max:2000'],
            'public_ports' => ['nullable', 'string', 'max:2000'],
        ]);
        $contract = $builder->build($validated['administrative_acls'], $validated['administrative_ports'] ?? '', $validated['public_ports'] ?? '');

        DnsFirewallPolicy::query()->updateOrCreate(
            ['dns_server_id' => $server->id],
            [
                'organization_id' => $organizationId,
                'administrative_acls' => $contract['administrative_acls'],
                'administrative_ports' => $contract['administrative_ports'],
                'public_ports' => $contract['public_ports'],
                'desired_hash' => $contract['desired_hash'],
            ],
        );
        DnsAuditLogger::record($organizationId, $request->user(), 'firewall.policy_updated', recordName: $server->name, newValue: $contract['desired_hash']);

        return back()->with('status', 'Política salva. Nenhuma regra foi aplicada.');
    }

    public function validatePolicy(Request $request, DnsServer $server, DnsFirewallPolicyBuilder $builder): JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);
        $policy = $server->firewallPolicy;
        abort_unless($policy, 409, 'Salve uma política antes de validar.');
        $contract = $builder->build(implode("\n", $policy->administrative_acls), implode("\n", $policy->administrative_ports), implode("\n", $policy->public_ports));

        $operation = DB::transaction(function () use ($request, $server, $organizationId, $contract): DnsBindOperation {
            $locked = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $agent = $locked->agent;
            abort_unless($agent && $agent->revoked_at === null, 409, 'Agente não vinculado ou revogado.');
            DnsBindOperation::expireStaleOperations($server->id);
            abort_if($locked->bindOperations()->where('action', 'validate_firewall_policy')->whereIn('status', ['authorized', 'running'])->exists(), 409, 'Já existe uma validação em andamento.');

            return DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'validate_firewall_policy',
                'params' => $contract,
                'status' => 'authorized',
                'authorization_nonce' => (string) Str::uuid(),
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ]);
        });
        DnsAuditLogger::record($organizationId, $request->user(), 'firewall.validation_requested', recordName: $server->name, newValue: $contract['desired_hash']);

        return response()->json(['ok' => true, 'operation_id' => $operation->id, 'status' => $operation->status]);
    }

    public function status(Request $request, DnsServer $server, DnsBindOperation $operation): JsonResponse
    {
        $this->authorizeServer($request, $server);
        abort_unless($operation->dns_server_id === $server->id && $operation->action === 'validate_firewall_policy', 404);

        return response()->json(['ok' => true, 'status' => $operation->status, 'result' => $operation->result, 'error' => $operation->error]);
    }

    private function authorizeServer(Request $request, DnsServer $server): int
    {
        $organizationId = (int) $request->user()->current_organization_id;
        abort_unless($organizationId > 0 && $server->organization_id === $organizationId, 404);
        abort_unless($request->user()->is_platform_admin || $request->user()->roleForOrganization($organizationId) === 'organization_admin', 403);

        return $organizationId;
    }
}
