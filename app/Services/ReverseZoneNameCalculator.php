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
}
