<?php

namespace App\Services;

use App\Models\DnsRecord;
use App\Models\DnsZone;

class DnsZoneValidator
{
    /**
     * Valida o estado desejado de uma zona antes de uma futura publicação.
     *
     * @return array{
     *     ok: bool,
     *     errors: array<int, string>,
     *     warnings: array<int, string>,
     *     summary: array{
     *         records: int,
     *         nameservers: int,
     *         primary_servers: int,
     *         secondary_servers: int
     *     }
     * }
     */
    public function validate(DnsZone $zone): array
    {
        $zone->loadMissing([
            "records",
            "servers",
        ]);

        $records = $zone->records
            ->where("enabled", true)
            ->values();

        $errors = [];
        $warnings = [];

        $primaryCount = $zone->servers
            ->where("pivot.role", "primary")
            ->count();

        $secondaryCount = $zone->servers
            ->where("pivot.role", "secondary")
            ->count();

        $nsRecords = $records
            ->where("type", "NS")
            ->values();

        if ($primaryCount !== 1) {
            $errors[] = "A zona deve possuir exatamente um servidor primary.";
        }

        if ($secondaryCount < 1) {
            $warnings[] = "Nenhum servidor secondary foi associado à zona.";
        }

        if ($nsRecords->count() < 2) {
            $errors[] = "Inclua pelo menos dois registros NS no apex da zona.";
        }

        foreach ($nsRecords as $record) {
            if ($this->owner($record, $zone) !== "@") {
                $warnings[] = sprintf(
                    "O registro NS %s não está no apex da zona.",
                    $record->content,
                );
            }

            $target = $this->domain($record->content);

            if (! $this->isInsideZone($target, $zone->name)) {
                continue;
            }

            $hasGlue = $records->contains(
                function (DnsRecord $candidate) use ($target, $zone): bool {
                    return in_array($candidate->type, ["A", "AAAA"], true)
                        && $this->absoluteOwner($candidate, $zone) === $target;
                },
            );

            if (! $hasGlue) {
                $errors[] = sprintf(
                    "O nameserver %s pertence a este domínio e precisa de pelo menos um registro A ou AAAA com o endereço público usado por ele. Os demais registros do domínio podem apontar normalmente para qualquer rede.",
                    $target,
                );
            }
        }

        $cnames = $records
            ->where("type", "CNAME")
            ->groupBy(
                fn (DnsRecord $record): string => $this->absoluteOwner($record, $zone),
            );

        foreach ($cnames as $owner => $items) {
            if ($items->count() > 1) {
                $errors[] = sprintf(
                    "O nome %s possui mais de um registro CNAME.",
                    $owner,
                );
            }

            $hasConflictingRecord = $records->contains(
                fn (DnsRecord $record): bool =>
                    $this->absoluteOwner($record, $zone) === $owner
                    && $record->type !== "CNAME",
            );

            if ($hasConflictingRecord) {
                $errors[] = sprintf(
                    "O nome %s possui CNAME junto com outro tipo de registro.",
                    $owner,
                );
            }
        }

        if ($zone->soa_retry >= $zone->soa_refresh) {
            $warnings[] = "O SOA retry normalmente deve ser menor que o refresh.";
        }

        if ($zone->soa_expire <= $zone->soa_refresh) {
            $errors[] = "O SOA expire deve ser maior que o refresh.";
        }

        return [
            "ok" => $errors === [],
            "errors" => array_values(array_unique($errors)),
            "warnings" => array_values(array_unique($warnings)),
            "summary" => [
                "records" => $records->count(),
                "nameservers" => $nsRecords->count(),
                "primary_servers" => $primaryCount,
                "secondary_servers" => $secondaryCount,
            ],
        ];
    }

    private function owner(DnsRecord $record, DnsZone $zone): string
    {
        return $this->absoluteOwner($record, $zone) === $this->domain($zone->name)
            ? "@"
            : $record->name;
    }

    private function absoluteOwner(DnsRecord $record, DnsZone $zone): string
    {
        $name = $this->domain($record->name);
        $zoneName = $this->domain($zone->name);

        if ($name === "@" || $name === $zoneName) {
            return $zoneName;
        }

        if ($this->isInsideZone($name, $zoneName)) {
            return $name;
        }

        return $name.".".$zoneName;
    }

    private function isInsideZone(string $name, string $zone): bool
    {
        $name = $this->domain($name);
        $zone = $this->domain($zone);

        return $name === $zone
            || str_ends_with($name, ".".$zone);
    }

    private function domain(string $value): string
    {
        return strtolower(rtrim(trim($value), "."));
    }
}
