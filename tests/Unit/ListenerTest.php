<?php

namespace Tests\Unit;

use App\Events\Invoice\Paid;
use App\Models\Product;
use App\Models\Service;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Listeners\ResumeAutoRenewOnPayment;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeServer;
use Tests\Support\FakeTransport;
use Tests\Support\TestableExtension;

class ListenerTest extends TestCase
{
    private FakeTransport $api;

    protected function setUp(): void
    {
        $this->api = new FakeTransport;
        DomainResellerApi::resolveUsing(function () {
            return (new TestableExtension(['api_key' => 'k']))->setClient(new ApiClient('k', 'https://api.example.test', false, 5, $this->api));
        });
    }

    protected function tearDown(): void
    {
        DomainResellerApi::resolveUsing(null);
    }

    private function service(array $properties, string $extension = 'DomainResellerApi'): Service
    {
        $service = new Service($properties);
        $service->product = new Product;
        $service->product->server = new FakeServer;
        $service->product->server->extension = $extension;

        return $service;
    }

    private function paid(Service ...$services): Paid
    {
        $items = array_map(fn ($s) => (object) ['reference_type' => Service::class, 'reference' => $s], $services);
        $items[] = (object) ['reference_type' => 'App\Models\Credit', 'reference' => null];

        return new Paid((object) ['items' => $items]);
    }

    public function test_resumes_auto_renew_of_guarded_domains(): void
    {
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => ['status' => 'active', 'autoRenew' => false]]);
        $this->api->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);
        $guarded = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'd', 'dra_autorenew_guarded' => '1']);

        (new ResumeAutoRenewOnPayment)->handle($this->paid($guarded));

        $this->assertSame(['autoRenew' => true], $this->api->find('PATCH', '/api/v1/domains/example.de')[0]['body']);
        $this->assertArrayNotHasKey('dra_autorenew_guarded', $guarded->props());
        $this->assertSame('1', $guarded->props()['dra_auto_renew']);
    }

    public function test_ignores_unguarded_and_foreign_services(): void
    {
        $plain = $this->service(['domain' => 'a.de', 'dra_domain_id' => 'd']);
        $foreign = $this->service(['domain' => 'b.de', 'dra_autorenew_guarded' => '1'], 'Pterodactyl');

        (new ResumeAutoRenewOnPayment)->handle($this->paid($plain, $foreign));

        $this->assertSame([], $this->api->requests);
    }

    public function test_api_errors_are_reported_not_thrown(): void
    {
        $this->api->on('GET', '/api/v1/domains/example.de', ['success' => false, 'error' => 'boom'], 500);
        $guarded = $this->service(['domain' => 'example.de', 'dra_domain_id' => 'd', 'dra_autorenew_guarded' => '1']);
        $GLOBALS['reported'] = [];

        (new ResumeAutoRenewOnPayment)->handle($this->paid($guarded));

        $this->assertCount(1, $GLOBALS['reported']);
        $this->assertSame('1', $guarded->props()['dra_autorenew_guarded']);
    }
}
