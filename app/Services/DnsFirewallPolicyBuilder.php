<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class DnsFirewallPolicyBuilder
{
    public function build(string $aclText, string $adminPortText, string $publicPortText): array
    {
        $acls = $this->lines($aclText);
        if ($acls === []) {
            throw ValidationException::withMessages(['administrative_acls' => 'Informe pelo menos uma ACL administrativa.']);
        }

        foreach ($acls as $acl) {
            if (! filter_var(explode('/', $acl, 2)[0], FILTER_VALIDATE_IP) || ! str_contains($acl, '/')) {
                throw ValidationException::withMessages(['administrative_acls' => "ACL inválida: {$acl}"]);
            }
            [$address, $prefix] = explode('/', $acl, 2);
            $max = str_contains($address, ':') ? 128 : 32;
            if (! ctype_digit($prefix) || (int) $prefix < 1 || (int) $prefix > $max) {
                throw ValidationException::withMessages(['administrative_acls' => "Prefixo inválido ou aberto demais: {$acl}"]);
            }
        }

        $contract = [
            'version' => 1,
            'table' => 'inet dns_center',
            'administrative_acls' => $acls,
            'administrative_ports' => $this->ports($adminPortText, 'administrative_ports'),
            'public_ports' => $this->ports($publicPortText, 'public_ports'),
            'invariants' => ['dns_tcp_udp_53' => true, 'outbound_unchanged' => true],
        ];
        $contract['desired_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $contract;
    }

    private function lines(string $value): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $value) ?: []))));
        sort($values, SORT_STRING);

        return $values;
    }

    private function ports(string $value, string $field): array
    {
        $ports = [];
        foreach ($this->lines($value) as $entry) {
            if (! preg_match('/\A(tcp|udp)\/(\d{1,5})\z/', strtolower($entry), $match) || (int) $match[2] < 1 || (int) $match[2] > 65535) {
                throw ValidationException::withMessages([$field => "Porta inválida: {$entry}. Use tcp/22 ou udp/53."]);
            }
            $ports[] = strtolower($match[1]).'/'.(int) $match[2];
        }
        sort($ports, SORT_STRING);

        return array_values(array_unique($ports));
    }
}
