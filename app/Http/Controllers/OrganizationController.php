<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Support\DnsAuditLogger;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensurePlatformAdmin($request);

        $organizations = Organization::query()
            ->withCount('users')
            ->orderByDesc('created_at')
            ->get();

        return view('organizations.index', [
            'organizations' => $organizations,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensurePlatformAdmin($request);

        $validated = $request->validate([
            'organization_name' => [
                'required',
                'string',
                'min:2',
                'max:150',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $slug = Str::slug((string) $value);

                    if ($slug === '' || Organization::query()->where('slug', $slug)->exists()) {
                        $fail('Já existe uma empresa com um nome equivalente.');
                    }
                },
            ],
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
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $organization = Organization::query()->create([
                'name' => trim($validated['organization_name']),
                'status' => 'active',
                'is_default' => false,
            ]);

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
                'role' => 'organization_admin',
                'status' => 'active',
                'is_default' => true,
            ]);

            DnsAuditLogger::record(
                organizationId: $organization->id,
                user: $request->user(),
                action: 'organization.created',
                recordName: $organization->name,
            );
        });

        return redirect()
            ->route('organizations.index')
            ->with(
                'status',
                'Empresa e usuário administrador criados com sucesso.',
            );
    }

    private function ensurePlatformAdmin(Request $request): void
    {
        abort_unless(
            $request->user()->is_platform_admin,
            403,
            'Apenas administradores da plataforma podem gerenciar empresas.',
        );
    }
}
