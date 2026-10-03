<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Livewire\Component;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns\ManagesDomain;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactMapper;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;

/**
 * Shows the contacts of a domain, lets the customer update the data of their
 * own owner contact handle and – if the product allows it – change the owner.
 */
class Contacts extends Component
{
    use ManagesDomain;

    /** Editable fields of a contact. */
    public const FIELDS = ['firstName', 'lastName', 'organization', 'street', 'houseNumber', 'postalCode', 'city', 'state', 'country', 'phone', 'email'];

    /** Role => handle */
    #[Locked]
    public array $handles = [];

    /** Handle of the owner contact if it belongs to this customer, otherwise null. */
    #[Locked]
    public ?string $ownerHandle = null;

    #[Locked]
    public ?string $ownerType = null;

    public array $contact = [];

    /** Owner change: off | free | all (product setting) */
    #[Locked]
    public string $ownerChangeMode = 'off';

    public bool $changingOwner = false;

    /** Data of the new owner. */
    public array $newOwner = [];

    /** Handle created for the new owner, waiting for confirmation. */
    #[Locked]
    public ?string $pendingHandle = null;

    /** Price information returned by the API for the pending owner change. */
    #[Locked]
    public ?array $ownerChangePrice = null;

    protected function feature(): string
    {
        return 'contacts';
    }

    protected function load(): void
    {
        $this->ownerChangeMode = in_array($mode = $this->productSettings()['owner_change'] ?? 'free', ['off', 'free', 'all'], true) ? $mode : 'off';

        try {
            $info = $this->client()->getDomain($this->domain())['data'] ?? [];
            $this->handles = array_filter($info['contacts'] ?? []);

            $owner = $this->handles['owner'] ?? null;
            $ownHandle = $this->serviceProperties()[DomainResellerApi::PROP_OWNER_HANDLE] ?? null;
            $this->ownerHandle = $owner !== null && $owner === $ownHandle ? $owner : null;

            if ($this->ownerHandle !== null) {
                $data = $this->client()->getContact($this->ownerHandle)['data'] ?? [];
                $this->ownerType = $data['type'] ?? null;
                $this->contact = [];
                foreach (self::FIELDS as $field) {
                    $this->contact[$field] = (string) ($data[$field] ?? '');
                }
            }
        } catch (ApiException $e) {
            $this->loadError = $e->getMessage();

            return;
        }

        $this->loadError = null;
    }

    protected function contactRules(string $prefix): array
    {
        return [
            $prefix . '.firstName' => ['required', 'string', 'max:100'],
            $prefix . '.lastName' => ['required', 'string', 'max:100'],
            $prefix . '.organization' => [$prefix === 'contact' ? 'required' : 'nullable', 'string', 'max:255'],
            $prefix . '.street' => ['required', 'string', 'max:255'],
            $prefix . '.houseNumber' => ['required', 'string', 'max:20'],
            $prefix . '.postalCode' => ['required', 'string', 'max:20'],
            $prefix . '.city' => ['required', 'string', 'max:100'],
            $prefix . '.state' => ['nullable', 'string', 'max:100'],
            $prefix . '.country' => ['required', 'string', 'regex:/^[A-Za-z]{2}$/'],
            $prefix . '.phone' => ['required', 'string', 'max:50'],
            $prefix . '.email' => ['required', 'string', 'regex:/^[0-9a-zA-Z._-]{1,64}@[0-9a-zA-Z._-]{3,64}$/'],
        ];
    }

    /**
     * Normalised contact payload from form data, or null (with error) for an invalid phone number.
     */
    private function payload(array $input, string $prefix): ?array
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = trim((string) ($input[$field] ?? ''));
        }
        $data['country'] = strtoupper($data['country']);

        $phone = (new ContactMapper)->formatPhone($data['phone'], $data['country']);
        if ($phone === null) {
            $this->addError($prefix . '.phone', $this->t('contacts.invalid_phone'));

            return null;
        }
        $data['phone'] = $phone;

        return $data;
    }

    public function save(): void
    {
        if ($this->ownerHandle === null) {
            return;
        }

        $this->validate($this->contactRules('contact'));

        $data = $this->payload($this->contact, 'contact');
        if ($data === null) {
            return;
        }

        $result = $this->attempt(
            fn (ApiClient $client) => $client->updateContact($this->ownerHandle, $data),
            $this->t('contacts.saved'),
        );

        if ($result !== null) {
            $this->load();
        }
    }

    // ── Owner change ─────────────────────────────

    public function startOwnerChange(): void
    {
        if ($this->ownerChangeMode === 'off') {
            return;
        }

        $this->resetErrorBag();
        $this->changingOwner = true;
        $this->newOwner = array_fill_keys(self::FIELDS, '');
        $this->newOwner['country'] = $this->contact['country'] ?? '';
    }

    /**
     * Create the new owner handle and ask the API what the change costs.
     */
    public function requestOwnerChange(): void
    {
        if ($this->ownerChangeMode === 'off' || $this->pendingHandle !== null) {
            return;
        }

        $this->validate($this->contactRules('newOwner'));
        $data = $this->payload($this->newOwner, 'newOwner');
        if ($data === null) {
            return;
        }

        $company = $data['organization'];
        $data['type'] = $company !== '' ? 'organization' : 'person';
        $data['organization'] = $company !== '' ? $company : trim($data['firstName'] . ' ' . $data['lastName']);
        $data['sex'] = 'NA';
        $data = array_filter($data, fn ($v) => $v !== '');

        $created = $this->attempt(fn (ApiClient $client) => $client->createContact($data));
        $handle = is_array($created) ? ($created['data']['handle'] ?? null) : null;
        if (!$handle) {
            return;
        }

        try {
            // Without confirmation the API only reports the price (HTTP 409).
            $this->client()->updateDomainContacts($this->domain(), ['owner' => $handle]);
            $this->completeOwnerChange($handle, 0.0);

            return;
        } catch (ApiException $e) {
            $price = $e->isConflict() ? ($e->response()['ownerChange'] ?? null) : null;
            if (!is_array($price)) {
                $this->discardHandle($handle);
                $this->notify($e->getMessage(), 'error');

                return;
            }
        }

        if ((float) ($price['netPrice'] ?? 0) > 0 && $this->ownerChangeMode !== 'all') {
            $this->discardHandle($handle);
            $this->notify($this->t('contacts.owner_change_chargeable'), 'error');

            return;
        }

        $this->pendingHandle = $handle;
        $this->ownerChangePrice = $price;
    }

    public function confirmOwnerChange(): void
    {
        if ($this->pendingHandle === null) {
            return;
        }

        $handle = $this->pendingHandle;
        $result = $this->attempt(fn (ApiClient $client, string $domain) => $client->updateDomainContacts($domain, ['owner' => $handle], true));
        if ($result === null) {
            return;
        }

        $this->completeOwnerChange($handle, (float) ($result['data']['charged'] ?? 0));
    }

    public function cancelOwnerChange(): void
    {
        if ($this->pendingHandle !== null) {
            $this->discardHandle($this->pendingHandle);
        }

        $this->changingOwner = false;
        $this->pendingHandle = null;
        $this->ownerChangePrice = null;
        $this->newOwner = [];
        $this->resetErrorBag();
    }

    private function completeOwnerChange(string $handle, float $charged): void
    {
        (new PropertyStore($this->service))->set(DomainResellerApi::PROP_OWNER_HANDLE, $handle, $this->extension()->propertyName(DomainResellerApi::PROP_OWNER_HANDLE));

        if ($charged > 0) {
            $this->extension()->notifier()->admins(
                'Chargeable owner change',
                sprintf('The owner of %s (service #%d) was changed by the customer; %.2f EUR were charged to the wallet.', $this->domain(), $this->service->id, $charged),
                url('/admin/services/' . $this->service->id . '/edit'),
            );
        }

        $this->changingOwner = false;
        $this->pendingHandle = null;
        $this->ownerChangePrice = null;
        $this->newOwner = [];
        $this->notify($this->t('contacts.owner_changed'), 'success');
        $this->load();
    }

    private function discardHandle(string $handle): void
    {
        try {
            $this->client()->deleteContact($handle);
        } catch (ApiException) {
            // An unused handle is harmless.
        }
    }

    public function countries(): array
    {
        $countries = config('app.countries', []);
        if (!is_array($countries)) {
            return [];
        }
        unset($countries['']);

        return $countries;
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::livewire.contacts', [
            'countries' => $this->countries(),
        ]);
    }
}
