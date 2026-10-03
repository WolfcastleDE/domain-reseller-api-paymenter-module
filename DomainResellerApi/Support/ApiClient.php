<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Thin HTTP client for the Domain Reseller API (https://domain-reseller-api.de/swagger).
 *
 * Every method returns the decoded JSON body of a successful response and
 * throws an {@see ApiException} otherwise. The API signals errors either with
 * a non-2xx status or with `{"success": false, "error": "..."}` — both cases
 * are normalised here.
 */
class ApiClient
{
    public const DEFAULT_BASE_URL = 'https://domain-reseller-api.de';

    public const USER_AGENT = 'Paymenter-DomainResellerApi/1.0';

    private string $baseUrl;

    /** @var callable(string, string, array, ?array, int): array{status: int, body: string} */
    private $transport;

    /**
     * @param  callable|null  $transport  Optional transport for tests:
     *                                    fn(string $method, string $url, array $headers, ?array $body, int $timeout): array{status: int, body: string}
     */
    public function __construct(
        private readonly string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        private readonly bool $sandbox = false,
        private readonly int $timeout = 30,
        ?callable $transport = null,
    ) {
        $baseUrl = rtrim(trim($baseUrl) ?: self::DEFAULT_BASE_URL, '/');

        if (!str_starts_with($baseUrl, 'https://') && !preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#', $baseUrl)) {
            throw new InvalidArgumentException('The API base URL must use HTTPS.');
        }

        $this->baseUrl = $baseUrl;
        $this->transport = $transport ?? self::laravelTransport(...);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    // ──────────────────────────────────────────────
    // Health & pricing (public endpoints)
    // ──────────────────────────────────────────────

    public function health(): array
    {
        return $this->request('GET', '/api/v1/health/');
    }

    /**
     * Net EUR prices for all TLDs, or for a single TLD when given.
     */
    public function pricing(?string $tld = null): array
    {
        return $this->request('GET', '/api/v1/pricing/', $tld !== null ? ['tld' => ltrim($tld, '.')] : []);
    }

    // ──────────────────────────────────────────────
    // Domains
    // ──────────────────────────────────────────────

    public function listDomains(int $page = 1, int $pageSize = 100, ?string $search = null, ?string $status = null): array
    {
        return $this->request('GET', '/api/v1/domains/', array_filter([
            'page' => $page,
            'pageSize' => $pageSize,
            'search' => $search,
            'status' => $status,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function checkDomain(string $domain): array
    {
        return $this->request('GET', '/api/v1/domains/check/' . $this->segment($domain));
    }

    /**
     * @param  string[]  $domains  Up to 50 domain names
     */
    public function checkDomains(array $domains): array
    {
        return $this->request('POST', '/api/v1/domains/check', [], ['domains' => array_values($domains)]);
    }

    /**
     * @param  string[]  $tlds  TLDs to prioritise
     */
    public function suggestDomains(string $query, array $tlds = []): array
    {
        $params = ['q' => $query];
        if ($tlds !== []) {
            $params['defaults'] = implode(',', $tlds);
        }

        return $this->request('GET', '/api/v1/domains/suggest', $params);
    }

    /**
     * @param  array{owner: string, admin?: string, tech?: string, billing?: string}  $contacts
     * @param  string[]  $nameservers  Empty = use the managed nameservers of the API
     */
    public function registerDomain(string $domain, array $contacts, array $nameservers = [], int $years = 1, bool $whoisPrivacy = false): array
    {
        $body = [
            'domain' => $domain,
            'years' => max(1, min(10, $years)),
            'contacts' => array_filter($contacts),
        ];

        if ($nameservers !== []) {
            $body['nameservers'] = array_values($nameservers);
        }

        if ($whoisPrivacy) {
            $body['whoisPrivacy'] = true;
            $body['acceptWppTerms'] = true;
        }

        return $this->request('POST', '/api/v1/domains/register', [], $body);
    }

    /**
     * @param  array{owner: string, admin?: string, tech?: string, billing?: string}  $contacts
     */
    public function transferDomain(string $domain, string $authCode, array $contacts, bool $whoisPrivacy = false): array
    {
        $body = [
            'domain' => $domain,
            'authCode' => $authCode,
            'contacts' => array_filter($contacts),
        ];

        if ($whoisPrivacy) {
            $body['whoisPrivacy'] = true;
            $body['acceptWppTerms'] = true;
        }

        return $this->request('POST', '/api/v1/domains/transfer', [], $body);
    }

    public function getDomain(string $domain): array
    {
        return $this->request('GET', '/api/v1/domains/' . $this->segment($domain));
    }

    /**
     * @param  array{nameservers?: string[], autoRenew?: bool, useManagedDns?: bool}  $data
     */
    public function updateDomain(string $domain, array $data): array
    {
        return $this->request('PATCH', '/api/v1/domains/' . $this->segment($domain), [], $data);
    }

    public function setAutoRenew(string $domain, bool $autoRenew): array
    {
        return $this->updateDomain($domain, ['autoRenew' => $autoRenew]);
    }

    /**
     * Set custom nameservers. For a domain on managed DNS, managed DNS has to be
     * switched off in the same request, otherwise the API keeps the managed zone active.
     */
    public function setNameservers(string $domain, array $nameservers, bool $leaveManagedDns = false): array
    {
        $data = ['nameservers' => array_values($nameservers)];
        if ($leaveManagedDns) {
            $data['useManagedDns'] = false;
        }

        return $this->updateDomain($domain, $data);
    }

    /**
     * Change the contact handles of a domain. Owner changes are only executed
     * with $confirmOwnerChange = true; otherwise the API answers 409 with the price.
     *
     * @param  array{owner?: string, admin?: string, tech?: string, billing?: string}  $contacts
     */
    public function updateDomainContacts(string $domain, array $contacts, bool $confirmOwnerChange = false): array
    {
        $body = ['contacts' => array_filter($contacts)];
        if ($confirmOwnerChange) {
            $body['confirmOwnerChange'] = true;
        }

        return $this->request('PATCH', '/api/v1/domains/' . $this->segment($domain) . '/contacts', [], $body);
    }

    public function getDomainState(string $domain): array
    {
        return $this->request('GET', '/api/v1/domains/' . $this->segment($domain) . '/state');
    }

    public function getDnssec(string $domain): array
    {
        return $this->request('GET', '/api/v1/domains/' . $this->segment($domain) . '/dnssec');
    }

    /**
     * @param  string[]  $dnskeys  Only used for domains with custom nameservers
     */
    public function updateDnssec(string $domain, bool $enabled, array $dnskeys = []): array
    {
        $body = ['enabled' => $enabled];
        if ($dnskeys !== []) {
            $body['dnskeys'] = array_values($dnskeys);
        }

        return $this->request('PUT', '/api/v1/domains/' . $this->segment($domain) . '/dnssec', [], $body);
    }

    public function getAuthCode(string $domain): array
    {
        return $this->request('GET', '/api/v1/domains/' . $this->segment($domain) . '/authcode');
    }

    /**
     * Only supported for .de domains.
     */
    public function resetAuthCode(string $domain): array
    {
        return $this->request('POST', '/api/v1/domains/' . $this->segment($domain) . '/reset-authcode');
    }

    /**
     * Cancel the domain at the end of its term, or delete it right away.
     */
    public function deleteDomain(string $domain, bool $immediate = false): array
    {
        return $this->request('DELETE', '/api/v1/domains/' . $this->segment($domain), $immediate ? ['immediate' => 'true'] : []);
    }

    public function restoreDomain(string $domain): array
    {
        return $this->request('POST', '/api/v1/domains/' . $this->segment($domain) . '/restore');
    }

    public function cancelDelete(string $domain): array
    {
        return $this->request('POST', '/api/v1/domains/' . $this->segment($domain) . '/cancel-delete');
    }

    /**
     * Pending outgoing transfers of the account.
     */
    public function pendingTransferOuts(): array
    {
        return $this->request('GET', '/api/v1/domains/transfer-out/pending');
    }

    /**
     * Approve (default) or reject an outgoing transfer.
     */
    public function transferOut(string $domain, bool $approve = true): array
    {
        return $this->request('POST', '/api/v1/domains/' . $this->segment($domain) . '/transfer-out', [], ['approve' => $approve]);
    }

    /**
     * Put a .de domain on hold (disconnect = true) or release it.
     */
    public function holdDomain(string $domain, bool $disconnect, ?string $execDate = null, bool $execOnExpire = false): array
    {
        $body = ['disconnect' => $disconnect];
        if ($execDate !== null) {
            $body['execDate'] = $execDate;
        }
        if ($execOnExpire) {
            $body['execOnExpire'] = true;
        }

        return $this->request('POST', '/api/v1/domains/' . $this->segment($domain) . '/hold', [], $body);
    }

    // ──────────────────────────────────────────────
    // Wallet
    // ──────────────────────────────────────────────

    /**
     * Wallet balance: balance, currency, allowNegativeBalance, postpaid, creditLimit.
     */
    public function wallet(): array
    {
        return $this->request('GET', '/api/v1/billing/wallet');
    }

    /**
     * Auto top-up configuration: enabled, threshold, amount.
     */
    public function autoTopup(): array
    {
        return $this->request('GET', '/api/v1/billing/auto-topup');
    }

    // ──────────────────────────────────────────────
    // Contacts (handles)
    // ──────────────────────────────────────────────

    public function listContacts(int $page = 1, int $pageSize = 100, ?string $search = null): array
    {
        return $this->request('GET', '/api/v1/contacts/', array_filter([
            'page' => $page,
            'pageSize' => $pageSize,
            'search' => $search,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    public function getContact(string $handle): array
    {
        return $this->request('GET', '/api/v1/contacts/' . $this->segment($handle));
    }

    public function createContact(array $data): array
    {
        return $this->request('POST', '/api/v1/contacts/', [], $data);
    }

    public function updateContact(string $handle, array $data): array
    {
        return $this->request('PATCH', '/api/v1/contacts/' . $this->segment($handle), [], $data);
    }

    public function deleteContact(string $handle): array
    {
        return $this->request('DELETE', '/api/v1/contacts/' . $this->segment($handle));
    }

    // ──────────────────────────────────────────────
    // Managed DNS
    // ──────────────────────────────────────────────

    public function enableManagedDns(string $domain): array
    {
        return $this->request('POST', '/api/v1/dns/' . $this->segment($domain) . '/enable');
    }

    public function disableManagedDns(string $domain): array
    {
        return $this->request('POST', '/api/v1/dns/' . $this->segment($domain) . '/disable');
    }

    public function getDnsRecords(string $domain): array
    {
        return $this->request('GET', '/api/v1/dns/' . $this->segment($domain));
    }

    public function createDnsRecord(string $domain, string $type, string $name, string $content, ?int $ttl = null, ?int $priority = null): array
    {
        $body = [
            'type' => $type,
            'name' => $name,
            'content' => $content,
        ];
        if ($ttl !== null) {
            $body['ttl'] = $ttl;
        }
        if ($priority !== null) {
            $body['priority'] = $priority;
        }

        return $this->request('POST', '/api/v1/dns/' . $this->segment($domain) . '/records', [], $body);
    }

    /**
     * @param  array{name?: string, content?: string, ttl?: int, priority?: int}  $data
     */
    public function updateDnsRecord(string $domain, string $recordId, array $data): array
    {
        return $this->request('PATCH', '/api/v1/dns/' . $this->segment($domain) . '/records/' . $this->segment($recordId), [], $data);
    }

    public function deleteDnsRecord(string $domain, string $recordId): array
    {
        return $this->request('DELETE', '/api/v1/dns/' . $this->segment($domain) . '/records/' . $this->segment($recordId));
    }

    public function getZoneInfo(string $domain): array
    {
        return $this->request('GET', '/api/v1/dns/' . $this->segment($domain) . '/zone-info');
    }

    public function exportZone(string $domain): array
    {
        return $this->request('GET', '/api/v1/dns/' . $this->segment($domain) . '/export');
    }

    public function importZone(string $domain, string $zoneFile): array
    {
        return $this->request('POST', '/api/v1/dns/' . $this->segment($domain) . '/import', [], ['zoneFile' => $zoneFile]);
    }

    // ──────────────────────────────────────────────
    // Webhooks
    // ──────────────────────────────────────────────

    public function listWebhooks(): array
    {
        return $this->request('GET', '/api/v1/webhooks/');
    }

    /**
     * Create a webhook. The response contains the signing secret for "custom" webhooks.
     *
     * @param  string[]  $events
     */
    public function createWebhook(string $url, array $events, string $type = 'custom'): array
    {
        return $this->request('POST', '/api/v1/webhooks/', [], ['url' => $url, 'events' => array_values($events), 'type' => $type]);
    }

    public function deleteWebhook(string $id): array
    {
        return $this->request('DELETE', '/api/v1/webhooks/' . $this->segment($id));
    }

    // ──────────────────────────────────────────────
    // Transport
    // ──────────────────────────────────────────────

    /**
     * Perform a request and return the decoded body.
     *
     * @throws ApiException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => self::USER_AGENT,
        ];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->sandbox) {
            $headers['x-sandbox'] = 'true';
        }

        try {
            $response = ($this->transport)($method, $url, $headers, $body, $this->timeout);
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ApiException('Could not connect to the Domain Reseller API: ' . $e->getMessage());
        }

        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException(
                sprintf('Invalid response from the Domain Reseller API (HTTP %d): %s', $status, mb_substr(strip_tags($raw), 0, 200)),
                $status,
            );
        }

        if ($status < 200 || $status >= 300 || ($decoded['success'] ?? true) === false) {
            $message = $decoded['error'] ?? $decoded['message'] ?? null;
            if (!is_string($message) || $message === '') {
                $message = sprintf('The Domain Reseller API returned HTTP %d', $status);
            }

            throw new ApiException($message, $status, $decoded);
        }

        return $decoded;
    }

    private function segment(string $value): string
    {
        return rawurlencode(trim($value));
    }

    /**
     * Default transport based on Laravel's HTTP client (always available inside Paymenter).
     */
    private static function laravelTransport(string $method, string $url, array $headers, ?array $body, int $timeout): array
    {
        $request = Http::withHeaders($headers)
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->withOptions(['allow_redirects' => false]);

        if ($body !== null) {
            $request = $request->withBody(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json');
        }

        $response = $request->send($method, $url);

        return [
            'status' => $response->status(),
            'body' => $response->body(),
        ];
    }
}
