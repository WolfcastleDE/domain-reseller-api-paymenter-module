<?php

namespace Tests\Support;

use App\Models\User;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Notifier;

/**
 * Records notifications instead of storing them.
 */
class FakeNotifier extends Notifier
{
    public array $admin = [];

    public array $customer = [];

    public function admins(string $title, string $body, ?string $url = null, ?string $dedupeKey = null, int $dedupeMinutes = 1440): void
    {
        $this->admin[] = compact('title', 'body', 'dedupeKey');
    }

    public function customer(?User $user, string $title, string $body, ?string $url = null): void
    {
        $this->customer[] = compact('title', 'body');
    }
}
