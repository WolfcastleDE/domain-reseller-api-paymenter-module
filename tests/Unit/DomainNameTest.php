<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainNameTest extends TestCase
{
    public static function validInputs(): array
    {
        return [
            'plain' => ['example.com', 'example.com'],
            'uppercase and spaces' => ['  Example.DE ', 'example.de'],
            'trailing dot' => ['example.com.', 'example.com'],
            'url' => ['https://example.org/path?x=1', 'example.org'],
            'url with port' => ['http://example.net:8080', 'example.net'],
            'multi-label tld' => ['example.co.uk', 'example.co.uk'],
            'hyphen' => ['my-domain.eu', 'my-domain.eu'],
            'idn' => ['Müller.de', 'xn--mller-kva.de'],
            'punycode' => ['xn--mller-kva.de', 'xn--mller-kva.de'],
        ];
    }

    #[DataProvider('validInputs')]
    public function test_normalizes_valid_domains(string $input, string $expected): void
    {
        $this->assertSame($expected, DomainName::normalize($input));
    }

    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'no tld' => ['localhost'],
            'leading hyphen' => ['-example.com'],
            'trailing hyphen' => ['example-.com'],
            'underscore' => ['ex_ample.com'],
            'space inside' => ['exa mple.com'],
            'numeric tld' => ['example.123'],
            'label too long' => [str_repeat('a', 64) . '.com'],
            'empty label' => ['example..com'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_domains(string $input): void
    {
        $this->assertNull(DomainName::normalize($input));
    }

    public function test_splits_tld_and_sld(): void
    {
        $this->assertSame('co.uk', DomainName::tld('example.co.uk'));
        $this->assertSame('example', DomainName::sld('example.co.uk'));
        $this->assertSame('de', DomainName::tld('example.de'));
    }

    public function test_converts_to_unicode_for_display(): void
    {
        $this->assertSame('müller.de', DomainName::toUnicode('xn--mller-kva.de'));
        $this->assertSame('example.de', DomainName::toUnicode('example.de'));
    }

    public function test_normalizes_tld_lists_from_any_format(): void
    {
        $this->assertSame(['de', 'com'], DomainName::normalizeTlds(['.DE', ' com ', 'de']));
        $this->assertSame(['de', 'eu'], DomainName::normalizeTlds('["de", ".eu"]'));
        $this->assertSame(['de', 'at', 'ch'], DomainName::normalizeTlds('de, .at ch'));
        $this->assertSame([], DomainName::normalizeTlds(null));
    }

    public function test_checks_allowed_tlds(): void
    {
        $this->assertTrue(DomainName::tldAllowed('example.de', ['de', 'com']));
        $this->assertFalse(DomainName::tldAllowed('example.net', ['de', 'com']));
        $this->assertTrue(DomainName::tldAllowed('example.net', []));
        $this->assertFalse(DomainName::tldAllowed('example.co.uk', ['uk']));
    }
}
