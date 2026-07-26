<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class DnsAgentRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'activation_code' => [
                'required',
                'string',
                'max:128',
            ],
            'agent_uuid' => [
                'required',
                'uuid',
            ],
            'fingerprint' => [
                'required',
                'string',
                'min:32',
                'max:255',
            ],
            'hostname' => [
                'required',
                'string',
                'max:255',
            ],
            'agent_version' => [
                'nullable',
                'string',
                'max:50',
            ],
        ]);

        $normalizedCode = Str::upper(
            preg_replace(
                '/[^A-Z0-9]/i',
                '',
                $validated['activation_code'],
            ) ?? '',
        );

        $codeHash = hash('sha256', $normalizedCode);

        try {
            $result = DB::transaction(function () use (
                $request,
                $validated,
                $codeHash,
            ): array {
                $enrollment = DnsAgentEnrollment::query()
                    ->where('code_hash', $codeHash)
                    ->lockForUpdate()
                    ->first();

                if (! $enrollment) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'Código de ativação inválido.',
                    ]);
                }

                if ($enrollment->revoked_at !== null) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'Código de ativação revogado.',
                    ]);
                }

                if ($enrollment->used_at !== null) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'Código de ativação já utilizado.',
                    ]);
                }

                if ($enrollment->expires_at->isPast()) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'Código de ativação expirado.',
                    ]);
                }

                $server = $enrollment->server()
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $server->enabled) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'O servidor está desativado no painel.',
                    ]);
                }

                $existingAgent = DnsAgent::query()
                    ->where('dns_server_id', $server->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->first();

                if ($existingAgent) {
                    throw ValidationException::withMessages([
                        'activation_code' =>
                            'O servidor já possui um agente ativo.',
                    ]);
                }

                $uuidInUse = DnsAgent::query()
                    ->where('agent_uuid', $validated['agent_uuid'])
                    ->exists();

                if ($uuidInUse) {
                    throw ValidationException::withMessages([
                        'agent_uuid' =>
                            'Este UUID de agente já está registrado.',
                    ]);
                }

                $plainToken = Str::random(96);
                $now = now();

                $agent = DnsAgent::query()->create([
                    'organization_id' =>
                        $enrollment->organization_id,
                    'dns_server_id' =>
                        $enrollment->dns_server_id,
                    'agent_uuid' =>
                        $validated['agent_uuid'],
                    'fingerprint' =>
                        $validated['fingerprint'],
                    'token_hash' =>
                        hash('sha256', $plainToken),
                    'reported_hostname' =>
                        Str::lower($validated['hostname']),
                    'registered_ip' =>
                        $request->ip(),
                    'registered_at' => $now,
                    'last_seen_at' => $now,
                    'metadata' => [
                        'agent_version' =>
                            $validated['agent_version'] ?? null,
                    ],
                ]);

                $enrollment->forceFill([
                    'used_at' => $now,
                ])->save();

                $server->forceFill([
                    'status' => 'pending',
                    'agent_uuid' => $agent->agent_uuid,
                    'agent_version' =>
                        $validated['agent_version'] ?? null,
                    'agent_status' => 'pending',
                    'agent_fingerprint' => $agent->fingerprint,
                    'agent_registered_at' => $now,
                    'last_seen_at' => $now,
                ])->save();

                return [
                    'agent' => $agent,
                    'server' => $server,
                    'plain_token' => $plainToken,
                ];
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'registration_failed',
                'message' =>
                    'Não foi possível registrar o agente.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Agente registrado com sucesso.',
            'agent' => [
                'uuid' => $result['agent']->agent_uuid,
                'token' => $result['plain_token'],
            ],
            'server' => [
                'id' => $result['server']->id,
                'name' => $result['server']->name,
                'hostname' => $result['server']->hostname,
                'role' => $result['server']->role,
            ],
            'endpoints' => [
                'heartbeat' => '/api/agent/heartbeat',
                'inventory' => '/api/agent/inventory',
            ],
        ], 201);
    }
}
