<?php

namespace Tests\Support;

use App\Models\Service;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;

/**
 * The extension with database, auth and routing lookups replaced.
 */
class TestableExtension extends DomainResellerApi
{
    public bool $managedElsewhere = false;

    public ?object $user = null;

    public FakeNotifier $notifications;

    public function __construct($config = [])
    {
        parent::__construct($config);
        $this->notifications = new FakeNotifier;
        $this->setNotifier($this->notifications);
    }

    protected function isManagedByOtherService(Service $service, string $domain): bool
    {
        return $this->managedElsewhere;
    }

    protected function currentUser(): ?object
    {
        return $this->user;
    }

    public function webhookUrl(): string
    {
        return 'https://shop.example.com/extensions/domain-reseller-api/webhook';
    }
}
