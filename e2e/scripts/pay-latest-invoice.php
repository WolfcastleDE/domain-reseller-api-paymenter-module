<?php
// Pays the newest invoice (as a gateway would) and checks that Paymenter provisioned the domain.
require __DIR__ . '/boot.php';

use App\Helpers\ExtensionHelper;
use App\Models\Invoice;

section('Payment → provisioning job');
$invoice = Invoice::latest('id')->firstOrFail();
$service = $invoice->items->first()->reference;
ExtensionHelper::addPayment($invoice->id, null, amount: $invoice->remaining);
$service = $service->fresh();
$props = $service->properties->pluck('value', 'key');
ok('service active: ' . $service->status, $service->status === 'active');
ok('domain ' . $props['domain'] . ' registered by the create job (' . ($props['dra_status'] ?? '-') . ')', ($props['dra_status'] ?? null) === 'active');
exit($GLOBALS['failures']);
