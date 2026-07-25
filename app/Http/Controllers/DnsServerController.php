<?php

namespace App\Http\Controllers;

use App\Models\DnsServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DnsServerController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        $servers = DnsServer::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get();

        return view('servers.index', [
            'servers' => $servers,
            'roles' => DnsServer::ROLES,
            'environments' => DnsServer::ENVIRONMENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureCanManage($request);

        $organizationId = $this->organizationId($request);

        $validated = $this->validateServer(
            request: $request,
            organizationId: $organizationId,
        );

        DnsServer::query()->create([
            ...$validated,
            'organization_id' => $organizationId,
            'status' => 'pending',
            'enabled' => true,
        ]);

        return redirect()
            ->route('servers.index')
            ->with('status', 'Servidor DNS criado com sucesso.');
    }

    public function update(
        Request $request,
        DnsServer $server,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureServerAccess($request, $server);

        $organizationId = $this->organizationId($request);

        $validated = $this->validateServer(
            request: $request,
            organizationId: $organizationId,
            server: $server,
        );

        $server->update($validated);

        return redirect()
            ->route('servers.index')
            ->with('status', 'Servidor DNS atualizado com sucesso.');
    }

    public function toggleStatus(
        Request $request,
        DnsServer $server,
    ): RedirectResponse {
        $this->ensureCanManage($request);
        $this->ensureServerAccess($request, $server);

        $enableServer = ! $server->enabled;

        $server->update([
            'enabled' => $enableServer,
            'status' => $enableServer
                ? 'pending'
                : 'maintenance',
        ]);

        return redirect()
            ->route('servers.index')
            ->with(
                'status',
                $enableServer
                    ? 'Servidor DNS ativado com sucesso.'
                    : 'Servidor DNS desativado com sucesso.',
            );
    }

    private function validateServer(
        Request $request,
        int $organizationId,
        ?DnsServer $server = null,
    ): array {
        $hostnameRule = Rule::unique(
            'dns_servers',
            'hostname',
        )->where(
            fn ($query) => $query->where(
                'organization_id',
                $organizationId,
            )
        );

        if ($server) {
            $hostnameRule->ignore($server->id);
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
                'regex:/^(?=.{1,253}$)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/',
                $hostnameRule,
            ],
            'ipv4_address' => [
                'nullable',
                'ipv4',
                'required_without:ipv6_address',
            ],
            'ipv6_address' => [
                'nullable',
                'ipv6',
                'required_without:ipv4_address',
            ],
            'role' => [
                'required',
                Rule::in(DnsServer::ROLES),
            ],
            'environment' => [
                'required',
                Rule::in(DnsServer::ENVIRONMENTS),
            ],
            'notes' => [
                'nullable',
                'string',
                'max:2000',
            ],
        ], [
            'hostname.regex' =>
                'Informe um hostname DNS válido.',
            'hostname.unique' =>
                'Este hostname já está cadastrado nesta empresa.',
            'ipv4_address.ipv4' =>
                'Informe um endereço IPv4 válido.',
            'ipv6_address.ipv6' =>
                'Informe um endereço IPv6 válido.',
            'ipv4_address.required_without' =>
                'Informe ao menos um endereço IPv4 ou IPv6.',
            'ipv6_address.required_without' =>
                'Informe ao menos um endereço IPv4 ou IPv6.',
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['hostname'] = mb_strtolower(
            rtrim(trim($validated['hostname']), '.')
        );

        $validated['ipv4_address'] =
            $validated['ipv4_address'] ?: null;

        $validated['ipv6_address'] =
            $validated['ipv6_address'] ?: null;

        $validated['notes'] =
            isset($validated['notes'])
                ? trim($validated['notes'])
                : null;

        return $validated;
    }

    private function organizationId(Request $request): int
    {
        $organizationId = $request->user()?->current_organization_id;

        abort_unless(
            $organizationId,
            403,
            'Nenhuma empresa está selecionada.',
        );

        return (int) $organizationId;
    }

    private function ensureCanManage(Request $request): void
    {
        $user = $request->user();
        $organizationId = $this->organizationId($request);

        $allowed = $user->is_platform_admin
            || $user->roleForOrganization($organizationId)
                === 'organization_admin';

        abort_unless(
            $allowed,
            403,
            'Você não possui permissão para gerenciar servidores.',
        );
    }

    private function ensureServerAccess(
        Request $request,
        DnsServer $server,
    ): void {
        abort_unless(
            $server->organization_id
                === $this->organizationId($request),
            404,
        );
    }
}
