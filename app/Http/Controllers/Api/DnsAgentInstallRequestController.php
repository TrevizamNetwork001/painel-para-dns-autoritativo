<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollmentCode;
use App\Models\DnsAgentInstallRequest;
use App\Support\DnsAgentInstallRequestMatcher;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DnsAgentInstallRequestController extends Controller
{
    public function __construct(
        private readonly DnsAgentInstallRequestMatcher $matcher,
    ) {}

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
            'enrollment_code' => ['nullable', 'string', 'min:32', 'max:255'],
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

            if (
                $existing->status === 'pending'
                && $existing->dns_server_id === null
            ) {
                $server = $this->matcher->unique($hostname, $request->ip());

                if ($server) {
                    $existing->forceFill([
                        'organization_id' => $server->organization_id,
                        'dns_server_id' => $server->id,
                        'registered_ip' => $request->ip(),
                    ])->save();
                }
            }

            return $this->pendingResponse($existing);
        }

        $installRequest = DB::transaction(function () use ($validated, $hostname, $fingerprint, $requestTokenHash, $request): DnsAgentInstallRequest {
            $code = null;
            $server = null;
            $warnings = [];

            if (isset($validated['enrollment_code'])) {
                $code = DnsAgentEnrollmentCode::query()
                    ->where('code_hash', hash('sha256', $validated['enrollment_code']))
                    ->lockForUpdate()
                    ->first();
                abort_unless($code && ! $code->used_at && ! $code->revoked_at && $code->expires_at->isFuture(), 422, 'Código de vínculo inválido ou expirado.');
                $server = $code->server()->with('organization')->firstOrFail();
                abort_unless($server->enabled && $server->organization?->status === 'active', 409, 'Servidor ou organização inativo.');
                abort_if(DnsAgent::query()->where('dns_server_id', $server->id)->whereNull('revoked_at')->lockForUpdate()->exists(), 409, 'Este servidor já possui um agente ativo.');

                if (! hash_equals(Str::lower(rtrim($server->hostname, '.')), $hostname)) {
                    $warnings[] = 'hostname_mismatch';
                }
                $knownAddresses = array_values(array_filter([$server->ipv4_address, $server->ipv6_address]));
                if ($request->ip() && ! in_array($request->ip(), $knownAddresses, true)) {
                    $warnings[] = 'ip_mismatch';
                }
                $code->forceFill(['used_at' => now()])->save();
            } else {
                $server = $this->matcher->unique($hostname, $request->ip());
            }

            $attributes = [
                'agent_uuid' => $validated['agent_uuid'],
                'request_id' => $validated['request_id'],
                'request_token_hash' => $requestTokenHash,
                'fingerprint' => $fingerprint,
                'reported_hostname' => $hostname,
                'registered_ip' => $request->ip(),
                'agent_version' => $validated['agent_version'] ?? null,
                'operating_system' => $validated['operating_system'] ?? null,
                'operating_system_version' => $validated['operating_system_version'] ?? null,
                'organization_id' => $server?->organization_id,
                'dns_server_id' => $server?->id,
                'enrollment_code_id' => $code?->id,
                'review_warnings' => $warnings ?: null,
                'enrollment_source' => $code ? 'panel_code' : 'legacy',
                'status' => 'pending',
                'expires_at' => now()->addHours(24),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_at' => null,
                'claimed_at' => null,
                'agent_token' => null,
            ];

            // agent_uuid é único na tabela: um novo request_id do mesmo agente
            // físico (ex.: código trocado, reenrollment) reaproveita a linha
            // anterior em vez de colidir com a unique constraint.
            $previous = DnsAgentInstallRequest::query()
                ->where('agent_uuid', $validated['agent_uuid'])
                ->lockForUpdate()
                ->first();

            if ($previous) {
                abort_if(
                    $previous->status === 'approved' && $previous->claimed_at === null,
                    409,
                    'Uma aprovação para este agente já está pendente de retirada; aguarde a conclusão ou revogue antes de reenviar.',
                );

                $previous->forceFill($attributes)->save();

                return $previous;
            }

            return DnsAgentInstallRequest::query()->create($attributes);
        });

        $server = $installRequest->server;

        SecurityAuditLogger::record(
            event: 'agent.enrollment_requested',
            user: null,
            result: $server ? 'matched' : 'unmatched',
            actor: 'agent-request:'.$installRequest->request_id,
            source: 'agent',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $server?->organization_id,
            reason: 'install_request:'.$installRequest->id,
        );

        if (
            $installRequest->enrollment_source === 'panel_code'
            && ($server?->agent_registered_at || DnsAgent::query()
                ->where('dns_server_id', $server?->id)->whereNotNull('revoked_at')->exists())
        ) {
            SecurityAuditLogger::record(
                event: 'agent.reenrollment_started', user: null, result: 'success',
                actor: 'agent-request:'.$installRequest->request_id, source: 'agent',
                ipAddress: $request->ip(), userAgent: $request->userAgent(),
                organizationId: $installRequest->organization_id,
                reason: 'install_request:'.$installRequest->id.';server:'.$server?->id,
            );
        }

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

            SecurityAuditLogger::record(
                event: 'agent.enrollment_completed', user: null, result: 'success',
                actor: 'agent-request:'.$locked->request_id, source: 'agent',
                organizationId: $locked->organization_id,
                reason: 'install_request:'.$locked->id.';server:'.$server->id,
            );

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
