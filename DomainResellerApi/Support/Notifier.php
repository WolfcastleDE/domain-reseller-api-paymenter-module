<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Support;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-app notifications (bell icon) for admins and customers. Never throws:
 * a failing notification must not break provisioning.
 */
class Notifier
{
    /**
     * Notify every admin that can manage services. With a $dedupeKey the same
     * notification is sent at most once per $dedupeMinutes.
     */
    public function admins(string $title, string $body, ?string $url = null, ?string $dedupeKey = null, int $dedupeMinutes = 1440): void
    {
        try {
            Log::warning('[DomainResellerApi] ' . $title . ': ' . $body);

            if ($dedupeKey !== null && !Cache::add('dra-notify:' . $dedupeKey, true, now()->addMinutes($dedupeMinutes))) {
                return;
            }

            User::whereNotNull('role_id')->get()
                ->filter(fn (User $user) => $user->hasPermission('admin.services.view'))
                ->each(fn (User $user) => $this->create($user, $title, $body, $url));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function customer(?User $user, string $title, string $body, ?string $url = null): void
    {
        if (!$user) {
            return;
        }

        try {
            $this->create($user, $title, $body, $url);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function create(User $user, string $title, string $body, ?string $url): void
    {
        Notification::create(['user_id' => $user->id, 'title' => $title, 'body' => $body, 'url' => $url]);
    }
}
