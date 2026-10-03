<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Livewire;

use App\Helpers\ExtensionHelper;
use App\Livewire\Component;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Values;

/**
 * Public domain search: checks a name across the TLDs of all domain products
 * and links to the checkout with the domain pre-filled.
 */
class DomainSearch extends Component
{
    /** Searches per IP and minute. */
    public const RATE_LIMIT = 15;

    /** TLDs checked per search. */
    public const MAX_CANDIDATES = 20;

    #[Url(as: 'q', except: '')]
    public string $query = '';

    #[Locked]
    public array $results = [];

    #[Locked]
    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(self::enabled(), 404);

        if ($this->query !== '') {
            $this->search();
        }
    }

    /**
     * Is at least one product offered in the domain search?
     */
    public static function enabled(): bool
    {
        return Cache::remember('dra-search-enabled', 300, fn () => self::products()->isNotEmpty());
    }

    /**
     * Products of this extension that take part in the search.
     */
    public static function products()
    {
        return Product::query()
            ->whereHas('server', fn ($q) => $q->where('extension', DomainResellerApi::EXTENSION))
            ->where(fn ($q) => $q->whereNull('hidden')->orWhere('hidden', false))
            ->with(['settings', 'server.settings', 'category', 'plans.prices'])
            ->get()
            ->filter(function (Product $product) {
                $settings = ExtensionHelper::settingsToArray($product->settings);

                return !Values::toBool($settings['hide_from_search'] ?? null) && $product->price()->available;
            })
            ->values();
    }

    public function search(): void
    {
        $this->results = [];
        $this->error = null;

        $raw = mb_strtolower(trim($this->query));
        $typedTld = str_contains($raw, '.') ? DomainName::tld(DomainName::normalize($raw) ?? $raw) : null;
        $label = DomainName::sld(DomainName::normalize(str_contains($raw, '.') ? $raw : $raw . '.com') ?? '');
        if ($label === '') {
            $this->error = __('domain-reseller-api::messages.search.invalid');

            return;
        }

        $key = 'dra-search:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            $this->error = __('domain-reseller-api::messages.search.rate_limited', ['seconds' => RateLimiter::availableIn($key)]);

            return;
        }
        RateLimiter::hit($key, 60);

        $candidates = $this->candidates($typedTld);
        if ($candidates === []) {
            $this->error = __('domain-reseller-api::messages.search.no_tld', ['tld' => '.' . $typedTld]);

            return;
        }

        // One bulk check per server (= API account).
        $byServer = [];
        foreach ($candidates as $tld => $product) {
            $byServer[$product->server_id][$label . '.' . $tld] = $product;
        }

        foreach ($byServer as $domains) {
            $first = reset($domains);
            try {
                $checks = DomainResellerApi::forServer($first->server)->client()->checkDomains(array_keys($domains))['data']['results'] ?? [];
            } catch (ApiException $e) {
                report($e);
                $this->error = __('domain-reseller-api::messages.search.failed');

                continue;
            }

            foreach ($checks as $check) {
                $domain = $check['domain'] ?? null;
                if (!isset($domains[$domain]) || isset($check['error'])) {
                    continue;
                }
                $this->results[] = $this->row($domain, $check, $domains[$domain]);
            }
        }

        // The typed TLD first, then available before taken, then alphabetically.
        usort($this->results, fn ($a, $b) => [$a['tld'] !== $typedTld, !$a['available'], $a['domain']] <=> [$b['tld'] !== $typedTld, !$b['available'], $b['domain']]);
    }

    /**
     * TLD => product that sells it (registration allowed).
     *
     * @return array<string, Product>
     */
    private function candidates(?string $typedTld): array
    {
        $candidates = [];
        $wildcard = null;

        foreach (self::products() as $product) {
            $settings = ExtensionHelper::settingsToArray($product->settings);
            if (!Values::toBool($settings['allow_register'] ?? null, true)) {
                continue;
            }
            $tlds = DomainName::normalizeTlds($settings['tlds'] ?? []);
            if ($tlds === []) {
                $wildcard ??= $product;
            }
            foreach ($tlds as $tld) {
                $candidates[$tld] ??= $product;
            }
        }

        if ($typedTld !== null && !isset($candidates[$typedTld]) && $wildcard !== null) {
            $candidates = [$typedTld => $wildcard] + $candidates;
        }
        if ($typedTld !== null && isset($candidates[$typedTld])) {
            $candidates = [$typedTld => $candidates[$typedTld]] + $candidates;
        }

        return array_slice($candidates, 0, self::MAX_CANDIDATES, true);
    }

    private function row(string $domain, array $check, Product $product): array
    {
        $settings = ExtensionHelper::settingsToArray($product->settings);
        $premiumBlocked = !empty($check['premium']) && !Values::toBool($settings['allow_premium'] ?? null);
        $available = !empty($check['available']) && !$premiumBlocked;
        $transferable = empty($check['available']) && Values::toBool($settings['allow_transfer'] ?? null, true);

        $url = fn (string $action) => route('products.checkout', ['category' => $product->category, 'product' => $product])
            . '?' . http_build_query(['config' => ['action' => $action, 'domain' => $domain]]);

        return [
            'domain' => DomainName::toUnicode($domain),
            'tld' => DomainName::tld($domain),
            'available' => $available,
            'premium' => !empty($check['premium']),
            'price' => (string) $product->price(),
            'order_url' => $available ? $url('register') : null,
            'transfer_url' => $transferable ? $url('transfer') : null,
        ];
    }

    public function render()
    {
        return view(DomainResellerApi::NAME . '::search')->layoutData([
            'title' => __('domain-reseller-api::messages.search.title'),
        ]);
    }
}
