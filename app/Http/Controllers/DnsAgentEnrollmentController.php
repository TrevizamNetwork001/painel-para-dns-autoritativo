<?php

namespace App\Http\Controllers;

use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollment;
use App\Models\DnsServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DnsAgentEnrollmentController extends Controller
{
    public function show(
        Request $request,
        DnsServer $server,
    ): View {
        $organizationId = $this->authorizeServer(
            $request,
            $server,
        );

        $agent = DnsAgent::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->latest('id')
            ->first();

        $latestEnrollment = DnsAgentEnrollment::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->latest('id')
            ->first();

        $latestPublication = $server->agentPublications()
            ->with('zoneVersion.zone')
            ->latest('dns_zone_version_id')
            ->first();

        $latestAppliedPublication = $server->agentPublications()
            ->where('status', 'applied')
            ->latest('last_apply_at')
            ->first();

        return view('servers.agent', [
            'server' => $server,
            'agent' => $agent,
            'latestEnrollment' => $latestEnrollment,
            'latestPublication' => $latestPublication,
            'latestAppliedPublication' => $latestAppliedPublication,
        ]);
    }

    public function store(
        Request $request,
        DnsServer $server,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer(
            $request,
            $server,
        );

        abort_if(
            DnsAgent::query()
                ->where('dns_server_id', $server->id)
                ->whereNull('revoked_at')
                ->exists(),
            409,
            'Este servidor já possui um agente ativo.',
        );

        $plainCode = $this->generateActivationCode();
        $normalizedCode = $this->normalizeCode($plainCode);

        $enrollment = DB::transaction(function () use (
            $request,
            $server,
            $organizationId,
            $normalizedCode,
        ): DnsAgentEnrollment {
            DnsAgentEnrollment::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            return DnsAgentEnrollment::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'created_by' => $request->user()->id,
                'code_hash' => hash(
                    'sha256',
                    $normalizedCode,
                ),
                'expires_at' => now()->addMinutes(30),
            ]);
        });

        return redirect()
            ->route('servers.agent.show', $server)
            ->with([
                'status' => 'Código de ativação gerado com sucesso.',
                'agent_enrollment_code' => $plainCode,
                'agent_enrollment_expires_at' => $enrollment->expires_at->toIso8601String(),
            ]);
    }

    public function revoke(
        Request $request,
        DnsServer $server,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer(
            $request,
            $server,
        );

        DB::transaction(function () use (
            $server,
            $organizationId,
        ): void {
            $agent = DnsAgent::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            abort_unless($agent, 404, 'Agente ativo não encontrado.');

            $now = now();

            $agent->forceFill([
                'revoked_at' => $now,
            ])->save();

            DnsAgentEnrollment::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'updated_at' => $now,
                ]);

            $server->forceFill([
                'status' => 'pending',
                'agent_uuid' => null,
                'agent_version' => null,
                'agent_status' => 'not_installed',
                'agent_fingerprint' => null,
                'agent_registered_at' => null,
                'last_seen_at' => null,
                'capabilities' => null,
                'inventory' => null,
            ])->save();
        });

        return redirect()
            ->route('servers.agent.show', $server)
            ->with(
                'status',
                'Credencial do agente revogada com sucesso.',
            );
    }

    private function authorizeServer(
        Request $request,
        DnsServer $server,
    ): int {
        $user = $request->user();
        $organizationId = (int) $user->current_organization_id;

        abort_unless($organizationId > 0, 403);
        abort_unless(
            (int) $server->organization_id === $organizationId,
            404,
        );

        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin
                || $role === 'organization_admin',
            403,
        );

        return $organizationId;
    }

    private function generateActivationCode(): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $parts = [];

        for ($group = 0; $group < 4; $group++) {
            $value = '';

            for ($position = 0; $position < 5; $position++) {
                $value .= $characters[
                    random_int(0, strlen($characters) - 1)
                ];
            }

            $parts[] = $value;
        }

        return 'DNSC-'.implode('-', $parts);
    }

    private function normalizeCode(string $code): string
    {
        return Str::upper(
            preg_replace('/[^A-Z0-9]/i', '', $code) ?? '',
        );
    }
}
