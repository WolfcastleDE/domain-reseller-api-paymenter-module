<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Decides whether the reseller wallet can pay for an order.
 */
final class WalletCheck
{
    /**
     * Gross amount the API will charge for a register/transfer, from a domain check result.
     */
    public static function requiredAmount(array $check, string $action, int $years = 1): float
    {
        $setup = (float) ($check['setupPrice'] ?? 0);
        if ($action === 'transfer') {
            $net = (float) ($check['transferPrice'] ?? 0) + $setup;
        } elseif (!empty($check['premium'])) {
            // Premium: first year at the premium price, further years at the renew price.
            $net = (float) ($check['price'] ?? 0) + (float) ($check['renewPrice'] ?? 0) * max(0, $years - 1) + $setup;
        } else {
            $net = (float) ($check['price'] ?? 0) * max(1, $years) + $setup;
        }

        $vat = !empty($check['reverseCharge']) ? 0.0 : (float) ($check['vatPercent'] ?? 0);

        return round($net * (1 + $vat / 100), 2);
    }

    /**
     * Money that can be spent right now: balance plus credit limit for postpaid/negative-balance accounts.
     */
    public static function available(array $wallet): float
    {
        $available = (float) ($wallet['balance'] ?? 0);
        if (!empty($wallet['allowNegativeBalance']) || !empty($wallet['postpaid'])) {
            $available += (float) ($wallet['creditLimit'] ?? 0);
        }

        return round($available, 2);
    }

    /**
     * Does the wallet cover the amount? An active auto top-up is trusted to cover it.
     */
    public static function covers(array $wallet, ?array $autoTopup, float $amount): bool
    {
        if (!empty($autoTopup['enabled'])) {
            return true;
        }

        return self::available($wallet) + 0.00001 >= $amount;
    }
}
