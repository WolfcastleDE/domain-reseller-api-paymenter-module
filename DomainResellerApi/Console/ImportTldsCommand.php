<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Console;

use App\Models\Category;
use App\Models\Product;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PriceCalculator;
use Throwable;

/**
 * Creates (or updates) one Paymenter product per TLD from the price list of
 * the Domain Reseller API, including a yearly plan with your mark-up.
 */
class ImportTldsCommand extends Command
{
    protected $signature = 'domain-reseller-api:import-tlds
        {--server= : ID of the Domain Reseller API server (defaults to the only one)}
        {--category=domains : Slug of the category for the products (created if missing)}
        {--tlds= : Comma separated list of TLDs to import (default: all)}
        {--mode=register : "register" (registration + transfer, priced as registration) or "transfer" (transfer-only products)}
        {--margin=0 : Mark-up in percent on the purchase price}
        {--fixed=0 : Fixed mark-up per year in the target currency}
        {--currency= : Currency code of the prices (default: the shop default currency)}
        {--rate=1 : Conversion rate from EUR into the target currency}
        {--ending= : Round prices up to this ending, e.g. 0.99}
        {--update-prices : Also update prices of products that already exist}
        {--dry-run : Only print the price table}';

    protected $description = 'Create Paymenter products for TLDs offered by the Domain Reseller API';

    public function handle(): int
    {
        $mode = $this->option('mode');
        if (!in_array($mode, ['register', 'transfer'], true)) {
            $this->error('--mode must be "register" or "transfer".');

            return self::INVALID;
        }

        $server = $this->resolveServer();
        if (!$server) {
            return self::FAILURE;
        }

        try {
            $calculator = new PriceCalculator(
                (float) $this->option('margin'),
                (float) $this->option('fixed'),
                (float) $this->option('rate'),
                $this->option('ending') !== null && $this->option('ending') !== '' ? (float) $this->option('ending') : null,
            );
            $pricing = DomainResellerApi::forServer($server)->client()->pricing()['data'] ?? [];
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $wanted = DomainName::normalizeTlds($this->option('tlds'));
        $pricing = array_filter($pricing, fn ($p) => is_array($p) && isset($p['tld']) && ($wanted === [] || in_array($p['tld'], $wanted, true)));
        if ($pricing === []) {
            $this->warn('No matching TLDs found.');

            return self::SUCCESS;
        }

        $currency = strtoupper((string) ($this->option('currency') ?: config('settings.default_currency', 'EUR')));
        $rows = [];
        foreach ($pricing as $entry) {
            $plan = $calculator->plan($entry, $mode);
            $rows[] = [$entry['tld'], $plan['years'], number_format($plan['price'], 2), number_format($plan['setup_fee'], 2), number_format($plan['first_period'], 2), $currency];
        }
        $this->table(['TLD', 'Years', 'Recurring', 'Setup fee', 'First period', 'Currency'], $rows);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $category = Category::firstOrCreate(
            ['slug' => $this->option('category')],
            ['name' => ucfirst(str_replace('-', ' ', $this->option('category')))],
        );

        $created = 0;
        $updated = 0;
        foreach ($pricing as $entry) {
            DB::transaction(function () use ($entry, $mode, $calculator, $server, $category, $currency, &$created, &$updated) {
                $result = $this->importTld($entry, $mode, $calculator, $server, $category, $currency);
                $result === 'created' ? $created++ : ($result === 'updated' ? $updated++ : null);
            });
        }

        $this->info(sprintf('%d product(s) created, %d updated.', $created, $updated));

        return self::SUCCESS;
    }

    private function importTld(array $entry, string $mode, PriceCalculator $calculator, Server $server, Category $category, string $currency): string
    {
        $tld = $entry['tld'];
        $plan = $calculator->plan($entry, $mode);
        $slug = 'domain-' . str_replace('.', '-', $tld) . ($mode === 'transfer' ? '-transfer' : '');
        $display = '.' . DomainName::toUnicode($tld);

        $product = Product::where('slug', $slug)->first();
        $isNew = $product === null;

        if ($isNew) {
            $product = Product::create([
                'category_id' => $category->id,
                'server_id' => $server->id,
                'name' => $mode === 'transfer' ? $display . ' domain transfer' : $display . ' domain',
                'slug' => $slug,
                'description' => $mode === 'transfer'
                    ? 'Transfer your ' . $display . ' domain to us.'
                    : 'Register or transfer a ' . $display . ' domain.',
                'allow_quantity' => 'disabled',
            ]);

            $settings = [
                'tlds' => [json_encode([$tld]), 'array'],
                'allow_register' => [$mode === 'register' ? '1' : '0', 'boolean'],
                'allow_transfer' => ['1', 'boolean'],
                'years' => [(string) $plan['years'], 'string'],
                'nameserver_mode' => ['managed', 'string'],
                'whois_privacy' => ['off', 'string'],
                'allow_premium' => ['0', 'boolean'],
                'feature_nameservers' => ['1', 'boolean'],
                'feature_dns' => ['1', 'boolean'],
                'feature_contacts' => ['1', 'boolean'],
                'feature_dnssec' => ['1', 'boolean'],
                'feature_authcode' => ['1', 'boolean'],
            ];
            foreach ($settings as $key => [$value, $type]) {
                $product->settings()->updateOrCreate(['key' => $key], ['value' => $value, 'type' => $type]);
            }
        } elseif (!$this->option('update-prices')) {
            return 'skipped';
        }

        $planModel = $product->plans()->firstOrCreate(
            ['type' => 'recurring', 'billing_unit' => 'year', 'billing_period' => $plan['years']],
            ['name' => $plan['years'] === 1 ? '1 year' : $plan['years'] . ' years'],
        );

        $planModel->prices()->updateOrCreate(
            ['currency_code' => $currency],
            ['price' => $plan['price'], 'setup_fee' => $plan['setup_fee'] > 0 ? $plan['setup_fee'] : null],
        );

        return $isNew ? 'created' : 'updated';
    }

    private function resolveServer(): ?Server
    {
        $query = Server::where('extension', DomainResellerApi::EXTENSION);

        if ($this->option('server')) {
            $server = $query->where('id', $this->option('server'))->first();
            if (!$server) {
                $this->error('Server #' . $this->option('server') . ' is not a Domain Reseller API server.');
            }

            return $server;
        }

        $servers = $query->get();
        if ($servers->count() !== 1) {
            $this->error($servers->isEmpty()
                ? 'Create a Domain Reseller API server in the admin area first.'
                : 'Several Domain Reseller API servers exist, please pass --server=ID.');

            return null;
        }

        return $servers->first();
    }
}
