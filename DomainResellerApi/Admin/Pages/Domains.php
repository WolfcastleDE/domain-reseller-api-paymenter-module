<?php

namespace Paymenter\Extensions\Servers\DomainResellerApi\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Server;
use App\Models\Service;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\DomainResellerApi\DomainResellerApi;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiClient;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\ApiException;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\DomainName;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\PropertyStore;
use Paymenter\Extensions\Servers\DomainResellerApi\Support\WalletCheck;
use Throwable;

/**
 * Admin overview of all domains with registry actions that Paymenter's
 * service page does not offer (restore, hold, transfer-out, ...).
 */
class Domains extends Page implements HasActions, HasTable
{
    use InteractsWithActions, InteractsWithTable;

    protected string $view = 'domain-reseller-api::admin.domains';

    protected static ?string $title = 'Domains';

    protected static ?string $slug = 'domain-reseller-api/domains';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-global-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-global-fill';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->hasPermission('admin.services.view');
    }

    public function getSubheading(): ?string
    {
        $parts = [];
        foreach (Server::where('extension', DomainResellerApi::EXTENSION)->get() as $server) {
            $parts[] = $server->name . ': ' . Cache::remember('dra-wallet-' . $server->id, 60, function () use ($server) {
                try {
                    $extension = DomainResellerApi::forServer($server);
                    if ($extension->client()->isSandbox()) {
                        return 'sandbox';
                    }
                    $wallet = $extension->client()->wallet()['data'] ?? [];

                    return sprintf('wallet %.2f %s available', WalletCheck::available($wallet), $wallet['currency'] ?? 'EUR');
                } catch (Throwable $e) {
                    return 'wallet unavailable (' . $e->getMessage() . ')';
                }
            });
        }

        return $parts === [] ? 'No Domain Reseller API server configured.' : implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync all domains')
                ->icon('ri-refresh-line')
                ->action(function () {
                    Artisan::call('domain-reseller-api:sync');
                    $this->toast('Synchronisation finished', trim(Artisan::output()));
                }),
            Action::make('webhook')
                ->label('Register webhook')
                ->icon('ri-links-line')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates (or replaces) the webhook at the API and stores its secret in the server settings.')
                ->action(function () {
                    foreach (Server::where('extension', DomainResellerApi::EXTENSION)->get() as $server) {
                        DomainResellerApi::forServer($server)->registerWebhook($server, true);
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Service::query()
                ->whereHas('product.server', fn (Builder $q) => $q->where('extension', DomainResellerApi::EXTENSION))
                ->whereHas('properties', fn (Builder $q) => $q->where('key', DomainResellerApi::PROP_DOMAIN))
                ->with(['properties', 'user', 'product']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('Service')->sortable()
                    ->url(fn (Service $record) => url('/admin/services/' . $record->id . '/edit')),
                TextColumn::make('domain')->label('Domain')
                    ->state(fn (Service $record) => DomainName::toUnicode((string) $this->prop($record, DomainResellerApi::PROP_DOMAIN)))
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('properties', fn (Builder $q) => $q->where('key', DomainResellerApi::PROP_DOMAIN)->where('value', 'like', '%' . strtolower($search) . '%'))),
                TextColumn::make('user.email')->label('Customer')->searchable(),
                TextColumn::make('status')->label('Service')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('registry_status')->label('Registry')->badge()
                    ->state(fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) ?? ($this->prop($record, DomainResellerApi::PROP_DOMAIN_ID) ? 'unknown' : 'not provisioned'))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'pending_transfer', 'pending', 'not provisioned' => 'warning',
                        'expired', 'redemption', 'pending_delete', 'deleted', 'transferred_away', 'transfer_failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('registry_expiry')->label('Registry expiry')
                    ->state(fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_EXPIRES_AT)),
                TextColumn::make('expires_at')->label('Due date')->date()->sortable(),
                IconColumn::make('auto_renew')->label('Auto-renew')->boolean()
                    ->state(fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_AUTO_RENEW) === null ? null : $this->prop($record, DomainResellerApi::PROP_AUTO_RENEW) === '1'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Service status')->options([
                    'active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->domainAction('refresh', 'Sync now', 'ri-refresh-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $info = $client->getDomain($domain)['data'] ?? [];
                        $ext->rememberDomainInfo($record, $info);

                        return 'Status: ' . ($info['status'] ?? 'unknown');
                    }),
                    Action::make('provision')->label('Retry provisioning')->icon('ri-play-line')
                        ->visible(fn (Service $record) => !$this->prop($record, DomainResellerApi::PROP_DOMAIN_ID))
                        ->requiresConfirmation()
                        ->modalDescription('Registers or transfers the domain now. The price is debited from the reseller wallet.')
                        ->action(fn (Service $record) => $this->run(fn () => ExtensionHelper::createServer($record) ? 'Domain provisioned.' : 'Done.')),
                    $this->domainAction('autorenew_on', 'Enable auto-renew', 'ri-repeat-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->setAutoRenew($domain, true);
                        (new PropertyStore($record))->set(DomainResellerApi::PROP_AUTO_RENEW, true);

                        return 'Auto-renew enabled.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_AUTO_RENEW) !== '1'),
                    $this->domainAction('autorenew_off', 'Disable auto-renew', 'ri-forbid-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->setAutoRenew($domain, false);
                        (new PropertyStore($record))->set(DomainResellerApi::PROP_AUTO_RENEW, false);

                        return 'Auto-renew disabled.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_AUTO_RENEW) === '1'),
                    $this->domainAction('restore', 'Restore from redemption', 'ri-arrow-go-back-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->restoreDomain($domain);
                        $ext->updateStatus($record, 'active');

                        return 'Domain restored.';
                    }, fn (Service $record) => in_array($this->prop($record, DomainResellerApi::PROP_STATUS), ['redemption', 'expired'], true), 'The restore fee of the registry is debited from the reseller wallet.'),
                    $this->domainAction('cancel_delete', 'Cancel scheduled deletion', 'ri-close-circle-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->cancelDelete($domain);
                        $ext->updateStatus($record, 'active');

                        return 'Deletion cancelled.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) === 'pending_delete'),
                    $this->domainAction('delete', 'Cancel at end of term', 'ri-delete-bin-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->deleteDomain($domain);
                        $ext->updateStatus($record, 'pending_delete');

                        return 'Domain will be deleted at the end of its term.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) === 'active', 'The domain is deleted at the registry when it expires.'),
                    $this->domainAction('hold', 'Put on hold (.de)', 'ri-pause-circle-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->holdDomain($domain, true);
                        $ext->updateStatus($record, 'suspended');

                        return 'Domain put on hold.';
                    }, fn (Service $record) => $this->isDe($record) && $this->prop($record, DomainResellerApi::PROP_STATUS) === 'active', 'The domain stops resolving until it is released.'),
                    $this->domainAction('unhold', 'Release hold (.de)', 'ri-play-circle-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->holdDomain($domain, false);
                        $ext->updateStatus($record, 'active');

                        return 'Hold released.';
                    }, fn (Service $record) => $this->isDe($record) && $this->prop($record, DomainResellerApi::PROP_STATUS) === 'suspended'),
                    $this->domainAction('transfer_out_approve', 'Approve outgoing transfer', 'ri-logout-box-r-line', function (DomainResellerApi $ext, ApiClient $client, string $domain, Service $record) {
                        $client->transferOut($domain, true);
                        (new PropertyStore($record))->set(DomainResellerApi::PROP_STATUS, 'transferred_away');

                        return 'Outgoing transfer approved.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) === 'active', 'The domain leaves your account.'),
                    $this->domainAction('transfer_out_reject', 'Reject outgoing transfer', 'ri-shield-line', function (DomainResellerApi $ext, ApiClient $client, string $domain) {
                        $client->transferOut($domain, false);

                        return 'Outgoing transfer rejected.';
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) === 'active'),
                    $this->domainAction('authcode', 'Show auth code', 'ri-key-2-line', function (DomainResellerApi $ext, ApiClient $client, string $domain) {
                        return 'Auth code: ' . ($client->getAuthCode($domain)['data']['authCode'] ?? '—');
                    }, fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_STATUS) === 'active', null, true),
                ]),
            ]);
    }

    /**
     * Table action calling the API for the record's domain.
     */
    private function domainAction(string $name, string $label, string $icon, callable $callback, ?callable $visible = null, ?string $confirm = null, bool $persistent = false): Action
    {
        $action = Action::make($name)->label($label)->icon($icon)
            ->visible(fn (Service $record) => $this->prop($record, DomainResellerApi::PROP_DOMAIN_ID) !== null && ($visible === null || $visible($record)))
            ->action(function (Service $record) use ($callback, $persistent) {
                $extension = DomainResellerApi::forService($record);
                if (!$extension) {
                    $this->toast('Not a Domain Reseller API service', '', false);

                    return;
                }
                $this->run(fn () => $callback($extension, $extension->client(), $extension->domainOf($record->properties()->pluck('value', 'key')->all()), $record), $persistent);
            });

        if ($confirm !== null) {
            $action->requiresConfirmation()->modalDescription($confirm);
        }

        return $action;
    }

    private function run(callable $callback, bool $persistent = false): void
    {
        try {
            $message = $callback();
            $this->toast('Done', is_string($message) ? $message : '', true, $persistent);
        } catch (ApiException $e) {
            $this->toast('API error', $e->getMessage(), false);
        } catch (Throwable $e) {
            report($e);
            $this->toast('Error', $e->getMessage(), false);
        }
    }

    private function toast(string $title, string $body, bool $success = true, bool $persistent = false): void
    {
        $notification = Notification::make()->title($title)->body($body);
        $notification = $success ? $notification->success() : $notification->danger();
        if ($persistent) {
            $notification->persistent();
        }
        $notification->send();
    }

    private function prop(Service $record, string $key): ?string
    {
        return $record->properties->firstWhere('key', $key)?->value;
    }

    private function isDe(Service $record): bool
    {
        return DomainName::tld((string) $this->prop($record, DomainResellerApi::PROP_DOMAIN)) === 'de';
    }
}
