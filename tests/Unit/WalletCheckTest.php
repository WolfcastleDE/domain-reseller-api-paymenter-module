<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\WalletCheck;
use PHPUnit\Framework\TestCase;

class WalletCheckTest extends TestCase
{
    private const CHECK = ['available' => true, 'premium' => false, 'price' => 10.0, 'setupPrice' => 2.0, 'transferPrice' => 8.0, 'renewPrice' => 12.0, 'vatPercent' => 19, 'reverseCharge' => false];

    public function test_required_amount_for_registration(): void
    {
        $this->assertSame(14.28, WalletCheck::requiredAmount(self::CHECK, 'register'));
        $this->assertSame(26.18, WalletCheck::requiredAmount(self::CHECK, 'register', 2));
    }

    public function test_required_amount_for_transfer_and_premium(): void
    {
        $this->assertSame(11.9, WalletCheck::requiredAmount(self::CHECK, 'transfer'));
        $premium = ['premium' => true, 'price' => 500.0, 'renewPrice' => 20.0] + self::CHECK;
        $this->assertSame(round((500 + 20 + 2) * 1.19, 2), WalletCheck::requiredAmount($premium, 'register', 2));
    }

    public function test_reverse_charge_has_no_vat(): void
    {
        $this->assertSame(12.0, WalletCheck::requiredAmount(['reverseCharge' => true] + self::CHECK, 'register'));
    }

    public function test_available_and_covers(): void
    {
        $this->assertSame(5.0, WalletCheck::available(['balance' => 5, 'creditLimit' => 100]));
        $this->assertSame(105.0, WalletCheck::available(['balance' => 5, 'creditLimit' => 100, 'postpaid' => true]));
        $this->assertSame(-5.0, WalletCheck::available(['balance' => -55, 'creditLimit' => 50, 'allowNegativeBalance' => true]));

        $this->assertTrue(WalletCheck::covers(['balance' => 14.28], null, 14.28));
        $this->assertFalse(WalletCheck::covers(['balance' => 14.27], null, 14.28));
        $this->assertTrue(WalletCheck::covers(['balance' => 0], ['enabled' => true], 14.28));
        $this->assertFalse(WalletCheck::covers(['balance' => 0], ['enabled' => false], 14.28));
    }
}
