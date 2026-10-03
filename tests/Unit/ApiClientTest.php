<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\FakeTransport;

class ApiClientTest extends TestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
    }

    private function client(bool $sandbox = false): ApiClient
    {
        return new ApiClient('drapi_test_key', 'https://api.example.test/', $sandbox, 15, $this->transport);
    }

    public function test_sends_auth_headers(): void
    {
        $this->transport->on('GET', '/api/v1/domains/example.de', ['success' => true, 'data' => ['name' => 'example.de']]);

        $result = $this->client()->getDomain('example.de');

        $this->assertSame('example.de', $result['data']['name']);
        $request = $this->transport->last();
        $this->assertSame('https://api.example.test/api/v1/domains/example.de', $request['url']);
        $this->assertSame('Bearer drapi_test_key', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Accept']);
        $this->assertArrayNotHasKey('x-sandbox', $request['headers']);
        $this->assertArrayNotHasKey('Content-Type', $request['headers']);
    }

    public function test_sandbox_header(): void
    {
        $this->transport->on('GET', '/api/v1/domains/', ['success' => true, 'data' => ['domains' => []]]);

        $this->client(true)->listDomains(2, 50, 'foo');

        $request = $this->transport->last();
        $this->assertSame('true', $request['headers']['x-sandbox']);
        $this->assertSame(['page' => '2', 'pageSize' => '50', 'search' => 'foo'], $request['query']);
    }

    public function test_register_payload(): void
    {
        $this->transport->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => ['domainId' => 'd1']]);

        $this->client()->registerDomain('example.de', ['owner' => 'H1', 'admin' => null, 'tech' => 'H2'], ['ns1.example.com', 'ns2.example.com'], 2, true);

        $this->assertSame([
            'domain' => 'example.de',
            'years' => 2,
            'contacts' => ['owner' => 'H1', 'tech' => 'H2'],
            'nameservers' => ['ns1.example.com', 'ns2.example.com'],
            'whoisPrivacy' => true,
            'acceptWppTerms' => true,
        ], $this->transport->last()['body']);
        $this->assertSame('application/json', $this->transport->last()['headers']['Content-Type']);
    }

    public function test_register_without_nameservers_uses_managed_dns(): void
    {
        $this->transport->on('POST', '/api/v1/domains/register', ['success' => true, 'data' => []]);

        $this->client()->registerDomain('example.de', ['owner' => 'H1']);

        $body = $this->transport->last()['body'];
        $this->assertArrayNotHasKey('nameservers', $body);
        $this->assertArrayNotHasKey('whoisPrivacy', $body);
        $this->assertSame(1, $body['years']);
    }

    public function test_transfer_payload(): void
    {
        $this->transport->on('POST', '/api/v1/domains/transfer', ['success' => true, 'data' => ['status' => 'pending_transfer']]);

        $this->client()->transferDomain('example.com', 'AUTH-123', ['owner' => 'H1']);

        $this->assertSame(['domain' => 'example.com', 'authCode' => 'AUTH-123', 'contacts' => ['owner' => 'H1']], $this->transport->last()['body']);
    }

    public function test_endpoints(): void
    {
        $ok = ['success' => true];
        $calls = [
            ['GET', '/api/v1/domains/check/example.de', fn (ApiClient $c) => $c->checkDomain('example.de')],
            ['POST', '/api/v1/domains/check', fn (ApiClient $c) => $c->checkDomains(['a.de', 'b.de'])],
            ['GET', '/api/v1/domains/suggest', fn (ApiClient $c) => $c->suggestDomains('foo', ['de'])],
            ['PATCH', '/api/v1/domains/example.de', fn (ApiClient $c) => $c->setAutoRenew('example.de', false)],
            ['PATCH', '/api/v1/domains/example.de/contacts', fn (ApiClient $c) => $c->updateDomainContacts('example.de', ['admin' => 'H2'])],
            ['GET', '/api/v1/domains/example.de/state', fn (ApiClient $c) => $c->getDomainState('example.de')],
            ['GET', '/api/v1/domains/example.de/dnssec', fn (ApiClient $c) => $c->getDnssec('example.de')],
            ['PUT', '/api/v1/domains/example.de/dnssec', fn (ApiClient $c) => $c->updateDnssec('example.de', true, ['257 3 13 AAA'])],
            ['GET', '/api/v1/domains/example.de/authcode', fn (ApiClient $c) => $c->getAuthCode('example.de')],
            ['POST', '/api/v1/domains/example.de/reset-authcode', fn (ApiClient $c) => $c->resetAuthCode('example.de')],
            ['DELETE', '/api/v1/domains/example.de', fn (ApiClient $c) => $c->deleteDomain('example.de')],
            ['POST', '/api/v1/domains/example.de/restore', fn (ApiClient $c) => $c->restoreDomain('example.de')],
            ['POST', '/api/v1/domains/example.de/cancel-delete', fn (ApiClient $c) => $c->cancelDelete('example.de')],
            ['GET', '/api/v1/contacts/H1', fn (ApiClient $c) => $c->getContact('H1')],
            ['POST', '/api/v1/contacts/', fn (ApiClient $c) => $c->createContact(['firstName' => 'A'])],
            ['PATCH', '/api/v1/contacts/H1', fn (ApiClient $c) => $c->updateContact('H1', ['city' => 'B'])],
            ['POST', '/api/v1/dns/example.de/enable', fn (ApiClient $c) => $c->enableManagedDns('example.de')],
            ['GET', '/api/v1/dns/example.de', fn (ApiClient $c) => $c->getDnsRecords('example.de')],
            ['POST', '/api/v1/dns/example.de/records', fn (ApiClient $c) => $c->createDnsRecord('example.de', 'MX', '@', 'mx.example.de', 3600, 10)],
            ['PATCH', '/api/v1/dns/example.de/records/r1', fn (ApiClient $c) => $c->updateDnsRecord('example.de', 'r1', ['content' => '1.2.3.4'])],
            ['DELETE', '/api/v1/dns/example.de/records/r1', fn (ApiClient $c) => $c->deleteDnsRecord('example.de', 'r1')],
            ['GET', '/api/v1/pricing/', fn (ApiClient $c) => $c->pricing('.de')],
        ];

        foreach ($calls as [$method, $path, $call]) {
            $this->transport->on($method, $path, $ok);
            $call($this->client());
            $request = $this->transport->last();
            $this->assertSame([$method, $path], [$request['method'], $request['path']]);
        }

        $this->assertSame(['tld' => 'de'], $this->transport->find('GET', '/api/v1/pricing/')[0]['query']);
        $this->assertSame(['q' => 'foo', 'defaults' => 'de'], $this->transport->find('GET', '/api/v1/domains/suggest')[0]['query']);
        $this->assertSame(['type' => 'MX', 'name' => '@', 'content' => 'mx.example.de', 'ttl' => 3600, 'priority' => 10], $this->transport->find('POST', '/api/v1/dns/example.de/records')[0]['body']);
        $this->assertSame(['enabled' => true, 'dnskeys' => ['257 3 13 AAA']], $this->transport->find('PUT', '/api/v1/domains/example.de/dnssec')[0]['body']);
    }

    public function test_leaving_managed_dns_sends_the_flag(): void
    {
        $this->transport->on('PATCH', '/api/v1/domains/example.de', ['success' => true]);

        $this->client()->setNameservers('example.de', ['ns1.example.com', 'ns2.example.com']);
        $this->assertSame(['nameservers' => ['ns1.example.com', 'ns2.example.com']], $this->transport->last()['body']);

        $this->client()->setNameservers('example.de', ['ns1.example.com', 'ns2.example.com'], true);
        $this->assertSame(['nameservers' => ['ns1.example.com', 'ns2.example.com'], 'useManagedDns' => false], $this->transport->last()['body']);
    }

    public function test_immediate_delete(): void
    {
        $this->transport->on('DELETE', '/api/v1/domains/example.de', ['success' => true]);

        $this->client()->deleteDomain('example.de', true);

        $this->assertSame(['immediate' => 'true'], $this->transport->last()['query']);
    }

    public function test_owner_change_confirmation_flag(): void
    {
        $this->transport->on('PATCH', '/api/v1/domains/example.de/contacts', ['success' => true]);

        $this->client()->updateDomainContacts('example.de', ['owner' => 'H9'], true);

        $this->assertSame(['contacts' => ['owner' => 'H9'], 'confirmOwnerChange' => true], $this->transport->last()['body']);
    }

    public function test_encodes_path_segments(): void
    {
        $this->transport->on('GET', '/api/v1/contacts/A%2FB%20C', ['success' => true]);

        $this->client()->getContact('A/B C');

        $this->assertSame('https://api.example.test/api/v1/contacts/A%2FB%20C', $this->transport->last()['url']);
    }

    public function test_error_status_throws(): void
    {
        $this->transport->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Insufficient wallet balance'], 402);

        try {
            $this->client()->registerDomain('example.de', ['owner' => 'H1']);
            $this->fail('Expected exception');
        } catch (ApiException $e) {
            $this->assertSame('Insufficient wallet balance', $e->getMessage());
            $this->assertSame(402, $e->status());
            $this->assertFalse($e->isConflict());
        }
    }

    public function test_success_false_with_status_200_throws(): void
    {
        $this->transport->on('GET', '/api/v1/contacts/H1', ['success' => false, 'error' => 'Contact not found']);

        try {
            $this->client()->getContact('H1');
            $this->fail('Expected exception');
        } catch (ApiException $e) {
            $this->assertTrue($e->isNotFound());
        }
    }

    public function test_conflict(): void
    {
        $this->transport->on('POST', '/api/v1/domains/register', ['success' => false, 'error' => 'Diese Domain ist bereits in einem Konto registriert.'], 409);

        try {
            $this->client()->registerDomain('example.de', ['owner' => 'H1']);
            $this->fail('Expected exception');
        } catch (ApiException $e) {
            $this->assertTrue($e->isConflict());
            $this->assertSame(['success' => false, 'error' => 'Diese Domain ist bereits in einem Konto registriert.'], $e->response());
        }
    }

    public function test_invalid_json_throws(): void
    {
        $this->transport->on('GET', '/api/v1/domains/example.de', '<html>Bad Gateway</html>', 502);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessageMatches('/HTTP 502/');
        $this->client()->getDomain('example.de');
    }

    public function test_connection_errors_are_wrapped(): void
    {
        $client = new ApiClient('key', 'https://api.example.test', false, 5, function () {
            throw new RuntimeException('Connection refused');
        });

        try {
            $client->health();
            $this->fail('Expected exception');
        } catch (ApiException $e) {
            $this->assertTrue($e->isConnectionError());
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }
    }

    public function test_requires_https(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApiClient('key', 'http://domain-reseller-api.de');
    }

    public function test_allows_local_http_for_development(): void
    {
        $client = new ApiClient('key', 'http://localhost:3000', false, 5, $this->transport);

        $this->assertSame('http://localhost:3000', $client->baseUrl());
    }

    public function test_empty_base_url_falls_back_to_default(): void
    {
        $this->assertSame(ApiClient::DEFAULT_BASE_URL, (new ApiClient('key', '', false, 5, $this->transport))->baseUrl());
    }
}
