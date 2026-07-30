<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsServer;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DnsAgentInstallRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        if (! Schema::hasTable('dns_agent_install_requests')) {
            return $this->migrationPending();
        }

        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'request_token' => ['required', 'string', 'min:32', 'max:255'],
            'agent_uuid' => ['required', 'uuid'],
            'fingerprint' => ['required', 'string', 'min:32', 'max:255'],
            'hostname' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-zA-Z0-9.-]+\z/',
            ],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'operating_system' => ['nullable', 'string', 'max:80'],
            'operating_system_version' => ['nullable', 'string', 'max:80'],
        ]);

        $hostname = Str::lower(rtrim($validated['hostname'], '.'));
        $fingerprint = hash('sha256', $validated['fingerprint']);
        $requestTokenHash = hash('sha256', $validated['request_token']);

        $existing = DnsAgentInstallRequest::query()
            ->where('request_id', $validated['request_id'])
            ->first();

        if ($existing) {
            abort_unless(
                hash_equals($existing->request_token_hash, $requestTokenHash)
                && hash_equals($existing->fingerprint, $fingerprint),
                409,
            );

            return $this->pendingResponse($existing);
        }

        $matches = DnsServer::query()
            ->where('enabled', true)
            ->whereDoesntHave('agent', fn ($query) => $query->whereNull('revoked_at'))
            ->where(function ($query) use ($hostname, $request): void {
                $query->whereRaw('LOWER(hostname) = ?', [$hostname]);

                if ($request->ip()) {
                    $query->orWhere('ipv4_address', $request->ip())
                        ->orWhere('ipv6_address', $request->ip());
                }
            })
            ->limit(2)
            ->get();

        $server = $matches->count() === 1 ? $matches->first() : null;

        $installRequest = DnsAgentInstallRequest::query()->create([
            'request_id' => $validated['request_id'],
            'request_token_hash' => $requestTokenHash,
            'agent_uuid' => $validated['agent_uuid'],
            'fingerprint' => $fingerprint,
            'reported_hostname' => $hostname,
            'registered_ip' => $request->ip(),
            'agent_version' => $validated['agent_version'] ?? null,
            'operating_system' => $validated['operating_system'] ?? null,
            'operating_system_version' => $validated['operating_system_version'] ?? null,
            'organization_id' => $server?->organization_id,
            'dns_server_id' => $server?->id,
            'status' => 'pending',
            'expires_at' => now()->addHours(24),
        ]);

        SecurityAuditLogger::record(
            event: 'agent.install_requested',
            user: null,
            result: $server ? 'matched' : 'unmatched',
            actor: 'agent-request:'.$installRequest->request_id,
            source: 'agent',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $server?->organization_id,
            reason: 'install_request:'.$installRequest->id,
        );

        return $this->pendingResponse($installRequest);
    }

    public function status(Request $request): JsonResponse
    {
        if (! Schema::hasTable('dns_agent_install_requests')) {
            return $this->migrationPending();
        }

        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            'request_token' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        $installRequest = DnsAgentInstallRequest::query()
            ->where('request_id', $validated['request_id'])
            ->firstOrFail();

        abort_unless(
            hash_equals(
                $installRequest->request_token_hash,
                hash('sha256', $validated['request_token']),
            ),
            404,
        );

        if ($installRequest->expires_at->isPast() && $installRequest->status === 'pending') {
            $installRequest->forceFill(['status' => 'expired'])->save();
        }

        if ($installRequest->status !== 'approved') {
            return response()->json([
                'ok' => true,
                'status' => $installRequest->status,
                'matched' => $installRequest->dns_server_id !== null,
                'expires_at' => $installRequest->expires_at->toIso8601String(),
            ]);
        }

        if ($installRequest->claimed_at || ! $installRequest->agent_token) {
            return response()->json([
                'ok' => false,
                'status' => 'claimed',
                'message' => 'A credencial desta solicitação já foi entregue.',
            ], 410);
        }

        return DB::transaction(function () use ($installRequest): JsonResponse {
            $locked = DnsAgentInstallRequest::query()
                ->whereKey($installRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($locked->claimed_at || ! $locked->agent_token, 410);

            $token = $locked->agent_token;
            $server = $locked->server()->firstOrFail();
            $locked->forceFill([
                'claimed_at' => now(),
                'agent_token' => null,
            ])->save();

            return response()->json([
                'ok' => true,
                'status' => 'approved',
                'agent' => [
                    'uuid' => $locked->agent_uuid,
                    'token' => $token,
                ],
                'server' => [
                    'id' => $server->id,
                    'name' => $server->name,
                    'hostname' => $server->hostname,
                    'role' => $server->role,
                ],
            ]);
        });
    }

    private function pendingResponse(DnsAgentInstallRequest $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'status' => $request->status,
            'request_id' => $request->request_id,
            'matched' => $request->dns_server_id !== null,
            'expires_at' => $request->expires_at->toIso8601String(),
        ], 202);
    }

    private function migrationPending(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'installation_unavailable',
            'message' => 'O fluxo de instalação ainda não está disponível.',
        ], 503);
    }
}
