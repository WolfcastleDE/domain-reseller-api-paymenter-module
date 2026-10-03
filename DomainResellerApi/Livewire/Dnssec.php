<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Livewire\Component;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns\ManagesDomain;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;

/**
 * DNSSEC: zone signing for managed DNS, DNSKEY records for custom nameservers.
 */
class Dnssec extends Component
{
    use ManagesDomain;

    #[Locked]
    public bool $enabled = false;

    #[Locked]
    public bool $managed = false;

    /** Keys of the signed zone (managed DNS): keytype, algorithm, dnskey, ds[] */
    #[Locked]
    public array $keys = [];

    /** DNSKEY records for custom nameservers, one per line. */
    public string $dnskeys = '';

    protected function feature(): string
    {
        return 'dnssec';
    }

    protected function load(): void
    {
        try {
            $data = $this->client()->getDnssec($this->domain())['data'] ?? [];
        } catch (ApiException $e) {
            $this->loadError = $e->getMessage();

            return;
        }

        $this->loadError = null;
        $this->enabled = (bool) ($data['dnssecEnabled'] ?? false);
        $this->managed = (bool) ($data['useManagedDns'] ?? false);
        $this->keys = array_values(array_filter($data['keys'] ?? [], 'is_array'));
        $this->dnskeys = implode("\n", array_filter($data['dnskeys'] ?? [], 'is_string'));
    }

    /**
     * @return string[]
     */
    private function parsedDnskeys(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $this->dnskeys) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn ($line) => $line !== ''));
    }

    public function enable(): void
    {
        $keys = [];
        if (!$this->managed) {
            $keys = $this->parsedDnskeys();
            if ($keys === []) {
                $this->addError('dnskeys', $this->t('dnssec.keys_required'));

                return;
            }
            if (count($keys) > 10) {
                $this->addError('dnskeys', $this->t('dnssec.too_many_keys'));

                return;
            }
        }

        $this->applyDnssec(true, $keys, $this->t('dnssec.enabled'));
    }

    public function disable(): void
    {
        $this->applyDnssec(false, [], $this->t('dnssec.disabled'));
    }

    private function applyDnssec(bool $enabled, array $keys, string $message): void
    {
        $this->resetErrorBag();

        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->updateDnssec($domain, $enabled, $keys),
            $message,
        );

        if ($result !== null) {
            $this->load();
        }
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::livewire.dnssec');
    }
}
