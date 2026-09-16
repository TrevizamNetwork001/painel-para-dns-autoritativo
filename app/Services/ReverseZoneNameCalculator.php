<?php

namespace App\Services;

use InvalidArgumentException;

class ReverseZoneNameCalculator
{
    public function fromIpv4Cidr(string $cidr): string
    {
        if (! str_contains($cidr, '/')) {
            throw new InvalidArgumentException(
                'Informe o bloco no formato CIDR, ex.: 192.0.2.0/24.',
            );
        }

        [$address, $prefix] = explode('/', $cidr, 2);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new InvalidArgumentException(
                'Endereço IPv4 inválido.',
            );
        }

        if (! ctype_digit($prefix) || (int) $prefix < 0 || (int) $prefix > 32) {
            throw new InvalidArgumentException(
                'Prefixo IPv4 inválido.',
            );
        }

        $prefix = (int) $prefix;

        if ($prefix % 8 !== 0 || $prefix < 8) {
            throw new InvalidArgumentException(
                'Este assistente só aceita blocos alinhados em octeto (/8, /16 ou /24).',
            );
        }

        $octets = array_map('intval', explode('.', $address));
        $labelCount = intdiv($prefix, 8);

        for ($i = $labelCount; $i < 4; $i++) {
            if ($octets[$i] !== 0) {
                throw new InvalidArgumentException(
                    'O endereço informado não é o endereço de rede do bloco (os octetos fora da máscara devem ser 0).',
                );
            }
        }

        $labels = array_reverse(array_slice($octets, 0, $labelCount));

        return implode('.', $labels).'.in-addr.arpa';
    }

    public function fromIpv6Prefix(string $prefix): string
    {
        if (! str_contains($prefix, '/')) {
            throw new InvalidArgumentException(
                'Informe o bloco no formato CIDR, ex.: 2001:db8::/48.',
            );
        }

        [$address, $bits] = explode('/', $prefix, 2);

        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new InvalidArgumentException(
                'Endereço IPv6 inválido.',
            );
        }

        if (! ctype_digit($bits) || (int) $bits < 0 || (int) $bits > 128) {
            throw new InvalidArgumentException(
                'Prefixo IPv6 inválido.',
            );
        }

        $bits = (int) $bits;

        if ($bits % 4 !== 0 || $bits < 4) {
            throw new InvalidArgumentException(
                'O prefixo IPv6 precisa ser múltiplo de 4 bits (alinhado a nibble).',
            );
        }

        $binary = inet_pton($address);

        if ($binary === false || strlen($binary) !== 16) {
            throw new InvalidArgumentException(
                'Endereço IPv6 inválido.',
            );
        }

        $hex = bin2hex($binary);
        $nibbleCount = intdiv($bits, 4);

        for ($i = $nibbleCount; $i < 32; $i++) {
            if ($hex[$i] !== '0') {
                throw new InvalidArgumentException(
                    'O endereço informado não é o endereço de rede do bloco (os nibbles fora do prefixo devem ser 0).',
                );
            }
        }

        $nibbles = array_reverse(str_split(substr($hex, 0, $nibbleCount)));

        return implode('.', $nibbles).'.ip6.arpa';
    }

    public function toIpv4Cidr(string $zoneName): ?string
    {
        $name = strtolower(rtrim(trim($zoneName), '.'));

        if (! str_ends_with($name, '.in-addr.arpa')) {
            return null;
        }

        $labels = explode('.', substr($name, 0, -strlen('.in-addr.arpa')));
        $labelCount = count($labels);

        if ($labelCount < 1 || $labelCount > 4) {
            return null;
        }

        foreach ($labels as $label) {
            if (! ctype_digit($label) || (int) $label > 255) {
                return null;
            }
        }

        $octets = array_reverse(array_map('intval', $labels));

        while (count($octets) < 4) {
            $octets[] = 0;
        }

        return implode('.', $octets).'/'.($labelCount * 8);
    }

    public function toIpv6Prefix(string $zoneName): ?string
    {
        $name = strtolower(rtrim(trim($zoneName), '.'));

        if (! str_ends_with($name, '.ip6.arpa')) {
            return null;
        }

        $nibbles = explode('.', substr($name, 0, -strlen('.ip6.arpa')));
        $nibbleCount = count($nibbles);

        if ($nibbleCount < 1 || $nibbleCount > 32) {
            return null;
        }

        foreach ($nibbles as $nibble) {
            if (! ctype_xdigit($nibble) || strlen($nibble) !== 1) {
                return null;
            }
        }

        $hex = implode('', array_reverse($nibbles));
        $hex = str_pad($hex, 32, '0');

        $address = implode(':', str_split($hex, 4));
        $address = inet_ntop(inet_pton($address));

        return $address.'/'.($nibbleCount * 4);
    }

    public function ipv4ToPtrName(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $octets = explode('.', $ip);

        return implode('.', array_reverse($octets)).'.in-addr.arpa';
    }

    public function ipv6ToPtrName(string $ip): ?string
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }

        $binary = @inet_pton($ip);

        if ($binary === false || strlen($binary) !== 16) {
            return null;
        }

        $nibbles = array_reverse(str_split(bin2hex($binary)));

        return implode('.', $nibbles).'.ip6.arpa';
    }

    public function ptrNameToIpv4(string $recordName): ?string
    {
        $name = strtolower(rtrim(trim($recordName), '.'));

        if (! str_ends_with($name, '.in-addr.arpa')) {
            return null;
        }

        $labels = explode('.', substr($name, 0, -strlen('.in-addr.arpa')));

        if (count($labels) !== 4) {
            return null;
        }

        foreach ($labels as $label) {
            if (! ctype_digit($label) || (int) $label > 255) {
                return null;
            }
        }

        return implode('.', array_reverse($labels));
    }

    public function ptrNameToIpv6(string $recordName): ?string
    {
        $name = strtolower(rtrim(trim($recordName), '.'));

        if (! str_ends_with($name, '.ip6.arpa')) {
            return null;
        }

        $nibbles = explode('.', substr($name, 0, -strlen('.ip6.arpa')));

        if (count($nibbles) !== 32) {
            return null;
        }

        foreach ($nibbles as $nibble) {
            if (! ctype_xdigit($nibble) || strlen($nibble) !== 1) {
                return null;
            }
        }

        $hex = implode('', array_reverse($nibbles));
        $address = implode(':', str_split($hex, 4));

        $binary = @inet_pton($address);

        return $binary === false ? null : inet_ntop($binary);
    }
}
