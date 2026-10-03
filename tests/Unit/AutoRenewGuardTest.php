<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\AutoRenewGuard;
use PHPUnit\Framework\TestCase;

class AutoRenewGuardTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function domain(int $daysUntilExpiry, bool $autoRenew = true, string $status = 'active'): array
    {
        return ['status' => $status, 'autoRenew' => $autoRenew, 'expiresAt' => gmdate('c', self::NOW + $daysUntilExpiry * 86400)];
    }

    public function test_pauses_unpaid_domain_close_to_expiry(): void
    {
        $this->assertSame(AutoRenewGuard::PAUSE, AutoRenewGuard::decide('active', false, true, $this->domain(3), 5, self::NOW));
        $this->assertSame(AutoRenewGuard::PAUSE, AutoRenewGuard::decide('active', false, true, $this->domain(5), 5, self::NOW));
    }

    public function test_does_not_pause_otherwise(): void
    {
        $this->assertNull(AutoRenewGuard::decide('active', false, true, $this->domain(10), 5, self::NOW), 'too early');
        $this->assertNull(AutoRenewGuard::decide('active', false, false, $this->domain(3), 5, self::NOW), 'paid');
        $this->assertNull(AutoRenewGuard::decide('active', false, true, $this->domain(3), 0, self::NOW), 'guard disabled');
        $this->assertNull(AutoRenewGuard::decide('active', false, true, $this->domain(3, false), 5, self::NOW), 'auto-renew already off');
        $this->assertNull(AutoRenewGuard::decide('active', false, true, $this->domain(3, true, 'pending_transfer'), 5, self::NOW), 'not active at registry');
        $this->assertNull(AutoRenewGuard::decide('suspended', false, true, $this->domain(3), 5, self::NOW), 'suspension handles it');
        $this->assertNull(AutoRenewGuard::decide('active', false, true, ['status' => 'active', 'autoRenew' => true], 5, self::NOW), 'unknown expiry');
    }

    public function test_resumes_after_payment(): void
    {
        $this->assertSame(AutoRenewGuard::RESUME, AutoRenewGuard::decide('active', true, false, $this->domain(3, false), 5, self::NOW));
        $this->assertNull(AutoRenewGuard::decide('active', true, true, $this->domain(3, false), 5, self::NOW), 'still unpaid');
        $this->assertNull(AutoRenewGuard::decide('suspended', true, false, $this->domain(3, false), 5, self::NOW));
    }
}
