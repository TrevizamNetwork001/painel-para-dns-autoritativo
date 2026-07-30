<?php

namespace App\Http\Controllers;

use App\Models\DnsAgent;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Support\SecurityAuditLogger;
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

        $latestInstallRequest = DnsAgentInstallRequest::query()
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

        $latestBindOperation = $server->bindOperations()
            ->latest('id')
            ->first();

        return view('servers.agent', [
            'server' => $server,
            'agent' => $agent,
            'latestInstallRequest' => $latestInstallRequest,
            'latestPublication' => $latestPublication,
            'latestAppliedPublication' => $latestAppliedPublication,
            'latestBindOperation' => $latestBindOperation,
        ]);
    }

    public function planBind(
        Request $request,
        DnsServer $server,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer($request, $server);
        $agent = DnsAgent::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->whereNull('revoked_at')
            ->firstOrFail();

        abort_unless($server->bind_readiness_at !== null, 409, 'Aguarde o inventário de prontidão do agente.');

        $action = data_get($server->bind_readiness, 'bind_installed', false)
            ? 'configure_bind'
            : 'install_bind';

        $operation = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->whereIn('status', ['planned', 'authorized', 'running'])
            ->latest('id')
            ->first();

        if (! $operation) {
            $operation = DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => $action,
                'status' => 'planned',
                'authorization_nonce' => (string) Str::uuid(),
            ]);
        }

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Plano BIND preparado. Nenhuma ação foi executada.');
    }

    public function authorizeBind(
        Request $request,
        DnsServer $server,
        DnsBindOperation $operation,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer($request, $server);

        abort_unless(
            (int) $operation->organization_id === $organizationId
            && (int) $operation->dns_server_id === (int) $server->id,
            404,
        );

        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:180'],
        ]);

        abort_unless(
            hash_equals(
                'AUTORIZAR BIND '.Str::upper($server->name),
                Str::upper(trim($validated['confirmation'])),
            ),
            422,
            'A confirmação forte não corresponde ao servidor.',
        );

        if ($operation->status === 'planned') {
            $operation->forceFill([
                'status' => 'authorized',
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ])->save();

            SecurityAuditLogger::record(
                event: 'bind.operation_authorized',
                user: $request->user(),
                result: 'success',
                actor: 'user:'.$request->user()->id,
                source: 'web',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Operação BIND autorizada para execução pelo agente.');
    }

    public function approve(
        Request $request,
        DnsServer $server,
        DnsAgentInstallRequest $installRequest,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer($request, $server);

        abort_unless(
            (int) $installRequest->organization_id === $organizationId
            && (int) $installRequest->dns_server_id === (int) $server->id,
            404,
        );

        DB::transaction(function () use (
            $request,
            $server,
            $installRequest,
            $organizationId,
        ): void {
            $locked = DnsAgentInstallRequest::query()
                ->whereKey($installRequest->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $locked->status === 'pending'
                && $locked->expires_at->isFuture(),
                409,
                'A solicitação não está mais disponível.',
            );
            abort_if(
                DnsAgent::query()
                    ->where('dns_server_id', $server->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->exists(),
                409,
                'Este servidor já possui um agente ativo.',
            );
            abort_if(
                DnsAgent::query()
                    ->where('agent_uuid', $locked->agent_uuid)
                    ->exists(),
                409,
                'Este agente já foi registrado.',
            );

            $plainToken = Str::random(96);
            $now = now();

            DnsAgent::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'agent_uuid' => $locked->agent_uuid,
                'fingerprint' => $locked->fingerprint,
                'token_hash' => hash('sha256', $plainToken),
                'reported_hostname' => $locked->reported_hostname,
                'registered_ip' => $locked->registered_ip,
                'registered_at' => $now,
                'last_seen_at' => $now,
                'metadata' => [
                    'agent_version' => $locked->agent_version,
                    'installation' => 'panel_approval',
                ],
            ]);

            $locked->forceFill([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => $now,
                'agent_token' => $plainToken,
            ])->save();

            $server->forceFill([
                'status' => 'pending',
                'agent_uuid' => $locked->agent_uuid,
                'agent_version' => $locked->agent_version,
                'agent_status' => 'pending',
                'agent_fingerprint' => $locked->fingerprint,
                'agent_registered_at' => $now,
                'last_seen_at' => $now,
            ])->save();
        });

        SecurityAuditLogger::record(
            event: 'agent.install_approved',
            user: $request->user(),
            result: 'success',
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $organizationId,
            reason: 'install_request:'.$installRequest->id,
        );

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Instalação do agente aprovada.');
    }

    public function reject(
        Request $request,
        DnsServer $server,
        DnsAgentInstallRequest $installRequest,
    ): RedirectResponse {
        $organizationId = $this->authorizeServer($request, $server);
        abort_unless(
            (int) $installRequest->organization_id === $organizationId
            && (int) $installRequest->dns_server_id === (int) $server->id,
            404,
        );

        $installRequest->forceFill([
            'status' => 'rejected',
            'rejected_at' => now(),
            'agent_token' => null,
        ])->save();

        SecurityAuditLogger::record(
            event: 'agent.install_rejected',
            user: $request->user(),
            result: 'success',
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $organizationId,
            reason: 'install_request:'.$installRequest->id,
        );

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Solicitação de instalação rejeitada.');
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

            DnsAgentInstallRequest::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'rejected_at' => $now,
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
}
