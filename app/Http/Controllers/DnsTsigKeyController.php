<?php

namespace App\Http\Controllers;

use App\Models\DnsTsigKey;
use App\Models\DnsZone;
use App\Support\SecurityAuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DnsTsigKeyController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $organizationId = $this->authorizeAdmin($request);
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-zA-Z0-9._-]+\z/',
                Rule::unique('dns_tsig_keys', 'name')
                    ->where('organization_id', $organizationId),
            ],
            'algorithm' => ['required', Rule::in(DnsTsigKey::ALGORITHMS)],
        ]);

        $key = DnsTsigKey::query()->create([
            'organization_id' => $organizationId,
            'name' => strtolower($validated['name']),
            'algorithm' => $validated['algorithm'],
            'secret' => base64_encode(random_bytes(32)),
            'enabled' => true,
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, 'dns.tsig.created', $key);

        return back()->with('status', 'Chave TSIG criada e armazenada cifrada.');
    }

    public function rotate(Request $request, DnsTsigKey $tsigKey): RedirectResponse
    {
        $this->authorizeKey($request, $tsigKey);

        $replacement = DB::transaction(function () use ($request, $tsigKey): DnsTsigKey {
            $suffix = now()->format('YmdHis');
            $name = $tsigKey->name.'-r'.$suffix;
            $counter = 1;

            while (
                DnsTsigKey::query()
                    ->where('organization_id', $tsigKey->organization_id)
                    ->where('name', $name)
                    ->exists()
            ) {
                $name = $tsigKey->name.'-r'.$suffix.'-'.$counter++;
            }

            $replacement = DnsTsigKey::query()->create([
                'organization_id' => $tsigKey->organization_id,
                'name' => $name,
                'algorithm' => $tsigKey->algorithm,
                'secret' => base64_encode(random_bytes(32)),
                'enabled' => true,
                'created_by' => $request->user()->id,
                'rotated_by' => $request->user()->id,
                'rotated_at' => now(),
            ]);

            $tsigKey->zones()->update([
                'dns_tsig_key_id' => $replacement->id,
                'status' => DB::raw(
                    "CASE WHEN status = 'published' THEN 'ready' ELSE status END",
                ),
            ]);

            return $replacement;
        });

        $this->audit($request, 'dns.tsig.rotated', $replacement);

        return back()->with(
            'status',
            'Nova geração TSIG criada e associada. A chave anterior foi mantida para rollback; republique as zonas antes de desativá-la.',
        );
    }

    public function disable(Request $request, DnsTsigKey $tsigKey): RedirectResponse
    {
        $this->authorizeKey($request, $tsigKey);

        abort_if($tsigKey->zones()->where('enabled', true)->exists(), 422);

        $tsigKey->forceFill([
            'enabled' => false,
            'disabled_at' => now(),
        ])->save();

        $this->audit($request, 'dns.tsig.disabled', $tsigKey);

        return back()->with('status', 'Chave TSIG desativada.');
    }

    public function associate(
        Request $request,
        DnsZone $zone,
    ): RedirectResponse {
        $organizationId = $this->authorizeAdmin($request);
        abort_unless((int) $zone->organization_id === $organizationId, 404);

        $validated = $request->validate([
            'dns_tsig_key_id' => [
                'required',
                'integer',
                Rule::exists('dns_tsig_keys', 'id')
                    ->where('organization_id', $organizationId)
                    ->where('enabled', true),
            ],
        ]);

        DB::transaction(function () use ($zone, $validated): void {
            $zone->lockForUpdate();
            $zone->forceFill([
                'dns_tsig_key_id' => (int) $validated['dns_tsig_key_id'],
                'status' => $zone->status === 'published' ? 'ready' : $zone->status,
            ])->save();
        });

        $key = DnsTsigKey::query()->findOrFail($validated['dns_tsig_key_id']);
        $this->audit($request, 'dns.tsig.associated', $key);

        return back()->with('status', 'Chave TSIG associada. Republique a zona.');
    }

    private function authorizeKey(Request $request, DnsTsigKey $key): int
    {
        $organizationId = $this->authorizeAdmin($request);
        abort_unless((int) $key->organization_id === $organizationId, 404);

        return $organizationId;
    }

    private function authorizeAdmin(Request $request): int
    {
        $organizationId = (int) $request->user()->current_organization_id;
        $role = $request->user()->roleForOrganization($organizationId);
        abort_unless(
            $organizationId > 0
            && ($request->user()->is_platform_admin || $role === 'organization_admin'),
            403,
        );

        return $organizationId;
    }

    private function audit(Request $request, string $event, DnsTsigKey $key): void
    {
        SecurityAuditLogger::record(
            event: $event,
            user: $request->user(),
            result: 'success',
            actor: 'user:'.$request->user()->id,
            source: 'web',
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            organizationId: (int) $key->organization_id,
            reason: 'tsig_key:'.$key->id,
        );
    }
}
