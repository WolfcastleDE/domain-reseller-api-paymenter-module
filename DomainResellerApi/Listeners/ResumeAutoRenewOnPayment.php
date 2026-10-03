<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Listeners;

use App\Events\Invoice\Paid;
use App\Models\Service;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Values;
use Throwable;

/**
 * Turns auto-renew back on as soon as the renewal invoice of a domain is paid
 * when the sync command paused it because the invoice was overdue.
 */
class ResumeAutoRenewOnPayment
{
    public function handle(Paid $event): void
    {
        foreach ($event->invoice->items as $item) {
            if ($item->reference_type !== Service::class || !$item->reference instanceof Service) {
                continue;
            }

            $service = $item->reference;
            if (!Values::toBool((new PropertyStore($service))->get(DomainResellerApi::PROP_GUARDED))) {
                continue;
            }

            $extension = DomainResellerApi::forService($service);
            if (!$extension) {
                continue;
            }

            try {
                $properties = $service->properties()->pluck('value', 'key')->all();
                $extension->reactivate($service, $extension->domainOf($properties));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
