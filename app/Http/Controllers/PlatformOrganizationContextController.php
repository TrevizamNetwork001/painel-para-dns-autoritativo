<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformOrganizationContextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user?->is_platform_admin, 403);

        $validated = $request->validate([
            'organization_id' => [
                'required',
                'integer',
                Rule::exists('organizations', 'id')->where('status', 'active'),
            ],
        ]);

        $previousOrganizationId = $user->current_organization_id;
        $organization = Organization::query()->findOrFail(
            (int) $validated['organization_id'],
        );

        $user->forceFill([
            'current_organization_id' => $organization->id,
        ])->saveQuietly();

        $request->session()->regenerateToken();

        SecurityAuditLogger::record(
            event: 'platform.organization_context_changed',
            user: $user,
            result: 'success',
            actor: 'user',
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: $organization->id,
            reason: sprintf(
                'previous_organization:%s;current_organization:%d',
                $previousOrganizationId ?? 'none',
                $organization->id,
            ),
        );

        return redirect()->route('dashboard')->with(
            'status',
            "Agora você está administrando {$organization->name}.",
        );
    }
}
