<?php

namespace App\Services;

use App\Models\DnsNameserverProfile;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DnsZoneNameserverSynchronizer
{
    public function profileForOrganization(
        int $profileId,
        int $organizationId,
    ): DnsNameserverProfile {
        $profile = DnsNameserverProfile::query()
            ->forOrganization($organizationId)
            ->enabled()
            ->with([
                'identities' => fn ($query) => $query
                    ->where(
                        'dns_nameserver_identities.enabled',
                        true,
                    ),
            ])
            ->find($profileId);

        if ($profile === null) {
            throw ValidationException::withMessages([
                'dns_nameserver_profile_id' => 'Selecione um perfil de nameservers válido e habilitado.',
            ]);
        }

        if ($profile->identities->count() < 2) {
            throw ValidationException::withMessages([
                'dns_nameserver_profile_id' => 'O perfil precisa possuir pelo menos duas identidades de nameserver habilitadas.',
            ]);
        }

        foreach ($profile->identities as $identity) {
            if (
                (int) $identity->organization_id
                !== $organizationId
            ) {
                throw ValidationException::withMessages([
                    'dns_nameserver_profile_id' => 'O perfil contém uma identidade de outra empresa.',
                ]);
            }
        }

        return $profile;
    }

    public function synchronize(
        DnsZone $zone,
        DnsNameserverProfile $profile,
    ): void {
        $identities = $profile->identities
            ->sortBy(
                fn ($identity): int => (int) $identity->pivot->position,
            )
            ->values();

        $firstIdentity = $identities->first();

        if ($firstIdentity === null) {
            throw ValidationException::withMessages([
                'dns_nameserver_profile_id' => 'O perfil não possui identidades disponíveis.',
            ]);
        }

        $zone->forceFill([
            'dns_nameserver_profile_id' => $profile->id,
            'soa_mname' => $this->domain(
                $firstIdentity->hostname,
            ),
        ])->save();

        $this->synchronizeApexNameservers(
            $zone,
            $identities,
        );

        $this->synchronizeGlue(
            $zone,
            $identities,
        );
    }

    private function synchronizeApexNameservers(
        DnsZone $zone,
        Collection $identities,
    ): void {
        /*
         * Remove apenas NS do apex.
         * Delegações NS de subdomínios permanecem intactas.
         */
        $zone->records()
            ->where('type', 'NS')
            ->where(function ($query) use ($zone): void {
                $query
                    ->where('name', '@')
                    ->orWhere('name', $zone->name);
            })
            ->delete();

        foreach ($identities as $identity) {
            $zone->records()->create([
                'organization_id' => $zone->organization_id,
                'name' => $zone->name,
                'type' => 'NS',
                'ttl' => null,
                'priority' => null,
                'content' => $this->domain(
                    $identity->hostname,
                ),
                'enabled' => true,
            ]);
        }
    }

    private function synchronizeGlue(
        DnsZone $zone,
        Collection $identities,
    ): void {
        $zoneName = $this->domain($zone->name);

        foreach ($identities as $identity) {
            $hostname = $this->domain(
                $identity->hostname,
            );

            if (! $this->isInsideZone($hostname, $zoneName)) {
                continue;
            }

            $this->synchronizeGlueAddress(
                $zone,
                $hostname,
                'A',
                $identity->ipv4_address,
            );

            $this->synchronizeGlueAddress(
                $zone,
                $hostname,
                'AAAA',
                $identity->ipv6_address,
            );
        }
    }

    private function synchronizeGlueAddress(
        DnsZone $zone,
        string $hostname,
        string $type,
        ?string $address,
    ): void {
        $records = $zone->records()
            ->where('name', $hostname)
            ->where('type', $type)
            ->orderBy('id')
            ->get();

        if (blank($address)) {
            /*
             * Não removemos registros existentes quando a identidade
             * não informa um endereço. Isso evita apagar deliberadamente
             * um registro administrado manualmente.
             */
            return;
        }

        $record = $records->first();

        if ($record === null) {
            $zone->records()->create([
                'organization_id' => $zone->organization_id,
                'name' => $hostname,
                'type' => $type,
                'ttl' => null,
                'priority' => null,
                'content' => trim($address),
                'enabled' => true,
            ]);

            return;
        }

        $record->forceFill([
            'content' => trim($address),
            'enabled' => true,
        ])->save();

        $duplicateIds = $records
            ->skip(1)
            ->pluck('id');

        if ($duplicateIds->isNotEmpty()) {
            DnsRecord::query()
                ->whereIn('id', $duplicateIds)
                ->delete();
        }
    }

    private function isInsideZone(
        string $hostname,
        string $zone,
    ): bool {
        return $hostname === $zone
            || str_ends_with(
                $hostname,
                '.'.$zone,
            );
    }

    private function domain(string $value): string
    {
        return strtolower(
            rtrim(
                trim($value),
                '.',
            ),
        );
    }
}
