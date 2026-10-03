@php($m = 'domain-reseller-api::messages.')
<div class="flex flex-col gap-4">
    @if ($loadError)
        @include('domain-reseller-api::partials.error', ['message' => $loadError])
    @else
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold mb-2">{{ __($m . 'contacts.title') }}</h4>
            <dl class="grid grid-cols-2 gap-y-1 text-sm max-w-md">
                @foreach (['owner', 'admin', 'tech', 'billing'] as $role)
                    @if (!empty($handles[$role]))
                        <dt class="text-base/50">{{ __($m . 'contacts.role_' . $role) }}</dt>
                        <dd class="font-mono">{{ $handles[$role] }}</dd>
                    @endif
                @endforeach
            </dl>
        </div>

        @if ($ownerHandle && !$changingOwner)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4">
                <h4 class="text-lg font-semibold">{{ __($m . 'contacts.edit_title') }}</h4>
                <p class="text-sm text-base/50 mb-4">{{ __($m . 'contacts.edit_hint', ['handle' => $ownerHandle]) }}</p>
                <form wire:submit="save" class="flex flex-col gap-3">
                    @include('domain-reseller-api::partials.contact-form', ['prefix' => 'contact', 'organizationRequired' => true])
                    <x-button.primary type="submit" class="!w-fit" wire:confirm="{{ __($m . 'contacts.save_confirm') }}">
                        {{ __($m . 'contacts.save') }}
                    </x-button.primary>
                </form>
            </div>
        @elseif (!$ownerHandle && !$changingOwner)
            <p class="text-sm text-base/50">{{ __($m . 'contacts.not_editable') }}</p>
        @endif

        @if ($ownerChangeMode !== 'off')
            <div class="bg-background-secondary border border-neutral rounded-lg p-4">
                <h4 class="text-lg font-semibold">{{ __($m . 'contacts.owner_change_title') }}</h4>
                <p class="text-sm text-base/50 mb-4">{{ __($m . 'contacts.owner_change_description') }}</p>

                @if (!$changingOwner)
                    <x-button.secondary type="button" class="!w-fit" wire:click="startOwnerChange">
                        {{ __($m . 'contacts.owner_change_start') }}
                    </x-button.secondary>
                @elseif ($pendingHandle)
                    <div class="flex flex-col gap-3">
                        <p class="text-sm">
                            @if ((float) ($ownerChangePrice['netPrice'] ?? 0) > 0)
                                {{ __($m . 'contacts.owner_change_price', ['price' => number_format((float) $ownerChangePrice['price'], 2, ',', '.') . ' ' . ($ownerChangePrice['currency'] ?? 'EUR')]) }}
                            @else
                                {{ __($m . 'contacts.owner_change_free') }}
                            @endif
                        </p>
                        <div class="flex flex-row gap-2">
                            <x-button.primary type="button" class="!w-fit" wire:click="confirmOwnerChange">
                                {{ __($m . 'contacts.owner_change_confirm') }}
                            </x-button.primary>
                            <x-button.secondary type="button" class="!w-fit" wire:click="cancelOwnerChange">
                                {{ __($m . 'dns.cancel') }}
                            </x-button.secondary>
                        </div>
                    </div>
                @else
                    <form wire:submit="requestOwnerChange" class="flex flex-col gap-3">
                        @include('domain-reseller-api::partials.contact-form', ['prefix' => 'newOwner', 'organizationRequired' => false])
                        <div class="flex flex-row gap-2">
                            <x-button.primary type="submit" class="!w-fit">
                                {{ __($m . 'contacts.owner_change_continue') }}
                            </x-button.primary>
                            <x-button.secondary type="button" class="!w-fit" wire:click="cancelOwnerChange">
                                {{ __($m . 'dns.cancel') }}
                            </x-button.secondary>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    @endif
</div>
