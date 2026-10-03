<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Console;

use App\Models\Server;
use App\Models\Service;
use Illuminate\Console\Command;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\AutoRenewGuard;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Values;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WalletCheck;
use Throwable;

/**
 * Pulls the registry state of all provisioned domains into the service
 * properties and pauses auto-renew for domains whose renewal is unpaid.
 *
 * Scheduled hourly by the extension; can also be run manually.
 */
class SyncDomainsCommand extends Command
{
    protected $signature = 'domain-reseller-api:sync
        {--service=* : Only synchronise these service IDs}
        {--dry-run : Show what would change without calling write endpoints}';

    protected $description = 'Synchronise domain status, expiry and auto-renew from the Domain Reseller API';

    public function handle(): int
    {
        $servers = Server::where('extension', DomainResellerApi::EXTENSION)->get();
        if ($servers->isEmpty()) {
            $this->info('No Domain Reseller API server configured.');

            return self::SUCCESS;
        }

        $failed = false;
        foreach ($servers as $server) {
            try {
                $this->syncServer($server);
            } catch (Throwable $e) {
                $failed = true;
                $this->error(sprintf('Server #%d: %s', $server->id, $e->getMessage()));
                report($e);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function syncServer(Server $server): void
    {
        $extension = DomainResellerApi::forServer($server);

        $services = Service::query()
            ->whereHas('product', fn ($q) => $q->where('server_id', $server->id))
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereHas('properties', fn ($q) => $q->where('key', DomainResellerApi::PROP_DOMAIN_ID))
            ->when($this->option('service'), fn ($q, $ids) => $q->whereIn('id', $ids))
            ->with('properties')
            ->get();

        if ($services->isEmpty()) {
            return;
        }

        $domains = $this->fetchAllDomains($extension);
        $guardDays = Values::toInt($extension->config('autorenew_guard_days'), 0);

        foreach ($services as $service) {
            $properties = $service->properties->pluck('value', 'key')->all();
            $domain = DomainName::normalize($properties[DomainResellerApi::PROP_DOMAIN] ?? null);
            if ($domain === null) {
                continue;
            }

            $info = $domains[$domain] ?? null;
            if ($info === null) {
                $this->warn(sprintf('Service #%d: %s was not found in the reseller account.', $service->id, $domain));
                continue;
            }

            if (!$this->option('dry-run')) {
                $extension->rememberDomainInfo($service, $info);
            }

            try {
                $this->applyAutoRenewGuard($extension, $service, $domain, $info, $properties, $guardDays);
            } catch (ApiException $e) {
                $this->error(sprintf('Service #%d (%s): %s', $service->id, $domain, $e->getMessage()));
            }
        }

        if (!$this->option('service')) {
            $this->checkUpcomingRenewals($extension, $domains);
        }

        $this->info(sprintf('Server #%d: synchronised %d domain(s).', $server->id, $services->count()));
    }

    /**
     * Pause auto-renew shortly before expiry when the renewal invoice is unpaid,
     * and resume it once the invoice has been paid.
     */
    private function applyAutoRenewGuard(DomainResellerApi $extension, Service $service, string $domain, array $info, array $properties, int $guardDays): void
    {
        $decision = AutoRenewGuard::decide(
            $service->status,
            Values::toBool($properties[DomainResellerApi::PROP_GUARDED] ?? null),
            $service->invoices()->where('status', 'pending')->exists(),
            $info,
            $guardDays,
        );

        if ($decision === AutoRenewGuard::RESUME) {
            $this->line(sprintf('Service #%d: renewal of %s paid, resuming auto-renew.', $service->id, $domain));
            if (!$this->option('dry-run')) {
                $extension->reactivate($service, $domain);
            }
        } elseif ($decision === AutoRenewGuard::PAUSE) {
            $this->line(sprintf('Service #%d: renewal of %s is unpaid, pausing auto-renew.', $service->id, $domain));
            if (!$this->option('dry-run')) {
                $extension->client()->setAutoRenew($domain, false);
                (new PropertyStore($service))->setMany([
                    DomainResellerApi::PROP_AUTO_RENEW => false,
                    DomainResellerApi::PROP_GUARDED => true,
                ], [
                    DomainResellerApi::PROP_AUTO_RENEW => $extension->propertyName(DomainResellerApi::PROP_AUTO_RENEW),
                    DomainResellerApi::PROP_GUARDED => $extension->propertyName(DomainResellerApi::PROP_GUARDED),
                ]);
            }
        }
    }

    /**
     * Warn admins when the wallet will not cover the auto-renewals of the next 30 days.
     *
     * @param  array<string, array>  $domains
     */
    private function checkUpcomingRenewals(DomainResellerApi $extension, array $domains): void
    {
        if ($extension->client()->isSandbox()) {
            return;
        }

        $limit = time() + 30 * 86400;
        $due = array_filter($domains, fn ($d) => !empty($d['autoRenew']) && ($d['status'] ?? null) === 'active'
            && !empty($d['expiresAt']) && strtotime((string) $d['expiresAt']) <= $limit);
        if ($due === []) {
            return;
        }

        try {
            $prices = [];
            foreach ($extension->client()->pricing()['data'] ?? [] as $entry) {
                $prices[$entry['tld']] = (float) $entry['renewPrice'];
            }
            $needed = array_sum(array_map(fn ($d) => $prices[DomainName::tld(strtolower($d['name']))] ?? 0.0, $due));
            $wallet = $extension->client()->wallet()['data'] ?? [];
            $autoTopup = $extension->client()->autoTopup()['data'] ?? null;
        } catch (ApiException $e) {
            $this->warn('Could not check upcoming renewals: ' . $e->getMessage());

            return;
        }

        // Net prices; add a margin for VAT.
        if (!WalletCheck::covers($wallet, $autoTopup, round($needed * 1.19, 2))) {
            $extension->notifier()->admins(
                'Wallet may not cover upcoming domain renewals',
                sprintf('%d domain(s) renew within 30 days for about %.2f EUR (net), but only %.2f EUR are available in the Domain Reseller API wallet.', count($due), $needed, WalletCheck::available($wallet)),
                null,
                'wallet-renewals',
                1440,
            );
            $this->warn('The wallet may not cover the renewals of the next 30 days.');
        }
    }

    /**
     * @return array<string, array> domain name => domain list entry
     */
    private function fetchAllDomains(DomainResellerApi $extension): array
    {
        $domains = [];
        $page = 1;

        do {
            $response = $extension->client()->listDomains($page, 100)['data'] ?? [];
            foreach ($response['domains'] ?? [] as $domain) {
                if (isset($domain['name'])) {
                    $domains[strtolower($domain['name'])] = $domain;
                }
            }
            $totalPages = (int) ($response['pagination']['totalPages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 1000);

        return $domains;
    }
}
