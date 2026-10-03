<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Exception;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactMapper;
use PHPUnit\Framework\TestCase;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Tests\Support\FakeServer;
use Tests\Support\FakeTransport;
use Tests\Support\TestableExtension;

class ExtensionTest extends TestCase
{
    private FakeTransport $api;

    protected function setUp(): void
    {
        $this->api = new FakeTransport;
    }

    private function extension(array $config = []): TestableExtension
    {
        $extension = new TestableExtension(array_merge(['api_key' => 'drapi_key', 'skip_wallet_check' => true], $config));
        $extension->setClient(new ApiClient('drapi_key', 'https://api.example.test', false, 5, $this->api));

        return $extension;
    }

    private function service(array $properties = ['domain' => 'example.de', 'action' => 'register']): Service
    {
        return new Service($properties);
    }

    private function stubContactCreation(string $handle = 'DRA-H1'): void
    {
        $this->api->on('POST', '/api/v1/contacts/', ['success' => true, 'data' => ['id' => 'c1', 'handle' => $handle, 'message' => 'ok']]);
    }

    private function message(string $key): string
    {
        return 'domain-reseller-api::messages.' . $key;
    }

    // ── Checkout ─────────────────────────────────

    public function test_checkout_offers_register_and_transfer(): void
    {
        $fields = $this->extension()->getCheckoutConfig(new Product, [], ['tlds' => ['de']]);

        $this->assertSame(['action', 'domain'], array_column($fields, 'name'));
        $this->assertSame(['register', 'transfer'], array_keys($fields[0]['options']));
        $this->assertSame('', $fields[1]['default']);
        $this->assertSame('example.de', $fields[1]['placeholder']);
    }

    public function test_checkout_asks_for_auth_code_on_transfer(): void
    {
        $fields = $this->extension()->getCheckoutConfig(new Product, ['action' => 'transfer'], []);

        $this->assertSame(['action', 'domain', 'auth_code'], array_column($fields, 'name'));
        $this->assertTrue($fields[2]['required']);
    }

    public function test_checkout_transfer_only_product(): void
    {
        $fields = $this->extension()->getCheckoutConfig(new Product, [], ['allow_register' => false, 'allow_transfer' => true]);

        $this->assertSame(['domain', 'auth_code'], array_column($fields, 'name'));
    }

    public function test_checkout_nameservers_and_whois_privacy(): void
    {
        $fields = $this->extension()->getCheckoutConfig(new Product, ['action' => 'register'], [
            'nameserver_mode' => 'customer',
            'whois_privacy' => 'optional',
            'allow_transfer' => '0',
        ]);

        $this->assertSame(['domain', 'ns1', 'ns2', 'ns3', 'ns4', 'whois_privacy'], array_column($fields, 'name'));
        $this->assertSame('no', $fields[5]['default']);
    }

    public function test_domain_validation(): void
    {
        $extension = $this->extension();
        $settings = ['tlds' => ['de', 'com'], 'allow_premium' => false];

        $this->assertSame($this->message('checkout.error_invalid_domain'), $extension->validateOrderableDomain('not a domain', $settings, 'register'));
        $this->assertSame($this->message('checkout.error_tld_not_allowed'), $extension->validateOrderableDomain('example.net', $settings, 'register'));
        $this->assertSame([], $this->api->requests, 'No API call for locally invalid input');

        $this->api->on('GET', '/api/v1/domains/check/free.de', ['success' => true, 'data' => ['domain' => 'free.de', 'available' => true, 'premium' => false]]);
        $this->api->on('GET', '/api/v1/domains/check/taken.de', ['success' => true, 'data' => ['domain' => 'taken.de', 'available' => false, 'premium' => false]]);
        $this->api->on('GET', '/api/v1/domains/check/premium.com', ['success' => true, 'data' => ['domain' => 'premium.com', 'available' => true, 'premium' => true]]);
        $this->api->on('GET', '/api/v1/domains/check/broken.de', ['success' => false, 'error' => 'Rate limit exceeded'], 429);

        $this->assertNull($extension->validateOrderableDomain('FREE.de', $settings, 'register'));
        $this->assertSame($this->message('checkout.error_unavailable'), $extension->validateOrderableDomain('taken.de', $settings, 'register'));
        $this->assertSame($this->message('checkout.error_premium'), $extension->validateOrderableDomain('premium.com', $settings, 'register'));
        $this->assertNull($extension->validateOrderableDomain('premium.com', ['allow_premium' => true], 'register'));
        $this->assertNull($extension->validateOrderableDomain('taken.de', $settings, 'transfer'));
        $this->assertSame($this->message('checkout.error_not_registered'), $extension->validateOrderableDomain('free.de', $settings, 'transfer'));
        $this->assertSame($this->message('checkout.error_check_failed'), $extension->validateOrderableDomain('broken.de', $settings, 'register'));
    }

    public function test_sandbox_allows_transfers_of_unregistered_domains(): void
    {
        $this->api->on('GET', '/api/v1/domains/check/free.de', ['success' => true, 'data' => ['domain' => 'free.de', 'available' => true, 'premium' => false]]);
        $extension = new TestableExtension(['api_key' => 'k', 'sandbox' => true]);
        $extension->setClient(new ApiClient('k', 'https://api.example.test', true, 5, $this->api));

        $this->assertNull($extension->validateOrderableDomain('free.de', [], 'transfer'));
    }

    public function test_domain_rule_closure_reports_failures(): void
    {
        $rule = $this->extension()->domainRule(['tlds' => ['de']], 'register');
        $errors = [];

        $rule('checkoutConfig.domain', 'example.com', function ($message) use (&$errors) {
            $errors[] = $message;
        });

        $this->assertSame([$this->message('checkout.error_tld_not_allowed')], $errors);
    }

    // ── Provisioning ─────────────────────────────

    public function test_register_creates_contact_and_domain(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => [
            'domainId' => 'dom_1', 'domain' => 'example.de', 'status' => 'active', 'expiresAt' => '2027-10-03T10:00:00.000Z',
        ]]);

        $service = $this->service();
        $result = $this->extension(['tech_handle' => 'RESELLER-TECH'])->createServer($service, ['years' => '1'], $service->props());

        $contactRequest = $this->api->find('POST', '/api/v1/contacts/')[0]['body'];
        $this->assertSame('Musterstraße', $contactRequest['street']);
        $this->assertSame('+49.301234567', $contactRequest['phone']);
        $this->assertSame('DE', $contactRequest['country'], 'Country name resolved through config("app.countries")');

        $register = $this->api->find('POST', '/api/v1/domains/register')[0]['body'];
        $this->assertSame([
            'domain' => 'example.de',
            'years' => 1,
            'contacts' => ['owner' => 'DRA-H1', 'tech' => 'RESELLER-TECH'],
        ], $register);

        $props = $service->props();
        $this->assertSame('dom_1', $props['dra_domain_id']);
        $this->assertSame('active', $props['dra_status']);
        $this->assertSame('2027-10-03', $props['dra_expires_at']);
        $this->assertSame('DRA-H1', $props['dra_owner_handle']);
        $this->assertSame('1', $props['dra_auto_renew']);
        $this->assertSame(['domain' => 'example.de', 'action' => 'register', 'status' => 'active', 'expires_at' => '2027-10-03'], $result);

        $userProps = $service->user->properties()->toArray();
        $handleKeys = array_values(array_filter(array_keys($userProps), fn ($k) => str_starts_with($k, 'dra_handle_') && !str_starts_with($k, 'dra_handle_fp_')));
        $this->assertCount(1, $handleKeys);
        $this->assertSame('DRA-H1', $userProps[$handleKeys[0]]);
    }

    public function test_register_reuses_existing_handle(): void
    {
        $this->stubContactCreation();
        $this->api->on('GET', '/api/v1/contacts/DRA-H1', ['success' => true, 'data' => ['handle' => 'DRA-H1']]);
        $this->api->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => ['domainId' => 'dom_x', 'status' => 'active']]);

        $user = (new Service)->user;
        $extension = $this->extension();

        $first = new Service(['domain' => 'one.de'], $user);
        $extension->createServer($first, [], $first->props());
        $second = new Service(['domain' => 'two.de'], $user);
        $extension->createServer($second, [], $second->props());

        $this->assertCount(1, $this->api->find('POST', '/api/v1/contacts/'));
        $this->assertCount(1, $this->api->find('GET', '/api/v1/contacts/DRA-H1'));
        $this->assertSame('DRA-H1', $second->props()['dra_owner_handle']);
    }

    public function test_changed_profile_gets_a_new_handle(): void
    {
        $this->stubContactCreation('DRA-H1');
        $this->api->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => ['domainId' => 'dom_x', 'status' => 'active']]);

        $user = new User(['address' => 'Musterstraße 1', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'DE', 'phone' => '+49 30 1234567']);
        $extension = $this->extension();
        $first = new Service(['domain' => 'one.de'], $user);
        $extension->createServer($first, [], $first->props());

        $user->properties()->updateOrCreate(['key' => 'city'], ['value' => 'Hamburg']);
        $this->stubContactCreation('DRA-H2');
        $second = new Service(['domain' => 'two.de'], $user);
        $extension->createServer($second, [], $second->props());

        $this->assertCount(2, $this->api->find('POST', '/api/v1/contacts/'));
        $this->assertSame('DRA-H2', $second->props()['dra_owner_handle']);
    }

    public function test_incomplete_profile_fails_with_readable_message(): void
    {
        $service = new Service(['domain' => 'example.de'], new User([]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/street address/');
        $this->extension()->createServer($service, [], $service->props());
    }

    public function test_register_with_nameserver_modes(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => ['domainId' => 'd', 'status' => 'active']]);
        $extension = $this->extension();

        $service = $this->service(['domain' => 'a.de', 'ns1' => 'ns1.customer.net', 'ns2' => 'NS2.customer.net', 'ns3' => '']);
        $extension->createServer($service, ['nameserver_mode' => 'customer', 'default_nameservers' => ['ns1.default.de', 'ns2.default.de']], $service->props());
        $this->assertSame(['ns1.customer.net', 'ns2.customer.net'], $this->api->last()['body']['nameservers']);

        $service = $this->service(['domain' => 'b.de', 'ns1' => 'ns1.customer.net']);
        $extension->createServer($service, ['nameserver_mode' => 'customer', 'default_nameservers' => '["ns1.default.de","ns2.default.de"]'], $service->props());
        $this->assertSame(['ns1.default.de', 'ns2.default.de'], $this->api->last()['body']['nameservers'], 'Falls back to defaults with fewer than two customer nameservers');

        $service = $this->service(['domain' => 'c.de', 'ns1' => 'ns1.customer.net', 'ns2' => 'ns2.customer.net']);
        $extension->createServer($service, ['nameserver_mode' => 'managed'], $service->props());
        $this->assertArrayNotHasKey('nameservers', $this->api->last()['body']);
    }

    public function test_whois_privacy_only_for_persons(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => ['domainId' => 'd', 'status' => 'active']]);

        $service = $this->service(['domain' => 'a.com', 'whois_privacy' => 'yes']);
        $this->extension()->createServer($service, ['whois_privacy' => 'optional'], $service->props());
        $this->assertTrue($this->api->last()['body']['whoisPrivacy']);

        $user = new User(['company_name' => 'ACME GmbH', 'address' => 'Weg 1', 'city' => 'Köln', 'zip' => '50667', 'country' => 'DE', 'phone' => '0221 123456']);
        $service = new Service(['domain' => 'b.com'], $user);
        $this->extension()->createServer($service, ['whois_privacy' => 'always'], $service->props());
        $this->assertArrayNotHasKey('whoisPrivacy', $this->api->last()['body']);
    }

    public function test_transfer_sends_auth_code_and_forgets_it(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/transfer', ['success' => true, 'data' => [
            'domainId' => 'dom_t', 'domain' => 'example.com', 'status' => 'pending_transfer', 'message' => 'Transfer initiated.',
        ]]);

        $service = $this->service(['domain' => 'example.com', 'action' => 'transfer', 'auth_code' => ' S3CR3T ']);
        $result = $this->extension()->createServer($service, [], $service->props());

        $this->assertSame(['domain' => 'example.com', 'authCode' => 'S3CR3T', 'contacts' => ['owner' => 'DRA-H1']], $this->api->last()['body']);
        $this->assertSame('pending_transfer', $result['status']);
        $this->assertArrayNotHasKey('auth_code', $service->props());
        $this->assertSame('pending_transfer', $service->props()['dra_status']);
    }

    public function test_transfer_without_auth_code_fails(): void
    {
        $this->stubContactCreation();
        $service = $this->service(['domain' => 'example.com', 'action' => 'transfer', 'auth_code' => '']);

        $this->expectExceptionMessageMatches('/auth code is required/');
        $this->extension()->createServer($service, [], $service->props());
    }

    public function test_conflict_adopts_domain_of_same_customer(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Diese Domain ist bereits in einem Konto registriert.'], 409);
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => [
            'id' => 'dom_existing', 'name' => 'example.de', 'status' => 'active', 'expiresAt' => '2027-01-01T00:00:00Z', 'contacts' => ['owner' => 'DRA-H1'],
        ]]);

        $service = $this->service();
        $this->extension()->createServer($service, [], $service->props());

        $this->assertSame('dom_existing', $service->props()['dra_domain_id']);
    }

    public function test_conflict_never_adopts_foreign_domain(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Already registered'], 409);
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => [
            'id' => 'dom_existing', 'status' => 'active', 'contacts' => ['owner' => 'SOMEONE-ELSE'],
        ]]);

        $service = $this->service();
        try {
            $this->extension()->createServer($service, [], $service->props());
            $this->fail('Expected exception');
        } catch (ApiException $e) {
            $this->assertTrue($e->isConflict());
        }
        $this->assertArrayNotHasKey('dra_domain_id', $service->props());
    }

    public function test_other_api_errors_are_not_swallowed(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Insufficient wallet balance'], 402);

        $service = $this->service();
        $this->expectExceptionMessage('Insufficient wallet balance');
        $this->extension()->createServer($service, [], $service->props());
    }

    public function test_rejects_disallowed_tld_and_duplicates(): void
    {
        $service = $this->service(['domain' => 'example.net']);
        try {
            $this->extension()->createServer($service, ['tlds' => ['de']], $service->props());
            $this->fail('Expected exception');
        } catch (Exception $e) {
            $this->assertStringContainsString('not allowed', $e->getMessage());
        }

        $extension = $this->extension();
        $extension->managedElsewhere = true;
        $service = $this->service();
        $this->expectExceptionMessageMatches('/already managed/');
        $extension->createServer($service, [], $service->props());
    }

    public function test_create_is_idempotent_and_reactivates(): void
    {
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => [
            'name' => 'example.de', 'status' => 'pending_delete', 'autoRenew' => false, 'expiresAt' => '2027-01-01T00:00:00Z',
            'stateInfo' => ['canCancelDelete' => true, 'canRestore' => false],
        ]]);
        $this->api->on('POST', '/api/v1/domains/example.de/cancel-delete', ['success' => true]);
        $this->api->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);

        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1']);
        $result = $this->extension()->createServer($service, [], $service->props());

        $this->assertSame('active', $result['status']);
        $this->assertCount(0, $this->api->find('POST', '/api/v1/domains/register'));
        $this->assertCount(1, $this->api->find('POST', '/api/v1/domains/example.de/cancel-delete'));
        $this->assertSame(['autoRenew' => true], $this->api->find('PATCH', '/api/v1/domains/example.de')[0]['body']);
    }

    // ── Suspend / unsuspend / terminate ──────────

    public function test_suspend_disables_auto_renew(): void
    {
        $this->api->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);
        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1']);

        $this->extension()->suspendServer($service, [], $service->props());

        $this->assertSame(['autoRenew' => false], $this->api->last()['body']);
        $this->assertSame('0', $service->props()['dra_auto_renew']);
    }

    public function test_suspend_can_be_a_noop(): void
    {
        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1']);
        $this->extension(['on_suspend' => 'nothing'])->suspendServer($service, [], $service->props());

        $unprovisioned = $this->service();
        $this->extension()->suspendServer($unprovisioned, [], $unprovisioned->props());

        $this->assertSame([], $this->api->requests);
    }

    public function test_unsuspend_refuses_unwanted_restore(): void
    {
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => ['status' => 'redemption', 'autoRenew' => false, 'stateInfo' => ['canRestore' => true]]]);
        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1']);

        $this->expectExceptionMessageMatches('/redemption/');
        $this->extension()->unsuspendServer($service, [], $service->props());
    }

    public function test_unsuspend_restores_when_enabled(): void
    {
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => ['status' => 'redemption', 'autoRenew' => false, 'stateInfo' => ['canRestore' => true]]]);
        $this->api->on('POST', '/api/v1/domains/example.de/restore', ['success' => true]);
        $this->api->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);
        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1', 'dra_autorenew_guarded' => '1']);

        $this->extension(['restore_on_unsuspend' => true])->unsuspendServer($service, [], $service->props());

        $this->assertCount(1, $this->api->find('POST', '/api/v1/domains/example.de/restore'));
        $this->assertSame(['autoRenew' => true], $this->api->last()['body']);
        $this->assertSame('active', $service->props()['dra_status']);
        $this->assertArrayNotHasKey('dra_autorenew_guarded', $service->props());
    }

    public function test_terminate_modes(): void
    {
        $this->api->on('DELETE', '/api/v1/domains/example.de', ['success' => true]);
        $this->api->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);
        $props = ['domain' => 'example.de', 'dra_domain_id' => 'dom_1'];

        $service = $this->service($props);
        $this->extension()->terminateServer($service, [], $service->props());
        $this->assertSame([], $this->api->last()['query']);
        $this->assertSame('pending_delete', $service->props()['dra_status']);

        $service = $this->service($props);
        $this->extension(['on_terminate' => 'delete'])->terminateServer($service, [], $service->props());
        $this->assertSame(['immediate' => 'true'], $this->api->last()['query']);
        $this->assertSame('deleted', $service->props()['dra_status']);

        $service = $this->service($props);
        $this->extension(['on_terminate' => 'disable_autorenew'])->terminateServer($service, [], $service->props());
        $this->assertSame(['autoRenew' => false], $this->api->last()['body']);

        $count = count($this->api->requests);
        $service = $this->service($props);
        $this->extension(['on_terminate' => 'nothing'])->terminateServer($service, [], $service->props());
        $this->assertCount($count, $this->api->requests);
    }

    public function test_terminate_tolerates_missing_domain(): void
    {
        $this->api->on('DELETE', '/api/v1/domains/example.de', ['success' => false, 'error' => 'Domain not found'], 404);
        $service = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'dom_1']);

        $this->extension()->terminateServer($service, [], $service->props());

        $this->assertSame('deleted', $service->props()['dra_status']);
    }

    // ── Client area ──────────────────────────────

    public function test_actions_before_provisioning(): void
    {
        $actions = $this->extension()->getActions($this->service(['domain' => 'xn--mller-kva.de']), [], ['domain' => 'xn--mller-kva.de']);

        $this->assertSame('müller.de', $actions[0]['text']);
        $this->assertSame($this->message('status.provisioning'), $actions[1]['text']);
        $this->assertCount(2, $actions);
    }

    public function test_actions_for_active_domain(): void
    {
        $props = ['domain' => 'example.de', 'dra_domain_id' => 'd', 'dra_status' => 'active', 'dra_expires_at' => '2027-01-01'];
        $actions = $this->extension()->getActions($this->service($props), ['feature_dns' => '0'], $props);

        $views = array_column(array_filter($actions, fn ($a) => $a['type'] === 'view'), 'name');
        $this->assertSame(['overview', 'nameservers', 'contacts', 'dnssec', 'transfer'], $views);
        $this->assertSame('2027-01-01', $actions[2]['text']);
    }

    public function test_actions_for_pending_transfer_only_show_overview(): void
    {
        $props = ['domain' => 'example.de', 'dra_domain_id' => 'd', 'dra_status' => 'pending_transfer'];
        $actions = $this->extension()->getActions($this->service($props), [], $props);

        $this->assertSame(['overview'], array_column(array_filter($actions, fn ($a) => $a['type'] === 'view'), 'name'));
    }

    public function test_client_without_api_key_fails_clearly(): void
    {
        $this->expectException(ApiException::class);
        (new DomainResellerApi([]))->client();
    }

    public function test_status_label_falls_back_to_readable_text(): void
    {
        $this->assertSame('Something new', $this->extension()->statusLabel('something_new'));
    }

    public function test_contact_mapper_is_used_for_profile(): void
    {
        $profile = $this->extension()->customerProfile((new Service)->user);

        $this->assertSame('Erika', $profile['first_name']);
        $this->assertSame('Berlin', $profile['city']);
        $this->assertSame('person', (new ContactMapper(config('app.countries')))->map($profile)['type']);
    }

    // ── Checks before payment ────────────────────

    private function liveExtension(array $config = []): TestableExtension
    {
        return $this->extension(array_merge(['skip_wallet_check' => false], $config));
    }

    private function stubCheck(string $domain, bool $available = true, array $extra = []): void
    {
        $this->api->on('GET', '/api/v1/domains/check/' . $domain, ['success' => true, 'data' => array_merge([
            'domain' => $domain, 'available' => $available, 'premium' => false, 'price' => 10.0, 'setupPrice' => null,
            'transferPrice' => 8.0, 'renewPrice' => 10.0, 'vatPercent' => 19, 'reverseCharge' => false,
        ], $extra)]);
    }

    public function test_incomplete_profile_is_rejected_at_checkout(): void
    {
        $extension = $this->extension();
        $extension->user = new User([]);

        $this->assertSame($this->message('checkout.error_profile'), $extension->validateOrderableDomain('example.de', [], 'register'));
        $this->assertSame([], $this->api->requests, 'No API call when the profile is incomplete');

        $extension->user = (new Service)->user;
        $this->stubCheck('example.de');
        $this->assertNull($extension->validateOrderableDomain('example.de', [], 'register'));
    }

    public function test_wallet_too_low_refuses_order_and_alerts_admins(): void
    {
        $this->stubCheck('example.de');
        $this->api->on('GET', '/api/v1/billing/wallet', ['success' => true, 'data' => ['balance' => 5.0, 'currency' => 'EUR', 'allowNegativeBalance' => false, 'postpaid' => false, 'creditLimit' => 0]]);
        $this->api->on('GET', '/api/v1/billing/auto-topup', ['success' => true, 'data' => ['enabled' => false, 'threshold' => 0, 'amount' => 0]]);
        $extension = $this->liveExtension();

        $this->assertSame($this->message('checkout.error_temporarily_unavailable'), $extension->validateOrderableDomain('example.de', [], 'register'));
        $this->assertCount(1, $extension->notifications->admin);
        $this->assertStringContainsString('11.90 EUR', $extension->notifications->admin[0]['body']);
        $this->assertSame('wallet-checkout', $extension->notifications->admin[0]['dedupeKey']);
    }

    public function test_wallet_with_enough_balance_or_auto_topup_passes(): void
    {
        $this->stubCheck('example.de');
        $this->api->on('GET', '/api/v1/billing/wallet', ['success' => true, 'data' => ['balance' => 50.0, 'currency' => 'EUR', 'allowNegativeBalance' => false, 'postpaid' => false, 'creditLimit' => 0]]);
        $this->assertNull($this->liveExtension()->validateOrderableDomain('example.de', [], 'register'));

        $this->api->on('GET', '/api/v1/billing/wallet', ['success' => true, 'data' => ['balance' => 0.0, 'currency' => 'EUR', 'allowNegativeBalance' => false, 'postpaid' => false, 'creditLimit' => 0]]);
        $this->api->on('GET', '/api/v1/billing/auto-topup', ['success' => true, 'data' => ['enabled' => true, 'threshold' => 10, 'amount' => 50]]);
        $this->assertNull($this->liveExtension()->validateOrderableDomain('example.de', [], 'register'));
    }

    public function test_wallet_check_failure_does_not_block_orders(): void
    {
        $this->stubCheck('example.de');
        $this->api->on('GET', '/api/v1/billing/wallet', ['success' => false, 'error' => 'Forbidden'], 403);
        $GLOBALS['reported'] = [];

        $this->assertNull($this->liveExtension()->validateOrderableDomain('example.de', [], 'register'));
        $this->assertCount(1, $GLOBALS['reported']);
    }

    public function test_wallet_check_can_be_disabled_and_is_skipped_in_sandbox(): void
    {
        $this->stubCheck('example.de');
        $this->assertNull($this->extension(['skip_wallet_check' => true])->validateOrderableDomain('example.de', [], 'register'));

        $sandbox = $this->liveExtension(['sandbox' => true]);
        $sandbox->setClient(new ApiClient('k', 'https://api.example.test', true, 5, $this->api));
        $this->assertNull($sandbox->validateOrderableDomain('example.de', [], 'register'));
        $this->assertCount(0, $this->api->find('GET', '/api/v1/billing/wallet'));
    }

    public function test_taken_domain_lists_alternatives_of_the_product(): void
    {
        $this->stubCheck('taken.de', false);
        $this->api->on('GET', '/api/v1/domains/suggest', ['success' => true, 'data' => ['suggestions' => [
            ['domain' => 'taken.com', 'zone' => 'com'], ['domain' => 'taken.net', 'zone' => 'net'], ['domain' => 'taken.de', 'zone' => 'de'],
        ]]]);

        $error = $this->extension()->validateOrderableDomain('taken.de', ['tlds' => ['de', 'com']], 'register');

        $this->assertSame($this->message('checkout.error_unavailable') . ' ' . $this->message('checkout.suggestions'), $error);
        $this->assertSame(['q' => 'taken', 'defaults' => 'de,com'], $this->api->last()['query']);
        $this->assertSame(['de', 'com'], DomainName::normalizeTlds(['de', 'com']));
    }

    public function test_suggestion_text_filters_to_allowed_tlds(): void
    {
        $this->api->on('GET', '/api/v1/domains/suggest', ['success' => true, 'data' => ['suggestions' => [
            ['domain' => 'taken.com', 'zone' => 'com'], ['domain' => 'taken.net', 'zone' => 'net'], ['domain' => 'taken.de', 'zone' => 'de'],
        ]]]);

        // trans() returns the key in tests, so check the filtered list through a subclass-free call:
        $extension = new class(['api_key' => 'k']) extends TestableExtension
        {
            public function trans(string $key, array $replace = []): string
            {
                return $key . ':' . ($replace['domains'] ?? '');
            }
        };
        $extension->setClient(new ApiClient('k', 'https://api.example.test', false, 5, $this->api));

        $this->assertSame(' checkout.suggestions:taken.com', $extension->suggestionText('taken.de', ['de', 'com']));
        $this->assertSame(' checkout.suggestions:taken.com, taken.net', $extension->suggestionText('taken.de', []));
    }

    // ── Notifications, expiry alignment ──────────

    public function test_failed_provisioning_alerts_admins(): void
    {
        $this->stubContactCreation();
        $this->api->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Insufficient wallet balance'], 402);
        $extension = $this->extension();
        $service = $this->service();

        try {
            $extension->createServer($service, [], $service->props());
            $this->fail('Expected exception');
        } catch (ApiException) {
        }

        $this->assertSame('Domain provisioning failed', $extension->notifications->admin[0]['title']);
        $this->assertStringContainsString('Insufficient wallet balance', $extension->notifications->admin[0]['body']);
    }

    private function yearlyService(array $properties = []): Service
    {
        $service = $this->service($properties ?: ['domain' => 'example.de', 'dra_domain_id' => 'd', 'dra_status' => 'active']);
        $service->plan = (object) ['type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 1];
        $service->expires_at = new \DateTimeImmutable('2027-01-15');

        return $service;
    }

    public function test_due_date_follows_registry_expiry_for_yearly_plans(): void
    {
        $service = $this->yearlyService();
        $this->extension()->alignServiceExpiry($service, '2027-03-01');
        $this->assertSame('2027-03-01', $service->expires_at->format('Y-m-d'));
        $this->assertSame(1, $service->saved);

        $this->extension()->alignServiceExpiry($service, '2027-03-01');
        $this->assertSame(1, $service->saved, 'No save without change');
    }

    public function test_due_date_is_left_alone_otherwise(): void
    {
        $monthly = $this->yearlyService();
        $monthly->plan = (object) ['type' => 'recurring', 'billing_unit' => 'month', 'billing_period' => 12];
        $this->extension()->alignServiceExpiry($monthly, '2027-03-01');

        $twoYears = $this->yearlyService();
        $twoYears->plan = (object) ['type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => 2];
        $this->extension()->alignServiceExpiry($twoYears, '2027-03-01');

        $pending = $this->yearlyService();
        $pending->status = Service::STATUS_PENDING;
        $this->extension()->alignServiceExpiry($pending, '2027-03-01');

        $disabled = $this->yearlyService();
        $this->extension(['keep_due_date' => true])->alignServiceExpiry($disabled, '2027-03-01');

        foreach ([$monthly, $twoYears, $pending, $disabled] as $service) {
            $this->assertSame('2027-01-15', $service->expires_at->format('Y-m-d'));
            $this->assertSame(0, $service->saved);
        }
    }

    public function test_remember_domain_info_aligns_due_date(): void
    {
        $service = $this->yearlyService();
        $this->extension()->rememberDomainInfo($service, ['status' => 'active', 'expiresAt' => '2027-06-30T10:00:00Z', 'autoRenew' => true, 'nameservers' => ['ns1.a.de', 'ns2.a.de']]);

        $this->assertSame('2027-06-30', $service->expires_at->format('Y-m-d'));
        $this->assertSame('ns1.a.de, ns2.a.de', $service->props()['dra_nameservers']);
    }

    public function test_status_changes_notify_customer_and_admins(): void
    {
        $extension = $this->extension();

        $transfer = $this->service(['domain' => 'example.com', 'dra_status' => 'pending_transfer']);
        $extension->updateStatus($transfer, 'active');
        $this->assertSame($this->message('notifications.transfer_completed_title'), $extension->notifications->customer[0]['title']);
        $this->assertCount(0, $extension->notifications->admin);

        $failed = $this->service(['domain' => 'example.com', 'dra_status' => 'pending_transfer']);
        $extension->updateStatus($failed, 'transfer_failed');
        $this->assertSame($this->message('notifications.transfer_failed_title'), $extension->notifications->customer[1]['title']);
        $this->assertSame('Domain transfer failed', $extension->notifications->admin[0]['title']);

        $expired = $this->service(['domain' => 'example.com', 'dra_status' => 'active']);
        $extension->updateStatus($expired, 'redemption');
        $this->assertSame('Domain status changed: redemption', $extension->notifications->admin[1]['title']);
        $this->assertSame('redemption', $expired->props()['dra_status']);
    }

    public function test_status_without_change_or_first_status_is_silent(): void
    {
        $extension = $this->extension();
        $extension->updateStatus($this->service(['domain' => 'a.de', 'dra_status' => 'active']), 'active');
        $extension->updateStatus($this->service(['domain' => 'a.de']), 'pending_transfer');

        $suspended = $this->service(['domain' => 'a.de', 'dra_status' => 'active']);
        $suspended->status = Service::STATUS_SUSPENDED;
        $extension->updateStatus($suspended, 'expired');

        $this->assertSame([], $extension->notifications->admin);
        $this->assertSame([], $extension->notifications->customer);
    }

    // ── Webhook registration ─────────────────────

    public function test_registers_webhook_and_stores_secret(): void
    {
        $url = 'https://shop.example.com/extensions/domain-reseller-api/webhook';
        $this->api->on('GET', '/api/v1/webhooks/', ['success' => true, 'data' => [['id' => 'old', 'url' => $url, 'type' => 'custom', 'events' => [], 'isActive' => true], ['id' => 'other', 'url' => 'https://elsewhere.test']]]);
        $this->api->on('DELETE', '/api/v1/webhooks/old', ['success' => true]);
        $this->api->on('POST', '/api/v1/webhooks/', ['success' => true, 'data' => ['id' => 'new', 'url' => $url, 'secret' => 'S3CRET']]);
        $server = new FakeServer;

        $secret = $this->extension()->registerWebhook($server);

        $this->assertSame('S3CRET', $secret);
        $this->assertSame('S3CRET', $server->settings()->toArray()['webhook_secret']);
        $this->assertCount(1, $this->api->find('DELETE', '/api/v1/webhooks/old'));
        $this->assertCount(0, $this->api->find('DELETE', '/api/v1/webhooks/other'));
        $body = $this->api->find('POST', '/api/v1/webhooks/')[0]['body'];
        $this->assertSame($url, $body['url']);
        $this->assertSame('custom', $body['type']);
        $this->assertContains('domain.transferred', $body['events']);
    }

    public function test_webhook_registration_is_skipped_or_fails_softly(): void
    {
        $this->assertNull($this->extension(['webhook_secret' => 'existing'])->registerWebhook(new FakeServer));
        $this->assertNull($this->extension(['manual_webhook' => true])->registerWebhook(new FakeServer));
        $this->assertNull($this->extension()->registerWebhook(null));
        $this->assertSame([], $this->api->requests);

        $this->api->on('GET', '/api/v1/webhooks/', ['success' => true, 'data' => []]);
        $this->api->on('POST', '/api/v1/webhooks/', ['success' => false, 'error' => 'Webhook URL must be a public address']);
        $server = new FakeServer;
        $this->assertNull($this->extension()->registerWebhook($server));
        $this->assertSame([], $server->settings()->toArray());

        // "force" ignores an existing secret
        $this->api->on('POST', '/api/v1/webhooks/', ['success' => true, 'data' => ['secret' => 'NEW']]);
        $this->assertSame('NEW', $this->extension(['webhook_secret' => 'old'])->registerWebhook($server, true));
    }
}
