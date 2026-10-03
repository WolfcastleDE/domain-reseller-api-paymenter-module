<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Decides when auto-renew should be paused because the renewal invoice is
 * unpaid shortly before the registry expiry, and when it can be resumed.
 */
final class AutoRenewGuard
{
    public const PAUSE = 'pause';

    public const RESUME = 'resume';

    /**
     * @param  string  $serviceStatus  Paymenter service status
     * @param  bool  $guarded  Auto-renew is currently paused by the guard
     * @param  bool  $unpaid  The service has an unpaid invoice
     * @param  array  $domain  Domain entry of the API (status, autoRenew, expiresAt)
     * @param  int  $guardDays  Days before expiry; 0 disables the guard
     */
    public static function decide(string $serviceStatus, bool $guarded, bool $unpaid, array $domain, int $guardDays, ?int $now = null): ?string
    {
        if ($serviceStatus !== 'active') {
            return null;
        }

        if ($guarded) {
            return $unpaid ? null : self::RESUME;
        }

        if ($guardDays <= 0 || !$unpaid || empty($domain['autoRenew']) || ($domain['status'] ?? null) !== 'active') {
            return null;
        }

        $expires = !empty($domain['expiresAt']) ? strtotime((string) $domain['expiresAt']) : false;
        if ($expires === false) {
            return null;
        }

        return $expires <= ($now ?? time()) + $guardDays * 86400 ? self::PAUSE : null;
    }
}
