<?php

namespace App\Http\Controllers;

use App\Models\DnsRecord;
use App\Models\DnsServer;
use App\Models\DnsZone;
use App\Models\DnsZoneVersion;
use App\Services\BindZoneRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DnsZoneController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = $this->organizationId($request);

        return view('zones.index', [
            'zones' => DnsZone::query()
                ->forOrganization($organizationId)
                ->withCount('records')
                ->orderBy('name')
                ->get(),
            'servers' => DnsServer::query()
                ->forOrganization($organizationId)
                ->enabled()
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, BindZoneRenderer $renderer): RedirectResponse
    {
        $organizationId = $this->authorizeWrite($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\.?$/i',
                Rule::unique('dns_zones', 'name')->where('organization_id', $organizationId),
            ],
            'kind' => ['required', Rule::in(DnsZone::KINDS)],
            'default_ttl' => ['required', 'integer', 'min:60', 'max:2147483647'],
            'soa_mname' => ['required', 'string', 'max:255'],
            'soa_rname' => ['required', 'string', 'max:255'],
            'soa_refresh' => ['required', 'integer', 'min:60', 'max:2147483647'],
            'soa_retry' => ['required', 'integer', 'min:60', 'max:2147483647'],
            'soa_expire' => ['required', 'integer', 'min:3600', 'max:2147483647'],
            'soa_minimum' => ['required', 'integer', 'min:60', 'max:2147483647'],
            'primary_server_id' => [
                'required',
                'integer',
                Rule::exists('dns_servers', 'id')->where('organization_id', $organizationId),
            ],
            'secondary_server_id' => [
                'nullable',
                'integer',
                'different:primary_server_id',
                Rule::exists('dns_servers', 'id')->where('organization_id', $organizationId),
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $zone = DB::transaction(function () use ($request, $validated, $organizationId, $renderer): DnsZone {
            $zone = DnsZone::query()->create([
                'organization_id' => $organizationId,
                'name' => $this->domain($validated['name']),
                'kind' => $validated['kind'],
                'serial' => $this->nextSerial(),
                'default_ttl' => $validated['default_ttl'],
                'soa_mname' => $this->domain($validated['soa_mname']),
                'soa_rname' => $this->domain($validated['soa_rname']),
                'soa_refresh' => $validated['soa_refresh'],
                'soa_retry' => $validated['soa_retry'],
                'soa_expire' => $validated['soa_expire'],
                'soa_minimum' => $validated['soa_minimum'],
                'status' => 'draft',
                'version' => 1,
                'enabled' => true,
                'notes' => $validated['notes'] ?? null,
            ]);

            $sync = [(int) $validated['primary_server_id'] => ['role' => 'primary']];

            if (! empty($validated['secondary_server_id'])) {
                $sync[(int) $validated['secondary_server_id']] = ['role' => 'secondary'];
            }

            $zone->servers()->sync($sync);
            $this->saveVersion($zone, $request, 'Zona criada.', $renderer);

            return $zone;
        });

        return redirect()->route('zones.show', $zone)->with('status', 'Zona autoritativa criada.');
    }

    public function show(Request $request, DnsZone $zone, BindZoneRenderer $renderer): View
    {
        $this->authorizeZone($request, $zone);

        $zone->load([
            'records' => fn ($query) => $query->orderBy('type')->orderBy('name'),
            'servers',
            'versions' => fn ($query) => $query->latest('version')->limit(10),
        ]);

        return view('zones.show', [
            'zone' => $zone,
            'preview' => $renderer->render($zone),
        ]);
    }

    public function storeRecord(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
    ): RedirectResponse {
        $organizationId = $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(DnsRecord::TYPES)],
            'ttl' => ['nullable', 'integer', 'min:60', 'max:2147483647'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'content' => ['required', 'string', 'max:4096'],
        ]);

        $this->validateRecord($validated);

        DB::transaction(function () use ($request, $zone, $validated, $organizationId, $renderer): void {
            $zone->records()->create([
                'organization_id' => $organizationId,
                'name' => $validated['name'] === '@'
                    ? $zone->name
                    : strtolower(rtrim(trim($validated['name']), '.')),
                'type' => $validated['type'],
                'ttl' => $validated['ttl'] ?? null,
                'priority' => $validated['type'] === 'MX'
                    ? ($validated['priority'] ?? 10)
                    : null,
                'content' => trim($validated['content']),
                'enabled' => true,
            ]);

            $this->bump($zone, $request, 'Registro adicionado.', $renderer);
        });

        return back()->with('status', 'Registro DNS adicionado.');
    }

    public function destroyRecord(
        Request $request,
        DnsZone $zone,
        DnsRecord $record,
        BindZoneRenderer $renderer,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_unless(
            (int) $record->dns_zone_id === (int) $zone->id
            && (int) $record->organization_id === (int) $zone->organization_id,
            404,
        );

        DB::transaction(function () use ($request, $zone, $record, $renderer): void {
            $record->delete();
            $this->bump($zone, $request, 'Registro removido.', $renderer);
        });

        return back()->with('status', 'Registro DNS removido.');
    }

    public function publish(
        Request $request,
        DnsZone $zone,
        BindZoneRenderer $renderer,
    ): RedirectResponse {
        $this->authorizeWrite($request);
        $this->authorizeZone($request, $zone);

        abort_if(
            $zone->records()->where('enabled', true)->where('type', 'NS')->count() < 2,
            422,
            'Inclua pelo menos dois registros NS antes de publicar.',
        );

        DB::transaction(function () use ($request, $zone, $renderer): void {
            $zone->forceFill([
                'status' => 'published',
                'serial' => $this->nextSerial($zone->serial),
                'version' => $zone->version + 1,
            ])->save();

            $this->saveVersion($zone, $request, 'Zona publicada.', $renderer);
        });

        return back()->with('status', 'Zona publicada para distribuição.');
    }

    private function validateRecord(array $record): void
    {
        $type = $record['type'];
        $content = trim($record['content']);

        if ($type === 'A' && ! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw ValidationException::withMessages(['content' => 'Informe um IPv4 válido.']);
        }

        if ($type === 'AAAA' && ! filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw ValidationException::withMessages(['content' => 'Informe um IPv6 válido.']);
        }

        if (
            in_array($type, ['CNAME', 'MX', 'NS', 'PTR'], true)
            && ! preg_match('/^(?=.{1,253}\.?$)(?:[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?\.)+[a-z0-9_-]{1,63}\.?$/i', $content)
        ) {
            throw ValidationException::withMessages(['content' => 'Informe um hostname válido.']);
        }

        if ($type === 'CAA' && ! preg_match('/^\d+\s+[a-z0-9-]+\s+.+$/i', $content)) {
            throw ValidationException::withMessages(['content' => 'Use: 0 issue letsencrypt.org.']);
        }
    }

    private function bump(
        DnsZone $zone,
        Request $request,
        string $reason,
        BindZoneRenderer $renderer,
    ): void {
        $zone->forceFill([
            'serial' => $this->nextSerial($zone->serial),
            'version' => $zone->version + 1,
            'status' => $zone->status === 'published' ? 'ready' : $zone->status,
        ])->save();

        $this->saveVersion($zone, $request, $reason, $renderer);
    }

    private function saveVersion(
        DnsZone $zone,
        Request $request,
        string $reason,
        BindZoneRenderer $renderer,
    ): void {
        $zone->refresh()->load(['records', 'servers']);

        DnsZoneVersion::query()->create([
            'organization_id' => $zone->organization_id,
            'dns_zone_id' => $zone->id,
            'created_by' => $request->user()->id,
            'version' => $zone->version,
            'serial' => $zone->serial,
            'reason' => $reason,
            'snapshot' => $renderer->snapshot($zone),
        ]);
    }

    private function nextSerial(?int $current = null): int
    {
        return max((int) now()->format('Ymd').'00', ($current ?? 0) + 1);
    }

    private function domain(string $value): string
    {
        return strtolower(rtrim(trim($value), '.'));
    }

    private function authorizeZone(Request $request, DnsZone $zone): void
    {
        abort_unless(
            (int) $zone->organization_id === $this->organizationId($request),
            404,
        );
    }

    private function authorizeWrite(Request $request): int
    {
        $organizationId = $this->organizationId($request);
        $user = $request->user();
        $role = $user->roleForOrganization($organizationId);

        abort_unless(
            $user->is_platform_admin || $role === 'organization_admin',
            403,
        );

        return $organizationId;
    }

    private function organizationId(Request $request): int
    {
        $organizationId = (int) $request->user()->current_organization_id;
        abort_unless($organizationId > 0, 403);

        return $organizationId;
    }
}
