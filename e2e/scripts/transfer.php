<?php
// Transfer-in: order with auth code, then the registry completes the transfer and notifies us via webhook.
require __DIR__ . '/boot.php';

use App\Helpers\ExtensionHelper;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$domain = 'transfer-' . substr(md5((string) microtime(true)), 0, 8) . '.com';
$product = Product::where('slug', 'domain-de')->firstOrFail();
$user = User::where('email', 'kunde@e2e.test')->firstOrFail();

section('Transfer');
$order = Order::create(['user_id' => $user->id, 'currency_code' => $product->plans->first()->prices->first()->currency_code]);
$service = $order->services()->create(['user_id' => $user->id, 'currency_code' => $order->currency_code, 'product_id' => $product->id, 'plan_id' => $product->plans->first()->id, 'price' => 9.99, 'quantity' => 1, 'status' => 'active']);
foreach (['action' => 'transfer', 'domain' => $domain, 'auth_code' => 'E2E-AUTH-123', 'whois_privacy' => 'no'] as $key => $value) {
    $service->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
}
$result = ExtensionHelper::createServer($service->fresh());
$props = fn () => $service->fresh()->properties->pluck('value', 'key');
ok('transfer submitted: ' . json_encode($result), $result['status'] === 'pending_transfer');
ok('auth code removed from the service', !isset($props()['auth_code']));
ok('client area only shows the overview while pending', array_column(array_filter(ExtensionHelper::getActions($service->fresh()), fn ($a) => $a['type'] === 'view'), 'name') === ['overview']);

// Simulate the registry completing the transfer (the sandbox never completes on its own).
config(['database.connections.backend' => array_merge(config('database.connections.mysql'), ['database' => 'domain_reseller'])]);
DB::connection('backend')->table('domains')->where('name', $domain)->update(['status' => 'active']);

$before = Notification::where('user_id', $user->id)->count();
$payload = json_encode(['event' => 'domain.transferred', 'timestamp' => date('c'), 'data' => ['domain' => $domain]]);
$ch = curl_init('http://127.0.0.1/extensions/domain-reseller-api/webhook');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Signature: ' . hash_hmac('sha256', $payload, 'e2e-webhook-secret')]]);
$body = curl_exec($ch);
ok('webhook domain.transferred handled: ' . $body, curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200);
ok('status active after the transfer', $props()['dra_status'] === 'active');
ok('customer notified about the completed transfer', Notification::where('user_id', $user->id)->count() === $before + 1);

$failedPayload = json_encode(['event' => 'domain.transfer_failed', 'timestamp' => date('c'), 'data' => ['domain' => $domain]]);
$ch = curl_init('http://127.0.0.1/extensions/domain-reseller-api/webhook');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $failedPayload, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Signature: ' . hash_hmac('sha256', $failedPayload, 'e2e-webhook-secret')]]);
curl_exec($ch);
$admin = User::where('email', 'admin@e2e.test')->first();
ok('admins notified about a failed transfer', Notification::where('user_id', $admin->id)->where('title', 'Domain transfer failed')->exists());

exit($GLOBALS['failures']);
