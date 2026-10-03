<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Events\Invoice\Paid as InvoicePaid;
use App\Helpers\ExtensionHelper;
use App\Models\Product;
use App\Models\Service;
use Closure;
use Exception;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\DomainResellerApi\Console\ImportTldsCommand;
use Paymenter\Extensions\Servers\DomainResellerApi\Console\SyncDomainsCommand;
use Paymenter\Extensions\Servers\DomainResellerApi\Listeners\ResumeAutoRenewOnPayment;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Contacts as ContactsComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\DnsRecords as DnsRecordsComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Dnssec as DnssecComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\DomainSearch as DomainSearchComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Nameservers as NameserversComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Livewire\Transfer as TransferComponent;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactDataException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ContactMapper;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Nameservers;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Notifier;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\Values;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WalletCheck;
use Throwable;

#[ExtensionMeta(
    name: 'Domain Reseller API',
    description: 'Register, transfer and manage domains through domain-reseller-api.de',
    version: '1.0.0',
    author: 'Wolfscastle',
    url: 'https://domain-reseller-api.de',
    icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCA2NCA2NCI+PHJlY3Qgd2lkdGg9IjY0IiBoZWlnaHQ9IjY0IiByeD0iMTQiIGZpbGw9IiMyNTYzZWIiLz48ZyBmaWxsPSJub25lIiBzdHJva2U9IiNmZmYiIHN0cm9rZS13aWR0aD0iMyI+PGNpcmNsZSBjeD0iMzIiIGN5PSIzMiIgcj0iMTgiLz48ZWxsaXBzZSBjeD0iMzIiIGN5PSIzMiIgcng9IjgiIHJ5PSIxOCIvPjxwYXRoIGQ9Ik0xNCAzMmgzNk0xNyAyM2gzME0xNyA0MWgzMCIvPjwvZz48L3N2Zz4=',
)]
class DomainResellerApi extends Server
{
    public const NAME = 'domain-reseller-api';

    /** Extension (class) name as stored in Paymenter's extensions table. */
    public const EXTENSION = 'DomainResellerApi';

    // Checkout fields (stored as service properties by Paymenter)
    public const PROP_DOMAIN = 'domain';

    public const PROP_ACTION = 'action';

    public const PROP_AUTH_CODE = 'auth_code';

    public const PROP_WHOIS_PRIVACY = 'whois_privacy';

    // Provisioning state (service properties written by this extension)
    public const PROP_DOMAIN_ID = 'dra_domain_id';

    public const PROP_STATUS = 'dra_status';

    public const PROP_EXPIRES_AT = 'dra_expires_at';

    public const PROP_AUTO_RENEW = 'dra_auto_renew';

    public const PROP_OWNER_HANDLE = 'dra_owner_handle';

    public const PROP_NAMESERVERS = 'dra_nameservers';

    public const PROP_GUARDED = 'dra_autorenew_guarded';

    public const PROP_LAST_SYNC = 'dra_last_sync';

    /** Webhook events the extension subscribes to. */
    public const WEBHOOK_EVENTS = [
        'domain.registered', 'domain.transferred', 'domain.transfer_failed', 'domain.traded', 'domain.expired',
        'domain.expiry_warning', 'domain.deleted', 'domain.restored', 'domain.updated', 'domain.held', 'domain.unheld',
        'wallet.low_balance',
    ];

    public const ACTION_REGISTER = 'register';

    public const ACTION_TRANSFER = 'transfer';

    public const CHECKOUT_NAMESERVER_FIELDS = 4;

    /** Statuses in which the domain can be managed by the customer. */
    public const MANAGEABLE_STATUSES = ['active'];

    private const PROPERTY_NAMES = [
        self::PROP_DOMAIN_ID => 'Domain ID',
        self::PROP_STATUS => 'Registry status',
        self::PROP_EXPIRES_AT => 'Registry expiry date',
        self::PROP_AUTO_RENEW => 'Auto-renew at registry',
        self::PROP_OWNER_HANDLE => 'Owner contact handle',
        self::PROP_NAMESERVERS => 'Nameservers',
        self::PROP_GUARDED => 'Auto-renew paused (unpaid invoice)',
        self::PROP_LAST_SYNC => 'Last synchronisation',
    ];

    private ?ApiClient $client = null;

    private static ?Closure $factory = null;

    private ?Notifier $notifier = null;

    // ──────────────────────────────────────────────
    // Extension lifecycle
    // ──────────────────────────────────────────────

    public function boot()
    {
        if (!Route::has('extensions.domain-reseller-api.webhook')) {
            require __DIR__ . '/routes/web.php';
        }

        View::addNamespace(self::NAME, __DIR__ . '/resources/views');
        app('translator')->addNamespace(self::NAME, __DIR__ . '/resources/lang');

        Livewire::component(self::NAME . '.nameservers', NameserversComponent::class);
        Livewire::component(self::NAME . '.dns', DnsRecordsComponent::class);
        Livewire::component(self::NAME . '.contacts', ContactsComponent::class);
        Livewire::component(self::NAME . '.dnssec', DnssecComponent::class);
        Livewire::component(self::NAME . '.transfer', TransferComponent::class);
        Livewire::component(self::NAME . '.search', DomainSearchComponent::class);

        // Public domain search page, linked in the main navigation.
        Event::listen('navigation', function () {
            if (!DomainSearchComponent::enabled()) {
                return null;
            }

            return [
                'name' => $this->trans('search.navigation'),
                'route' => 'extensions.domain-reseller-api.search',
                'icon' => 'ri-global',
                'separator' => true,
                'children' => [],
            ];
        });

        Event::listen(InvoicePaid::class, [ResumeAutoRenewOnPayment::class, 'handle']);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SyncDomainsCommand::class, ImportTldsCommand::class]);
        });

        $schedule = function (Schedule $schedule) {
            $schedule->command(SyncDomainsCommand::class)
                ->description('Synchronises domain status from the Domain Reseller API')
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer();
        };
        app()->afterResolving(Schedule::class, $schedule);
        if (app()->resolved(Schedule::class)) {
            $schedule(app(Schedule::class));
        }
    }

    // ──────────────────────────────────────────────
    // Configuration
    // ──────────────────────────────────────────────

    public function getConfig($values = []): array
    {
        $webhookUrl = '/extensions/domain-reseller-api/webhook';
        try {
            $webhookUrl = route('extensions.domain-reseller-api.webhook');
        } catch (Throwable) {
            // Route not registered yet (extension not booted)
        }

        return [
            [
                'name' => 'api_key',
                'type' => 'text',
                'label' => 'API key',
                'description' => 'Create an API key in your domain-reseller-api.de dashboard (Dashboard → API keys). Required scopes: domains, contacts, dns.',
                'placeholder' => 'drapi_xxxxxxxxxxxx_xxxxxxxxxxxxxxxxxxxxxx',
                'required' => true,
                'encrypted' => true,
            ],
            [
                'name' => 'base_url',
                'type' => 'text',
                'label' => 'API URL',
                'default' => ApiClient::DEFAULT_BASE_URL,
                'description' => 'Only change this for testing against a different installation.',
                'required' => false,
                'validation' => 'nullable|url:https,http',
            ],
            [
                'name' => 'sandbox',
                'type' => 'checkbox',
                'label' => 'Sandbox mode',
                'description' => 'Sends the x-sandbox header: no real registrations, no charges.',
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'timeout',
                'type' => 'number',
                'label' => 'Request timeout (seconds)',
                'default' => 60,
                'required' => false,
                'validation' => 'nullable|integer|min:5|max:300',
            ],
            [
                'name' => 'admin_handle',
                'type' => 'text',
                'label' => 'Admin contact handle',
                'description' => 'Optional contact handle used as admin-c for all domains (e.g. your own company). Defaults to the customer.',
                'required' => false,
            ],
            [
                'name' => 'tech_handle',
                'type' => 'text',
                'label' => 'Tech contact handle',
                'description' => 'Optional contact handle used as tech-c for all domains. Defaults to the customer.',
                'required' => false,
            ],
            [
                'name' => 'billing_handle',
                'type' => 'text',
                'label' => 'Billing/zone contact handle',
                'description' => 'Optional contact handle used as billing/zone contact. Defaults to the customer.',
                'required' => false,
            ],
            [
                'name' => 'on_suspend',
                'type' => 'select',
                'label' => 'When a service is suspended',
                'options' => [
                    'disable_autorenew' => 'Disable auto-renew at the registry (domain keeps working)',
                    'nothing' => 'Do nothing',
                ],
                'default' => 'disable_autorenew',
                'required' => true,
            ],
            [
                'name' => 'on_terminate',
                'type' => 'select',
                'label' => 'When a service is terminated',
                'options' => [
                    'cancel' => 'Cancel the domain at the end of its term',
                    'disable_autorenew' => 'Only disable auto-renew',
                    'delete' => 'Delete the domain immediately',
                    'nothing' => 'Do nothing',
                ],
                'default' => 'cancel',
                'required' => true,
            ],
            [
                'name' => 'restore_on_unsuspend',
                'type' => 'checkbox',
                'label' => 'Restore domains from redemption when a service is unsuspended',
                'description' => 'The restore fee of the registry is charged to your wallet.',
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'autorenew_guard_days',
                'type' => 'number',
                'label' => 'Pause auto-renew for unpaid domains (days before expiry)',
                'description' => 'If the renewal invoice is still unpaid this many days before the registry expiry date, auto-renew is paused until it is paid. 0 disables this.',
                'default' => 0,
                'required' => false,
                'validation' => 'nullable|integer|min:0|max:60',
            ],
            [
                'name' => 'skip_wallet_check',
                'type' => 'checkbox',
                'label' => 'Do not check the wallet balance at checkout',
                'description' => 'By default, orders the reseller wallet cannot pay for (balance + credit limit, or an active auto top-up) are refused at checkout, so registrations do not fail after the customer paid. Tick this for accounts that are not billed.',
                'default' => false,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'keep_due_date',
                'type' => 'checkbox',
                'label' => 'Do not align the service due date with the registry expiry',
                'description' => 'By default, the Paymenter due date of yearly services follows the expiry date of the domain, so renewal invoices are created before the registry renews it.',
                'default' => false,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'manual_webhook',
                'type' => 'checkbox',
                'label' => 'Do not register the webhook automatically',
                'description' => 'By default, the webhook is created at the API when the server is saved and its secret is stored below (requires a publicly reachable Paymenter URL).',
                'default' => false,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'webhook_secret',
                'type' => 'text',
                'label' => 'Webhook secret',
                'description' => 'Filled in automatically. If automatic registration is not possible, create a "custom" webhook in the API dashboard pointing to ' . $webhookUrl . ' and paste its secret here.',
                'required' => false,
                'encrypted' => true,
            ],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->client()->listDomains(1, 1);

            return true;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            [
                'name' => 'tlds',
                'type' => 'tags',
                'label' => 'Allowed TLDs',
                'description' => 'TLDs that can be ordered with this product, e.g. "de", "com". Leave empty to allow every TLD (only recommended if all TLDs cost the same).',
                'database_type' => 'array',
                'required' => false,
            ],
            [
                'name' => 'allow_register',
                'type' => 'checkbox',
                'label' => 'Allow registrations',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'allow_transfer',
                'type' => 'checkbox',
                'label' => 'Allow transfers',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'years',
                'type' => 'number',
                'label' => 'Registration period (years)',
                'description' => 'Should match the billing period of the product plan. Renewals are performed yearly by the registry auto-renew.',
                'default' => 1,
                'required' => false,
                'validation' => 'nullable|integer|min:1|max:10',
            ],
            [
                'name' => 'nameserver_mode',
                'type' => 'select',
                'label' => 'Nameservers for new registrations',
                'options' => [
                    'managed' => 'Managed DNS of domain-reseller-api.de',
                    'default' => 'Default nameservers below',
                    'customer' => 'Let the customer enter nameservers (falls back to the defaults/managed DNS)',
                ],
                'default' => 'managed',
                'required' => true,
            ],
            [
                'name' => 'default_nameservers',
                'type' => 'tags',
                'label' => 'Default nameservers',
                'description' => '2–6 hostnames, e.g. ns1.example.com. Used for "Default nameservers" and as fallback for "customer".',
                'database_type' => 'array',
                'required' => false,
            ],
            [
                'name' => 'whois_privacy',
                'type' => 'select',
                'label' => 'WHOIS privacy',
                'description' => 'Only available for OpenProvider-routed TLDs and private persons.',
                'options' => [
                    'off' => 'Not offered',
                    'optional' => 'Customer can choose at checkout',
                    'always' => 'Always enabled (private persons only)',
                ],
                'default' => 'off',
                'required' => true,
            ],
            [
                'name' => 'allow_premium',
                'type' => 'checkbox',
                'label' => 'Allow premium domains',
                'description' => 'Premium domains are charged at the registry premium price, which is usually higher than the product price.',
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'owner_change',
                'type' => 'select',
                'label' => 'Owner changes by customers',
                'description' => 'Some registries charge for an owner change (e.g. .eu trade). The fee is debited from your wallet.',
                'options' => [
                    'off' => 'Not allowed (contact support)',
                    'free' => 'Only free owner changes',
                    'all' => 'Allowed, including chargeable ones',
                ],
                'default' => 'free',
                // Not required: products created before this setting existed keep working (empty = "free").
                'required' => false,
            ],
            [
                'name' => 'hide_from_search',
                'type' => 'checkbox',
                'label' => 'Hide from the public domain search',
                'default' => false,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'feature_nameservers',
                'type' => 'checkbox',
                'label' => 'Customers can change nameservers',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'feature_dns',
                'type' => 'checkbox',
                'label' => 'Customers can manage DNS records',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'feature_contacts',
                'type' => 'checkbox',
                'label' => 'Customers can update their domain contact data',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'feature_dnssec',
                'type' => 'checkbox',
                'label' => 'Customers can manage DNSSEC',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
            [
                'name' => 'feature_authcode',
                'type' => 'checkbox',
                'label' => 'Customers can retrieve the auth code',
                'default' => true,
                'database_type' => 'boolean',
                'required' => false,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    // Checkout
    // ──────────────────────────────────────────────

    public function getCheckoutConfig(Product $product, $values = [], $settings = []): array
    {
        $settings = is_array($settings) ? $settings : [];
        $values = is_array($values) ? $values : [];
        $tlds = DomainName::normalizeTlds($settings['tlds'] ?? []);
        $actions = $this->allowedActions($settings);
        $action = in_array($values[self::PROP_ACTION] ?? null, $actions, true) ? $values[self::PROP_ACTION] : $actions[0];

        $fields = [];

        if (count($actions) > 1) {
            $fields[] = [
                'name' => self::PROP_ACTION,
                'type' => 'select',
                'label' => $this->trans('checkout.action'),
                'options' => array_combine($actions, array_map(fn ($a) => $this->trans('checkout.action_' . $a), $actions)),
                'default' => $actions[0],
                'required' => true,
            ];
        }

        $fields[] = [
            'name' => self::PROP_DOMAIN,
            'type' => 'text',
            'label' => $this->trans('checkout.domain'),
            'placeholder' => 'example.' . ($tlds[0] ?? 'com'),
            'description' => $tlds !== []
                ? $this->trans('checkout.domain_description_tlds', ['tlds' => implode(', ', array_map(fn ($t) => '.' . $t, $tlds))])
                : $this->trans('checkout.domain_description'),
            'default' => '',
            'required' => true,
            'validation' => [$this->domainRule($settings, $action)],
        ];

        if ($action === self::ACTION_TRANSFER) {
            $fields[] = [
                'name' => self::PROP_AUTH_CODE,
                'type' => 'text',
                'label' => $this->trans('checkout.auth_code'),
                'description' => $this->trans('checkout.auth_code_description'),
                'default' => '',
                'required' => true,
                'validation' => ['max:255'],
            ];
        }

        if ($action === self::ACTION_REGISTER && ($settings['nameserver_mode'] ?? 'managed') === 'customer') {
            for ($i = 1; $i <= self::CHECKOUT_NAMESERVER_FIELDS; $i++) {
                $fields[] = [
                    'name' => 'ns' . $i,
                    'type' => 'text',
                    'label' => $this->trans('checkout.nameserver', ['number' => $i]),
                    'placeholder' => 'ns' . $i . '.example.com',
                    'description' => $i === 1 ? $this->trans('checkout.nameserver_description') : null,
                    'default' => '',
                    'required' => false,
                    'validation' => [$this->nameserverRule()],
                ];
            }
        }

        if (($settings['whois_privacy'] ?? 'off') === 'optional') {
            $fields[] = [
                'name' => self::PROP_WHOIS_PRIVACY,
                'type' => 'select',
                'label' => $this->trans('checkout.whois_privacy'),
                'description' => $this->trans('checkout.whois_privacy_description'),
                'options' => [
                    'no' => $this->trans('checkout.no'),
                    'yes' => $this->trans('checkout.yes'),
                ],
                'default' => 'no',
                'required' => true,
            ];
        }

        return $fields;
    }

    /**
     * @return string[]
     */
    public function allowedActions(array $settings): array
    {
        $actions = [];
        if (Values::toBool($settings['allow_register'] ?? null, true)) {
            $actions[] = self::ACTION_REGISTER;
        }
        if (Values::toBool($settings['allow_transfer'] ?? null, true)) {
            $actions[] = self::ACTION_TRANSFER;
        }

        return $actions === [] ? [self::ACTION_REGISTER] : $actions;
    }

    /**
     * Validation rule for the domain field: syntax, allowed TLD and live availability.
     */
    public function domainRule(array $settings, string $action): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($settings, $action) {
            $error = $this->validateOrderableDomain((string) $value, $settings, $action);
            if ($error !== null) {
                $fail($error);
            }
        };
    }

    /**
     * Returns a translated error message, or null when the domain can be ordered.
     */
    public function validateOrderableDomain(string $input, array $settings, string $action): ?string
    {
        $domain = DomainName::normalize($input);
        if ($domain === null) {
            return $this->trans('checkout.error_invalid_domain');
        }

        $tlds = DomainName::normalizeTlds($settings['tlds'] ?? []);
        if (!DomainName::tldAllowed($domain, $tlds)) {
            return $this->trans('checkout.error_tld_not_allowed', ['tlds' => implode(', ', array_map(fn ($t) => '.' . $t, $tlds))]);
        }

        // Catch incomplete customer profiles now instead of after the payment.
        $profileError = $this->profileProblem();
        if ($profileError !== null) {
            return $profileError;
        }

        try {
            $check = $this->client()->checkDomain($domain)['data'] ?? [];
        } catch (ApiException $e) {
            return $this->trans('checkout.error_check_failed', ['error' => $e->getMessage()]);
        }

        $available = (bool) ($check['available'] ?? false);

        if ($action === self::ACTION_TRANSFER) {
            // The sandbox reports every domain as available, so transfers could never be tested.
            if ($available && !$this->client()->isSandbox()) {
                return $this->trans('checkout.error_not_registered');
            }
        } else {
            if (!$available) {
                return $this->trans('checkout.error_unavailable') . $this->suggestionText($domain, $tlds);
            }

            if (($check['premium'] ?? false) && !Values::toBool($settings['allow_premium'] ?? null)) {
                return $this->trans('checkout.error_premium') . $this->suggestionText($domain, $tlds);
            }
        }

        $years = $action === self::ACTION_TRANSFER ? 1 : max(1, Values::toInt($settings['years'] ?? null, 1));

        return $this->walletProblem($domain, $check, $action, $years);
    }

    /**
     * Error message if the logged-in customer's profile lacks data the registry needs.
     */
    public function profileProblem(): ?string
    {
        $user = $this->currentUser();
        if ($user === null) {
            return null;
        }

        try {
            (new ContactMapper($this->countryList()))->map($this->customerProfile($user));
        } catch (ContactDataException $e) {
            return $this->trans('checkout.error_profile', ['problems' => implode(' ', $e->problems())]);
        }

        return null;
    }

    /**
     * The logged-in user, if any.
     */
    protected function currentUser(): ?object
    {
        try {
            return auth()->user();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Error message if the reseller wallet cannot pay for the order. Admins are notified.
     */
    public function walletProblem(string $domain, array $check, string $action, int $years): ?string
    {
        if (Values::toBool($this->config('skip_wallet_check')) || $this->client()->isSandbox()) {
            return null;
        }

        $required = WalletCheck::requiredAmount($check, $action, $years);
        if ($required <= 0) {
            return null;
        }

        try {
            $wallet = $this->client()->wallet()['data'] ?? [];
            $autoTopup = null;
            if (!WalletCheck::covers($wallet, null, $required)) {
                $autoTopup = $this->client()->autoTopup()['data'] ?? null;
            }
        } catch (ApiException $e) {
            // Missing billing scope or API problem: do not block the order because of the pre-check.
            report($e);

            return null;
        }

        if (WalletCheck::covers($wallet, $autoTopup, $required)) {
            return null;
        }

        $this->notifier()->admins(
            'Domain order refused: wallet balance too low',
            sprintf('A customer tried to order %s (%s, %.2f EUR), but only %.2f EUR are available in the Domain Reseller API wallet. Top up the wallet at domain-reseller-api.de.', $domain, $action, $required, WalletCheck::available($wallet)),
            null,
            'wallet-checkout',
            180,
        );

        return $this->trans('checkout.error_temporarily_unavailable');
    }

    /**
     * " Available alternatives: …" for a taken domain, limited to the product's TLDs.
     */
    public function suggestionText(string $domain, array $tlds): string
    {
        try {
            $suggestions = $this->client()->suggestDomains(DomainName::sld($domain), $tlds)['data']['suggestions'] ?? [];
        } catch (ApiException) {
            return '';
        }

        $names = [];
        foreach ($suggestions as $suggestion) {
            $name = $suggestion['domain'] ?? null;
            if (is_string($name) && $name !== $domain && DomainName::tldAllowed($name, $tlds)) {
                $names[] = DomainName::toUnicode($name);
            }
        }

        $names = array_slice(array_values(array_unique($names)), 0, 5);

        return $names === [] ? '' : ' ' . $this->trans('checkout.suggestions', ['domains' => implode(', ', $names)]);
    }

    private function nameserverRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $normalized = Nameservers::normalize([(string) $value]);
            if ($normalized !== [] && !Nameservers::isValidHostname($normalized[0])) {
                $fail($this->trans('checkout.error_invalid_nameserver'));
            }
        };
    }

    // ──────────────────────────────────────────────
    // Provisioning
    // ──────────────────────────────────────────────

    /**
     * Register or transfer the domain. Safe to call again: an already
     * provisioned service is only re-activated, never charged twice.
     */
    public function createServer(Service $service, $settings, $properties)
    {
        try {
            $result = $this->provision($service, (array) $settings, (array) $properties);
        } catch (Throwable $e) {
            $domain = $properties[self::PROP_DOMAIN] ?? '?';
            $this->notifier()->admins(
                'Domain provisioning failed',
                sprintf('Service #%d (%s): %s', $service->id, $domain, $e->getMessage()),
                $this->adminServiceUrl($service),
            );
            throw $e;
        }

        $this->alignServiceExpiry($service, $result['expires_at'] ?? null);

        return $result;
    }

    /**
     * Register or transfer the domain (see createServer()).
     */
    protected function provision(Service $service, array $settings, array $properties): array
    {
        $properties = (array) $properties;
        $store = new PropertyStore($service);

        $domain = DomainName::normalize($properties[self::PROP_DOMAIN] ?? null);
        if ($domain === null) {
            throw new Exception('The service has no valid domain name (property "domain").');
        }
        if (($properties[self::PROP_DOMAIN] ?? null) !== $domain) {
            $store->set(self::PROP_DOMAIN, $domain);
        }

        if (!empty($properties[self::PROP_DOMAIN_ID])) {
            return $this->reactivate($service, $domain);
        }

        $tlds = DomainName::normalizeTlds($settings['tlds'] ?? []);
        if (!DomainName::tldAllowed($domain, $tlds)) {
            throw new Exception(sprintf('The TLD of %s is not allowed for this product.', $domain));
        }

        if ($this->isManagedByOtherService($service, $domain)) {
            throw new Exception(sprintf('The domain %s is already managed by another active service.', $domain));
        }

        $action = ($properties[self::PROP_ACTION] ?? self::ACTION_REGISTER) === self::ACTION_TRANSFER
            ? self::ACTION_TRANSFER
            : self::ACTION_REGISTER;

        $contacts = $this->resolveContacts($service);
        $whoisPrivacy = $this->wantsWhoisPrivacy($settings, $properties, $contacts['owner_type']);
        $handles = array_filter([
            'owner' => $contacts['owner'],
            'admin' => $contacts['admin'],
            'tech' => $contacts['tech'],
            'billing' => $contacts['billing'],
        ]);

        try {
            if ($action === self::ACTION_TRANSFER) {
                $authCode = trim((string) ($properties[self::PROP_AUTH_CODE] ?? ''));
                if ($authCode === '') {
                    throw new Exception('An auth code is required to transfer ' . $domain . '.');
                }
                $result = $this->client()->transferDomain($domain, $authCode, $handles, $whoisPrivacy)['data'] ?? [];
                $status = $result['status'] ?? 'pending_transfer';
            } else {
                $nameservers = $this->resolveNameservers($settings, $properties);
                $years = max(1, min(10, Values::toInt($settings['years'] ?? null, 1)));
                $result = $this->client()->registerDomain($domain, $handles, $nameservers, $years, $whoisPrivacy)['data'] ?? [];
                $status = $result['status'] ?? 'active';
            }
        } catch (ApiException $e) {
            if (!$e->isConflict()) {
                throw $e;
            }
            // 409: the domain already exists. Adopt it only when it is in our
            // account and registered to this very customer (e.g. a retry after
            // a timed out request), never someone else's domain.
            $result = $this->adoptExisting($domain, $contacts['owner'], $e);
            $status = $result['status'] ?? 'active';
        }

        $store->setMany([
            self::PROP_DOMAIN_ID => $result['domainId'] ?? $result['id'] ?? $domain,
            self::PROP_STATUS => $status,
            self::PROP_EXPIRES_AT => $this->formatDate($result['expiresAt'] ?? null),
            self::PROP_OWNER_HANDLE => $contacts['owner'],
            self::PROP_AUTO_RENEW => true,
        ], self::PROPERTY_NAMES);

        // The auth code is not needed anymore and should not linger around.
        if ($action === self::ACTION_TRANSFER) {
            $store->forget(self::PROP_AUTH_CODE);
        }

        return [
            'domain' => DomainName::toUnicode($domain),
            'action' => $action,
            'status' => $status,
            'expires_at' => $this->formatDate($result['expiresAt'] ?? null),
        ];
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        $properties = (array) $properties;
        if (empty($properties[self::PROP_DOMAIN_ID]) || ($this->config('on_suspend') ?: 'disable_autorenew') !== 'disable_autorenew') {
            return true;
        }

        $domain = $this->domainOf($properties);
        try {
            $this->client()->setAutoRenew($domain, false);
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                return true;
            }
            throw $e;
        }

        (new PropertyStore($service))->set(self::PROP_AUTO_RENEW, false, self::PROPERTY_NAMES[self::PROP_AUTO_RENEW]);

        return true;
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        $properties = (array) $properties;
        if (empty($properties[self::PROP_DOMAIN_ID])) {
            return true;
        }

        $this->reactivate($service, $this->domainOf($properties));

        return true;
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $properties = (array) $properties;
        if (empty($properties[self::PROP_DOMAIN_ID])) {
            return true;
        }

        $domain = $this->domainOf($properties);
        $mode = $this->config('on_terminate') ?: 'cancel';
        $store = new PropertyStore($service);

        try {
            switch ($mode) {
                case 'cancel':
                    $this->client()->deleteDomain($domain);
                    $store->set(self::PROP_STATUS, 'pending_delete', self::PROPERTY_NAMES[self::PROP_STATUS]);
                    break;
                case 'delete':
                    $this->client()->deleteDomain($domain, true);
                    $store->set(self::PROP_STATUS, 'deleted', self::PROPERTY_NAMES[self::PROP_STATUS]);
                    break;
                case 'disable_autorenew':
                    $this->client()->setAutoRenew($domain, false);
                    break;
                default:
                    return true;
            }
        } catch (ApiException $e) {
            if (!$e->isNotFound()) {
                throw $e;
            }
            $store->set(self::PROP_STATUS, 'deleted', self::PROPERTY_NAMES[self::PROP_STATUS]);
        }

        $store->set(self::PROP_AUTO_RENEW, false, self::PROPERTY_NAMES[self::PROP_AUTO_RENEW]);

        return true;
    }

    /**
     * Bring an already provisioned domain back into service: cancel a
     * scheduled deletion, restore it from redemption (if allowed) and turn
     * auto-renew back on.
     */
    public function reactivate(Service $service, string $domain): array
    {
        $store = new PropertyStore($service);

        try {
            $info = $this->client()->getDomain($domain)['data'] ?? [];
        } catch (ApiException $e) {
            if ($e->isNotFound()) {
                throw new Exception(sprintf('The domain %s no longer exists in the reseller account.', $domain), 0, $e);
            }
            throw $e;
        }

        $status = $info['status'] ?? 'unknown';
        $state = $info['stateInfo'] ?? [];

        if ($status === 'pending_delete' && ($state['canCancelDelete'] ?? true)) {
            $this->client()->cancelDelete($domain);
            $status = 'active';
        } elseif ($status === 'redemption' || ($state['canRestore'] ?? false)) {
            if (!Values::toBool($this->config('restore_on_unsuspend'))) {
                throw new Exception(sprintf('The domain %s is in redemption. Restore it manually or enable automatic restores in the server settings.', $domain));
            }
            $this->client()->restoreDomain($domain);
            $status = 'active';
        }

        $autoRenew = (bool) ($info['autoRenew'] ?? false);
        if (in_array($status, ['active', 'expired'], true) && !$autoRenew) {
            $this->client()->setAutoRenew($domain, true);
            $autoRenew = true;
        }

        $store->setMany([
            self::PROP_STATUS => $status,
            self::PROP_AUTO_RENEW => $autoRenew,
            self::PROP_EXPIRES_AT => $this->formatDate($info['expiresAt'] ?? null),
            self::PROP_GUARDED => null,
        ], self::PROPERTY_NAMES);

        return [
            'domain' => DomainName::toUnicode($domain),
            'status' => $status,
            'expires_at' => $this->formatDate($info['expiresAt'] ?? null),
        ];
    }

    // ──────────────────────────────────────────────
    // Client area
    // ──────────────────────────────────────────────

    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        $settings = (array) $settings;
        $properties = (array) $properties;
        $domain = DomainName::normalize($properties[self::PROP_DOMAIN] ?? null);

        if ($domain === null) {
            return [];
        }

        $actions = [
            ['type' => 'text', 'label' => $this->trans('client.domain'), 'text' => DomainName::toUnicode($domain)],
        ];

        if (empty($properties[self::PROP_DOMAIN_ID])) {
            $actions[] = ['type' => 'text', 'label' => $this->trans('client.status'), 'text' => $this->trans('status.provisioning')];

            return $actions;
        }

        $status = $properties[self::PROP_STATUS] ?? 'unknown';
        $actions[] = ['type' => 'text', 'label' => $this->trans('client.status'), 'text' => $this->statusLabel($status)];
        if (!empty($properties[self::PROP_EXPIRES_AT])) {
            $actions[] = ['type' => 'text', 'label' => $this->trans('client.registry_expiry'), 'text' => $properties[self::PROP_EXPIRES_AT]];
        }

        $actions[] = ['type' => 'view', 'name' => 'overview', 'label' => $this->trans('client.tab_overview')];

        if (!in_array($status, self::MANAGEABLE_STATUSES, true)) {
            return $actions;
        }

        foreach ($this->clientFeatures($settings) as $feature) {
            $actions[] = ['type' => 'view', 'name' => $feature, 'label' => $this->trans('client.tab_' . $feature)];
        }

        return $actions;
    }

    /**
     * Enabled client-area tabs for a product.
     *
     * @return string[]
     */
    public function clientFeatures(array $settings): array
    {
        $features = [];
        foreach (['nameservers', 'dns', 'contacts', 'dnssec'] as $feature) {
            if (Values::toBool($settings['feature_' . $feature] ?? null, true)) {
                $features[] = $feature;
            }
        }
        if (Values::toBool($settings['feature_authcode'] ?? null, true)) {
            $features[] = 'transfer';
        }

        return $features;
    }

    public function getView(Service $service, $settings, $properties, $view)
    {
        $settings = (array) $settings;
        $properties = (array) $properties;

        if ($view !== 'overview' && !in_array($view, $this->clientFeatures($settings), true)) {
            throw new Exception('View not found');
        }

        if ($view !== 'overview') {
            return view(self::NAME . '::client.component', [
                'component' => self::NAME . '.' . $view,
                'service' => $service,
            ]);
        }

        $domain = $this->domainOf($properties);
        $info = null;
        $error = null;

        try {
            $info = $this->client()->getDomain($domain)['data'] ?? null;
            if (is_array($info)) {
                $this->rememberDomainInfo($service, $info);
            }
        } catch (ApiException $e) {
            $error = $e->getMessage();
        }

        return view(self::NAME . '::client.overview', [
            'service' => $service,
            'domain' => $domain,
            'displayDomain' => DomainName::toUnicode($domain),
            'info' => $info,
            'error' => $error,
            'statusLabel' => $this->statusLabel($info['status'] ?? ($properties[self::PROP_STATUS] ?? 'unknown')),
        ]);
    }

    // ──────────────────────────────────────────────
    // Shared helpers (also used by Livewire components & commands)
    // ──────────────────────────────────────────────

    /**
     * The extension instance configured for the server of a service's product,
     * or null when the service does not belong to this extension.
     */
    public static function forService(Service $service): ?static
    {
        $server = $service->product?->server;
        if (!$server || $server->extension !== self::EXTENSION) {
            return null;
        }

        return static::forServer($server);
    }

    public static function forServer(object $server): static
    {
        if (self::$factory !== null) {
            return (self::$factory)($server);
        }

        return new static(ExtensionHelper::settingsToArray($server->settings));
    }

    /**
     * Override how instances are created for a server (used by tests).
     *
     * @param  (Closure(object): DomainResellerApi)|null  $factory
     */
    public static function resolveUsing(?Closure $factory): void
    {
        self::$factory = $factory;
    }

    public function client(): ApiClient
    {
        if ($this->client === null) {
            $apiKey = (string) $this->config('api_key');
            if ($apiKey === '') {
                throw new ApiException('No API key configured for the Domain Reseller API extension.');
            }

            $this->client = new ApiClient(
                $apiKey,
                (string) ($this->config('base_url') ?: ApiClient::DEFAULT_BASE_URL),
                Values::toBool($this->config('sandbox')),
                max(5, Values::toInt($this->config('timeout'), 60)),
            );
        }

        return $this->client;
    }

    /**
     * Replace the API client (used by tests).
     */
    public function setClient(ApiClient $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function domainOf(array $properties): string
    {
        $domain = DomainName::normalize($properties[self::PROP_DOMAIN] ?? null);
        if ($domain === null) {
            throw new Exception('The service has no valid domain name (property "domain").');
        }

        return $domain;
    }

    /**
     * Store the latest registry data of a domain on the service.
     */
    public function rememberDomainInfo(Service $service, array $info): void
    {
        if (!empty($info['status'])) {
            $this->updateStatus($service, (string) $info['status']);
        }

        $values = [
            self::PROP_EXPIRES_AT => $this->formatDate($info['expiresAt'] ?? null),
            self::PROP_LAST_SYNC => date('Y-m-d H:i'),
        ];
        if (array_key_exists('autoRenew', $info) && $info['autoRenew'] !== null) {
            $values[self::PROP_AUTO_RENEW] = (bool) $info['autoRenew'];
        }
        if (!empty($info['nameservers']) && is_array($info['nameservers'])) {
            $values[self::PROP_NAMESERVERS] = implode(', ', $info['nameservers']);
        }

        (new PropertyStore($service))->setMany(array_filter($values, fn ($v) => $v !== null), self::PROPERTY_NAMES);

        $this->alignServiceExpiry($service, $values[self::PROP_EXPIRES_AT]);
    }

    /**
     * Store a new registry status and tell customer/admins about relevant changes.
     */
    public function updateStatus(Service $service, string $status): void
    {
        $store = new PropertyStore($service);
        $previous = $store->get(self::PROP_STATUS);
        if ($previous === $status) {
            return;
        }

        $store->set(self::PROP_STATUS, $status, self::PROPERTY_NAMES[self::PROP_STATUS]);
        if ($previous === null) {
            return;
        }

        $domain = DomainName::toUnicode((string) $store->get(self::PROP_DOMAIN, ''));
        $customerUrl = $this->customerServiceUrl($service);

        if ($previous === 'pending_transfer' && $status === 'active') {
            $this->notifier()->customer($service->user, $this->trans('notifications.transfer_completed_title'), $this->trans('notifications.transfer_completed_body', ['domain' => $domain]), $customerUrl);
        } elseif ($status === 'transfer_failed') {
            $this->notifier()->customer($service->user, $this->trans('notifications.transfer_failed_title'), $this->trans('notifications.transfer_failed_body', ['domain' => $domain]), $customerUrl);
            $this->notifier()->admins('Domain transfer failed', sprintf('The transfer of %s (service #%d) failed.', $domain, $service->id), $this->adminServiceUrl($service));
        } elseif (in_array($status, ['expired', 'redemption', 'pending_delete', 'deleted', 'transferred_away', 'suspended'], true)
            && $service->status === Service::STATUS_ACTIVE) {
            $this->notifier()->admins(
                'Domain status changed: ' . $status,
                sprintf('%s (service #%d) is now "%s" at the registry while the Paymenter service is active.', $domain, $service->id, $status),
                $this->adminServiceUrl($service),
            );
        }
    }

    /**
     * Move the Paymenter due date to the registry expiry, so the renewal invoice is
     * created before the registry renews the domain. Only for yearly plans, where
     * one Paymenter period equals one registry renewal.
     */
    public function alignServiceExpiry(Service $service, ?string $date): void
    {
        if ($date === null || Values::toBool($this->config('keep_due_date')) || $service->status !== Service::STATUS_ACTIVE) {
            return;
        }

        $plan = $service->plan;
        if (!$plan || $plan->type !== 'recurring' || $plan->billing_unit !== 'year' || (int) $plan->billing_period !== 1) {
            return;
        }

        $current = $service->expires_at ? $service->expires_at->format('Y-m-d') : null;
        if ($current === $date) {
            return;
        }

        $service->expires_at = $date;
        $service->save();
    }

    // ──────────────────────────────────────────────
    // Webhook registration (Paymenter calls updated()/enabled() after saving the server)
    // ──────────────────────────────────────────────

    public function updated($server = null)
    {
        $this->registerWebhook($server);
    }

    public function enabled($server = null)
    {
        $this->registerWebhook($server);
    }

    /**
     * Create the webhook at the API and store its secret in the server settings.
     * Returns the secret, or null when nothing was done.
     */
    public function registerWebhook(?object $server, bool $force = false): ?string
    {
        if ($server === null || (!$force && (Values::toBool($this->config('manual_webhook')) || $this->config('webhook_secret')))) {
            return null;
        }

        try {
            $url = $this->webhookUrl();
            // A webhook for our URL without a known secret is useless: replace it.
            $existing = $this->client()->listWebhooks()['data'] ?? [];
            foreach ($existing['webhooks'] ?? $existing as $webhook) {
                if (is_array($webhook) && ($webhook['url'] ?? null) === $url && isset($webhook['id'])) {
                    $this->client()->deleteWebhook((string) $webhook['id']);
                }
            }

            $secret = $this->client()->createWebhook($url, self::WEBHOOK_EVENTS)['data']['secret'] ?? null;
            if (!$secret) {
                throw new ApiException('The API did not return a webhook secret.');
            }
        } catch (Throwable $e) {
            $this->flash('Webhook could not be registered automatically', $e->getMessage() . ' — create it manually or tick "Do not register the webhook automatically".', false);

            return null;
        }

        $server->settings()->updateOrCreate(['key' => 'webhook_secret'], ['value' => $secret, 'type' => 'string', 'encrypted' => true]);
        $this->config['webhook_secret'] = $secret;
        $this->flash('Webhook registered', $url, true);

        return $secret;
    }

    public function webhookUrl(): string
    {
        return route('extensions.domain-reseller-api.webhook');
    }

    /**
     * Show a Filament notification in the admin area (no-op elsewhere).
     */
    private function flash(string $title, string $body, bool $success): void
    {
        if (!class_exists(\Filament\Notifications\Notification::class)) {
            return;
        }

        try {
            $notification = \Filament\Notifications\Notification::make()->title($title)->body($body);
            ($success ? $notification->success() : $notification->warning())->send();
        } catch (Throwable) {
            // Not in an admin request
        }
    }

    public function notifier(): Notifier
    {
        return $this->notifier ??= new Notifier;
    }

    /**
     * Replace the notifier (used by tests).
     */
    public function setNotifier(Notifier $notifier): static
    {
        $this->notifier = $notifier;

        return $this;
    }

    private function adminServiceUrl(Service $service): ?string
    {
        try {
            return url('/admin/services/' . $service->id . '/edit');
        } catch (Throwable) {
            return null;
        }
    }

    private function customerServiceUrl(Service $service): ?string
    {
        try {
            return route('services.show', $service->id);
        } catch (Throwable) {
            return null;
        }
    }

    public function statusLabel(string $status): string
    {
        $key = 'status.' . $status;
        $label = $this->trans($key);

        return $label === self::NAME . '::messages.' . $key ? ucfirst(str_replace('_', ' ', $status)) : $label;
    }

    /**
     * Find or create the owner contact handle of a customer.
     *
     * A handle is created once per customer and API account and reused for
     * all their domains. When the customer changes their profile a new handle
     * is created, so existing domains are never silently changed.
     *
     * @return array{owner: string, owner_type: string, admin: ?string, tech: ?string, billing: ?string}
     */
    public function resolveContacts(Service $service): array
    {
        $user = $service->user;
        if (!$user) {
            throw new Exception('The service has no customer.');
        }

        $profile = $this->customerProfile($user);
        try {
            $payload = (new ContactMapper($this->countryList()))->map($profile);
        } catch (ContactDataException $e) {
            throw new Exception($e->getMessage(), 0, $e);
        }

        $fingerprint = ContactMapper::fingerprint($payload);
        $userStore = new PropertyStore($user);
        $suffix = $this->accountSuffix();
        $handleKey = 'dra_handle_' . $suffix;
        $fingerprintKey = 'dra_handle_fp_' . $suffix;

        $handle = $userStore->get($handleKey);
        if ($handle && $userStore->get($fingerprintKey) === $fingerprint) {
            try {
                $this->client()->getContact($handle);
            } catch (ApiException $e) {
                if (!$e->isNotFound()) {
                    throw $e;
                }
                $handle = null;
            }
        } else {
            $handle = null;
        }

        if (!$handle) {
            $created = $this->client()->createContact($payload);
            $handle = $created['data']['handle'] ?? null;
            if (!$handle) {
                throw new ApiException('The API did not return a contact handle.', 0, $created);
            }
            $userStore->set($handleKey, $handle, 'Domain contact handle');
            $userStore->set($fingerprintKey, $fingerprint, 'Domain contact fingerprint');
        }

        return [
            'owner' => $handle,
            'owner_type' => $payload['type'],
            'admin' => $this->config('admin_handle') ?: null,
            'tech' => $this->config('tech_handle') ?: null,
            'billing' => $this->config('billing_handle') ?: null,
        ];
    }

    /**
     * Profile data of a Paymenter user as used by {@see ContactMapper}.
     */
    public function customerProfile(object $user): array
    {
        $profile = [];
        foreach ($user->properties ?? [] as $property) {
            $profile[$property->key] = $property->value;
        }

        return array_merge($profile, [
            'first_name' => $user->first_name ?? '',
            'last_name' => $user->last_name ?? '',
            'email' => $user->email ?? '',
        ]);
    }

    /**
     * Nameservers for a new registration. An empty list makes the API use its managed DNS.
     *
     * @return string[]
     */
    public function resolveNameservers(array $settings, array $properties): array
    {
        $mode = $settings['nameserver_mode'] ?? 'managed';
        $defaults = Nameservers::normalize($settings['default_nameservers'] ?? []);

        $nameservers = match ($mode) {
            'customer' => Nameservers::fromKeys($properties) ?: $defaults,
            'default' => $defaults,
            default => [],
        };

        if ($mode === 'customer' && count($nameservers) < Nameservers::MIN) {
            $nameservers = count($defaults) >= Nameservers::MIN ? $defaults : [];
        }

        if ($nameservers === []) {
            return [];
        }

        try {
            return Nameservers::validate($nameservers);
        } catch (InvalidArgumentException $e) {
            throw new Exception('Invalid nameservers: ' . $e->getMessage(), 0, $e);
        }
    }

    private function wantsWhoisPrivacy(array $settings, array $properties, string $ownerType): bool
    {
        if ($ownerType !== 'person') {
            return false;
        }

        return match ($settings['whois_privacy'] ?? 'off') {
            'always' => true,
            'optional' => Values::toBool($properties[self::PROP_WHOIS_PRIVACY] ?? null),
            default => false,
        };
    }

    /**
     * Does another live service already manage this domain?
     */
    protected function isManagedByOtherService(Service $service, string $domain): bool
    {
        return Service::query()
            ->where('id', '!=', $service->id)
            ->whereIn('status', [Service::STATUS_ACTIVE, Service::STATUS_SUSPENDED])
            ->whereHas('properties', fn ($q) => $q->where('key', self::PROP_DOMAIN)->where('value', $domain))
            ->whereHas('properties', fn ($q) => $q->where('key', self::PROP_DOMAIN_ID))
            ->exists();
    }

    private function adoptExisting(string $domain, string $ownerHandle, ApiException $conflict): array
    {
        try {
            $info = $this->client()->getDomain($domain)['data'] ?? [];
        } catch (ApiException) {
            throw $conflict;
        }

        if (($info['contacts']['owner'] ?? null) !== $ownerHandle) {
            throw $conflict;
        }

        return [
            'domainId' => $info['id'] ?? $domain,
            'status' => $info['status'] ?? 'active',
            'expiresAt' => $info['expiresAt'] ?? null,
        ];
    }

    /**
     * Identifies the API account so handles are not mixed between accounts or sandbox/live.
     */
    private function accountSuffix(): string
    {
        return substr(hash('sha256', implode('|', [
            rtrim((string) ($this->config('base_url') ?: ApiClient::DEFAULT_BASE_URL), '/'),
            substr((string) $this->config('api_key'), 0, 18),
            Values::toBool($this->config('sandbox')) ? 'sandbox' : 'live',
        ])), 0, 10);
    }

    /**
     * @return array<string, string>
     */
    private function countryList(): array
    {
        try {
            $countries = config('app.countries', []);
        } catch (Throwable) {
            $countries = [];
        }

        return is_array($countries) ? $countries : [];
    }

    private function formatDate(mixed $date): ?string
    {
        if (!$date) {
            return null;
        }

        $timestamp = strtotime((string) $date);

        return $timestamp === false ? null : date('Y-m-d', $timestamp);
    }

    public function trans(string $key, array $replace = []): string
    {
        $full = self::NAME . '::messages.' . $key;
        if (function_exists('__')) {
            try {
                $translated = __($full, $replace);
                if (is_string($translated)) {
                    return $translated;
                }
            } catch (Throwable) {
                // Translator not available (e.g. unit tests)
            }
        }

        return $full;
    }

    public function propertyName(string $key): ?string
    {
        return self::PROPERTY_NAMES[$key] ?? null;
    }
}
