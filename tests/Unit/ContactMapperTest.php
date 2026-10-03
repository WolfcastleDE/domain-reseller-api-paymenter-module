<?php

namespace Tests\Unit;

use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactDataException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ContactMapperTest extends TestCase
{
    private const COUNTRIES = ['' => 'Select a country', 'DE' => 'Germany', 'AT' => 'Austria', 'US' => 'United States', 'GB' => 'United Kingdom'];

    private function mapper(): ContactMapper
    {
        return new ContactMapper(self::COUNTRIES);
    }

    private function profile(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Erika',
            'last_name' => 'Mustermann',
            'email' => 'erika@example.de',
            'address' => 'Musterstraße 12a',
            'city' => 'Berlin',
            'zip' => '10115',
            'country' => 'Germany',
            'phone' => '030 1234567',
        ], $overrides);
    }

    public function test_maps_a_private_person(): void
    {
        $payload = $this->mapper()->map($this->profile());

        $this->assertSame([
            'type' => 'person',
            'sex' => 'NA',
            'firstName' => 'Erika',
            'lastName' => 'Mustermann',
            'organization' => 'Erika Mustermann',
            'street' => 'Musterstraße',
            'houseNumber' => '12a',
            'postalCode' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'phone' => '+49.301234567',
            'email' => 'erika@example.de',
        ], $payload);
    }

    public function test_maps_a_company(): void
    {
        $payload = $this->mapper()->map($this->profile(['company_name' => 'Beispiel GmbH', 'state' => 'Berlin']));

        $this->assertSame('organization', $payload['type']);
        $this->assertSame('Beispiel GmbH', $payload['organization']);
        $this->assertSame('Berlin', $payload['state']);
    }

    public function test_reports_all_missing_fields(): void
    {
        try {
            $this->mapper()->map(['first_name' => '', 'last_name' => '', 'email' => 'erika+tag@example.de']);
            $this->fail('Expected exception');
        } catch (ContactDataException $e) {
            $this->assertCount(7, $e->problems());
        }
    }

    public function test_single_name_is_used_for_both_fields(): void
    {
        $payload = $this->mapper()->map($this->profile(['last_name' => '']));

        $this->assertSame('Erika', $payload['lastName']);
    }

    public static function streets(): array
    {
        return [
            ['Musterstraße 12', '', 'Musterstraße', '12'],
            ['Hauptstr. 5 b', '', 'Hauptstr.', '5b'],
            ['Am Ring 12-14', '', 'Am Ring', '12-14'],
            ['Straße des 17. Juni 135', '', 'Straße des 17. Juni', '135'],
            ['Lindenweg 3/1', '', 'Lindenweg', '3/1'],
            ['Rue de Rivoli, 10', '', 'Rue de Rivoli', '10'],
            ['221B Baker Street', '', 'Baker Street', '221B'],
            ['Musterallee', '7', 'Musterallee', '7'],
        ];
    }

    #[DataProvider('streets')]
    public function test_splits_streets(string $address, string $address2, string $street, string $number): void
    {
        $this->assertSame(['street' => $street, 'number' => $number], $this->mapper()->splitStreet($address, $address2));
    }

    public function test_street_without_number_is_rejected(): void
    {
        $this->assertNull($this->mapper()->splitStreet('Postfach', ''));
    }

    public static function phones(): array
    {
        return [
            ['030 1234567', 'DE', '+49.301234567'],
            ['+49 (0)30 123 4567', 'DE', '+49.301234567'],
            ['+49 30 1234567', 'DE', '+49.301234567'],
            ['0049 30 1234567', 'DE', '+49.301234567'],
            ['+49.301234567', 'DE', '+49.301234567'],
            ['+43 1 234567', 'DE', '+43.1234567'],
            ['(555) 123-4567', 'US', '+1.5551234567'],
            ['06 123 45678', 'IT', '+39.0612345678'],
        ];
    }

    #[DataProvider('phones')]
    public function test_formats_phone_numbers(string $input, string $country, string $expected): void
    {
        $this->assertSame($expected, $this->mapper()->formatPhone($input, $country));
    }

    public function test_rejects_bad_phone_numbers(): void
    {
        $this->assertNull($this->mapper()->formatPhone('', 'DE'));
        $this->assertNull($this->mapper()->formatPhone('12', 'DE'));
    }

    public function test_resolves_countries(): void
    {
        $mapper = $this->mapper();

        $this->assertSame('DE', $mapper->countryCode('Germany'));
        $this->assertSame('DE', $mapper->countryCode('de'));
        $this->assertSame('GB', $mapper->countryCode('UK'));
        $this->assertSame('AT', $mapper->countryCode('austria'));
        $this->assertNull($mapper->countryCode('Atlantis'));
        $this->assertNull($mapper->countryCode(''));
    }

    public function test_fingerprint_is_order_independent(): void
    {
        $this->assertSame(
            ContactMapper::fingerprint(['a' => 1, 'b' => 2]),
            ContactMapper::fingerprint(['b' => 2, 'a' => 1]),
        );
        $this->assertNotSame(
            ContactMapper::fingerprint(['a' => 1]),
            ContactMapper::fingerprint(['a' => 2]),
        );
    }
}
