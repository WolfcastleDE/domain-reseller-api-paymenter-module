@php($m = 'domain-reseller-api::messages.')
<div class="flex flex-col gap-4">
    @if ($loadError)
        @include('domain-reseller-api::partials.error', ['message' => $loadError])
    @else
        <div class="bg-background-secondary border border-neutral rounded-lg p-4">
            <div class="flex flex-row justify-between items-center">
                <h4 class="text-lg font-semibold">{{ __($m . 'dnssec.title') }}</h4>
                <span class="font-semibold @if ($enabled) text-green-500 @else text-orange-500 @endif">
                    {{ $enabled ? __($m . 'dnssec.status_enabled') : __($m . 'dnssec.status_disabled') }}
                </span>
            </div>
            <p class="text-sm text-base/50 mt-1 mb-4">
                {{ $managed ? __($m . 'dnssec.managed_hint') : __($m . 'dnssec.custom_hint') }}
            </p>

            @unless ($managed)
                <x-form.textarea name="dnskeys" wire:model="dnskeys" :label="__($m . 'dnssec.dnskeys')" rows="5"
                    placeholder="257 3 13 mdsswUyr3DPW132mOi8V9xESWE8jTo0dxCjjnopKl+GqJxpVXckHAeF+KkxLbxILfDLUT0rAK9iUzy1L53eKGQ==" />
                <p class="text-xs text-base/50 mt-1 mb-4">{{ __($m . 'dnssec.dnskeys_hint') }}</p>
            @endunless

            <div class="flex flex-row gap-2">
                @if (!$enabled || !$managed)
                    <x-button.primary type="button" class="!w-fit" wire:click="enable">
                        {{ $enabled ? __($m . 'dnssec.update_keys') : __($m . 'dnssec.enable') }}
                    </x-button.primary>
                @endif
                @if ($enabled)
                    <x-button.danger type="button" class="!w-fit" wire:click="disable"
                        wire:confirm="{{ __($m . 'dnssec.disable_confirm') }}">
                        {{ __($m . 'dnssec.disable') }}
                    </x-button.danger>
                @endif
            </div>
        </div>

        @if ($managed && $enabled && count($keys) > 0)
            <div class="bg-background-secondary border border-neutral rounded-lg p-4 overflow-x-auto">
                <h4 class="text-lg font-semibold mb-2">{{ __($m . 'dnssec.keys') }}</h4>
                @foreach ($keys as $key)
                    <div class="border-b border-neutral/50 py-2 text-sm" wire:key="key-{{ $key['id'] ?? $loop->index }}">
                        <p class="font-semibold">
                            {{ strtoupper($key['keytype'] ?? '') }} · {{ $key['algorithm'] ?? '' }} · {{ $key['bits'] ?? '' }} bit
                        </p>
                        <p class="font-mono text-xs break-all mt-1">{{ $key['dnskey'] ?? '' }}</p>
                        @foreach ($key['ds'] ?? [] as $ds)
                            <p class="font-mono text-xs break-all text-base/50">DS {{ $ds }}</p>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
