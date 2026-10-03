<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

/**
 * Maps a Paymenter customer profile onto the contact payload of the
 * Domain Reseller API (POST /api/v1/contacts).
 *
 * Paymenter profile fields (default custom properties): first_name,
 * last_name, email, company_name, address, address2, city, state, zip,
 * country (stored as country name), phone.
 */
final class ContactMapper
{
    /** Mirrors the e-mail pattern enforced by the API/registry. */
    private const EMAIL_PATTERN = '/^[0-9a-zA-Z._-]{1,64}@[0-9a-zA-Z._-]{3,64}$/';

    /** International calling codes by ISO 3166-1 alpha-2 country code. */
    private const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1', 'AI' => '1', 'AL' => '355', 'AM' => '374', 'AO' => '244',
        'AR' => '54', 'AS' => '1', 'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358', 'AZ' => '994', 'BA' => '387',
        'BB' => '1', 'BD' => '880', 'BE' => '32', 'BF' => '226', 'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229',
        'BL' => '590', 'BM' => '1', 'BN' => '673', 'BO' => '591', 'BQ' => '599', 'BR' => '55', 'BS' => '1', 'BT' => '975',
        'BW' => '267', 'BY' => '375', 'BZ' => '501', 'CA' => '1', 'CC' => '61', 'CD' => '243', 'CF' => '236', 'CG' => '242',
        'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56', 'CM' => '237', 'CN' => '86', 'CO' => '57', 'CR' => '506',
        'CU' => '53', 'CV' => '238', 'CW' => '599', 'CX' => '61', 'CY' => '357', 'CZ' => '420', 'DE' => '49', 'DJ' => '253',
        'DK' => '45', 'DM' => '1', 'DO' => '1', 'DZ' => '213', 'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212',
        'ER' => '291', 'ES' => '34', 'ET' => '251', 'FI' => '358', 'FJ' => '679', 'FK' => '500', 'FM' => '691', 'FO' => '298',
        'FR' => '33', 'GA' => '241', 'GB' => '44', 'GD' => '1', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233',
        'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590', 'GQ' => '240', 'GR' => '30', 'GT' => '502',
        'GU' => '1', 'GW' => '245', 'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385', 'HT' => '509', 'HU' => '36',
        'ID' => '62', 'IE' => '353', 'IL' => '972', 'IM' => '44', 'IN' => '91', 'IO' => '246', 'IQ' => '964', 'IR' => '98',
        'IS' => '354', 'IT' => '39', 'JE' => '44', 'JM' => '1', 'JO' => '962', 'JP' => '81', 'KE' => '254', 'KG' => '996',
        'KH' => '855', 'KI' => '686', 'KM' => '269', 'KN' => '1', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KY' => '1',
        'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1', 'LI' => '423', 'LK' => '94', 'LR' => '231', 'LS' => '266',
        'LT' => '370', 'LU' => '352', 'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373', 'ME' => '382',
        'MF' => '590', 'MG' => '261', 'MH' => '692', 'MK' => '389', 'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853',
        'MP' => '1', 'MQ' => '596', 'MR' => '222', 'MS' => '1', 'MT' => '356', 'MU' => '230', 'MV' => '960', 'MW' => '265',
        'MX' => '52', 'MY' => '60', 'MZ' => '258', 'NA' => '264', 'NC' => '687', 'NE' => '227', 'NF' => '672', 'NG' => '234',
        'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977', 'NR' => '674', 'NU' => '683', 'NZ' => '64', 'OM' => '968',
        'PA' => '507', 'PE' => '51', 'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PL' => '48', 'PM' => '508',
        'PR' => '1', 'PS' => '970', 'PT' => '351', 'PW' => '680', 'PY' => '595', 'QA' => '974', 'RE' => '262', 'RO' => '40',
        'RS' => '381', 'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677', 'SC' => '248', 'SD' => '249', 'SE' => '46',
        'SG' => '65', 'SH' => '290', 'SI' => '386', 'SJ' => '47', 'SK' => '421', 'SL' => '232', 'SM' => '378', 'SN' => '221',
        'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239', 'SV' => '503', 'SX' => '1', 'SY' => '963', 'SZ' => '268',
        'TC' => '1', 'TD' => '235', 'TG' => '228', 'TH' => '66', 'TJ' => '992', 'TK' => '690', 'TL' => '670', 'TM' => '993',
        'TN' => '216', 'TO' => '676', 'TR' => '90', 'TT' => '1', 'TV' => '688', 'TW' => '886', 'TZ' => '255', 'UA' => '380',
        'UG' => '256', 'US' => '1', 'UY' => '598', 'UZ' => '998', 'VA' => '39', 'VC' => '1', 'VE' => '58', 'VG' => '1',
        'VI' => '1', 'VN' => '84', 'VU' => '678', 'WF' => '681', 'WS' => '685', 'XK' => '383', 'YE' => '967', 'YT' => '262',
        'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /**
     * @param  array<string, string>  $countries  ISO code => country name (Paymenter's config('app.countries'))
     */
    public function __construct(private readonly array $countries = []) {}

    /**
     * Build the API contact payload.
     *
     * @param  array<string, mixed>  $profile
     *
     * @throws ContactDataException
     */
    public function map(array $profile): array
    {
        $problems = [];

        $firstName = $this->clean($profile['first_name'] ?? '', 100);
        $lastName = $this->clean($profile['last_name'] ?? '', 100);
        $company = $this->clean($profile['company_name'] ?? $profile['company'] ?? '', 255);
        $email = trim((string) ($profile['email'] ?? ''));
        $city = $this->clean($profile['city'] ?? '', 100);
        $postalCode = $this->clean($profile['zip'] ?? $profile['postal_code'] ?? '', 20);
        $state = $this->clean($profile['state'] ?? '', 100);
        $country = $this->countryCode($profile['country'] ?? null);

        if ($firstName === '' && $lastName === '') {
            $problems[] = 'A first and last name are required.';
        } elseif ($lastName === '') {
            // Single-word names: registries require both fields to be filled.
            $lastName = $firstName;
        } elseif ($firstName === '') {
            $firstName = $lastName;
        }

        if ($email === '' || preg_match(self::EMAIL_PATTERN, $email) !== 1) {
            $problems[] = 'A valid e-mail address (letters, digits, ".", "_" and "-" only) is required.';
        }

        $address = $this->splitStreet((string) ($profile['address'] ?? ''), (string) ($profile['address2'] ?? ''));
        if ($address === null) {
            $problems[] = 'A street address including the house number is required.';
        }

        if ($city === '') {
            $problems[] = 'A city is required.';
        }
        if ($postalCode === '') {
            $problems[] = 'A postal code is required.';
        }
        if ($country === null) {
            $problems[] = 'A valid country is required.';
        }

        $phone = $country !== null ? $this->formatPhone((string) ($profile['phone'] ?? ''), $country) : null;
        if ($phone === null) {
            $problems[] = 'A phone number including the country code (e.g. +49 30 123456) is required.';
        }

        if ($problems !== []) {
            throw new ContactDataException($problems);
        }

        $payload = [
            'type' => $company !== '' ? 'organization' : 'person',
            'sex' => 'NA',
            'firstName' => $firstName,
            'lastName' => $lastName,
            // The API requires an organisation; for private persons the full name is used.
            'organization' => $company !== '' ? $company : $this->clean($firstName . ' ' . $lastName, 255),
            'street' => $address['street'],
            'houseNumber' => $address['number'],
            'postalCode' => $postalCode,
            'city' => $city,
            'country' => $country,
            'phone' => $phone,
            'email' => $email,
        ];

        if ($state !== '') {
            $payload['state'] = $state;
        }

        return $payload;
    }

    /**
     * Fingerprint of the registry-relevant contact data. Used to detect when a
     * customer changed their profile and a new handle should be created.
     */
    public static function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Split "Musterstraße 12a" / "12 Main Street" into street and house number.
     *
     * @return array{street: string, number: string}|null
     */
    public function splitStreet(string $address, string $address2 = ''): ?array
    {
        $address = $this->clean($address, 255);
        $address2 = $this->clean($address2, 255);

        if ($address === '') {
            return null;
        }

        $number = '(\d+(?:\s?[a-zA-Z])?(?:\s*[-\/]\s*\d*[a-zA-Z]?)?)';

        // German/European style: street name followed by the number.
        if (preg_match('/^(.*?\D)[\s,]+(?:nr\.?\s*|no\.?\s*)?' . $number . '$/iu', $address, $m) && trim($m[1], " ,") !== '') {
            return ['street' => trim($m[1], " ,"), 'number' => $this->clean(str_replace(' ', '', $m[2]), 20)];
        }

        // Anglo/French style: number first.
        if (preg_match('/^' . $number . '[\s,]+(.+)$/u', $address, $m)) {
            return ['street' => trim($m[2], " ,"), 'number' => $this->clean(str_replace(' ', '', $m[1]), 20)];
        }

        // Number given in the second address line.
        if ($address2 !== '' && preg_match('/\d/', $address2) && mb_strlen($address2) <= 20) {
            return ['street' => $address, 'number' => $address2];
        }

        return null;
    }

    /**
     * Normalise a phone number to the registry format "+CC.NUMBER".
     */
    public function formatPhone(string $phone, string $countryCode): ?string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return null;
        }

        // Already in registry format.
        if (preg_match('/^\+\d{1,3}\.\d{4,}$/', $phone)) {
            return $phone;
        }

        // "+49 (0)30 ..." – the trunk prefix in brackets is not dialled internationally.
        $phone = str_replace('(0)', '', $phone);
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone);

        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus) {
            $callingCode = $this->detectCallingCode($digits, $countryCode);
            if ($callingCode === null) {
                return null;
            }
            $subscriber = substr($digits, strlen($callingCode));
        } else {
            $callingCode = self::CALLING_CODES[$countryCode] ?? null;
            if ($callingCode === null) {
                return null;
            }
            // National format: drop the trunk prefix (0) — except for Italy, which keeps it.
            $subscriber = $countryCode === 'IT' ? $digits : ltrim($digits, '0');
        }

        if (strlen($subscriber) < 4 || strlen($subscriber) > 14) {
            return null;
        }

        return '+' . $callingCode . '.' . $subscriber;
    }

    /**
     * Resolve the ISO country code from a code ("DE") or a country name ("Germany").
     */
    public function countryCode(mixed $country): ?string
    {
        $country = trim((string) $country);
        if ($country === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $country)) {
            $code = strtoupper($country);
            // UK is commonly used but not ISO 3166.
            $code = $code === 'UK' ? 'GB' : $code;

            return isset(self::CALLING_CODES[$code]) || isset($this->countries[$code]) ? $code : null;
        }

        foreach ($this->countries as $code => $name) {
            if (is_string($code) && strlen($code) === 2 && strcasecmp(trim((string) $name), $country) === 0) {
                return strtoupper($code);
            }
        }

        return null;
    }

    private function detectCallingCode(string $digits, string $countryCode): ?string
    {
        // Prefer the calling code of the customer's country when it matches.
        $own = self::CALLING_CODES[$countryCode] ?? null;
        if ($own !== null && str_starts_with($digits, $own)) {
            return $own;
        }

        // ITU calling codes are prefix-free: try 1, 2 and 3 digits.
        $known = array_flip(self::CALLING_CODES);
        for ($length = 1; $length <= 3; $length++) {
            $candidate = substr($digits, 0, $length);
            if (isset($known[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function clean(mixed $value, int $maxLength): string
    {
        $value = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';

        return mb_substr($value, 0, $maxLength);
    }
}
