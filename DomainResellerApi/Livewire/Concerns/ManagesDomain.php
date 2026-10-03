<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Concerns;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;

/**
 * Shared plumbing for the client-area components: authorisation, access to
 * the configured extension/API client and error handling.
 *
 * Every request (initial and subsequent) re-checks that the logged-in user
 * owns the active service and that the feature is enabled for the product.
 */
trait ManagesDomain
{
    #[Locked]
    public Service $service;

    /** Error shown instead of the component content (e.g. API unreachable). */
    #[Locked]
    public ?string $loadError = null;

    private ?DomainResellerApi $extensionInstance = null;

    /**
     * Client-area feature the component belongs to (see DomainResellerApi::clientFeatures()).
     */
    abstract protected function feature(): string;

    /**
     * Load the component state from the API after a successful authorisation.
     */
    abstract protected function load(): void;

    public function mount(Service $service): void
    {
        $this->service = $service;
        $this->authorizeAccess();
        $this->load();
    }

    public function hydrateManagesDomain(): void
    {
        $this->authorizeAccess();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(Auth::check() && (int) $this->service->user_id === (int) Auth::id(), 404);
        abort_unless($this->service->status === Service::STATUS_ACTIVE, 403);
        abort_unless(in_array($this->feature(), $this->extension()->clientFeatures($this->productSettings()), true), 403);
    }

    protected function extension(): DomainResellerApi
    {
        if ($this->extensionInstance === null) {
            $extension = DomainResellerApi::forService($this->service);
            abort_if($extension === null, 404);
            $this->extensionInstance = $extension;
        }

        return $this->extensionInstance;
    }

    protected function client(): ApiClient
    {
        return $this->extension()->client();
    }

    protected function productSettings(): array
    {
        return ExtensionHelper::settingsToArray($this->service->product->settings);
    }

    protected function serviceProperties(): array
    {
        return $this->service->properties()->pluck('value', 'key')->all();
    }

    protected function domain(): string
    {
        return $this->extension()->domainOf($this->serviceProperties());
    }

    public function displayDomain(): string
    {
        return DomainName::toUnicode($this->domain());
    }

    /**
     * Run an API call; on failure show the error as notification and return null.
     */
    protected function attempt(callable $callback, ?string $successMessage = null): mixed
    {
        try {
            $result = $callback($this->client(), $this->domain());
        } catch (ApiException $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }

        if ($successMessage !== null) {
            $this->notify($successMessage, 'success');
        }

        return $result ?? true;
    }

    protected function t(string $key, array $replace = []): string
    {
        return $this->extension()->trans($key, $replace);
    }
}
