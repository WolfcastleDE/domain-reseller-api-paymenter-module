<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Http;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WebhookEvent;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WebhookSignature;

/**
 * Receives "custom" webhooks of the Domain Reseller API and keeps the
 * registry status of the matching services up to date.
 */
class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('X-Webhook-Signature', '');

        $server = $this->serverForSignature($payload, $signature);
        if (!$server) {
            return response()->json(['success' => false, 'error' => 'Invalid signature'], 401);
        }

        $body = json_decode($payload, true);
        if (!is_array($body)) {
            return response()->json(['success' => false, 'error' => 'Invalid payload'], 400);
        }

        $event = (string) ($body['event'] ?? $request->header('X-Webhook-Event', ''));
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $domain = DomainName::normalize($data['domain'] ?? null);

        if ($event === 'wallet.low_balance') {
            DomainResellerApi::forServer($server)->notifier()->admins(
                'Domain Reseller API wallet balance is low',
                sprintf('The wallet balance is %s %s. Top up the wallet at domain-reseller-api.de to avoid failing registrations and renewals.', $data['balance'] ?? '?', $data['currency'] ?? 'EUR'),
                null,
                'wallet-low-balance',
                360,
            );

            return response()->json(['success' => true, 'handled' => 1]);
        }

        if (!WebhookEvent::isDomainEvent($event) || $domain === null) {
            return response()->json(['success' => true, 'handled' => 0]);
        }

        $services = Service::query()
            ->whereHas('product', fn ($q) => $q->where('server_id', $server->id))
            ->whereHas('properties', fn ($q) => $q->where('key', DomainResellerApi::PROP_DOMAIN)->where('value', $domain))
            ->get();

        $extension = DomainResellerApi::forServer($server);
        $status = WebhookEvent::status($event, $data);
        $info = null;

        if ($services->isNotEmpty() && WebhookEvent::shouldRefresh($event)) {
            try {
                $info = $extension->client()->getDomain($domain)['data'] ?? null;
            } catch (ApiException $e) {
                Log::warning('[DomainResellerApi] Could not refresh ' . $domain . ' after webhook ' . $event . ': ' . $e->getMessage());
            }
        }

        foreach ($services as $service) {
            if (is_array($info)) {
                $extension->rememberDomainInfo($service, $info);
            } elseif ($status !== null) {
                $extension->updateStatus($service, $status);
            }
        }

        return response()->json(['success' => true, 'handled' => $services->count()]);
    }

    private function serverForSignature(string $payload, string $signature): ?Server
    {
        if ($signature === '') {
            return null;
        }

        foreach (Server::where('extension', DomainResellerApi::EXTENSION)->get() as $server) {
            $secret = (string) (ExtensionHelper::settingsToArray($server->settings)['webhook_secret'] ?? '');
            if ($secret !== '' && WebhookSignature::verify($payload, $signature, $secret)) {
                return $server;
            }
        }

        return null;
    }
}
