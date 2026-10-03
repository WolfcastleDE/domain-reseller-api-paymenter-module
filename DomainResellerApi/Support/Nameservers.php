<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use InvalidArgumentException;

/**
 * Parsing and validation of nameserver lists (the API accepts 2–6 hostnames).
 */
final class Nameservers
{
    public const MIN = 2;

    public const MAX = 6;

    /** Mirrors HOSTNAME_PATTERN of the API. */
    private const HOSTNAME_PATTERN = '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/';

    /**
     * Normalise a list of nameservers: lowercase, trimmed, no trailing dot,
     * IDN converted, empty entries and duplicates removed. Order is kept.
     *
     * @return string[]
     */
    public static function normalize(mixed $nameservers): array
    {
        $result = [];
        foreach (Values::toList($nameservers) as $ns) {
            $ns = rtrim(mb_strtolower(trim($ns)), '.');
            if ($ns === '') {
                continue;
            }
            if (preg_match('/[^\x20-\x7e]/', $ns) && function_exists('idn_to_ascii')) {
                $ns = strtolower((string) idn_to_ascii($ns, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46));
            }
            $result[$ns] = $ns;
        }

        return array_values($result);
    }

    public static function isValidHostname(string $hostname): bool
    {
        return preg_match(self::HOSTNAME_PATTERN, $hostname) === 1;
    }

    /**
     * Normalise and validate; throws with a readable message on invalid input.
     *
     * @return string[]
     */
    public static function validate(mixed $nameservers): array
    {
        $list = self::normalize($nameservers);

        foreach ($list as $ns) {
            if (!self::isValidHostname($ns)) {
                throw new InvalidArgumentException(sprintf('"%s" is not a valid nameserver hostname.', $ns));
            }
        }

        if (count($list) < self::MIN) {
            throw new InvalidArgumentException(sprintf('At least %d nameservers are required.', self::MIN));
        }

        if (count($list) > self::MAX) {
            throw new InvalidArgumentException(sprintf('At most %d nameservers are allowed.', self::MAX));
        }

        return $list;
    }

    /**
     * Collect ns1..ns6 style keys from an array (service properties / checkout values).
     *
     * @return string[]
     */
    public static function fromKeys(array $values, string $prefix = 'ns'): array
    {
        $list = [];
        for ($i = 1; $i <= self::MAX; $i++) {
            if (!empty($values[$prefix . $i])) {
                $list[] = (string) $values[$prefix . $i];
            }
        }

        return self::normalize($list);
    }
}
