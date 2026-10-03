@php($m = 'domain-reseller-api::messages.')
<div class="flex flex-col gap-4">
    <div class="bg-background-secondary border border-neutral rounded-lg p-4">
        <h4 class="text-lg font-semibold">{{ __($m . 'transfer.title') }}</h4>
        <p class="text-sm text-base/50 mt-1 mb-4">{{ __($m . 'transfer.description', ['domain' => $this->displayDomain()]) }}</p>

        @if ($authCode)
            <div class="flex flex-col gap-2" x-data="{ copied: false, code: @js($authCode) }">
                <label class="text-sm text-primary-100">{{ __($m . 'transfer.auth_code') }}</label>
                <div class="flex flex-row gap-2 items-center">
                    <code class="font-mono text-base bg-background border border-neutral rounded-md px-3 py-2 break-all select-all">{{ $authCode }}</code>
                    <x-button.secondary type="button" class="!w-fit"
                        x-on:click="navigator.clipboard.writeText(code); copied = true; setTimeout(() => copied = false, 2000)">
                        <span x-show="!copied">{{ __($m . 'transfer.copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __($m . 'transfer.copied') }}</span>
                    </x-button.secondary>
                    <x-button.secondary type="button" class="!w-fit" wire:click="hide">
                        {{ __($m . 'transfer.hide') }}
                    </x-button.secondary>
                </div>
            </div>
        @else
            <x-button.primary type="button" class="!w-fit" wire:click="reveal">
                {{ __($m . 'transfer.reveal') }}
            </x-button.primary>
        @endif
    </div>

    @if ($pendingTransferOut)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold">{{ __($m . 'transfer.out_title') }}</h4>
            <p class="text-sm text-base/50 mt-1 mb-4">{{ __($m . 'transfer.out_description', ['domain' => $this->displayDomain()]) }}</p>
            <div class="flex flex-row gap-2">
                <x-button.primary type="button" class="!w-fit" wire:click="approveTransferOut"
                    wire:confirm="{{ __($m . 'transfer.out_approve_confirm') }}">
                    {{ __($m . 'transfer.out_approve') }}
                </x-button.primary>
                <x-button.danger type="button" class="!w-fit" wire:click="rejectTransferOut">
                    {{ __($m . 'transfer.out_reject') }}
                </x-button.danger>
            </div>
        </div>
    @endif

    @if ($canReset)
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <h4 class="text-lg font-semibold">{{ __($m . 'transfer.reset_title') }}</h4>
            <p class="text-sm text-base/50 mt-1 mb-4">{{ __($m . 'transfer.reset_description') }}</p>
            <x-button.danger type="button" class="!w-fit" wire:click="resetCode"
                wire:confirm="{{ __($m . 'transfer.reset_confirm') }}">
                {{ __($m . 'transfer.reset') }}
            </x-button.danger>
        </div>
    @endif
</div>
