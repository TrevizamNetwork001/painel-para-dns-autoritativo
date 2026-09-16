<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Support\DnsAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    private const ROLES = [
        'organization_admin',
        'operator',
        'viewer',
    ];

    public function index(Request $request): View
    {
        $organization = $this->organization($request);

        $users = $organization->users()
            ->orderBy('name')
            ->paginate(20);

        return view('users.index', [
            'organization' => $organization,
            'users' => $users,
            'roles' => self::ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'min:3',
                'max:150',
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => [
                'required',
                'confirmed',
                'string',
                'min:8',
            ],
            'role' => [
                'required',
                Rule::in(self::ROLES),
            ],
        ]);

        DB::transaction(function () use (
            $request,
            $validated,
            $organization
        ): void {
            $user = new User;

            $user->forceFill([
                'name' => trim($validated['name']),
                'email' => mb_strtolower(trim($validated['email'])),
                'password' => Hash::make($validated['password']),
                'current_organization_id' => $organization->id,
                'is_platform_admin' => false,
                'status' => 'active',
                'must_change_password' => true,
                'temporary_password_expires_at' => now()->addHours(48),
                'password_changed_at' => null,
            ])->save();

            $user->organizations()->attach($organization->id, [
                'role' => $validated['role'],
                'status' => 'active',
                'is_default' => true,
            ]);

            DnsAuditLogger::record(
                organizationId: $organization->id,
                user: $request->user(),
                action: 'user.created',
                recordName: $user->email,
                newValue: $validated['role'],
            );
        });

        return redirect()
            ->route('users.index')
            ->with('status', 'Usuário criado com sucesso.');
    }

    public function updateRole(
        Request $request,
        User $user
    ): RedirectResponse {
        $organization = $this->organization($request);

        abort_unless(
            $this->hasOrganizationMembership($user, $organization),
            404
        );

        if ($request->user()->is($user)) {
            return back()->withErrors([
                'role' => 'Você não pode alterar seu próprio papel.',
            ]);
        }

        if ($user->is_platform_admin && ! $request->user()->is_platform_admin) {
            abort(403, 'Você não pode alterar um administrador da plataforma.');
        }

        $validated = $request->validate([
            'role' => [
                'required',
                Rule::in(self::ROLES),
            ],
        ]);

        $previousRole = $user->roleForOrganization($organization->id);

        $user->organizations()->updateExistingPivot(
            $organization->id,
            ['role' => $validated['role']]
        );

        DnsAuditLogger::record(
            organizationId: $organization->id,
            user: $request->user(),
            action: 'user.role_updated',
            recordName: $user->email,
            oldValue: $previousRole,
            newValue: $validated['role'],
        );

        return back()->with(
            'status',
            'Papel atualizado com sucesso.'
        );
    }

    public function updateStatus(
        Request $request,
        User $user
    ): RedirectResponse {
        $organization = $this->organization($request);

        abort_unless(
            $this->hasOrganizationMembership($user, $organization),
            404
        );

        if ($request->user()->is($user)) {
            return back()->withErrors([
                'status' => 'Você não pode desativar sua própria conta.',
            ]);
        }

        if ($user->is_platform_admin && ! $request->user()->is_platform_admin) {
            abort(403, 'Você não pode desativar um administrador da plataforma.');
        }

        $newStatus = $user->status === 'active'
            ? 'inactive'
            : 'active';

        DB::transaction(function () use (
            $request,
            $user,
            $organization,
            $newStatus
        ): void {
            $user->forceFill([
                'status' => $newStatus,
            ])->save();

            $user->organizations()->updateExistingPivot(
                $organization->id,
                ['status' => $newStatus]
            );

            DnsAuditLogger::record(
                organizationId: $organization->id,
                user: $request->user(),
                action: 'user.status_updated',
                recordName: $user->email,
                newValue: $newStatus,
            );
        });

        return back()->with(
            'status',
            $newStatus === 'active'
                ? 'Usuário ativado com sucesso.'
                : 'Usuário desativado com sucesso.'
        );
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()
            ->currentOrganization()
            ->where('status', 'active')
            ->first();

        abort_unless($organization, 403);

        return $organization;
    }

    private function hasOrganizationMembership(
        User $user,
        Organization $organization,
    ): bool {
        return $organization->users()
            ->whereKey($user->id)
            ->exists();
    }
}
