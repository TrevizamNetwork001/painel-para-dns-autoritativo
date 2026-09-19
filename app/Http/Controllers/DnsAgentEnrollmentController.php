<?php

namespace App\Http\Controllers;

use App\Models\DnsAgent;
use App\Models\DnsAgentEnrollmentCode;
use App\Models\DnsAgentInstallRequest;
use App\Models\DnsBindDiscoveredZone;
use App\Models\DnsBindOperation;
use App\Models\DnsServer;
use App\Services\DnsBindConfigConflicts;
use App\Support\AgentArtifact;
use App\Support\AgentSourceBlocker;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

        DnsBindOperation::expireStaleOperations($server->id);

        $agent = DnsAgent::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->latest('id')
            ->first();

        $latestInstallRequest = Schema::hasTable('dns_agent_install_requests')
            ? DnsAgentInstallRequest::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->latest('id')
                ->first()
            : null;

        $latestEnrollmentCode = Schema::hasTable('dns_agent_enrollment_codes')
            ? DnsAgentEnrollmentCode::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->latest('id')
                ->first()
            : null;

        $latestPublication = $server->agentPublications()
            ->with('zoneVersion.zone')
            ->latest('dns_zone_version_id')
            ->first();

        $latestAppliedPublication = $server->agentPublications()
            ->where('status', 'applied')
            ->latest('last_apply_at')
            ->first();

        $latestBindOperation = $server->bindOperations()
            ->whereIn('action', ['install_bind', 'configure_bind'])
            ->latest('id')
            ->first();

        $latestDiscoveryOperation = $server->bindOperations()
            ->where('action', 'discover_bind_zones')
            ->latest('id')
            ->first();

        $latestAgentUpgradeOperation = $server->bindOperations()
            ->where('action', 'upgrade_agent')
            ->latest('id')
            ->first();

        $discoveredZones = $latestDiscoveryOperation && $latestDiscoveryOperation->status === 'succeeded'
            ? DnsBindDiscoveredZone::query()
                ->where('dns_bind_operation_id', $latestDiscoveryOperation->id)
                ->get(['detected_type', 'comparison_state', 'validation_status'])
            : collect();

        $discoveryStats = [
            'total' => $discoveredZones->count(),
            'primary' => $discoveredZones->where('detected_type', 'primary')->count(),
            'secondary' => $discoveredZones->where('detected_type', 'secondary')->count(),
            'imported' => $discoveredZones->where('comparison_state', 'imported')->count(),
        ];

        $pendingPublicationCount = $server->agentPublications()
            ->whereIn('status', ['pending', 'downloaded', 'applying', 'failed'])
            ->count();

        $installedAgentVersion = $agent?->metadata['agent_version'] ?? $server->agent_version;
        $availableAgentVersion = AgentArtifact::availableVersion();
        $legacyZoneConflicts = (new DnsBindConfigConflicts)->forServer($server);

        return view('servers.agent', [
            'server' => $server,
            'agent' => $agent,
            'latestInstallRequest' => $latestInstallRequest,
            'latestEnrollmentCode' => $latestEnrollmentCode,
            'issuedEnrollmentCode' => $request->session()->pull('issued_enrollment_code'),
            'latestPublication' => $latestPublication,
            'latestAppliedPublication' => $latestAppliedPublication,
            'latestBindOperation' => $latestBindOperation,
            'latestDiscoveryOperation' => $latestDiscoveryOperation,
            'latestAgentUpgradeOperation' => $latestAgentUpgradeOperation,
            'installedAgentVersion' => $installedAgentVersion,
            'availableAgentVersion' => $availableAgentVersion,
            'agentUpdateAvailable' => AgentArtifact::isNewerThan($installedAgentVersion, $availableAgentVersion),
            'discoveredZoneCount' => $discoveryStats['total'],
            'discoveryStats' => $discoveryStats,
            'pendingPublicationCount' => $pendingPublicationCount,
            'legacyZoneConflicts' => $legacyZoneConflicts,
        ]);
    }

    public function issueCode(Request $request, DnsServer $server): RedirectResponse
    {
        $organizationId = $this->authorizeServer($request, $server);
        abort_unless($server->enabled, 409, 'O servidor está inativo.');
        abort_unless($server->organization?->status === 'active', 409, 'A organização está inativa.');
        abort_if(
            DnsAgent::query()->where('dns_server_id', $server->id)->whereNull('revoked_at')->exists(),
            409,
            'Este servidor já possui agente ativo. Use o fluxo explícito de rotação.',
        );

        $plainCode = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $previousCodeIds = DnsAgentEnrollmentCode::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->whereNull('used_at')->whereNull('revoked_at')->pluck('id');
        $issued = DB::transaction(function () use ($request, $server, $organizationId, $plainCode): DnsAgentEnrollmentCode {
            DnsAgentEnrollmentCode::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->whereNull('used_at')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            return DnsAgentEnrollmentCode::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'created_by' => $request->user()->id,
                'code_hash' => hash('sha256', $plainCode),
                'expires_at' => now()->addMinutes(max(1, (int) config('security.agent_enrollment.ttl_minutes'))),
            ]);
        });

        SecurityAuditLogger::record(
            event: 'agent.enrollment_code_issued', user: $request->user(), result: 'success',
            actor: 'user:'.$request->user()->id, source: 'web', ipAddress: $request->ip(),
            userAgent: $request->userAgent(), organizationId: $organizationId,
            reason: 'enrollment_code:'.$issued->id.';server:'.$server->id,
        );

        foreach ($previousCodeIds as $previousCodeId) {
            SecurityAuditLogger::record(
                event: 'agent.enrollment_code_revoked', user: $request->user(), result: 'superseded',
                actor: 'user:'.$request->user()->id, source: 'web', ipAddress: $request->ip(),
                userAgent: $request->userAgent(), organizationId: $organizationId,
                reason: 'enrollment_code:'.$previousCodeId.';server:'.$server->id,
            );
        }

        return redirect()->route('servers.agent.show', $server)
            ->with('status', 'Vínculo temporário criado. O código será exibido somente uma vez.')
            ->with('issued_enrollment_code', $plainCode);
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

        DnsBindOperation::expireStaleOperations($server->id);

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

    public function installRequestStatus(
        Request $request,
        DnsServer $server,
    ): JsonResponse {
        $organizationId = $this->authorizeServer($request, $server);

        $installRequest = DnsAgentInstallRequest::query()
            ->where('organization_id', $organizationId)
            ->where('dns_server_id', $server->id)
            ->latest('id')
            ->first();

        $actionable = $installRequest !== null && (
            ($installRequest->status === 'pending' && $installRequest->expires_at->isFuture())
            || ($installRequest->status === 'approved' && $installRequest->claimed_at === null)
        );

        return response()->json([
            'ok' => true,
            'request' => $installRequest ? [
                'id' => $installRequest->id,
                'status' => $installRequest->status,
                'actionable' => $actionable,
                'updated_at' => $installRequest->updated_at?->toIso8601String(),
            ] : null,
        ]);
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
            $plainToken = Str::random(96);
            $now = now();
            $existingAgent = DnsAgent::query()
                ->where('agent_uuid', $locked->agent_uuid)
                ->lockForUpdate()
                ->first();
            abort_if(
                $existingAgent && $existingAgent->revoked_at === null,
                409,
                'Este agente já está registrado em outro vínculo ativo.',
            );

            if ($existingAgent
                && ((int) $existingAgent->dns_server_id !== (int) $server->id
                    || (int) $existingAgent->organization_id !== $organizationId)
            ) {
                $previousAgentUuid = $existingAgent->agent_uuid;
                $metadata = is_array($existingAgent->metadata) ? $existingAgent->metadata : [];
                $metadata['superseded_agent_uuid'] = $previousAgentUuid;
                $metadata['superseded_at'] = $now->toIso8601String();
                $metadata['superseded_by_organization_id'] = $organizationId;
                $metadata['superseded_by_dns_server_id'] = $server->id;

                // Preserve the revoked row and its historical relations while
                // releasing the physical agent UUID for the new tenant binding.
                DnsServer::query()
                    ->whereKey($existingAgent->dns_server_id)
                    ->where('agent_uuid', $previousAgentUuid)
                    ->lockForUpdate()
                    ->update([
                        'agent_uuid' => null,
                        'updated_at' => $now,
                    ]);
                $existingAgent->forceFill([
                    'agent_uuid' => (string) Str::uuid(),
                    'metadata' => $metadata,
                ])->save();
                $existingAgent = null;
            }

            $agentAttributes = [
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
                'revoked_at' => null,
            ];

            if ($existingAgent) {
                $existingAgent->forceFill($agentAttributes)->save();
            } else {
                DnsAgent::query()->create($agentAttributes);
            }

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
            event: 'agent.enrollment_approved',
            user: $request->user(),
            result: 'success',
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $organizationId,
            reason: 'install_request:'.$installRequest->id,
        );

        if ($installRequest->enrollment_source === 'legacy') {
            SecurityAuditLogger::record(
                event: 'agent.install_approved', user: $request->user(), result: 'success',
                actor: 'user:'.$request->user()->id, source: 'web', ipAddress: $request->ip(),
                userAgent: $request->userAgent(), organizationId: $organizationId,
                reason: 'install_request:'.$installRequest->id,
            );
        }

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Vínculo do agente aprovado.');
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

            app(AgentSourceBlocker::class)->blockToken($agent);

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
                'status' => 'warning',
                'agent_status' => 'blocked',
                'last_seen_at' => null,
            ])->save();
        });

        return redirect()
            ->route('servers.agent.show', $server)
            ->with(
                'status',
                'Credencial do agente revogada com sucesso.',
            );
    }

    public function upgradeAgent(Request $request, DnsServer $server): RedirectResponse|JsonResponse
    {
        $organizationId = $this->authorizeServer($request, $server);

        DB::transaction(function () use ($request, $server, $organizationId): void {
            $server = DnsServer::query()->lockForUpdate()->findOrFail($server->id);
            $this->authorizeServer($request, $server);
            DnsBindOperation::expireStaleOperations($server->id);

            $agent = DnsAgent::query()
                ->where('organization_id', $organizationId)
                ->where('dns_server_id', $server->id)
                ->whereNull('revoked_at')
                ->first();

            abort_unless($agent, 409, 'Agente não vinculado ou revogado.');
            abort_if(
                ! $this->agentRecentlySeen($server),
                409,
                'O agente está offline. Restabeleça a comunicação antes de solicitar a atualização.',
            );

            $inFlight = DnsBindOperation::query()
                ->where('dns_server_id', $server->id)
                ->where('action', 'upgrade_agent')
                ->whereIn('status', ['authorized', 'running'])
                ->exists();

            abort_if($inFlight, 409, 'Já existe uma atualização de agente em andamento para este servidor.');

            DnsBindOperation::query()->create([
                'organization_id' => $organizationId,
                'dns_server_id' => $server->id,
                'dns_agent_id' => $agent->id,
                'action' => 'upgrade_agent',
                'status' => 'authorized',
                'authorization_nonce' => (string) Str::uuid(),
                'authorized_by' => $request->user()->id,
                'authorized_at' => now(),
            ]);
        });

        SecurityAuditLogger::record(
            event: 'agent.upgrade_requested',
            user: $request->user(),
            result: 'success',
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $organizationId,
            reason: 'dns_server:'.$server->id,
        );

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()
            ->route('servers.agent.show', $server)
            ->with('status', 'Atualização do agente solicitada. Ele aplica no próximo ciclo do timer.');
    }

    public function upgradeAgentStatus(Request $request, DnsServer $server): JsonResponse
    {
        $this->authorizeServer($request, $server);

        DnsBindOperation::expireStaleOperations($server->id);

        $installedVersion = $server->agent?->metadata['agent_version'] ?? $server->agent_version;
        $availableVersion = AgentArtifact::availableVersion();
        $versionConfirmed = $installedVersion !== null && $installedVersion === $availableVersion;

        $operation = DnsBindOperation::query()
            ->where('dns_server_id', $server->id)
            ->where('action', 'upgrade_agent')
            ->latest('id')
            ->first();

        if (! $operation) {
            return response()->json([
                'ok' => true,
                'status' => null,
                'installed_version' => $installedVersion,
                'available_version' => $availableVersion,
                'update_available' => AgentArtifact::isNewerThan($installedVersion, $availableVersion),
            ]);
        }

        $result = $operation->status === 'succeeded' ? $operation->result : null;
        $changed = $result['changed'] ?? null;
        $reportedVersion = $result['installed_version'] ?? null;
        $resultConfirmed = is_string($reportedVersion)
            && $reportedVersion === $availableVersion;

        return response()->json([
            'ok' => true,
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'error' => match ($operation->status) {
                'failed' => 'O agente não conseguiu concluir a atualização.',
                'expired' => $operation->started_at
                    ? 'A atualização excedeu o prazo de execução. Verifique o agente antes de tentar novamente.'
                    : 'A solicitação expirou porque o agente não a coletou dentro do prazo.',
                default => null,
            },
            'result' => $result,
            'requested_at' => $operation->authorized_at?->toIso8601String(),
            'expires_at' => ($operation->started_at
                ? $operation->started_at->addMinutes(max(1, (int) config('security.agent_upgrade.running_ttl_minutes', 20)))
                : $operation->authorized_at?->addMinutes(max(1, (int) config('security.agent_upgrade.ttl_minutes', 10))))?->toIso8601String(),
            'agent_online' => $this->agentRecentlySeen($server),
            'agent_last_seen_at' => $server->agent?->last_seen_at?->toIso8601String(),
            'installed_version' => $resultConfirmed ? $reportedVersion : $installedVersion,
            'available_version' => $availableVersion,
            'target_version' => $availableVersion,
            'version_confirmed' => $changed === false || $resultConfirmed || $versionConfirmed,
        ]);
    }

    private function agentRecentlySeen(DnsServer $server): bool
    {
        $lastSeen = $server->agent?->last_seen_at;

        return $server->agent_status === 'online'
            && $lastSeen !== null
            && $lastSeen->greaterThan(now()->subMinutes(10));
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
