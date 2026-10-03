<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\Values;
use PHPUnit\Framework\TestCase;

class ValuesTest extends TestCase
{
    public function test_bool(): void
    {
        foreach ([true, 1, '1', 'true', 'yes', 'on', 'Y'] as $value) {
            $this->assertTrue(Values::toBool($value), var_export($value, true));
        }
        foreach ([false, 0, '0', 'false', 'no', 'off'] as $value) {
            $this->assertFalse(Values::toBool($value, true), var_export($value, true));
        }
        $this->assertTrue(Values::toBool(null, true));
        $this->assertFalse(Values::toBool('', false));
    }

    public function test_int(): void
    {
        $this->assertSame(5, Values::toInt('5'));
        $this->assertSame(3, Values::toInt('abc', 3));
        $this->assertSame(7, Values::toInt(null, 7));
    }

    public function test_list(): void
    {
        $this->assertSame(['a', 'b'], Values::toList(['a', ' ', 'b']));
        $this->assertSame(['a', 'b'], Values::toList('["a","b"]'));
        $this->assertSame(['a', 'b', 'c'], Values::toList("a, b;c"));
        $this->assertSame([], Values::toList(null));
    }
}
