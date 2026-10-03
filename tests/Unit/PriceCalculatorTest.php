<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PriceCalculator;
use PHPUnit\Framework\TestCase;

class PriceCalculatorTest extends TestCase
{
    private const DE = ['tld' => 'de', 'registerPrice' => '4.00', 'renewPrice' => '4.00', 'transferPrice' => '4.00', 'setupPrice' => '2.50', 'minYears' => 1];

    private const COM = ['tld' => 'com', 'registerPrice' => '9.00', 'renewPrice' => '12.00', 'transferPrice' => '10.00', 'setupPrice' => null, 'minYears' => 1];

    public function test_plain_purchase_prices(): void
    {
        $plan = (new PriceCalculator)->plan(self::DE);

        $this->assertSame(1, $plan['years']);
        $this->assertSame(4.0, $plan['price']);
        $this->assertSame(2.5, $plan['setup_fee']);
        $this->assertSame(6.5, $plan['first_period']);
    }

    public function test_margin_fixed_and_rate(): void
    {
        $plan = (new PriceCalculator(marginPercent: 50, fixedMarkup: 1, rate: 2))->plan(self::DE);

        // 4 * 2 * 1.5 + 1 = 13; first: 6.5 * 2 * 1.5 + 1 = 20.5
        $this->assertSame(13.0, $plan['price']);
        $this->assertSame(7.5, $plan['setup_fee']);
    }

    public function test_cheaper_registration_has_no_negative_setup_fee(): void
    {
        $plan = (new PriceCalculator)->plan(self::COM);

        $this->assertSame(12.0, $plan['price']);
        $this->assertSame(0.0, $plan['setup_fee']);
    }

    public function test_transfer_mode_uses_transfer_price(): void
    {
        $plan = (new PriceCalculator)->plan(self::DE, 'transfer');

        $this->assertSame(4.0, $plan['price']);
        $this->assertSame(2.5, $plan['setup_fee']);
    }

    public function test_multi_year_minimum(): void
    {
        $plan = (new PriceCalculator)->plan(['registerPrice' => '10', 'renewPrice' => '10', 'minYears' => 2]);

        $this->assertSame(2, $plan['years']);
        $this->assertSame(20.0, $plan['price']);
    }

    public function test_price_endings(): void
    {
        $calculator = new PriceCalculator(ending: 0.99);

        $this->assertSame(4.99, $calculator->sell(4.20));
        $this->assertSame(5.99, $calculator->sell(5.0));
        $this->assertSame(4.99, $calculator->sell(4.99));
    }

    public function test_rejects_invalid_parameters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PriceCalculator(rate: 0);
    }
}
