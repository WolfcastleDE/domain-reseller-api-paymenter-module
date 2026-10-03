<?php
// Idempotent setup: admin + customer, the Domain Reseller API server and a .de/.com product.
require __DIR__ . '/boot.php';

use App\Helpers\ExtensionHelper;
use App\Models\Category;
use App\Models\CustomProperty;
use App\Models\Product;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$appUrl = $argv[1] ?? 'http://127.0.0.1:18080';
$apiKey = trim((string) file_get_contents('/shared/api_key'));

section('Setup');

DB::table('settings')->updateOrInsert(['key' => 'app_url', 'settingable_type' => null, 'settingable_id' => null], ['value' => $appUrl]);
Artisan::call('cache:clear');

$admin = User::firstOrCreate(['email' => 'admin@e2e.test'], [
    'first_name' => 'Admin', 'last_name' => 'E2E', 'password' => bcrypt('e2e-password'),
    'role_id' => Role::where('name', 'admin')->value('id'), 'email_verified_at' => now(),
]);

$customer = User::firstOrCreate(['email' => 'kunde@e2e.test'], [
    'first_name' => 'Erika', 'last_name' => 'Mustermann', 'password' => bcrypt('e2e-password'), 'email_verified_at' => now(),
]);
foreach (['address' => 'Musterstraße 12a', 'city' => 'Berlin', 'zip' => '10115', 'country' => 'Germany', 'phone' => '030 1234567'] as $key => $value) {
    $customer->properties()->updateOrCreate(['key' => $key], [
        'value' => $value, 'name' => ucfirst($key), 'custom_property_id' => CustomProperty::where('key', $key)->value('id'),
    ]);
}

$server = Server::where('extension', 'DomainResellerApi')->first()
    ?? Server::create(['name' => 'Domain Reseller API (sandbox)', 'extension' => 'DomainResellerApi', 'type' => 'server']);
$serverSettings = [
    'api_key' => $apiKey, 'base_url' => 'http://localhost:3000', 'sandbox' => true, 'timeout' => 30,
    'on_suspend' => 'disable_autorenew', 'on_terminate' => 'cancel', 'restore_on_unsuspend' => false,
    'autorenew_guard_days' => 0, 'webhook_secret' => 'e2e-webhook-secret',
];
// Same persistence as Paymenter's server edit page
foreach (ExtensionHelper::getConfig('server', 'DomainResellerApi') as $option) {
    $value = $serverSettings[$option['name']] ?? null;
    $server->settings()->updateOrCreate(['key' => $option['name']], [
        'type' => $option['database_type'] ?? 'string',
        'value' => is_array($value) ? json_encode($value) : $value,
        'encrypted' => $option['encrypted'] ?? false,
    ]);
}
$server->refresh();

$category = Category::firstOrCreate(['slug' => 'domains'], ['name' => 'Domains']);
$product = Product::firstOrCreate(['slug' => 'domain-de'], ['category_id' => $category->id, 'name' => '.de / .com Domain', 'server_id' => $server->id]);
$productSettings = [
    'tlds' => ['de', 'com'], 'allow_register' => true, 'allow_transfer' => true, 'years' => 1,
    'nameserver_mode' => 'customer', 'default_nameservers' => [], 'whois_privacy' => 'optional', 'allow_premium' => false,
    'feature_nameservers' => true, 'feature_dns' => true, 'feature_contacts' => true, 'feature_dnssec' => true, 'feature_authcode' => true,
];
foreach (ExtensionHelper::getProductConfig($server, $productSettings) as $option) {
    $value = $productSettings[$option['name']] ?? null;
    $product->settings()->updateOrCreate(['key' => $option['name']], ['type' => $option['database_type'] ?? 'string', 'value' => is_array($value) ? json_encode($value) : $value]);
}
$plan = $product->plans()->firstOrCreate(['type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year'], ['name' => '1 year']);
$plan->prices()->updateOrCreate(['currency_code' => config('settings.default_currency', 'USD')], ['price' => 9.99]);

ok('extension discovered', in_array('DomainResellerApi', array_column(ExtensionHelper::getExtensions('server'), 'name'), true));
ok('API key stored encrypted', DB::table('settings')->where('key', 'api_key')->where('settingable_id', $server->id)->value('value') !== $apiKey);
$test = ExtensionHelper::testConfig($server, ExtensionHelper::settingsToArray($server->settings));
ok('test connection: ' . var_export($test, true), $test === true);
$bad = ExtensionHelper::testConfig($server, ['api_key' => 'drapi_invalid', 'base_url' => 'http://localhost:3000', 'sandbox' => true]);
ok('test connection rejects a wrong key: ' . var_export($bad, true), is_string($bad));

exit($GLOBALS['failures']);
