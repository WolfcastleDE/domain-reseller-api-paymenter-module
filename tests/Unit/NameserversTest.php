<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Nameservers;
use PHPUnit\Framework\TestCase;

class NameserversTest extends TestCase
{
    public function test_normalizes_lists(): void
    {
        $this->assertSame(
            ['ns1.example.com', 'ns2.example.com'],
            Nameservers::normalize([' NS1.Example.com. ', '', 'ns2.example.com', 'ns1.example.com']),
        );
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], Nameservers::normalize('ns1.example.com, ns2.example.com'));
    }

    public function test_validates_count_and_hostnames(): void
    {
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], Nameservers::validate(['ns1.example.com', 'ns2.example.com']));

        $this->expectException(InvalidArgumentException::class);
        Nameservers::validate(['ns1.example.com']);
    }

    public function test_rejects_invalid_hostname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Nameservers::validate(['ns1.example.com', 'not a host']);
    }

    public function test_rejects_too_many(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Nameservers::validate(array_map(fn ($i) => "ns{$i}.example.com", range(1, 7)));
    }

    public function test_reads_numbered_keys(): void
    {
        $this->assertSame(
            ['ns1.example.com', 'ns3.example.com'],
            Nameservers::fromKeys(['ns1' => 'ns1.example.com', 'ns2' => '', 'ns3' => 'NS3.example.com', 'domain' => 'x.de']),
        );
    }

    public function test_hostname_validation(): void
    {
        $this->assertTrue(Nameservers::isValidHostname('a.ns.example.de'));
        $this->assertFalse(Nameservers::isValidHostname('example'));
        $this->assertFalse(Nameservers::isValidHostname('-ns.example.com'));
    }
}
