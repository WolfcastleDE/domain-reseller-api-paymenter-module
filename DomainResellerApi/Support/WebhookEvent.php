<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Derives the registry status of a domain from a webhook event.
 */
final class WebhookEvent
{
    private const STATUS_BY_EVENT = [
        'domain.registered' => 'active',
        'domain.transferred' => 'active',
        'domain.transfer_failed' => 'transfer_failed',
        'domain.traded' => 'active',
        'domain.expired' => 'expired',
        'domain.deleted' => 'deleted',
        'domain.restored' => 'active',
        'domain.held' => 'suspended',
        'domain.unheld' => 'active',
    ];

    /** Events after which the full domain record should be re-fetched. */
    private const REFRESH_EVENTS = [
        'domain.registered',
        'domain.transferred',
        'domain.traded',
        'domain.restored',
        'domain.updated',
        'domain.unheld',
        'domain.expiry_warning',
    ];

    /**
     * New status for the domain, or null if the event does not change it.
     */
    public static function status(string $event, array $data): ?string
    {
        if (!empty($data['currentState']) && is_string($data['currentState'])) {
            return $data['currentState'];
        }

        return self::STATUS_BY_EVENT[$event] ?? null;
    }

    public static function shouldRefresh(string $event): bool
    {
        return in_array($event, self::REFRESH_EVENTS, true);
    }

    public static function isDomainEvent(string $event): bool
    {
        return str_starts_with($event, 'domain.');
    }
}
