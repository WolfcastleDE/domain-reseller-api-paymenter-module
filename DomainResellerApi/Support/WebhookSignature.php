<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Verification of the X-Webhook-Signature header sent with "custom" webhooks:
 * hex encoded HMAC-SHA256 of the raw request body, keyed with the webhook secret.
 */
final class WebhookSignature
{
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $payload, string $signature, string $secret): bool
    {
        $signature = strtolower(trim($signature));
        if ($secret === '' || $signature === '') {
            return false;
        }

        // Tolerate a "sha256=" prefix as used by other providers.
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        return hash_equals(self::sign($payload, $secret), $signature);
    }
}
