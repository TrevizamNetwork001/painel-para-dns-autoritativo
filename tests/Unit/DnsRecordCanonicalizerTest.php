<?php

namespace Tests\Unit;

use App\Support\DnsRecordCanonicalizer;
use PHPUnit\Framework\TestCase;

class DnsRecordCanonicalizerTest extends TestCase
{
    public function test_name_lowercases_and_strips_trailing_dot(): void
    {
        $this->assertSame('ns1.example.com', DnsRecordCanonicalizer::name('NS1.Example.COM.'));
        $this->assertSame('ns1.example.com', DnsRecordCanonicalizer::name('ns1.example.com'));
    }

    public function test_name_preserves_apex_marker_and_empty_string(): void
    {
        $this->assertSame('@', DnsRecordCanonicalizer::name('@'));
        $this->assertSame('', DnsRecordCanonicalizer::name(''));
    }

    public function test_split_mx_content_extracts_leading_numeric_priority(): void
    {
        $this->assertSame([10, 'mail.example.com.'], DnsRecordCanonicalizer::splitMxContent('10 mail.example.com.'));
        $this->assertSame([0, 'mail.example.com'], DnsRecordCanonicalizer::splitMxContent('0 mail.example.com'));
    }

    public function test_split_mx_content_defaults_priority_ten_without_numeric_prefix(): void
    {
        $this->assertSame([10, 'mail.example.com.'], DnsRecordCanonicalizer::splitMxContent('mail.example.com.'));
    }

    public function test_comparison_key_treats_case_and_trailing_dot_as_equivalent(): void
    {
        $a = DnsRecordCanonicalizer::comparisonKey('NS', 'example.com.', 'NS1.Example.com.');
        $b = DnsRecordCanonicalizer::comparisonKey('ns', 'example.com', 'ns1.example.com');

        $this->assertSame($a, $b);
    }

    public function test_comparison_key_distinguishes_real_content_differences(): void
    {
        $a = DnsRecordCanonicalizer::comparisonKey('A', 'www.example.com', '198.51.100.10');
        $b = DnsRecordCanonicalizer::comparisonKey('A', 'www.example.com', '198.51.100.11');

        $this->assertNotSame($a, $b);
    }

    public function test_comparison_key_does_not_canonicalize_non_name_content(): void
    {
        // Conteúdo de A não é um nome DNS; não deve ser mexido (é um IP).
        $key = DnsRecordCanonicalizer::comparisonKey('A', 'HOST.Example.com.', '198.51.100.10');

        $this->assertStringContainsString('198.51.100.10', $key);
        $this->assertStringNotContainsString('198.51.100.10.', $key);
    }

    public function test_comparison_key_treats_discovered_and_stored_mx_form_as_equal(): void
    {
        // Formato bruto do dump do BIND (prioridade embutida no conteúdo)...
        $discovered = DnsRecordCanonicalizer::comparisonKey('MX', 'example.com.', '10 Mail.Example.com.');
        // ...contra o formato salvo no banco (prioridade já separada em coluna própria).
        $stored = DnsRecordCanonicalizer::comparisonKey('MX', 'example.com', 'mail.example.com', 10);

        $this->assertSame($discovered, $stored);
    }

    public function test_comparison_key_distinguishes_different_mx_priority(): void
    {
        $a = DnsRecordCanonicalizer::comparisonKey('MX', 'example.com', 'mail.example.com', 10);
        $b = DnsRecordCanonicalizer::comparisonKey('MX', 'example.com', 'mail.example.com', 20);

        $this->assertNotSame($a, $b);
    }
}
