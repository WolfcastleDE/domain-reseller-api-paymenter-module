<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Livewire\Component;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns\ManagesDomain;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Nameservers as NameserverList;

class Nameservers extends Component
{
    use ManagesDomain;

    /** @var string[] */
    public array $nameservers = [];

    #[Locked]
    public bool $useManagedDns = false;

    protected function feature(): string
    {
        return 'nameservers';
    }

    protected function load(): void
    {
        try {
            $info = $this->client()->getDomain($this->domain())['data'] ?? [];
        } catch (ApiException $e) {
            $this->loadError = $e->getMessage();

            return;
        }

        $this->loadError = null;
        $this->useManagedDns = (bool) ($info['useManagedDns'] ?? false);
        $this->nameservers = array_values($info['nameservers'] ?? []);
        while (count($this->nameservers) < NameserverList::MIN) {
            $this->nameservers[] = '';
        }

        $this->extension()->rememberDomainInfo($this->service, $info);
    }

    public function addNameserver(): void
    {
        if (count($this->nameservers) < NameserverList::MAX) {
            $this->nameservers[] = '';
        }
    }

    public function removeNameserver(int $index): void
    {
        if (count($this->nameservers) > NameserverList::MIN && array_key_exists($index, $this->nameservers)) {
            unset($this->nameservers[$index]);
            $this->nameservers = array_values($this->nameservers);
        }
    }

    public function save(): void
    {
        $this->resetErrorBag();

        try {
            $list = NameserverList::validate($this->nameservers);
        } catch (InvalidArgumentException $e) {
            $this->addError('nameservers', $e->getMessage());

            return;
        }

        $saved = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->setNameservers($domain, $list, $this->useManagedDns),
            $this->t('nameservers.saved'),
        );

        if ($saved !== null) {
            $this->load();
        }
    }

    public function useManaged(): void
    {
        $saved = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->updateDomain($domain, ['useManagedDns' => true]),
            $this->t('nameservers.saved_managed'),
        );

        if ($saved !== null) {
            $this->load();
        }
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::livewire.nameservers');
    }
}
