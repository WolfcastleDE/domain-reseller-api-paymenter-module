<?php
// Read-only check of the extension's API calls against the live API (no registrations, no charges).
//
//   LIVE_API_KEY=drapi_... ./e2e/run.sh live
//
// Optional: LIVE_API_URL (default https://domain-reseller-api.de), LIVE_SANDBOX=1.

foreach (['ApiException', 'ApiClient', 'Values', 'DomainName', 'WalletCheck'] as $class) {
    require __DIR__ . '/../DomainResellerApi/Support/' . $class . '.php';
}

use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WalletCheck;

$key = getenv('LIVE_API_KEY') ?: '';
if ($key === '') {
    fwrite(STDERR, "Set LIVE_API_KEY.\n");
    exit(2);
}

$transport = function (string $method, string $url, array $headers, ?array $body, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
        CURLOPT_POSTFIELDS => $body !== null ? json_encode($body) : null,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }

    return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $response];
};

$client = new ApiClient($key, getenv('LIVE_API_URL') ?: ApiClient::DEFAULT_BASE_URL, (bool) getenv('LIVE_SANDBOX'), 30, $transport);
$failures = 0;
$check = function (string $label, callable $call, ?callable $describe = null) use (&$failures) {
    try {
        $result = $call();
        echo "  \033[32mPASS\033[0m $label" . ($describe ? ': ' . $describe($result) : '') . PHP_EOL;

        return $result;
    } catch (ApiException $e) {
        echo "  \033[31mFAIL\033[0m $label: HTTP {$e->status()} {$e->getMessage()}" . PHP_EOL;
        $failures++;

        return null;
    }
};

echo "\033[1mLive API check (read-only) against {$client->baseUrl()}" . ($client->isSandbox() ? ' [sandbox]' : '') . "\033[0m" . PHP_EOL;
$check('health', fn () => $client->health(), fn ($r) => $r['status'] ?? '?');
$check('API key / domains', fn () => $client->listDomains(1, 5), fn ($r) => ($r['data']['pagination']['total'] ?? 0) . ' domain(s)');
$pricing = $check('pricing', fn () => $client->pricing(), fn ($r) => count($r['data'] ?? []) . ' TLDs');
$wallet = $check('wallet (billing scope)', fn () => $client->wallet(), fn ($r) => sprintf('%.2f EUR available', WalletCheck::available($r['data'] ?? [])));
$check('auto top-up', fn () => $client->autoTopup(), fn ($r) => !empty($r['data']['enabled']) ? 'enabled' : 'disabled');
$name = 'paymenter-live-check-' . bin2hex(random_bytes(4));
$domainCheck = $check("availability $name.de", fn () => $client->checkDomain($name . '.de'), fn ($r) => ($r['data']['available'] ? 'available' : 'taken') . sprintf(', %.2f EUR + %d%% VAT', $r['data']['price'] ?? 0, $r['data']['vatPercent'] ?? 0));
$check('availability of a taken domain (google.de)', fn () => $client->checkDomain('google.de'), fn ($r) => $r['data']['available'] ? 'reported available?!' : 'taken');
$check('suggestions', fn () => $client->suggestDomains($name, ['de', 'com']), fn ($r) => count($r['data']['suggestions'] ?? []) . ' suggestion(s)');
$check('contacts', fn () => $client->listContacts(1, 1), fn ($r) => 'ok');
$check('pending outgoing transfers', fn () => $client->pendingTransferOuts(), fn ($r) => count($r['data']['transfers'] ?? []) . ' pending');
$check('webhooks', fn () => $client->listWebhooks(), fn ($r) => 'ok');

if ($wallet && $domainCheck) {
    $needed = WalletCheck::requiredAmount($domainCheck['data'], 'register');
    echo PHP_EOL . sprintf('A .de registration costs %.2f EUR gross; the wallet %s it.', $needed, WalletCheck::covers($wallet['data'], null, $needed) ? 'covers' : 'does NOT cover') . PHP_EOL;
}

exit($failures > 0 ? 1 : 0);
