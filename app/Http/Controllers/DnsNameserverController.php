<?php

namespace App\Http\Controllers;

use App\Models\DnsNameserverIdentity;
use App\Models\DnsNameserverProfile;
use App\Models\DnsServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DnsNameserverController extends Controller
{
    private const HOSTNAME_REGEX =
        '/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        $identities = DnsNameserverIdentity::query()
            ->forOrganization($organizationId)
            ->with('server')
            ->withCount('profiles')
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get();

        $profiles = DnsNameserverProfile::query()
            ->forOrganization($organizationId)
            ->with([
                'identities' => fn ($query) => $query
                    ->with('server'),
            ])
            ->orderByDesc('is_default')
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get();

        $servers = DnsServer::query()
            ->forOrganization($organizationId)
            ->orderByDesc('enabled')
            ->orderBy('name')
            ->get();

        return view('nameservers.index', [
            'identities' => $identities,
            'profiles' => $profiles,
            'servers' => $servers,
            'canManage' => $this->canManage($request),
        ]);
    }

    public function storeIdentity(
        Request $request,
    ): RedirectResponse {
        $this->ensureCanManage($request);

        $organizationId = $this->organizationId($request);

        $validated = $this->validateIdentity(
            request: $request,
            organizationId: $organizationId,
        );

        DnsNameserverIdentity::query()->create([
            ...$validated,
            'organization_id' => $organizationId,
            'enabled' => true,
        ]);

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'identities',
            ])
            ->with(
                'status',
                'Identidade de nameserver criada com sucesso.',
            );
    }

    public function updateIdentity(
        Request $request,
        DnsNameserverIdentity $identity,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureIdentityAccess($request, $identity);

        $validated = $this->validateIdentity(
            request: $request,
            organizationId: $this->organizationId($request),
            identity: $identity,
        );

        $identity->update($validated);

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'identities',
            ])
            ->with(
                'status',
                'Identidade de nameserver atualizada com sucesso.',
            );
    }

    public function toggleIdentity(
        Request $request,
        DnsNameserverIdentity $identity,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureIdentityAccess($request, $identity);

        $identity->update([
            'enabled' => ! $identity->enabled,
        ]);

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'identities',
            ])
            ->with(
                'status',
                $identity->enabled
                    ? 'Identidade de nameserver ativada.'
                    : 'Identidade de nameserver desativada.',
            );
    }

    public function storeProfile(
        Request $request,
    ): RedirectResponse {
        $this->ensureCanManage($request);

        $organizationId = $this->organizationId($request);

        $validated = $this->validateProfile(
            request: $request,
            organizationId: $organizationId,
        );

        DB::transaction(function () use (
            $organizationId,
            $validated,
        ): void {
            if ($validated['is_default']) {
                DnsNameserverProfile::query()
                    ->forOrganization($organizationId)
                    ->update([
                        'is_default' => false,
                    ]);
            }

            $profile = DnsNameserverProfile::query()->create([
                'organization_id' => $organizationId,
                'name' => $validated['name'],
                'is_default' => $validated['is_default'],
                'enabled' => true,
                'notes' => $validated['notes'],
            ]);

            $profile->identities()->attach(
                $this->identityPivot(
                    $validated['identity_ids'],
                ),
            );
        });

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'profiles',
            ])
            ->with(
                'status',
                'Perfil de nameservers criado com sucesso.',
            );
    }

    public function updateProfile(
        Request $request,
        DnsNameserverProfile $profile,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureProfileAccess($request, $profile);

        $organizationId = $this->organizationId($request);

        $validated = $this->validateProfile(
            request: $request,
            organizationId: $organizationId,
            profile: $profile,
        );

        DB::transaction(function () use (
            $organizationId,
            $profile,
            $validated,
        ): void {
            if ($validated['is_default']) {
                DnsNameserverProfile::query()
                    ->forOrganization($organizationId)
                    ->whereKeyNot($profile->id)
                    ->update([
                        'is_default' => false,
                    ]);
            }

            $profile->update([
                'name' => $validated['name'],
                'is_default' => $validated['is_default'],
                'notes' => $validated['notes'],
            ]);

            $profile->identities()->sync(
                $this->identityPivot(
                    $validated['identity_ids'],
                ),
            );
        });

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'profiles',
            ])
            ->with(
                'status',
                'Perfil de nameservers atualizado com sucesso.',
            );
    }

    public function toggleProfile(
        Request $request,
        DnsNameserverProfile $profile,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureProfileAccess($request, $profile);

        $enable = ! $profile->enabled;

        DB::transaction(function () use (
            $profile,
            $enable,
        ): void {
            $profile->update([
                'enabled' => $enable,
                'is_default' => $enable
                    ? $profile->is_default
                    : false,
            ]);
        });

        return redirect()
            ->route('nameservers.index', [
                'tab' => 'profiles',
            ])
            ->with(
                'status',
                $enable
                    ? 'Perfil de nameservers ativado.'
                    : 'Perfil de nameservers desativado.',
            );
    }

    private function validateIdentity(
        Request $request,
        int $organizationId,
        ?DnsNameserverIdentity $identity = null,
    ): array {
        $hostname = mb_strtolower(
            rtrim(
                trim(
                    (string) $request->input('hostname'),
                ),
                '.',
            ),
        );

        $request->merge([
            'hostname' => $hostname,
            'dns_server_id' => $request->filled('dns_server_id')
                ? $request->integer('dns_server_id')
                : null,
        ]);

        $hostnameRule = Rule::unique(
            'dns_nameserver_identities',
            'hostname',
        )->where(
            fn ($query) => $query->where(
                'organization_id',
                $organizationId,
            ),
        );

        if ($identity) {
            $hostnameRule->ignore($identity->id);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
            ],
            'hostname' => [
                'required',
                'string',
                'max:255',
                'regex:'.self::HOSTNAME_REGEX,
                $hostnameRule,
            ],
            'dns_server_id' => [
                'nullable',
                'integer',
                Rule::exists(
                    'dns_servers',
                    'id',
                )->where(
                    fn ($query) => $query->where(
                        'organization_id',
                        $organizationId,
                    ),
                ),
            ],
            'ipv4_address' => [
                'nullable',
                'ipv4',
            ],
            'ipv6_address' => [
                'nullable',
                'ipv6',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'hostname.regex' =>
                'Informe um hostname DNS completo e válido.',
            'hostname.unique' =>
                'Este hostname já está cadastrado nesta empresa.',
            'dns_server_id.exists' =>
                'O servidor selecionado não pertence à empresa atual.',
            'ipv4_address.ipv4' =>
                'Informe um endereço IPv4 válido.',
            'ipv6_address.ipv6' =>
                'Informe um endereço IPv6 válido.',
        ]);

        return [
            'name' => trim($validated['name']),
            'hostname' => $validated['hostname'],
            'dns_server_id' => $validated['dns_server_id'] ?? null,
            'ipv4_address' => $validated['ipv4_address'] ?? null,
            'ipv6_address' => $validated['ipv6_address'] ?? null,
            'notes' => filled($validated['notes'] ?? null)
                ? trim($validated['notes'])
                : null,
        ];
    }

    private function validateProfile(
        Request $request,
        int $organizationId,
        ?DnsNameserverProfile $profile = null,
    ): array {
        $request->merge([
            'is_default' => $request->boolean('is_default'),
        ]);

        $nameRule = Rule::unique(
            'dns_nameserver_profiles',
            'name',
        )->where(
            fn ($query) => $query->where(
                'organization_id',
                $organizationId,
            ),
        );

        if ($profile) {
            $nameRule->ignore($profile->id);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                $nameRule,
            ],
            'identity_ids' => [
                'required',
                'array',
                'min:2',
                'max:10',
            ],
            'identity_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(
                    'dns_nameserver_identities',
                    'id',
                )->where(
                    fn ($query) => $query->where(
                        'organization_id',
                        $organizationId,
                    ),
                ),
            ],
            'is_default' => [
                'boolean',
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'name.unique' =>
                'Já existe um perfil com este nome.',
            'identity_ids.required' =>
                'Selecione pelo menos dois nameservers.',
            'identity_ids.min' =>
                'Um perfil deve possuir pelo menos dois nameservers.',
            'identity_ids.max' =>
                'Um perfil pode possuir no máximo dez nameservers.',
            'identity_ids.*.distinct' =>
                'O mesmo nameserver não pode ser selecionado duas vezes.',
            'identity_ids.*.exists' =>
                'Um dos nameservers selecionados não pertence à empresa atual.',
        ]);

        $identityIds = array_values(
            array_map(
                'intval',
                $validated['identity_ids'],
            ),
        );

        $enabledCount = DnsNameserverIdentity::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $identityIds)
            ->where('enabled', true)
            ->count();

        if ($enabledCount !== count($identityIds)) {
            throw ValidationException::withMessages([
                'identity_ids' =>
                    'Perfis novos ou alterados só podem usar identities ativas.',
            ]);
        }

        return [
            'name' => trim($validated['name']),
            'identity_ids' => $identityIds,
            'is_default' => (bool) $validated['is_default'],
            'notes' => filled($validated['notes'] ?? null)
                ? trim($validated['notes'])
                : null,
        ];
    }

    private function identityPivot(
        array $identityIds,
    ): array {
        $pivot = [];

        foreach ($identityIds as $index => $identityId) {
            $pivot[$identityId] = [
                'position' => $index + 1,
            ];
        }

        return $pivot;
    }

    private function organizationId(
        Request $request,
    ): int {
        $organizationId =
            $request->user()?->current_organization_id;

        abort_unless(
            $organizationId,
            403,
            'Nenhuma empresa está selecionada.',
        );

        return (int) $organizationId;
    }

    private function canManage(
        Request $request,
    ): bool {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        return $user->is_platform_admin
            || $user->roleForOrganization($organizationId)
                === 'organization_admin';
    }

    private function ensureCanManage(
        Request $request,
    ): void {
        abort_unless(
            $this->canManage($request),
            403,
            'Você não possui permissão para gerenciar nameservers.',
        );
    }

    private function ensureIdentityAccess(
        Request $request,
        DnsNameserverIdentity $identity,
    ): void {
        abort_unless(
            (int) $identity->organization_id
                === $this->organizationId($request),
            404,
        );
    }

    private function ensureProfileAccess(
        Request $request,
        DnsNameserverProfile $profile,
    ): void {
        abort_unless(
            (int) $profile->organization_id
                === $this->organizationId($request),
            404,
        );
    }
}
