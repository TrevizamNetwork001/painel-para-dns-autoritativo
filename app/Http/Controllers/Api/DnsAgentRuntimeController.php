<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DnsAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DnsAgentRuntimeController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hostname' => [
                'nullable',
                'string',
                'max:255',
            ],
            'agent_version' => [
                'nullable',
                'string',
                'max:50',
            ],
            'status' => [
                'nullable',
                'in:online,warning',
            ],
            'capabilities' => [
                'nullable',
                'array',
                'max:100',
            ],
        ]);

        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        DB::transaction(function () use (
            $agent,
            $validated,
        ): void {
            $agent = DnsAgent::query()
                ->whereKey($agent->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($agent->revoked_at !== null, 403);

            $now = now();
            $metadata = $agent->metadata ?? [];

            if (array_key_exists('agent_version', $validated)) {
                $metadata['agent_version'] =
                    $validated['agent_version'];
            }

            $agent->forceFill([
                'reported_hostname' => isset($validated['hostname'])
                    ? Str::lower($validated['hostname'])
                    : $agent->reported_hostname,
                'last_seen_at' => $now,
                'metadata' => $metadata,
            ])->save();

            $server = $agent->server()
                ->lockForUpdate()
                ->firstOrFail();

            $server->forceFill([
                'agent_uuid' => $agent->agent_uuid,
                'agent_version' =>
                    $validated['agent_version']
                    ?? $server->agent_version,
                'agent_status' => 'online',
                'agent_fingerprint' => $agent->fingerprint,
                'agent_registered_at' =>
                    $server->agent_registered_at
                    ?? $agent->registered_at,
                'last_seen_at' => $now,
                'status' => $validated['status'] ?? 'online',
                'capabilities' =>
                    $validated['capabilities']
                    ?? $server->capabilities,
            ])->save();
        });

        return response()->json([
            'ok' => true,
            'message' => 'Heartbeat recebido.',
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hostname' => [
                'nullable',
                'string',
                'max:255',
            ],
            'operating_system' => [
                'required',
                'string',
                'max:80',
            ],
            'operating_system_version' => [
                'nullable',
                'string',
                'max:80',
            ],
            'bind_version' => [
                'nullable',
                'string',
                'max:80',
            ],
            'agent_version' => [
                'nullable',
                'string',
                'max:50',
            ],
            'capabilities' => [
                'nullable',
                'array',
                'max:100',
            ],
            'inventory' => [
                'required',
                'array',
                'max:200',
            ],
        ]);

        /** @var DnsAgent $agent */
        $agent = $request->attributes->get('dns_agent');

        DB::transaction(function () use (
            $agent,
            $validated,
        ): void {
            $agent = DnsAgent::query()
                ->whereKey($agent->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($agent->revoked_at !== null, 403);

            $now = now();
            $metadata = $agent->metadata ?? [];
            $metadata['last_inventory_at'] =
                $now->toIso8601String();

            if (array_key_exists('agent_version', $validated)) {
                $metadata['agent_version'] =
                    $validated['agent_version'];
            }

            $agent->forceFill([
                'reported_hostname' => isset($validated['hostname'])
                    ? Str::lower($validated['hostname'])
                    : $agent->reported_hostname,
                'last_seen_at' => $now,
                'metadata' => $metadata,
            ])->save();

            $server = $agent->server()
                ->lockForUpdate()
                ->firstOrFail();

            $server->forceFill([
                'operating_system' =>
                    $validated['operating_system'],
                'operating_system_version' =>
                    $validated['operating_system_version']
                    ?? null,
                'bind_version' =>
                    $validated['bind_version']
                    ?? null,
                'agent_uuid' => $agent->agent_uuid,
                'agent_version' =>
                    $validated['agent_version']
                    ?? $server->agent_version,
                'agent_status' => 'online',
                'agent_fingerprint' => $agent->fingerprint,
                'agent_registered_at' =>
                    $server->agent_registered_at
                    ?? $agent->registered_at,
                'last_seen_at' => $now,
                'status' => 'online',
                'capabilities' =>
                    $validated['capabilities']
                    ?? $server->capabilities,
                'inventory' => $validated['inventory'],
            ])->save();
        });

        return response()->json([
            'ok' => true,
            'message' => 'Inventário atualizado.',
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
