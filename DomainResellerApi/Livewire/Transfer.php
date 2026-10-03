<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Livewire\Component;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns\ManagesDomain;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;

/**
 * Auth code (EPP code) retrieval for moving a domain to another provider.
 */
class Transfer extends Component
{
    use ManagesDomain;

    #[Locked]
    public ?string $authCode = null;

    #[Locked]
    public bool $canReset = false;

    /** Another provider requested the domain and the transfer waits for approval. */
    #[Locked]
    public bool $pendingTransferOut = false;

    protected function feature(): string
    {
        return 'transfer';
    }

    protected function load(): void
    {
        // The auth code is only fetched on explicit request.
        $this->canReset = DomainName::tld($this->domain()) === 'de';

        try {
            $transfers = $this->client()->pendingTransferOuts()['data']['transfers'] ?? [];
            $this->pendingTransferOut = in_array($this->domain(), array_map(fn ($t) => strtolower((string) ($t['domain'] ?? '')), $transfers), true);
        } catch (ApiException) {
            $this->pendingTransferOut = false;
        }
    }

    public function approveTransferOut(): void
    {
        $this->answerTransferOut(true);
    }

    public function rejectTransferOut(): void
    {
        $this->answerTransferOut(false);
    }

    private function answerTransferOut(bool $approve): void
    {
        if (!$this->pendingTransferOut) {
            return;
        }

        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->transferOut($domain, $approve),
            $this->t($approve ? 'transfer.out_approved' : 'transfer.out_rejected'),
        );
        if ($result === null) {
            return;
        }

        if ($approve) {
            (new PropertyStore($this->service))->set(DomainResellerApi::PROP_STATUS, 'transferred_away', $this->extension()->propertyName(DomainResellerApi::PROP_STATUS));
            $this->extension()->notifier()->admins(
                'Domain transferred away',
                sprintf('The customer approved the transfer of %s (service #%d) to another provider. Cancel the service in Paymenter.', $this->domain(), $this->service->id),
                url('/admin/services/' . $this->service->id . '/edit'),
            );
        }
        $this->load();
    }

    public function reveal(): void
    {
        $result = $this->attempt(fn (ApiClient $client, string $domain) => $client->getAuthCode($domain));

        if (is_array($result)) {
            $this->authCode = $result['data']['authCode'] ?? null;
        }
    }

    public function resetCode(): void
    {
        if (!$this->canReset) {
            return;
        }

        $result = $this->attempt(
            fn (ApiClient $client, string $domain) => $client->resetAuthCode($domain),
            $this->t('transfer.reset_done'),
        );

        if ($result !== null) {
            $this->authCode = null;
        }
    }

    public function hide(): void
    {
        $this->authCode = null;
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::livewire.transfer');
    }
}
