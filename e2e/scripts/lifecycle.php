<?php
// Full lifecycle against the real API: checkout, provisioning, client area, suspend/unsuspend,
// webhook, sync, price import, termination and re-activation.
require __DIR__ . '/boot.php';

use App\Helpers\ExtensionHelper;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;

$domain = 'e2e-' . substr(md5((string) microtime(true)), 0, 8) . '.de';
$product = Product::where('slug', 'domain-de')->firstOrFail();
$user = User::where('email', 'kunde@e2e.test')->firstOrFail();

section('Checkout');
$fields = ExtensionHelper::getCheckoutConfig($product, []);
ok('register fields: ' . implode(',', array_column($fields, 'name')), array_column($fields, 'name') === ['action', 'domain', 'ns1', 'ns2', 'ns3', 'ns4', 'whois_privacy']);
$transferFields = ExtensionHelper::getCheckoutConfig($product, ['action' => 'transfer']);
ok('transfer fields: ' . implode(',', array_column($transferFields, 'name')), array_column($transferFields, 'name') === ['action', 'domain', 'auth_code', 'whois_privacy']);
$rules = fn ($fields) => collect($fields)->mapWithKeys(fn ($f) => ['checkoutConfig.' . $f['name'] => array_merge(($f['required'] ?? false) ? ['required'] : [], is_array($f['validation'] ?? null) ? $f['validation'] : [])])->all();
$v = Validator::make(['checkoutConfig' => ['domain' => 'example.net', 'ns1' => 'bad host']], $rules($fields));
ok('rejects wrong TLD and invalid nameserver', $v->errors()->has('checkoutConfig.domain') && $v->errors()->has('checkoutConfig.ns1'));
$v = Validator::make(['checkoutConfig' => ['action' => 'register', 'domain' => strtoupper($domain), 'ns1' => '', 'whois_privacy' => 'no']], $rules($fields));
ok('accepts an available domain (live availability check)', !$v->fails());

section('Provisioning');
$order = Order::create(['user_id' => $user->id, 'currency_code' => $product->plans->first()->prices->first()->currency_code]);
$service = $order->services()->create(['user_id' => $user->id, 'currency_code' => $order->currency_code, 'product_id' => $product->id, 'plan_id' => $product->plans->first()->id, 'price' => 9.99, 'quantity' => 1]);
foreach (['action' => 'register', 'domain' => $domain, 'ns1' => '', 'ns2' => '', 'ns3' => '', 'ns4' => '', 'whois_privacy' => 'no'] as $key => $value) {
    $service->properties()->updateOrCreate(['key' => $key], ['value' => $value]);
}
$data = ExtensionHelper::createServer($service->fresh());
$props = fn () => $service->fresh()->properties->pluck('value', 'key');
$ext = DomainResellerApi::forService($service->fresh());
$info = fn () => $ext->client()->getDomain($domain)['data'];
ok('createServer: ' . json_encode($data), ($data['status'] ?? null) === 'active');
ok('owner handle ' . $props()['dra_owner_handle'] . ' is the domain owner at the API', $info()['contacts']['owner'] === $props()['dra_owner_handle']);
ok('createServer is idempotent', ExtensionHelper::createServer($service->fresh())['status'] === 'active');

section('Client area');
$service->update(['status' => 'active']);
$views = array_column(array_filter(ExtensionHelper::getActions($service->fresh()), fn ($a) => $a['type'] === 'view'), 'name');
ok('tabs: ' . implode(',', $views), $views === ['overview', 'nameservers', 'dns', 'contacts', 'dnssec', 'transfer']);
Auth::login($user);
foreach ($views as $view) {
    $html = (string) ExtensionHelper::getView($service->fresh(), ['name' => $view]);
    ok("tab $view renders", $html !== '' && !str_contains($html, 'messages.'));
}

section('Billing lifecycle');
ExtensionHelper::suspendServer($service->fresh());
ok('suspend disables auto-renew', $info()['autoRenew'] === false);
ExtensionHelper::unsuspendServer($service->fresh());
ok('unsuspend re-enables auto-renew', $info()['autoRenew'] === true);

section('Webhook');
$payload = json_encode(['event' => 'domain.expiry_warning', 'timestamp' => date('c'), 'data' => ['domain' => $domain, 'daysUntilExpiry' => 30]]);
$post = function (string $signature) use ($payload) {
    $ch = curl_init('http://127.0.0.1/extensions/domain-reseller-api/webhook');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Webhook-Signature: ' . $signature]]);
    $body = curl_exec($ch);

    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), $body];
};
ok('invalid signature → 401', $post('deadbeef')[0] === 401);
DB::table('properties')->where('model_id', $service->id)->where('key', 'dra_last_sync')->delete();
[$code, $body] = $post(hash_hmac('sha256', $payload, 'e2e-webhook-secret'));
ok("valid signature → $code $body", $code === 200 && isset($props()['dra_last_sync']));

section('Commands');
ok('domain-reseller-api:sync', Artisan::call('domain-reseller-api:sync') === 0);
$exit = Artisan::call('domain-reseller-api:import-tlds', ['--tlds' => 'com,io', '--margin' => 20, '--ending' => '0.99', '--category' => 'domain-imports', '--update-prices' => true]);
$com = Product::where('slug', 'domain-com')->first();
ok('import-tlds created .com at ' . $com?->plans->first()->prices->first()->price, $exit === 0 && (float) $com->plans->first()->prices->first()->price === 17.99);

section('Due date alignment');
Artisan::call('domain-reseller-api:sync');
ok('due date = registry expiry (' . $service->fresh()->expires_at?->format('Y-m-d') . ')', $service->fresh()->expires_at?->format('Y-m-d') === substr((string) $info()['expiresAt'], 0, 10));

section('Auto-renew pause for unpaid renewals');
$server = $product->server;
$server->settings()->where('key', 'autorenew_guard_days')->update(['value' => 400]);
$invoice = App\Models\Invoice::create(['user_id' => $user->id, 'currency_code' => $order->currency_code, 'due_at' => now()->addDays(7), 'status' => 'pending']);
$invoice->items()->create(['description' => 'Renewal ' . $domain, 'price' => 9.99, 'quantity' => 1, 'reference_id' => $service->id, 'reference_type' => App\Models\Service::class]);
Artisan::call('domain-reseller-api:sync', ['--service' => [$service->id]]);
ok('unpaid renewal pauses auto-renew: ' . trim(Artisan::output()), $info()['autoRenew'] === false && $props()['dra_autorenew_guarded'] === '1');
ExtensionHelper::addPayment($invoice->id, null, amount: $invoice->fresh()->remaining);
ok('payment resumes auto-renew immediately', $info()['autoRenew'] === true && !isset($props()['dra_autorenew_guarded']));
$server->settings()->where('key', 'autorenew_guard_days')->update(['value' => 0]);

section('Webhook registration');
$secretBefore = ExtensionHelper::settingsToArray($server->fresh()->settings)['webhook_secret'];
$result = DomainResellerApi::forServer($server->fresh())->registerWebhook($server->fresh(), true);
ok('private URLs are refused by the API, existing secret kept', $result === null && ExtensionHelper::settingsToArray($server->fresh()->settings)['webhook_secret'] === $secretBefore);

section('Scheduler');
Artisan::call('schedule:list');
ok('sync is scheduled hourly', str_contains(Artisan::output(), 'domain-reseller-api:sync'));

section('Termination');
ExtensionHelper::terminateServer($service->fresh());
ok('terminate schedules deletion (' . $info()['status'] . ')', $info()['status'] === 'pending_delete');
ExtensionHelper::createServer($service->fresh());
ok('re-create cancels the deletion (' . $info()['status'] . ')', $info()['status'] === 'active');

file_put_contents('/shared/e2e_service_id', (string) $service->id);
exit($GLOBALS['failures']);
