<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Normalisation and validation of domain names entered by customers.
 *
 * The API expects lowercase ASCII names, so IDNs are converted to punycode
 * (requires ext-intl, which Paymenter/Filament already depend on).
 */
final class DomainName
{
    /**
     * Turn user input ("https://Www.Müller.de/", " example.com. ") into a
     * lowercase ASCII domain name. Returns null when the input is not a
     * syntactically valid domain name.
     */
    public static function normalize(?string $input): ?string
    {
        $domain = mb_strtolower(trim((string) $input));
        if ($domain === '') {
            return null;
        }

        // Strip scheme, credentials, path, query and port if a URL was pasted.
        $domain = preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $domain);
        $domain = preg_replace('#[/?\#].*$#', '', $domain);
        $domain = preg_replace('#^.*@#', '', $domain);
        $domain = preg_replace('#:\d+$#', '', $domain);
        $domain = rtrim($domain, '.');

        if ($domain === '' || str_contains($domain, ' ')) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $domain)) {
            if (!function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                return null;
            }
            $domain = strtolower($ascii);
        }

        return self::isValid($domain) ? $domain : null;
    }

    /**
     * Syntactic validation of an ASCII domain name with at least two labels.
     */
    public static function isValid(string $domain): bool
    {
        if (strlen($domain) > 253) {
            return false;
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label)) {
                return false;
            }
        }

        // The TLD must not be purely numeric.
        return !ctype_digit(end($labels));
    }

    /**
     * Everything after the first label ("example.co.uk" → "co.uk"), matching
     * how the API determines the TLD for pricing.
     */
    public static function tld(string $domain): string
    {
        $pos = strpos($domain, '.');

        return $pos === false ? '' : substr($domain, $pos + 1);
    }

    public static function sld(string $domain): string
    {
        $pos = strpos($domain, '.');

        return $pos === false ? $domain : substr($domain, 0, $pos);
    }

    /**
     * Unicode representation for display purposes.
     */
    public static function toUnicode(string $domain): string
    {
        if (!str_contains($domain, 'xn--') || !function_exists('idn_to_utf8')) {
            return $domain;
        }

        $unicode = idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);

        return $unicode === false ? $domain : $unicode;
    }

    /**
     * Normalise a configured list of TLDs (".de", "DE", " com ") to ["de", "com"].
     *
     * @param  mixed  $tlds  array, JSON string or comma/whitespace separated string
     * @return string[]
     */
    public static function normalizeTlds(mixed $tlds): array
    {
        $list = Values::toList($tlds);

        $normalized = [];
        foreach ($list as $tld) {
            $tld = ltrim(mb_strtolower(trim((string) $tld)), '.');
            if ($tld === '') {
                continue;
            }
            if (preg_match('/[^\x20-\x7e]/', $tld) && function_exists('idn_to_ascii')) {
                $tld = strtolower((string) idn_to_ascii($tld, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46));
            }
            $normalized[$tld] = $tld;
        }

        return array_values($normalized);
    }

    /**
     * Is the TLD of $domain contained in the allow-list? An empty list allows every TLD.
     *
     * @param  string[]  $allowedTlds
     */
    public static function tldAllowed(string $domain, array $allowedTlds): bool
    {
        return $allowedTlds === [] || in_array(self::tld($domain), $allowedTlds, true);
    }
}
