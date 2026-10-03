<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\WebhookEvent;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WebhookSignature;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    public function test_verifies_signature(): void
    {
        $payload = '{"event":"domain.transferred","timestamp":"2026-10-03T12:00:00Z","data":{"domain":"example.de"}}';
        $signature = hash_hmac('sha256', $payload, 'secret123');

        $this->assertTrue(WebhookSignature::verify($payload, $signature, 'secret123'));
        $this->assertTrue(WebhookSignature::verify($payload, 'sha256=' . strtoupper($signature), 'secret123'));
        $this->assertFalse(WebhookSignature::verify($payload, $signature, 'other'));
        $this->assertFalse(WebhookSignature::verify($payload . ' ', $signature, 'secret123'));
        $this->assertFalse(WebhookSignature::verify($payload, '', 'secret123'));
        $this->assertFalse(WebhookSignature::verify($payload, $signature, ''));
    }

    public function test_maps_events_to_status(): void
    {
        $this->assertSame('active', WebhookEvent::status('domain.transferred', []));
        $this->assertSame('transfer_failed', WebhookEvent::status('domain.transfer_failed', []));
        $this->assertSame('redemption', WebhookEvent::status('domain.expired', ['currentState' => 'redemption']));
        $this->assertNull(WebhookEvent::status('domain.updated', []));
        $this->assertTrue(WebhookEvent::shouldRefresh('domain.transferred'));
        $this->assertFalse(WebhookEvent::shouldRefresh('domain.deleted'));
        $this->assertTrue(WebhookEvent::isDomainEvent('domain.deleted'));
        $this->assertFalse(WebhookEvent::isDomainEvent('wallet.deposit'));
    }
}
