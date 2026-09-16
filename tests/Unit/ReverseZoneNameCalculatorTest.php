<?php

namespace Tests\Unit;

use App\Services\ReverseZoneNameCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReverseZoneNameCalculatorTest extends TestCase
{
    private ReverseZoneNameCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new ReverseZoneNameCalculator;
    }

    public function test_ipv4_slash_24_computes_reversed_octets(): void
    {
        $this->assertSame(
            '2.0.192.in-addr.arpa',
            $this->calculator->fromIpv4Cidr('192.0.2.0/24'),
        );
    }

    public function test_ipv4_slash_16_computes_two_reversed_octets(): void
    {
        $this->assertSame(
            '162.196.in-addr.arpa',
            $this->calculator->fromIpv4Cidr('198.18.0.0/16'),
        );
    }

    public function test_ipv4_slash_8_computes_one_octet(): void
    {
        $this->assertSame(
            '196.in-addr.arpa',
            $this->calculator->fromIpv4Cidr('10.0.0.0/8'),
        );
    }

    public function test_ipv4_rejects_non_octet_aligned_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('octeto');

        $this->calculator->fromIpv4Cidr('192.0.2.0/25');
    }

    public function test_ipv4_rejects_address_that_is_not_the_network_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->fromIpv4Cidr('192.0.2.5/24');
    }

    public function test_ipv4_rejects_missing_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->fromIpv4Cidr('192.0.2.0');
    }

    public function test_ipv6_slash_32_matches_real_conecta_zone(): void
    {
        $this->assertSame(
            '8.b.d.0.1.0.0.2.ip6.arpa',
            $this->calculator->fromIpv6Prefix('2001:db8::/32'),
        );
    }

    public function test_ipv6_rejects_prefix_not_multiple_of_four(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nibble');

        $this->calculator->fromIpv6Prefix('2001:db8::/30');
    }

    public function test_ipv6_rejects_address_that_is_not_the_network_address(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->fromIpv6Prefix('2001:db8::1/32');
    }

    public function test_to_ipv4_cidr_reverses_the_calculation(): void
    {
        $this->assertSame(
            '192.0.2.0/24',
            $this->calculator->toIpv4Cidr('2.0.192.in-addr.arpa'),
        );
    }

    public function test_to_ipv4_cidr_returns_null_for_non_reverse_zone(): void
    {
        $this->assertNull($this->calculator->toIpv4Cidr('example.com'));
    }

    public function test_to_ipv6_prefix_reverses_the_calculation(): void
    {
        $this->assertSame(
            '2001:db8:1::/48',
            $this->calculator->toIpv6Prefix('1.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa'),
        );
    }

    public function test_to_ipv6_prefix_matches_real_conecta_zone(): void
    {
        $this->assertSame(
            '2001:db8::/32',
            $this->calculator->toIpv6Prefix('8.b.d.0.1.0.0.2.ip6.arpa'),
        );
    }

    public function test_to_ipv6_prefix_returns_null_for_non_reverse_zone(): void
    {
        $this->assertNull($this->calculator->toIpv6Prefix('example.com'));
    }

    public function test_ptr_name_to_ipv4_computes_the_address(): void
    {
        $this->assertSame(
            '192.0.2.10',
            $this->calculator->ptrNameToIpv4('10.2.0.192.in-addr.arpa'),
        );
    }

    public function test_ptr_name_to_ipv4_returns_null_for_partial_name(): void
    {
        $this->assertNull($this->calculator->ptrNameToIpv4('2.0.192.in-addr.arpa'));
    }

    public function test_ptr_name_to_ipv6_computes_the_address(): void
    {
        $this->assertSame(
            '2001:db8::242',
            $this->calculator->ptrNameToIpv6(
                '2.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            ),
        );
    }

    public function test_ptr_name_to_ipv6_returns_null_for_partial_name(): void
    {
        $this->assertNull(
            $this->calculator->ptrNameToIpv6('8.b.d.0.1.0.0.2.ip6.arpa'),
        );
    }

    public function test_ipv4_to_ptr_name_computes_the_record_name(): void
    {
        $this->assertSame(
            '10.2.0.192.in-addr.arpa',
            $this->calculator->ipv4ToPtrName('192.0.2.10'),
        );
    }

    public function test_ipv4_to_ptr_name_returns_null_for_invalid_ip(): void
    {
        $this->assertNull($this->calculator->ipv4ToPtrName('not-an-ip'));
    }

    public function test_ipv6_to_ptr_name_computes_the_record_name(): void
    {
        $this->assertSame(
            '2.4.2.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            $this->calculator->ipv6ToPtrName('2001:db8::242'),
        );
    }

    public function test_ipv6_to_ptr_name_returns_null_for_invalid_ip(): void
    {
        $this->assertNull($this->calculator->ipv6ToPtrName('not-an-ip'));
    }

    public function test_ipv4_to_ptr_name_and_back_are_inverses(): void
    {
        $name = $this->calculator->ipv4ToPtrName('192.0.2.10');

        $this->assertSame(
            '192.0.2.10',
            $this->calculator->ptrNameToIpv4($name),
        );
    }

    public function test_ipv6_to_ptr_name_and_back_are_inverses(): void
    {
        $name = $this->calculator->ipv6ToPtrName('2001:db8::242');

        $this->assertSame(
            '2001:db8::242',
            $this->calculator->ptrNameToIpv6($name),
        );
    }
}
